<?php
/**
 * Minimal default user-code entry template (RFC 8628 §3.3).
 *
 * Deliberately unbranded, matching the consent template: this generic
 * layer only asks for the code. Product UI belongs in a host-supplied
 * template via the `wp_native_auth_oauth_device_form_template` filter,
 * which receives this same $args array.
 *
 * @package WPNativeAuth
 *
 * @var array $args {
 *     @type string $user_code    Previously submitted code, if any.
 *     @type string $error        Validation message to show, if any.
 *     @type string $nonce_action Nonce action for the submission.
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
	<title><?php esc_html_e( 'Connect a device', 'wp-native-auth' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; margin: 0; padding: 2em 1em; }
		.card { max-width: 26em; margin: 3em auto; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 2em; }
		h1 { font-size: 1.2em; margin-top: 0; }
		.code-input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.5em; letter-spacing: .15em; text-align: center; text-transform: uppercase; width: 100%; box-sizing: border-box; padding: .5em; border: 1px solid #8c8f94; border-radius: 3px; }
		.error { background: #fcf0f1; border-left: 4px solid #d63638; padding: .75em 1em; margin-bottom: 1em; }
		.actions { margin-top: 1.5em; }
		.actions button { padding: .5em 1.25em; cursor: pointer; background: #2271b1; color: #fff; border: 1px solid #135e96; border-radius: 3px; }
	</style>
</head>
<body>
<div class="card">
	<h1><?php esc_html_e( 'Connect a device', 'wp-native-auth' ); ?></h1>
	<?php if ( '' !== (string) $args['error'] ) : ?>
		<p class="error"><?php echo esc_html( (string) $args['error'] ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Enter the code shown by the application you are connecting.', 'wp-native-auth' ); ?></p>
	<form method="post" action="">
		<?php wp_nonce_field( (string) $args['nonce_action'] ); ?>
		<label for="wp-native-auth-user-code" class="screen-reader-text"><?php esc_html_e( 'Device code', 'wp-native-auth' ); ?></label>
		<input
			type="text"
			id="wp-native-auth-user-code"
			class="code-input"
			name="user_code"
			value="<?php echo esc_attr( (string) $args['user_code'] ); ?>"
			autocomplete="off"
			autocapitalize="characters"
			autocorrect="off"
			spellcheck="false"
			required
		>
		<div class="actions">
			<button type="submit"><?php esc_html_e( 'Continue', 'wp-native-auth' ); ?></button>
		</div>
	</form>
</div>
</body>
</html>
