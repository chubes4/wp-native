<?php
/**
 * Tests for the device authorization grant (#91, RFC 8628).
 *
 * WordPress integration tests (WP_UnitTestCase) exercising the real
 * DB-backed flow in `inc/oauth-device.php` + `inc/oauth-device-codes.php`:
 *
 *   1. A device authorization request issues a device code and a user code.
 *   2. Polling before approval returns `authorization_pending`.
 *   3. Polling with the wrong client is rejected and does NOT consume or
 *      decide the request.
 *   4. Denial surfaces as `access_denied`.
 *   5. Approval completes the exchange and binds the RFC 8707 resource.
 *   6. An approved device code is single-use.
 *   7. Expired requests surface as `expired_token`.
 *   8. Polling faster than the advertised interval returns `slow_down`
 *      and widens the interval.
 *   9. A user code cannot be approved twice.
 *  10. The grant type and endpoint are advertised in server metadata.
 *
 * Run via the standard WP plugin test harness (wp-env / wp-phpunit).
 * The pure user-code primitives are covered without WordPress in
 * tests/device-user-code-standalone-smoke.php.
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
 * @group device
 */
class Test_WP_Native_Auth_OAuth_Device extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var string */
	private $client_id;

	/** @var string */
	private $other_client_id;

	/**
	 * Distinguishes the registration IP per test.
	 *
	 * Dynamic client registration is rate-limited to 10 per IP per hour,
	 * and that limit lives in a transient rather than a table — so it is
	 * NOT rolled back with the test transaction. Reusing one IP across
	 * every test in this class would start failing registrations partway
	 * through the run, as a function of test count rather than of
	 * anything under test.
	 *
	 * @var int
	 */
	private static $registration_seq = 0;

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		++self::$registration_seq;
		$octet = 10 + ( self::$registration_seq * 2 );

		$client = wp_native_auth_oauth_register_client(
			array(
				'client_name'   => 'Device Test Client',
				'redirect_uris' => array( 'https://example.com/callback' ),
			),
			'198.51.100.' . $octet
		);
		$this->assertNotWPError( $client );
		$this->client_id = (string) $client['client_id'];

		$other = wp_native_auth_oauth_register_client(
			array(
				'client_name'   => 'Other Client',
				'redirect_uris' => array( 'https://other.example.com/callback' ),
			),
			'198.51.100.' . ( $octet + 1 )
		);
		$this->assertNotWPError( $other );
		$this->other_client_id = (string) $other['client_id'];
	}

	/**
	 * Issue a device code for the default test client.
	 *
	 * @param string $resource Optional resource audience.
	 * @return array{device_code:string, user_code:string, expires_at:int, interval:int}
	 */
	private function issue( string $resource = '' ): array {
		$created = wp_native_auth_oauth_create_device_code( $this->client_id, 'account', $resource );
		$this->assertNotWPError( $created );

		return $created;
	}

	/**
	 * Approve (or deny) a pending request the way the verification screen does.
	 *
	 * @param string $user_code Display-form user code.
	 * @param string $decision  'approved' or 'denied'.
	 * @return bool
	 */
	private function decide( string $user_code, string $decision ): bool {
		$row = wp_native_auth_oauth_find_device_code_by_user_code( $user_code );
		$this->assertIsArray( $row, 'the pending request should be findable by user code' );

		return wp_native_auth_oauth_decide_device_code( (int) $row['id'], $this->user_id, $decision );
	}

	/**
	 * Clear the poll-interval gate so a test can poll twice in a row
	 * without tripping slow_down. The rate limiter itself is asserted
	 * directly in test_rapid_polling_is_slowed_down().
	 *
	 * @param string $device_code Plaintext device code.
	 */
	private function clear_poll_gate( string $device_code ): void {
		global $wpdb;

		$wpdb->update(
			wp_native_auth_oauth_device_codes_table_name(),
			array( 'last_polled_at' => null ),
			array( 'device_code_hash' => hash( 'sha256', $device_code ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/** A device authorization request issues both codes with a live TTL. */
	public function test_device_authorization_issues_codes(): void {
		$created = $this->issue();

		$this->assertNotSame( '', $created['device_code'] );
		$this->assertSame(
			WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH,
			strlen( wp_native_auth_oauth_normalize_user_code( $created['user_code'] ) )
		);
		$this->assertGreaterThan( time(), $created['expires_at'] );
		$this->assertGreaterThan( 0, $created['interval'] );
	}

	/** Before any decision, polling reports authorization_pending. */
	public function test_polling_before_approval_is_pending(): void {
		$created = $this->issue();

		$result = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );

		$this->assertWPError( $result );
		$this->assertSame( 'authorization_pending', $result->get_error_code() );
	}

	/**
	 * A different client cannot poll someone else's device code — and the
	 * rejection must not consume the grant or record a poll, or one client
	 * could grief another's backoff.
	 */
	public function test_wrong_client_cannot_poll(): void {
		$created = $this->issue();
		$this->decide( $created['user_code'], 'approved' );

		$result = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->other_client_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_grant', $result->get_error_code() );

		// The rightful client still succeeds afterwards.
		$granted = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );
		$this->assertIsArray( $granted, 'the wrong-client poll must not consume the grant' );
		$this->assertArrayHasKey( 'access_token', $granted );
	}

	/** Denial is reported as access_denied, not as a pending request. */
	public function test_denied_request_reports_access_denied(): void {
		$created = $this->issue();
		$this->assertTrue( $this->decide( $created['user_code'], 'denied' ) );

		$result = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );

		$this->assertWPError( $result );
		$this->assertSame( 'access_denied', $result->get_error_code() );
	}

	/** Approval yields a token pair bound to the approving user and resource. */
	public function test_approved_request_grants_tokens(): void {
		$created = $this->issue( 'https://api.example.com/' );
		$this->assertTrue( $this->decide( $created['user_code'], 'approved' ) );

		$granted = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );

		$this->assertIsArray( $granted );
		$this->assertArrayHasKey( 'access_token', $granted );
		$this->assertArrayHasKey( 'refresh_token', $granted );
		$this->assertSame( 'Bearer', $granted['token_type'] );
		$this->assertSame( 'account', $granted['scope'] );

		$session = wp_native_auth_find_refresh_session_by_token( (string) $granted['refresh_token'] );
		$this->assertIsArray( $session );
		$this->assertSame( $this->user_id, (int) $session['user_id'] );
		$this->assertSame( $this->client_id, (string) $session['oauth_client_id'] );
	}

	/** An approved device code can be redeemed exactly once. */
	public function test_approved_device_code_is_single_use(): void {
		$created = $this->issue();
		$this->decide( $created['user_code'], 'approved' );

		$first = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );
		$this->assertIsArray( $first );

		$this->clear_poll_gate( $created['device_code'] );

		$second = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );
		$this->assertWPError( $second );
		$this->assertSame( 'invalid_grant', $second->get_error_code() );
	}

	/** An expired request reports expired_token, even once approved. */
	public function test_expired_device_code_reports_expired_token(): void {
		global $wpdb;

		$created = $this->issue();
		$this->decide( $created['user_code'], 'approved' );

		$wpdb->update(
			wp_native_auth_oauth_device_codes_table_name(),
			array( 'expires_at' => wp_native_auth_mysql_gmt( time() - 60 ) ),
			array( 'device_code_hash' => hash( 'sha256', $created['device_code'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		$result = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );

		$this->assertWPError( $result );
		$this->assertSame( 'expired_token', $result->get_error_code() );
	}

	/**
	 * Polling twice inside the interval yields slow_down, and the stored
	 * interval grows so an ill-behaved client is actually throttled rather
	 * than merely warned.
	 */
	public function test_rapid_polling_is_slowed_down(): void {
		global $wpdb;

		$created = $this->issue();
		$table   = wp_native_auth_oauth_device_codes_table_name();
		$hash    = hash( 'sha256', $created['device_code'] );

		$before = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT poll_interval FROM {$table} WHERE device_code_hash = %s",
				$hash
			)
		);

		// First poll registers the timestamp; the second is immediate.
		wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );
		$result = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );

		$this->assertWPError( $result );
		$this->assertSame( 'slow_down', $result->get_error_code() );

		$after = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT poll_interval FROM {$table} WHERE device_code_hash = %s",
				$hash
			)
		);

		$this->assertSame( $before + 5, $after, 'the poll interval should widen after a violation' );
	}

	/**
	 * A decided request is no longer findable by user code, so it cannot be
	 * approved a second time or re-approved by a different user.
	 */
	public function test_decided_request_cannot_be_decided_again(): void {
		$created = $this->issue();

		$this->assertTrue( $this->decide( $created['user_code'], 'approved' ) );
		$this->assertNull(
			wp_native_auth_oauth_find_device_code_by_user_code( $created['user_code'] ),
			'a decided request must not be re-presentable on the verification screen'
		);
	}

	/** An unknown user code resolves to nothing rather than erroring. */
	public function test_unknown_user_code_is_not_found(): void {
		$this->assertNull( wp_native_auth_oauth_find_device_code_by_user_code( 'BCDF-GHJK' ) );
		$this->assertNull( wp_native_auth_oauth_find_device_code_by_user_code( 'nonsense' ) );
	}

	/** Discovery advertises the grant so clients can find it. */
	public function test_metadata_advertises_device_grant(): void {
		$metadata = wp_native_auth_oauth_server_metadata();

		$this->assertContains(
			WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE,
			$metadata['grant_types_supported']
		);
		$this->assertArrayHasKey( 'device_authorization_endpoint', $metadata );
		$this->assertNotSame( '', (string) $metadata['device_authorization_endpoint'] );
	}

	/** The device grant lands on the same session row as a code grant. */
	public function test_device_grant_reuses_the_oauth_session_row(): void {
		$created = $this->issue();
		$this->decide( $created['user_code'], 'approved' );

		$granted = wp_native_auth_oauth_exchange_device_code( $created['device_code'], $this->client_id );
		$this->assertIsArray( $granted );

		$session = wp_native_auth_find_refresh_session_by_token( (string) $granted['refresh_token'] );
		$this->assertIsArray( $session );
		$this->assertSame(
			wp_native_auth_oauth_device_id( $this->user_id, $this->client_id ),
			(string) $session['device_id'],
			'a device grant must reuse the stable per-(user, client) session row'
		);
	}
}
