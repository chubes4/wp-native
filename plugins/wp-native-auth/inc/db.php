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
		created_at datetime NOT NULL,
		last_used_at datetime NULL,
		expires_at datetime NOT NULL,
		revoked_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_device (user_id, device_id),
		KEY user_id (user_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $sql );
}
