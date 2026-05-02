<?php
/**
 * Ability: wp-native/auth.login
 *
 * Authenticate a user by credentials and issue an access + refresh token
 * pair bound to a device.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_login_ability' ) ) {
	/**
	 * Register the wp-native/auth.login ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_login_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth.login',
			array(
				'label'               => __( 'Log in with username or email + password', 'wp-native-auth' ),
				'description'         => __( 'Authenticate a user by credentials and issue an access token + refresh token bound to a device.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'identifier', 'password', 'device_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'identifier'  => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Username or email address.',
						),
						'password'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Plaintext password. Sent over HTTPS only.',
						),
						'device_id'   => array(
							'type'        => 'string',
							'pattern'     => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
							'description' => 'UUID v4 generated client-side and persisted per device.',
						),
						'device_name' => array(
							'type'        => 'string',
							'maxLength'   => 191,
							'description' => "Optional human-readable device name (e.g. 'Chris's iPhone').",
						),
						'remember'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Whether to extend cookie session length when set_cookie is also true.',
						),
						'set_cookie'  => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Whether to also set a WordPress browsing cookie. Mobile apps pass false; web clients calling same-origin pass true.',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'access_token', 'access_expires_at', 'refresh_token', 'refresh_expires_at', 'user' ),
					'additionalProperties' => false,
					'properties'           => array(
						'access_token'       => array( 'type' => 'string' ),
						'access_expires_at'  => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
						'refresh_token'      => array( 'type' => 'string' ),
						'refresh_expires_at' => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
						'user'               => array( 'type' => 'object' ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => 'wp_native_auth_execute_login_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_login_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth.login.
	 *
	 * Delegates to the token service shipped in M4.2.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_login_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_login_with_tokens' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$identifier = isset( $input['identifier'] ) ? (string) $input['identifier'] : '';
		$password   = isset( $input['password'] ) ? (string) $input['password'] : '';
		$device_id  = isset( $input['device_id'] ) ? (string) $input['device_id'] : '';

		$options = array(
			'device_name' => isset( $input['device_name'] ) ? (string) $input['device_name'] : '',
			'remember'    => ! empty( $input['remember'] ),
			'set_cookie'  => ! empty( $input['set_cookie'] ),
		);

		return wp_native_auth_login_with_tokens( $identifier, $password, $device_id, $options );
	}
}
