<?php
/**
 * Ability: wp-native/auth.sessions
 *
 * Returns one row per device that has a non-revoked, non-expired refresh
 * token. Useful for "manage your devices" UI.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_sessions_ability' ) ) {
	/**
	 * Register the wp-native/auth.sessions ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_sessions_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth.sessions',
			array(
				'label'               => __( 'List active device sessions for the current user', 'wp-native-auth' ),
				'description'         => __( 'Returns one row per device that has a non-revoked, non-expired refresh token. Useful for "manage your devices" UI.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'sessions' ),
					'additionalProperties' => false,
					'properties'           => array(
						'sessions' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'required'   => array( 'device_id', 'device_name', 'created_at', 'last_used_at', 'expires_at', 'current' ),
								'properties' => array(
									'device_id'    => array( 'type' => 'string' ),
									'device_name'  => array( 'type' => array( 'string', 'null' ) ),
									'created_at'   => array(
										'type'   => 'string',
										'format' => 'date-time',
									),
									'last_used_at' => array(
										'type'   => array( 'string', 'null' ),
										'format' => 'date-time',
									),
									'expires_at'   => array(
										'type'   => 'string',
										'format' => 'date-time',
									),
									'current'      => array(
										'type'        => 'boolean',
										'description' => 'True if this is the device that made the request.',
									),
								),
							),
						),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_sessions_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_sessions_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth.sessions.
	 *
	 * Delegates to the token service shipped in M4.2.
	 *
	 * @param array<string, mixed> $input Validated ability input (empty for this ability).
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_sessions_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_list_user_sessions' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$user_id = wp_get_current_user()->ID;

		return wp_native_auth_list_user_sessions( $user_id );
	}
}
