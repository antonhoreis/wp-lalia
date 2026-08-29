<?php
/**
 * /my-lalia/ — shown when the portal cannot be opened for this visitor
 * (module misconfigured, account not eligible, minting failed).
 *
 * Variables: string $message (already translated, plain text), string $home_url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$lang = get_bloginfo( 'language' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ? $lang : 'en' ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'My LALIA', 'lalia' ); ?></title>
	<style>
		html, body { margin: 0; min-height: 100%; background: #ffffff; }
		main { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; text-align: center; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #4b5563; }
		h1 { font-size: 20px; color: #111827; margin: 0; }
		p { margin: 0; max-width: 32rem; }
		a { font: inherit; color: #111827; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 14px; text-decoration: none; }
		@media (prefers-color-scheme: dark) {
			html, body { background: #0b0b0c; }
			main { color: #d1d5db; }
			h1 { color: #f3f4f6; }
			a { color: #f3f4f6; background: #1f1f23; border-color: #3a3a40; }
		}
	</style>
</head>
<body>
	<main>
		<h1><?php echo esc_html__( 'My LALIA', 'lalia' ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<p><a href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html__( 'Back to lalia-berlin.com', 'lalia' ); ?></a></p>
	</main>
</body>
</html>
