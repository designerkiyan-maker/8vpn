package ir.mrkiyan.eligius

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.net.HttpURLConnection
import java.net.URL
import java.security.Signature
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.FixMethodOrder
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.MethodSorters

/**
 * Phase 2 (CD-10) instrumented tests — REAL Android Keystore ECDSA P-256
 * device binding, including the full Android ↔ Backend challenge/response
 * round trip against the local WordPress instance
 * (10.0.2.2 is the emulator's alias for the host loopback).
 *
 * Requires: local WP at host port 8080 with the eligius-subscription plugin
 * active, MariaDB running, and the test account below present.
 */
@RunWith(AndroidJUnit4::class)
@FixMethodOrder(MethodSorters.NAME_ASCENDING)
class DeviceIdentityTest {

    /** Local backend (host loopback from the emulator). */
    private val base = "http://10.0.2.2:8080/wp/wp-json"

    /** Dedicated local test account (dev-only credentials). */
    private val testUser = "androidkeystore"
    private val testPass = "KsTest123@"

    private val ctx get() = InstrumentationRegistry.getInstrumentation().targetContext

    private fun http(method: String, url: String, token: String?, body: JSONObject?): Pair<Int, JSONObject> {
        val con = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            if (body != null) doOutput = true
            token?.let { setRequestProperty("Authorization", "Bearer $it") }
            setRequestProperty("X-Device-Hash", DeviceIdentity.getDeviceHash(ctx))
            setRequestProperty("Content-Type", "application/json")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        try {
            if (body != null) {
                con.outputStream.use { out -> out.write(body.toString().toByteArray()) }
            }
            val code = con.responseCode
            val text = (if (code in 200..299) con.inputStream else con.errorStream)
                ?.bufferedReader()?.use { it.readText() } ?: ""
            // 302 deep-link responses have empty bodies — tolerate non-JSON.
            val json = runCatching { JSONObject(text) }.getOrElse { JSONObject() }
            return Pair(code, json)
        } finally {
            con.disconnect()
        }
    }

    private fun jwtToken(): String {
        val (code, j) = http(
            "POST", "$base/jwt-auth/v1/token", null,
            JSONObject().put("username", testUser).put("password", testPass)
        )
        assertEquals("jwt login", 200, code)
        return j.getString("token")
    }

    // --- 1. Keystore key generation + fingerprint determinism -----------------

    @Test
    fun t1_keypairGeneration_and_hashDeterminism() {
        val hash1 = DeviceIdentity.getDeviceHash(ctx)
        val hash2 = DeviceIdentity.getDeviceHash(ctx)
        assertEquals(64, hash1.length)
        assertEquals(hash1, hash2) // same Keystore key → same fingerprint
        assertTrue(hash1.matches(Regex("[0-9a-f]{64}")))
    }

    // --- 2. Sign/verify round trip with the NON-EXPORTABLE key ----------------

    @Test
    fun t2_signVerify_roundTrip_onDevice() {
        val challenge = "phase2-keystore-challenge-${System.nanoTime()}"
        val signature = DeviceIdentity.sign(ctx, challenge.toByteArray())

        // Verify with the public key exactly as the SERVER does: parse the PEM
        // into an X509EncodedKeySpec (SubjectPublicKeyInfo) via KeyFactory.
        val pem = DeviceIdentity.getPublicKeyPem(ctx)
        val der = org.junit.Assert.assertTrue(
            "PEM body present",
            pem.contains("-----BEGIN PUBLIC KEY-----")
        ).let {
            pem.replace("-----BEGIN PUBLIC KEY-----", "")
                .replace("-----END PUBLIC KEY-----", "")
                .replace("\\s".toRegex(), "")
                .let { android.util.Base64.decode(it, android.util.Base64.DEFAULT) }
        }
        val pub = java.security.KeyFactory.getInstance("EC")
            .generatePublic(java.security.spec.X509EncodedKeySpec(der))

        val ok = Signature.getInstance("SHA256withECDSA").apply {
            initVerify(pub)
            update(challenge.toByteArray())
        }.verify(signature)
        assertTrue("Keystore ECDSA signature must verify against the registered public key", ok)

        // Tampered payload must fail.
        val bad = Signature.getInstance("SHA256withECDSA").apply {
            initVerify(pub)
            update((challenge + "x").toByteArray())
        }.verify(signature)
        assertFalse(bad)
    }

    // --- 3. FULL Android ↔ Backend round trip ---------------------------------

    @Test
    fun t3_backend_register_challenge_verify() {
        val token = jwtToken()
        val deviceHash = DeviceIdentity.getDeviceHash(ctx)
        val pem = DeviceIdentity.getPublicKeyPem(ctx)

        // 1. Register (CONFLICT 409 is acceptable if this device already exists).
        val (regCode, regBody) = http(
            "POST", "$base/eligius/v1/devices/register", token,
            JSONObject()
                .put("public_key", pem)
                .put("fingerprint", deviceHash)
                .put("label", "Keystore Instrumented Test")
        )
        assertTrue(
            "register failed: $regCode $regBody",
            regCode == 200 || regCode == 201 || regCode == 409
        )
        val deviceId = regBody.optJSONObject("data")?.optInt("device_id", 0) ?: 0

        // 2. Challenge
        val (chalCode, chalBody) = http(
            "POST", "$base/eligius/v1/devices/challenge", token,
            JSONObject().put("device_hash", deviceHash)
        )
        assertEquals("challenge", 200, chalCode)
        val challenge = chalBody.getJSONObject("data").getString("challenge")
        assertTrue(challenge.isNotEmpty())

        // 3. Sign with the REAL Android Keystore key
        val signature = DeviceIdentity.signChallenge(ctx, challenge.toByteArray())

        // 4. Verify on the server
        val (verCode, verBody) = http(
            "POST", "$base/eligius/v1/devices/verify", token,
            JSONObject()
                .put("device_hash", deviceHash)
                .put("challenge", challenge)
                .put("signature", signature)
        )
        assertEquals("verify", 200, verCode)
        assertTrue(
            "server must verify the Keystore ECDSA signature: $verBody",
            verBody.getJSONObject("data").getBoolean("verified")
        )
        assertTrue(deviceId > 0 || verBody.getJSONObject("data").getInt("device_id") > 0)
    }

    // --- 4. Replay protection (server-side) -----------------------------------

    @Test
    fun t4_replay_sameChallenge_rejected() {
        val token = jwtToken()
        val deviceHash = DeviceIdentity.getDeviceHash(ctx)

        val (chalCode, chalBody) = http(
            "POST", "$base/eligius/v1/devices/challenge", token,
            JSONObject().put("device_hash", deviceHash)
        )
        assertEquals(200, chalCode)
        val challenge = chalBody.getJSONObject("data").getString("challenge")
        val signature = DeviceIdentity.signChallenge(ctx, challenge.toByteArray())
        val payload = JSONObject()
            .put("device_hash", deviceHash)
            .put("challenge", challenge)
            .put("signature", signature)

        val (firstCode, _) = http("POST", "$base/eligius/v1/devices/verify", token, payload)
        assertEquals(200, firstCode)

        val (replayCode, replayBody) = http("POST", "$base/eligius/v1/devices/verify", token, payload)
        assertNotEquals(200, replayCode)
        assertTrue(
            "replay must be rejected: $replayBody",
            replayBody.optString("error", "") .contains("expired") ||
                replayBody.optJSONObject("error")?.optString("code") == "eligius_challenge_expired"
        )
    }
}