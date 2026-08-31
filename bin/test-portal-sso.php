<?php
/**
 * Dev-only assertion script for the Portal SSO module.
 * Run: docker compose run --rm wpcli eval-file wp-content/plugins/lalia/bin/test-portal-sso.php
 * Exits non-zero on any failure. Never deployed logic — pure test harness.
 *
 * Covers the token contract the ERP verifier enforces
 * (lalia-erp supabase/functions/_shared/portal/handoff.ts), the eligibility
 * gate, the settings sanitizers and the page routing helpers. The ERP-side
 * verifier itself is exercised separately (see the L20-985 PR: a Deno one-liner
 * feeds a token minted here into verifyHandoffToken()).
 */
if ( ! defined( 'WP_CLI' ) ) {
	echo "Run via: wp eval-file wp-content/plugins/lalia/bin/test-portal-sso.php\n";
	exit( 1 );
}

global $failures, $checks;
$failures = 0;
$checks   = 0;
function lalia_portal_check( $label, $cond ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) {
		WP_CLI::log( "PASS: $label" );
	} else {
		$failures++;
		WP_CLI::warning( "FAIL: $label" );
	}
}

$old_secret  = get_option( Lalia_Portal_SSO::OPTION_SECRET, '' );
$old_url     = get_option( Lalia_Portal_SSO::OPTION_PORTAL_URL, '' );
$old_enabled = get_option( Lalia_Portal_SSO::OPTION_ENABLED, 'no' );
$secret      = 'test-portal-secret-do-not-use-in-prod';
update_option( Lalia_Portal_SSO::OPTION_SECRET, $secret );
update_option( Lalia_Portal_SSO::OPTION_PORTAL_URL, 'https://erp.example.test/portal/' );

$created_users = array();
$make_user     = function ( $login, $email, $role, $first = '', $last = '' ) use ( &$created_users ) {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
			'first_name' => $first,
			'last_name'  => $last,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'could not create test user ' . $login . ': ' . $id->get_error_message() );
	}
	$created_users[] = $id;
	return $id;
};

try {
	$stamp    = substr( md5( uniqid( '', true ) ), 0, 8 );
	$customer = $make_user( "pt_customer_$stamp", "PT.Customer.$stamp@Example.COM", 'customer', 'Maria', 'Rossi' );
	$blogger  = $make_user( "pt_blogger_$stamp", "pt.blogger.$stamp@example.com", 'contributor' );
	$billing  = $make_user( "pt_billing_$stamp", "pt.billing.$stamp@example.com", 'customer' );
	update_user_meta( $billing, 'billing_first_name', '  Anna ' );
	update_user_meta( $billing, 'billing_last_name', 'Schmidt' );

	// --- eligibility gate ---
	lalia_portal_check( 'customer is eligible', true === Lalia_Portal_SSO_Token::validate_user( $customer ) );
	$denied = Lalia_Portal_SSO_Token::validate_user( $blogger );
	lalia_portal_check( 'contributor is denied (not_customer)', is_wp_error( $denied ) && 'not_customer' === $denied->get_error_code() );
	$missing = Lalia_Portal_SSO_Token::validate_user( 999999999 );
	lalia_portal_check( 'unknown user is denied (invalid_user)', is_wp_error( $missing ) && 'invalid_user' === $missing->get_error_code() );

	// --- claim set (spec §3.1.1) ---
	$now    = 1756380000;
	$claims = Lalia_Portal_SSO_Token::claims( $customer, $now );
	lalia_portal_check( 'claims: iss=lalia-wp', 'lalia-wp' === $claims['iss'] );
	lalia_portal_check( 'claims: aud=lalia-portal', 'lalia-portal' === $claims['aud'] );
	lalia_portal_check( 'claims: sub is the WP user id as a string', (string) $customer === $claims['sub'] && is_string( $claims['sub'] ) );
	lalia_portal_check( 'claims: email lower-cased', "pt.customer.$stamp@example.com" === $claims['email'] );
	lalia_portal_check( 'claims: names from user meta', 'Maria' === $claims['first_name'] && 'Rossi' === $claims['last_name'] );
	lalia_portal_check( 'claims: iat = now', $now === $claims['iat'] );
	lalia_portal_check( 'claims: exp = iat + 120', $now + 120 === $claims['exp'] );
	lalia_portal_check( 'claims: jti is a uuid v4', 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $claims['jti'] ) );
	$claims2 = Lalia_Portal_SSO_Token::claims( $customer, $now );
	lalia_portal_check( 'claims: jti differs per mint', $claims['jti'] !== $claims2['jti'] );
	lalia_portal_check( 'claims: exactly the spec keys', array( 'iss', 'aud', 'sub', 'email', 'first_name', 'last_name', 'iat', 'exp', 'jti' ) === array_keys( $claims ) );

	$bclaims = Lalia_Portal_SSO_Token::claims( $billing );
	lalia_portal_check( 'claims: billing_* name fallback, trimmed', 'Anna' === $bclaims['first_name'] && 'Schmidt' === $bclaims['last_name'] );
	$denied_claims = Lalia_Portal_SSO_Token::claims( $blogger );
	lalia_portal_check( 'claims: denied user yields WP_Error', is_wp_error( $denied_claims ) );

	// --- signing ---
	$token = Lalia_Portal_SSO_Token::mint( $customer );
	lalia_portal_check( 'mint: returns a 3-part JWT', is_string( $token ) && 3 === count( explode( '.', $token ) ) );
	$header = json_decode( base64_decode( strtr( explode( '.', $token )[0], '-_', '+/' ) ), true );
	lalia_portal_check( 'mint: header alg=HS256', is_array( $header ) && 'HS256' === $header['alg'] );
	$decoded = Lalia_Portal_SSO_Token::decode( $token );
	lalia_portal_check( 'mint: verifies with the shared secret', is_array( $decoded ) && $decoded['sub'] === (string) $customer && 'lalia-portal' === $decoded['aud'] );
	lalia_portal_check( 'mint: exp - iat = 120 on the wire', is_array( $decoded ) && 120 === $decoded['exp'] - $decoded['iat'] );
	$tampered = substr( $token, 0, -2 ) . 'xx';
	lalia_portal_check( 'mint: tampered signature rejected', is_wp_error( Lalia_Portal_SSO_Token::decode( $tampered ) ) );
	$other = \Firebase\JWT\JWT::encode( $decoded, 'some-other-secret-that-is-at-least-32-bytes-long', 'HS256' ); // php-jwt ≥6.10 enforces a 256-bit HS256 key
	lalia_portal_check( 'mint: token signed with another secret rejected', is_wp_error( Lalia_Portal_SSO_Token::decode( $other ) ) );
	$alg_none = rtrim( strtr( base64_encode( '{"alg":"none","typ":"JWT"}' ), '+/', '-_' ), '=' ) . '.' . explode( '.', $token )[1] . '.';
	lalia_portal_check( 'mint: alg=none rejected', is_wp_error( Lalia_Portal_SSO_Token::decode( $alg_none ) ) );
	lalia_portal_check( 'mint: denied user cannot mint', is_wp_error( Lalia_Portal_SSO_Token::mint( $blogger ) ) );

	update_option( Lalia_Portal_SSO::OPTION_SECRET, '' );
	$no_secret = Lalia_Portal_SSO_Token::mint( $customer );
	lalia_portal_check( 'mint: no secret → no_secret error (module inert)', is_wp_error( $no_secret ) && 'no_secret' === $no_secret->get_error_code() );
	$cfg = Lalia_Portal_SSO::validate_configuration();
	lalia_portal_check( 'validate_configuration flags the missing secret', is_wp_error( $cfg ) );
	update_option( Lalia_Portal_SSO::OPTION_SECRET, $secret );
	lalia_portal_check( 'validate_configuration passes with secret + https portal URL', true === Lalia_Portal_SSO::validate_configuration() );
	update_option( Lalia_Portal_SSO::OPTION_SECRET, 'short' );
	lalia_portal_check( 'validate_configuration flags a secret shorter than 32 bytes', is_wp_error( Lalia_Portal_SSO::validate_configuration() ) );
	update_option( Lalia_Portal_SSO::OPTION_SECRET, $secret );

	// --- settings sanitizers ---
	$settings = Lalia_Portal_SSO_Settings::init();
	lalia_portal_check( 'secret sanitizer: empty submission keeps the stored secret', $secret === $settings->sanitize_secret( '' ) );
	lalia_portal_check( 'secret sanitizer: whitespace-only keeps the stored secret', $secret === $settings->sanitize_secret( "  \n" ) );
	lalia_portal_check( 'secret sanitizer: new value trimmed, otherwise verbatim', 'a=b+c/d==' === $settings->sanitize_secret( ' a=b+c/d== ' ) );
	lalia_portal_check( 'portal url sanitizer: https accepted with trailing slash', 'https://erp.lalia-berlin.com/stage/portal/' === $settings->sanitize_portal_url( 'https://erp.lalia-berlin.com/stage/portal' ) );
	lalia_portal_check( 'portal url sanitizer: http rejected → previous value kept', 'https://erp.example.test/portal/' === $settings->sanitize_portal_url( 'http://insecure.example/portal/' ) );
	lalia_portal_check( 'page slug sanitizer: slugified, never empty', 'my-lalia' === $settings->sanitize_page_slug( 'My LALIA' ) && 'my-lalia' === $settings->sanitize_page_slug( '' ) );

	// --- routing helpers ---
	lalia_portal_check( 'portal_url has a trailing slash', 'https://erp.example.test/portal/' === Lalia_Portal_SSO::portal_url() );
	lalia_portal_check( 'portal_origin is scheme://host', 'https://erp.example.test' === Lalia_Portal_SSO::portal_origin() );
	lalia_portal_check( 'page_url is /<slug>/ under home', home_url( '/my-lalia/' ) === Lalia_Portal_SSO::page_url() );
	wp_set_current_user( $customer );
	$logout_url = Lalia_Portal_SSO::logout_url();
	wp_set_current_user( 0 );
	lalia_portal_check( 'logout_url carries a nonce and is not HTML-escaped (JS navigates to it)', false !== strpos( $logout_url, '_wpnonce=' ) && false === strpos( $logout_url, '&amp;' ) );
	$rules = get_option( 'rewrite_rules' );
	lalia_portal_check( 'rewrite rule for the page slug is registered', 'yes' !== $old_enabled || ( is_array( $rules ) && isset( $rules[ '^' . preg_quote( 'my-lalia', '#' ) . '/?$' ] ) ) );
	lalia_portal_check( 'allowed roles include customer and exclude contributor', in_array( 'customer', Lalia_Portal_SSO_Token::allowed_roles(), true ) && ! in_array( 'contributor', Lalia_Portal_SSO_Token::allowed_roles(), true ) );

	// --- login redirect ---
	$sso2 = Lalia_Portal_SSO::init();
	lalia_portal_check( 'login redirect: customer → User Zone page', Lalia_Portal_SSO::page_url() === $sso2->filter_login_redirect( admin_url(), '', get_user_by( 'id', $customer ) ) );
	lalia_portal_check( 'login redirect: contributor unchanged', admin_url() === $sso2->filter_login_redirect( admin_url(), '', get_user_by( 'id', $blogger ) ) );
	$admin_user = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( $admin_user ) {
		lalia_portal_check( 'login redirect: administrator unchanged', admin_url() === $sso2->filter_login_redirect( admin_url(), '', $admin_user[0] ) );
	}

	// --- header zone entry ---
	$sso3 = Lalia_Portal_SSO::init();
	$primary_args = (object) array( 'theme_location' => 'primary', 'menu' => null );
	$other_args   = (object) array( 'theme_location' => 'footer_menu', 'menu' => null );
	wp_set_current_user( $customer );
	$with = $sso3->filter_menu_objects( array(), $primary_args );
	lalia_portal_check( 'zone entry appended to the primary menu for a customer', 1 === count( $with ) && false !== strpos( $with[0]->title, 'User Zone' ) && false !== strpos( $with[0]->title, 'Maria' ) && Lalia_Portal_SSO::page_url() === $with[0]->url );
	lalia_portal_check( 'zone entry absent from other menus', array() === $sso3->filter_menu_objects( array(), $other_args ) );
	wp_set_current_user( $blogger );
	$blogger_items = $sso3->filter_menu_objects( array(), $primary_args );
	wp_set_current_user( 0 );
	$anon_items = $sso3->filter_menu_objects( array(), $primary_args );
	lalia_portal_check( 'zone entry absent for non-customers and logged-out visitors', array() === $blogger_items && array() === $anon_items );

	// --- logger ---
	Lalia_Portal_SSO_Logger::ensure_table();
	$logger = Lalia_Portal_SSO_Logger::get_instance();
	$logger->log( $customer, Lalia_Portal_SSO_Logger::EVENT_MINT, 'success', 'test-suite' );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Lalia_Portal_SSO_Logger::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $customer ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	lalia_portal_check( 'logger writes a row with the user e-mail and event', $row && 'mint' === $row->event && "PT.Customer.$stamp@Example.COM" === $row->user_email );
	$wpdb->delete( Lalia_Portal_SSO_Logger::table(), array( 'user_id' => $customer ), array( '%d' ) );
} finally {
	update_option( Lalia_Portal_SSO::OPTION_SECRET, $old_secret );
	update_option( Lalia_Portal_SSO::OPTION_PORTAL_URL, $old_url );
	foreach ( $created_users as $uid ) {
		wp_delete_user( $uid );
	}
}

WP_CLI::log( "\n$checks checks, $failures failures" );
if ( $failures > 0 ) {
	WP_CLI::error( 'Portal SSO test suite failed' );
}
WP_CLI::success( 'Portal SSO test suite passed' );
