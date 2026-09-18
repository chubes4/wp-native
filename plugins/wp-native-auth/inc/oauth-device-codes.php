<?php
/**
 * Device authorization grant storage (RFC 8628 §3.2, §3.3, §3.5).
 *
 * Two secrets per request, with deliberately different jobs:
 *
 *   - `device_code` — a 256-bit opaque bearer held by the client. It never
 *     transits the browser and is the thing the token endpoint authenticates.
 *   - `user_code` — a short, human-transcribable string the user types on a
 *     second device. Low entropy by necessity, so its exposure is bounded by
 *     a short TTL and rate limiting rather than by size.
 *
 * Both are persisted as SHA-256 hashes: a database read yields neither a
 * usable device code nor a live user code.
 *
 * On PKCE: this grant has no authorization response and no redirect leg, so
 * there is no code-interception attack for PKCE to bind against — RFC 8628
 * defines no `code_challenge` and clients do not send one. The single-use
 * atomic claim, client binding, and short TTL carry the security here, the
 * same way they do for authorization codes. Requiring PKCE would reject
 * every spec-compliant device client for no gain.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * User-code alphabet (RFC 8628 §6.1).
 *
 * Twenty-two characters: no vowels (so no code can spell a word), and none
 * of the transcription-ambiguous pairs — no 0/O, no 1/I/L, no 5/S, no 2/Z,
 * no 8/B. A user reading a code aloud from a phone to a laptop, or typing
 * it from memory across the room, is the design target.
 */
const WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET = 'BCDFGHJKMNPQRTVWXY3467';

/**
 * User-code length, excluding the display separator.
 *
 * Eight characters over a 22-character alphabet is ~35.7 bits. That is low
 * enough that it is only safe in combination with the short TTL and the
 * per-IP attempt limiting in wp_native_auth_oauth_device_verify_rate_limited();
 * RFC 8628 §5.2 requires exactly that combination.
 */
const WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH = 8;

/**
 * Generate a user code in `XXXX-XXXX` display form.
 *
 * Uses random_int() — the CSPRNG — because a predictable user code is a
 * pre-authorized grant waiting to be claimed by whoever guesses it.
 *
 * @return string Display-formatted user code.
 */
function wp_native_auth_oauth_generate_user_code(): string {
	$alphabet = WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET;
	$max      = strlen( $alphabet ) - 1;
	$code     = '';

	for ( $i = 0; $i < WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH; $i++ ) {
		$code .= $alphabet[ random_int( 0, $max ) ];
	}

	return substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
}

/**
 * Normalize a user-entered code to its canonical storage form.
 *
 * RFC 8628 §6.1: input handling should be forgiving. Case is folded,
 * and every character outside the alphabet — separators, spaces, the
 * dash the UI displays — is discarded before hashing, so `wdjb-mjht`,
 * `WDJB MJHT`, and `WDJBMJHT` are the same code.
 *
 * @param string $user_code Raw user input.
 * @return string Canonical code, or '' when nothing usable remains.
 */
function wp_native_auth_oauth_normalize_user_code( string $user_code ): string {
	$upper      = strtoupper( $user_code );
	$normalized = '';
	$length     = strlen( $upper );

	for ( $i = 0; $i < $length; $i++ ) {
		if ( false !== strpos( WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET, $upper[ $i ] ) ) {
			$normalized .= $upper[ $i ];
		}

		if ( strlen( $normalized ) > WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH ) {
			return '';
		}
	}

	return strlen( $normalized ) === WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH ? $normalized : '';
}

/**
 * Hash a user code for storage and lookup.
 *
 * @param string $user_code Canonical (normalized) user code.
 * @return string SHA-256 hex digest.
 */
function wp_native_auth_oauth_hash_user_code( string $user_code ): string {
	return hash( 'sha256', 'user_code|' . $user_code );
}

/**
 * Create a device authorization request.
 *
 * The user code is generated with bounded retries because the column is
 * UNIQUE: a collision with a live code must not silently overwrite the
 * other request's grant.
 *
 * @param string $client_id Validated client identifier.
 * @param string $scope     Granted scope.
 * @param string $resource  Optional. RFC 8707 resource audience.
 * @return array{device_code:string, user_code:string, expires_at:int, interval:int}|WP_Error
 */
// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- `resource` is the RFC 8707 parameter name; renaming it would diverge from the spec.
function wp_native_auth_oauth_create_device_code( string $client_id, string $scope = '', string $resource = '' ): array|WP_Error {
	global $wpdb;

	$device_code   = wp_native_auth_generate_opaque_token();
	$now           = time();
	$ttl           = (int) apply_filters( 'wp_native_auth_oauth_device_code_ttl', WP_NATIVE_AUTH_OAUTH_DEVICE_CODE_TTL );
	$expires       = $now + $ttl;
	$poll_interval = (int) apply_filters( 'wp_native_auth_oauth_device_poll_interval', WP_NATIVE_AUTH_OAUTH_DEVICE_POLL_INTERVAL );
	$table         = wp_native_auth_oauth_device_codes_table_name();

	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$user_code = wp_native_auth_oauth_generate_user_code();

		$inserted = $wpdb->insert(
			$table,
			array(
				'device_code_hash' => hash( 'sha256', $device_code ),
				'user_code_hash'   => wp_native_auth_oauth_hash_user_code( wp_native_auth_oauth_normalize_user_code( $user_code ) ),
				'client_id'        => substr( $client_id, 0, 255 ),
				'user_id'          => null,
				'grant_status'     => 'pending',
				'resource'         => '' !== $resource ? substr( $resource, 0, 255 ) : null,
				'scope'            => '' !== $scope ? substr( $scope, 0, 64 ) : null,
				'poll_interval'    => $poll_interval,
				'last_polled_at'   => null,
				'claim_token'      => null,
				'claimed_at'       => null,
				'created_at'       => wp_native_auth_mysql_gmt( $now ),
				'expires_at'       => wp_native_auth_mysql_gmt( $expires ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			return array(
				'device_code' => $device_code,
				'user_code'   => $user_code,
				'expires_at'  => $expires,
				'interval'    => $poll_interval,
			);
		}
	}

	return new WP_Error(
		'server_error',
		__( 'A device code could not be generated. Please try again.', 'wp-native-auth' ),
		array( 'status' => 500 )
	);
}

/**
 * Look up a pending device request by user code.
 *
 * Returns only live, unapproved rows: an expired or already-decided code
 * must not be re-presentable on the verification screen.
 *
 * @param string $user_code Raw user input.
 * @return array<string,mixed>|null
 */
function wp_native_auth_oauth_find_device_code_by_user_code( string $user_code ): ?array {
	global $wpdb;

	$normalized = wp_native_auth_oauth_normalize_user_code( $user_code );

	if ( '' === $normalized ) {
		return null;
	}

	$table = wp_native_auth_oauth_device_codes_table_name();

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT * FROM {$table} WHERE user_code_hash = %s AND grant_status = 'pending' AND expires_at >= %s LIMIT 1",
			wp_native_auth_oauth_hash_user_code( $normalized ),
			wp_native_auth_mysql_gmt( time() )
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Record the user's decision on a device request.
 *
 * The UPDATE is conditional on the row still being `pending`, so a double
 * submission (or two tabs) cannot flip an already-decided request, and an
 * expired request cannot be approved after the fact.
 *
 * @param int    $row_id    Device code row id.
 * @param int    $user_id   Deciding user.
 * @param string $decision  'approved' or 'denied'.
 * @return bool True when this call recorded the decision.
 */
function wp_native_auth_oauth_decide_device_code( int $row_id, int $user_id, string $decision ): bool {
	global $wpdb;

	if ( ! in_array( $decision, array( 'approved', 'denied' ), true ) ) {
		return false;
	}

	$table = wp_native_auth_oauth_device_codes_table_name();

	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET grant_status = %s, user_id = %d WHERE id = %d AND grant_status = 'pending' AND expires_at >= %s",
			$decision,
			$user_id,
			$row_id,
			wp_native_auth_mysql_gmt( time() )
		)
	);

	return 1 === (int) $updated;
}

/**
 * Poll a device code at the token endpoint.
 *
 * Implements the RFC 8628 §3.5 polling state machine. Ordering matters:
 *
 *  1. Unknown code → `invalid_grant`. Indistinguishable from a wrong-client
 *     presentation, so probing learns nothing.
 *  2. Wrong client → `invalid_grant`, checked BEFORE any state mutation so
 *     another client cannot consume or rate-limit someone else's request.
 *  3. Expired → `expired_token`.
 *  4. Polling faster than the advertised interval → `slow_down`, and the
 *     stored interval increases by 5 seconds as the RFC requires.
 *  5. Denied → `access_denied`.
 *  6. Still pending → `authorization_pending`.
 *  7. Approved → atomic claim, exactly once.
 *
 * @param string $device_code Plaintext device code.
 * @param string $client_id   Presenting client identifier.
 * @return array<string,mixed>|WP_Error Claimed row on success.
 */
function wp_native_auth_oauth_poll_device_code( string $device_code, string $client_id ): array|WP_Error {
	global $wpdb;

	if ( '' === $device_code || strlen( $device_code ) > 512 || '' === $client_id ) {
		return new WP_Error( 'invalid_grant', __( 'The device code is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$table            = wp_native_auth_oauth_device_codes_table_name();
	$device_code_hash = hash( 'sha256', $device_code );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT * FROM {$table} WHERE device_code_hash = %s LIMIT 1",
			$device_code_hash
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'invalid_grant', __( 'The device code is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	// Client binding precedes every state change below, so a wrong client
	// can neither consume the grant nor drive another client's backoff.
	if ( ! hash_equals( (string) $row['client_id'], $client_id ) ) {
		return new WP_Error( 'invalid_grant', __( 'The device code was issued to a different client.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$now_ts = time();
	$now    = wp_native_auth_mysql_gmt( $now_ts );

	if ( (string) $row['expires_at'] < $now ) {
		return new WP_Error( 'expired_token', __( 'The device code has expired. Please start the connection again.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( wp_native_auth_oauth_device_poll_too_fast( $row, $now_ts ) ) {
		return new WP_Error( 'slow_down', __( 'Polling too frequently. Increase the interval and retry.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( 'denied' === (string) $row['grant_status'] ) {
		return new WP_Error( 'access_denied', __( 'The authorization request was denied.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( 'approved' !== (string) $row['grant_status'] ) {
		return new WP_Error( 'authorization_pending', __( 'The authorization request is still pending.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! empty( $row['claim_token'] ) ) {
		return new WP_Error( 'invalid_grant', __( 'The device code has already been used.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$claim_token = hash( 'sha256', wp_native_auth_generate_opaque_token() );

	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET claim_token = %s, claimed_at = %s WHERE id = %d AND device_code_hash = %s AND claim_token IS NULL AND grant_status = 'approved' AND expires_at >= %s",
			$claim_token,
			$now,
			(int) $row['id'],
			$device_code_hash,
			$now
		)
	);

	if ( 1 !== (int) $updated ) {
		// A concurrent poll won the claim between the SELECT and the UPDATE.
		return new WP_Error( 'invalid_grant', __( 'The device code has already been used.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$row['claim_token'] = $claim_token;

	return $row;
}

/**
 * Whether this poll arrived sooner than the advertised interval.
 *
 * Records the poll time either way. On a violation the stored interval
 * grows by 5 seconds (RFC 8628 §3.5), so a client that ignores `slow_down`
 * is progressively throttled rather than merely told off.
 *
 * @param array<string,mixed> $row    Device code row.
 * @param int                 $now_ts Current Unix time.
 * @return bool True when the client polled too fast.
 */
function wp_native_auth_oauth_device_poll_too_fast( array $row, int $now_ts ): bool {
	global $wpdb;

	$table         = wp_native_auth_oauth_device_codes_table_name();
	$poll_interval = (int) $row['poll_interval'];
	$last          = ! empty( $row['last_polled_at'] ) ? (int) strtotime( (string) $row['last_polled_at'] . ' UTC' ) : 0;
	$too_fast      = $last > 0 && ( $now_ts - $last ) < $poll_interval;

	$wpdb->update(
		$table,
		array(
			'last_polled_at' => wp_native_auth_mysql_gmt( $now_ts ),
			'poll_interval'  => $too_fast ? $poll_interval + 5 : $poll_interval,
		),
		array( 'id' => (int) $row['id'] ),
		array( '%s', '%d' ),
		array( '%d' )
	);

	return $too_fast;
}

/**
 * Whether this IP may request another device authorization.
 *
 * Issuance is unauthenticated and inserts a row per request, so without a
 * bound a single caller can grow the table faster than the capped cleanup
 * batches reclaim it. Per-IP fixed window, the same shape as the
 * registration and verification limiters.
 *
 * Keyed on IP rather than client_id on purpose: dynamic registration is
 * open, so a caller that wanted to evade a per-client bound could simply
 * register another client.
 *
 * @param string $ip Client IP.
 * @return bool True when the request should be refused.
 */
function wp_native_auth_oauth_device_authorization_rate_limited( string $ip ): bool {
	$key   = 'wp_native_auth_oauth_devauth_' . hash( 'sha256', $ip );
	$count = (int) get_transient( $key );

	/**
	 * Filter the per-IP device authorization request limit.
	 *
	 * @param int $limit Requests allowed per window.
	 */
	$limit = (int) apply_filters( 'wp_native_auth_oauth_device_authorization_rate_limit', WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_LIMIT );

	if ( $count >= $limit ) {
		return true;
	}

	set_transient( $key, $count + 1, WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_WINDOW );

	return false;
}

/**
 * Whether this IP may attempt another user-code verification.
 *
 * RFC 8628 §5.2: the user code is short enough to brute-force, so the
 * verification endpoint must be rate-limited. Per-IP fixed window, the
 * same shape as the dynamic-registration limiter.
 *
 * @param string $ip Client IP.
 * @return bool True when the attempt should be refused.
 */
function wp_native_auth_oauth_device_verify_rate_limited( string $ip ): bool {
	$key   = 'wp_native_auth_oauth_devver_' . hash( 'sha256', $ip );
	$count = (int) get_transient( $key );

	/**
	 * Filter the per-IP user-code verification attempt limit.
	 *
	 * @param int $limit Attempts allowed per window.
	 */
	$limit = (int) apply_filters( 'wp_native_auth_oauth_device_verify_rate_limit', WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_LIMIT );

	if ( $count >= $limit ) {
		return true;
	}

	set_transient( $key, $count + 1, WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_WINDOW );

	return false;
}

/**
 * Delete expired device codes in bounded batches.
 *
 * Mirrors the authorization-code cleanup: rows survive one day past
 * expiry so a claimed row remains as evidence, then are removed.
 *
 * @param int $limit Max rows to delete.
 * @return int Rows deleted.
 */
function wp_native_auth_cleanup_oauth_device_codes( int $limit = 500 ): int {
	global $wpdb;

	$table = wp_native_auth_oauth_device_codes_table_name();
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

/** Delete a user's device codes when the user is deleted. */
function wp_native_auth_oauth_delete_user_device_codes( int $user_id ): void {
	global $wpdb;

	$wpdb->delete( wp_native_auth_oauth_device_codes_table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
}
