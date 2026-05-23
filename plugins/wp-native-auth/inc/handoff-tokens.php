<?php
/**
 * Browser handoff token service.
 *
 * Generates and validates one-time tokens used to bootstrap a WordPress
 * cookie session in a real browser after app authentication.
 *
 * Token store: transient keyed by sha256(token), 60-second TTL, single-use.
 *
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Handoff token TTL in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL', 60 );
}

/**
 * Create a single-use browser handoff token for a user.
 *
 * Generates a random token, stores its SHA-256 hash as a transient key
 * with a 60-second TTL, and returns the plaintext token (returned to
 * the client exactly once).
 *
 * @param int $user_id User ID to bind the handoff to.
 * @return string Plaintext handoff token.
 */
function wp_native_auth_create_handoff_token( int $user_id ): string {
	$token      = wp_native_auth_generate_opaque_token();
	$token_hash = hash( 'sha256', $token );

	set_transient(
		'wp_native_auth_handoff_' . $token_hash,
		array(
			'user_id'    => $user_id,
			'created_at' => time(),
		),
		WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL
	);

	return $token;
}

/**
 * Validate and consume a browser handoff token (single-use).
 *
 * Computes sha256 of the incoming token, looks up the transient, deletes
 * it immediately (single-use semantics), then checks the created_at
 * timestamp against the TTL. Returns the user_id on success, null on
 * any failure.
 *
 * @param string $token Plaintext handoff token from the query param.
 * @return int|null User ID on success, null on failure.
 */
function wp_native_auth_validate_handoff_token( string $token ): ?int {
	$token = trim( $token );
	if ( '' === $token ) {
		return null;
	}

	$token_hash    = hash( 'sha256', $token );
	$transient_key = 'wp_native_auth_handoff_' . $token_hash;
	$payload       = get_transient( $transient_key );

	// Delete immediately — single-use regardless of validity.
	delete_transient( $transient_key );

	if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
		return null;
	}

	// Double-check TTL (transient expiry is the primary guard, but belt-and-suspenders).
	if ( ! empty( $payload['created_at'] ) && ( time() - (int) $payload['created_at'] ) > WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL ) {
		return null;
	}

	return (int) $payload['user_id'];
}
