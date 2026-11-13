package ir.mrkiyan.eligius

import android.util.Base64
import org.json.JSONObject
import java.nio.charset.StandardCharsets
import java.security.KeyFactory
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

/**
 * Offline entitlement (JWT) validation on device — Ed25519 with the
 * embedded server public keys, kid-selected (docs 06 §3.5, 04 §4.1/§6.1).
 *
 * Ed25519 is the server LICENSE-SIGNING algorithm (CD-10); verification here
 * uses BouncyCastle (pure Java, works on all supported API levels — platform
 * Ed25519 exists only on API 33+).
 */
object OfflineEntitlementValidator {

    data class ValidationResult(
        val isValid: Boolean,
        val licenseId: Long? = null,
        val deviceHash: String? = null,
        val expiresAt: Long = 0,
        val permissions: List<String> = emptyList(),
        val error: String? = null
    ) {
        companion object {
            fun Valid(licenseId: Long, deviceHash: String, expiresAt: Long, permissions: List<String>) =
                ValidationResult(true, licenseId, deviceHash, expiresAt, permissions)

            fun Expired() = ValidationResult(false, error = "EXPIRED")
            fun Invalid(error: String) = ValidationResult(false, error = error)
        }
    }

    /** Embedded public keys — base64 of the RAW 32-byte Ed25519 public keys
     *  (exactly what the server stores per kid). Rotated via app updates
     *  (docs 04 §6.1); injected through BuildConfig (doc 06 §9). */
    private val EMBEDDED_PUBLIC_KEYS: Map<String, String> = buildMap {
        if (BuildConfig.EMBEDDED_PUBLIC_KEY_V1.isNotBlank()) put("v1", BuildConfig.EMBEDDED_PUBLIC_KEY_V1)
        if (BuildConfig.EMBEDDED_PUBLIC_KEY_V2.isNotBlank()) put("v2", BuildConfig.EMBEDDED_PUBLIC_KEY_V2)
        if (BuildConfig.EMBEDDED_PUBLIC_KEY_V3.isNotBlank()) put("v3", BuildConfig.EMBEDDED_PUBLIC_KEY_V3)
    }

    fun validate(blob: String): ValidationResult {
        val parts = blob.split(".")
        if (parts.size != 3) return ValidationResult.Invalid("Invalid JWT format")

        val headerB64 = parts[0]
        val payloadB64 = parts[1]
        val signatureB64 = parts[2]

        val headerJson = base64UrlDecodeToString(headerB64)
        val header = JSONObject(headerJson)
        val kid = header.optString("kid")
        val alg = header.optString("alg")

        val pubKeyB64 = EMBEDDED_PUBLIC_KEYS[kid]
            ?: return ValidationResult.Invalid("Unknown key ID: $kid")

        val signingInput = "$headerB64.$payloadB64"
        val signature = base64UrlDecodeToBytes(signatureB64)
        if (!verifySignature(signingInput.toByteArray(StandardCharsets.UTF_8), signature, pubKeyB64, alg)) {
            return ValidationResult.Invalid("Signature verification failed")
        }

        val payloadJson = base64UrlDecodeToString(payloadB64)
        val payload = JSONObject(payloadJson)

        val now = System.currentTimeMillis() / 1000
        val exp = payload.optLong("exp", 0)
        val nbf = payload.optLong("nbf", 0)

        if (now >= exp) return ValidationResult.Expired()
        if (now < nbf) return ValidationResult.Invalid("Token not yet valid")
        if (payload.optString("aud") != "eligius-android") {
            return ValidationResult.Invalid("Invalid audience")
        }

        return ValidationResult.Valid(
            licenseId = payload.optLong("license_id"),
            deviceHash = payload.optString("device_hash"),
            expiresAt = exp * 1000L,
            permissions = payload.optJSONArray("permissions")?.let { arr ->
                (0 until arr.length()).map { arr.optString(it) }
            } ?: emptyList()
        )
    }

    private fun base64UrlDecodeToString(input: String): String =
        String(base64UrlDecodeToBytes(input), StandardCharsets.UTF_8)

    private fun base64UrlDecodeToBytes(input: String): ByteArray {
        val padded = input.replace('-', '+').replace('_', '/')
        val pad = (4 - padded.length % 4) % 4
        return Base64.decode(padded + "=".repeat(pad), Base64.DEFAULT)
    }

    private fun verifySignature(data: ByteArray, signature: ByteArray, pubKeyB64: String, alg: String): Boolean {
        return when (alg) {
            "EdDSA" -> {
                // Ed25519 via BouncyCastle — the public key is the RAW 32 bytes.
                val raw = Base64.decode(pubKeyB64, Base64.DEFAULT)
                org.bouncycastle.crypto.params.Ed25519PublicKeyParameters(raw, 0).let { pub ->
                    org.bouncycastle.crypto.signers.Ed25519Signer().apply {
                        init(false, pub)
                        update(data, 0, data.size)
                    }.verifySignature(signature)
                }
            }
            "ES256" -> {
                // ECDSA P-256 fallback header (never produced by our server —
                // licenses are Ed25519 per CD-10; kept for forward-compat only).
                val der = Base64.decode(pubKeyB64, Base64.DEFAULT)
                val pub = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(der))
                Signature.getInstance("SHA256withECDSA").apply {
                    initVerify(pub)
                    update(data)
                }.verify(signature)
            }
            else -> false
        }
    }
}
