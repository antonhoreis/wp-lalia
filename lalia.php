<?php
/**
 * Plugin Name: Lalia
 * Description: Consolidated plugin integrating Single-Item Cart behavior, WP SSO, and the Stripe→LALIA package_id injector under one roof.
 * Version: 1.0.7
 * Author: Anton Horeis
 * Text Domain: lalia
 * Update URI: https://europe-west3-horeis.cloudfunctions.net/wp_update/lalia
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Lalia constants.
if ( ! defined( 'LALIA_VERSION' ) ) {
	define( 'LALIA_VERSION', '1.0.7' );
}
if ( ! defined( 'LALIA_PLUGIN_FILE' ) ) {
	define( 'LALIA_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'LALIA_PLUGIN_DIR' ) ) {
	define( 'LALIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'LALIA_PLUGIN_URL' ) ) {
	define( 'LALIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Point SSO constants to the embedded SSO folder so its assets enqueue correctly.
if ( ! defined( 'WP_SSO_PLUGIN_VERSION' ) ) {
	define( 'WP_SSO_PLUGIN_VERSION', '1.0.0' );
}
if ( ! defined( 'WP_SSO_PLUGIN_URL' ) ) {
	define( 'WP_SSO_PLUGIN_URL', LALIA_PLUGIN_URL . 'includes/wp-sso/' );
}
if ( ! defined( 'WP_SSO_PLUGIN_BASENAME' ) ) {
	define( 'WP_SSO_PLUGIN_BASENAME', 'lalia/wp-sso' );
}

// Absolute paths to integrated modules (within this plugin).
$lalia_sso_dir = LALIA_PLUGIN_DIR . 'includes/wp-sso/';
$lalia_sic_file = LALIA_PLUGIN_DIR . 'includes/wc-single-item-cart.php';

// Load embedded Firebase JWT classes (standalone, no Composer autoloader).
// Only load once per request.
if ( ! class_exists( '\\Firebase\\JWT\\JWT' ) && ! class_exists( '\Firebase\JWT\JWT' ) ) {
	$jwt_src = LALIA_PLUGIN_DIR . 'vendor/firebase/php-jwt/src/';
	$jwt_files = array(
		// Load interface first to avoid class implements errors in exceptions
		'JWTExceptionWithPayloadInterface.php',
		// Exceptions that implement the interface
		'BeforeValidException.php',
		'ExpiredException.php',
		'SignatureInvalidException.php',
		// Core classes
		'Key.php',
		'JWT.php',
		'JWK.php',
		// Optional helper (safe to include; only used if referenced)
		'CachedKeySet.php',
	);
	foreach ( $jwt_files as $file_name ) {
		$file = $jwt_src . $file_name;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Include embedded SSO classes.
foreach ( array(
	'includes/class-activator.php',
	'includes/class-logger.php',
	'includes/class-jwt-generator.php',
	'includes/class-sso-handler.php',
	'includes/class-settings.php',
	'includes/class-menu-manager.php',
) as $rel_path ) {
	$file = $lalia_sso_dir . $rel_path;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

// Single-Item Cart will be conditionally included during bootstrap based on settings.

class Lalia_Plugin {

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_lalia_toggle_module', array( $this, 'handle_toggle_module' ) );
		register_activation_hook( LALIA_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( LALIA_PLUGIN_FILE, array( $this, 'on_deactivate' ) );
	}

	public function bootstrap() {
		$lalia_enable_sso = get_option( 'lalia_enable_sso', 'yes' ) === 'yes';
		$lalia_enable_cart = get_option( 'lalia_enable_single_item_cart', 'yes' ) === 'yes';
		$lalia_enable_prefill = get_option( 'lalia_enable_checkout_prefill', 'yes' ) === 'yes';

		// Start SSO components if enabled.
		if ( $lalia_enable_sso && class_exists( 'WP_SSO_Handler' ) ) {
			WP_SSO_Handler::get_instance();
		}
		if ( $lalia_enable_sso && class_exists( 'WP_SSO_Logger' ) ) {
			WP_SSO_Logger::get_instance();
		}
		if ( $lalia_enable_sso && class_exists( 'WP_SSO_Menu_Manager' ) ) {
			WP_SSO_Menu_Manager::get_instance();
		}
		// Prepare settings, but prevent it from creating its own top-level menu.
		if ( class_exists( 'WP_SSO_Settings' ) ) {
			$settings = WP_SSO_Settings::get_instance();
			remove_action( 'admin_menu', array( $settings, 'add_admin_menu' ), 10 );
		}
		// Ensure Single-Item Cart behavior is loaded if enabled.
		if ( $lalia_enable_cart ) {
			$sic_file = LALIA_PLUGIN_DIR . 'includes/wc-single-item-cart.php';
			if ( file_exists( $sic_file ) && ! class_exists( 'WCSingleItemCart' ) ) {
				require_once $sic_file;
			}
		}
		// Load Checkout Prefill module if enabled.
		if ( $lalia_enable_prefill ) {
			$prefill_file = LALIA_PLUGIN_DIR . 'includes/checkout-prefill.php';
			if ( file_exists( $prefill_file ) && ! class_exists( 'Lalia_Checkout_Prefill' ) ) {
				require_once $prefill_file;
				Lalia_Checkout_Prefill::init();
			}
		}
		// Stripe → LALIA package_id injector (WC product meta → PI metadata).
		$lalia_enable_pkg_id = get_option( 'lalia_enable_stripe_package_id', 'yes' ) === 'yes';
		if ( $lalia_enable_pkg_id ) {
			$pkg_file = LALIA_PLUGIN_DIR . 'includes/wc-stripe-package-id.php';
			if ( file_exists( $pkg_file ) && ! class_exists( 'LaliaStripePackageId' ) ) {
				require_once $pkg_file;
			}
			if ( class_exists( 'LaliaStripePackageId' ) ) {
				new LaliaStripePackageId();
			}
		}
	}

	public function on_activate() {
		// Run SSO activator to create tables and defaults.
		if ( class_exists( 'WP_SSO_Activator' ) ) {
			WP_SSO_Activator::activate();
		}
		// Default module toggles if not present
		add_option( 'lalia_enable_sso', 'yes' );
		add_option( 'lalia_enable_single_item_cart', 'yes' );
		add_option( 'lalia_enable_checkout_prefill', 'yes' );
		add_option( 'lalia_prefill_secret', '' );
		add_option( 'lalia_enable_stripe_package_id', 'yes' );
		// Deactivate old standalone plugins if active.
		if ( function_exists( 'deactivate_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugins_to_deactivate = array(
				'wp-sso-plugin/wp-sso-plugin.php',
				'wc-single-item-cart/wc-single-item-cart.php',
			);
			foreach ( $plugins_to_deactivate as $plugin_basename ) {
				if ( is_plugin_active( $plugin_basename ) ) {
					deactivate_plugins( $plugin_basename );
				}
			}
		}
		flush_rewrite_rules();
	}

	public function on_deactivate() {
		flush_rewrite_rules();
	}

	public function register_admin_menu() {
		add_menu_page(
			'Lalia',
			'Lalia',
			'manage_options',
			'lalia',
			array( $this, 'render_overview_page' ),
			'dashicons-admin-generic',
			80
		);

		// Overview as first submenu for consistency.
		add_submenu_page(
			'lalia',
			'Overview',
			'Overview',
			'manage_options',
			'lalia',
			array( $this, 'render_overview_page' )
		);

		// Mount SSO pages under Lalia when enabled.
		if ( get_option( 'lalia_enable_sso', 'yes' ) === 'yes' && class_exists( 'WP_SSO_Settings' ) ) {
			$settings = WP_SSO_Settings::get_instance();
			add_submenu_page(
				'lalia',
				'SSO Settings',
				'SSO Settings',
				'manage_options',
				'wp-sso-settings',
				array( $settings, 'render_settings_page' )
			);
			add_submenu_page(
				'lalia',
				'SSO Logs',
				'SSO Logs',
				'manage_options',
				'wp-sso-settings-logs',
				array( $settings, 'render_logs_page' )
			);
		}
	}

	public function render_overview_page() {
		$cart_enabled = get_option( 'lalia_enable_single_item_cart', 'yes' ) === 'yes';
		$sso_enabled = get_option( 'lalia_enable_sso', 'yes' ) === 'yes';
		$pkg_id_enabled = get_option( 'lalia_enable_stripe_package_id', 'yes' ) === 'yes';
		$has_wc_single_item_cart = $cart_enabled && class_exists( 'WCSingleItemCart' );
		$sso_status = ( $sso_enabled && class_exists( 'WP_SSO_Handler' ) ) ? WP_SSO_Handler::validate_configuration() : new WP_Error( 'disabled', 'WP SSO is disabled' );
		$notice = isset( $_GET['lalia_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['lalia_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1>Lalia</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<p>This plugin integrates the following modules:</p>
			<ul style="list-style:disc;margin-left:20px;">
				<li>
					<strong>Single Item Cart</strong>: Limit cart to one product; adding a new product replaces the existing one.
					Status: <?php echo $cart_enabled ? '<span style=\"color:#46b450;\">Enabled</span>' : '<span style=\"color:#dc3232;\">Disabled</span>'; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:10px;">
						<?php wp_nonce_field( 'lalia_toggle_module' ); ?>
						<input type="hidden" name="action" value="lalia_toggle_module" />
						<input type="hidden" name="module" value="cart" />
						<input type="hidden" name="state" value="<?php echo $cart_enabled ? 'disable' : 'enable'; ?>" />
						<input type="submit" class="button" value="<?php echo $cart_enabled ? 'Disable' : 'Enable'; ?>" />
					</form>
				</li>
				<li>
					<strong>WP SSO</strong>: JWT-based SSO to external platform with logs and settings.
					Status: <?php echo $sso_enabled ? '<span style=\"color:#46b450;\">Enabled</span>' : '<span style=\"color:#dc3232;\">Disabled</span>'; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:10px;">
						<?php wp_nonce_field( 'lalia_toggle_module' ); ?>
						<input type="hidden" name="action" value="lalia_toggle_module" />
						<input type="hidden" name="module" value="sso" />
						<input type="hidden" name="state" value="<?php echo $sso_enabled ? 'disable' : 'enable'; ?>" />
						<input type="submit" class="button" value="<?php echo $sso_enabled ? 'Disable' : 'Enable'; ?>" />
					</form>
				</li>
				<li>
					<strong>Stripe package_id injector</strong>: copy each WC product's <code>_lalia_package_id</code> custom field into Stripe PaymentIntent metadata so LALIA ERP can credit the right package on purchase.
					Status: <?php echo $pkg_id_enabled ? '<span style=\"color:#46b450;\">Enabled</span>' : '<span style=\"color:#dc3232;\">Disabled</span>'; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:10px;">
						<?php wp_nonce_field( 'lalia_toggle_module' ); ?>
						<input type="hidden" name="action" value="lalia_toggle_module" />
						<input type="hidden" name="module" value="stripe_package_id" />
						<input type="hidden" name="state" value="<?php echo $pkg_id_enabled ? 'disable' : 'enable'; ?>" />
						<input type="submit" class="button" value="<?php echo $pkg_id_enabled ? 'Disable' : 'Enable'; ?>" />
					</form>
				</li>
			</ul>
			<hr />
			<h2>WP SSO Configuration</h2>
			<?php if ( is_wp_error( $sso_status ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $sso_status->get_error_message() ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-success"><p>SSO configuration looks good.</p></div>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wp-sso-settings' ) ); ?>">Configure SSO</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wp-sso-settings-logs' ) ); ?>">View SSO Logs</a>
			</p>
		</div>
		<?php
	}

	public function handle_toggle_module() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'lalia_toggle_module' );
		$module = isset( $_POST['module'] ) ? sanitize_text_field( wp_unslash( $_POST['module'] ) ) : '';
		$state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$enable = ( $state === 'enable' );
		$message = '';

		switch ( $module ) {
			case 'sso':
				update_option( 'lalia_enable_sso', $enable ? 'yes' : 'no' );
				flush_rewrite_rules();
				$message = $enable ? 'SSO enabled' : 'SSO disabled';
				break;
			case 'cart':
				update_option( 'lalia_enable_single_item_cart', $enable ? 'yes' : 'no' );
				$message = $enable ? 'Single Item Cart enabled' : 'Single Item Cart disabled';
				break;
			case 'stripe_package_id':
				update_option( 'lalia_enable_stripe_package_id', $enable ? 'yes' : 'no' );
				$message = $enable ? 'Stripe package_id injector enabled' : 'Stripe package_id injector disabled';
				break;
			default:
				wp_redirect( admin_url( 'admin.php?page=lalia' ) );
				exit;
		}

		wp_redirect( add_query_arg( array( 'page' => 'lalia', 'lalia_notice' => rawurlencode( $message ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

new Lalia_Plugin();

// -----------------------------------------------------------------------------
// Auto-update integration
//
// The "Update URI" header above points WordPress at our wp_update cloud
// function instead of wordpress.org. WordPress fires the
// `update_plugins_<host>` filter when checking for updates against that
// host; the handler fetches the JSON response (slug, new_version, package
// URL, tested, requires_php, etc.) and hands it back to the built-in
// update checker. Mirrors the user_auth plugin's pipeline exactly — see
// the lalia-wp README ▸ Deployment and Automated Plugin Updates.
// -----------------------------------------------------------------------------

add_filter( 'update_plugins_europe-west3-horeis.cloudfunctions.net', 'lalia_plugin_update_handler', 10, 4 );
function lalia_plugin_update_handler( $update_info, $plugin_headers, $plugin_file, $locales ) {
	if ( empty( $plugin_headers['UpdateURI'] ) ) {
		return $update_info;
	}
	// The wp_update cloud function authorizes WP-side reads via IP
	// allowlist (per the Redis-backed allowlist in the function), so the
	// GET below carries no API key — same pattern as user_auth's update
	// handler. If you ever host the WP install at a new IP, add it to
	// the allowlist or this filter will silently return "no update".
	$request = wp_remote_get(
		$plugin_headers['UpdateURI'],
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			),
		)
	);

	if ( is_wp_error( $request ) ) {
		return $update_info;
	}
	$code = wp_remote_retrieve_response_code( $request );
	if ( 200 !== (int) $code ) {
		return $update_info;
	}
	$body = wp_remote_retrieve_body( $request );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return $update_info;
	}
	return $data;
}

