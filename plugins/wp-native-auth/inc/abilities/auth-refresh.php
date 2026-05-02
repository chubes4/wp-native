<?php
/**
 * Ability: wp-native/auth.refresh
 *
 * Validate the supplied refresh token, rotate it (issue a new one,
 * invalidate the prior), and return a fresh access token.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_refresh_ability' ) ) {
	/**
	 * Register the wp-native/auth.refresh ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_refresh_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth.refresh',
			array(
				'label'               => __( 'Refresh access token using a refresh token', 'wp-native-auth' ),
				'description'         => __( 'Validate the supplied refresh token, rotate it (issue a new one, invalidate the prior), and return a fresh access token. Sliding 30-day expiry.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'refresh_token', 'device_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'refresh_token' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'device_id'     => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
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
				'execute_callback'    => 'wp_native_auth_execute_refresh_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_refresh_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth.refresh.
	 *
	 * Delegates to the token service shipped in M4.2.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_refresh_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_refresh_tokens' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$refresh_token = isset( $input['refresh_token'] ) ? (string) $input['refresh_token'] : '';
		$device_id     = isset( $input['device_id'] ) ? (string) $input['device_id'] : '';

		return wp_native_auth_refresh_tokens( $refresh_token, $device_id );
	}
}
