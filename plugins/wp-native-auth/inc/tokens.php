<?php
/**
 * Token primitives for wp-native-auth.
 *
 * Generic token helpers — no Extra Chill specifics.
 *
 * Lineage: forked from extrachill-users/inc/auth-tokens/tokens.php and
 * generalized. Access tokens are opaque random strings (site-transient-
 * backed, network-wide on multisite) for v0.1; JWT lands later if/when
 * needed.
 *
 * @package WPNative\Auth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Generate an opaque random token suitable for HTTP transport.
 *
 * 256 random bits, base64url-encoded (no padding) = 43 chars from the
 * `[A-Za-z0-9_-]` alphabet. This is the standard bearer token alphabet
 * from RFC 7235 — it survives HTTP headers, URL params, JSON bodies,
 * command-line args, and shell escaping without any character class
 * needing escaping.
 *
 * Why not `wp_generate_password( 64, true, true )`:
 *   The "extra special chars" alphabet that wp_generate_password adds
 *   when both flags are true includes `<`, `>`, `&`, `"`, `'`, and a
 *   literal space character. Those are hostile to HTTP transport:
 *     - `sanitize_text_field()` HTML-encodes the first five, mangling
 *       the token in transit (caused ~45% silent auth failures in
 *       extrachill.com production before this fix).
 *     - A literal space embeds in random positions, and `trim()` on
 *       the receiving side corrupts tokens with edge whitespace.
 *     - URL-encoding any of these requires extra care from clients.
 *   Switching to a base64url alphabet sidesteps the entire class.
 *
 * Entropy: 32 bytes of CSPRNG (`random_bytes`) = 256 bits, well above
 * the 128-bit threshold for opaque token unguessability.
 *
 * @return string Base64url-encoded random token (43 chars).
 */
function wp_native_auth_generate_opaque_token(): string {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
}

/**
 * Hash a refresh token for storage using SHA-256 hex.
 *
 * The plaintext refresh token is returned to the client exactly once on
 * issue/rotate. The hex hash is what gets persisted in the database, so
 * a database leak does not expose live tokens.
 *
 * @param string $token Plaintext refresh token.
 * @return string 64-character lowercase hex SHA-256 digest.
 */
function wp_native_auth_hash_refresh_token( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * Validate that a string is a UUID v4.
 *
 * Pattern matches the SCHEMAS.md regex exactly:
 *   ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$
 *
 * @param string $value Candidate string.
 * @return bool True if $value is a UUID v4.
 */
function wp_native_auth_is_uuid_v4( string $value ): bool {
	return (bool) preg_match(
		'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
		$value
	);
}

/**
 * Convert a UNIX timestamp to a GMT MySQL datetime string.
 *
 * @param int $ts UNIX timestamp.
 * @return string `Y-m-d H:i:s` formatted in UTC.
 */
function wp_native_auth_mysql_gmt( int $ts ): string {
	return gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * Generate an opaque random access token and persist it as a site transient.
 *
 * The site transient is keyed by the token's SHA-256 hash so the bearer-auth
 * filter can resolve incoming tokens with a single lookup. The stored value
 * contains the user_id and device_id; expiry is enforced by the transient
 * itself.
 *
 * Site transients (not per-blog transients) are used because access tokens
 * must resolve on every blog in a multisite, not just the blog that minted
 * them. `set_site_transient()` writes to `$wpdb->sitemeta` (or the network
 * cache key when an object cache is active), so tokens are visible network-
 * wide. On single-site WordPress, site transients behave identically to
 * regular transients — no behavior change.
 *
 * The TTL is filterable via `wp_native_auth_access_token_ttl`.
 *
 * @param int    $user_id   User ID the token authenticates.
 * @param string $device_id Device ID (UUID v4).
 * @return array{token:string, expires_at:int} Plaintext token and Unix expiry.
 */
function wp_native_auth_generate_access_token( int $user_id, string $device_id ): array {
	$ttl = (int) apply_filters(
		'wp_native_auth_access_token_ttl',
		WP_NATIVE_AUTH_ACCESS_TOKEN_TTL,
		$user_id
	);

	if ( $ttl <= 0 ) {
		$ttl = WP_NATIVE_AUTH_ACCESS_TOKEN_TTL;
	}

	$token      = wp_native_auth_generate_opaque_token();
	$token_hash = hash( 'sha256', $token );
	$expires_at = time() + $ttl;

	set_site_transient(
		'wp_native_auth_access_' . $token_hash,
		array(
			'user_id'    => $user_id,
			'device_id'  => $device_id,
			'expires_at' => $expires_at,
		),
		$ttl
	);

	return array(
		'token'      => $token,
		'expires_at' => $expires_at,
	);
}

/**
 * Validate an access token and return the associated user ID.
 *
 * Reads the site transient written by wp_native_auth_generate_access_token().
 * Returns null if the token is unknown, expired, or malformed.
 *
 * Uses `get_site_transient()` so tokens resolve on every blog in a multisite,
 * not just the blog that minted them. See generator docblock for rationale.
 *
 * @param string $token Plaintext access token from `Authorization: Bearer <token>`.
 * @return int|null User ID or null on failure.
 */
function wp_native_auth_validate_access_token( string $token ): ?int {
	if ( '' === $token ) {
		return null;
	}

	$token_hash = hash( 'sha256', $token );
	$payload    = get_site_transient( 'wp_native_auth_access_' . $token_hash );

	if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
		return null;
	}

	if ( ! empty( $payload['expires_at'] ) && (int) $payload['expires_at'] < time() ) {
		return null;
	}

	return (int) $payload['user_id'];
}
