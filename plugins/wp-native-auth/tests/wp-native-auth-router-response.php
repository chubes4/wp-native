<?php
/**
 * Test-support exception for the OAuth router tests.
 *
 * The router-level tests register listeners on the
 * `wp_native_auth_oauth_before_response` and
 * `wp_native_auth_oauth_before_redirect` observability actions; the
 * listeners throw this before an endpoint's terminal exit, capturing
 * what would have been sent so the test can assert on it.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( class_exists( 'WP_Native_Auth_Router_Response' ) ) {
	return;
}

/**
 * Captured OAuth endpoint response.
 */
class WP_Native_Auth_Router_Response extends Exception {

	/** @var array<string,mixed> */
	public array $payload = array();

	public int $status = 0;

	/** @var array<string,string> */
	public array $headers = array();
}
