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
		add_action( 'wp_head', array( $this, 'print_zone_entry_css' ) );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'append_zone_dropdown' ), 10, 2 );
		// Priority 100: several plugins fight over login_redirect (members @9,
		// user_auth's role map @10, login-or-logout-menu-item @11 — the last
		// one forces home_url and wins). The User Zone is the customer's
		// destination, so this module has the final word for customer roles.
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 100, 3 );
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

	/**
	 * Nonce'd WordPress logout URL for use from JavaScript. wp_logout_url()
	 * returns an HTML-escaped string (`&amp;`) meant for attributes; navigating
	 * to it verbatim hands WordPress `amp;_wpnonce` and it shows the "do you
	 * really want to log out?" confirmation instead of logging out.
	 */
	public static function logout_url() {
		return wp_specialchars_decode( wp_logout_url( home_url( '/' ) ), ENT_QUOTES );
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
		$secret = Lalia_Portal_SSO_Token::secret();
		if ( '' === $secret ) {
			$errors[] = __( 'Portal SSO secret is not set.', 'lalia' );
		} elseif ( strlen( $secret ) < Lalia_Portal_SSO_Token::SECRET_MIN_BYTES ) {
			// php-jwt ≥ 6.10 refuses HS256 keys under 256 bits outright.
			$errors[] = __( 'Portal SSO secret must be at least 32 bytes.', 'lalia' );
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
		// Every response here is per-user and carries a fresh token: headers
		// for browsers/CDNs, plus the constants page caches (LiteSpeed on the
		// Hostinger install, WP Rocket, W3TC) honour regardless of headers.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
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
			'logoutUrl'         => self::logout_url(),
			'loginUrl'          => wp_specialchars_decode( wp_login_url( self::page_url() ), ENT_QUOTES ),
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
		// This page embeds the portal; nobody embeds this page. The CSP is sent
		// as an ADDITIONAL header (replace = false): the hosts already emit a
		// site-wide Content-Security-Policy, and browsers enforce every CSP
		// header they receive — replacing would let the later site-wide one win.
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'", false );
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
		$items = array_values( $items );
		if ( $show && $this->zone_entry_targets_menu( $args ) ) {
			// Design A: the dropdown carries "Log out", so the standalone
			// login-or-logout item disappears for zone users. Logged-out
			// visitors (no zone entry) keep their "Log In" item untouched.
			foreach ( $items as $key => $item ) {
				if ( ! empty( $item->url ) && false !== strpos( (string) $item->url, 'action=logout' ) ) {
					unset( $items[ $key ] );
				}
			}
			$items   = array_values( $items );
			$items[] = $this->build_zone_entry( count( $items ) + 1 );
		}
		return $items;
	}

	/**
	 * Should the "User Zone" profile entry be appended to this menu render?
	 * Default: the menu assigned to the `primary` location (the site header).
	 * The Elementor nav widget passes the menu, not a theme_location, so both
	 * are checked. Filter `lalia_portal_zone_entry_menus` (term ids) widens it.
	 */
	private function zone_entry_targets_menu( $args ) {
		if ( ! is_object( $args ) ) {
			return false;
		}
		$locations = get_nav_menu_locations();
		$primary   = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
		$targets   = apply_filters( 'lalia_portal_zone_entry_menus', array_filter( array( $primary ) ) );
		if ( ! empty( $args->theme_location ) && 'primary' === $args->theme_location ) {
			return true;
		}
		$menu = ! empty( $args->menu ) ? wp_get_nav_menu_object( $args->menu ) : false;
		return $menu && in_array( (int) $menu->term_id, array_map( 'intval', (array) $targets ), true );
	}

	/** The customer's first name for the header entry (billing fallback). */
	private function zone_entry_name() {
		$user = wp_get_current_user();
		$name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		if ( '' === $name ) {
			$name = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) );
		}
		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}
		return $name;
	}

	/** Two-letter initials for the avatar (first + last, with fallbacks). */
	private function zone_entry_initials() {
		$user  = wp_get_current_user();
		$first = $this->zone_entry_name();
		$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
		if ( '' === $last ) {
			$last = trim( (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
		}
		$initials = mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 );
		if ( '' === trim( $initials ) ) {
			$initials = mb_substr( trim( (string) $user->display_name ), 0, 2 );
		}
		return mb_strtoupper( $initials );
	}

	/**
	 * Synthetic nav item rendered by the standard walker (Design A): initials
	 * avatar + first name + chevron. The dropdown is appended after the link
	 * by append_zone_dropdown(); without JavaScript the chip stays a plain
	 * link into the zone.
	 */
	private function build_zone_entry( $order ) {
		$name  = $this->zone_entry_name();
		$title = '<span class="lalia-zone-avatar" aria-hidden="true">' . esc_html( $this->zone_entry_initials() ) . '</span>'
			. '<span class="lalia-zone-name">' . esc_html( $name ) . '</span>'
			. '<svg class="lalia-zone-caret" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>';

		return (object) array(
			'ID'                   => -985985,
			'db_id'                => 0,
			'menu_item_parent'     => 0,
			'object_id'            => 0,
			'object'               => 'custom',
			'type'                 => 'custom',
			'type_label'           => 'Custom Link',
			'post_type'            => 'nav_menu_item',
			'post_status'          => 'publish',
			'title'                => $title,
			'post_title'           => $title,
			'url'                  => self::page_url(),
			'target'               => '',
			'attr_title'           => __( 'Open your User Zone', 'lalia' ),
			'description'          => '',
			'classes'              => array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'lalia-zone-menu-item' ),
			'xfn'                  => '',
			'current'              => false,
			'current_item_ancestor' => false,
			'current_item_parent'  => false,
			'menu_order'           => 9000 + (int) $order,
			'post_parent'          => 0,
			'filter'               => 'raw',
		);
	}

	/** The Design-A dropdown, appended after the chip link inside the <li>. */
	public function append_zone_dropdown( $item_output, $item ) {
		if ( ! isset( $item->ID ) || -985985 !== $item->ID ) {
			return $item_output;
		}
		$user  = wp_get_current_user();
		$first = $this->zone_entry_name();
		$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
		if ( '' === $last ) {
			$last = trim( (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
		}
		$full = trim( $first . ' ' . $last );
		ob_start();
		?>
		<div class="lalia-zone-dd" role="menu" aria-label="<?php echo esc_attr__( 'Account', 'lalia' ); ?>">
			<div class="lalia-zone-dd-head">
				<span class="lalia-zone-avatar lalia-zone-avatar-lg" aria-hidden="true"><?php echo esc_html( $this->zone_entry_initials() ); ?></span>
				<div class="lalia-zone-dd-id">
					<span class="lalia-zone-dd-name"><?php echo esc_html( $full ); ?></span>
					<span class="lalia-zone-dd-mail"><?php echo esc_html( $user->user_email ); ?></span>
				</div>
			</div>
			<a class="lalia-zone-dd-item lalia-zone-dd-primary" role="menuitem" href="<?php echo esc_url( self::page_url() ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3 1 9l11 6 9-4.91V17h2V9L12 3zm-7 9.18v4L12 20l7-3.82v-4L12 16l-7-3.82z"/></svg>
				<?php echo esc_html__( 'Open User Zone', 'lalia' ); ?>
			</a>
			<a class="lalia-zone-dd-item" role="menuitem" href="<?php echo esc_url( self::logout_url() ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
				<?php echo esc_html__( 'Log out', 'lalia' ); ?>
			</a>
		</div>
		<?php
		return $item_output . ob_get_clean();
	}

	/** Styling + toggle behaviour for the header entry (Design A). */
	public function print_zone_entry_css() {
		if ( is_admin() || ! $this->current_user_can_open_portal() ) {
			return;
		}
		?>
		<style id="lalia-zone-entry-css">
			.lalia-zone-menu-item { margin-left: auto; position: relative; }
			.lalia-zone-menu-item > a { display: inline-flex !important; align-items: center; gap: 9px; }
			.lalia-zone-avatar { width: 36px; height: 36px; border-radius: 50%; background: #0f60d6; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; flex: none; font-size: 13px; font-weight: 600; letter-spacing: 0.02em; }
			.lalia-zone-avatar-lg { width: 42px; height: 42px; font-size: 15px; }
			.lalia-zone-name { font-weight: 600; }
			.lalia-zone-caret { opacity: 0.55; transition: transform 0.15s ease; }
			.lalia-zone-menu-item.lalia-zone-open .lalia-zone-caret { transform: rotate(180deg); }
			.lalia-zone-dd { position: absolute; right: 0; top: calc(100% + 10px); width: 250px; background: #ffffff; border-radius: 14px; box-shadow: 0 12px 40px rgba(16, 42, 67, 0.16), 0 2px 8px rgba(16, 42, 67, 0.08); padding: 8px; z-index: 99990; display: none; }
			.lalia-zone-menu-item.lalia-zone-open .lalia-zone-dd { display: block; }
			.lalia-zone-dd-head { display: flex; gap: 12px; align-items: center; padding: 10px 12px 12px; border-bottom: 1px solid #eef2f6; margin-bottom: 6px; }
			.lalia-zone-dd-id { display: flex; flex-direction: column; min-width: 0; }
			.lalia-zone-dd-name { font-weight: 600; font-size: 14px; color: #1a2b3c; }
			.lalia-zone-dd-mail { font-size: 12px; color: #7d8fa1; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.lalia-zone-dd-item { display: flex !important; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 9px; font-size: 14px; color: #33475b !important; text-decoration: none; }
			.lalia-zone-dd-item:hover { background: #f2f7ff; }
			.lalia-zone-dd-primary { color: #0f60d6 !important; font-weight: 600; }
		</style>
		<script id="lalia-zone-entry-js">
		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('.lalia-zone-menu-item').forEach(function (li) {
				var chip = li.querySelector(':scope > a');
				if (!chip) { return; }
				chip.setAttribute('aria-haspopup', 'true');
				chip.setAttribute('aria-expanded', 'false');
				chip.addEventListener('click', function (ev) {
					ev.preventDefault();
					var open = li.classList.toggle('lalia-zone-open');
					chip.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
			});
			document.addEventListener('click', function (ev) {
				document.querySelectorAll('.lalia-zone-menu-item.lalia-zone-open').forEach(function (li) {
					if (!li.contains(ev.target)) {
						li.classList.remove('lalia-zone-open');
						var a = li.querySelector(':scope > a');
						if (a) { a.setAttribute('aria-expanded', 'false'); }
					}
				});
			});
			document.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape') {
					document.querySelectorAll('.lalia-zone-menu-item.lalia-zone-open').forEach(function (li) {
						li.classList.remove('lalia-zone-open');
					});
				}
			});
		});
		</script>
		<?php
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

	/**
	 * Roles whose login lands in the User Zone. Deliberately narrower than
	 * the mint gate: administrators and shop managers keep their normal
	 * destination (wp-admin etc.) even though they may open the portal.
	 */
	public static function login_redirect_roles() {
		$roles = apply_filters( 'lalia_portal_login_redirect_roles', array( 'customer', 'subscriber' ) );
		return is_array( $roles ) ? $roles : array();
	}

	/** login_redirect: send customers straight into the User Zone. */
	public function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}
		$roles = is_array( $user->roles ) ? $user->roles : array();
		if ( ! array_intersect( self::login_redirect_roles(), $roles ) ) {
			return $redirect_to;
		}
		if ( array_intersect( array( 'administrator', 'shop_manager' ), $roles ) ) {
			return $redirect_to;
		}
		if ( true !== Lalia_Portal_SSO_Token::validate_user( $user->ID ) ) {
			return $redirect_to;
		}
		return self::page_url();
	}

}
