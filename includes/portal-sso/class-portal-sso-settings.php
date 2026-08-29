<?php
/**
 * Portal SSO — settings + logs pages (WP Admin → Lalia → Portal SSO).
 *
 * Registered even while the module is disabled so the secret and portal URL
 * can be configured before switching it on. The secret field is write-only:
 * it is never echoed back, and an empty submission leaves it unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Portal_SSO_Settings {

	const GROUP     = 'lalia_portal_sso';
	const PAGE_SLUG = 'lalia-portal-sso';
	const LOGS_SLUG = 'lalia-portal-sso-logs';

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::GROUP,
			Lalia_Portal_SSO::OPTION_SECRET,
			array( 'sanitize_callback' => array( $this, 'sanitize_secret' ) )
		);
		register_setting(
			self::GROUP,
			Lalia_Portal_SSO::OPTION_PORTAL_URL,
			array( 'sanitize_callback' => array( $this, 'sanitize_portal_url' ) )
		);
		register_setting(
			self::GROUP,
			Lalia_Portal_SSO::OPTION_PAGE_SLUG,
			array( 'sanitize_callback' => array( $this, 'sanitize_page_slug' ) )
		);
		register_setting(
			self::GROUP,
			Lalia_Portal_SSO::OPTION_LOGGING,
			array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) )
		);

		add_settings_section( 'lalia_portal_sso_main', __( 'Portal SSO', 'lalia' ), array( $this, 'render_section' ), self::PAGE_SLUG );
		add_settings_field( Lalia_Portal_SSO::OPTION_SECRET, __( 'Shared secret (PORTAL_SSO_SECRET)', 'lalia' ), array( $this, 'render_secret_field' ), self::PAGE_SLUG, 'lalia_portal_sso_main' );
		add_settings_field( Lalia_Portal_SSO::OPTION_PORTAL_URL, __( 'Portal URL', 'lalia' ), array( $this, 'render_portal_url_field' ), self::PAGE_SLUG, 'lalia_portal_sso_main' );
		add_settings_field( Lalia_Portal_SSO::OPTION_PAGE_SLUG, __( 'Page slug', 'lalia' ), array( $this, 'render_page_slug_field' ), self::PAGE_SLUG, 'lalia_portal_sso_main' );
		add_settings_field( Lalia_Portal_SSO::OPTION_LOGGING, __( 'Logging', 'lalia' ), array( $this, 'render_logging_field' ), self::PAGE_SLUG, 'lalia_portal_sso_main' );
	}

	// ── sanitizers ───────────────────────────────────────────────────────────

	/**
	 * trim only — sanitize_text_field strips characters a secret may legitimately
	 * contain. Empty keeps the stored value (write-only field).
	 */
	public function sanitize_secret( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return Lalia_Portal_SSO_Token::secret();
		}
		return $value;
	}

	public function sanitize_portal_url( $value ) {
		$value = esc_url_raw( trim( (string) $value ), array( 'https' ) );
		if ( '' === $value ) {
			add_settings_error( Lalia_Portal_SSO::OPTION_PORTAL_URL, 'invalid_url', __( 'Portal URL must be an absolute https:// URL; kept the previous value.', 'lalia' ) );
			return get_option( Lalia_Portal_SSO::OPTION_PORTAL_URL, Lalia_Portal_SSO::DEFAULT_PORTAL_URL );
		}
		return trailingslashit( $value );
	}

	public function sanitize_page_slug( $value ) {
		$slug = sanitize_title( (string) $value );
		if ( '' === $slug ) {
			$slug = Lalia_Portal_SSO::DEFAULT_PAGE_SLUG;
		}
		if ( $slug !== Lalia_Portal_SSO::page_slug() ) {
			// Re-flush on the next init with the new rule registered.
			Lalia_Portal_SSO::invalidate_rewrite();
		}
		return $slug;
	}

	public function sanitize_checkbox( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	// ── settings page ────────────────────────────────────────────────────────

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		$enabled = Lalia_Portal_SSO::is_enabled();
		$config  = Lalia_Portal_SSO::validate_configuration();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portal SSO', 'lalia' ); ?></h1>
			<?php settings_errors( self::GROUP ); ?>
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'The Portal SSO module is disabled. Configure it here, then enable it under Lalia → Overview.', 'lalia' ); ?>
				</p></div>
			<?php endif; ?>
			<?php if ( is_wp_error( $config ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $config->get_error_message() ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-success"><p>
					<?php
					printf(
						/* translators: 1: page URL, 2: portal URL */
						esc_html__( 'Configuration looks good. Embed page: %1$s → portal %2$s', 'lalia' ),
						'<a href="' . esc_url( Lalia_Portal_SSO::page_url() ) . '" target="_blank" rel="noopener">' . esc_html( Lalia_Portal_SSO::page_url() ) . '</a>',
						'<code>' . esc_html( Lalia_Portal_SSO::portal_url() ) . '</code>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'How it fits together', 'lalia' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'A logged-in customer opens the page slug above (add a "My LALIA" menu item pointing at it — the item is hidden automatically for visitors who cannot open the portal).', 'lalia' ); ?></li>
				<li><?php esc_html_e( 'The page mints a 120-second, single-use token signed with the shared secret and embeds the portal with the token in the URL fragment.', 'lalia' ); ?></li>
				<li><?php esc_html_e( 'The ERP verifies the token, resolves the customer by e-mail, and issues its own session. Logging out on either side ends both.', 'lalia' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Secret rotation: set the new value in the ERP as PORTAL_SSO_SECRET (moving the old one to PORTAL_SSO_SECRET_PREVIOUS), deploy, then paste the new value here. The ERP accepts both for the grace window; clear the previous value afterwards.', 'lalia' ); ?></p>
		</div>
		<?php
	}

	public function render_section() {
		echo '<p>' . esc_html__( 'The ERP side of this handshake reads PORTAL_SSO_SECRET from its own secrets; the two values must match exactly. Never reuse the Zenler SSO API key or the checkout-prefill secret.', 'lalia' ) . '</p>';
	}

	public function render_secret_field() {
		$is_set = '' !== Lalia_Portal_SSO_Token::secret();
		?>
		<input type="password" id="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_SECRET ); ?>" name="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_SECRET ); ?>" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $is_set ? esc_attr__( '•••••••• (unchanged if left empty)', 'lalia' ) : esc_attr__( 'Enter secret', 'lalia' ); ?>" />
		<p class="description">
			<?php echo $is_set ? esc_html__( 'A secret is set. Enter a new value to replace it.', 'lalia' ) : esc_html__( 'No secret set — the module is inert until one is configured.', 'lalia' ); ?>
		</p>
		<?php
	}

	public function render_portal_url_field() {
		$value = get_option( Lalia_Portal_SSO::OPTION_PORTAL_URL, Lalia_Portal_SSO::DEFAULT_PORTAL_URL );
		?>
		<input type="url" id="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_PORTAL_URL ); ?>" name="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_PORTAL_URL ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Lalia_Portal_SSO::DEFAULT_PORTAL_URL ); ?>" />
		<p class="description"><?php esc_html_e( 'Production: https://erp.lalia-berlin.com/portal/ — stage: https://erp.lalia-berlin.com/stage/portal/ (the stage WordPress must point at the stage portal; the secrets differ).', 'lalia' ); ?></p>
		<?php
	}

	public function render_page_slug_field() {
		$value = Lalia_Portal_SSO::page_slug();
		?>
		<input type="text" id="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_PAGE_SLUG ); ?>" name="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_PAGE_SLUG ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description">
			<?php
			printf(
				/* translators: %s: page URL */
				esc_html__( 'Served by the plugin at %s (no WordPress page needed; an existing page with the same slug is shadowed).', 'lalia' ),
				'<code>' . esc_html( Lalia_Portal_SSO::page_url() ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	public function render_logging_field() {
		$value = get_option( Lalia_Portal_SSO::OPTION_LOGGING, 'yes' );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Lalia_Portal_SSO::OPTION_LOGGING ); ?>" value="yes" <?php checked( $value, 'yes' ); ?> />
			<?php esc_html_e( 'Log token mints, denials and errors (kept 30 days).', 'lalia' ); ?>
		</label>
		<?php
	}

	// ── logs page ────────────────────────────────────────────────────────────

	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		$page   = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$logger = Lalia_Portal_SSO_Logger::get_instance();
		$logs   = $logger->get_logs( array( 'page' => $page, 'per_page' => 25 ) );
		$stats  = $logger->get_statistics();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portal SSO Logs', 'lalia' ); ?></h1>
			<p>
				<?php esc_html_e( 'Last 7 days —', 'lalia' ); ?>
				<?php esc_html_e( 'Tokens minted:', 'lalia' ); ?> <strong><?php echo (int) $stats['mint']; ?></strong>
				&nbsp;|&nbsp; <?php esc_html_e( 'Denied:', 'lalia' ); ?> <strong><?php echo (int) $stats['denied']; ?></strong>
				&nbsp;|&nbsp; <?php esc_html_e( 'Errors:', 'lalia' ); ?> <strong><?php echo (int) $stats['error']; ?></strong>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Date/Time', 'lalia' ); ?></th>
					<th><?php esc_html_e( 'User', 'lalia' ); ?></th>
					<th><?php esc_html_e( 'Email', 'lalia' ); ?></th>
					<th><?php esc_html_e( 'Event', 'lalia' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lalia' ); ?></th>
					<th><?php esc_html_e( 'Message', 'lalia' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! empty( $logs['items'] ) ) : ?>
					<?php foreach ( $logs['items'] as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><?php echo esc_html( $log->user_id ); ?></td>
							<td><?php echo esc_html( $log->user_email ); ?></td>
							<td><?php echo esc_html( $log->event ); ?></td>
							<td><?php echo esc_html( $log->status ); ?></td>
							<td><?php echo esc_html( $log->message ? $log->message : '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No log entries yet.', 'lalia' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $logs['pages'] > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $logs['pages'],
							'current'   => $page,
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
