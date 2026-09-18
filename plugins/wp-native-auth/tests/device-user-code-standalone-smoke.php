<?php
/**
 * Standalone (no-WordPress) test of the device-grant user-code primitives.
 *
 * Unlike reuse-logic-standalone-smoke.php, this does NOT mirror the
 * implementation — it includes `inc/oauth-device-codes.php` directly and
 * exercises the real generation and normalization functions. Those two
 * call no WordPress APIs, so the only shim needed is ABSPATH.
 *
 * A mirrored copy of parsing logic can drift from the original and still
 * pass; this cannot.
 *
 * The DB-backed behavior (claims, polling state machine, rate limiting)
 * lives in the WP integration tests in test-oauth-device.php.
 *
 * Dependency-free, so it runs on any PHP CLI:
 *
 *   php tests/device-user-code-standalone-smoke.php
 *
 * Exit code 0 = all assertions passed, 1 = a failure.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

// The production file guards on ABSPATH; satisfy it without WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/oauth-device-codes.php';

$failures = 0;
$assert   = static function ( bool $cond, string $label ) use ( &$failures ): void {
	if ( $cond ) {
		fwrite( STDOUT, "PASS: {$label}\n" );
	} else {
		fwrite( STDOUT, "FAIL: {$label}\n" );
		++$failures;
	}
};

// 1. The alphabet excludes every transcription-ambiguous character.
//    This is the property the whole "read it aloud" design rests on, so
//    it is asserted rather than left to review.
$forbidden = str_split( 'AEIOU01258SZLI' );
$missing   = array();
foreach ( $forbidden as $char ) {
	if ( false !== strpos( WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET, $char ) ) {
		$missing[] = $char;
	}
}
$assert( array() === $missing, 'alphabet excludes vowels and ambiguous glyphs (found: ' . implode( ',', $missing ) . ')' );

// 2. The alphabet has no duplicate characters — a duplicate would skew
//    the distribution of random_int() selections toward that character.
$chars = str_split( WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET );
$assert( count( $chars ) === count( array_unique( $chars ) ), 'alphabet has no duplicate characters' );

// 3. Generated codes are in XXXX-XXXX display form.
$generated = wp_native_auth_oauth_generate_user_code();
$assert(
	1 === preg_match( '/^[' . preg_quote( WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET, '/' ) . ']{4}-[' . preg_quote( WP_NATIVE_AUTH_OAUTH_USER_CODE_ALPHABET, '/' ) . ']{4}$/', $generated ),
	"generated code is XXXX-XXXX over the alphabet ({$generated})"
);

// 4. A generated code survives its own normalization.
$assert(
	WP_NATIVE_AUTH_OAUTH_USER_CODE_LENGTH === strlen( wp_native_auth_oauth_normalize_user_code( $generated ) ),
	'generated code normalizes to the declared length'
);

// 5. Generation is not obviously degenerate. 200 draws from a ~35-bit
//    space should collide with negligible probability; a constant or
//    seeded generator shows up here immediately.
$seen = array();
for ( $i = 0; $i < 200; $i++ ) {
	$seen[ wp_native_auth_oauth_generate_user_code() ] = true;
}
$assert( count( $seen ) === 200, 'no collisions across 200 generated codes (got ' . count( $seen ) . ')' );

// 6. Normalization is forgiving in exactly the ways RFC 8628 §6.1 asks:
//    case, separators, and whitespace all wash out to one canonical form.
$canonical = wp_native_auth_oauth_normalize_user_code( 'WDJB-MJHT' );
$variants  = array(
	'WDJB-MJHT',
	'wdjb-mjht',
	'WDJBMJHT',
	'wdjb mjht',
	'  WDJB-MJHT  ',
	"WDJB\tMJHT",
	'WdJb-MjHt',
);
foreach ( $variants as $variant ) {
	$assert(
		wp_native_auth_oauth_normalize_user_code( $variant ) === $canonical && '' !== $canonical,
		"normalizes variant '{$variant}' to the canonical form"
	);
}

// 7. Equal normalization implies equal hash — the property the DB lookup
//    depends on. If this breaks, every typed-in code misses its row.
$assert(
	wp_native_auth_oauth_hash_user_code( wp_native_auth_oauth_normalize_user_code( 'wdjb mjht' ) )
		=== wp_native_auth_oauth_hash_user_code( wp_native_auth_oauth_normalize_user_code( 'WDJB-MJHT' ) ),
	'hash is stable across input formatting'
);

// 8. Wrong lengths are rejected rather than padded, truncated, or
//    silently accepted. A short code must never match a live row.
$rejected = array(
	''            => 'empty string',
	'WDJB'        => 'too short',
	'WDJB-MJH'    => 'one character short',
	'WDJB-MJHTB'  => 'two characters long',
	'WDJBMJHTX'   => 'nine valid characters',
	'------------' => 'separators only',
	'AEIOU'       => 'characters outside the alphabet only',
);
foreach ( $rejected as $input => $label ) {
	$assert( '' === wp_native_auth_oauth_normalize_user_code( (string) $input ), "rejects {$label}" );
}

// 9. Distinct codes stay distinct after hashing — no domain collapse.
$assert(
	wp_native_auth_oauth_hash_user_code( 'BCDFGHJK' ) !== wp_native_auth_oauth_hash_user_code( 'BCDFGHJM' ),
	'distinct codes produce distinct hashes'
);

// 10. The user-code hash is domain-separated from a bare SHA-256, so a
//     device_code_hash and a user_code_hash can never collide even if the
//     same string were somehow used for both.
$assert(
	wp_native_auth_oauth_hash_user_code( 'BCDFGHJK' ) !== hash( 'sha256', 'BCDFGHJK' ),
	'user-code hash is domain-separated from the device-code hash'
);

fwrite( STDOUT, 0 === $failures ? "\nAll assertions passed.\n" : "\n{$failures} assertion(s) failed.\n" );

exit( 0 === $failures ? 0 : 1 );
