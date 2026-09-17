<?php
/**
 * Authorization-code storage and single-use redemption.
 *
 * Codes are 256-bit opaque bearers persisted as SHA-256 hashes with a
 * short TTL and an atomic claim (the continuations-table pattern):
 * exactly one token-endpoint request can ever convert a code into
 * tokens, and a replay of an already-claimed code revokes everything
 * that code ever minted.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Create an authorization code.
 *
 * @param int    $user_id              Authorizing user.
 * @param string $client_id            Validated client identifier.
 * @param string $redirect_uri         Validated redirect URI (bound to the code).
 * @param string $code_challenge       PKCE S256 challenge.
 * @param string $resource             Optional. RFC 8707 resource audience.
 * @param string $scope                Granted scope string.
 * @return array{code:string, expires_at:int} Plaintext code (returned once) and Unix expiry.
 */
// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- `resource` is the RFC 8707 parameter name; renaming it would diverge from the spec.
function wp_native_auth_oauth_create_authorization_code( int $user_id, string $client_id, string $redirect_uri, string $code_challenge, string $resource = '', string $scope = '' ): array {
	global $wpdb;

	$code      = wp_native_auth_generate_opaque_token();
	$now       = time();
	$expires   = $now + (int) apply_filters( 'wp_native_auth_oauth_code_ttl', WP_NATIVE_AUTH_OAUTH_CODE_TTL );
	$client_id = substr( $client_id, 0, 255 );

	$wpdb->insert(
		wp_native_auth_oauth_codes_table_name(),
		array(
			'code_hash'             => hash( 'sha256', $code ),
			'user_id'               => $user_id,
			'client_id'             => $client_id,
			'redirect_uri'          => substr( $redirect_uri, 0, 500 ),
			'code_challenge'        => substr( $code_challenge, 0, 128 ),
			'code_challenge_method' => 'S256',
			'resource'              => '' !== $resource ? substr( $resource, 0, 255 ) : null,
			'scope'                 => '' !== $scope ? substr( $scope, 0, 64 ) : null,
			'claim_token'           => null,
			'claimed_at'            => null,
			'created_at'            => wp_native_auth_mysql_gmt( $now ),
			'expires_at'            => wp_native_auth_mysql_gmt( $expires ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return array(
		'code'       => $code,
		'expires_at' => $expires,
	);
}

/**
 * Atomically claim an authorization code for redemption.
 *
 * The claim is a single conditional UPDATE (InnoDB-atomic, same pattern
 * as the login continuation claim): the first request to present the
 * correct code wins; every later request sees 0 affected rows and is
 * treated as a replay.
 *
 * Client binding and redirect-URI binding are checked BEFORE the claim
 * so a wrong-client presentation of a live code does not consume it.
 * A successful claim consumes the code regardless of what happens
 * afterwards — including a failed PKCE check. That is the conservative
 * reading of RFC 7636: only one verification attempt against a code is
 * ever possible.
 *
 * @param string $code         Plaintext authorization code.
 * @param string $client_id    Presenting client identifier.
 * @param string $redirect_uri Redirect URI presented at the token endpoint.
 * @return array<string,mixed>|WP_Error The claimed code row.
 */
function wp_native_auth_oauth_redeem_code( string $code, string $client_id, string $redirect_uri ): array|WP_Error {
	global $wpdb;

	if ( '' === $code || strlen( $code ) > 512 || '' === $client_id || '' === $redirect_uri ) {
		return new WP_Error( 'invalid_grant', __( 'The authorization code is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$table     = wp_native_auth_oauth_codes_table_name();
	$code_hash = hash( 'sha256', $code );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1",
			$code_hash
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'invalid_grant', __( 'The authorization code is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$now      = wp_native_auth_mysql_gmt( time() );
	$expired  = (string) $row['expires_at'] < $now;
	$claimed  = ! empty( $row['claim_token'] );
	$bound_ok = hash_equals( (string) $row['client_id'], $client_id ) && hash_equals( (string) $row['redirect_uri'], $redirect_uri );

	if ( $expired ) {
		$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );

		return new WP_Error( 'invalid_grant', __( 'The authorization code has expired.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! $bound_ok ) {
		return new WP_Error( 'invalid_grant', __( 'The authorization code was issued to a different client or redirect URI.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( $claimed ) {
		// Replay of an already-claimed code. Burn everything this code
		// ever minted (RFC 6749 §4.1.2) — conservative reading.
		wp_native_auth_oauth_revoke_code_session( (int) $row['user_id'], (string) $row['client_id'] );

		/**
		 * Fires when an authorization code is replayed at the token endpoint.
		 *
		 * @param int    $user_id   User the code was issued to.
		 * @param string $client_id Client the code was issued to.
		 */
		do_action( 'wp_native_auth_oauth_code_replay_detected', (int) $row['user_id'], (string) $row['client_id'] );

		return new WP_Error( 'invalid_grant', __( 'The authorization code has already been used.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$claim_token = hash( 'sha256', wp_native_auth_generate_opaque_token() );

	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET claim_token = %s, claimed_at = %s WHERE id = %d AND code_hash = %s AND claim_token IS NULL AND expires_at >= %s",
			$claim_token,
			$now,
			(int) $row['id'],
			$code_hash,
			$now
		)
	);

	if ( 1 !== (int) $updated ) {
		// A concurrent request won the claim between our SELECT and UPDATE.
		wp_native_auth_oauth_revoke_code_session( (int) $row['user_id'], (string) $row['client_id'] );

		/** This action is documented in wp_native_auth_oauth_redeem_code(). */
		do_action( 'wp_native_auth_oauth_code_replay_detected', (int) $row['user_id'], (string) $row['client_id'] );

		return new WP_Error( 'invalid_grant', __( 'The authorization code has already been used.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$row['claim_token'] = $claim_token;

	return $row;
}

/**
 * Derive the stable refresh-session device id for a user/client pair.
 *
 * A UUID-v4-shaped identifier derived via keyed hash, so each OAuth
 * client grant maps onto exactly one row of the existing refresh-token
 * table (upsert semantics apply) without a second token store.
 *
 * @param int    $user_id   User ID.
 * @param string $client_id OAuth client identifier.
 * @return string UUID-v4-shaped device id.
 */
function wp_native_auth_oauth_device_id( int $user_id, string $client_id ): string {
	$bytes = substr( hash_hmac( 'sha256', 'oauth-client|' . $client_id . '|' . $user_id, wp_salt( 'auth' ), true ), 0, 16 );

	// Force the UUID v4 version and variant bits so the identifier
	// satisfies the plugin's device-id validation.
	$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
	$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
	$hex      = bin2hex( $bytes );

	return sprintf(
		'%s-%s-%s-%s-%s',
		substr( $hex, 0, 8 ),
		substr( $hex, 8, 4 ),
		substr( $hex, 12, 4 ),
		substr( $hex, 16, 4 ),
		substr( $hex, 20, 12 )
	);
}

/**
 * Revoke the refresh session(s) minted through an OAuth client grant.
 *
 * Used on code replay: everything issued from the compromised code path
 * is burned so a stolen code cannot leave live tokens behind.
 *
 * @param int    $user_id   User ID.
 * @param string $client_id OAuth client identifier.
 * @return void
 */
function wp_native_auth_oauth_revoke_code_session( int $user_id, string $client_id ): void {
	$device_id = wp_native_auth_oauth_device_id( $user_id, $client_id );
	wp_native_auth_revoke_refresh_token( $user_id, $device_id );
}

/**
 * Delete expired authorization codes in bounded batches.
 *
 * Claimed and unclaimed rows alike are removed one day after expiry;
 * until then, claimed rows remain as replay evidence.
 *
 * @param int $limit Max rows to delete.
 * @return int Rows deleted.
 */
function wp_native_auth_cleanup_oauth_codes( int $limit = 500 ): int {
	global $wpdb;

	$table = wp_native_auth_oauth_codes_table_name();
	$limit = max( 1, min( 5000, $limit ) );

	return (int) $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"DELETE FROM {$table} WHERE expires_at < %s ORDER BY id ASC LIMIT %d",
			wp_native_auth_mysql_gmt( time() - DAY_IN_SECONDS ),
			$limit
		)
	);
}

/** Delete a user's authorization codes when the user is deleted. */
function wp_native_auth_oauth_delete_user_codes( int $user_id ): void {
	global $wpdb;

	$wpdb->delete( wp_native_auth_oauth_codes_table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
}
