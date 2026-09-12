<?php
/**
 * HTTP plumbing for the OAuth endpoints: request path detection, JSON and
 * redirect responses, request-body parsing, and RFC 6750 / RFC 9728
 * WWW-Authenticate challenge emission.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * The current request path relative to this site's root.
 *
 * Handles subdirectory (path-based) multisite by stripping the site's
 * home path prefix, and ignores any query string.
 *
 * @return string Lowercase-normalized path without leading/trailing slashes.
 */
function wp_native_auth_oauth_current_path(): string {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( '' === $uri ) {
		return '';
	}

	$path = wp_parse_url( $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
	if ( is_string( $home_path ) && '' !== $home_path && '/' !== $home_path ) {
		$prefix = rtrim( (string) $home_path, '/' );
		if ( 0 === strpos( $path, $prefix . '/' ) || $path === $prefix ) {
			$path = substr( $path, strlen( $prefix ) );
		}
	}

	$path = trim( rawurldecode( $path ), '/' );

	return $path;
}

/**
 * Whether a request path matches an endpoint path.
 *
 * $allow_suffix permits the RFC 9728 / RFC 8414 path-insertion form
 * (metadata documents served under "/<path>/<anything>") so discovery
 * works regardless of where the protected resource itself lives.
 *
 * @param string $path         Request path (no slashes at the edges).
 * @param string $route        Endpoint route (no slashes at the edges).
 * @param bool   $allow_suffix Optional. Allow a suffixed subpath. Default false.
 * @return bool
 */
function wp_native_auth_oauth_path_matches( string $path, string $route, bool $allow_suffix = false ): bool {
	$path  = trim( strtolower( $path ), '/' );
	$route = trim( strtolower( $route ), '/' );

	if ( $path === $route ) {
		return true;
	}

	if ( $allow_suffix && 0 === strpos( $path, $route . '/' ) ) {
		return true;
	}

	return false;
}

/**
 * Send a JSON response and terminate the request.
 *
 * @param array<string,mixed> $data    Payload.
 * @param int                 $status  HTTP status code. Default 200.
 * @param array<string,string> $headers Extra headers.
 * @return never
 */
function wp_native_auth_oauth_send_json( array $data, int $status = 200, array $headers = array() ): void {
	if ( ! headers_sent() ) {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API response body.
	echo wp_json_encode( $data );
	exit;
}

/**
 * Send an RFC 6749 §5.2 error response and terminate the request.
 *
 * @param string               $error       OAuth error code (e.g. invalid_grant).
 * @param string               $description Human-readable detail.
 * @param int                  $status      HTTP status code.
 * @param array<string,string> $headers     Extra headers.
 * @return never
 */
function wp_native_auth_oauth_send_error( string $error, string $description, int $status, array $headers = array() ): void {
	wp_native_auth_oauth_send_json(
		array(
			'error'             => $error,
			'error_description' => $description,
		),
		$status,
		$headers
	);
}

/**
 * Send a 401 with an RFC 9728 §5 Bearer challenge pointing at the
 * protected-resource metadata document.
 *
 * @param string $error       Optional. OAuth error code for the challenge.
 * @param string $description Optional. Error description for the challenge.
 * @return never
 */
function wp_native_auth_oauth_send_unauthorized( string $error = '', string $description = '' ): void {
	$challenge = 'Bearer resource_metadata="' . esc_url_raw( wp_native_auth_oauth_protected_resource_url() ) . '"';

	if ( '' !== $error ) {
		$challenge .= ', error="' . $error . '"';
	}
	if ( '' !== $description ) {
		$challenge .= ', error_description="' . str_replace( '"', "'", $description ) . '"';
	}

	wp_native_auth_oauth_send_error(
		'' !== $error ? $error : 'invalid_token',
		$description,
		401,
		array( 'WWW-Authenticate' => $challenge )
	);
}

/**
 * Send a 405 for a wrong-method request.
 *
 * @param string $allow Allowed methods.
 * @return never
 */
function wp_native_auth_oauth_send_method_not_allowed( string $allow ): void {
	wp_native_auth_oauth_send_error(
		'invalid_request',
		__( 'Unsupported request method.', 'wp-native-auth' ),
		405,
		array( 'Allow' => $allow )
	);
}

/**
 * Read the request body as form fields or a JSON object.
 *
 * OAuth clients POST form-encoded bodies; JSON is accepted as a
 * convenience for the same parameter names. Both are treated
 * identically and never trusted without revalidation.
 *
 * @return array<string,mixed>
 */
function wp_native_auth_oauth_read_body(): array {
	$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';

	if ( false !== strpos( $content_type, 'application/json' ) ) {
		$raw = file_get_contents( 'php://input' );
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 65536 ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint-level validation follows.
	$body = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

	return is_array( $body ) ? $body : array();
}

/**
 * Build a success authorization-response redirect URL (RFC 6749 §4.1.2
 * with the RFC 9207 iss parameter).
 *
 * @param string $redirect_uri Validated client redirect URI.
 * @param string $code         Authorization code.
 * @param string $state        Optional. Client state echoed back.
 * @param string $issuer       Optional. Issuer URL. Defaults to the site issuer.
 * @return string
 */
function wp_native_auth_oauth_build_success_redirect( string $redirect_uri, string $code, string $state = '', string $issuer = '' ): string {
	$issuer = '' !== $issuer ? $issuer : wp_native_auth_oauth_issuer();

	$args = array(
		'code' => $code,
		'iss'  => $issuer,
	);

	if ( '' !== $state ) {
		$args['state'] = $state;
	}

	return add_query_arg( rawurlencode_deep( $args ), $redirect_uri );
}

/**
 * Build an error authorization-response redirect URL.
 *
 * Only used once the client and redirect URI are validated; earlier
 * failures are shown to the user directly (RFC 6749 §4.1.2.1).
 *
 * @param string $redirect_uri Validated client redirect URI.
 * @param string $error        OAuth error code.
 * @param string $description  Optional. Human-readable detail.
 * @param string $state        Optional. Client state echoed back.
 * @param string $issuer       Optional. Issuer URL. Defaults to the site issuer.
 * @return string
 */
function wp_native_auth_oauth_build_error_redirect( string $redirect_uri, string $error, string $description = '', string $state = '', string $issuer = '' ): string {
	$issuer = '' !== $issuer ? $issuer : wp_native_auth_oauth_issuer();

	$args = array(
		'error' => $error,
		'iss'   => $issuer,
	);

	if ( '' !== $description ) {
		$args['error_description'] = $description;
	}
	if ( '' !== $state ) {
		$args['state'] = $state;
	}

	return add_query_arg( rawurlencode_deep( $args ), $redirect_uri );
}

/**
 * Redirect the browser to an authorization response and terminate.
 *
 * The target is the client's registered redirect URI — an external
 * URL by design, so wp_safe_redirect()'s same-host restriction does
 * not apply here.
 *
 * @param string $url Redirect target (already built + validated).
 * @return never
 */
function wp_native_auth_oauth_redirect( string $url ): void {
	if ( ! headers_sent() ) {
		nocache_headers();
		wp_redirect( $url, 302 );
	}

	exit;
}

/**
 * Show a plain error page for failures that must NOT be redirected
 * (unknown client, unregistered redirect URI, invalid consent state).
 *
 * 401 responses carry the RFC 9728 Bearer challenge so every 401 this
 * server emits points at the protected-resource metadata document.
 *
 * @param string $message User-facing message.
 * @param int    $status  HTTP status code. Default 400.
 * @return never
 */
function wp_native_auth_oauth_send_page_error( string $message, int $status = 400 ): void {
	if ( 401 === $status && ! headers_sent() ) {
		header( 'WWW-Authenticate: Bearer resource_metadata="' . esc_url_raw( wp_native_auth_oauth_protected_resource_url() ) . '"' );
	}

	wp_die(
		esc_html( $message ),
		esc_html__( 'Authorization error', 'wp-native-auth' ),
		array(
			'response'  => $status,
			'back_link' => false,
		)
	);
}
