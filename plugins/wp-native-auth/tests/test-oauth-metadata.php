<?php
/**
 * Tests for the OAuth discovery metadata documents (#80).
 *
 * Verifies the required RFC 8414 / RFC 9728 keys and the exact flags
 * the issue mandates — including the `"none"` client-auth entry whose
 * absence makes some clients silently abandon CIMD and fall back to
 * dynamic registration.
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
 * @group oauth
 */
class Test_WP_Native_Auth_OAuth_Metadata extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private $as;

	/** @var array<string,mixed> */
	private $prm;

	public function set_up(): void {
		parent::set_up();
		$this->as  = wp_native_auth_oauth_server_metadata();
		$this->prm = wp_native_auth_oauth_protected_resource_metadata();
	}

	public function test_authorization_server_metadata_required_keys(): void {
		foreach ( array(
			'issuer',
			'authorization_endpoint',
			'token_endpoint',
			'registration_endpoint',
			'revocation_endpoint',
			'scopes_supported',
			'response_types_supported',
			'grant_types_supported',
			'code_challenge_methods_supported',
			'token_endpoint_auth_methods_supported',
			'client_id_metadata_document_supported',
			'authorization_response_iss_parameter_supported',
			'protected_resource_metadata',
		) as $key ) {
			$this->assertArrayHasKey( $key, $this->as, "Missing metadata key: {$key}" );
		}
	}

	public function test_issuer_is_site_url_without_trailing_slash(): void {
		$this->assertSame( untrailingslashit( home_url() ), $this->as['issuer'] );
		$this->assertSame( $this->as['issuer'], $this->prm['resource'] );
	}

	public function test_pkce_s256_is_advertised(): void {
		$this->assertSame( array( 'S256' ), $this->as['code_challenge_methods_supported'] );
	}

	/**
	 * The "none" entry is required: without it, public clients using
	 * client-id metadata documents silently fall back to DCR.
	 */
	public function test_none_client_auth_is_advertised(): void {
		$this->assertContains( 'none', $this->as['token_endpoint_auth_methods_supported'] );
		$this->assertContains( 'none', $this->as['revocation_endpoint_auth_methods_supported'] );
	}

	public function test_cimd_and_iss_flags_are_advertised(): void {
		$this->assertTrue( $this->as['client_id_metadata_document_supported'] );
		$this->assertTrue( $this->as['authorization_response_iss_parameter_supported'] );
	}

	/**
	 * Only the code grant and the device grant are advertised.
	 *
	 * The device grant adds a second way in, but it does not relax what this
	 * assertion has always really been guarding: no implicit grant, no
	 * resource-owner password grant, no token response type. Those are now
	 * asserted directly, so a future grant cannot quietly reintroduce one of
	 * them by only extending the expected list.
	 */
	public function test_only_code_and_device_grants_are_advertised(): void {
		$this->assertSame( array( 'code' ), $this->as['response_types_supported'] );
		$this->assertSame(
			array( 'authorization_code', 'refresh_token', WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE ),
			$this->as['grant_types_supported']
		);

		$this->assertNotContains( 'implicit', $this->as['grant_types_supported'] );
		$this->assertNotContains( 'password', $this->as['grant_types_supported'] );
		$this->assertNotContains( 'token', $this->as['response_types_supported'] );
	}

	/**
	 * A client cannot discover the device grant without its endpoint.
	 */
	public function test_device_authorization_endpoint_is_advertised(): void {
		$this->assertArrayHasKey( 'device_authorization_endpoint', $this->as );
		$this->assertNotEmpty( $this->as['device_authorization_endpoint'] );
	}

	public function test_single_scope_declared(): void {
		$this->assertSame( array( WP_NATIVE_AUTH_OAUTH_SCOPE ), $this->as['scopes_supported'] );
		$this->assertSame( array( WP_NATIVE_AUTH_OAUTH_SCOPE ), $this->prm['scopes_supported'] );
	}

	public function test_documents_encode_to_valid_json(): void {
		foreach ( array( $this->as, $this->prm ) as $document ) {
			$encoded = wp_json_encode( $document );
			$this->assertIsString( $encoded );
			$this->assertIsArray( json_decode( $encoded, true ) );
		}
	}

	public function test_prm_lists_the_authorization_server(): void {
		$this->assertSame( array( untrailingslashit( home_url() ) ), $this->prm['authorization_servers'] );
		$this->assertSame(
			untrailingslashit( home_url() ) . '/.well-known/oauth-protected-resource',
			$this->as['protected_resource_metadata']
		);
	}
}
