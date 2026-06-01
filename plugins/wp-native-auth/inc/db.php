<?php
/**
 * Refresh tokens table — installer + table-name helper.
 *
 * The table is network-wide ({$wpdb->base_prefix}), shared across every blog
 * in a multisite install. Schema is the contract defined in SCHEMAS.md.
 *
 * Lineage: forked from extrachill-users (inc/auth-tokens/db.php). All
 * Extra Chill specifics stripped — this runs on vanilla WordPress.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Schema version for the refresh tokens table.
 *
 * Bump this whenever the table shape changes so the lazy migration in
 * wp_native_auth_maybe_upgrade_schema() re-runs dbDelta on existing
 * installs without requiring a plugin reactivation.
 *
 * History:
 *   1 — initial shape (id, user_id, device_id, device_name,
 *       refresh_token_hash, created_at, last_used_at, expires_at,
 *       revoked_at).
 *   2 — refresh-token reuse detection (#55): adds token_family and
 *       prev_token_hash columns.
 */
const WP_NATIVE_AUTH_SCHEMA_VERSION = 2;

/**
 * Network option key storing the installed schema version.
 *
 * Uses a *site* option (get_site_option) so it tracks the network-wide
 * table, mirroring base_prefix table placement on multisite.
 */
const WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION = 'wp_native_auth_schema_version';

/**
 * Returns the fully-qualified refresh tokens table name.
 *
 * Uses base_prefix so the table lives at the network level on multisite,
 * not per-blog. SCHEMAS.md mandates this.
 */
function wp_native_auth_refresh_tokens_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_refresh_tokens';
}

/**
 * Install (or upgrade) the refresh tokens table via dbDelta().
 *
 * Called on plugin activation. Idempotent — dbDelta diffs the existing
 * schema and only applies necessary changes.
 *
 * Schema MUST match SCHEMAS.md exactly. Do not change column order, types,
 * or index names without updating the contract.
 *
 * The migration is purely additive and backward-compatible:
 *   - `token_family` and `prev_token_hash` are added NULL-able, so existing
 *     rows survive untouched and their current refresh tokens keep working.
 *   - dbDelta() only emits ALTER TABLE ADD COLUMN for the missing columns;
 *     it never drops or rewrites existing data.
 * After dbDelta runs, legacy rows missing a token_family are backfilled
 * with a fresh UUID each (see wp_native_auth_backfill_token_family()).
 */
function wp_native_auth_install_refresh_tokens_table(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = wp_native_auth_refresh_tokens_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		device_id char(36) NOT NULL,
		device_name varchar(191) NULL,
		refresh_token_hash char(64) NOT NULL,
		token_family char(36) NULL,
		prev_token_hash char(64) NULL,
		created_at datetime NOT NULL,
		last_used_at datetime NULL,
		expires_at datetime NOT NULL,
		revoked_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_device (user_id, device_id),
		KEY user_id (user_id),
		KEY token_family (token_family),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $sql );

	wp_native_auth_backfill_token_family();

	update_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, WP_NATIVE_AUTH_SCHEMA_VERSION );
}

/**
 * Backfill `token_family` for any legacy rows that predate the column.
 *
 * Each legacy row gets its own fresh UUID v4 family id. A pre-migration
 * row has no `prev_token_hash`, so reuse of its pre-migration token cannot
 * be detected until it rotates once post-migration — acceptable, and it
 * never logs the user out. Idempotent: only touches rows where
 * `token_family IS NULL`.
 */
function wp_native_auth_backfill_token_family(): void {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table_name is a trusted internal constant; one-time migration read.
	$ids = $wpdb->get_col( "SELECT id FROM {$table_name} WHERE token_family IS NULL" );

	if ( empty( $ids ) ) {
		return;
	}

	foreach ( $ids as $id ) {
		$wpdb->update(
			$table_name,
			array( 'token_family' => wp_generate_uuid4() ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}

/**
 * Lazily run the schema migration when the installed version is behind.
 *
 * Hooked on admin_init so existing installs pick up new columns without a
 * plugin reactivation. Cheap no-op once the stored version matches the
 * current WP_NATIVE_AUTH_SCHEMA_VERSION constant.
 */
function wp_native_auth_maybe_upgrade_schema(): void {
	$installed = (int) get_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, 0 );

	if ( $installed >= WP_NATIVE_AUTH_SCHEMA_VERSION ) {
		return;
	}

	wp_native_auth_install_refresh_tokens_table();
}
