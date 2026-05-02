<?php
/**
 * Ability: wp-native/auth-browser-handoff
 *
 * Mint a one-time signed handoff URL that establishes a WordPress browsing
 * session when opened in the system browser. Used by wp-native-shell's
 * useBrowserHandoff() hook to bridge from the app to web flows.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_browser_handoff_ability' ) ) {
	/**
	 * Register the wp-native/auth-browser-handoff ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_browser_handoff_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth-browser-handoff',
			array(
				'label'               => __( 'Generate a browser handoff URL', 'wp-native-auth' ),
				'description'         => __( 'Mint a one-time signed URL that establishes a WordPress browsing session when opened in the system browser.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'url' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Destination URL to open in the browser. Must be a valid http(s) URL.',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'handoff_url', 'expires_at' ),
					'additionalProperties' => false,
					'properties'           => array(
						'handoff_url' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'The original URL with a ?wp-native-handoff=<token> query appended.',
						),
						'expires_at'  => array(
							'type'        => 'string',
							'format'      => 'date-time',
							'description' => 'ISO 8601 timestamp when the handoff token expires (~60 seconds).',
						),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_browser_handoff_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_browser_handoff_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth-browser-handoff.
	 *
	 * Validates the destination URL, mints a handoff token, and returns
	 * the handoff URL with the token appended as a query parameter.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_browser_handoff_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_create_handoff_token' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth handoff token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$url = isset( $input['url'] ) ? (string) $input['url'] : '';

		// Validate the URL is a valid http(s) URL.
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The url parameter must be a valid http or https URL.', 'wp-native-auth' ),
				array( 'status' => 400 )
			);
		}

		$user_id = wp_get_current_user()->ID;

		$token       = wp_native_auth_create_handoff_token( $user_id );
		$handoff_url = add_query_arg( 'wp-native-handoff', $token, $url );
		$expires_at  = gmdate( 'c', time() + WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL );

		return array(
			'handoff_url' => $handoff_url,
			'expires_at'  => $expires_at,
		);
	}
}
