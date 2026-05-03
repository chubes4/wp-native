<?php
/**
 * Ability: wp-native/auth-register
 *
 * Register a new user by email + password and issue an access + refresh
 * token pair bound to a device.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_register_ability' ) ) {
	/**
	 * Register the wp-native/auth-register ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth-register',
			array(
				'label'               => __( 'Register a new user account', 'wp-native-auth' ),
				'description'         => __( 'Create a new WordPress user by email + password and issue an access token + refresh token bound to a device.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'email', 'password', 'password_confirm', 'device_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'email'            => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Email address for the new account.',
						),
						'password'         => array(
							'type'        => 'string',
							'minLength'   => 8,
							'description' => 'Plaintext password. Sent over HTTPS only. Minimum 8 characters.',
						),
						'password_confirm' => array(
							'type'        => 'string',
							'minLength'   => 8,
							'description' => 'Must match password exactly.',
						),
						'device_id'        => array(
							'type'        => 'string',
							'pattern'     => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
							'description' => 'UUID v4 generated client-side and persisted per device.',
						),
						'device_name'      => array(
							'type'        => 'string',
							'maxLength'   => 191,
							'description' => "Optional human-readable device name (e.g. 'Chris's iPhone').",
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
				'execute_callback'    => 'wp_native_auth_execute_register_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_register_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth-register.
	 *
	 * Delegates to the registration service function.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_register_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_register_with_tokens' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$email            = isset( $input['email'] ) ? (string) $input['email'] : '';
		$password         = isset( $input['password'] ) ? (string) $input['password'] : '';
		$password_confirm = isset( $input['password_confirm'] ) ? (string) $input['password_confirm'] : '';
		$device_id        = isset( $input['device_id'] ) ? (string) $input['device_id'] : '';

		$options = array(
			'device_name' => isset( $input['device_name'] ) ? (string) $input['device_name'] : '',
		);

		return wp_native_auth_register_with_tokens( $email, $password, $password_confirm, $device_id, $options );
	}
}
