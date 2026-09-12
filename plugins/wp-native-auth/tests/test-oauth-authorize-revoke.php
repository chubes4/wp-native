<?php
/**
 * Tests for the /authorize consent round-trip, RFC 8707 resource
 * validation, and /revoke ownership checks (#80).
 *
 * The consent form never trusts the browser: parameters travel in an
 * HMAC-signed, age-limited bundle bound to the authorizing user. These
 * tests verify the sign/verify seam plus revocation ownership.
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
class Test_WP_Native_Auth_OAuth_Authorize_Revoke extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string,mixed> */
	private $client;

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$client = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example.org/callback' ),
				'client_name'   => 'Consent Client',
			),
			'203.0.113.30'
		);
		$this->assertNotWPError( $client );
		$this->client = $client;
	}

	private function sample_bundle(): array {
		return array(
			'client_id'      => (string) $this->client['client_id'],
			'redirect_uri'   => 'https://app.example.org/callback',
			'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', str_repeat( 'v', 43 ), true ) ), '+/', '-_' ), '=' ),
			'scope'          => WP_NATIVE_AUTH_OAUTH_SCOPE,
			'resource'       => 'https://api.example.org/mcp',
			'state'          => 'xyz',
			'user_id'        => $this->user_id,
			'issued'         => time(),
		);
	}

	public function test_consent_bundle_verifies_when_untampered(): void {
		$bundle    = $this->sample_bundle();
		$signature = wp_native_auth_oauth_sign_request( $bundle );

		$this->assertTrue( wp_native_auth_oauth_verify_request( $signature, $bundle ) );
		$this->assertFalse( wp_native_auth_oauth_verify_request( str_repeat( 'a', 64 ), $bundle ) );

		// Malformed transport encodings decode to null instead of a bundle.
		$this->assertNull( wp_native_auth_oauth_decode_bundle( '' ) );
		$this->assertNull( wp_native_auth_oauth_decode_bundle( '####' ) );
	}

	public function test_consent_bundle_rejects_field_swapping(): void {
		$bundle    = $this->sample_bundle();
		$signature = wp_native_auth_oauth_sign_request( $bundle );

		$swapped                  = $bundle;
		$swapped['redirect_uri']  = 'https://evil.example.org/cb';

		$this->assertFalse(
			wp_native_auth_oauth_verify_request( $signature, $swapped ),
			'A bundle with swapped fields must fail signature verification.'
		);
	}

	public function test_consent_bundle_roundtrips(): void {
		$bundle    = $this->sample_bundle();
		$roundtrip = wp_native_auth_oauth_decode_bundle( wp_native_auth_oauth_encode_bundle( $bundle ) );

		$this->assertIsArray( $roundtrip );
		$this->assertSame( $bundle['state'], $roundtrip['state'] );
		$this->assertSame( (string) $this->client['client_id'], $roundtrip['client_id'] );
		$this->assertSame( $this->user_id, (int) $roundtrip['user_id'] );
	}

	public function test_resource_validation(): void {
		$this->assertTrue( wp_native_auth_oauth_validate_resource( 'https://api.example.org/mcp' ) );
		$this->assertTrue( wp_native_auth_oauth_validate_resource( 'https://api.example.org' ) );

		$this->assertFalse( wp_native_auth_oauth_validate_resource( 'http://api.example.org/mcp' ), 'http audiences rejected.' );
		$this->assertFalse( wp_native_auth_oauth_validate_resource( 'https://api.example.org/mcp#frag' ), 'Fragments rejected.' );
		$this->assertFalse( wp_native_auth_oauth_validate_resource( 'api.example.org/mcp' ), 'Relative URIs rejected.' );
		$this->assertFalse( wp_native_auth_oauth_validate_resource( '' ), 'Empty is absent, not a resource — validation fails.' );
		$this->assertFalse( wp_native_auth_oauth_validate_resource( 'https://' . str_repeat( 'a', 200 ) . '.org' ), 'Over-column-length audiences rejected.' );
	}

	public function test_device_id_is_stable_and_uuid_v4(): void {
		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );

		$this->assertTrue( wp_native_auth_is_uuid_v4( $device_id ) );
		$this->assertSame( $device_id, wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] ) );
		$this->assertNotSame( $device_id, wp_native_auth_oauth_device_id( $this->user_id + 1, (string) $this->client['client_id'] ) );
		$this->assertNotSame( $device_id, wp_native_auth_oauth_device_id( $this->user_id, 'https://other.example.org' ) );
	}

	public function test_revoke_ownership_enforced(): void {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = wp_native_auth_oauth_create_authorization_code(
			$this->user_id,
			(string) $this->client['client_id'],
			'https://app.example.org/callback',
			$challenge,
			'',
			WP_NATIVE_AUTH_OAUTH_SCOPE
		)['code'];

		$grant = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			'https://app.example.org/callback',
			$verifier
		);
		$this->assertIsArray( $grant );

		// Foreign client: nothing happens.
		$foreign = wp_native_auth_oauth_register_client(
			array( 'redirect_uris' => array( 'https://foreign.example.org/cb' ) ),
			'203.0.113.31'
		);
		$this->assertNotWPError( $foreign );

		$this->assertFalse(
			wp_native_auth_oauth_revoke_refresh_for_client( (string) $grant['refresh_token'], (string) $foreign['client_id'] ),
			'A foreign client must not revoke a token it does not own.'
		);
		$this->assertFalse(
			wp_native_auth_oauth_revoke_access_for_client( (string) $grant['access_token'], (string) $foreign['client_id'] ),
			'A foreign client must not revoke an access token it does not own.'
		);

		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$this->assertTrue(
			wp_native_auth_oauth_revoke_refresh_for_client( (string) $grant['refresh_token'], (string) $this->client['client_id'] ),
			'The owning client revokes its refresh session.'
		);

		global $wpdb;
		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT revoked_at FROM %i WHERE device_id = %s LIMIT 1',
				wp_native_auth_refresh_tokens_table_name(),
				$device_id
			)
		);
		$this->assertNotEmpty( $revoked );

		$this->assertTrue(
			wp_native_auth_oauth_revoke_access_for_client( (string) $grant['access_token'], (string) $this->client['client_id'] ),
			'The owning client revokes the access token.'
		);
		$this->assertNull(
			wp_native_auth_get_access_token_payload( (string) $grant['access_token'] ),
			'The access token no longer resolves after revocation.'
		);
	}
}
