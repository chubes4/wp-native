<?php
/**
 * Tests for the generic OAuth 2.1 authorization server (#80) —
 * token-endpoint security cases.
 *
 * WordPress integration tests (WP_UnitTestCase) exercising the real
 * DB-backed flow in `inc/oauth-token.php` + `inc/oauth-codes.php`:
 *
 *   1. A missing `code_verifier` is rejected at the token endpoint —
 *      the classic silent PKCE bypass must be impossible.
 *   2. A wrong verifier fails PKCE and consumes the code (one
 *      verification attempt per code, ever).
 *   3. A correct S256 verifier completes the exchange and binds the
 *      RFC 8707 resource audience to the issued tokens.
 *   4. Replaying a consumed code is rejected and revokes everything
 *      the code minted.
 *   5. Refresh rotation + reuse detection through the OAuth path.
 *   6. Cross-client refresh is rejected.
 *
 * Run via the standard WP plugin test harness (wp-env / wp-phpunit).
 * A runnable standalone version of these flows lives in
 * tests/test-oauth-standalone.php (no WordPress needed).
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
class Test_WP_Native_Auth_OAuth_Token extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string,mixed> */
	private $client;

	/** @var string */
	private $redirect_uri = 'https://app.example.org/callback';

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->client  = $this->register_client();
	}

	private function register_client( string $redirect_uri = '' ): array {
		$result = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris' => array( '' !== $redirect_uri ? $redirect_uri : $this->redirect_uri ),
				'client_name'   => 'Integration Client',
			),
			'203.0.113.10'
		);

		$this->assertNotWPError( $result );

		return $result;
	}

	private function pkce_pair(): array {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return array( $verifier, $challenge );
	}

	private function mint_code( string $challenge, string $resource = '' ): string {
		return wp_native_auth_oauth_create_authorization_code(
			$this->user_id,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$challenge,
			$resource,
			WP_NATIVE_AUTH_OAUTH_SCOPE
		)['code'];
	}

	private function clear_rate_limit( string $device_id ): void {
		delete_transient( 'wp_native_auth_refresh_' . md5( $device_id ) );
	}

	private function device_row( string $device_id ): ?array {
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
	}

	/**
	 * 1. THE critical case: a MISSING verifier is invalid_request, not a
	 *    silent pass. Every issued code is S256-bound, so an exchange
	 *    without a verifier can never be legitimate.
	 */
	public function test_missing_verifier_is_rejected(): void {
		list( , $challenge ) = $this->pkce_pair();
		$code                = $this->mint_code( $challenge );

		$exchange = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			''
		);

		$this->assertWPError( $exchange, 'A missing code_verifier must be rejected.' );
		$this->assertSame( 'invalid_request', $exchange->get_error_code() );
		$this->assertStringContainsString( 'code_verifier', $exchange->get_error_message(), 'The error must name the missing code_verifier.' );

		// The failed exchange must not have minted a session.
		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$this->assertNull( $this->device_row( $device_id ), 'No refresh session may exist after a rejected exchange.' );
	}

	/**
	 * 2. A well-formed but WRONG verifier fails PKCE, and because the
	 *    atomic claim precedes verification, the code is consumed: a code
	 *    gets exactly one verification attempt ever.
	 */
	public function test_wrong_verifier_fails_and_consumes_code(): void {
		list( , $challenge ) = $this->pkce_pair();
		$code                = $this->mint_code( $challenge );

		$exchange = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			str_repeat( 'w', 43 )
		);

		$this->assertWPError( $exchange );
		$this->assertSame( 'invalid_grant', $exchange->get_error_code() );

		// The code is already claimed: the failed verification consumed it.
		global $wpdb;
		$claimed = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT claim_token FROM %i WHERE code_hash = %s LIMIT 1',
				wp_native_auth_oauth_codes_table_name(),
				hash( 'sha256', $code )
			)
		);
		$this->assertNotEmpty( $claimed, 'The code must be claimed by the failed verification attempt.' );
	}

	/**
	 * 3. Correct S256 verifier: exchange succeeds; the resource audience
	 *    and client binding land on both tokens and the session row.
	 */
	public function test_valid_exchange_binds_resource_and_client(): void {
		$resource = 'https://api.example.org/mcp';
		list( $verifier, $challenge ) = $this->pkce_pair();
		$code = $this->mint_code( $challenge, $resource );

		$grant = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$verifier,
			'Integration Client'
		);

		$this->assertIsArray( $grant );
		$this->assertSame( 'Bearer', $grant['token_type'] );
		$this->assertSame( WP_NATIVE_AUTH_OAUTH_SCOPE, $grant['scope'] );

		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$row       = $this->device_row( $device_id );

		$this->assertNotNull( $row, 'The grant lives in the existing refresh-token table.' );
		$this->assertSame( (string) $this->client['client_id'], (string) $row['oauth_client_id'] );
		$this->assertSame( $resource, (string) $row['resource'] );

		$payload = wp_native_auth_get_access_token_payload( (string) $grant['access_token'] );
		$this->assertIsArray( $payload );
		$this->assertSame( $resource, $payload['meta']['resource'] ?? '' );
		$this->assertSame( (string) $this->client['client_id'], $payload['meta']['client_id'] ?? '' );
		$this->assertSame( $this->user_id, (int) $payload['user_id'] );
	}

	/**
	 * 4. REPLAY: the second redemption of the same code is rejected and
	 *    the session minted from it is revoked (RFC 6749 §4.1.2).
	 */
	public function test_code_replay_is_rejected_and_revokes(): void {
		list( $verifier, $challenge ) = $this->pkce_pair();
		$code                         = $this->mint_code( $challenge );

		$first = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$verifier
		);
		$this->assertIsArray( $first );

		$fired = array();
		add_action(
			'wp_native_auth_oauth_code_replay_detected',
			static function ( $uid, $cid ) use ( &$fired ): void {
				$fired[] = array( $uid, $cid );
			},
			10,
			2
		);

		$second = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$verifier
		);

		$this->assertWPError( $second, 'The second redemption must be rejected.' );
		$this->assertSame( 'invalid_grant', $second->get_error_code() );
		$this->assertNotEmpty( $fired, 'The replay action must fire.' );

		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$row       = $this->device_row( $device_id );
		$this->assertNotNull( $row );
		$this->assertNotEmpty( $row['revoked_at'], 'Tokens minted from the replayed code must be revoked.' );
	}

	/**
	 * 5. Refresh rotation + reuse detection through the OAuth path: the
	 *    pre-existing rotation lifecycle does the work untouched.
	 */
	public function test_refresh_rotation_and_reuse_via_oauth_path(): void {
		list( $verifier, $challenge ) = $this->pkce_pair();
		$code                         = $this->mint_code( $challenge );

		$grant = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$verifier
		);
		$this->assertIsArray( $grant );

		$device_id   = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$family_before = (string) $this->device_row( $device_id )['token_family'];

		$this->clear_rate_limit( $device_id );
		$rotated = wp_native_auth_oauth_refresh_grant( (string) $grant['refresh_token'], (string) $this->client['client_id'] );

		$this->assertIsArray( $rotated, 'OAuth refresh grant must rotate.' );
		$this->assertNotSame( $grant['refresh_token'], $rotated['refresh_token'] );
		$this->assertSame( $family_before, (string) $this->device_row( $device_id )['token_family'] );

		// REUSE: presenting the superseded token again must trip reuse
		// detection and revoke the family.
		$this->clear_rate_limit( $device_id );
		$replay = wp_native_auth_oauth_refresh_grant( (string) $grant['refresh_token'], (string) $this->client['client_id'] );

		$this->assertWPError( $replay, 'A replayed refresh token must be rejected.' );
		$this->assertSame( 'invalid_grant', $replay->get_error_code() );

		$row = $this->device_row( $device_id );
		$this->assertNotEmpty( $row['revoked_at'], 'The whole family must be revoked on reuse.' );

		// And the rotated-away token is now dead too.
		$this->clear_rate_limit( $device_id );
		$after = wp_native_auth_oauth_refresh_grant( (string) $rotated['refresh_token'], (string) $this->client['client_id'] );
		$this->assertWPError( $after );
	}

	/**
	 * 6. A refresh token bound to client A cannot be refreshed through
	 *    client B.
	 */
	public function test_cross_client_refresh_is_rejected(): void {
		list( $verifier, $challenge ) = $this->pkce_pair();
		$code                         = $this->mint_code( $challenge );

		$grant = wp_native_auth_oauth_exchange_code(
			$code,
			(string) $this->client['client_id'],
			$this->redirect_uri,
			$verifier
		);
		$this->assertIsArray( $grant );

		$other   = $this->register_client( 'https://other.example.org/cb' );
		$foreign = wp_native_auth_oauth_refresh_grant( (string) $grant['refresh_token'], (string) $other['client_id'] );

		$this->assertWPError( $foreign, 'Cross-client refresh must be rejected.' );
		$this->assertSame( 'invalid_client', $foreign->get_error_code() );

		// The legitimate client is unaffected.
		$device_id = wp_native_auth_oauth_device_id( $this->user_id, (string) $this->client['client_id'] );
		$this->clear_rate_limit( $device_id );
		$own = wp_native_auth_oauth_refresh_grant( (string) $grant['refresh_token'], (string) $this->client['client_id'] );
		$this->assertIsArray( $own, 'The owning client can still refresh.' );
	}
}
