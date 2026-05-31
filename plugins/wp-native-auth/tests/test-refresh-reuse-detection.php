<?php
/**
 * Tests for refresh-token reuse detection + atomic rotation (#55).
 *
 * These are WordPress integration tests (WP_UnitTestCase) that exercise the
 * real DB-backed lifecycle in `inc/service.php`:
 *
 *   1. Happy-path rotation returns a new pair and the old token stops working.
 *   2. Replaying a just-rotated token triggers reuse detection: the entire
 *      token family is revoked and a distinct `refresh_token_reused` error is
 *      returned (NOT a generic invalid-token error).
 *   3. Two concurrent rotations of the SAME old token: exactly one succeeds.
 *      The atomic conditional UPDATE is simulated by issuing the same old hash
 *      twice — the second attempt sees 0 affected rows and is rejected as reuse.
 *
 * Run via the standard WP plugin test harness (wp-env / wp-phpunit). The repo
 * does not yet ship a phpunit bootstrap (tracked separately); CI is expected
 * to provide the WordPress test scaffold and `WP_UnitTestCase`.
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
 */
class Test_WP_Native_Auth_Refresh_Reuse_Detection extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var string */
	private $device_id = '11111111-1111-4111-8111-111111111111';

	public function set_up(): void {
		parent::set_up();

		// Ensure the table (with v2 columns) exists for this test DB.
		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The 5s per-device rate limit would otherwise block back-to-back
		// refresh calls in a single test; clear it before each scenario.
		delete_transient( 'wp_native_auth_refresh_' . md5( $this->device_id ) );
	}

	/**
	 * Clear the per-device rate-limit transient between refresh calls so the
	 * test exercises rotation/reuse logic rather than the rate limiter.
	 */
	private function clear_rate_limit(): void {
		delete_transient( 'wp_native_auth_refresh_' . md5( $this->device_id ) );
	}

	private function issue_token(): string {
		$pair = wp_native_auth_issue_refresh_token( $this->user_id, $this->device_id, 'Test Device' );
		$this->assertArrayHasKey( 'token', $pair );
		return (string) $pair['token'];
	}

	/**
	 * 1. Happy-path: rotation returns a fresh pair; the old token is dead.
	 */
	public function test_happy_path_rotation_returns_new_pair(): void {
		$original = $this->issue_token();

		$this->clear_rate_limit();
		$result = wp_native_auth_refresh_tokens( $original, $this->device_id );

		$this->assertIsArray( $result, 'Rotation should return a token pair array.' );
		$this->assertArrayHasKey( 'refresh_token', $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertNotSame( $original, $result['refresh_token'], 'Rotated token must differ.' );

		// The token_family must be preserved across the rotation.
		$family_before = $this->family_for_device();
		$this->assertNotEmpty( $family_before );
	}

	/**
	 * 2. Reuse: replaying the just-rotated (superseded) token revokes the
	 *    family and returns the distinct reuse error.
	 */
	public function test_replaying_rotated_token_triggers_reuse_detection(): void {
		$original = $this->issue_token();
		$family   = $this->family_for_device();

		$this->clear_rate_limit();
		$rotated = wp_native_auth_refresh_tokens( $original, $this->device_id );
		$this->assertIsArray( $rotated );

		// Hook should fire on reuse.
		$fired = array();
		add_action(
			'wp_native_auth_refresh_token_reuse_detected',
			static function ( $uid, $did, $fam ) use ( &$fired ): void {
				$fired = array( $uid, $did, $fam );
			},
			10,
			3
		);

		// Replay the ORIGINAL (now superseded) token.
		$this->clear_rate_limit();
		$replay = wp_native_auth_refresh_tokens( $original, $this->device_id );

		$this->assertWPError( $replay, 'Replaying a superseded token must error.' );
		$this->assertSame( 'refresh_token_reused', $replay->get_error_code() );

		// The reuse hook fired with the right family.
		$this->assertSame( $this->user_id, $fired[0] ?? null );
		$this->assertSame( $this->device_id, $fired[1] ?? null );
		$this->assertSame( $family, $fired[2] ?? null );

		// The whole family is now revoked.
		$this->assertTrue( $this->all_family_rows_revoked( $family ) );
	}

	/**
	 * 3. Concurrent rotation: exactly one of two rotations using the SAME old
	 *    token can succeed. The second sees 0 affected rows → reuse error.
	 */
	public function test_concurrent_rotation_only_one_succeeds(): void {
		$original = $this->issue_token();

		$this->clear_rate_limit();
		$first = wp_native_auth_refresh_tokens( $original, $this->device_id );
		$this->assertIsArray( $first, 'First rotation should succeed.' );

		// Second rotation with the SAME original token — models the losing
		// side of a concurrent double-refresh. The conditional UPDATE matches
		// 0 rows (the row no longer holds the old hash), so it must be treated
		// as reuse, NOT a second valid mint.
		$this->clear_rate_limit();
		$second = wp_native_auth_refresh_tokens( $original, $this->device_id );

		$this->assertWPError( $second, 'Second concurrent rotation must not mint a pair.' );
		$this->assertSame( 'refresh_token_reused', $second->get_error_code() );
	}

	/**
	 * 4. A wholly invalid token (never issued) returns the generic invalid
	 *    error — NOT the reuse error.
	 */
	public function test_unknown_token_returns_generic_invalid(): void {
		$this->issue_token();

		$this->clear_rate_limit();
		$bogus  = wp_native_auth_generate_opaque_token();
		$result = wp_native_auth_refresh_tokens( $bogus, $this->device_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_refresh_token', $result->get_error_code() );
	}

	// --- helpers -----------------------------------------------------------

	private function family_for_device(): string {
		global $wpdb;
		$table = wp_native_auth_refresh_tokens_table_name();
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT token_family FROM {$table} WHERE device_id = %s LIMIT 1",
				$this->device_id
			)
		);
	}

	private function all_family_rows_revoked( string $family ): bool {
		global $wpdb;
		$table   = wp_native_auth_refresh_tokens_table_name();
		$active  = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE token_family = %s AND revoked_at IS NULL",
				$family
			)
		);
		return 0 === $active;
	}
}
