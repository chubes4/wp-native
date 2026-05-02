<?php
/**
 * Ability: wp-native/auth.me
 *
 * Returns the user payload for whoever the bearer token authenticates.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_me_ability' ) ) {
	/**
	 * Register the wp-native/auth.me ability.
	 *
	 * @return void
	 */
	function wp_native_auth_register_me_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native/auth.me',
			array(
				'label'               => __( 'Get the currently authenticated user', 'wp-native-auth' ),
				'description'         => __( 'Returns the user payload for whoever the bearer token authenticates.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'user' ),
					'additionalProperties' => false,
					'properties'           => array(
						'user' => array( 'type' => 'object' ),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_me_ability',
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_execute_me_ability' ) ) {
	/**
	 * Execute callback for wp-native/auth.me.
	 *
	 * Delegates to the token service shipped in M4.2 to build the user payload.
	 *
	 * @param array<string, mixed> $input Validated ability input (empty for this ability).
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_me_ability( array $input ) {
		if ( ! function_exists( 'wp_native_auth_build_user_payload' ) ) {
			return new WP_Error(
				'token_service_unavailable',
				__( 'The wp-native-auth token service is not available.', 'wp-native-auth' ),
				array( 'status' => 500 )
			);
		}

		$user_id = wp_get_current_user()->ID;

		return array(
			'user' => wp_native_auth_build_user_payload( $user_id ),
		);
	}
}
