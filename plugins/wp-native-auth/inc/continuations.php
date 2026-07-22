<?php
/**
 * Opaque authentication challenge continuation storage and consumption.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Read the generic client identifier supplied by wp-native transports.
 */
function wp_native_auth_get_client_id(): string {
	$value = isset( $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) ? wp_unslash( (string) $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) : '';

	return substr( sanitize_text_field( $value ), 0, 191 );
}

/**
 * Create an HMAC for a pending request snapshot.
 *
 * @param array<string,mixed> $request Pending login request.
 */
function wp_native_auth_hash_pending_request( array $request ): string {
	$encoded = wp_json_encode( $request );

	return hash_hmac( 'sha256', is_string( $encoded ) ? $encoded : '', wp_salt( 'auth' ) );
}

/**
 * Persist a pending login and return its plaintext continuation once.
 *
 * Passwords and challenge responses are intentionally absent from the stored
 * request. The opaque bearer is stored only as a SHA-256 hash.
 *
 * @param array<string,mixed> $request Pending login request.
 * @return array{token:string,expires_at:int}|WP_Error
 */
function wp_native_auth_create_continuation( array $request ) {
	global $wpdb;

	$token      = wp_native_auth_generate_opaque_token();
	$token_hash = hash( 'sha256', $token );
	$now        = time();
	$encoded    = wp_json_encode( $request );
	$table      = wp_native_auth_continuations_table_name();

	if ( ! is_string( $encoded ) ) {
		return new WP_Error( 'continuation_storage_failed', __( 'The pending login could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	// Opportunistic cleanup keeps abandoned continuations bounded without cron.
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"DELETE FROM {$table} WHERE expires_at < %s",
			wp_native_auth_mysql_gmt( $now )
		)
	);

	$inserted = $wpdb->insert(
		$table,
		array(
			'token_hash'   => $token_hash,
			'user_id'      => (int) $request['user_id'],
			'device_id'    => (string) $request['device_id'],
			'client_id'    => (string) $request['client_id'],
			'request_hash' => wp_native_auth_hash_pending_request( $request ),
			'request_data' => $encoded,
			'attempts'     => 0,
			'created_at'   => wp_native_auth_mysql_gmt( $now ),
			'expires_at'   => wp_native_auth_mysql_gmt( $now + WP_NATIVE_AUTH_CONTINUATION_TTL ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'continuation_storage_failed', __( 'The pending login could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	return array(
		'token'      => $token,
		'expires_at' => $now + WP_NATIVE_AUTH_CONTINUATION_TTL,
	);
}

/**
 * Verify and atomically consume a pending authentication challenge.
 *
 * Each structurally valid request consumes an attempt before policy runs.
 * Only an explicit boolean true from the policy completes authentication.
 *
 * @param string               $token              Opaque continuation bearer.
 * @param string               $device_id          Original device UUID.
 * @param array<string,mixed>  $challenge_response Policy-specific public response fields.
 * @return array<string,mixed>|WP_Error Token pair + user on success.
 */
function wp_native_auth_continue_login( string $token, string $device_id, array $challenge_response ) {
	global $wpdb;

	if ( '' === $token || strlen( $token ) > 512 ) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error( 'invalid_device_id', __( 'device_id must be a UUID v4.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$table      = wp_native_auth_continuations_table_name();
	$token_hash = hash( 'sha256', $token );
	$client_id  = wp_native_auth_get_client_id();
	$now        = wp_native_auth_mysql_gmt( time() );

	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET attempts = attempts + 1 WHERE token_hash = %s AND device_id = %s AND client_id = %s AND expires_at >= %s AND attempts < %d",
			$token_hash,
			$device_id,
			$client_id,
			$now,
			WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS
		)
	);

	if ( 1 !== $updated ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT device_id, client_id, expires_at, attempts FROM {$table} WHERE token_hash = %s LIMIT 1",
				$token_hash
			),
			ARRAY_A
		);

		if ( is_array( $row ) && (int) $row['attempts'] >= WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS ) {
			return new WP_Error( 'continuation_rate_limited', __( 'Too many authentication challenge attempts.', 'wp-native-auth' ), array( 'status' => 429 ) );
		}

		if ( is_array( $row ) && (string) $row['expires_at'] < $now ) {
			return new WP_Error( 'continuation_expired', __( 'The authentication continuation has expired.', 'wp-native-auth' ), array( 'status' => 401 ) );
		}

		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
			$token_hash
		),
		ARRAY_A
	);

	$request       = is_array( $row ) ? json_decode( (string) $row['request_data'], true ) : null;
	$required_keys = array( 'pending_request_id', 'user_id', 'device_id', 'client_id' );
	if ( ! is_array( $row ) || ! is_array( $request ) || array_diff( $required_keys, array_keys( $request ) ) || ! hash_equals( (string) $row['request_hash'], wp_native_auth_hash_pending_request( $request ) ) ) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$user = get_user_by( 'id', (int) $request['user_id'] );
	if (
		! ( $user instanceof WP_User )
		|| (int) $row['user_id'] !== (int) $user->ID
		|| (string) $request['device_id'] !== $device_id
		|| (string) $request['client_id'] !== $client_id
	) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$context = array(
		'pending_request_id' => (string) $request['pending_request_id'],
		'device_id'          => $device_id,
		'client_id'          => $client_id,
		'attempt'            => (int) $row['attempts'],
	);

	/**
	 * Verify an external authentication policy challenge response.
	 *
	 * Return true only when the challenge is complete. Return WP_Error to
	 * reject it, or null/false when it remains incomplete.
	 *
	 * @param null|bool|WP_Error $verified           Verification result.
	 * @param WP_User           $user               User bound to the pending login.
	 * @param array             $challenge_response Untrusted response supplied by the client.
	 * @param array             $context            Bound request context.
	 */
	$verified = apply_filters( 'wp_native_auth_verify_login_challenge', null, $user, $challenge_response, $context );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	if ( true !== $verified ) {
		return new WP_Error( 'challenge_rejected', __( 'The authentication challenge was not completed.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$pre_login = apply_filters( 'wp_native_auth_pre_login', null, $user, $context );
	if ( is_wp_error( $pre_login ) ) {
		return $pre_login;
	}

	$deleted = $wpdb->delete(
		$table,
		array(
			'id'         => (int) $row['id'],
			'token_hash' => $token_hash,
		),
		array( '%d', '%s' )
	);
	if ( 1 !== $deleted ) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation has already been used.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	return wp_native_auth_complete_login( $user, $device_id, $request );
}
