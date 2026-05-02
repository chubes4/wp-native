<?php
/**
 * Bearer token authentication.
 *
 * Reads `Authorization: Bearer <token>` from the incoming request and resolves
 * it to a WordPress user via the `determine_current_user` filter so that
 * ability permission callbacks can rely on `is_user_logged_in()`.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'wp_native_auth_authenticate_bearer_token', 20 );

/**
 * Resolve the current user from a Bearer token, if one is present.
 *
 * If WordPress already determined a user (e.g. via cookie auth) we yield to
 * that — we never override an existing logged-in session. Otherwise we look
 * for a Bearer token in the Authorization header and, if present, hand it to
 * `wp_native_auth_validate_access_token()` to resolve to a user id.
 *
 * @param int|false $user_id Current user id resolved by an earlier filter, or false.
 * @return int|false Resolved user id on successful bearer auth, or the original value.
 */
function wp_native_auth_authenticate_bearer_token( int|false $user_id ): int|false {
	if ( $user_id ) {
		return $user_id;
	}

	$token = wp_native_auth_get_bearer_token();
	if ( null === $token ) {
		return $user_id;
	}

	if ( ! function_exists( 'wp_native_auth_validate_access_token' ) ) {
		return $user_id;
	}

	$resolved = wp_native_auth_validate_access_token( $token );
	if ( null === $resolved ) {
		return $user_id;
	}

	return $resolved;
}

/**
 * Extract the Bearer token from the Authorization header, if present.
 *
 * Checks the standard `HTTP_AUTHORIZATION` server var, the
 * `REDIRECT_HTTP_AUTHORIZATION` fallback (for setups where Apache strips the
 * Authorization header), and `getallheaders()` as a final fallback.
 *
 * @return string|null The raw token string, or null if no Bearer header is present.
 */
function wp_native_auth_get_bearer_token(): ?string {
	$auth_header = null;

	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
	} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	} elseif ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( isset( $headers['Authorization'] ) ) {
			$auth_header = sanitize_text_field( $headers['Authorization'] );
		} elseif ( isset( $headers['authorization'] ) ) {
			$auth_header = sanitize_text_field( $headers['authorization'] );
		}
	}

	if ( null === $auth_header || '' === $auth_header ) {
		return null;
	}

	if ( 0 !== stripos( $auth_header, 'Bearer ' ) ) {
		return null;
	}

	$token = trim( substr( $auth_header, 7 ) );

	return '' === $token ? null : $token;
}
