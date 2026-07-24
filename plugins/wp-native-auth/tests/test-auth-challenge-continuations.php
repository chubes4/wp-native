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
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
	}

	public function tear_down(): void {
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
		remove_all_filters( 'wp_native_auth_pre_login' );
		remove_all_filters( 'wp_native_auth_revalidate_login' );
		remove_all_filters( 'wp_native_auth_access_token_storage_result' );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = $this->original_client;
		parent::tear_down();
	}

	public function test_password_only_login_is_unchanged(): void {
		$result = $this->login();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayNotHasKey( 'challenge_required', $result );
	}

	public function test_challenge_issues_bound_continuation_without_tokens(): void {
		$this->register_policy();
		$result = $this->login();
		$this->assertTrue( $result['challenge_required'] );
		$this->assertSame( 'test-policy', $result['challenge_policy'] );
		$this->assertSame( 'test', $result['challenge']['type'] );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	public function test_successful_continuation_is_single_use(): void {
		$result    = $this->pending_login();
		$completed = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertIsArray( $completed );
		$this->assertArrayHasKey( 'access_token', $completed );
		$this->assertSame( 1, $this->refresh_token_count() );

		$replay = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertWPError( $replay );
		$this->assertSame( 'invalid_continuation', $replay->get_error_code() );
	}

	public function test_cross_blog_redemption_never_runs_verifier(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$calls  = 0;
		$result = $this->pending_login(
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);
		$other_blog = self::factory()->blog->create();
		switch_to_blog( $other_blog );
		$cross_blog = $this->continue( $result, array( 'answer' => 'valid' ) );
		restore_current_blog();

		$this->assertWPError( $cross_blog );
		$this->assertSame( 'invalid_continuation', $cross_blog->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_wrong_user_device_and_client_bindings_are_rejected(): void {
		global $wpdb;
		$calls   = 0;
		$pending = $this->pending_login(
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$wrong_device = wp_native_auth_continue_login( $pending['continuation_token'], $this->other_device_id, array() );
		$this->assertSame( 'invalid_continuation', $wrong_device->get_error_code() );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = 'other-client';
		$wrong_client = $this->continue( $pending, array() );
		$this->assertSame( 'invalid_continuation', $wrong_client->get_error_code() );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = 'test-client';

		$wpdb->update(
			wp_native_auth_continuations_table_name(),
			array( 'user_id' => self::factory()->user->create() ),
			array( 'token_hash' => hash( 'sha256', $pending['continuation_token'] ) )
		);
		$wrong_user = $this->continue( $pending, array() );
		$this->assertSame( 'invalid_continuation', $wrong_user->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_malformed_continuation_is_rejected(): void {
		$result = wp_native_auth_continue_login( '', 'not-a-device', array() );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_continuation', $result->get_error_code() );
	}

	public function test_verification_routes_only_to_issuing_policy(): void {
		$issuer_calls = 0;
		$other_calls  = 0;
		$this->register_policy(
			'test-policy',
			static function () use ( &$issuer_calls ) {
				++$issuer_calls;
				return new WP_Error( 'policy_rejected', 'Rejected.', array( 'status' => 403 ) );
			}
		);
		wp_native_auth_register_challenge_policy(
			'other-policy',
			'__return_null',
			static function () use ( &$other_calls ): bool {
				++$other_calls;
				return true;
			}
		);

		$result   = $this->login();
		$rejected = $this->continue( $result, array() );
		$this->assertWPError( $rejected );
		$this->assertSame( 'policy_rejected', $rejected->get_error_code() );
		$this->assertSame( 1, $issuer_calls );
		$this->assertSame( 0, $other_calls, 'An unrelated verifier cannot override issuer rejection.' );
	}

	public function test_reissuance_preserves_attempts_and_bounds_pending_rows(): void {
		$this->register_policy( 'test-policy', '__return_false' );
		$pending = $this->login();
		$this->assertSame( 'challenge_rejected', $this->continue( $pending, array() )->get_error_code() );

		$replacement = $this->login();
		$this->assertNotSame( $pending['continuation_token'], $replacement['continuation_token'] );
		$this->assertSame( 1, $this->continuation_count() );
		$this->assertSame( 'invalid_continuation', $this->continue( $pending, array() )->get_error_code() );

		for ( $attempt = 1; $attempt < WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS; $attempt++ ) {
			$this->assertSame( 'challenge_rejected', $this->continue( $replacement, array() )->get_error_code() );
			if ( $attempt + 1 < WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS ) {
				$replacement = $this->login();
			}
		}

		$limited = $this->login();
		$this->assertWPError( $limited );
		$this->assertSame( 'continuation_rate_limited', $limited->get_error_code() );
		$this->assertSame( 1, $this->continuation_count() );
	}

	public function test_concurrent_submission_runs_verifier_once(): void {
		$pending = array();
		$calls   = 0;
		$nested  = null;
		$this->register_policy(
			'test-policy',
			function () use ( &$pending, &$calls, &$nested ): bool {
				++$calls;
				$nested = $this->continue( $pending, array( 'answer' => 'valid' ) );
				return true;
			}
		);
		$pending = $this->login();
		$result  = $this->continue( $pending, array( 'answer' => 'valid' ) );

		$this->assertIsArray( $result );
		$this->assertWPError( $nested );
		$this->assertSame( 'continuation_in_progress', $nested->get_error_code() );
		$this->assertSame( 1, $calls );
	}

	public function test_slow_verifier_cannot_consume_expired_state(): void {
		global $wpdb;
		$this->register_policy(
			'test-policy',
			static function () use ( $wpdb ): bool {
				$wpdb->query( 'UPDATE ' . wp_native_auth_continuations_table_name() . " SET expires_at = '2000-01-01 00:00:00'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return true;
			}
		);
		$result  = $this->login();
		$expired = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertWPError( $expired );
		$this->assertSame( 'continuation_expired', $expired->get_error_code() );
		$this->assertSame( 0, $this->refresh_token_count() );
	}

	public function test_storage_failure_releases_claim_for_retry(): void {
		global $wpdb;
		$result        = $this->pending_login();
		$refresh_table = wp_native_auth_refresh_tokens_table_name();
		$wpdb->query( "DROP TABLE {$refresh_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failed = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertWPError( $failed );
		$this->assertSame( 'refresh_token_storage_failed', $failed->get_error_code() );

		wp_native_auth_install_refresh_tokens_table();
		$retry = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertIsArray( $retry, 'A persistence failure must not burn the continuation.' );
	}

	public function test_access_storage_failure_rolls_back_refresh_and_releases_claim(): void {
		$result = $this->pending_login();
		add_filter( 'wp_native_auth_access_token_storage_result', '__return_false' );
		$failed = $this->continue( $result, array( 'answer' => 'valid' ) );
		$this->assertWPError( $failed );
		$this->assertSame( 'access_token_storage_failed', $failed->get_error_code() );
		$this->assertSame( 0, $this->refresh_token_count() );

		remove_filter( 'wp_native_auth_access_token_storage_result', '__return_false' );
		$this->assertIsArray( $this->continue( $result, array( 'answer' => 'valid' ) ) );
	}

	public function test_pre_login_is_not_repeated_and_revalidation_keeps_context(): void {
		$pre_login_calls = 0;
		add_filter(
			'wp_native_auth_pre_login',
			static function ( $blocked ) use ( &$pre_login_calls ) {
				++$pre_login_calls;
				return $blocked;
			}
		);
		$context_seen = array();
		add_filter(
			'wp_native_auth_revalidate_login',
			static function ( $blocked, $user, $context ) use ( &$context_seen ) {
				$context_seen = $context;
				return $blocked;
			},
			10,
			3
		);
		$result = $this->pending_login();
		$this->assertIsArray( $this->continue( $result, array( 'answer' => 'valid' ) ) );
		$this->assertSame( 1, $pre_login_calls );
		$this->assertSame( 'login', $context_seen['reason'] );
		$this->assertSame( 'continuation', $context_seen['phase'] );
		$this->assertArrayHasKey( 'identifier', $context_seen );
	}

	public function test_schema_upgrade_runs_before_public_login(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE ' . wp_native_auth_continuations_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, 2 );
		$this->register_policy();
		$result = wp_native_auth_execute_login_ability(
			array(
				'identifier' => get_user_by( 'id', $this->user_id )->user_login,
				'password'   => $this->password,
				'device_id'  => $this->device_id,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( WP_NATIVE_AUTH_SCHEMA_VERSION, (int) get_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION ) );
		$this->assertSame( wp_native_auth_continuations_table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', wp_native_auth_continuations_table_name() ) ) );
	}

	public function test_cleanup_and_user_deletion_remove_ephemeral_state(): void {
		global $wpdb;
		$result = $this->pending_login();
		$wpdb->update(
			wp_native_auth_continuations_table_name(),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'token_hash' => hash( 'sha256', $result['continuation_token'] ) )
		);
		$this->assertSame( 1, wp_native_auth_cleanup_continuations() );

		$result = $this->login();
		wp_native_auth_delete_user_continuations( $this->user_id );
		$this->assertSame( 0, $this->continuation_count() );
		$this->assertNotEmpty( $result['continuation_token'] );
	}

	public function test_rest_schemas_expose_discriminated_contract(): void {
		if ( ! wp_get_ability( 'wp-native/auth-login' ) ) {
			wp_native_auth_register_login_ability();
		}
		if ( ! wp_get_ability( 'wp-native/auth-continue-login' ) ) {
			wp_native_auth_register_continue_login_ability();
		}
		$login = wp_get_ability( 'wp-native/auth-login' );
		$this->assertNotNull( $login );
		$schema = $login->get_output_schema();
		$this->assertContains( 'challenge_policy', $schema['oneOf'][1]['required'] );
		$this->assertSame( array( true ), $schema['oneOf'][1]['properties']['challenge_required']['enum'] );

		$continue = wp_get_ability( 'wp-native/auth-continue-login' );
		$this->assertContains( 'challenge_response', $continue->get_input_schema()['required'] );
	}

	private function register_policy( string $policy_id = 'test-policy', $verify = null ): void {
		wp_native_auth_register_challenge_policy(
			$policy_id,
			static fn() => array( 'type' => 'test', 'prompt' => 'Respond.' ),
			$verify ?? static fn( $user, $response ) => isset( $response['answer'] ) && 'valid' === $response['answer']
		);
	}

	private function login() {
		$user = get_user_by( 'id', $this->user_id );
		return wp_native_auth_login_with_tokens( $user->user_login, $this->password, $this->device_id );
	}

	private function pending_login( $verify = null ): array {
		$this->register_policy( 'test-policy', $verify );
		$result = $this->login();
		$this->assertIsArray( $result );
		return $result;
	}

	private function continue( array $pending, array $response ) {
		return wp_native_auth_continue_login( $pending['continuation_token'], $this->device_id, $response );
	}

	private function refresh_token_count(): int {
		global $wpdb;
		$table = wp_native_auth_refresh_tokens_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $this->user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function continuation_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wp_native_auth_continuations_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
