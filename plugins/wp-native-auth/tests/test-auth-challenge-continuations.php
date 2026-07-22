<?php
/**
 * Integration tests for provider-agnostic authentication continuations.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/** @group auth @group security */
class Test_WP_Native_Auth_Challenge_Continuations extends WP_UnitTestCase {
	private int $user_id;
	private string $device_id = '11111111-1111-4111-8111-111111111111';
	private string $other_device_id = '22222222-2222-4222-8222-222222222222';
	private string $password = 'correct horse battery staple';
	private string $original_client = '';

	public function set_up(): void {
		parent::set_up();
		wp_native_auth_install_refresh_tokens_table();
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'challenge-user-' . wp_generate_uuid4(),
				'user_pass'  => $this->password,
			)
		);
		$this->original_client            = isset( $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) ? (string) $_SERVER['HTTP_WP_NATIVE_CLIENT'] : '';
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = 'test-client';
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_native_auth_login_challenge' );
		remove_all_filters( 'wp_native_auth_verify_login_challenge' );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = $this->original_client;
		parent::tear_down();
	}

	public function test_password_only_login_is_unchanged(): void {
		$result = $this->login();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'refresh_token', $result );
		$this->assertArrayNotHasKey( 'challenge_required', $result );
	}

	public function test_challenge_issues_continuation_without_tokens(): void {
		$this->require_challenge();
		$result = $this->login();
		$this->assertTrue( $result['challenge_required'] );
		$this->assertSame( 'test', $result['challenge']['type'] );
		$this->assertNotEmpty( $result['continuation_token'] );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	public function test_successful_continuation_is_single_use(): void {
		$result = $this->pending_login();
		$this->accept_response( 'valid' );
		$completed = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array( 'answer' => 'valid' ) );
		$this->assertIsArray( $completed );
		$this->assertArrayHasKey( 'access_token', $completed );
		$this->assertSame( 1, $this->refresh_token_count() );

		$replay = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array( 'answer' => 'valid' ) );
		$this->assertWPError( $replay );
		$this->assertSame( 'invalid_continuation', $replay->get_error_code() );
	}

	public function test_expired_continuation_is_rejected(): void {
		global $wpdb;
		$result = $this->pending_login();
		$wpdb->update(
			wp_native_auth_continuations_table_name(),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'token_hash' => hash( 'sha256', $result['continuation_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);
		$expired = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
		$this->assertWPError( $expired );
		$this->assertSame( 'continuation_expired', $expired->get_error_code() );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	public function test_wrong_device_and_client_are_rejected(): void {
		$result       = $this->pending_login();
		$wrong_device = wp_native_auth_continue_login( $result['continuation_token'], $this->other_device_id, array() );
		$this->assertWPError( $wrong_device );
		$this->assertSame( 'invalid_continuation', $wrong_device->get_error_code() );

		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = 'other-client';
		$wrong_client = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
		$this->assertWPError( $wrong_client );
		$this->assertSame( 'invalid_continuation', $wrong_client->get_error_code() );
	}

	public function test_user_substitution_is_rejected(): void {
		global $wpdb;
		$result        = $this->pending_login();
		$other_user_id = self::factory()->user->create();
		$wpdb->update(
			wp_native_auth_continuations_table_name(),
			array( 'user_id' => $other_user_id ),
			array( 'token_hash' => hash( 'sha256', $result['continuation_token'] ) ),
			array( '%d' ),
			array( '%s' )
		);
		$substitution = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
		$this->assertWPError( $substitution );
		$this->assertSame( 'invalid_continuation', $substitution->get_error_code() );
	}

	public function test_policy_rejection_does_not_issue_tokens(): void {
		$result = $this->pending_login();
		add_filter(
			'wp_native_auth_verify_login_challenge',
			static fn() => new WP_Error( 'policy_rejected', 'Rejected.', array( 'status' => 403 ) )
		);
		$rejected = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
		$this->assertWPError( $rejected );
		$this->assertSame( 'policy_rejected', $rejected->get_error_code() );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	public function test_malformed_input_is_rejected(): void {
		$result = wp_native_auth_continue_login( '', 'not-a-device', array() );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_continuation', $result->get_error_code() );
	}

	public function test_attempt_boundary_rate_limits_without_tokens(): void {
		$result = $this->pending_login();
		add_filter( 'wp_native_auth_verify_login_challenge', '__return_false' );

		for ( $attempt = 0; $attempt < WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS; $attempt++ ) {
			$rejected = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
			$this->assertWPError( $rejected );
			$this->assertSame( 'challenge_rejected', $rejected->get_error_code() );
		}

		$limited = wp_native_auth_continue_login( $result['continuation_token'], $this->device_id, array() );
		$this->assertWPError( $limited );
		$this->assertSame( 'continuation_rate_limited', $limited->get_error_code() );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	private function login() {
		$user = get_user_by( 'id', $this->user_id );
		return wp_native_auth_login_with_tokens( $user->user_login, $this->password, $this->device_id );
	}

	private function pending_login(): array {
		$this->require_challenge();
		$result = $this->login();
		$this->assertIsArray( $result );
		return $result;
	}

	private function require_challenge(): void {
		add_filter( 'wp_native_auth_login_challenge', static fn() => array( 'type' => 'test', 'prompt' => 'Respond.' ) );
	}

	private function accept_response( string $expected ): void {
		add_filter(
			'wp_native_auth_verify_login_challenge',
			static fn( $verified, $user, $response ) => isset( $response['answer'] ) && $expected === $response['answer'],
			10,
			3
		);
	}

	private function refresh_token_count(): int {
		global $wpdb;
		$table = wp_native_auth_refresh_tokens_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$this->user_id
			)
		);
	}
}
