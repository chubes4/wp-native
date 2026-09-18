<?php
/**
 * The /authorize endpoint: request validation, login routing, and the
 * consent decision.
 *
 * The unauthenticated case routes through normal WordPress login so
 * existing authentication policies (including two-factor plugins) keep
 * working untouched. Consent state is carried in a signed, age-limited
 * bundle (HMAC-SHA256 over the validated parameters, bound to the
 * authorizing user) rather than trusted back from the browser form.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Validate an RFC 8707 resource indicator.
 *
 * The resource identifier must be an absolute HTTPS URI with no
 * fragment (RFC 8707 §2), capped to the storage column length.
 *
 * @param string $resource Candidate resource identifier.
 * @return bool
 */
// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- `resource` is the RFC 8707 parameter name; renaming it would diverge from the spec.
function wp_native_auth_oauth_validate_resource( string $resource ): bool {
	if ( '' === $resource || strlen( $resource ) > 191 ) {
		return false;
	}

	if ( false !== strpos( $resource, '#' ) ) {
		return false;
	}

	$parsed = wp_parse_url( $resource );
	if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
		return false;
	}

	return 'https' === strtolower( (string) $parsed['scheme'] );
}

/**
 * Validate a PKCE code challenge (S256 form).
 *
 * RFC 7636 §4.2: base64url of a SHA-256 digest — 43 characters from
 * `[A-Za-z0-9\-_]`.
 *
 * @param string $challenge Candidate challenge.
 * @return bool
 */
function wp_native_auth_oauth_validate_code_challenge( string $challenge ): bool {
	return (bool) preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $challenge );
}

/**
 * Validate a PKCE code verifier.
 *
 * RFC 7636 §4.1: 43-128 characters from the unreserved + `~` alphabet.
 *
 * @param string $verifier Candidate verifier.
 * @return bool
 */
function wp_native_auth_oauth_validate_code_verifier( string $verifier ): bool {
	return (bool) preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier );
}

/**
 * Verify a PKCE verifier against the stored S256 challenge.
 *
 * Constant-time comparison of BASE64URL(SHA256(verifier)) with the
 * challenge bound to the authorization code.
 *
 * @param string $verifier  Presented verifier.
 * @param string $challenge Stored challenge.
 * @return bool
 */
function wp_native_auth_oauth_verify_pkce( string $verifier, string $challenge ): bool {
	if ( ! wp_native_auth_oauth_validate_code_verifier( $verifier ) ) {
		return false;
	}

	$digest = hash( 'sha256', $verifier, true );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256 requires base64url of the SHA-256 digest, not obfuscation.
	$base64 = rtrim( strtr( base64_encode( $digest ), '+/', '-_' ), '=' );

	return hash_equals( $challenge, $base64 );
}

/**
 * Sign an authorization request bundle for the consent round-trip.
 *
 * @param array<string,mixed> $bundle Validated request fields.
 * @return string HMAC-SHA256 hex digest.
 */
function wp_native_auth_oauth_sign_request( array $bundle ): string {
	$encoded = wp_json_encode( $bundle );

	return hash_hmac( 'sha256', is_string( $encoded ) ? $encoded : '', wp_salt( 'auth' ) );
}

/**
 * Verify a consent-signed bundle.
 *
 * Callers must pass a decoded bundle (wp_native_auth_oauth_decode_bundle
 * handles the null case); this function only answers whether the
 * signature matches.
 *
 * @param string               $signature Stored signature.
 * @param array<string,mixed>  $bundle    Bundle as returned by the browser.
 * @return bool
 */
function wp_native_auth_oauth_verify_request( string $signature, array $bundle ): bool {
	return hash_equals( wp_native_auth_oauth_sign_request( $bundle ), $signature );
}

/**
 * Encode a bundle for transport.
 *
 * @param array<string,mixed> $bundle Bundle fields.
 * @return string Base64url-encoded JSON.
 */
function wp_native_auth_oauth_encode_bundle( array $bundle ): string {
	$encoded = wp_json_encode( $bundle );
	if ( ! is_string( $encoded ) ) {
		return '';
	}

	return wp_native_auth_base64url_encode( $encoded );
}

/**
 * Decode a transported bundle.
 *
 * @param string $encoded Base64url-encoded JSON.
 * @return array<string,mixed>|null
 */
function wp_native_auth_oauth_decode_bundle( string $encoded ): ?array {
	$raw = wp_native_auth_base64url_decode( $encoded );

	// base64_decode() returns false for invalid input; reject it (and an
	// empty decode) explicitly rather than feeding it to json_decode.
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Handle GET /authorize.
 *
 * Validation order follows RFC 6749 §4.1.2.1: the client and redirect
 * URI are validated first (failures are shown to the user directly —
 * never redirected), and only then can errors be delivered through the
 * redirect URI.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_authorize(): void {
	wp_native_auth_ensure_schema();

	// $_GET is a PHP language-level superglobal that is always an array,
	// and wp_unslash() on an array returns an array — the old
	// isset()/is_array() guards were dead type-noise, not security guards.
	// Nonce verification does not apply here: GET /authorize is the RFC 6749
	// entry point and its parameters come from a third-party OAuth client, not
	// from a form this site rendered. The request is authenticated by client
	// resolution plus exact redirect-URI matching below, and the consent POST
	// that follows does verify a nonce.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External OAuth client request; see above.
	$params = wp_unslash( $_GET );

	$client_id    = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
	$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';

	if ( '' === $client_id ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request is missing a client_id.', 'wp-native-auth' ) );
	}

	$client = wp_native_auth_oauth_resolve_client( substr( $client_id, 0, 255 ) );
	if ( is_wp_error( $client ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The application requesting access could not be identified.', 'wp-native-auth' ), 401 );
	}

	if ( '' === $redirect_uri || ! wp_native_auth_oauth_validate_redirect_uri( $client['redirect_uris'], $redirect_uri ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The redirect URI is not registered for this application.', 'wp-native-auth' ) );
	}

	// From here on, errors go back to the client via the redirect URI.
	$fail = static function ( string $error, string $description = '' ) use ( $redirect_uri, $params ): never {
		$state = isset( $params['state'] ) && is_string( $params['state'] ) ? $params['state'] : '';
		wp_native_auth_oauth_redirect( wp_native_auth_oauth_build_error_redirect( $redirect_uri, $error, $description, $state ) );
	};

	$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
	if ( '' === $response_type ) {
		$fail( 'invalid_request', 'response_type is required.' );
	}
	if ( 'code' !== $response_type ) {
		$fail( 'unsupported_response_type', 'Only the authorization code flow is supported.' );
	}

	$challenge_method = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
	$code_challenge   = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';

	if ( '' === $code_challenge ) {
		$fail( 'invalid_request', 'PKCE is required: a code_challenge must accompany every authorization request.' );
	}
	if ( 'S256' !== $challenge_method ) {
		$fail( 'invalid_request', 'Only the S256 code_challenge_method is supported.' );
	}
	if ( ! wp_native_auth_oauth_validate_code_challenge( $code_challenge ) ) {
		$fail( 'invalid_request', 'The code_challenge is malformed.' );
	}

	$scope = isset( $params['scope'] ) ? trim( (string) $params['scope'] ) : WP_NATIVE_AUTH_OAUTH_SCOPE;
	if ( WP_NATIVE_AUTH_OAUTH_SCOPE !== $scope ) {
		$fail( 'invalid_scope', 'The requested scope is not available.' );
	}

	$resource = isset( $params['resource'] ) ? trim( (string) $params['resource'] ) : '';
	if ( '' !== $resource && ! wp_native_auth_oauth_validate_resource( $resource ) ) {
		$fail( 'invalid_target', 'The resource indicator is not a valid RFC 8707 audience.' );
	}

	$state = isset( $params['state'] ) && is_string( $params['state'] ) ? $params['state'] : '';

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		// Route through normal WordPress login so site authentication
		// policies (including two-factor plugins) keep working untouched.
		$current = esc_url_raw( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ) );
		wp_native_auth_oauth_redirect( wp_login_url( untrailingslashit( home_url() ) . $current ) );
	}

	$bundle = array(
		'client_id'      => (string) $client['client_id'],
		'redirect_uri'   => $redirect_uri,
		'code_challenge' => $code_challenge,
		'scope'          => $scope,
		'resource'       => $resource,
		'state'          => $state,
		'user_id'        => $user_id,
		'issued'         => time(),
	);

	wp_native_auth_oauth_render_consent(
		array(
			'client_name'      => null !== $client['client_name'] ? $client['client_name'] : (string) $client['client_id'],
			'client_uri'       => null !== $client['client_uri'] ? $client['client_uri'] : '',
			'client_id'        => (string) $client['client_id'],
			'scope'            => $scope,
			'resource'         => $resource,
			'bundle'           => wp_native_auth_oauth_encode_bundle( $bundle ),
			'signature'        => wp_native_auth_oauth_sign_request( $bundle ),
			'authorize_action' => wp_create_nonce( 'wp_native_auth_oauth_consent' ),
		)
	);
}

/**
 * Handle POST /authorize — the consent decision.
 *
 * @return never
 */
function wp_native_auth_oauth_handle_authorize_decision(): void {
	wp_native_auth_ensure_schema();

	$body = wp_native_auth_oauth_read_body();

	$nonce     = isset( $body['_wpnonce'] ) ? (string) $body['_wpnonce'] : '';
	$signature = isset( $body['oauth_signature'] ) ? (string) $body['oauth_signature'] : '';
	$bundle    = isset( $body['oauth_request'] ) ? wp_native_auth_oauth_decode_bundle( (string) $body['oauth_request'] ) : null;
	$decision  = isset( $body['oauth_decision'] ) ? (string) $body['oauth_decision'] : '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_native_auth_oauth_consent' ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The consent form has expired. Please start the connection again.', 'wp-native-auth' ), 403 );
	}

	if ( ! is_array( $bundle ) || '' === $signature || ! wp_native_auth_oauth_verify_request( $signature, $bundle ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request could not be verified. Please start the connection again.', 'wp-native-auth' ), 400 );
	}

	$required = array( 'client_id', 'redirect_uri', 'code_challenge', 'scope', 'user_id', 'issued' );
	if ( array_diff( $required, array_keys( $bundle ) ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request is incomplete.', 'wp-native-auth' ), 400 );
	}

	$current_user_id = get_current_user_id();

	if ( (int) $bundle['user_id'] !== $current_user_id ) {
		wp_native_auth_oauth_send_page_error( __( 'The signed-in account changed before consent was given. Please start the connection again.', 'wp-native-auth' ), 403 );
	}

	if ( time() - (int) $bundle['issued'] > (int) apply_filters( 'wp_native_auth_oauth_request_ttl', WP_NATIVE_AUTH_OAUTH_REQUEST_TTL ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The authorization request expired. Please start the connection again.', 'wp-native-auth' ), 400 );
	}

	$redirect_uri = (string) $bundle['redirect_uri'];
	$state        = isset( $bundle['state'] ) ? (string) $bundle['state'] : '';

	if ( 'deny' === $decision ) {
		wp_native_auth_oauth_redirect(
			wp_native_auth_oauth_build_error_redirect( $redirect_uri, 'access_denied', '', $state )
		);
	}

	if ( 'approve' !== $decision ) {
		wp_native_auth_oauth_send_page_error( __( 'Unknown consent decision.', 'wp-native-auth' ), 400 );
	}

	// Re-validate the client and redirect binding at decision time: the
	// client may have been removed, or its registered URIs changed,
	// between the consent screen being shown and approved.
	$client = wp_native_auth_oauth_resolve_client( (string) $bundle['client_id'] );
	if ( is_wp_error( $client ) || ! wp_native_auth_oauth_validate_redirect_uri( $client['redirect_uris'], $redirect_uri ) ) {
		wp_native_auth_oauth_send_page_error( __( 'The application requesting access could not be verified.', 'wp-native-auth' ), 401 );
	}

	$code = wp_native_auth_oauth_create_authorization_code(
		(int) $bundle['user_id'],
		(string) $bundle['client_id'],
		$redirect_uri,
		(string) $bundle['code_challenge'],
		isset( $bundle['resource'] ) ? (string) $bundle['resource'] : '',
		(string) $bundle['scope']
	);

	wp_native_auth_oauth_redirect(
		wp_native_auth_oauth_build_success_redirect( $redirect_uri, $code['code'], $state )
	);
}

/**
 * Render the consent screen.
 *
 * Ships a minimal, unbranded template. A host replaces it wholesale
 * via the `wp_native_auth_oauth_consent_template` filter (return an
 * absolute path to a template file receiving the same $args), which is
 * the intended seam for branded product UI — that UI does not belong
 * in this generic layer.
 *
 * @param array<string,mixed> $args View args (client_name, client_uri, client_id, scope, resource, bundle, signature, authorize_action).
 * @return never
 */
function wp_native_auth_oauth_render_consent( array $args ): void {
	$default_template = __DIR__ . '/oauth-consent-form.php';

	/**
	 * Filter the consent template path.
	 *
	 * @param string              $template Absolute path to the template file.
	 * @param array<string,mixed> $args     View args for the template.
	 */
	$template = (string) apply_filters( 'wp_native_auth_oauth_consent_template', $default_template, $args );

	if ( '' === $template || ! is_readable( $template ) ) {
		$template = $default_template;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput -- The template escapes its own output.
	include $template;

	exit;
}
