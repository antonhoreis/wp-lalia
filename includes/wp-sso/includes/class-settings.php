<?php
if (!defined('ABSPATH')) { exit; }

class WP_SSO_Settings {
    private static $instance = null;
    private $page_slug = 'wp-sso-settings';
    public static function get_instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('plugin_action_links_' . WP_SSO_PLUGIN_BASENAME, array($this, 'add_settings_link'));
    }
    public function add_admin_menu() {
        // Lalia owns the top-level; we only provide renderers. The caller removes this menu.
        add_menu_page(__('WP SSO Settings', 'lalia'), __('WP SSO', 'lalia'), 'manage_options', $this->page_slug, array($this, 'render_settings_page'), 'dashicons-admin-network', 80);
        add_submenu_page($this->page_slug, __('SSO Logs', 'lalia'), __('Logs', 'lalia'), 'manage_options', $this->page_slug . '-logs', array($this, 'render_logs_page'));
    }
    public function register_settings() {
        register_setting('wp_sso_settings', 'wp_sso_api_key', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('wp_sso_settings', 'wp_sso_other_website_domain', array('sanitize_callback' => array($this, 'sanitize_domain')));
        register_setting('wp_sso_settings', 'wp_sso_error_page_url', array('sanitize_callback' => 'esc_url_raw'));
        register_setting('wp_sso_settings', 'wp_sso_menu_item_title', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('wp_sso_settings', 'wp_sso_enable_logging', array('sanitize_callback' => array($this, 'sanitize_checkbox')));
        register_setting('wp_sso_settings', 'wp_sso_return_to_url', array('sanitize_callback' => 'esc_url_raw'));
        add_settings_section('wp_sso_main_settings', __('Main Settings', 'lalia'), array($this, 'render_main_section'), $this->page_slug);
        add_settings_field('wp_sso_api_key', __('API Key', 'lalia'), array($this, 'render_api_key_field'), $this->page_slug, 'wp_sso_main_settings');
        add_settings_field('wp_sso_other_website_domain', __('Other Website Domain', 'lalia'), array($this, 'render_domain_field'), $this->page_slug, 'wp_sso_main_settings');
        add_settings_field('wp_sso_error_page_url', __('Error Page URL', 'lalia'), array($this, 'render_error_page_field'), $this->page_slug, 'wp_sso_main_settings');
        add_settings_field('wp_sso_menu_item_title', __('Menu Item Title', 'lalia'), array($this, 'render_menu_item_field'), $this->page_slug, 'wp_sso_main_settings');
        add_settings_field('wp_sso_enable_logging', __('Enable Logging', 'lalia'), array($this, 'render_logging_field'), $this->page_slug, 'wp_sso_main_settings');
        add_settings_field('wp_sso_return_to_url', __('Return To URL', 'lalia'), array($this, 'render_return_to_url_field'), $this->page_slug, 'wp_sso_main_settings');
    }
    public function sanitize_domain($domain) {
        $domain = preg_replace('~^(?:f|ht)tps?://~i', '', $domain);
        $domain = rtrim($domain, '/');
        return sanitize_text_field($domain);
    }
    public function sanitize_checkbox($value) { return $value === 'yes' ? 'yes' : 'no'; }
    public function render_settings_page() {
        $config_status = WP_SSO_Handler::validate_configuration();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if (is_wp_error($config_status)): ?>
                <div class="notice notice-warning"><p><?php echo esc_html($config_status->get_error_message()); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('wp_sso_settings'); do_settings_sections($this->page_slug); submit_button(); ?>
            </form>
        </div>
        <?php
    }
    public function render_main_section() { echo '<p>' . __('Configure your SSO settings below.', 'lalia') . '</p>'; }
    public function render_api_key_field() { $value = get_option('wp_sso_api_key', ''); ?>
        <input type="text" id="wp_sso_api_key" name="wp_sso_api_key" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter the API key provided by the external platform.', 'lalia'); ?></p>
    <?php }
    public function render_domain_field() { $value = get_option('wp_sso_other_website_domain', ''); ?>
        <input type="text" id="wp_sso_other_website_domain" name="wp_sso_other_website_domain" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="example.com" />
        <p class="description"><?php _e('Enter the domain of the external platform (without https://).', 'lalia'); ?></p>
    <?php }
    public function render_error_page_field() { $value = get_option('wp_sso_error_page_url', home_url('/sso-error/')); ?>
        <input type="url" id="wp_sso_error_page_url" name="wp_sso_error_page_url" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('URL to redirect users when SSO fails.', 'lalia'); ?></p>
    <?php }
    public function render_menu_item_field() { $value = get_option('wp_sso_menu_item_title', 'My Courses'); ?>
        <input type="text" id="wp_sso_menu_item_title" name="wp_sso_menu_item_title" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Title of the menu item that will trigger SSO.', 'lalia'); ?></p>
    <?php }
    public function render_logging_field() { $value = get_option('wp_sso_enable_logging', 'yes'); ?>
        <label>
            <input type="checkbox" id="wp_sso_enable_logging" name="wp_sso_enable_logging" value="yes" <?php checked($value, 'yes'); ?> />
            <?php _e('Enable logging of SSO attempts', 'lalia'); ?>
        </label>
        <p class="description"><?php _e('Logs will be automatically cleaned up after 30 days.', 'lalia'); ?></p>
    <?php }
    public function render_return_to_url_field() { $value = get_option('wp_sso_return_to_url', ''); ?>
        <input type="url" id="wp_sso_return_to_url" name="wp_sso_return_to_url" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('URL to redirect users to after successful SSO.', 'lalia'); ?></p>
    <?php }
    public function render_logs_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $logger = WP_SSO_Logger::get_instance();
        $logs_data = $logger->get_logs(array('page' => $page, 'per_page' => 20));
        $stats = $logger->get_statistics();
        ?>
        <div class="wrap">
            <h1><?php _e('SSO Logs', 'lalia'); ?></h1>
            <div class="wp-sso-stats">
                <h2><?php _e('Last 7 Days Statistics', 'lalia'); ?></h2>
                <p>
                    <?php _e('Success:', 'lalia'); ?>
                    <strong><?php echo isset($stats['totals']['success']) ? intval($stats['totals']['success']->count) : 0; ?></strong>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <?php _e('Failed:', 'lalia'); ?>
                    <strong><?php echo isset($stats['totals']['failed']) ? intval($stats['totals']['failed']->count) : 0; ?></strong>
                </p>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php _e('Date/Time', 'lalia'); ?></th>
                    <th><?php _e('User', 'lalia'); ?></th>
                    <th><?php _e('Email', 'lalia'); ?></th>
                    <th><?php _e('Status', 'lalia'); ?></th>
                    <th><?php _e('Error', 'lalia'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!empty($logs_data['items'])): foreach ($logs_data['items'] as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log->created_at); ?></td>
                        <td><?php echo esc_html($log->user_id); ?></td>
                        <td><?php echo esc_html($log->user_email); ?></td>
                        <td><span class="status-<?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
                        <td><?php echo esc_html($log->error_message ?: '-'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5"><?php _e('No logs found.', 'lalia'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($logs_data['pages'] > 1): ?>
                <div class="tablenav bottom"><div class="tablenav-pages">
                    <?php echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $logs_data['pages'],
                        'current' => $page
                    )); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, $this->page_slug) === false) { return; }
        wp_enqueue_style('wp-sso-admin', WP_SSO_PLUGIN_URL . 'admin/css/admin-styles.css', array(), WP_SSO_PLUGIN_VERSION);
        wp_enqueue_script('wp-sso-admin', WP_SSO_PLUGIN_URL . 'admin/js/admin-scripts.js', array('jquery'), WP_SSO_PLUGIN_VERSION, true);
    }
    public function add_settings_link($links) {
        $settings_link = sprintf('<a href="%s">%s</a>', admin_url('admin.php?page=' . $this->page_slug), __('Settings', 'lalia'));
        array_unshift($links, $settings_link);
        return $links;
    }
}


