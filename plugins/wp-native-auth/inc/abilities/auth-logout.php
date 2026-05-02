<?php
/**
 * Ability: wp-native/auth-logout
 *
 * Mark the device's refresh token as revoked. Access tokens cannot be
 * invalidated server-side (they're short-lived), but the refresh chain
 * ends here.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_logout_ability' ) ) {
	/**
	 * Register the wp-native/auth-logout ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_logout_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth-logout',
			array(
				'label'               => __( 'Revoke the refresh token for a device', 'wp-native-auth' ),
				'description'         => __( 'Mark the device\'s refresh token as revoked. Access tokens cannot be invalidated server-side (they\'re short-lived), but the refresh chain ends here.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'device_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'device_id' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'revoked' ),
					'additionalProperties' => false,
					'properties'           => array(
						'revoked' => array(
							'type'        => 'boolean',
							'description' => 'True if a session was revoked, false if no matching device session existed.',
						),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_logout_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_logout_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth-logout.
	 *
	 * Delegates to the token service shipped in M4.2.
	 * Uses the current user from the bearer token — not from input.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_logout_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_revoke_refresh_token' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$user_id   = wp_get_current_user()->ID;
		$device_id = isset( $input['device_id'] ) ? (string) $input['device_id'] : '';

		return wp_native_auth_revoke_refresh_token( $user_id, $device_id );
	}
}
