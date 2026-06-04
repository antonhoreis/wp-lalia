<?php
/**
 * Dev-only assertion script for the Checkout Prefill module.
 * Run: docker compose run --rm wpcli eval-file wp-content/plugins/lalia/bin/test-prefill.php
 * Exits non-zero on any failure. Never deployed logic — pure test harness.
 */
if ( ! defined( 'WP_CLI' ) ) {
	echo "Run via: wp eval-file wp-content/plugins/lalia/bin/test-prefill.php\n";
	exit( 1 );
}

$failures = 0;
$checks   = 0;
function lalia_check( $label, $cond ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) {
		WP_CLI::log( "PASS: $label" );
	} else {
		$failures++;
		WP_CLI::warning( "FAIL: $label" );
	}
}

// --- Setup: temporary secret (restored at the end) ---
$old_secret = get_option( 'lalia_prefill_secret', '' );
$secret     = 'test-secret-do-not-use-in-prod';
update_option( 'lalia_prefill_secret', $secret );

$mint = function ( array $payload, ?string $sign_secret = null ) use ( $secret ) {
	return \Firebase\JWT\JWT::encode( $payload, $sign_secret ?? $secret, 'HS256' );
};
$base_billing = array(
	'email'      => 'anna@example.de',
	'first_name' => 'Anna',
	'last_name'  => 'Schmidt',
	'phone'      => '+4915123456789',
	'address_1'  => 'Invalidenstr. 1',
	'city'       => 'Berlin',
	'postcode'   => '10115',
	'country'    => 'DE',
);
$valid_payload = array(
	'iat'     => time(),
	'exp'     => time() + 3600,
	'billing' => $base_billing,
	'coupon'  => 'SDR10',
);

// --- 1. Class exists ---
lalia_check( 'class Lalia_Checkout_Prefill exists', class_exists( 'Lalia_Checkout_Prefill' ) );

// --- 2. Valid token round-trips ---
$result = Lalia_Checkout_Prefill::verify_token( $mint( $valid_payload ) );
lalia_check( 'valid token accepted', ! is_wp_error( $result ) );
lalia_check( 'email round-trips', ! is_wp_error( $result ) && 'anna@example.de' === ( $result['billing']['email'] ?? null ) );
lalia_check( 'first_name round-trips', ! is_wp_error( $result ) && 'Anna' === ( $result['billing']['first_name'] ?? null ) );
lalia_check( 'country round-trips uppercased', ! is_wp_error( $result ) && 'DE' === ( $result['billing']['country'] ?? null ) );
lalia_check( 'coupon normalized lowercase', ! is_wp_error( $result ) && 'sdr10' === ( $result['coupon'] ?? null ) );

// --- 3. Expired token rejected ---
$expired = $valid_payload;
$expired['iat'] = time() - 7200;
$expired['exp'] = time() - 3600;
lalia_check( 'expired token rejected', is_wp_error( Lalia_Checkout_Prefill::verify_token( $mint( $expired ) ) ) );

// --- 4. Tampered signature rejected ---
lalia_check( 'wrong-secret token rejected', is_wp_error( Lalia_Checkout_Prefill::verify_token( $mint( $valid_payload, 'wrong-secret' ) ) ) );

// --- 5. Missing exp rejected ---
$no_exp = $valid_payload;
unset( $no_exp['exp'] );
lalia_check( 'token without exp rejected', is_wp_error( Lalia_Checkout_Prefill::verify_token( $mint( $no_exp ) ) ) );

// --- 6. Garbage token rejected ---
lalia_check( 'garbage token rejected', is_wp_error( Lalia_Checkout_Prefill::verify_token( 'not.a.jwt' ) ) );

// --- 7. Non-whitelisted billing keys dropped ---
$extra = $valid_payload;
$extra['billing']['is_admin'] = '1';
$extra['billing']['role']     = 'administrator';
$result = Lalia_Checkout_Prefill::verify_token( $mint( $extra ) );
lalia_check( 'unknown billing keys dropped', ! is_wp_error( $result ) && ! isset( $result['billing']['is_admin'] ) && ! isset( $result['billing']['role'] ) );

// --- 8. Invalid country dropped, other fields kept ---
$bad_country = $valid_payload;
$bad_country['billing']['country'] = 'XX';
$result = Lalia_Checkout_Prefill::verify_token( $mint( $bad_country ) );
lalia_check( 'invalid country dropped', ! is_wp_error( $result ) && ! isset( $result['billing']['country'] ) && 'Anna' === ( $result['billing']['first_name'] ?? null ) );

// --- 9. Empty secret -> rejected ---
update_option( 'lalia_prefill_secret', '' );
lalia_check( 'empty secret rejects token', is_wp_error( Lalia_Checkout_Prefill::verify_token( $mint( $valid_payload ) ) ) );

// --- Teardown ---
update_option( 'lalia_prefill_secret', $old_secret );

if ( $failures > 0 ) {
	WP_CLI::error( "$failures of $checks checks failed." );
}
WP_CLI::success( "All $checks checks passed." );
