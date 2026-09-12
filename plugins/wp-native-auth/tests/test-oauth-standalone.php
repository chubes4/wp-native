<?php
/**
 * Standalone (no-WordPress) tests for the generic OAuth 2.1
 * authorization server (#80).
 *
 * Boots the REAL plugin code from ../../inc/ against a minimal WordPress
 * shim backed by SQLite (tests/standalone/bootstrap.php) and verifies:
 *
 *   1. Discovery metadata required keys (RFC 8414 / RFC 9728 / issue #80).
 *   2. PKCE S256: valid verifier passes, tampered/malformed verifiers fail.
 *   3. Missing-verifier rejection at the token endpoint (separate probe
 *      process — the endpoint terminates the request on error).
 *   4. Authorization-code replay rejection + downstream revocation.
 *   5. Refresh rotation + reuse detection through the OAuth path.
 *   6. Redirect-URI exact-match enforcement with the loopback port rule.
 *   7. DCR registration validation + per-IP rate limiting.
 *   8. Resource (RFC 8707) validation + audience binding persistence.
 *   9. Revocation ownership checks (RFC 7009).
 *  10. Consent bundle HMAC sign/verify + redirect builders (RFC 9207 iss).
 *
 * Run:
 *
 *   php plugins/wp-native-auth/tests/test-oauth-standalone.php
 *
 * Exit code 0 = all assertions passed, 1 = a failure.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/standalone/bootstrap.php';

$failures = 0;
$checks   = 0;

$assert = static function ( bool $cond, string $label ) use ( &$failures, &$checks ): void {
	$checks++;
	if ( $cond ) {
		fwrite( STDOUT, "PASS: {$label}\n" );
	} else {
		fwrite( STDOUT, "FAIL: {$label}\n" );
		$failures++;
	}
};

/** Base64url of SHA-256 — what an S256 code_challenge must be. */
$pkce_pair = static function (): array {
	$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	return array( $verifier, $challenge );
};

/** Mint a fresh code for the given client. */
$mint_code = static function ( int $user_id, string $client_id, string $redirect_uri, string $challenge, string $resource = '' ): string {
	return wp_native_auth_oauth_create_authorization_code( $user_id, $client_id, $redirect_uri, $challenge, $resource, WP_NATIVE_AUTH_OAUTH_SCOPE )['code'];
};

$register_client = static function ( string $redirect_uri = 'https://app.example.org/callback' ): array {
	$result = wp_native_auth_oauth_register_client(
		array(
			'redirect_uris' => array( $redirect_uri ),
			'client_name'   => 'Standalone Test Client',
		),
		'203.0.113.10'
	);
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Client registration failed: ' . $result->get_error_message() );
	}
	return $result;
};

$clear_rate_limit = static function ( string $device_id ): void {
	delete_transient( 'wp_native_auth_refresh_' . md5( $device_id ) );
};

$device_row = static function ( string $device_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE device_id = %s LIMIT 1',
			wp_native_auth_refresh_tokens_table_name(),
			$device_id
		),
		ARRAY_A
	);
	return is_array( $row ) ? $row : null;
};

// ------------------------------------------------------------------
// 1. Discovery metadata.
// ------------------------------------------------------------------

fwrite( STDOUT, "-- discovery metadata --\n" );

$metadata = wp_native_auth_oauth_server_metadata();
$assert( is_array( $metadata ) && array() !== $metadata, 'server metadata is a non-empty array' );
$assert( 'https://auth.example.org' === $metadata['issuer'], 'issuer is the site URL without trailing slash' );
$assert( array( 'S256' ) === $metadata['code_challenge_methods_supported'], 'advertises S256 only' );
$assert( in_array( 'none', $metadata['token_endpoint_auth_methods_supported'], true ), 'advertises none client auth' );
$assert( true === $metadata['client_id_metadata_document_supported'], 'advertises CIMD support' );
$assert( true === $metadata['authorization_response_iss_parameter_supported'], 'advertises iss parameter support' );
$assert( array( 'code' ) === $metadata['response_types_supported'], 'advertises code response type' );
$assert( array( 'authorization_code', 'refresh_token' ) === $metadata['grant_types_supported'], 'advertises code + refresh grants' );
$assert( '' !== $metadata['token_endpoint'] && '' !== $metadata['authorization_endpoint'], 'endpoint URLs present' );

$prm = wp_native_auth_oauth_protected_resource_metadata();
$assert( 'https://auth.example.org' === $prm['resource'], 'PRM resource is the issuer' );
$assert( array( 'https://auth.example.org' ) === $prm['authorization_servers'], 'PRM lists the issuer as AS' );
$assert( is_string( wp_json_encode( $metadata ) ) && null !== json_decode( wp_json_encode( $metadata ), true ), 'AS metadata encodes to valid JSON' );
$assert( is_string( wp_json_encode( $prm ) ) && null !== json_decode( wp_json_encode( $prm ), true ), 'PRM encodes to valid JSON' );

// ------------------------------------------------------------------
// 2. PKCE logic.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- PKCE S256 --\n" );

list( $verifier, $challenge ) = $pkce_pair();

$assert( wp_native_auth_oauth_verify_pkce( $verifier, $challenge ), 'correct verifier passes S256 check' );
$assert( ! wp_native_auth_oauth_verify_pkce( $verifier . 'x', $challenge ), 'tampered verifier fails' );
$assert( ! wp_native_auth_oauth_verify_pkce( str_repeat( 'a', 43 ), $challenge ), 'wrong verifier fails' );
$assert( ! wp_native_auth_oauth_verify_pkce( '', $challenge ), 'empty verifier fails' );
$assert( ! wp_native_auth_oauth_validate_code_verifier( str_repeat( 'a', 42 ) ), '42-char verifier rejected (too short)' );
$assert( ! wp_native_auth_oauth_validate_code_verifier( str_repeat( 'a', 129 ) ), '129-char verifier rejected (too long)' );
$assert( ! wp_native_auth_oauth_validate_code_verifier( 'bad chars ~~~' ), 'verifier with forbidden chars rejected' );
$assert( wp_native_auth_oauth_validate_code_challenge( $challenge ), 'well-formed challenge accepted' );
$assert( ! wp_native_auth_oauth_validate_code_challenge( 'tooshort' ), 'malformed challenge rejected' );

// ------------------------------------------------------------------
// 3. Redirect URI matching.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- redirect URI enforcement --\n" );

$registered = array( 'https://app.example.org/callback', 'http://127.0.0.1:8765/oauth/done' );

$assert( wp_native_auth_oauth_validate_redirect_uri( $registered, 'https://app.example.org/callback' ), 'exact https match accepted' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'https://app.example.org/callback/' ), 'trailing slash mismatch rejected' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'https://app.example.org/callback?x=1' ), 'appended query rejected' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'https://evil.example.org/callback' ), 'different host rejected' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'http://app.example.org/callback' ), 'scheme downgrade rejected' );
$assert( wp_native_auth_oauth_validate_redirect_uri( $registered, 'http://127.0.0.1:9999/oauth/done' ), 'loopback port variation accepted' );
$assert( wp_native_auth_oauth_validate_redirect_uri( $registered, 'http://127.0.0.1/oauth/done' ), 'loopback with no port accepted' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'http://127.0.0.1:8765/other' ), 'loopback path change rejected' );
$assert( ! wp_native_auth_oauth_validate_redirect_uri( $registered, 'http://localhost:8765/oauth/done' ), 'localhost does not match a 127.0.0.1 registration' );

$assert( wp_native_auth_oauth_is_valid_redirect_uri( 'http://localhost:3000/cb' ), 'http loopback URI valid' );
$assert( ! wp_native_auth_oauth_is_valid_redirect_uri( 'http://app.example.org/cb' ), 'http non-loopback URI invalid' );
$assert( ! wp_native_auth_oauth_is_valid_redirect_uri( 'https://app.example.org/cb#fragment' ), 'fragment-bearing URI invalid' );
$assert( ! wp_native_auth_oauth_is_valid_redirect_uri( 'javascript:alert(1)' ), 'javascript: URI invalid' );
$assert( ! wp_native_auth_oauth_is_valid_redirect_uri( 'https://user:pass@app.example.org/cb' ), 'credential-bearing URI invalid' );

// ------------------------------------------------------------------
// 4. RFC 8707 resource validation.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- resource indicators --\n" );

$assert( wp_native_auth_oauth_validate_resource( 'https://api.example.org/mcp' ), 'https resource accepted' );
$assert( ! wp_native_auth_oauth_validate_resource( 'http://api.example.org/mcp' ), 'http resource rejected' );
$assert( ! wp_native_auth_oauth_validate_resource( 'https://api.example.org/x#frag' ), 'fragment resource rejected' );
$assert( ! wp_native_auth_oauth_validate_resource( 'not-a-uri' ), 'non-URI resource rejected' );
$assert( ! wp_native_auth_oauth_validate_resource( str_repeat( 'https://a/', 20 ) ), 'oversized resource rejected' );

// ------------------------------------------------------------------
// 5. DCR + rate limiting.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- dynamic client registration --\n" );

$client = $register_client();
$assert( '' !== $client['client_id'] && 43 === strlen( $client['client_id'] ), 'DCR issues an opaque client_id' );
$assert( 'none' === $client['token_endpoint_auth_method'], 'DCR issues public clients' );
$assert( null !== wp_native_auth_oauth_find_registered_client( $client['client_id'] ), 'registered client is retrievable' );

$bad = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'http://app.example.org/cb' ) ), '203.0.113.11' );
$assert( is_wp_error( $bad ) && 'invalid_redirect_uri' === $bad->get_error_code(), 'non-loopback http redirect rejected' );

$bad = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://app.example.org/cb' ), 'token_endpoint_auth_method' => 'client_secret_post' ), '203.0.113.11' );
$assert( is_wp_error( $bad ) && 'invalid_client_metadata' === $bad->get_error_code(), 'confidential client request rejected' );

// Rate limit: drop the ceiling to 2 via the filter and confirm the
// third registration from the same IP is refused.
add_filter( 'wp_native_auth_oauth_registration_rate_limit', static fn(): int => 2, 10, 0 );

$first  = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://r.example.org/cb' ) ), '198.51.100.9' );
$second = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://r.example.org/cb' ) ), '198.51.100.9' );
$third  = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://r.example.org/cb' ) ), '198.51.100.9' );

$assert( ! is_wp_error( $first ) && ! is_wp_error( $second ), 'first two registrations from an IP allowed' );
$assert( is_wp_error( $third ) && 'rate_limited' === $third->get_error_code(), 'third registration rate-limited' );

$other_ip = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://r.example.org/cb' ) ), '198.51.100.10' );
$assert( ! is_wp_error( $other_ip ), 'other IPs unaffected by the rate limit' );

// ------------------------------------------------------------------
// 6. Full authorization-code grant through the real storage layer.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- authorization code grant --\n" );

$user_id = wpshim_create_user( array( 'roles' => array( 'editor' ) ) );
wp_set_current_user( $user_id );

$resource = 'https://api.example.org/mcp';
list( $verifier, $challenge ) = $pkce_pair();
$code    = $mint_code( $user_id, $client['client_id'], 'https://app.example.org/callback', $challenge, $resource );
$grant   = wp_native_auth_oauth_exchange_code( $code, $client['client_id'], 'https://app.example.org/callback', $verifier, 'Standalone Test Client' );

$assert( ! is_wp_error( $grant ), 'code exchange succeeds with a correct verifier' );
if ( is_wp_error( $grant ) ) {
	fwrite( STDOUT, '  grant error: ' . $grant->get_error_code() . ' ' . $grant->get_error_message() . "\n" );
	exit( 1 );
}

$assert( 'Bearer' === $grant['token_type'], 'token_type is Bearer' );
$assert( WP_NATIVE_AUTH_OAUTH_SCOPE === $grant['scope'], 'declared scope is returned' );
$assert( '' !== $grant['access_token'] && '' !== $grant['refresh_token'], 'both tokens are minted' );

$oauth_device = wp_native_auth_oauth_device_id( $user_id, $client['client_id'] );
$assert( wp_native_auth_is_uuid_v4( $oauth_device ), 'derived device id is UUID-v4 shaped' );
$assert( wp_native_auth_oauth_device_id( $user_id, $client['client_id'] ) === $oauth_device, 'device id is stable per user/client' );
$assert( wp_native_auth_oauth_device_id( $user_id + 1, $client['client_id'] ) !== $oauth_device, 'device id differs per user' );

$row = $device_row( $oauth_device );
$assert( null !== $row, 'grant lives in the existing refresh-token table' );
$assert( (string) $row['oauth_client_id'] === $client['client_id'], 'refresh row carries the client binding' );
$assert( (string) $row['resource'] === $resource, 'refresh row carries the resource audience' );
$assert( 'Standalone Test Client' === (string) $row['device_name'], 'session row is labeled with the client name' );

$access_payload = wp_native_auth_get_access_token_payload( $grant['access_token'] );
$assert( is_array( $access_payload ), 'access token resolves' );
$assert( ( $access_payload['meta']['resource'] ?? '' ) === $resource, 'access token payload binds the resource audience' );
$assert( ( $access_payload['meta']['client_id'] ?? '' ) === $client['client_id'], 'access token payload binds the client' );
$assert( (int) $access_payload['user_id'] === $user_id, 'access token authenticates the authorizing user' );

// ------------------------------------------------------------------
// 7. Refresh rotation + reuse detection through the OAuth path.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- refresh rotation via OAuth path --\n" );

$original_refresh = $grant['refresh_token'];
$family_before    = (string) $device_row( $oauth_device )['token_family'];

$clear_rate_limit( $oauth_device );
$rotated = wp_native_auth_oauth_refresh_grant( $original_refresh, $client['client_id'] );

$assert( ! is_wp_error( $rotated ), 'OAuth refresh grant rotates successfully' );
if ( is_wp_error( $rotated ) ) {
	fwrite( STDOUT, '  refresh error: ' . $rotated->get_error_code() . ' ' . $rotated->get_error_message() . "\n" );
	exit( 1 );
}

$assert( $rotated['refresh_token'] !== $original_refresh, 'rotation mints a new refresh token' );

$row = $device_row( $oauth_device );
$assert( (string) $row['token_family'] === $family_before, 'token family preserved across rotation' );
$assert( ! empty( $row['prev_token_hash'] ), 'superseded hash parked in prev_token_hash' );
$assert( (string) $row['oauth_client_id'] === $client['client_id'], 'client binding survives rotation' );

$rotated_payload = wp_native_auth_get_access_token_payload( $rotated['access_token'] );
$assert( ( $rotated_payload['meta']['resource'] ?? '' ) === $resource, 'resource audience survives rotation onto the new access token' );
$assert( ( $rotated_payload['meta']['client_id'] ?? '' ) === $client['client_id'], 'client binding survives rotation onto the new access token' );

// REPLAY: presenting the just-rotated-away token again is reuse.
$replay_actions = array();
add_action(
	'wp_native_auth_refresh_token_reuse_detected',
	static function ( $uid, $did, $fam ) use ( &$replay_actions ): void {
		$replay_actions[] = array( $uid, $did, $fam );
	},
	10,
	3
);

$clear_rate_limit( $oauth_device );
$replay = wp_native_auth_oauth_refresh_grant( $original_refresh, $client['client_id'] );

$assert( is_wp_error( $replay ) && 'invalid_grant' === $replay->get_error_code(), 'replayed refresh token rejected as invalid_grant' );
$assert( array() !== $replay_actions, 'reuse detection fired through the OAuth path' );
$assert( (int) $replay_actions[0][0] === $user_id, 'reuse detection reports the right user' );

$row = $device_row( $oauth_device );
$assert( ! empty( $row['revoked_at'] ), 'entire token family revoked after replay' );

// A third use (either token) is now a plain invalid token.
$clear_rate_limit( $oauth_device );
$after = wp_native_auth_oauth_refresh_grant( $rotated['refresh_token'], $client['client_id'] );
$assert( is_wp_error( $after ) && 'invalid_grant' === $after->get_error_code(), 'post-revocation refresh rejected' );

// A refresh token from a DIFFERENT client is not usable for this one.
$other_client = $register_client( 'https://other.example.org/cb' );
list( $verifier_b, $challenge_b ) = $pkce_pair();
$code_b = $mint_code( $user_id, $other_client['client_id'], 'https://other.example.org/cb', $challenge_b );
$grant_b = wp_native_auth_oauth_exchange_code( $code_b, $other_client['client_id'], 'https://other.example.org/cb', $verifier_b, 'Other Client' );
$assert( ! is_wp_error( $grant_b ), 'second client grant succeeds' );

$other_device = wp_native_auth_oauth_device_id( $user_id, $other_client['client_id'] );
$clear_rate_limit( $other_device );
$cross = wp_native_auth_oauth_refresh_grant( $grant_b['refresh_token'], $client['client_id'] );
$assert( is_wp_error( $cross ) && 'invalid_client' === $cross->get_error_code(), 'cross-client refresh rejected as invalid_client' );

// ------------------------------------------------------------------
// 8. Authorization-code replay (single use).
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- authorization code replay --\n" );

list( $verifier_c, $challenge_c ) = $pkce_pair();
$code_c = $mint_code( $user_id, $client['client_id'], 'https://app.example.org/callback', $challenge_c );

$first_exchange = wp_native_auth_oauth_exchange_code( $code_c, $client['client_id'], 'https://app.example.org/callback', $verifier_c );
$assert( ! is_wp_error( $first_exchange ), 'first redemption of the code succeeds' );

$replay_device = wp_native_auth_oauth_device_id( $user_id, $client['client_id'] );

$replay_fired = array();
add_action(
	'wp_native_auth_oauth_code_replay_detected',
	static function ( $uid, $cid ) use ( &$replay_fired ): void {
		$replay_fired[] = array( $uid, $cid );
	},
	10,
	2
);

$second_exchange = wp_native_auth_oauth_exchange_code( $code_c, $client['client_id'], 'https://app.example.org/callback', $verifier_c );
$assert( is_wp_error( $second_exchange ) && 'invalid_grant' === $second_exchange->get_error_code(), 'second redemption of the same code is rejected' );
$assert( array() !== $replay_fired, 'code replay action fired' );

$row = $device_row( $replay_device );
$assert( ! empty( $row['revoked_at'] ), 'tokens minted from the replayed code are revoked' );

$wrong_client = wp_native_auth_oauth_exchange_code( $code_c, $other_client['client_id'], 'https://app.example.org/callback', $verifier_c );
$assert( is_wp_error( $wrong_client ) && 'invalid_grant' === $wrong_client->get_error_code(), 'code presented by the wrong client rejected' );

list( $verifier_d, $challenge_d ) = $pkce_pair();
$code_d = $mint_code( $user_id, $client['client_id'], 'https://app.example.org/callback', $challenge_d );
$wrong_redirect = wp_native_auth_oauth_exchange_code( $code_d, $client['client_id'], 'https://app.example.org/other', $verifier_d );
$assert( is_wp_error( $wrong_redirect ) && 'invalid_grant' === $wrong_redirect->get_error_code(), 'code with mismatched redirect_uri rejected' );

// The mismatched-redirect presentation must NOT have consumed the code:
// the code is only claimed by its rightful owner.
$clear_rate_limit( $replay_device );
$redirect_fixed = wp_native_auth_oauth_exchange_code( $code_d, $client['client_id'], 'https://app.example.org/callback', $verifier_d );
$assert( ! is_wp_error( $redirect_fixed ), 'code survives wrong-client probing and redeems correctly' );

// ------------------------------------------------------------------
// 9. Revocation ownership.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- revocation --\n" );

$revoke_refresh = $redirect_fixed['refresh_token'];
$assert( wp_native_auth_oauth_revoke_refresh_for_client( $revoke_refresh, $client['client_id'] ), 'owner client revokes its refresh session' );
$assert( ! empty( $device_row( $replay_device )['revoked_at'] ), 'revoked row is marked revoked' );

list( $verifier_e, $challenge_e ) = $pkce_pair();
$code_e = $mint_code( $user_id, $client['client_id'], 'https://app.example.org/callback', $challenge_e );
$grant_e = wp_native_auth_oauth_exchange_code( $code_e, $client['client_id'], 'https://app.example.org/callback', $verifier_e );
$assert( ! is_wp_error( $grant_e ), 'fresh grant for access-token revocation test' );

$assert( ! wp_native_auth_oauth_revoke_access_for_client( $grant_e['access_token'], $other_client['client_id'] ), 'foreign client cannot revoke the token' );
$assert( null !== wp_native_auth_get_access_token_payload( $grant_e['access_token'] ), 'token survives foreign revocation attempt' );
$assert( wp_native_auth_oauth_revoke_access_for_client( $grant_e['access_token'], $client['client_id'] ), 'owner client revokes the access token' );
$assert( null === wp_native_auth_get_access_token_payload( $grant_e['access_token'] ), 'access token is gone after owner revocation' );
$assert( ! wp_native_auth_oauth_revoke_refresh_for_client( $revoke_refresh, $client['client_id'] ), 'revoking an already-revoked token reports false' );

// ------------------------------------------------------------------
// 10. Consent bundle + redirect builders.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- consent bundle + redirects --\n" );

$bundle = array(
	'client_id'      => $client['client_id'],
	'redirect_uri'   => 'https://app.example.org/callback',
	'code_challenge' => $challenge,
	'scope'          => WP_NATIVE_AUTH_OAUTH_SCOPE,
	'resource'       => $resource,
	'state'          => 'st4te',
	'user_id'        => $user_id,
	'issued'         => time(),
);

$signature = wp_native_auth_oauth_sign_request( $bundle );
$assert( wp_native_auth_oauth_verify_request( $signature, $bundle ), 'consent bundle verifies' );

$tampered              = $bundle;
$tampered['client_id'] = $other_client['client_id'];
$assert( ! wp_native_auth_oauth_verify_request( $signature, $tampered ), 'tampered bundle rejected' );

$roundtrip = wp_native_auth_oauth_decode_bundle( wp_native_auth_oauth_encode_bundle( $bundle ) );
$assert( is_array( $roundtrip ) && $roundtrip['state'] === 'st4te' && (int) $roundtrip['user_id'] === $user_id, 'bundle roundtrips' );

$redirect = wp_native_auth_oauth_build_success_redirect( 'https://app.example.org/callback', 'the-code', 'st4te', 'https://auth.example.org' );
$assert( false !== strpos( $redirect, 'code=the-code' ), 'success redirect carries the code' );
$assert( false !== strpos( $redirect, 'iss=https%3A%2F%2Fauth.example.org' ), 'success redirect carries iss (RFC 9207)' );
$assert( false !== strpos( $redirect, 'state=st4te' ), 'success redirect echoes state' );

$error_redirect = wp_native_auth_oauth_build_error_redirect( 'https://app.example.org/callback', 'access_denied', '', 'st4te', 'https://auth.example.org' );
$assert( false !== strpos( $error_redirect, 'error=access_denied' ), 'error redirect carries access_denied' );
$assert( false !== strpos( $error_redirect, 'iss=https%3A%2F%2Fauth.example.org' ), 'error redirect carries iss (RFC 9207)' );

// ------------------------------------------------------------------
// 11. Exit-path probes (run in child processes).
// ------------------------------------------------------------------

fwrite( STDOUT, "\n-- token endpoint rejection probes --\n" );

$probe = static function ( string $scenario, array $expect ): bool {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/standalone/probe.php' ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1';
	exec( $cmd, $output_lines, $exit_code );
	$output = implode( "\n", $output_lines );

	if ( false !== strpos( $output, 'SURVIVED' ) ) {
		fwrite( STDOUT, "FAIL: probe {$scenario} did not terminate (endpoint failed to reject)\nOutput:\n{$output}\n" );
		return false;
	}

	foreach ( $expect as $needle ) {
		if ( false === strpos( $output, $needle ) ) {
			fwrite( STDOUT, "FAIL: probe {$scenario} expected [" . $needle . "] in output.\nOutput:\n{$output}\n" );
			return false;
		}
	}

	fwrite( STDOUT, "PASS: probe {$scenario} rejected with expected error\n" );
	return true;
};

$checks++;
if ( $probe( 'missing_verifier', array( '"error":"invalid_request"', 'code_verifier' ) ) === false ) {
	$failures++;
}

$checks++;
if ( $probe( 'wrong_verifier', array( '"error":"invalid_grant"' ) ) === false ) {
	$failures++;
}

$checks++;
if ( $probe( 'authorization_header', array( '"error":"invalid_client"' ) ) === false ) {
	$failures++;
}

$checks++;
if ( $probe( 'missing_code_challenge', array( 'REDIRECT:', 'error=invalid_request' ) ) === false ) {
	$failures++;
}

$checks++;
if ( $probe( 'plain_challenge_method', array( 'REDIRECT:', 'error=invalid_request', 'S256' ) ) === false ) {
	$failures++;
}

// ------------------------------------------------------------------
// Summary.
// ------------------------------------------------------------------

fwrite( STDOUT, "\n{$checks} assertion(s) run, {$failures} failure(s).\n" );
exit( 0 === $failures ? 0 : 1 );
