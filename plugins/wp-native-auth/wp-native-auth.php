<?php
/**
 * Plugin Name:       wp-native Auth
 * Plugin URI:        https://github.com/chubes4/wp-native
 * Description:       Token-based authentication for WordPress, built for native app consumers. Provides login, refresh, logout, and session abilities via the WP 6.9+ Abilities API.
 * Version:           0.1.2
 * Author:            Chris Huber
 * Author URI:        https://chubes.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Text Domain:       wp-native-auth
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 *
 * Schema-bearing constants (TTLs, rate limits) MUST match SCHEMAS.md exactly.
 * The wp-native-client side relies on these values being stable.
 */
define( 'WP_NATIVE_AUTH_PLUGIN_FILE', __FILE__ );
define( 'WP_NATIVE_AUTH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_NATIVE_AUTH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_NATIVE_AUTH_VERSION', '0.1.2' );

// Access token lifetime: 15 minutes.
define( 'WP_NATIVE_AUTH_ACCESS_TOKEN_TTL', 15 * MINUTE_IN_SECONDS );

// Refresh token lifetime: 30 days, sliding (extended on each refresh).
define( 'WP_NATIVE_AUTH_REFRESH_TOKEN_TTL', 30 * DAY_IN_SECONDS );

// Per-device refresh rate limit (seconds between successful refreshes).
define( 'WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS', 5 );

// DB layer (refresh tokens table installer).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/db.php';

// Token primitives (hashing, access-token generation/validation, helpers).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/tokens.php';

// External-service token signing (HMAC-SHA256 signed tokens for delegating
// scoped access to external services that share an HMAC secret).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/external-tokens.php';

// Token service (login, refresh, revoke, sessions, user payload).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/service.php';

// Bearer token request filter (resolves Authorization header → current user).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/bearer-auth.php';

// Browser handoff token primitives (mint + validate).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/handoff-tokens.php';

// Browser handoff receiver (init-hooked handler for ?wp-native-handoff=<token>).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/handoff-receiver.php';

// Ability registrations (the public surface for wp-native-client).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/abilities.php';

/**
 * Activation: install the network-wide refresh tokens table.
 */
register_activation_hook( __FILE__, 'wp_native_auth_install_refresh_tokens_table' );

/**
 * Lazy schema upgrade: pick up additive column migrations on existing
 * installs without requiring a plugin reactivation. Backward-compatible —
 * never logs active users out.
 */
add_action( 'admin_init', 'wp_native_auth_maybe_upgrade_schema' );
