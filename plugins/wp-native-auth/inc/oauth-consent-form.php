<?php
/**
 * Minimal default consent template.
 *
 * Deliberately unbranded: this generic layer only asks the question and
 * captures the decision. Product UI belongs in a host-supplied template
 * via the wp_native_auth_oauth_consent_template filter, which receives
 * this same $args array.
 *
 * @package WPNativeAuth
 *
 * @var array $args {
 *     @type string $client_name      Display name (or raw client id when unnamed).
 *     @type string $client_uri       Optional client homepage.
 *     @type string $client_id        Raw client identifier.
 *     @type string $scope            Declared scope.
 *     @type string $resource         RFC 8707 resource audience, if requested.
 *     @type string $bundle           Signed request bundle.
 *     @type string $signature        Bundle signature.
 *     @type string $authorize_action Consent nonce.
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
	<title><?php esc_html_e( 'Authorize application', 'wp-native-auth' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; margin: 0; padding: 2em 1em; }
		.card { max-width: 26em; margin: 3em auto; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 2em; }
		h1 { font-size: 1.2em; margin-top: 0; }
		code { word-break: break-all; font-size: 0.85em; }
		.actions { display: flex; gap: .75em; margin-top: 1.5em; }
		.actions input[type="submit"] { padding: .5em 1.25em; cursor: pointer; }
		.approve { background: #2271b1; color: #fff; border: 1px solid #135e96; border-radius: 3px; }
		.deny { background: #f6f7f7; color: #2271b1; border: 1px solid #2271b1; border-radius: 3px; }
	</style>
</head>
<body>
<div class="card">
	<h1><?php esc_html_e( 'Authorize application', 'wp-native-auth' ); ?></h1>
	<p>
		<?php
		printf(
			/* translators: %s: application name. */
			esc_html__( 'The application %s is requesting access to your account.', 'wp-native-auth' ),
			'<strong>' . esc_html( $args['client_name'] ) . '</strong>'
		);
		?>
	</p>
	<p><code><?php echo esc_html( $args['client_id'] ); ?></code></p>
	<?php if ( '' !== $args['resource'] ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: resource URI. */
				esc_html__( 'Access would be scoped to the resource %s.', 'wp-native-auth' ),
				'<code>' . esc_html( $args['resource'] ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Approving grants this application access as your account. You can revoke access at any time.', 'wp-native-auth' ); ?></p>
	<form method="post" action="">
		<?php wp_nonce_field( 'wp_native_auth_oauth_consent' ); ?>
		<input type="hidden" name="oauth_request" value="<?php echo esc_attr( $args['bundle'] ); ?>">
		<input type="hidden" name="oauth_signature" value="<?php echo esc_attr( $args['signature'] ); ?>">
		<div class="actions">
			<button type="submit" class="approve" name="oauth_decision" value="approve"><?php esc_html_e( 'Approve', 'wp-native-auth' ); ?></button>
			<button type="submit" class="deny" name="oauth_decision" value="deny"><?php esc_html_e( 'Deny', 'wp-native-auth' ); ?></button>
		</div>
	</form>
</div>
</body>
</html>
