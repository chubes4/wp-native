<?php
/**
 * Tests for user-wide refresh-session revocation on password changes (#65).
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group auth
 * @group security
 */
class Test_WP_Native_Auth_Password_Session_Revocation extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var int */
	private $other_user_id;

	/** @var array<string,string> */
	private $tokens = array();

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();
		$this->user_id       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->tokens        = array(
			'phone'  => $this->issue_token( $this->user_id, '11111111-1111-4111-8111-111111111111' ),
			'laptop' => $this->issue_token( $this->user_id, '22222222-2222-4222-8222-222222222222' ),
			'other'  => $this->issue_token( $this->other_user_id, '33333333-3333-4333-8333-333333333333' ),
		);
	}

	public function test_wp_update_user_password_change_revokes_all_user_devices_only(): void {
		$result = wp_update_user(
			array(
				'ID'        => $this->user_id,
				'user_pass' => 'Changed-password-65!',
			)
		);

		$this->assertSame( $this->user_id, $result );
		$this->assertSame( 0, $this->active_session_count( $this->user_id ) );
		$this->assertSame( 1, $this->active_session_count( $this->other_user_id ) );
		$this->assert_refresh_rejected( $this->tokens['phone'], '11111111-1111-4111-8111-111111111111' );
		$this->assert_refresh_rejected( $this->tokens['laptop'], '22222222-2222-4222-8222-222222222222' );
	}

	public function test_reset_password_revokes_all_user_devices(): void {
		reset_password( get_user_by( 'id', $this->user_id ), 'Reset-password-65!' );

		$this->assertSame( 0, $this->active_session_count( $this->user_id ) );
		$this->assertSame( 1, $this->active_session_count( $this->other_user_id ) );
		$this->assert_refresh_rejected( $this->tokens['phone'], '11111111-1111-4111-8111-111111111111' );
	}

	public function test_user_wide_primitive_is_idempotent(): void {
		$this->assertSame( 2, wp_native_auth_revoke_user_refresh_tokens( $this->user_id ) );
		$this->assertSame( 0, wp_native_auth_revoke_user_refresh_tokens( $this->user_id ) );
		$this->assertSame( 1, $this->active_session_count( $this->other_user_id ) );
	}

	public function test_existing_access_token_retains_its_normal_ttl(): void {
		$access = wp_native_auth_generate_access_token( $this->user_id, '11111111-1111-4111-8111-111111111111' );

		wp_set_password( 'Direct-password-65!', $this->user_id );

		$this->assertSame( $this->user_id, wp_native_auth_validate_access_token( $access['token'] ) );
		$this->assertSame( 0, $this->active_session_count( $this->user_id ) );
	}

	public function test_storage_failure_returns_error_and_emits_failure_action(): void {
		global $wpdb;

		$table_name = wp_native_auth_refresh_tokens_table_name();
		$failure    = null;
		$fail_query = static function ( string $query ) use ( $table_name ): string {
			if ( str_contains( $query, "UPDATE {$table_name} SET revoked_at" ) ) {
				return 'INVALID USER-WIDE REVOCATION QUERY';
			}

			return $query;
		};
		$capture_failure = static function ( int $user_id, WP_Error $error ) use ( &$failure ): void {
			$failure = array( $user_id, $error );
		};

		add_filter( 'query', $fail_query );
		add_action( 'wp_native_auth_user_refresh_session_revocation_failed', $capture_failure, 10, 2 );
		$previous_suppression = $wpdb->suppress_errors( true );

		$result = wp_native_auth_revoke_user_refresh_tokens( $this->user_id );

		$wpdb->suppress_errors( $previous_suppression );
		remove_filter( 'query', $fail_query );
		remove_action( 'wp_native_auth_user_refresh_session_revocation_failed', $capture_failure, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'refresh_session_revocation_failed', $result->get_error_code() );
		$this->assertSame( $this->user_id, $failure[0] ?? null );
		$this->assertSame( $result, $failure[1] ?? null );
		$this->assertSame( 2, $this->active_session_count( $this->user_id ) );
	}

	private function issue_token( int $user_id, string $device_id ): string {
		$pair = wp_native_auth_issue_refresh_token( $user_id, $device_id, 'Test Device' );
		return (string) $pair['token'];
	}

	private function active_session_count( int $user_id ): int {
		global $wpdb;

		$table_name = wp_native_auth_refresh_tokens_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test table name is trusted.
				"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND revoked_at IS NULL",
				$user_id
			)
		);
	}

	private function assert_refresh_rejected( string $token, string $device_id ): void {
		delete_transient( 'wp_native_auth_refresh_' . md5( $device_id ) );
		$result = wp_native_auth_refresh_tokens( $token, $device_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_refresh_token', $result->get_error_code() );
	}
}
