<?php
/**
 * Standalone (no-WordPress) bootstrap for the OAuth standalone tests.
 *
 * Provides just enough of the WordPress surface for the real plugin
 * code in ../../inc/ to run against a SQLite database, so the OAuth
 * security flows can be exercised on any PHP CLI without a WordPress
 * test scaffold.
 *
 * Deliberate fidelity notes:
 *   - wpdb::insert()/update() replicate WordPress's NULL semantics
 *     (PHP null binds to literal SQL NULL), as in core wp-db.php.
 *   - wpdb::prepare() handles %s, %d, %f, %i, and %% like core.
 *   - dbDelta() translates the plugin's MySQL DDL to SQLite DDL.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/faux-wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

// ------------------------------------------------------------------
// Minimal shim support files (dbDelta lives in the faux upgrade.php).
// ------------------------------------------------------------------

if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
file_put_contents(
	ABSPATH . 'wp-admin/includes/upgrade.php',
	'<?php // dbDelta shim is defined in tests/standalone/bootstrap.php via this file. '
	. 'The definition lives in wpshim_dbDelta() and is declared after the PDO handle exists.'
);

// ------------------------------------------------------------------
// WordPress classes: WP_Error, WP_User, WP_Die exception.
// ------------------------------------------------------------------

class WP_Error {
	private array $errors = array();
	private array $error_data = array();

	public function __construct( $code = '', $message = '', $data = array() ) {
		if ( '' !== $code ) {
			$this->errors[ $code ] = $message;
			if ( array() !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		return array_key_first( $this->errors ) ?? '';
	}

	public function get_error_message( $code = '' ) {
		$code = '' !== $code ? $code : $this->get_error_code();
		return $this->errors[ $code ] ?? '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' !== $code ? $code : $this->get_error_code();
		return $this->error_data[ $code ] ?? null;
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}
}

class WPDieException extends RuntimeException {
}

class WP_User {
	public int $ID = 0;
	public string $user_login = '';
	public string $user_email = '';
	public string $display_name = '';
	public array $roles = array();
	public string $user_registered = '';

	public function __construct( array $row ) {
		$this->ID              = (int) $row['ID'];
		$this->user_login      = (string) $row['user_login'];
		$this->user_email      = (string) $row['user_email'];
		$this->display_name    = (string) $row['display_name'];
		$this->roles           = (array) ( $row['roles'] ?? array( 'subscriber' ) );
		$this->user_registered = (string) ( $row['user_registered'] ?? gmdate( 'Y-m-d H:i:s' ) );
	}
}

// ------------------------------------------------------------------
// Hooks.
// ------------------------------------------------------------------

$GLOBALS['wpshim_filters']    = array();
$GLOBALS['wpshim_actions']    = array();
$GLOBALS['wpshim_fired']      = array();

function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['wpshim_filters'][ $hook ][] = array( $cb, $priority, $args );
	return true;
}

function add_action( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
	return add_filter( $hook, $cb, $priority, $args );
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wpshim_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, $entry[2] ) ) );
	}
	return $value;
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['wpshim_fired'][ $hook ][] = $args;
	// add_action() registers into the same registry as add_filter(),
	// mirroring core's single-callback store.
	foreach ( $GLOBALS['wpshim_filters'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
	}
}

function doing_action( string $hook ): bool {
	return false;
}

function did_action( string $hook ): int {
	return isset( $GLOBALS['wpshim_fired'][ $hook ] ) ? count( $GLOBALS['wpshim_fired'][ $hook ] ) : 0;
}

/** Whether an action fired; returns the recorded invocations. */
function wpshim_fired_actions( string $hook ): array {
	return $GLOBALS['wpshim_fired'][ $hook ] ?? array();
}

// ------------------------------------------------------------------
// Sanitization and escaping.
// ------------------------------------------------------------------

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $value ) {
	$value = (string) $value;
	$value = preg_replace( '/<[^>]*>/', '', $value );
	return trim( (string) $value );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html_e( $text, $domain = 'default' ): void {
	echo esc_html( $text );
}

function esc_attr_e( $text, $domain = 'default' ): void {
	echo esc_attr( $text );
}

function language_attributes(): void {
}

function bloginfo( $key ): void {
	echo 'utf-8';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

// ------------------------------------------------------------------
// Options and transients (single shared store, expiry honored).
// ------------------------------------------------------------------

$GLOBALS['wpshim_site_options'] = array();
$GLOBALS['wpshim_transients']   = array();

function update_site_option( string $key, $value ): bool {
	$GLOBALS['wpshim_site_options'][ $key ] = $value;
	return true;
}

function get_site_option( string $key, $default = false ) {
	return $GLOBALS['wpshim_site_options'][ $key ] ?? $default;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['wpshim_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $ttl > 0 ? time() + $ttl : 0,
	);
	return true;
}

function set_site_transient( string $key, $value, int $ttl = 0 ): bool {
	return set_transient( $key, $value, $ttl );
}

function wpshim_transient_get( string $key ) {
	$entry = $GLOBALS['wpshim_transients'][ $key ] ?? null;
	if ( null === $entry ) {
		return false;
	}
	if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
		unset( $GLOBALS['wpshim_transients'][ $key ] );
		return false;
	}
	return $entry['value'];
}

function get_transient( string $key ) {
	return wpshim_transient_get( $key );
}

function get_site_transient( string $key ) {
	return wpshim_transient_get( $key );
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['wpshim_transients'][ $key ] );
	return true;
}

function delete_site_transient( string $key ): bool {
	return delete_transient( $key );
}

// ------------------------------------------------------------------
// URLs.
// ------------------------------------------------------------------

function home_url( string $path = '' ): string {
	if ( '' === $path ) {
		return 'https://auth.example.org';
	}
	return 'https://auth.example.org/' . ltrim( $path, '/' );
}

function wp_login_url( string $redirect_to = '' ): string {
	$url = 'https://auth.example.org/wp-login.php';
	if ( '' !== $redirect_to ) {
		$url .= '?redirect_to=' . rawurlencode( $redirect_to ) . '&reauth=1';
	}
	return $url;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, (int) $component );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function rawurlencode_deep( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'rawurlencode_deep', $value );
	}
	return rawurlencode( (string) $value );
}

function wpshim_rebuild_query( array $params ): string {
	// Mirrors WP add_query_arg semantics: values are used verbatim
	// (callers pre-encode via rawurlencode_deep).
	$parts = array();
	foreach ( $params as $key => $value ) {
		$parts[] = $key . '=' . $value;
	}
	return implode( '&', $parts );
}

function add_query_arg( ...$args ): string {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = (string) ( $args[1] ?? '' );
	} else {
		$params = array( (string) $args[0] => (string) $args[1] );
		$url    = (string) ( $args[2] ?? '' );
	}

	$parsed = parse_url( $url );
	$base   = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );
	parse_str( $parsed['query'] ?? '', $existing );

	return $base . '?' . wpshim_rebuild_query( array_merge( $existing, $params ) );
}

$GLOBALS['wpshim_redirects'] = array();

function wp_redirect( string $url, int $status = 302 ): bool {
	$GLOBALS['wpshim_redirects'][] = $url;
	return true;
}

// ------------------------------------------------------------------
// Users and nonces.
// ------------------------------------------------------------------

$GLOBALS['wpshim_users']           = array();
$GLOBALS['wpshim_current_user_id'] = 0;

function wpshim_create_user( array $args ): int {
	static $next = 0;
	$next++;
	$id                             = $next;
	$GLOBALS['wpshim_users'][ $id ] = new WP_User(
		array_merge(
			array(
				'ID'           => $id,
				'user_login'   => 'user' . $id,
				'user_email'   => 'user' . $id . '@example.org',
				'display_name' => 'User ' . $id,
				'roles'        => array( 'subscriber' ),
			),
			$args
		)
	);
	return $id;
}

function get_userdata( int $id ) {
	return $GLOBALS['wpshim_users'][ $id ] ?? false;
}

function get_user_by( string $field, $value ) {
	if ( 'id' !== $field ) {
		return false;
	}
	return get_userdata( (int) $value );
}

function get_avatar_url( int $id, array $args = array() ): string {
	return 'https://auth.example.org/avatar/' . $id . '.png';
}

function wp_set_current_user( int $id ): void {
	$GLOBALS['wpshim_current_user_id'] = $id;
}

function get_current_user_id(): int {
	return $GLOBALS['wpshim_current_user_id'];
}

function is_user_logged_in(): bool {
	return get_current_user_id() > 0;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'wpshim-static-hmac-secret::' . $scheme;
}

function wpshim_nonce_hash( string $action, int $uid, int $bucket ): string {
	return substr( hash_hmac( 'sha256', $bucket . '|' . $action . '|' . $uid, wp_salt( 'nonce' ) ), -12 );
}

function wp_create_nonce( string $action ): string {
	return wpshim_nonce_hash( $action, get_current_user_id(), (int) floor( time() / 43200 ) );
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	$uid    = get_current_user_id();
	$bucket = (int) floor( time() / 43200 );
	foreach ( array( $bucket, $bucket - 1 ) as $candidate ) {
		if ( hash_equals( wpshim_nonce_hash( $action, $uid, $candidate ), $nonce ) ) {
			return 1;
		}
	}
	return false;
}

// ------------------------------------------------------------------
// Misc.
// ------------------------------------------------------------------

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function wp_generate_uuid4(): string {
	$bytes = random_bytes( 16 );
	$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
	$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
	$hex = bin2hex( $bytes );
	return sprintf( '%s-%s-%s-%s-%s', substr( $hex, 0, 8 ), substr( $hex, 8, 4 ), substr( $hex, 12, 4 ), substr( $hex, 16, 4 ), substr( $hex, 20, 12 ) );
}

function status_header( int $code ): void {
}

function nocache_headers(): void {
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new WPDieException( is_string( $message ) ? $message : json_encode( $message ) );
}

function wp_safe_remote_get( string $url, array $args = array() ) {
	return new WP_Error( 'http_request_failed', 'Network fetches are not available in the standalone harness.' );
}

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/' ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://auth.example.org/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function register_activation_hook( $file, $cb ): void {
}

function register_deactivation_hook( $file, $cb ): void {
}

function wp_next_scheduled( string $hook ): bool {
	return false;
}

function wp_schedule_event( int $ts, string $recurrence, string $hook ): bool {
	return true;
}

function wp_clear_scheduled_hook( string $hook ): void {
}

// ------------------------------------------------------------------
// wpdb over SQLite.
// ------------------------------------------------------------------

class WPDB_SQLite_Shim {
	public string $base_prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'PRAGMA journal_mode = MEMORY' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	/** Quote a literal the way wpdb binds values. */
	private function literal( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_bool( $value ) ) {
			return (string) (int) $value;
		}
		return $this->pdo->quote( (string) $value );
	}

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$out  = '';
		$rest = (string) $query;
		$i    = 0;

		while ( ( $pos = strpos( $rest, '%' ) ) !== false ) {
			$out .= substr( $rest, 0, $pos );
			$rest = substr( $rest, $pos );

			if ( preg_match( '/^%(d|s|f|i)/', $rest, $m ) ) {
				$value = $args[ $i ] ?? null;
				$i++;
				switch ( $m[1] ) {
					case 'd':
						$out .= (string) (int) $value;
						break;
					case 'f':
						$out .= (string) (float) $value;
						break;
					case 'i':
						$out .= '"' . str_replace( '"', '""', (string) $value ) . '"';
						break;
					default:
						$out .= $this->literal( $value );
				}
				$rest = substr( $rest, 2 );
			} elseif ( 0 === strpos( $rest, '%%' ) ) {
				$out .= '%';
				$rest = substr( $rest, 2 );
			} else {
				$out .= '%';
				$rest = substr( $rest, 1 );
			}
		}

		return $out . $rest;
	}

	private function select( string $sql ): array {
		$statement = $this->pdo->query( $sql );
		$rows      = $statement ? $statement->fetchAll( PDO::FETCH_ASSOC ) : array();
		return is_array( $rows ) ? $rows : array();
	}

	public function query( $sql ) {
		$sql = (string) $sql;
		try {
			if ( 0 === stripos( trim( $sql ), 'SELECT' ) ) {
				return count( $this->select( $sql ) );
			}
			return $this->pdo->exec( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function get_results( $sql, $output = 'ARRAY_A' ) {
		return $this->select( (string) $sql );
	}

	public function get_row( $sql, $output = 'ARRAY_A' ) {
		$rows = $this->select( (string) $sql );
		return $rows[0] ?? null;
	}

	public function get_col( $sql ) {
		return array_map(
			static fn( array $row ) => array_values( $row )[0],
			$this->select( (string) $sql )
		);
	}

	public function get_var( $sql ) {
		$rows = $this->select( (string) $sql );
		if ( array() === $rows ) {
			return null;
		}
		return array_values( $rows[0] )[0];
	}

	public function insert( string $table, array $data, $format = null ) {
		$columns = array();
		$values  = array();
		foreach ( $data as $column => $value ) {
			$columns[] = '"' . $column . '"';
			// Core wpdb binds PHP null as literal SQL NULL in INSERT.
			$values[]  = null === $value ? 'NULL' : $this->literal( $value );
		}

		$sql = 'INSERT INTO "' . $table . '" (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';

		try {
			$this->pdo->exec( $sql );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		$sets = array();
		foreach ( $data as $column => $value ) {
			// Core wpdb writes `col = NULL` for PHP null in UPDATE.
			$sets[] = null === $value ? '"' . $column . '" = NULL' : '"' . $column . '" = ' . $this->literal( $value );
		}

		$conditions = array();
		foreach ( $where as $column => $value ) {
			$conditions[] = null === $value ? '"' . $column . '" IS NULL' : '"' . $column . '" = ' . $this->literal( $value );
		}

		$sql = 'UPDATE "' . $table . '" SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conditions );

		try {
			return $this->pdo->exec( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function delete( string $table, array $where, $where_format = null ) {
		$conditions = array();
		foreach ( $where as $column => $value ) {
			$conditions[] = null === $value ? '"' . $column . '" IS NULL' : '"' . $column . '" = ' . $this->literal( $value );
		}

		$sql = 'DELETE FROM "' . $table . '" WHERE ' . implode( ' AND ', $conditions );

		try {
			return $this->pdo->exec( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}
}

/**
 * Translate this plugin's MySQL DDL into SQLite DDL and execute it.
 *
 * Reinstalling drops and recreates (fresh test DB each run); real
 * dbDelta diffs instead, but the shim only needs the same schema to
 * materialize.
 */
function wpshim_dbDelta( $ddl ): array {
	global $wpdb;

	foreach ( (array) $ddl as $sql ) {
		if ( ! preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)\s*;/s', (string) $sql, $m ) ) {
			continue;
		}

		$table = trim( $m[1], '`' );
		$lines = preg_split( '/\r\n|\r|\n/', $m[2] ) ?: array();

		$columns = array();
		$indexes = array();

		foreach ( $lines as $line ) {
			$line = trim( rtrim( trim( $line ), ',' ) );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^PRIMARY KEY\s*\(/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^(UNIQUE KEY|KEY)\s+(\S+)\s*\((.*)\)$/i', $line, $im ) ) {
				$unique = 0 === stripos( $im[1], 'UNIQUE' );
				$cols   = implode(
					', ',
					array_map(
						static fn( $c ) => '"' . trim( trim( $c ), '`' ) . '"',
						explode( ',', $im[3] )
					)
				);
				$indexes[] = 'CREATE ' . ( $unique ? 'UNIQUE ' : '' ) . 'INDEX IF NOT EXISTS "'
					. $table . '_' . trim( $im[2], '`' ) . '" ON "' . $table . '" (' . $cols . ')';
				continue;
			}

			if ( preg_match( '/^(\S+)\s+(.*)$/', $line, $cm ) ) {
				$column = trim( $cm[1], '`' );
				$rest   = str_ireplace( ' unsigned', '', $cm[2] );

				if ( false !== stripos( $rest, 'AUTO_INCREMENT' ) ) {
					$columns[] = '"' . $column . '" INTEGER PRIMARY KEY AUTOINCREMENT';
				} else {
					$columns[] = '"' . $column . '" ' . $rest;
				}
			}
		}

		$wpdb->query( 'DROP TABLE IF EXISTS "' . $table . '"' );
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode( ', ', $columns ) . ')' );
		foreach ( $indexes as $index_sql ) {
			$wpdb->query( $index_sql );
		}
	}

	return array();
}

$GLOBALS['wpdb'] = new WPDB_SQLite_Shim();

// The faux upgrade.php defers to the shim defined above.
file_put_contents(
	ABSPATH . 'wp-admin/includes/upgrade.php',
	"<?php function dbDelta( \$ddl = '' ) { return wpshim_dbDelta( \$ddl ); }"
);

// ------------------------------------------------------------------
// Load the real plugin.
// ------------------------------------------------------------------

require_once dirname( __DIR__, 2 ) . '/wp-native-auth.php';

// Install the schema (init hooks do not fire in CLI).
wp_native_auth_ensure_schema();
