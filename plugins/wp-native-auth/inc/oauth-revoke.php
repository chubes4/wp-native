<?php
/**
 * The /revoke endpoint (RFC 7009).
 *
 * Revokes refresh sessions and access tokens presented by the owning
 * client. Per RFC 7009 §2.2, unknown or already-invalid tokens are
 * acknowledged with 200 — the response must not become an oracle for
 * probing which tokens exist or which client owns them.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Handle POST /revoke.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_revoke(): void {
	wp_native_auth_ensure_schema();

	$body = wp_native_auth_oauth_read_body();

	$token     = isset( $body['token'] ) ? (string) $body['token'] : '';
	$client_id = isset( $body['client_id'] ) ? trim( (string) $body['client_id'] ) : '';
	$hint      = isset( $body['token_type_hint'] ) ? (string) $body['token_type_hint'] : '';

	if ( '' === $token ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The token parameter is required.', 'wp-native-auth' ), 400 );
	}

	if ( '' === $client_id ) {
		wp_native_auth_oauth_send_unauthorized( 'invalid_client', __( 'The client_id parameter is required.', 'wp-native-auth' ) );
	}

	$client = wp_native_auth_oauth_resolve_client( substr( $client_id, 0, 255 ) );
	if ( is_wp_error( $client ) ) {
		wp_native_auth_oauth_send_unauthorized( 'invalid_client', __( 'Unknown client.', 'wp-native-auth' ) );
	}

	// Refresh first by default (RFC 7009 §2.1); an access_token hint
	// flips the order. Both paths are attempted regardless of hint.
	if ( 'access_token' !== $hint ) {
		wp_native_auth_oauth_revoke_refresh_for_client( $token, (string) $client['client_id'] ) || wp_native_auth_oauth_revoke_access_for_client( $token, (string) $client['client_id'] );
	} else {
		wp_native_auth_oauth_revoke_access_for_client( $token, (string) $client['client_id'] ) || wp_native_auth_oauth_revoke_refresh_for_client( $token, (string) $client['client_id'] );
	}

	// 200 regardless of whether anything matched (RFC 7009 §2.2).
	wp_native_auth_oauth_send_json( array() );
}

/**
 * Revoke a refresh-token session if it belongs to the presenting client.
 *
 * @param string $token     Presented token.
 * @param string $client_id Presenting client identifier.
 * @return bool True when a session was revoked.
 */
function wp_native_auth_oauth_revoke_refresh_for_client( string $token, string $client_id ): bool {
	$session = wp_native_auth_find_refresh_session_by_token( $token );

	if ( null === $session || (string) ( $session['oauth_client_id'] ?? '' ) !== $client_id ) {
		return false;
	}

	return wp_native_auth_revoke_refresh_token( (int) $session['user_id'], (string) $session['device_id'] );
}

/**
 * Delete an access token if its stored binding belongs to the client.
 *
 * @param string $token     Presented token.
 * @param string $client_id Presenting client identifier.
 * @return bool True when a token was deleted.
 */
function wp_native_auth_oauth_revoke_access_for_client( string $token, string $client_id ): bool {
	$payload = wp_native_auth_get_access_token_payload( $token );

	if ( null === $payload ) {
		return false;
	}

	$meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

	$bound = isset( $meta['client_id'] ) && is_string( $meta['client_id'] ) && hash_equals( $meta['client_id'], $client_id );

	if ( ! $bound ) {
		return false;
	}

	return wp_native_auth_revoke_access_token( $token );
}
