<?php
/**
 * /my-lalia/ — full-viewport embed of the LALIA User Zone portal.
 *
 * Variables (set by Lalia_Portal_SSO::render_embed):
 *   string $portal_src  portal URL with the handoff token in the FRAGMENT
 *   array  $config      bridge configuration (origins, URLs, heartbeat)
 *   string $bridge_js   contents of assets/js/portal-embed.js
 *
 * Deliberately not a theme template: no header, footer, menus or theme
 * scripts — the portal supplies its own navigation (approved design, canvas
 * turn "Logged-in navigation").
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
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="strict-origin-when-cross-origin">
	<title><?php echo esc_html__( 'My LALIA', 'lalia' ); ?></title>
	<style>
		html, body { margin: 0; padding: 0; height: 100%; background: #ffffff; overflow: hidden; }
		#lalia-portal-frame { position: fixed; inset: 0; width: 100%; height: 100%; border: 0; display: block; background: transparent; }
		#lalia-portal-status { position: fixed; inset: 0; z-index: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; text-align: center; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #4b5563; background: #ffffff; }
		#lalia-portal-status[hidden] { display: none; }
		#lalia-portal-status .lalia-portal-spinner { width: 28px; height: 28px; border: 3px solid #e5e7eb; border-top-color: #111827; border-radius: 50%; animation: lalia-portal-spin 0.8s linear infinite; }
		#lalia-portal-status .lalia-portal-actions { display: none; gap: 8px; }
		#lalia-portal-status.is-error .lalia-portal-spinner { display: none; }
		#lalia-portal-status.is-error .lalia-portal-actions { display: flex; }
		#lalia-portal-status a, #lalia-portal-status button { font: inherit; color: #111827; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 14px; text-decoration: none; cursor: pointer; }
		@keyframes lalia-portal-spin { to { transform: rotate(360deg); } }
		@media (prefers-color-scheme: dark) {
			html, body, #lalia-portal-status { background: #0b0b0c; color: #d1d5db; }
			#lalia-portal-status .lalia-portal-spinner { border-color: #2a2a2e; border-top-color: #f3f4f6; }
			#lalia-portal-status a, #lalia-portal-status button { color: #f3f4f6; background: #1f1f23; border-color: #3a3a40; }
		}
	</style>
</head>
<body>
	<div id="lalia-portal-status" role="status" aria-live="polite">
		<div class="lalia-portal-spinner" aria-hidden="true"></div>
		<p class="lalia-portal-message"><?php echo esc_html__( 'Opening your portal…', 'lalia' ); ?></p>
		<div class="lalia-portal-actions">
			<button type="button" class="lalia-portal-reload"><?php echo esc_html__( 'Reload', 'lalia' ); ?></button>
			<a href="<?php echo esc_url( $config['homeUrl'] ); ?>"><?php echo esc_html__( 'Back to lalia-berlin.com', 'lalia' ); ?></a>
		</div>
	</div>
	<iframe id="lalia-portal-frame"
		src="<?php echo esc_url( $portal_src ); ?>"
		title="<?php echo esc_attr__( 'My LALIA', 'lalia' ); ?>"
		referrerpolicy="strict-origin"
		allow="clipboard-write"></iframe>
	<script>window.LALIA_PORTAL_EMBED = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
	<script><?php echo $bridge_js; // phpcs:ignore WordPress.Security.EscapeOutput -- static plugin asset, not user data. ?></script>
</body>
</html>
