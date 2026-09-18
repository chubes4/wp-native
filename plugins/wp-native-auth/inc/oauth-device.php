<?php
/**
 * The device authorization grant (RFC 8628).
 *
 * Exists for one topology the authorization-code flow cannot serve: the
 * client and the browser are on different machines, so no redirect — not
 * even a loopback one — can carry the authorization response back. A CLI
 * on a VPS, a container, a CI runner, a TV app, an agent sandbox.
 *
 * Three moving parts:
 *
 *   POST /device_authorization  client asks for a device code + user code
 *   GET|POST /device            user enters the code and approves, on a
 *                               device that does have a browser
 *   POST /token                 client polls with grant_type=...device_code
 *
 * The verification screen routes unauthenticated users through normal
 * WordPress login, exactly as `/authorize` does, so site authentication
 * policies (including two-factor plugins) keep working untouched.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * The RFC 8628 §3.4 grant type identifier.
 */
const WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

/**
 * The end-user verification URL (RFC 8628 §3.2 `verification_uri`).
 *
 * @return string
 */
function wp_native_auth_oauth_device_verification_url(): string {
	return wp_native_auth_oauth_endpoint_url( 'device_verification' );
}

/**
 * Handle POST /device_authorization (RFC 8628 §3.1).
 *
 * No redirect URI is involved, so — unlike `/authorize` — a client with
 * no registered redirect URIs is still perfectly valid here. Only client
 * resolution, scope, and the optional resource indicator are validated.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_device_authorization(): void {
	wp_native_auth_ensure_schema();

	$body   = wp_native_auth_oauth_read_body();
	$client = wp_native_auth_oauth_authenticate_public_client( $body );

	$scope = isset( $body['scope'] ) ? trim( (string) $body['scope'] ) : WP_NATIVE_AUTH_OAUTH_SCOPE;
	if ( '' === $scope ) {
		$scope = WP_NATIVE_AUTH_OAUTH_SCOPE;
	}
	if ( WP_NATIVE_AUTH_OAUTH_SCOPE !== $scope ) {
		wp_native_auth_oauth_send_error( 'invalid_scope', __( 'The requested scope is not available.', 'wp-native-auth' ), 400 );
	}

	$resource = isset( $body['resource'] ) ? trim( (string) $body['resource'] ) : '';
	if ( '' !== $resource && ! wp_native_auth_oauth_validate_resource( $resource ) ) {
		wp_native_auth_oauth_send_error( 'invalid_target', __( 'The resource indicator is not a valid RFC 8707 audience.', 'wp-native-auth' ), 400 );
	}

	$created = wp_native_auth_oauth_create_device_code( (string) $client['client_id'], $scope, $resource );

	if ( is_wp_error( $created ) ) {
		wp_native_auth_oauth_send_error(
			wp_native_auth_oauth_error_code( $created ),
			$created->get_error_message(),
			wp_native_auth_oauth_error_status( $created )
		);
	}

	$verification_uri = wp_native_auth_oauth_device_verification_url();

	/**
	 * Fires when a device authorization request is issued.
	 *
	 * @param string $client_id OAuth client identifier.
	 * @param string $resource  Bound resource audience ('' when none).
	 */
	do_action( 'wp_native_auth_oauth_device_authorization_issued', (string) $client['client_id'], $resource );

	wp_native_auth_oauth_send_json(
		array(
			'device_code'               => $created['device_code'],
			'user_code'                 => $created['user_code'],
			'verification_uri'          => $verification_uri,
			'verification_uri_complete' => add_query_arg( 'user_code', rawurlencode( $created['user_code'] ), $verification_uri ),
			'expires_in'                => max( 0, $created['expires_at'] - time() ),
			'interval'                  => $created['interval'],
		)
	);
}

/**
 * Handle GET /device — the user-code entry screen.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_device_verification(): void {
	wp_native_auth_ensure_schema();

	// Nonce verification does not apply: this is a bookmarkable entry point
	// reached from `verification_uri_complete`, not a form this site rendered.
	// The approval POST that follows does verify a nonce.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Entry point; see above.
	$params = wp_unslash( $_GET );

	$user_id = wp_native_auth_oauth_require_logged_in_user();

	$user_code = isset( $params['user_code'] ) ? (string) $params['user_code'] : '';

	if ( '' === $user_code ) {
		wp_native_auth_oauth_render_device_form( array( 'user_code' => '' ) );
	}

	wp_native_auth_oauth_render_device_confirmation( $user_code, $user_id );
}

/**
 * Handle POST /device — code submission and the approval decision.
 *
 * Two shapes arrive here: the code-entry form (no signed bundle yet), and
 * the approve/deny decision on the confirmation screen (signed bundle).
 *
 * @return never
 */
function wp_native_auth_oauth_handle_device_verification_decision(): void {
	wp_native_auth_ensure_schema();

	$body    = wp_native_auth_oauth_read_body();
	$user_id = wp_native_auth_oauth_require_logged_in_user();

	$nonce = isset( $body['_wpnonce'] ) ? (string) $body['_wpnonce'] : '';
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_native_auth_oauth_device' ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The form has expired. Please start the connection again.', 'wp-native-auth' ), 403 );
	}

	$signature = isset( $body['oauth_signature'] ) ? (string) $body['oauth_signature'] : '';
	$bundle    = isset( $body['oauth_request'] ) ? wp_native_auth_oauth_decode_bundle( (string) $body['oauth_request'] ) : null;

	// No signed bundle yet: this is the code-entry form.
	if ( ! is_array( $bundle ) || '' === $signature ) {
		wp_native_auth_oauth_render_device_confirmation(
			isset( $body['user_code'] ) ? (string) $body['user_code'] : '',
			$user_id
		);
	}

	if ( ! wp_native_auth_oauth_verify_request( $signature, $bundle ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request could not be verified. Please start the connection again.', 'wp-native-auth' ), 400 );
	}

	$required = array( 'device_row_id', 'client_id', 'scope', 'user_id', 'issued' );
	if ( array_diff( $required, array_keys( $bundle ) ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request is incomplete.', 'wp-native-auth' ), 400 );
	}

	if ( (int) $bundle['user_id'] !== $user_id ) {
		wp_native_auth_oauth_send_page_error( __( 'The signed-in account changed before consent was given. Please start the connection again.', 'wp-native-auth' ), 403 );
	}

	if ( time() - (int) $bundle['issued'] > (int) apply_filters( 'wp_native_auth_oauth_request_ttl', WP_NATIVE_AUTH_OAUTH_REQUEST_TTL ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request expired. Please start the connection again.', 'wp-native-auth' ), 400 );
	}

	$decision = isset( $body['oauth_decision'] ) ? (string) $body['oauth_decision'] : '';

	if ( 'approve' !== $decision && 'deny' !== $decision ) {
		wp_native_auth_oauth_send_page_error( __( 'Unknown consent decision.', 'wp-native-auth' ), 400 );
	}

	$recorded = wp_native_auth_oauth_decide_device_code(
		(int) $bundle['device_row_id'],
		$user_id,
		'approve' === $decision ? 'approved' : 'denied'
	);

	if ( ! $recorded ) {
		wp_native_auth_oauth_send_page_error( __( 'This request has expired or was already decided. Please start the connection again.', 'wp-native-auth' ), 400 );
	}

	if ( 'approve' === $decision ) {
		/**
		 * Fires when a user approves a device authorization request.
		 *
		 * @param int    $user_id   Approving user.
		 * @param string $client_id OAuth client identifier.
		 */
		do_action( 'wp_native_auth_oauth_device_approved', $user_id, (string) $bundle['client_id'] );
	}

	wp_native_auth_oauth_render_device_result( 'approve' === $decision );
}

/**
 * Return the current user id, or route through WordPress login.
 *
 * @return int Authenticated user id.
 */
function wp_native_auth_oauth_require_logged_in_user(): int {
	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		return $user_id;
	}

	$current = esc_url_raw( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ) );
	wp_native_auth_oauth_redirect( wp_login_url( untrailingslashit( home_url() ) . $current ) );
}

/**
 * Resolve a submitted user code and render the approve/deny screen.
 *
 * Rate limiting happens here rather than at the row lookup, because this
 * is the only path on which an attacker can guess a user code.
 *
 * @param string $user_code Raw user input.
 * @param int    $user_id   Authenticated user id.
 * @return never
 */
function wp_native_auth_oauth_render_device_confirmation( string $user_code, int $user_id ): void {
	if ( wp_native_auth_oauth_device_verify_rate_limited( wp_native_auth_oauth_client_ip() ) ) {
		wp_native_auth_oauth_send_page_error( __( 'Too many attempts. Please wait a few minutes and try again.', 'wp-native-auth' ), 429 );
	}

	$row = wp_native_auth_oauth_find_device_code_by_user_code( $user_code );

	if ( null === $row ) {
		wp_native_auth_oauth_render_device_form(
			array(
				'user_code' => $user_code,
				'error'     => __( 'That code is not valid, or it has expired. Check the code and try again.', 'wp-native-auth' ),
			)
		);
	}

	$client = wp_native_auth_oauth_resolve_client( (string) $row['client_id'] );

	if ( is_wp_error( $client ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The application requesting access could not be identified.', 'wp-native-auth' ), 401 );
	}

	$bundle = array(
		'device_row_id' => (int) $row['id'],
		'client_id'     => (string) $row['client_id'],
		'scope'         => isset( $row['scope'] ) ? (string) $row['scope'] : WP_NATIVE_AUTH_OAUTH_SCOPE,
		'user_id'       => $user_id,
		'issued'        => time(),
	);

	wp_native_auth_oauth_render_consent(
		array(
			'client_name'      => null !== $client['client_name'] ? $client['client_name'] : (string) $client['client_id'],
			'client_uri'       => null !== $client['client_uri'] ? $client['client_uri'] : '',
			'client_id'        => (string) $client['client_id'],
			'scope'            => (string) $bundle['scope'],
			'resource'         => isset( $row['resource'] ) ? (string) $row['resource'] : '',
			'bundle'           => wp_native_auth_oauth_encode_bundle( $bundle ),
			'signature'        => wp_native_auth_oauth_sign_request( $bundle ),
			'authorize_action' => wp_create_nonce( 'wp_native_auth_oauth_device' ),
			'nonce_action'     => 'wp_native_auth_oauth_device',
			'is_device_flow'   => true,
		)
	);
}

/**
 * Render the user-code entry form.
 *
 * @param array<string,mixed> $args View args (user_code, optional error).
 * @return never
 */
function wp_native_auth_oauth_render_device_form( array $args ): void {
	$args = array_merge(
		array(
			'user_code'    => '',
			'error'        => '',
			'nonce_action' => 'wp_native_auth_oauth_device',
		),
		$args
	);

	$default_template = __DIR__ . '/oauth-device-form.php';

	/**
	 * Filter the device user-code entry template path.
	 *
	 * @param string              $template Absolute path to the template file.
	 * @param array<string,mixed> $args     View args for the template.
	 */
	$template = (string) apply_filters( 'wp_native_auth_oauth_device_form_template', $default_template, $args );

	if ( '' === $template || ! is_readable( $template ) ) {
		$template = $default_template;
	}

	wp_native_auth_oauth_begin_device_page();

	// phpcs:ignore WordPress.Security.EscapeOutput -- The template escapes its own output.
	include $template;

	exit;
}

/**
 * Render the terminal "you can close this window" screen.
 *
 * @param bool $approved Whether the request was approved.
 * @return never
 */
function wp_native_auth_oauth_render_device_result( bool $approved ): void {
	$args             = array( 'approved' => $approved );
	$default_template = __DIR__ . '/oauth-device-result.php';

	/**
	 * Filter the device result template path.
	 *
	 * @param string              $template Absolute path to the template file.
	 * @param array<string,mixed> $args     View args for the template.
	 */
	$template = (string) apply_filters( 'wp_native_auth_oauth_device_result_template', $default_template, $args );

	if ( '' === $template || ! is_readable( $template ) ) {
		$template = $default_template;
	}

	wp_native_auth_oauth_begin_device_page();

	// phpcs:ignore WordPress.Security.EscapeOutput -- The template escapes its own output.
	include $template;

	exit;
}

/**
 * Clear the inherited 404 state before rendering a device-flow screen.
 *
 * Same reasoning as wp_native_auth_oauth_render_consent(): this runs on
 * `template_redirect` after WordPress has already resolved the path
 * against the posts table and flagged a 404. Serving a real screen under
 * a 404 status gives it a "Page not found" title and makes HTTP clients
 * that check status before parsing treat it as broken.
 *
 * Only the status is shared. Each caller resolves and includes its own
 * template so that every filter name is a literal at its call site —
 * passing the hook name through a variable would make these filters
 * invisible to a grep, which is how WordPress hooks are discovered.
 *
 * @return void
 */
function wp_native_auth_oauth_begin_device_page(): void {
	status_header( 200 );
	nocache_headers();

	global $wp_query;
	if ( $wp_query instanceof WP_Query ) {
		$wp_query->is_404 = false;
	}
}

/**
 * Grant tokens for a device_code exchange (RFC 8628 §3.4).
 *
 * @param array<string,mixed> $body Token request body.
 * @return never
 */
function wp_native_auth_oauth_token_from_device_code( array $body ): void {
	$client = wp_native_auth_oauth_authenticate_public_client( $body );

	$device_code = isset( $body['device_code'] ) ? (string) $body['device_code'] : '';

	if ( '' === $device_code ) {
		wp_native_auth_oauth_send_error( 'invalid_request', __( 'The device_code parameter is required.', 'wp-native-auth' ), 400 );
	}

	$grant = wp_native_auth_oauth_exchange_device_code( $device_code, (string) $client['client_id'], null !== $client['client_name'] ? $client['client_name'] : '' );

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
 * Exchange an approved device code for a token response.
 *
 * Transport-independent core of the device grant. Token issuance is the
 * shared `wp_native_auth_oauth_build_grant()` path, so a device grant
 * lands on the same refresh-session row, with the same rotation and reuse
 * detection, as a code grant for the same (user, client) pair.
 *
 * @param string $device_code Plaintext device code.
 * @param string $client_id   Authenticated client identifier.
 * @param string $client_name Display name for the session row.
 * @return array<string,mixed>|WP_Error Token response payload.
 */
function wp_native_auth_oauth_exchange_device_code( string $device_code, string $client_id, string $client_name = '' ): array|WP_Error {
	$row = wp_native_auth_oauth_poll_device_code( $device_code, $client_id );

	if ( is_wp_error( $row ) ) {
		return $row;
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
