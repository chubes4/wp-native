<?php
/**
 * Tests for the OAuth client binding surfaced by auth-sessions (#85) and
 * for revoking an OAuth-bound session through the auth-revoke-session
 * primitive.
 *
 * The grant path writes the client binding onto the refresh-token row:
 * `oauth_client_id` in its own column and the human-readable client name
 * (or client id fallback) in the device_name label. The sessions listing
 * must surface both, and revoking the row must end the whole token
 * lineage — OAuth grants live in the same table under the same
 * (user_id, device_id) key as native sessions.
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
class Test_WP_Native_Auth_Sessions_OAuth_Binding extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string,mixed> */
	private $client;

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		$client = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example.org/callback' ),
				'client_name'   => 'Sessions App',
			),
			'203.0.113.40'
		);
		$this->assertNotWPError( $client );
		$this->client = $client;
	}

	public function test_native_session_surfaces_null_oauth_binding(): void {
		$device_id = '11111111-1111-4111-8111-111111111111';
		wp_native_auth_issue_refresh_token( $this->user_id, $device_id, "Chris's iPhone" );

		$sessions = wp_native_auth_list_user_sessions( $this->user_id );

		$this->assertCount( 1, $sessions );
		$this->assertNull( $sessions[0]['oauth_client_id'], 'Native sessions must report a null oauth_client_id.' );
		$this->assertNull( $sessions[0]['oauth_client_name'], 'Native sessions must report a null oauth_client_name.' );
		$this->assertSame( "Chris's iPhone", $sessions[0]['device_name'] );
	}

	public function test_oauth_grant_surfaces_client_id_and_name(): void {
		$grant = $this->mint_grant( (string) $this->client['client_id'] );
		$this->assertIsArray( $grant );

		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$sessions  = wp_native_auth_list_user_sessions( $this->user_id );

		$this->assertCount( 1, $sessions );
		$this->assertSame( $device_id, $sessions[0]['device_id'] );
		$this->assertSame( (string) $this->client['client_id'], $sessions[0]['oauth_client_id'] );
		$this->assertSame( 'Sessions App', $sessions[0]['oauth_client_name'] );
	}

	public function test_oauth_grant_without_client_name_falls_back_to_client_id(): void {
		$anonymous = wp_native_auth_oauth_register_client(
			array( 'redirect_uris' => array( 'https://anon.example.org/cb' ) ),
			'203.0.113.41'
		);
		$this->assertNotWPError( $anonymous );
		$this->assertNull( $anonymous['client_name'] );

		$grant = $this->mint_grant( (string) $anonymous['client_id'] );
		$this->assertIsArray( $grant );

		$sessions = wp_native_auth_list_user_sessions( $this->user_id );

		$oauth_sessions = array_values(
			array_filter(
				$sessions,
				static fn( array $session ): bool => null !== $session['oauth_client_id']
			)
		);

		$this->assertCount( 1, $oauth_sessions );
		$this->assertSame( (string) $anonymous['client_id'], $oauth_sessions[0]['oauth_client_id'] );
		$this->assertSame( (string) $anonymous['client_id'], $oauth_sessions[0]['oauth_client_name'], 'An unnamed client falls back to its client id as the label.' );
	}

	public function test_revoking_oauth_session_kills_the_whole_token_lineage(): void {
		$client_id = (string) $this->client['client_id'];
		$grant     = $this->mint_grant( $client_id );
		$this->assertIsArray( $grant );

		$device_id = wp_native_auth_oauth_device_id( $this->user_id, $client_id );

		// Rotate once so the lineage holds a superseded token: the row is
		// the whole family, so revoking it must strand BOTH tokens.
		$rotated = $this->refresh( (string) $grant['refresh_token'], $device_id );
		$this->assertIsArray( $rotated );

		// What wp-native/auth-revoke-session executes for this device.
		$this->assertTrue( wp_native_auth_revoke_refresh_token( $this->user_id, $device_id ) );

		$this->assertSame( array(), wp_native_auth_list_user_sessions( $this->user_id ), 'A revoked OAuth session must not be listed.' );

		// The current token: found by hash with a matching client binding,
		// then refused because the row's revoked_at is set.
		$current = $this->refresh( (string) $rotated['refresh_token'], $device_id );
		$this->assertWPError( $current );
		$this->assertSame( 'invalid_refresh_token', $current->get_error_code() );

		// The superseded token: hits reuse detection, which revokes the
		// (already-revoked) family — the lineage has no way back.
		$superseded = $this->refresh( (string) $grant['refresh_token'], $device_id );
		$this->assertWPError( $superseded );
		$this->assertSame( 'refresh_token_reused', $superseded->get_error_code() );
	}

	public function test_access_token_retains_its_normal_ttl_after_oauth_session_revocation(): void {
		$client_id = (string) $this->client['client_id'];
		$grant     = $this->mint_grant( $client_id );
		$this->assertIsArray( $grant );

		$this->assertTrue(
			wp_native_auth_revoke_refresh_token( $this->user_id, wp_native_auth_oauth_device_id( $this->user_id, $client_id ) )
		);

		// The documented contract for logout/revoke-session (native and
		// OAuth alike): the refresh chain ends immediately; the short-lived
		// access token is bounded by its own TTL. Immediate deletion of a
		// presented access token is the /revoke endpoint's behavior.
		$this->assertSame( $this->user_id, wp_native_auth_validate_access_token( (string) $grant['access_token'] ) );
	}

	/**
	 * Mint an OAuth grant through the real code-exchange path.
	 *
	 * @param string $client_id Client identifier.
	 * @return array<string,mixed>|WP_Error Token response payload.
	 */
	private function mint_grant( string $client_id ): array|WP_Error {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes PKCE verifier bytes per RFC 7636, not code.
		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the S256 challenge digest, not code.
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = wp_native_auth_oauth_create_authorization_code(
			$this->user_id,
			$client_id,
			'https://app.example.org/callback',
			$challenge,
			'',
			WP_NATIVE_AUTH_OAUTH_SCOPE
		)['code'];

		$client = wp_native_auth_oauth_resolve_client( $client_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return wp_native_auth_oauth_exchange_code(
			$code,
			$client_id,
			'https://app.example.org/callback',
			$verifier,
			null !== $client['client_name'] ? $client['client_name'] : ''
		);
	}

	/**
	 * Rotate a refresh token, clearing the per-device rate-limit transient.
	 *
	 * @param string $token     Plaintext refresh token.
	 * @param string $device_id Device ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function refresh( string $token, string $device_id ): array|WP_Error {
		delete_transient( 'wp_native_auth_refresh_' . md5( $device_id ) );

		return wp_native_auth_refresh_tokens( $token, $device_id );
	}
}
