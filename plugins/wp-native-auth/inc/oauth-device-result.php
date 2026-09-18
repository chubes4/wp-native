<?php
/**
 * Terminal screen for the device grant.
 *
 * The device flow has no redirect back to the client, so this screen is
 * where the browser leg ends. It exists to tell the user the browser is
 * done and the other device is continuing — without it, a successful
 * approval looks identical to a hang.
 *
 * Replaceable via `wp_native_auth_oauth_device_result_template`.
 *
 * @package WPNativeAuth
 *
 * @var array $args {
 *     @type bool $approved Whether the request was approved.
 * }
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>
		<?php
		echo esc_html(
			$args['approved']
				? __( 'Device connected', 'wp-native-auth' )
				: __( 'Request denied', 'wp-native-auth' )
		);
		?>
	</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; margin: 0; padding: 2em 1em; }
		.card { max-width: 26em; margin: 3em auto; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 2em; text-align: center; }
		h1 { font-size: 1.2em; margin-top: 0; }
	</style>
</head>
<body>
<div class="card">
	<?php if ( $args['approved'] ) : ?>
		<h1><?php esc_html_e( 'Device connected', 'wp-native-auth' ); ?></h1>
		<p><?php esc_html_e( 'The application has been authorized. You can close this window and return to it.', 'wp-native-auth' ); ?></p>
	<?php else : ?>
		<h1><?php esc_html_e( 'Request denied', 'wp-native-auth' ); ?></h1>
		<p><?php esc_html_e( 'No access was granted. You can close this window.', 'wp-native-auth' ); ?></p>
	<?php endif; ?>
</div>
</body>
</html>
