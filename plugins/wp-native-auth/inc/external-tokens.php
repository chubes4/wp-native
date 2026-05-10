<?php
/**
 * External-service token signing and verification.
 *
 * Generic HMAC-SHA256 signed-token primitive for delegating short-lived,
 * user-scoped, capability-scoped access to external services (audio
 * workers, search indexers, image renderers, anything that has its own
 * auth surface and wants to trust tokens minted by this WordPress
 * install).
 *
 * **Token format:**
 *
 *     <base64url(payload_json)>.<base64url(hmac_sha256(secret, payload_b64))>
 *
 * The signature is computed over the base64url-encoded payload string
 * (NOT the decoded JSON) so issuers and verifiers agree byte-for-byte
 * regardless of JSON whitespace or key ordering.
 *
 * **Payload conventions:**
 *
 *     {
 *       "iss": "<issuer-id>",      // opaque issuer identifier
 *       "sub": <user-id-int>,      // subject — user this token represents
 *       "scope": "<space-separated-scopes>",
 *       "exp": <unix-timestamp>,   // hard expiry
 *       "jti": "<uuid4-hex>"       // unique token id; reserved for revocation lists
 *     }
 *
 * The structure is JWT-shaped on purpose but deliberately NOT a JWT —
 * no `alg` header, no algorithm negotiation, no `none` attack surface.
 * Both sides agree out-of-band on HMAC-SHA256.
 *
 * This file ships only the crypto primitive. Building payloads, choosing
 * scope strings, and gating on user identity are the caller's concern
 * (typically a feature plugin like extrachill-studio that bridges to a
 * specific external service).
 *
 * Lineage: shape forked from the sweatpants HMAC validator (see
 * Extra-Chill/sweatpants:sweatpants/api/auth.py) so both sides interop
 * byte-for-byte without a shared library.
 *
 * @package WPNative\Auth
 * @since 0.2.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Base64url-encode a binary string (no padding, URL-safe alphabet).
 *
 * Matches RFC 4648 §5 — used for both the payload and signature segments
 * of the token. Padding is stripped because the segments are
 * length-delimited by the `.` separator.
 *
 * @param string $binary Raw bytes to encode.
 * @return string Base64url-encoded string without `=` padding.
 */
function wp_native_auth_base64url_encode( string $binary ): string {
	return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
}

/**
 * Base64url-decode, tolerating missing `=` padding.
 *
 * @param string $encoded Base64url-encoded input.
 * @return string|false Decoded raw bytes, or false on malformed input.
 */
function wp_native_auth_base64url_decode( string $encoded ) {
	$remainder = strlen( $encoded ) % 4;
	$padded    = $remainder ? $encoded . str_repeat( '=', 4 - $remainder ) : $encoded;
	$decoded   = base64_decode( strtr( $padded, '-_', '+/' ), true );
	return false !== $decoded ? $decoded : false;
}

/**
 * Sign an arbitrary payload into a self-contained bearer token.
 *
 * The payload is serialized to JSON with no whitespace, base64url-encoded,
 * and HMAC-SHA256 signed against the provided secret. The returned token
 * is suitable for use as the value of an `Authorization: Bearer <token>`
 * header at any external service that shares the same secret.
 *
 * Callers are responsible for putting `exp`, `sub`, `scope`, etc. into
 * the payload — this function imposes no payload schema. Recommended
 * fields are documented at the top of this file.
 *
 * @param array  $payload Associative array, JSON-serializable.
 * @param string $secret  Shared HMAC secret. Must be non-empty.
 * @return string Signed token of the form `<payload_b64>.<sig_b64>`.
 * @throws InvalidArgumentException If the secret is empty.
 */
function wp_native_auth_sign_external_token( array $payload, string $secret ): string {
	if ( '' === $secret ) {
		throw new InvalidArgumentException( 'Secret must be non-empty for external token signing.' );
	}

	$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $payload_json ) {
		throw new InvalidArgumentException( 'Payload could not be JSON-encoded.' );
	}

	$payload_b64 = wp_native_auth_base64url_encode( $payload_json );
	$signature   = hash_hmac( 'sha256', $payload_b64, $secret, true );
	$sig_b64     = wp_native_auth_base64url_encode( $signature );

	return $payload_b64 . '.' . $sig_b64;
}

/**
 * Verify an external token and return its decoded payload.
 *
 * Performs (in order): structural split, constant-time HMAC signature
 * comparison, base64url + JSON decode of the payload, and `exp` claim
 * enforcement. Returns the decoded payload array on success, or null on
 * any validation failure (malformed, bad signature, expired). Error
 * details are intentionally not surfaced — callers should treat a null
 * return as "credential not accepted" without distinguishing cause.
 *
 * Scope-level authorization is the caller's job: this function only
 * confirms the token is well-formed, untampered, and unexpired. The
 * caller decides whether the `scope` field grants the requested action.
 *
 * @param string $token  Token string from `Authorization: Bearer <token>`.
 * @param string $secret Shared HMAC secret used to mint the token.
 * @param int    $now    Unix timestamp to compare against `exp`. Defaults
 *                       to `time()`; exposed for deterministic testing.
 * @return array<string,mixed>|null Decoded payload on success, null otherwise.
 */
function wp_native_auth_verify_external_token( string $token, string $secret, int $now = 0 ): ?array {
	if ( '' === $secret || '' === $token ) {
		return null;
	}

	$rsplit_idx = strrpos( $token, '.' );
	if ( false === $rsplit_idx || 0 === $rsplit_idx || $rsplit_idx === strlen( $token ) - 1 ) {
		return null;
	}

	$payload_b64 = substr( $token, 0, $rsplit_idx );
	$sig_b64     = substr( $token, $rsplit_idx + 1 );

	$provided_sig = wp_native_auth_base64url_decode( $sig_b64 );
	if ( false === $provided_sig ) {
		return null;
	}

	$expected_sig = hash_hmac( 'sha256', $payload_b64, $secret, true );
	if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
		return null;
	}

	$payload_json = wp_native_auth_base64url_decode( $payload_b64 );
	if ( false === $payload_json ) {
		return null;
	}

	$payload = json_decode( $payload_json, true );
	if ( ! is_array( $payload ) ) {
		return null;
	}

	$now = $now > 0 ? $now : time();
	if ( ! isset( $payload['exp'] ) || ! is_numeric( $payload['exp'] ) || (int) $payload['exp'] <= $now ) {
		return null;
	}

	return $payload;
}
