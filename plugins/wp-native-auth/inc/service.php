<?php
/**
 * Generic token lifecycle service for wp-native-auth.
 *
 * Issue, refresh-rotate, revoke, and validate refresh tokens — plus the
 * login and registration flows that wire those primitives together with
 * WordPress's native authentication.
 *
 * Lineage: forked from extrachill-users/inc/auth-tokens/service.php and
 * generalized. Anything Extra Chill specific (community-blog membership,
 * Turnstile, user blocking, profile URL decoration) has been removed and
 * is exposed via the extension hooks documented in SCHEMAS.md so plugins
 * like extrachill-users can layer that policy without this plugin
 * knowing about them.
 *
 * @package WPNative\Auth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/tokens.php';

/**
 * Resolve the refresh token table name.
 *
 * The canonical helper lives in the M4.1 plugin bootstrap. We declare a
 * fallback inside an `if ( ! function_exists( ... ) )` guard so this file
 * compiles standalone before M4.1 merges. M4.1's definition takes
 * precedence at runtime.
 */
if ( ! function_exists( 'wp_native_auth_refresh_token_table_name' ) ) {
	/**
	 * Network-wide refresh token table name.
	 *
	 * Uses `$wpdb->base_prefix` (not `$wpdb->prefix`) so the table is shared
	 * across every blog in a multisite install — token auth is a
	 * network-wide concern.
	 *
	 * @return string Fully-qualified table name.
	 */
	function wp_native_auth_refresh_token_table_name(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'wp_native_auth_refresh_tokens';
	}
}

/**
 * Issue (or update on conflict) a refresh token for a user/device pair.
 *
 * The `(user_id, device_id)` pair is unique in the schema. Re-issuing
 * for the same pair updates the existing row in place rather than
 * inserting a duplicate, which keeps the device's session monotonic.
 *
 * The refresh TTL is filterable via `wp_native_auth_refresh_token_ttl`.
 *
 * @param int    $user_id     User ID.
 * @param string $device_id   Device ID (UUID v4).
 * @param string $device_name Optional human-readable device name.
 * @return array{token:string, expires_at:int} Plaintext token and Unix expiry.
 */
function wp_native_auth_issue_refresh_token( int $user_id, string $device_id, string $device_name = '' ): array {
	global $wpdb;

	$table_name = wp_native_auth_refresh_token_table_name();

	$ttl = (int) apply_filters(
		'wp_native_auth_refresh_token_ttl',
		WP_NATIVE_AUTH_REFRESH_TOKEN_TTL,
		$user_id
	);
	if ( $ttl <= 0 ) {
		$ttl = WP_NATIVE_AUTH_REFRESH_TOKEN_TTL;
	}

	$now_ts     = time();
	$now        = wp_native_auth_mysql_gmt( $now_ts );
	$expires_ts = $now_ts + $ttl;
	$expires_at = wp_native_auth_mysql_gmt( $expires_ts );

	$refresh_token = wp_native_auth_generate_opaque_token();
	$token_hash    = wp_native_auth_hash_refresh_token( $refresh_token );

	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT id FROM {$table_name} WHERE user_id = %d AND device_id = %s LIMIT 1",
			$user_id,
			$device_id
		)
	);

	$data = array(
		'user_id'            => $user_id,
		'device_id'          => $device_id,
		'device_name'        => '' !== $device_name ? $device_name : null,
		'refresh_token_hash' => $token_hash,
		'last_used_at'       => $now,
		'expires_at'         => $expires_at,
		'revoked_at'         => null,
	);

	if ( $existing_id ) {
		$wpdb->update(
			$table_name,
			$data,
			array( 'id' => (int) $existing_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	} else {
		$data['created_at'] = $now;
		$wpdb->insert(
			$table_name,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	return array(
		'token'      => $refresh_token,
		'expires_at' => $expires_ts,
	);
}

/**
 * Build the public User payload for login/refresh/me responses.
 *
 * The shape is fixed by SCHEMAS.md (id, username, display_name, email,
 * avatar_url, roles, registered_at). Consumers can decorate the array via
 * the `wp_native_auth_user_payload` filter — that's where extrachill-users
 * adds `profile_url`, etc.
 *
 * @param WP_User             $user    WordPress user.
 * @param array<string,mixed> $context Optional. Additional context (e.g. device_id, reason).
 * @return array<string,mixed> User payload.
 */
function wp_native_auth_build_user_payload( WP_User $user, array $context = array() ): array {
	$registered_ts = strtotime( (string) $user->user_registered . ' UTC' );
	$registered_at = $registered_ts ? gmdate( 'c', (int) $registered_ts ) : '';

	$roles = array();
	if ( isset( $user->roles ) && is_array( $user->roles ) ) {
		$roles = array_values( array_map( 'strval', $user->roles ) );
	}

	$payload = array(
		'id'            => (int) $user->ID,
		'username'      => (string) $user->user_login,
		'display_name'  => (string) $user->display_name,
		'email'         => (string) $user->user_email,
		'avatar_url'    => (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		'roles'         => $roles,
		'registered_at' => $registered_at,
	);

	/**
	 * Filter the User payload returned in auth responses.
	 *
	 * @param array   $payload User array (id, username, display_name, email, avatar_url, roles, registered_at).
	 * @param WP_User $user    Underlying WP_User object.
	 * @param array   $context Additional context (device_id, reason, etc.).
	 */
	$filtered = apply_filters( 'wp_native_auth_user_payload', $payload, $user, $context );

	return is_array( $filtered ) ? $filtered : $payload;
}

/**
 * Authenticate a user by credentials and issue a fresh token pair.
 *
 * Flow:
 *   1. Validate `device_id` is a UUID v4.
 *   2. Apply `wp_native_auth_pre_authenticate` filter — short-circuits
 *      before WP touches the password (Turnstile, IP block, etc.).
 *   3. Call `wp_authenticate()` for username/email + password check.
 *   4. Apply `wp_native_auth_pre_login` filter on the resolved user —
 *      lets extensions block specific accounts (suspended, not a member
 *      of an EC blog, etc.).
 *   5. Optionally set the WP browsing cookie for same-origin web clients.
 *   6. Issue an access token + refresh token bound to the device.
 *   7. Fire `wp_native_auth_after_login`.
 *
 * @param string               $identifier Username or email.
 * @param string               $password   Plaintext password.
 * @param string               $device_id  Device ID (UUID v4).
 * @param array<string,mixed>  $options    Optional. {
 *     @type string $device_name Human-readable device name.
 *     @type bool   $remember    Extend cookie session length when set_cookie is true.
 *     @type bool   $set_cookie  Also set a WordPress browsing cookie.
 * }
 * @return array<string,mixed>|WP_Error Token pair + User on success, WP_Error on failure.
 */
function wp_native_auth_login_with_tokens( string $identifier, string $password, string $device_id, array $options = array() ) {
	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember    = ! empty( $options['remember'] );
	$set_cookie  = ! empty( $options['set_cookie'] );

	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$context = array(
		'identifier' => $identifier,
		'device_id'  => $device_id,
	);

	/**
	 * Filter to short-circuit authentication before password check.
	 *
	 * Return a WP_Error to block (e.g. Turnstile failure, IP block).
	 *
	 * @param null|WP_Error $blocked    Null to continue, WP_Error to block.
	 * @param string        $identifier Username or email being authenticated.
	 * @param array         $context    Login context.
	 */
	$pre_auth = apply_filters( 'wp_native_auth_pre_authenticate', null, $identifier, $context );
	if ( is_wp_error( $pre_auth ) ) {
		return $pre_auth;
	}

	$user = wp_authenticate( $identifier, $password );
	if ( is_wp_error( $user ) ) {
		return new WP_Error(
			'invalid_credentials',
			__( 'Invalid username or password.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	if ( ! ( $user instanceof WP_User ) ) {
		return new WP_Error(
			'invalid_credentials',
			__( 'Invalid username or password.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Filter to block a resolved user post-authentication.
	 *
	 * Return a WP_Error to block (e.g. user is suspended, not a member of
	 * a required blog). Null/non-WP_Error continues the flow.
	 *
	 * @param null|WP_Error $blocked Null to continue, WP_Error to block.
	 * @param WP_User       $user    Authenticated user.
	 * @param array         $context Login context.
	 */
	$pre_login = apply_filters( 'wp_native_auth_pre_login', null, $user, $context );
	if ( is_wp_error( $pre_login ) ) {
		return $pre_login;
	}

	if ( $set_cookie ) {
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	$access  = wp_native_auth_generate_access_token( (int) $user->ID, $device_id );
	$refresh = wp_native_auth_issue_refresh_token( (int) $user->ID, $device_id, $device_name );

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'login',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	/**
	 * Fires after a successful token-based login.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair (access + refresh + ISO expiries).
	 */
	do_action( 'wp_native_auth_after_login', (int) $user->ID, $device_id, $token_pair );

	return $response;
}

/**
 * Rotate a refresh token: validate the supplied token, invalidate it, and
 * issue a fresh access + refresh pair.
 *
 * Refresh rotation enforces a 5-second per-device rate limit to prevent
 * runaway client retry loops from generating churn in the tokens table.
 *
 * @param string $refresh_token Plaintext refresh token.
 * @param string $device_id     Device ID (UUID v4).
 * @return array<string,mixed>|WP_Error Token pair + User on success.
 */
function wp_native_auth_refresh_tokens( string $refresh_token, string $device_id ) {
	global $wpdb;

	if ( '' === $refresh_token ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Refresh token is required.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// Per-device rate limit. Prevents a buggy client retry loop from
	// rotating tokens dozens of times per second.
	$rate_key     = 'wp_native_auth_refresh_' . md5( $device_id );
	$last_refresh = get_transient( $rate_key );
	if ( $last_refresh && ( time() - (int) $last_refresh ) < WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS ) {
		return new WP_Error(
			'rate_limited',
			__( 'Please wait before refreshing tokens.', 'wp-native-auth' ),
			array( 'status' => 429 )
		);
	}
	set_transient( $rate_key, time(), MINUTE_IN_SECONDS );

	$table_name = wp_native_auth_refresh_token_table_name();
	$token_hash = wp_native_auth_hash_refresh_token( $refresh_token );

	$session = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT * FROM {$table_name} WHERE device_id = %s AND refresh_token_hash = %s LIMIT 1",
			$device_id,
			$token_hash
		),
		ARRAY_A
	);

	if ( empty( $session ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Invalid refresh token.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	if ( ! empty( $session['revoked_at'] ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Refresh token has been revoked.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$now_ts        = time();
	$now           = wp_native_auth_mysql_gmt( $now_ts );
	$expires_at_ts = strtotime( (string) $session['expires_at'] . ' UTC' );

	if ( $expires_at_ts && $expires_at_ts < $now_ts ) {
		return new WP_Error(
			'refresh_token_expired',
			__( 'Refresh token has expired.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$user_id = (int) $session['user_id'];
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'invalid_user',
			__( 'User not found.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Run the same pre_login policy filter on refresh that login uses.
	 *
	 * Lets extensions revoke active sessions when a user is suspended /
	 * removed from a required blog mid-session.
	 */
	$context = array(
		'device_id' => $device_id,
		'reason'    => 'refresh',
	);
	$blocked = apply_filters( 'wp_native_auth_pre_login', null, $user, $context );
	if ( is_wp_error( $blocked ) ) {
		return $blocked;
	}

	$ttl = (int) apply_filters(
		'wp_native_auth_refresh_token_ttl',
		WP_NATIVE_AUTH_REFRESH_TOKEN_TTL,
		$user_id
	);
	if ( $ttl <= 0 ) {
		$ttl = WP_NATIVE_AUTH_REFRESH_TOKEN_TTL;
	}

	$new_refresh_token = wp_native_auth_generate_opaque_token();
	$new_token_hash    = wp_native_auth_hash_refresh_token( $new_refresh_token );
	$new_expires_ts    = $now_ts + $ttl;
	$new_expires_at    = wp_native_auth_mysql_gmt( $new_expires_ts );

	$updated = $wpdb->update(
		$table_name,
		array(
			'refresh_token_hash' => $new_token_hash,
			'last_used_at'       => $now,
			'expires_at'         => $new_expires_at,
			'revoked_at'         => null,
		),
		array( 'id' => (int) $session['id'] ),
		array( '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		return new WP_Error(
			'refresh_update_failed',
			__( 'Failed to rotate refresh token.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	$access = wp_native_auth_generate_access_token( $user_id, $device_id );

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $new_refresh_token,
		'refresh_expires_at' => gmdate( 'c', (int) $new_expires_ts ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'refresh',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	/**
	 * Fires after a successful refresh-token rotation.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair.
	 */
	do_action( 'wp_native_auth_after_refresh', $user_id, $device_id, $token_pair );

	return $response;
}

/**
 * Revoke the refresh token for a user/device pair.
 *
 * Used by `auth.logout` (current device) and `auth.revoke-session`
 * (remote sign-out of another device). Idempotent — revoking an
 * already-revoked or non-existent session returns false but does not error.
 *
 * @param int    $user_id   User ID.
 * @param string $device_id Device ID (UUID v4).
 * @return bool True if a row was revoked, false otherwise.
 */
function wp_native_auth_revoke_refresh_token( int $user_id, string $device_id ): bool {
	global $wpdb;

	$table_name = wp_native_auth_refresh_token_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );

	$updated = $wpdb->update(
		$table_name,
		array( 'revoked_at' => $now ),
		array(
			'user_id'   => $user_id,
			'device_id' => $device_id,
		),
		array( '%s' ),
		array( '%d', '%s' )
	);

	$revoked = ( false !== $updated && $updated > 0 );

	if ( $revoked ) {
		/**
		 * Fires after a refresh token is revoked (logout / remote sign-out).
		 *
		 * @param int    $user_id   User ID.
		 * @param string $device_id Device ID whose session was revoked.
		 */
		do_action( 'wp_native_auth_after_logout', $user_id, $device_id );
	}

	return $revoked;
}

/**
 * List active device sessions for a user.
 *
 * Returns one row per device that has a non-revoked, non-expired refresh
 * token. The shape matches the `auth.sessions` output schema in
 * SCHEMAS.md exactly.
 *
 * @param int    $user_id           User ID.
 * @param string $current_device_id Optional. The device making the request,
 *                                  so the matching row gets `current = true`.
 * @return array<int,array<string,mixed>> List of session rows.
 */
function wp_native_auth_list_user_sessions( int $user_id, string $current_device_id = '' ): array {
	global $wpdb;

	$table_name = wp_native_auth_refresh_token_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT device_id, device_name, created_at, last_used_at, expires_at
			 FROM {$table_name}
			 WHERE user_id = %d
			   AND revoked_at IS NULL
			   AND expires_at > %s
			 ORDER BY last_used_at DESC, created_at DESC",
			$user_id,
			$now
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$sessions = array();
	foreach ( $rows as $row ) {
		$device_id    = (string) $row['device_id'];
		$created_ts   = strtotime( (string) $row['created_at'] . ' UTC' );
		$expires_ts   = strtotime( (string) $row['expires_at'] . ' UTC' );
		$last_used_ts = ! empty( $row['last_used_at'] )
			? strtotime( (string) $row['last_used_at'] . ' UTC' )
			: false;

		$sessions[] = array(
			'device_id'    => $device_id,
			'device_name'  => null !== $row['device_name'] && '' !== $row['device_name']
				? (string) $row['device_name']
				: null,
			'created_at'   => $created_ts ? gmdate( 'c', (int) $created_ts ) : '',
			'last_used_at' => $last_used_ts ? gmdate( 'c', (int) $last_used_ts ) : null,
			'expires_at'   => $expires_ts ? gmdate( 'c', (int) $expires_ts ) : '',
			'current'      => ( '' !== $current_device_id && $device_id === $current_device_id ),
		);
	}

	return $sessions;
}

/**
 * Register a new user by email + password and issue a fresh token pair.
 *
 * Flow:
 *   1. Validate email via `is_email()`.
 *   2. Validate password length >= 8.
 *   3. Validate password === password_confirm.
 *   4. Validate `device_id` is a UUID v4.
 *   5. Apply `wp_native_auth_pre_authenticate` filter — short-circuits
 *      before any user creation (CAPTCHA, IP block, etc.).
 *   6. Check `email_exists()` → return generic registration_failed
 *      (prevents email enumeration).
 *   7. Generate a deterministic username via `wp_hash()`.
 *   8. Apply `wp_native_auth_pre_register` filter — lets consumers
 *      adjust the username, set initial meta, or abort.
 *   9. Call `wp_create_user()` for the actual account creation.
 *  10. Apply `wp_native_auth_pre_login` filter — same post-user policy
 *      as login (user blocking, membership checks, etc.).
 *  11. Issue access + refresh tokens bound to the device.
 *  12. Fire `wp_native_auth_after_register`.
 *
 * @param string               $email            Email address.
 * @param string               $password         Plaintext password.
 * @param string               $password_confirm Must match $password.
 * @param string               $device_id        Device ID (UUID v4).
 * @param array<string,mixed>  $options          Optional. {
 *     @type string $device_name Human-readable device name.
 * }
 * @return array<string,mixed>|WP_Error Token pair + User on success, WP_Error on failure.
 */
function wp_native_auth_register_with_tokens( string $email, string $password, string $password_confirm, string $device_id, array $options = array() ) {
	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';

	// 1. Validate email format.
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please provide a valid email address.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 2. Validate password length.
	if ( strlen( $password ) < 8 ) {
		return new WP_Error(
			'password_too_short',
			__( 'Password must be at least 8 characters.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 3. Validate password confirmation.
	if ( $password !== $password_confirm ) {
		return new WP_Error(
			'password_mismatch',
			__( 'Passwords do not match.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 4. Validate device_id.
	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 5. Pre-authenticate filter (CAPTCHA, IP block, etc.).
	$context = array(
		'email'     => $email,
		'device_id' => $device_id,
	);

	/** This filter is documented in wp_native_auth_login_with_tokens(). */
	$pre_auth = apply_filters( 'wp_native_auth_pre_authenticate', null, $email, $context );
	if ( is_wp_error( $pre_auth ) ) {
		return $pre_auth;
	}

	// 6. Check if email already exists — use a generic error to prevent enumeration.
	if ( email_exists( $email ) ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 7. Generate a deterministic default username.
	$username = 'user' . substr( wp_hash( $email ), 0, 8 );

	// 8. Pre-register filter — consumers can adjust username, set initial meta, or abort.
	$registration_data = array(
		'email'    => $email,
		'password' => $password,
		'username' => $username,
	);

	/**
	 * Filter to modify registration data or abort registration.
	 *
	 * Return a WP_Error to abort. Return an array with modified
	 * registration data (e.g. different username) to continue.
	 * Null passes through unchanged.
	 *
	 * @param null|array|WP_Error $result            Null to continue, WP_Error to abort, array to override.
	 * @param array               $registration_data Registration data (email, password, username).
	 * @param array               $context           Registration context (email, device_id).
	 */
	$pre_register = apply_filters( 'wp_native_auth_pre_register', null, $registration_data, $context );

	if ( is_wp_error( $pre_register ) ) {
		return $pre_register;
	}

	// Allow consumers to override the username via the filter.
	if ( is_array( $pre_register ) && ! empty( $pre_register['username'] ) ) {
		$username = (string) $pre_register['username'];
	}

	// 9. Create the user.
	$user_id = wp_create_user( $username, $password, $email );
	if ( is_wp_error( $user_id ) ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	// 10. Pre-login filter — same post-user policy as login.
	$login_context = array(
		'device_id' => $device_id,
		'reason'    => 'register',
	);

	/** This filter is documented in wp_native_auth_login_with_tokens(). */
	$pre_login = apply_filters( 'wp_native_auth_pre_login', null, $user, $login_context );
	if ( is_wp_error( $pre_login ) ) {
		return $pre_login;
	}

	// 11. Issue tokens.
	$access  = wp_native_auth_generate_access_token( $user_id, $device_id );
	$refresh = wp_native_auth_issue_refresh_token( $user_id, $device_id, $device_name );

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'register',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	// 12. Fire after-register action.
	/**
	 * Fires after a successful user registration.
	 *
	 * @param int    $user_id    Newly created user ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair (access + refresh + ISO expiries).
	 */
	do_action( 'wp_native_auth_after_register', $user_id, $device_id, $token_pair );

	return $response;
}
