<?php
/**
 * Dev utility: mint a checkout-prefill payment link.
 *
 * Usage:
 *   docker compose run --rm wpcli eval-file wp-content/plugins/lalia/bin/make-prefill-token.php \
 *     <product_id> <email> [first_name] [last_name] [coupon] [days_valid]
 *
 * Example:
 *   docker compose run --rm wpcli eval-file wp-content/plugins/lalia/bin/make-prefill-token.php \
 *     123 anna@example.de Anna Schmidt SDR10 30
 */
if ( ! defined( 'WP_CLI' ) ) {
	echo "Run via wp eval-file\n";
	exit( 1 );
}

$secret = get_option( 'lalia_prefill_secret', '' );
if ( '' === $secret ) {
	WP_CLI::error( 'lalia_prefill_secret is not set. Set it: wp option update lalia_prefill_secret <secret>' );
}

$product_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$email      = isset( $args[1] ) ? sanitize_email( $args[1] ) : '';
if ( ! $product_id || ! $email ) {
	WP_CLI::error( 'Usage: ... make-prefill-token.php <product_id> <email> [first_name] [last_name] [coupon] [days_valid]' );
}
$first  = isset( $args[2] ) ? sanitize_text_field( $args[2] ) : '';
$last   = isset( $args[3] ) ? sanitize_text_field( $args[3] ) : '';
$coupon = isset( $args[4] ) ? sanitize_text_field( $args[4] ) : '';
$days   = isset( $args[5] ) ? absint( $args[5] ) : 30;

$now     = time();
$payload = array(
	'iat'     => $now,
	'exp'     => $now + $days * DAY_IN_SECONDS,
	'billing' => array_filter(
		array(
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
		)
	),
);
if ( '' !== $coupon ) {
	$payload['coupon'] = $coupon;
}

$token = \Firebase\JWT\JWT::encode( $payload, $secret, 'HS256' );
$url   = add_query_arg(
	array(
		'add-to-cart' => $product_id,
		'prefill'     => $token,
	),
	wc_get_checkout_url()
);
WP_CLI::success( 'Payment link (valid ' . $days . ' days):' );
WP_CLI::log( $url );
