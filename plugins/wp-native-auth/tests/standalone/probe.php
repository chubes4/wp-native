<?php
/**
 * Exit-path probe for the OAuth standalone tests.
 *
 * The endpoint handlers terminate the request (exit) when they send a
 * response, so rejection scenarios that live behind the HTTP shell run
 * in this separate process. The parent runner (test-oauth-standalone.php)
 * spawns it and asserts on the printed JSON / redirect target.
 *
 * If a handler SURVIVES a scenario that must reject, this probe prints
 * SURVIVED so the runner fails the check loudly.
 *
 * Usage: php tests/standalone/probe.php <scenario>
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../standalone/bootstrap.php';

register_shutdown_function(
	static function (): void {
		$redirects = $GLOBALS['wpshim_redirects'] ?? array();
		if ( array() !== $redirects ) {
			fwrite( STDOUT, "\nREDIRECT:" . (string) end( $redirects ) . "\n" );
		}
	}
);

$scenario = $argv[1] ?? '';

// Fixtures shared by every scenario: one user, one public client, one
// valid authorization code bound to a real S256 challenge.
$user_id = wpshim_create_user( array( 'roles' => array( 'editor' ) ) );
wp_set_current_user( $user_id );

$client = wp_native_auth_oauth_register_client(
	array(
		'redirect_uris' => array( 'https://app.example.org/callback' ),
		'client_name'   => 'Probe Client',
	),
	'203.0.113.50'
);
if ( is_wp_error( $client ) ) {
	fwrite( STDOUT, 'client registration failed: ' . $client->get_error_message() . "\n" );
	exit( 1 );
}

$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

$code = wp_native_auth_oauth_create_authorization_code(
	$user_id,
	(string) $client['client_id'],
	'https://app.example.org/callback',
	$challenge,
	'',
	WP_NATIVE_AUTH_OAUTH_SCOPE
)['code'];

switch ( $scenario ) {
	case 'missing_verifier':
		// THE critical case: no code_verifier at all. The endpoint must
		// answer invalid_request and terminate — never treat the absent
		// verifier as "no PKCE".
		wp_native_auth_oauth_token_from_authorization_code(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => (string) $client['client_id'],
				'redirect_uri' => 'https://app.example.org/callback',
			)
		);
		break;

	case 'wrong_verifier':
		wp_native_auth_oauth_token_from_authorization_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => (string) $client['client_id'],
				'redirect_uri'  => 'https://app.example.org/callback',
				'code_verifier' => str_repeat( 'w', 43 ),
			)
		);
		break;

	case 'authorization_header':
		// Public clients only: any Authorization header is refused.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
		wp_native_auth_oauth_token_from_authorization_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => (string) $client['client_id'],
				'redirect_uri'  => 'https://app.example.org/callback',
				'code_verifier' => $verifier,
			)
		);
		break;

	case 'missing_code_challenge':
		$_GET = array(
			'response_type' => 'code',
			'client_id'     => (string) $client['client_id'],
			'redirect_uri'  => 'https://app.example.org/callback',
			'state'         => 'probe-state',
		);
		wp_native_auth_oauth_handle_authorize();
		break;

	case 'plain_challenge_method':
		// "plain" (or an absent method) must be refused: S256 only.
		$_GET = array(
			'response_type'         => 'code',
			'client_id'             => (string) $client['client_id'],
			'redirect_uri'          => 'https://app.example.org/callback',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'plain',
			'state'                 => 'probe-state',
		);
		wp_native_auth_oauth_handle_authorize();
		break;

	default:
		fwrite( STDOUT, "unknown scenario: {$scenario}\n" );
		exit( 1 );
}

fwrite( STDOUT, "\nSURVIVED: scenario {$scenario} was not rejected\n" );
exit( 2 );
