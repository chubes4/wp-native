<?php
/**
 * The /token endpoint: authorization-code redemption with mandatory PKCE
 * verification, and refresh-token rotation through the existing
 * refresh-token lifecycle (rotation, reuse detection, and family
 * revocation are the ones this plugin already ships).
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Handle POST /token.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_token(): void {
	wp_native_auth_ensure_schema();

	$body = wp_native_auth_oauth_read_body();

	$grant_type = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

	if ( '' === $grant_type ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The grant_type parameter is required.', 'wp-native-auth' ), 400 );
	}

	if ( 'authorization_code' === $grant_type ) {
		wp_native_auth_oauth_token_from_authorization_code( $body );
	}

	if ( 'refresh_token' === $grant_type ) {
		wp_native_auth_oauth_token_from_refresh_token( $body );
	}

	wp_native_auth_oauth_send_error( 'unsupported_grant_type', __( 'Only authorization_code and refresh_token grants are supported.', 'wp-native-auth' ), 400 );
}

/**
 * Verify the presenting client and return its normalized record.
 *
 * Public-client server: the only authentication material is the
 * client_id itself, so a client_id that does not resolve is a 401
 * invalid_client with the RFC 9728 WWW-Authenticate challenge.
 *
 * @param array<string,mixed> $body Token request body.
 * @return array<string,mixed> Client record.
 */
function wp_native_auth_oauth_authenticate_public_client( array $body ): array {
	$client_id = isset( $body['client_id'] ) ? trim( (string) $body['client_id'] ) : '';

	if ( '' === $client_id ) {
		wp_native_auth_oauth_send_unauthorized( 'invalid_client', __( 'The client_id parameter is required.', 'wp-native-auth' ) );
	}

	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) && '' !== (string) $_SERVER['HTTP_AUTHORIZATION'] ) {
		// This server advertises "none" as its only client auth method;
		// client secrets have no meaning here and are refused outright.
		wp_native_auth_oauth_send_unauthorized( 'invalid_client', __( 'Client authentication is not supported; this server issues public clients only.', 'wp-native-auth' ) );
	}

	$client = wp_native_auth_oauth_resolve_client( substr( $client_id, 0, 255 ) );
	if ( is_wp_error( $client ) ) {
		wp_native_auth_oauth_send_unauthorized( 'invalid_client', __( 'Unknown client.', 'wp-native-auth' ) );
	}

	return $client;
}

/**
 * Grant tokens for an authorization_code exchange.
 *
 * Parameter order is the security order:
 *  1. `code_verifier` is REQUIRED — before anything else happens. A
 *     missing verifier is a hard invalid_request, never a silent pass
 *     (the classic PKCE bypass).
 *  2. The code is atomically claimed — exactly one redemption exists.
 *  3. The verifier is checked against the challenge bound to the code.
 *     Because the claim precedes the check, a code allows exactly one
 *     verification attempt ever.
 *
 * @param array<string,mixed> $body Token request body.
 * @return never
 */
function wp_native_auth_oauth_token_from_authorization_code( array $body ): void {
	$client = wp_native_auth_oauth_authenticate_public_client( $body );

	$code          = isset( $body['code'] ) ? (string) $body['code'] : '';
	$redirect_uri  = isset( $body['redirect_uri'] ) ? (string) $body['redirect_uri'] : '';
	$code_verifier = isset( $body['code_verifier'] ) ? (string) $body['code_verifier'] : '';

	if ( '' === $code ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The code parameter is required.', 'wp-native-auth' ), 400 );
	}

	// MISSING VERIFIER: reject outright. Treating an absent verifier as
	// "no PKCE" would silently downgrade the flow; that can never happen
	// here because codes only exist for S256-bound challenges.
	if ( '' === $code_verifier ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The code_verifier parameter is required.', 'wp-native-auth' ), 400 );
	}

	if ( ! wp_native_auth_oauth_validate_code_verifier( $code_verifier ) ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The code_verifier is malformed.', 'wp-native-auth' ), 400 );
	}

	if ( '' === $redirect_uri ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The redirect_uri parameter is required.', 'wp-native-auth' ), 400 );
	}

	$grant = wp_native_auth_oauth_exchange_code( $code, (string) $client['client_id'], $redirect_uri, $code_verifier, null !== $client['client_name'] ? $client['client_name'] : '' );

	if ( is_wp_error( $grant ) ) {
		wp_native_auth_oauth_send_error(
			wp_native_auth_oauth_error_code( $grant ),
			$grant->get_error_message(),
			wp_native_auth_oauth_error_status( $grant )
		);
	}

	wp_native_auth_oauth_send_json( $grant );
}

/**
 * Exchange an authorization code + PKCE verifier for a token response.
 *
 * The transport-independent core of the code grant. Every failure is a
 * WP_Error whose code is an OAuth error code (invalid_grant); success
 * returns the RFC 6749 §5.1 token response payload.
 *
 * @param string $code          Plaintext authorization code.
 * @param string $client_id     Authenticated client identifier.
 * @param string $redirect_uri  Redirect URI presented at the token endpoint.
 * @param string $code_verifier PKCE verifier (REQUIRED, validated here).
 * @param string $client_name   Display name for the session row.
 * @return array<string,mixed>|WP_Error Token response payload.
 */
function wp_native_auth_oauth_exchange_code( string $code, string $client_id, string $redirect_uri, string $code_verifier, string $client_name = '' ): array|WP_Error {
	// MISSING VERIFIER: rejected before the code is even claimed. This
	// is the deepest layer of the guarantee that an absent verifier can
	// never pass — the endpoint shell checks it earlier, but the core
	// refuses to touch a code without one.
	if ( '' === $code_verifier ) {
		return new WP_Error( 'invalid_request', __( 'The code_verifier parameter is required.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! wp_native_auth_oauth_validate_code_verifier( $code_verifier ) ) {
		return new WP_Error( 'invalid_request', __( 'The code_verifier is malformed.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$row = wp_native_auth_oauth_redeem_code( $code, $client_id, $redirect_uri );

	if ( is_wp_error( $row ) ) {
		return $row;
	}

	if ( ! wp_native_auth_oauth_verify_pkce( $code_verifier, (string) $row['code_challenge'] ) ) {
		return new WP_Error( 'invalid_grant', __( 'The PKCE verification failed.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$user_id = (int) $row['user_id'];
	$user    = get_userdata( $user_id );
	if ( ! $user instanceof WP_User || 0 === $user->ID ) {
		return new WP_Error( 'invalid_grant', __( 'The authorizing user no longer exists.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$resource = isset( $row['resource'] ) ? (string) $row['resource'] : '';
	$scope    = isset( $row['scope'] ) && '' !== (string) $row['scope'] ? (string) $row['scope'] : WP_NATIVE_AUTH_OAUTH_SCOPE;

	return wp_native_auth_oauth_build_grant( $user, $client_id, $resource, $scope, $client_name );
}

/**
 * Grant tokens for a refresh_token exchange.
 *
 * The presented token identifies its row via the refresh_token_hash
 * index; ownership is verified against the presenting client BEFORE
 * the existing rotation logic takes over. Rotation, sliding expiry,
 * per-device rate limiting, reuse detection, and family revocation are
 * performed by wp_native_auth_refresh_tokens() — unchanged.
 *
 * @param array<string,mixed> $body Token request body.
 * @return never
 */
function wp_native_auth_oauth_token_from_refresh_token( array $body ): void {
	$client = wp_native_auth_oauth_authenticate_public_client( $body );

	$refresh_token = isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';

	if ( '' === $refresh_token ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The refresh_token parameter is required.', 'wp-native-auth' ), 400 );
	}

	$grant = wp_native_auth_oauth_refresh_grant( $refresh_token, (string) $client['client_id'] );

	if ( is_wp_error( $grant ) ) {
		$code   = wp_native_auth_oauth_error_code( $grant );
		$status = wp_native_auth_oauth_error_status( $grant );

		// invalid_client → 401 with the Bearer challenge; the rest map
		// to RFC 6749 token-endpoint errors.
		if ( 'invalid_client' === $code ) {
			wp_native_auth_oauth_send_unauthorized( 'invalid_client', $grant->get_error_message() );
		}

		wp_native_auth_oauth_send_error( $code, $grant->get_error_message(), $status );
	}

	wp_native_auth_oauth_send_json( $grant );
}

/**
 * Rotate tokens for an OAuth refresh-token exchange.
 *
 * Transport-independent core of the refresh grant. Ownership of the
 * presented token is verified against the client before the existing
 * rotation runs. On reuse, wp_native_auth_refresh_tokens() has already
 * revoked the whole token family before the error is returned.
 *
 * @param string $refresh_token Presented refresh token.
 * @param string $client_id     Authenticated client identifier.
 * @return array<string,mixed>|WP_Error Token response payload, or WP_Error with
 *                                      invalid_client (401), slow_down (429),
 *                                      or invalid_grant (400) codes.
 */
function wp_native_auth_oauth_refresh_grant( string $refresh_token, string $client_id ): array|WP_Error {
	$session = wp_native_auth_find_refresh_session_by_token( $refresh_token );

	if ( null === $session || (string) ( $session['oauth_client_id'] ?? '' ) !== $client_id ) {
		return new WP_Error( 'invalid_client', __( 'The presented refresh token does not belong to this client.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$device_id = (string) $session['device_id'];

	$result = wp_native_auth_refresh_tokens( $refresh_token, $device_id );

	if ( is_wp_error( $result ) ) {
		$code = $result->get_error_code();

		if ( 'rate_limited' === $code ) {
			return new WP_Error( 'slow_down', $result->get_error_message(), array( 'status' => 429 ) );
		}

		// Every other failure — invalid, expired, or replayed token —
		// surfaces as invalid_grant. Reuse detection has already revoked
		// the token family inside the rotation by the time this runs.
		return new WP_Error( 'invalid_grant', $result->get_error_message(), array( 'status' => 400 ) );
	}

	return array(
		'access_token'  => (string) $result['access_token'],
		'token_type'    => 'Bearer',
		'expires_in'    => (int) apply_filters( 'wp_native_auth_access_token_ttl', WP_NATIVE_AUTH_ACCESS_TOKEN_TTL, (int) $session['user_id'] ),
		'refresh_token' => (string) $result['refresh_token'],
		'scope'         => WP_NATIVE_AUTH_OAUTH_SCOPE,
	);
}

/**
 * Mint the token-pair response for a new OAuth grant.
 *
 * Reuses the existing session primitives: the refresh token is issued
 * through wp_native_auth_issue_refresh_token() onto the stable
 * per-(user, client) device row — rotation and reuse detection come
 * with it — and the access token is a normal bearer token with the
 * resource audience and client binding stored in its payload.
 *
 * @param WP_User $user        Authorizing user.
 * @param string  $client_id   OAuth client identifier.
 * @param string  $resource    RFC 8707 resource audience ('' when none).
 * @param string  $scope       Granted scope.
 * @param string  $client_name Display name for the session row.
 * @return array<string,mixed>|WP_Error RFC 6749 §5.1 token response payload.
 */
function wp_native_auth_oauth_build_grant( WP_User $user, string $client_id, string $resource, string $scope, string $client_name = '' ): array|WP_Error {
	$device_id = wp_native_auth_oauth_device_id( (int) $user->ID, $client_id );

	$refresh = wp_native_auth_issue_refresh_token(
		(int) $user->ID,
		$device_id,
		'' !== $client_name ? $client_name : $client_id,
		array(
			'oauth_client_id' => $client_id,
			'resource'        => $resource,
		)
	);

	if ( is_wp_error( $refresh ) ) {
		return new WP_Error( 'server_error', $refresh->get_error_message(), array( 'status' => 500 ) );
	}

	$access_meta = array();
	if ( '' !== $resource ) {
		$access_meta['resource'] = $resource;
	}
	$access_meta['client_id'] = $client_id;

	$access = wp_native_auth_generate_access_token( (int) $user->ID, $device_id, $access_meta );

	if ( is_wp_error( $access ) ) {
		return new WP_Error( 'server_error', $access->get_error_message(), array( 'status' => 500 ) );
	}

	/**
	 * Fires after an OAuth grant (code exchange) completes.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $client_id OAuth client identifier.
	 * @param string $resource  Bound resource audience ('' when none).
	 */
	do_action( 'wp_native_auth_oauth_grant_issued', (int) $user->ID, $client_id, $resource );

	return array(
		'access_token'  => (string) $access['token'],
		'token_type'    => 'Bearer',
		'expires_in'    => (int) apply_filters( 'wp_native_auth_access_token_ttl', WP_NATIVE_AUTH_ACCESS_TOKEN_TTL, (int) $user->ID ),
		'refresh_token' => (string) $refresh['token'],
		'scope'         => $scope,
	);
}
