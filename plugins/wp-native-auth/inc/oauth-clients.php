<?php
/**
 * OAuth client management: dynamic client registration (RFC 7591),
 * client-id metadata documents (CIMD), and redirect-URI validation
 * with the loopback port exception.
 *
 * CIMD is preferred: a client_id that is itself an HTTPS URL is resolved
 * by fetching the metadata document hosted there. DCR exists as the
 * fallback for clients that cannot host metadata.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Whether a string looks like a CIMD client identifier (an HTTPS URL).
 *
 * @param string $client_id Candidate client_id.
 * @return bool
 */
function wp_native_auth_oauth_is_cimd_client_id( string $client_id ): bool {
	if ( strlen( $client_id ) > 255 || 0 !== stripos( $client_id, 'https://' ) ) {
		return false;
	}

	$parsed = wp_parse_url( $client_id );

	return is_array( $parsed ) && ! empty( $parsed['host'] );
}

/**
 * Validate one redirect URI candidate.
 *
 * HTTPS is required, except loopback http (localhost / 127.0.0.1 / ::1)
 * per RFC 8252 §7.3. Fragments are forbidden (RFC 6749 §3.1.2), the
 * component is capped at 500 chars, and scheme-relative or credential-
 * bearing URIs are rejected.
 *
 * @param string $uri Candidate URI.
 * @return bool
 */
function wp_native_auth_oauth_is_valid_redirect_uri( string $uri ): bool {
	if ( '' === $uri || strlen( $uri ) > 500 ) {
		return false;
	}

	if ( false !== strpos( $uri, '#' ) ) {
		return false;
	}

	$parsed = wp_parse_url( $uri );
	if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || ! isset( $parsed['scheme'] ) ) {
		return false;
	}

	if ( ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
		return false;
	}

	$scheme = strtolower( (string) $parsed['scheme'] );

	if ( 'https' === $scheme ) {
		return true;
	}

	if ( 'http' === $scheme ) {
		return wp_native_auth_oauth_is_loopback_host( (string) $parsed['host'] );
	}

	return false;
}

/**
 * Whether a host is a loopback host for the redirect port exception.
 *
 * @param string $host Parsed host (unbracketed for IPv6 by wp_parse_url).
 * @return bool
 */
function wp_native_auth_oauth_is_loopback_host( string $host ): bool {
	$host = strtolower( trim( $host, '[]' ) );

	return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
}

/**
 * Validate a presented redirect URI against a client's registered set.
 *
 * Exact string match, with one exception: for http loopback redirect
 * URIs the port is ignored (the OS assigns an ephemeral port to native
 * app loops; RFC 8252 §7.3). Path and query must still match exactly.
 *
 * @param array<int,string> $registered Registered redirect URIs.
 * @param string            $presented  Redirect URI presented in the request.
 * @return bool
 */
function wp_native_auth_oauth_validate_redirect_uri( array $registered, string $presented ): bool {
	foreach ( $registered as $candidate ) {
		if ( ! is_string( $candidate ) ) {
			continue;
		}

		if ( hash_equals( $candidate, $presented ) ) {
			return true;
		}

		if ( wp_native_auth_oauth_loopback_redirects_match( $candidate, $presented ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Loopback port-insensitive comparison of two redirect URIs.
 *
 * @param string $registered Registered URI.
 * @param string $presented  Presented URI.
 * @return bool
 */
function wp_native_auth_oauth_loopback_redirects_match( string $registered, string $presented ): bool {
	$a = wp_parse_url( $registered );
	$b = wp_parse_url( $presented );

	if ( ! is_array( $a ) || ! is_array( $b ) ) {
		return false;
	}

	if ( empty( $a['scheme'] ) || empty( $b['scheme'] ) || strtolower( (string) $a['scheme'] ) !== strtolower( (string) $b['scheme'] ) ) {
		return false;
	}

	if ( 'http' !== strtolower( (string) $a['scheme'] ) ) {
		return false;
	}

	if ( empty( $a['host'] ) || empty( $b['host'] ) ) {
		return false;
	}

	// Both hosts must be loopback AND identical: the RFC 8252 §7.3
	// exception ignores the PORT only. localhost and 127.0.0.1 are
	// distinct hosts and never interchangeable.
	if ( strtolower( trim( (string) $a['host'], '[]' ) ) !== strtolower( trim( (string) $b['host'], '[]' ) ) ) {
		return false;
	}

	if ( ! wp_native_auth_oauth_is_loopback_host( (string) $a['host'] ) || ! wp_native_auth_oauth_is_loopback_host( (string) $b['host'] ) ) {
		return false;
	}

	$a_path = isset( $a['path'] ) ? (string) $a['path'] : '';
	$b_path = isset( $b['path'] ) ? (string) $b['path'] : '';
	if ( $a_path !== $b_path ) {
		return false;
	}

	$a_query = isset( $a['query'] ) ? (string) $a['query'] : '';
	$b_query = isset( $b['query'] ) ? (string) $b['query'] : '';

	return $a_query === $b_query;
}

/**
 * Whether this IP may attempt another dynamic client registration.
 *
 * Anonymous endpoint that writes rows — rate-limited on its own path
 * with a per-IP fixed-window counter.
 *
 * @param string $ip Client IP.
 * @return bool True when allowed.
 */
function wp_native_auth_oauth_registration_rate_limited( string $ip ): bool {
	$key   = 'wp_native_auth_oauth_reg_' . hash( 'sha256', $ip );
	$count = (int) get_transient( $key );

	/**
	 * Filter the per-IP dynamic registration limit.
	 *
	 * @param int $limit Registrations allowed per window.
	 */
	$limit = (int) apply_filters( 'wp_native_auth_oauth_registration_rate_limit', WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_LIMIT );

	if ( $count >= $limit ) {
		return true;
	}

	set_transient( $key, $count + 1, WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_WINDOW );

	return false;
}

/**
 * The remote client IP for rate-limit accounting.
 *
 * @return string
 */
function wp_native_auth_oauth_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return '' !== $ip ? $ip : 'unknown';
}

/**
 * Register a dynamic OAuth client (RFC 7591).
 *
 * Only public clients are issued: token endpoint authentication is
 * always `none`, no client secret is generated, and unsupported
 * requested metadata is rejected rather than silently rewritten.
 *
 * @param array<string,mixed> $body Parsed registration request body.
 * @param string              $ip   Client IP for rate limiting.
 * @return array<string,mixed>|WP_Error RFC 7591 response payload.
 */
function wp_native_auth_oauth_register_client( array $body, string $ip ): array|WP_Error {
	if ( wp_native_auth_oauth_registration_rate_limited( $ip ) ) {
		return new WP_Error(
			'rate_limited',
			__( 'Too many client registrations. Try again later.', 'wp-native-auth' ),
			array( 'status' => 429 )
		);
	}

	$redirect_uris = $body['redirect_uris'] ?? null;
	if ( ! is_array( $redirect_uris ) || count( $redirect_uris ) < 1 || count( $redirect_uris ) > 10 ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'redirect_uris must be an array of 1 to 10 HTTPS (or loopback) URIs.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$validated_uris = array();
	foreach ( $redirect_uris as $uri ) {
		if ( ! is_string( $uri ) || ! wp_native_auth_oauth_is_valid_redirect_uri( $uri ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'One or more redirect_uris are not acceptable redirect URIs.', 'wp-native-auth' ),
				array( 'status' => 400 )
			);
		}
		$validated_uris[] = $uri;
	}

	$validated_uris = array_values( array_unique( $validated_uris ) );

	$auth_method = $body['token_endpoint_auth_method'] ?? 'none';
	if ( 'none' !== $auth_method ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'Only public clients (token_endpoint_auth_method "none") are supported.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$grant_types = $body['grant_types'] ?? array( 'authorization_code', 'refresh_token' );
	if ( ! is_array( $grant_types ) || array( 'authorization_code', 'refresh_token' ) !== array_values( $grant_types ) ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'Unsupported grant_types.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$response_types = $body['response_types'] ?? array( 'code' );
	if ( ! is_array( $response_types ) || array( 'code' ) !== array_values( $response_types ) ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'Unsupported response_types.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$scope = $body['scope'] ?? WP_NATIVE_AUTH_OAUTH_SCOPE;
	if ( ! is_string( $scope ) || WP_NATIVE_AUTH_OAUTH_SCOPE !== $scope ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'Unsupported scope.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$client_name = '';
	if ( isset( $body['client_name'] ) && is_string( $body['client_name'] ) && '' !== $body['client_name'] ) {
		$client_name = substr( sanitize_text_field( $body['client_name'] ), 0, 191 );
	}

	$client_uri = '';
	if ( isset( $body['client_uri'] ) && is_string( $body['client_uri'] ) && '' !== $body['client_uri'] ) {
		$client_uri = substr( sanitize_text_field( $body['client_uri'] ), 0, 255 );
	}

	$client_id = wp_native_auth_generate_opaque_token();

	global $wpdb;

	$inserted = $wpdb->insert(
		wp_native_auth_oauth_clients_table_name(),
		array(
			'client_id'                  => $client_id,
			'client_name'                => '' !== $client_name ? $client_name : null,
			'client_uri'                 => '' !== $client_uri ? $client_uri : null,
			'redirect_uris'              => (string) wp_json_encode( $validated_uris ),
			'token_endpoint_auth_method' => 'none',
			'created_at'                 => wp_native_auth_mysql_gmt( time() ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error(
			'invalid_client_metadata',
			__( 'The client could not be registered.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	$response = array(
		'client_id'                  => $client_id,
		'client_id_issued_at'        => time(),
		'redirect_uris'              => $validated_uris,
		'token_endpoint_auth_method' => 'none',
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'scope'                      => WP_NATIVE_AUTH_OAUTH_SCOPE,
	);

	if ( '' !== $client_name ) {
		$response['client_name'] = $client_name;
	}
	if ( '' !== $client_uri ) {
		$response['client_uri'] = $client_uri;
	}

	return $response;
}

/**
 * Look up a registered (DCR) client by id.
 *
 * @param string $client_id Client identifier.
 * @return array<string,mixed>|null Normalized client record.
 */
function wp_native_auth_oauth_find_registered_client( string $client_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT client_id, client_name, client_uri, redirect_uris, token_endpoint_auth_method
			 FROM %i WHERE client_id = %s LIMIT 1',
			wp_native_auth_oauth_clients_table_name(),
			$client_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	$redirect_uris = json_decode( (string) $row['redirect_uris'], true );

	return array(
		'client_id'                  => (string) $row['client_id'],
		'client_name'                => null !== $row['client_name'] && '' !== $row['client_name'] ? (string) $row['client_name'] : null,
		'client_uri'                 => null !== $row['client_uri'] && '' !== $row['client_uri'] ? (string) $row['client_uri'] : null,
		'redirect_uris'              => is_array( $redirect_uris ) ? array_values( array_filter( $redirect_uris, 'is_string' ) ) : array(),
		'token_endpoint_auth_method' => (string) $row['token_endpoint_auth_method'],
		'cimd'                       => false,
	);
}

/**
 * Resolve any client identifier to its metadata.
 *
 * HTTPS-URL client identifiers are resolved as client-id metadata
 * documents; everything else is looked up in the registration table.
 *
 * @param string $client_id Client identifier.
 * @return array<string,mixed>|WP_Error Normalized client record.
 */
function wp_native_auth_oauth_resolve_client( string $client_id ): array|WP_Error {
	if ( '' === $client_id ) {
		return new WP_Error( 'invalid_client', __( 'A client_id is required.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	if ( wp_native_auth_oauth_is_cimd_client_id( $client_id ) ) {
		return wp_native_auth_oauth_fetch_cimd( $client_id );
	}

	$client = wp_native_auth_oauth_find_registered_client( $client_id );

	if ( null === $client ) {
		return new WP_Error( 'invalid_client', __( 'Unknown client.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	return $client;
}

/**
 * Fetch and validate a client-id metadata document.
 *
 * The document is fetched from the HTTPS URL that IS the client_id,
 * briefly cached, and required to self-identify with a matching
 * client_id field. Hosts that are IP literals in private or reserved
 * ranges are refused to keep the server from being used to probe
 * internal networks.
 *
 * @param string $url CIMD client identifier (HTTPS URL).
 * @return array<string,mixed>|WP_Error Normalized client record.
 */
function wp_native_auth_oauth_fetch_cimd( string $url ): array|WP_Error {
	$parsed = wp_parse_url( $url );
	if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
		return new WP_Error( 'invalid_client', __( 'Invalid client metadata URL.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$host = strtolower( (string) $parsed['host'] );
	if ( (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false && (bool) filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return new WP_Error( 'invalid_client', __( 'Client metadata URLs on private or reserved IP ranges are not accepted.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$cache_key = 'wp_native_auth_oauth_cimd_' . hash( 'sha256', $url );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && ! empty( $cached['client_id'] ) ) {
		return $cached;
	}

	/**
	 * Filter the cache lifetime for client-id metadata documents.
	 *
	 * @param int $ttl Seconds. Return 0 to disable caching entirely.
	 */
	$ttl = (int) apply_filters( 'wp_native_auth_oauth_cimd_cache_ttl', WP_NATIVE_AUTH_OAUTH_CIMD_CACHE_TTL );

	if ( $ttl > 0 && is_array( $cached ) ) {
		// Negative-cache placeholder: a recent failed fetch. Do not hammer the origin.
		return new WP_Error( 'invalid_client', __( 'The client metadata document could not be used.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'    => 5,
			'redirection' => 3,
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		if ( $ttl > 0 ) {
			set_transient( $cache_key, array( 'client_id' => '' ), $ttl );
		}

		return new WP_Error( 'invalid_client', __( 'The client metadata document could not be fetched.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$code = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	$body = isset( $response['body'] ) ? (string) $response['body'] : '';

	if ( $code < 200 || $code >= 300 || '' === $body || strlen( $body ) > 65536 ) {
		if ( $ttl > 0 ) {
			set_transient( $cache_key, array( 'client_id' => '' ), $ttl );
		}

		return new WP_Error( 'invalid_client', __( 'The client metadata document could not be fetched.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$document = json_decode( $body, true );
	if ( ! is_array( $document ) ) {
		if ( $ttl > 0 ) {
			set_transient( $cache_key, array( 'client_id' => '' ), $ttl );
		}

		return new WP_Error( 'invalid_client', __( 'The client metadata document is not valid JSON.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	// The document MUST self-identify with the URL that was dereferenced.
	if ( ! isset( $document['client_id'] ) || ! is_string( $document['client_id'] ) || ! hash_equals( $url, $document['client_id'] ) ) {
		return new WP_Error( 'invalid_client', __( 'The client metadata document does not self-identify.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$redirect_uris = $document['redirect_uris'] ?? null;
	if ( ! is_array( $redirect_uris ) || count( $redirect_uris ) < 1 || count( $redirect_uris ) > 10 ) {
		return new WP_Error( 'invalid_client', __( 'The client metadata document has no usable redirect_uris.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	foreach ( $redirect_uris as $uri ) {
		if ( ! is_string( $uri ) || ! wp_native_auth_oauth_is_valid_redirect_uri( $uri ) ) {
			return new WP_Error( 'invalid_client', __( 'The client metadata document contains unacceptable redirect_uris.', 'wp-native-auth' ), array( 'status' => 401 ) );
		}
	}

	$client_name = null;
	if ( isset( $document['client_name'] ) && is_string( $document['client_name'] ) && '' !== $document['client_name'] ) {
		$client_name = substr( sanitize_text_field( $document['client_name'] ), 0, 191 );
	}

	$client = array(
		'client_id'                  => $url,
		'client_name'                => $client_name,
		'client_uri'                 => isset( $document['client_uri'] ) && is_string( $document['client_uri'] ) ? substr( sanitize_text_field( $document['client_uri'] ), 0, 255 ) : null,
		'redirect_uris'              => array_values( array_filter( $redirect_uris, 'is_string' ) ),
		'token_endpoint_auth_method' => 'none',
		'cimd'                       => true,
	);

	if ( $ttl > 0 ) {
		set_transient( $cache_key, $client, $ttl );
	}

	return $client;
}
