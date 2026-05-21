<?php
// SSO Error Page Template (embedded)
if (!defined('ABSPATH')) { exit; }
$error_message = isset($_GET['sso_error']) ? urldecode($_GET['sso_error']) : __('An unknown error occurred during SSO authentication.', 'lalia');
get_header();
?>
<div class="wp-sso-error-page"><div class="container"><div class="error-content">
<h1><?php _e('SSO Authentication Error', 'lalia'); ?></h1>
<div class="error-icon"><span class="dashicons dashicons-warning"></span></div>
<div class="error-message"><p><?php echo esc_html($error_message); ?></p></div>
<div class="error-actions">
  <a href="<?php echo esc_url(home_url()); ?>" class="button button-primary"><?php _e('Return to Homepage', 'lalia'); ?></a>
  <?php if (is_user_logged_in()): ?>
    <a href="<?php echo esc_url( function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('dashboard') : home_url('/my-account/') ); ?>" class="button button-secondary"><?php _e('Go to My Account', 'lalia'); ?></a>
  <?php else: ?>
    <a href="<?php echo esc_url(wp_login_url()); ?>" class="button button-secondary"><?php _e('Login', 'lalia'); ?></a>
  <?php endif; ?>
</div>
<div class="error-help"><p><?php _e('If you continue to experience issues, please contact our support team.', 'lalia'); ?></p></div>
</div></div></div>
<style>
.wp-sso-error-page { padding: 60px 0; min-height: 400px; }
.wp-sso-error-page .container { max-width: 600px; margin: 0 auto; padding: 0 20px; }
.wp-sso-error-page .error-content { text-align: center; background: #fff; padding: 40px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.wp-sso-error-page h1 { color: #dc3232; margin-bottom: 30px; font-size: 28px; }
.wp-sso-error-page .error-icon { font-size: 60px; color: #dc3232; margin-bottom: 20px; }
.wp-sso-error-page .error-icon .dashicons { width: 60px; height: 60px; font-size: 60px; }
.wp-sso-error-page .error-message { margin-bottom: 30px; font-size: 16px; color: #555; }
.wp-sso-error-page .error-actions { margin-bottom: 30px; }
.wp-sso-error-page .error-actions .button { margin: 0 5px; padding: 10px 20px; font-size: 16px; text-decoration: none; display: inline-block; }
.wp-sso-error-page .button-primary { background: #0073aa; color: #fff; border: none; border-radius: 3px; }
.wp-sso-error-page .button-primary:hover { background: #005a87; }
.wp-sso-error-page .button-secondary { background: #f1f1f1; color: #555; border: 1px solid #ccc; border-radius: 3px; }
.wp-sso-error-page .button-secondary:hover { background: #e5e5e5; }
.wp-sso-error-page .error-help { font-size: 14px; color: #777; }
@media (max-width: 600px) { .wp-sso-error-page .error-content { padding: 20px; } .wp-sso-error-page h1 { font-size: 24px; } .wp-sso-error-page .error-actions .button { display: block; width: 100%; margin: 10px 0; } }
</style>
<?php get_footer();


