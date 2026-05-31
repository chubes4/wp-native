<?php
/**
 * Standalone (no-WordPress) test of the reuse-detection DECISION LOGIC.
 *
 * The DB-backed behavior lives in WP integration tests
 * (test-refresh-reuse-detection.php). This file isolates and verifies the
 * pure branch logic that classifies a presented token hash as:
 *
 *   - matches current hash → ROTATE
 *   - matches previous hash → REUSE (revoke family)
 *   - matches neither       → INVALID
 *
 * It is dependency-free so it can run on any PHP CLI:
 *
 *   php tests/test-reuse-logic-standalone.php
 *
 * Exit code 0 = all assertions passed, 1 = a failure.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

/**
 * Mirror of the classification used in wp_native_auth_refresh_tokens().
 *
 * Kept in lock-step with the service: current → rotate, prev → reuse,
 * neither → invalid. Uses hash_equals for constant-time comparison.
 *
 * @return 'rotate'|'reuse'|'invalid'
 */
function wp_native_auth_classify_presented_hash( string $current_hash, string $prev_hash, string $presented_hash ): string {
	$matches_current = hash_equals( $current_hash, $presented_hash );
	$matches_prev    = ( '' !== $prev_hash && hash_equals( $prev_hash, $presented_hash ) );

	if ( ! $matches_current && $matches_prev ) {
		return 'reuse';
	}
	if ( ! $matches_current ) {
		return 'invalid';
	}
	return 'rotate';
}

$failures = 0;
$assert   = static function ( bool $cond, string $label ) use ( &$failures ): void {
	if ( $cond ) {
		fwrite( STDOUT, "PASS: {$label}\n" );
	} else {
		fwrite( STDOUT, "FAIL: {$label}\n" );
		$failures++;
	}
};

$h = static fn( string $s ): string => hash( 'sha256', $s );

$current = $h( 'current-token' );
$prev    = $h( 'previous-token' );

// 1. Presenting the current token → rotate.
$assert(
	'rotate' === wp_native_auth_classify_presented_hash( $current, $prev, $h( 'current-token' ) ),
	'current token classified as rotate'
);

// 2. Presenting the superseded (previous) token → reuse.
$assert(
	'reuse' === wp_native_auth_classify_presented_hash( $current, $prev, $h( 'previous-token' ) ),
	'previous (superseded) token classified as reuse'
);

// 3. Presenting an unknown token → invalid.
$assert(
	'invalid' === wp_native_auth_classify_presented_hash( $current, $prev, $h( 'random-token' ) ),
	'unknown token classified as invalid'
);

// 4. Fresh login (no previous hash yet): unknown token is still invalid,
//    never misclassified as reuse.
$assert(
	'invalid' === wp_native_auth_classify_presented_hash( $current, '', $h( 'random-token' ) ),
	'no-prev-hash + unknown token classified as invalid (not reuse)'
);

// 5. Fresh login (no previous hash): current token still rotates.
$assert(
	'rotate' === wp_native_auth_classify_presented_hash( $current, '', $h( 'current-token' ) ),
	'no-prev-hash + current token classified as rotate'
);

// 6. Empty prev must never match an empty presented hash as reuse.
$assert(
	'invalid' === wp_native_auth_classify_presented_hash( $current, '', '' ),
	'empty prev + empty presented hash is invalid, not reuse'
);

if ( $failures > 0 ) {
	fwrite( STDOUT, "\n{$failures} assertion(s) failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "\nAll assertions passed.\n" );
exit( 0 );
