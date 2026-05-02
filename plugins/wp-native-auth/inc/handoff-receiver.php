<?php
/**
 * Browser handoff receiver.
 *
 * Hooks into `init` to detect the `?wp-native-handoff=<token>` query
 * parameter, validates the handoff token, establishes a WordPress browsing
 * session, and redirects to the clean URL (without the handoff param).
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_native_auth_handle_browser_handoff' ) ) {
	/**
	 * Handle an incoming browser handoff request.
	 *
	 * Checks for the `wp-native-handoff` query parameter, validates
	 * the token (single-use), sets the WP auth cookie for the bound
	 * user, and redirects to the original URL sans handoff param.
	 *
	 * @return void
	 */
	function wp_native_auth_handle_browser_handoff(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handoff token is the auth mechanism.
		if ( empty( $_GET['wp-native-handoff'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = sanitize_text_field( wp_unslash( $_GET['wp-native-handoff'] ) );

		if ( ! function_exists( 'wp_native_auth_validate_handoff_token' ) ) {
			return;
		}

		$user_id = wp_native_auth_validate_handoff_token( $token );

		if ( null === $user_id ) {
			wp_die(
				esc_html__( 'Invalid or expired handoff token.', 'wp-native-auth' ),
				esc_html__( 'Handoff Failed', 'wp-native-auth' ),
				array( 'response' => 403 )
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_die(
				esc_html__( 'Invalid user.', 'wp-native-auth' ),
				esc_html__( 'Handoff Failed', 'wp-native-auth' ),
				array( 'response' => 403 )
			);
		}

		// Establish the WordPress session.
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, false );

		/**
		 * Fires after a successful browser handoff session is established.
		 *
		 * @param int $user_id The user ID that was authenticated.
		 */
		do_action( 'wp_native_auth_after_browser_handoff', $user_id );

		// Build the redirect URL: current URL minus the handoff param.
		$redirect_url = remove_query_arg( 'wp-native-handoff' );

		wp_safe_redirect( $redirect_url );
		exit;
	}
}

add_action( 'init', 'wp_native_auth_handle_browser_handoff', 1 );
