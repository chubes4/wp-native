<?php
/**
 * Tests for OAuth redirect-URI validation (#80) and dynamic client
 * registration validation.
 *
 *   1. Exact-match enforcement for registered redirect URIs.
 *   2. The single exception: port-insensitive matching for http
 *      loopback URIs (localhost / 127.0.0.1 / ::1), host-identical.
 *   3. DCR rejects unacceptable URIs, confidential-client requests,
 *      and is rate-limited per IP.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	// Allows the file to be collected without a WP test harness present; the
	// real run happens in CI where WP_UnitTestCase exists.
	return;
}

/**
 * @group auth
 * @group security
 * @group oauth
 */
class Test_WP_Native_Auth_OAuth_Redirect_URI extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_native_auth_install_refresh_tokens_table();
	}

	private function validate( array $registered, string $presented ): bool {
		return wp_native_auth_oauth_validate_redirect_uri( $registered, $presented );
	}

	public function test_exact_match_required(): void {
		$registered = array( 'https://app.example.org/callback' );

		$this->assertTrue( $this->validate( $registered, 'https://app.example.org/callback' ) );
		$this->assertFalse( $this->validate( $registered, 'https://app.example.org/callback/' ), 'Trailing slash must not match.' );
		$this->assertFalse( $this->validate( $registered, 'https://app.example.org/callback?extra=1' ), 'Appended query must not match.' );
		$this->assertFalse( $this->validate( $registered, 'https://app.example.org/other' ) );
		$this->assertFalse( $this->validate( $registered, 'https://evil.example.org/callback' ) );
		$this->assertFalse( $this->validate( $registered, 'http://app.example.org/callback' ), 'Scheme change must not match.' );
		$this->assertFalse( $this->validate( $registered, 'https://app.example.org:8443/callback' ), 'Port change on https must not match.' );
	}

	public function test_loopback_port_is_ignored_for_http(): void {
		$registered = array( 'http://127.0.0.1:8765/oauth/done' );

		$this->assertTrue( $this->validate( $registered, 'http://127.0.0.1:8765/oauth/done' ), 'Identical URI matches.' );
		$this->assertTrue( $this->validate( $registered, 'http://127.0.0.1:9999/oauth/done' ), 'Different port matches (RFC 8252 §7.3).' );
		$this->assertTrue( $this->validate( $registered, 'http://127.0.0.1/oauth/done' ), 'Absent port matches.' );

		$this->assertFalse( $this->validate( $registered, 'http://127.0.0.1:9999/other' ), 'Path must still match exactly.' );
		$this->assertFalse( $this->validate( $registered, 'http://127.0.0.1:9999/oauth/done?x=1' ), 'Query must still match exactly.' );
	}

	public function test_loopback_exception_does_not_loosen_the_host(): void {
		$registered = array( 'http://127.0.0.1:8765/oauth/done' );

		$this->assertFalse( $this->validate( $registered, 'http://localhost:8765/oauth/done' ), 'localhost is a different host than 127.0.0.1.' );
		$this->assertFalse( $this->validate( $registered, 'http://[::1]:8765/oauth/done' ), '::1 is a different host than 127.0.0.1.' );

		$localhost = array( 'http://localhost:3000/cb' );
		$this->assertTrue( $this->validate( $localhost, 'http://localhost:4000/cb' ) );
		$this->assertFalse( $this->validate( $localhost, 'http://127.0.0.1:3000/cb' ) );
	}

	public function test_loopback_exception_requires_http(): void {
		$registered = array( 'https://app.example.org/callback' );

		$this->assertFalse( $this->validate( $registered, 'https://app.example.org:8443/callback' ), 'The port exception applies to http loopback URIs only.' );
	}

	public function test_uri_validation_rejects_unacceptable_shapes(): void {
		$this->assertTrue( wp_native_auth_oauth_is_valid_redirect_uri( 'https://app.example.org/cb' ) );
		$this->assertTrue( wp_native_auth_oauth_is_valid_redirect_uri( 'http://localhost:3000/cb' ) );
		$this->assertTrue( wp_native_auth_oauth_is_valid_redirect_uri( 'http://[::1]:3000/cb' ) );

		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( 'http://app.example.org/cb' ), 'Plain http non-loopback rejected.' );
		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( 'https://app.example.org/cb#frag' ), 'Fragments forbidden by RFC 6749.' );
		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( 'javascript:alert(1)' ) );
		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( 'https://user:pass@app.example.org/cb' ), 'Credentials forbidden.' );
		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( '' ) );
		$this->assertFalse( wp_native_auth_oauth_is_valid_redirect_uri( 'https://' . str_repeat( 'a', 600 ) . '.org/cb' ), 'Oversized URIs rejected.' );
	}

	public function test_dcr_rejects_bad_metadata(): void {
		$bad = wp_native_auth_oauth_register_client(
			array( 'redirect_uris' => array( 'http://app.example.org/cb' ) ),
			'203.0.113.20'
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'invalid_redirect_uri', $bad->get_error_code() );

		$bad = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris'              => array( 'https://app.example.org/cb' ),
				'token_endpoint_auth_method' => 'client_secret_basic',
			),
			'203.0.113.20'
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'invalid_client_metadata', $bad->get_error_code() );

		$bad = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array() ), '203.0.113.20' );
		$this->assertWPError( $bad, 'At least one redirect URI is required.' );
	}

	public function test_dcr_issues_public_clients(): void {
		$client = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example.org/cb' ),
				'client_name'   => 'Public App',
			),
			'203.0.113.21'
		);

		$this->assertIsArray( $client );
		$this->assertArrayHasKey( 'client_id', $client );
		$this->assertSame( 'none', $client['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $client, 'Public clients never receive a secret.' );

		$stored = wp_native_auth_oauth_find_registered_client( (string) $client['client_id'] );
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'https://app.example.org/cb' ), $stored['redirect_uris'] );
	}

	public function test_dcr_rate_limit_per_ip(): void {
		add_filter(
			'wp_native_auth_oauth_registration_rate_limit',
			static fn(): int => 2,
			10,
			0
		);

		$first  = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://a.example.org/cb' ) ), '198.51.100.5' );
		$second = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://a.example.org/cb' ) ), '198.51.100.5' );
		$third  = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://a.example.org/cb' ) ), '198.51.100.5' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertWPError( $third );
		$this->assertSame( 'rate_limited', $third->get_error_code() );

		$other_ip = wp_native_auth_oauth_register_client( array( 'redirect_uris' => array( 'https://a.example.org/cb' ) ), '198.51.100.6' );
		$this->assertIsArray( $other_ip, 'A different IP is unaffected.' );
	}
}
