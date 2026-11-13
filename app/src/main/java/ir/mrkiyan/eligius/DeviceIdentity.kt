package ir.mrkiyan.eligius

import android.content.Context
import android.provider.Settings
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyPair
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.ECGenParameterSpec

/**
 * Device identity & registration (Phase 2 — CD-10).
 *
 * Confirmed mechanism: Android Keystore ECDSA P-256 (secp256r1).
 *   - private key is generated inside the Keystore and is NON-EXPORTABLE
 *     (the platform never exposes the key material; no code path exports it)
 *   - device_hash = SHA-256(pubkey_der || ANDROID_ID)
 *   - ownership proof = signing the server challenge with SHA256withECDSA;
 *     the server verifies against the registered public key (PHP openssl_verify)
 *
 * NOTE (CD-10): generation inside the Keystore must NOT be presented as proof
 * of hardware-backed storage (TEE/StrongBox varies; software Keystore is
 * acceptable on emulators).
 */
object DeviceIdentity {

    private const val TAG = "DeviceIdentity"
    private const val KEY_ALIAS = "eligius_device_identity"
    private const val KEY_ALGORITHM = "EC"
    private const val KEY_SPEC = "secp256r1"
    private const val SIGN_ALGORITHM = "SHA256withECDSA"
    private const val KEYSTORE = "AndroidKeyStore"

    /** device_hash = SHA-256(pubkey_der || ANDROID_ID), lowercase hex. */
    fun getDeviceHash(context: Context): String {
        val keyPair = getOrCreateKeyPair(context)
        val androidId = getAndroidId(context)
        val pubKeyDer = keyPair.public.encoded
        val input = ByteArray(pubKeyDer.size + androidId.toByteArray().size)
        System.arraycopy(pubKeyDer, 0, input, 0, pubKeyDer.size)
        System.arraycopy(androidId.toByteArray(), 0, input, pubKeyDer.size, androidId.toByteArray().size)
        return sha256Hex(input)
    }

    /** Public key as a PEM "PUBLIC KEY" block (SubjectPublicKeyInfo DER). */
    fun getPublicKeyPem(context: Context): String {
        val keyPair = getOrCreateKeyPair(context)
        return derToPem(keyPair.public.encoded)
    }

    /**
     * Sign [data] with the device private key.
     * The signature is DER-encoded ECDSA (as produced by SHA256withECDSA),
     * which PHP openssl_verify accepts directly.
     */
    fun sign(context: Context, data: ByteArray): ByteArray {
        val privateKey = getPrivateKey(context)
        return Signature.getInstance(SIGN_ALGORITHM).apply {
            initSign(privateKey)
            update(data)
        }.sign()
    }

    /** Sign the server challenge (base64url string) and return base64 (DER) signature. */
    fun signChallenge(context: Context, challenge: ByteArray): String {
        val der = sign(context, challenge)
        return Base64.encodeToString(der, Base64.NO_WRAP)
    }

    /**
     * Best-effort root detection (Phase 2 acceptance criterion — WARNING ONLY).
     * A positive result NEVER blocks binding; it is logged so the server-side
     * audit trail can flag the session (04 §2.4: rooted devices remain the
     * server's authoritative boundary). No attestation claims are made.
     */
    fun isDeviceRooted(): Boolean {
        val buildTags = android.os.Build.TAGS ?: ""
        if (buildTags.contains("test-keys")) return true
        val suPaths = arrayOf(
            "/system/bin/su", "/system/xbin/su", "/sbin/su",
            "/system/sd/xbin/su", "/system/app/Superuser.apk",
            "/data/local/xbin/su", "/data/local/bin/su", "/data/local/su"
        )
        for (path in suPaths) {
            if (java.io.File(path).exists()) return true
        }
        return false
    }

    private fun getOrCreateKeyPair(context: Context): KeyPair {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        if (keyStore.containsAlias(KEY_ALIAS)) {
            val entry = keyStore.getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry
            return KeyPair(entry.certificate.publicKey, entry.privateKey)
        }
        val generator = KeyPairGenerator.getInstance(KEY_ALGORITHM, KEYSTORE)
        val spec = KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_SIGN
        )
            .setAlgorithmParameterSpec(ECGenParameterSpec(KEY_SPEC))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .build()
        generator.initialize(spec)
        return generator.generateKeyPair()
    }

    private fun getPrivateKey(context: Context): java.security.PrivateKey {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        val entry = keyStore.getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry
        return entry.privateKey
    }

    private fun getAndroidId(context: Context): String {
        return Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID) ?: ""
    }

    private fun sha256Hex(input: ByteArray): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(input)
        val sb = StringBuilder(digest.size * 2)
        for (b in digest) {
            sb.append(String.format("%02x", b))
        }
        return sb.toString()
    }

    private fun derToPem(der: ByteArray): String {
        val b64 = Base64.encodeToString(der, Base64.NO_WRAP)
        val sb = StringBuilder()
        sb.append("-----BEGIN PUBLIC KEY-----\n")
        var i = 0
        while (i < b64.length) {
            val end = minOf(i + 64, b64.length)
            sb.append(b64, i, end).append('\n')
            i = end
        }
        sb.append("-----END PUBLIC KEY-----")
        return sb.toString()
    }
}
