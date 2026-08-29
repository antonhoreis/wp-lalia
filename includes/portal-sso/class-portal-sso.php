<?php
/**
 * Lalia module: Portal SSO — the WordPress half of the LALIA User Zone handoff.
 *
 * Serves the `/my-lalia/` page: a standalone, full-viewport document (no theme
 * header/footer — the portal brings its own rail) that embeds the ERP portal
 * in an iframe with a freshly minted handoff token in the URL FRAGMENT
 * (`…/portal/#sso=<token>`; never a query string — fragments are not sent to
 * any server and never appear in logs or Referer), and runs the postMessage
 * bridge with the frame: logout in either surface ends both, and an expired
 * portal session asks this page to reload (which re-mints if the WordPress
 * session is still alive).
 *
 * Spec: lalia-erp docs/superpowers/specs/2026-08-28-user-zone-portal-design.md
 * (§3.1.2 transport, §3.4 logout/re-entry). Ticket L20-985.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Portal_SSO {

	const OPTION_ENABLED         = 'lalia_enable_portal_sso';
	const OPTION_SECRET          = 'lalia_portal_sso_secret';
	const OPTION_PORTAL_URL      = 'lalia_portal_url';
	const OPTION_PAGE_SLUG       = 'lalia_portal_page_slug';
	const OPTION_LOGGING         = 'lalia_portal_sso_enable_logging';
	const OPTION_REWRITE_VERSION = 'lalia_portal_sso_rewrite';

	const DEFAULT_PORTAL_URL = 'https://erp.lalia-berlin.com/portal/';
	const DEFAULT_PAGE_SLUG  = 'my-lalia';

	const QUERY_VAR      = 'lalia_portal';
	const AJAX_HEARTBEAT = 'lalia_portal_heartbeat';
	/** Parent → frame heartbeat cadence (ms); also fires on tab focus. */
	const HEARTBEAT_INTERVAL_MS = 60000;
	/** Bump when the rewrite rule changes shape so upgraded installs re-flush. */
	const REWRITE_VERSION = '1';

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_page' ), 1 );
		add_action( 'wp_ajax_' . self::AJAX_HEARTBEAT, array( $this, 'ajax_heartbeat' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_HEARTBEAT, array( $this, 'ajax_heartbeat' ) );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_menu_items' ), 10, 3 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_objects' ), 10, 2 );
		Lalia_Portal_SSO_Logger::get_instance();
	}

	// ── configuration ────────────────────────────────────────────────────────

	public static function is_enabled() {
		return get_option( self::OPTION_ENABLED, 'no' ) === 'yes';
	}

	public static function page_slug() {
		$slug = sanitize_title( (string) get_option( self::OPTION_PAGE_SLUG, self::DEFAULT_PAGE_SLUG ) );
		return '' === $slug ? self::DEFAULT_PAGE_SLUG : $slug;
	}

	/** Absolute URL of the embed page, e.g. https://lalia-berlin.com/my-lalia/ */
	public static function page_url() {
		return home_url( '/' . self::page_slug() . '/' );
	}

	/** Portal base URL (trailing slash), e.g. https://erp.lalia-berlin.com/portal/ */
	public static function portal_url() {
		$url = trim( (string) get_option( self::OPTION_PORTAL_URL, self::DEFAULT_PORTAL_URL ) );
		if ( '' === $url ) {
			$url = self::DEFAULT_PORTAL_URL;
		}
		return trailingslashit( $url );
	}

	/** scheme://host[:port] of the portal — the postMessage target/origin. */
	public static function portal_origin() {
		$parts = wp_parse_url( self::portal_url() );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Configuration problems that keep the module from working, as a WP_Error
	 * (one message per problem), or true.
	 */
	public static function validate_configuration() {
		$errors = array();
		if ( '' === Lalia_Portal_SSO_Token::secret() ) {
			$errors[] = __( 'Portal SSO secret is not set.', 'lalia' );
		}
		// Shape check only (no wp_http_validate_url: that resolves DNS on every
		// call, and this runs on each page render).
		$parts = wp_parse_url( self::portal_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			$errors[] = __( 'Portal URL must be an absolute https:// URL.', 'lalia' );
		}
		if ( ! class_exists( '\Firebase\JWT\JWT' ) ) {
			$errors[] = __( 'JWT library is not loaded.', 'lalia' );
		}
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'configuration_error', implode( ' ', $errors ) );
		}
		return true;
	}

	// ── routing ──────────────────────────────────────────────────────────────

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * `/<slug>/` → our renderer. Flushes the rewrite cache once per
	 * (rule version, slug) pair instead of on every request.
	 */
	public function register_rewrite() {
		$slug = self::page_slug();
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		$stamp = self::REWRITE_VERSION . ':' . $slug;
		if ( get_option( self::OPTION_REWRITE_VERSION, '' ) !== $stamp ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE_VERSION, $stamp, false );
		}
	}

	/** Forget the flush stamp so the next `init` re-flushes (toggle / slug change). */
	public static function invalidate_rewrite() {
		delete_option( self::OPTION_REWRITE_VERSION );
	}

	public function maybe_render_page() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

		if ( ! is_user_logged_in() ) {
			// Back to /my-lalia/ after login; wp_login_url() honours the
			// `login_url` filter, so a custom (user_auth) login page is used.
			wp_safe_redirect( wp_login_url( self::page_url() ) );
			exit;
		}

		$user_id = get_current_user_id();
		$logger  = Lalia_Portal_SSO_Logger::get_instance();

		$config = self::validate_configuration();
		if ( is_wp_error( $config ) ) {
			$logger->log( $user_id, Lalia_Portal_SSO_Logger::EVENT_ERROR, 'failed', $config->get_error_message() );
			$this->render_error( 503, __( 'My LALIA is not available right now. Please try again later.', 'lalia' ) );
		}

		$eligible = Lalia_Portal_SSO_Token::validate_user( $user_id );
		if ( is_wp_error( $eligible ) ) {
			$logger->log( $user_id, Lalia_Portal_SSO_Logger::EVENT_DENIED, 'failed', $eligible->get_error_code() . ': ' . $eligible->get_error_message() );
			$this->render_error( 403, $eligible->get_error_message() );
		}

		$token = Lalia_Portal_SSO_Token::mint( $user_id );
		if ( is_wp_error( $token ) ) {
			$logger->log( $user_id, Lalia_Portal_SSO_Logger::EVENT_ERROR, 'failed', $token->get_error_code() . ': ' . $token->get_error_message() );
			$this->render_error( 500, __( 'We could not open your portal. Please try again in a moment.', 'lalia' ) );
		}

		$logger->log( $user_id, Lalia_Portal_SSO_Logger::EVENT_MINT, 'success' );
		$this->render_embed( $user_id, $token );
	}

	/**
	 * The `/my-lalia/` document. The token goes into the iframe's fragment
	 * only; the page itself is uncacheable and unframeable.
	 */
	private function render_embed( $user_id, $token ) {
		$this->send_page_headers( 200 );

		$portal_src = self::portal_url() . '#sso=' . rawurlencode( $token );
		$config     = array(
			'portalOrigin'      => self::portal_origin(),
			'logoutUrl'         => wp_logout_url( home_url( '/' ) ),
			'loginUrl'          => wp_login_url( self::page_url() ),
			'heartbeatUrl'      => add_query_arg( 'action', self::AJAX_HEARTBEAT, admin_url( 'admin-ajax.php' ) ),
			'heartbeatInterval' => self::HEARTBEAT_INTERVAL_MS,
			'userId'            => (int) $user_id,
			'homeUrl'           => home_url( '/' ),
		);
		$bridge_js  = (string) file_get_contents( LALIA_PLUGIN_DIR . 'assets/js/portal-embed.js' );

		include LALIA_PLUGIN_DIR . 'includes/portal-sso/templates/embed-page.php';
		exit;
	}

	private function render_error( $status, $message ) {
		$this->send_page_headers( (int) $status );
		$home_url = home_url( '/' );
		include LALIA_PLUGIN_DIR . 'includes/portal-sso/templates/error-page.php';
		exit;
	}

	private function send_page_headers( $status ) {
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		// This page embeds the portal; nobody embeds this page.
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	/**
	 * Polled by the embed page (60 s + on tab focus). Lets a WordPress logout
	 * that happened elsewhere (another tab, admin bar) reach an open portal:
	 * the page sees `logged_in: false`, tells the frame to revoke its ERP
	 * session, and goes to the login screen. admin-ajax authenticates by
	 * cookie without a nonce and always sends no-cache headers.
	 */
	public function ajax_heartbeat() {
		wp_send_json(
			array(
				'logged_in' => is_user_logged_in(),
				'user'      => get_current_user_id(),
			),
			200
		);
	}

	// ── navigation ───────────────────────────────────────────────────────────

	/**
	 * A menu item that links to the embed page is only shown to visitors who
	 * could actually open it. Items are matched by URL, so the "My LALIA" entry
	 * is added in the normal menu editor (or Elementor's nav widget, which
	 * reads the same menus) — nothing is auto-inserted.
	 */
	public function filter_menu_items( $items, $menu = null, $args = null ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		// Never edit what the menu editor round-trips to the database.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $items;
		}
		if ( $this->current_user_can_open_portal() ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( $this->is_portal_menu_item( $item ) ) {
				unset( $items[ $key ] );
			}
		}
		return array_values( $items );
	}

	public function filter_menu_objects( $items, $args = null ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$show = $this->current_user_can_open_portal();
		foreach ( $items as $key => $item ) {
			if ( ! $this->is_portal_menu_item( $item ) ) {
				continue;
			}
			if ( ! $show ) {
				unset( $items[ $key ] );
			} else {
				$item->classes[] = 'lalia-portal-menu-item';
			}
		}
		return array_values( $items );
	}

	private function is_portal_menu_item( $item ) {
		if ( empty( $item->url ) ) {
			return false;
		}
		$target = untrailingslashit( (string) wp_parse_url( self::page_url(), PHP_URL_PATH ) );
		$path   = untrailingslashit( (string) wp_parse_url( (string) $item->url, PHP_URL_PATH ) );
		$host   = wp_parse_url( (string) $item->url, PHP_URL_HOST );
		$home   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $host && $home && strcasecmp( $host, $home ) !== 0 ) {
			return false;
		}
		return '' !== $target && $path === $target;
	}

	private function current_user_can_open_portal() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return true === Lalia_Portal_SSO_Token::validate_user( get_current_user_id() );
	}
}
