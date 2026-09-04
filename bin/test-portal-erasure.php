<?php
/**
 * Dev-only assertion script for the Portal erasure endpoint (L20-1025).
 * Run: docker compose run --rm wpcli eval-file wp-content/plugins/lalia/bin/test-portal-erasure.php
 * Exits non-zero on any failure. Never deployed logic — pure test harness.
 *
 * Covers the pure claim rules (Lalia_Portal_Erasure::validate_claims), the
 * bearer/JWT handling, user resolution (sub vs e-mail-only), the protected-
 * role gate, jti replay, the idempotent not-found answer, WooCommerce order
 * retention across the deletion, and the log row. Every user it creates is
 * its own; the site's real accounts are never touched.
 */
if ( ! defined( 'WP_CLI' ) ) {
	echo "Run via: wp eval-file wp-content/plugins/lalia/bin/test-portal-erasure.php\n";
	exit( 1 );
}

global $failures, $checks;
$failures = 0;
$checks   = 0;
function lalia_erase_check( $label, $cond ) {
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
$old_enabled = get_option( Lalia_Portal_Erasure::OPTION_ENABLED, 'no' );
$secret      = 'test-erasure-secret-at-least-32-bytes-long!!'; // php-jwt ≥ 6.10 enforces a 256-bit HS256 key
$started     = current_time( 'mysql' );
update_option( Lalia_Portal_SSO::OPTION_SECRET, $secret );
update_option( Lalia_Portal_Erasure::OPTION_ENABLED, 'yes' );

// The route only exists while the module is on; make sure it is registered
// even when the toggle was off at bootstrap.
Lalia_Portal_Erasure::init();
$server    = rest_get_server(); // fires rest_api_init once
$route_key = '/' . Lalia_Portal_Erasure::REST_NAMESPACE . Lalia_Portal_Erasure::REST_ROUTE;
if ( ! isset( $server->get_routes( Lalia_Portal_Erasure::REST_NAMESPACE )[ $route_key ] ) ) {
	Lalia_Portal_Erasure::register_routes();
}

$base_claims = function ( $overrides = array(), $now = null ) {
	$now = null === $now ? time() : (int) $now;
	return array_merge(
		array(
			'iss'   => 'lalia-erp',
			'aud'   => 'lalia-wp-erase',
			'sub'   => '',
			'email' => 'someone@example.com',
			'iat'   => $now,
			'exp'   => $now + 120,
			'jti'   => wp_generate_uuid4(),
		),
		$overrides
	);
};
$mint        = function ( $claims, $key = null ) use ( $secret ) {
	return \Firebase\JWT\JWT::encode( $claims, null === $key ? $secret : $key, 'HS256' );
};
$call        = function ( $token, $mode = 'header' ) {
	$request = new WP_REST_Request( 'POST', '/lalia/v1/portal/erase-user' );
	if ( 'header' === $mode && null !== $token ) {
		$request->set_header( 'Authorization', 'Bearer ' . $token );
	} elseif ( 'body' === $mode ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'token' => $token ) ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), (array) $response->get_data() );
};
$is_error    = function ( $result, $status, $code ) {
	return $status === $result[0] && isset( $result[1]['code'] ) && $code === $result[1]['code'];
};

$created_users = array();
$make_user     = function ( $login, $email, $role ) use ( &$created_users ) {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'could not create test user ' . $login . ': ' . $id->get_error_message() );
	}
	$created_users[] = $id;
	return $id;
};

$order = null;
try {
	$stamp = substr( md5( uniqid( '', true ) ), 0, 8 );
	$alice = $make_user( "pe_alice_$stamp", "PE.Alice.$stamp@Example.COM", 'customer' );   // deleted by sub
	$bob   = $make_user( "pe_bob_$stamp", "pe.bob.$stamp@example.com", 'customer' );       // deleted by e-mail only
	$carol = $make_user( "pe_carol_$stamp", "pe.carol.$stamp@example.com", 'customer' );   // e-mail mismatch → survives
	$dave  = $make_user( "pe_dave_$stamp", "pe.dave.$stamp@example.com", 'subscriber' );   // body-token fallback
	$erin  = $make_user( "pe_erin_$stamp", "pe.erin.$stamp@example.com", 'customer' );     // owns an order
	$blog  = $make_user( "pe_blogger_$stamp", "pe.blogger.$stamp@example.com", 'contributor' );
	$admin = $make_user( "pe_admin_$stamp", "pe.admin.$stamp@example.com", 'administrator' );
	$mgr   = get_role( 'shop_manager' ) ? $make_user( "pe_manager_$stamp", "pe.manager.$stamp@example.com", 'shop_manager' ) : 0;

	// --- pure claim rules ---
	$now    = 1756380000;
	$reason = function ( $overrides ) use ( $base_claims, $now ) {
		$result = Lalia_Portal_Erasure::validate_claims( $base_claims( $overrides, $now ), $now );
		return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	};
	lalia_erase_check( 'claims: valid set (sub empty) passes', 'ok' === $reason( array() ) );
	lalia_erase_check( 'claims: sub as numeric string passes', 'ok' === $reason( array( 'sub' => '42' ) ) );
	lalia_erase_check( 'claims: sub as integer passes', 'ok' === $reason( array( 'sub' => 42 ) ) );
	lalia_erase_check( 'claims: iss must be lalia-erp', 'iss' === $reason( array( 'iss' => 'lalia-wp' ) ) && 'iss' === $reason( array( 'iss' => null ) ) );
	lalia_erase_check( 'claims: aud must be lalia-wp-erase', 'aud' === $reason( array( 'aud' => 'lalia-portal' ) ) );
	lalia_erase_check( 'claims: sub must be a positive integer or empty', 'sub' === $reason( array( 'sub' => 'abc' ) ) && 'sub' === $reason( array( 'sub' => '0' ) ) && 'sub' === $reason( array( 'sub' => '-1' ) ) && 'sub' === $reason( array( 'sub' => '1.5' ) ) );
	lalia_erase_check( 'claims: email required and shaped', 'email' === $reason( array( 'email' => '' ) ) && 'email' === $reason( array( 'email' => null ) ) && 'email' === $reason( array( 'email' => 'nope' ) ) && 'email' === $reason( array( 'email' => 'a b@example.com' ) ) );
	lalia_erase_check( 'claims: jti must be a UUID', 'jti' === $reason( array( 'jti' => 'not-a-uuid' ) ) && 'jti' === $reason( array( 'jti' => null ) ) );
	lalia_erase_check( 'claims: iat required numeric', 'iat' === $reason( array( 'iat' => null ) ) && 'iat' === $reason( array( 'iat' => (string) $now ) ) );
	lalia_erase_check( 'claims: exp required numeric', 'exp' === $reason( array( 'exp' => null ) ) );
	lalia_erase_check( 'claims: exp - iat capped at 300', 'ttl' === $reason( array( 'exp' => $now + 301 ) ) && 'ok' === $reason( array( 'exp' => $now + 300 ) ) );
	lalia_erase_check( 'claims: exp must be after iat', 'ttl' === $reason( array( 'exp' => $now ) ) && 'ttl' === $reason( array( 'exp' => $now - 10 ) ) );
	lalia_erase_check( 'claims: expired token rejected', 'expired' === $reason( array( 'iat' => $now - 400, 'exp' => $now - 100 ) ) );
	lalia_erase_check( 'claims: not-yet-valid token rejected', 'not_yet_valid' === $reason( array( 'iat' => $now + 60, 'exp' => $now + 180 ) ) );
	lalia_erase_check( 'claims: 30 s clock skew tolerated both ways', 'ok' === $reason( array( 'iat' => $now + 20, 'exp' => $now + 140 ) ) && 'ok' === $reason( array( 'iat' => $now - 140, 'exp' => $now - 20 ) ) );
	$err = Lalia_Portal_Erasure::validate_claims( $base_claims( array( 'aud' => 'x' ), $now ), $now );
	lalia_erase_check( 'claims: errors carry HTTP 401', is_wp_error( $err ) && 401 === $err->get_error_data()['status'] );

	// --- endpoint: authentication ---
	lalia_erase_check( 'no bearer token → 401 invalid_token', $is_error( $call( null ), 401, 'invalid_token' ) );
	lalia_erase_check( 'garbage token → 401 invalid_token', $is_error( $call( 'garbage' ), 401, 'invalid_token' ) );
	lalia_erase_check( 'token signed with another secret → 401', $is_error( $call( $mint( $base_claims(), 'some-other-secret-that-is-at-least-32-bytes-long' ) ), 401, 'invalid_token' ) );
	$good     = $mint( $base_claims() );
	$alg_none = rtrim( strtr( base64_encode( '{"alg":"none","typ":"JWT"}' ), '+/', '-_' ), '=' ) . '.' . explode( '.', $good )[1] . '.';
	lalia_erase_check( 'alg=none → 401', $is_error( $call( $alg_none ), 401, 'invalid_token' ) );
	$handoff = $mint( $base_claims( array( 'iss' => 'lalia-wp', 'aud' => 'lalia-portal', 'sub' => (string) $alice, 'email' => "pe.alice.$stamp@example.com" ) ) );
	lalia_erase_check( 'a Portal SSO handoff token cannot erase (iss/aud) → 401', $is_error( $call( $handoff ), 401, 'invalid_token' ) && get_user_by( 'id', $alice ) );
	lalia_erase_check( 'expired token → 401', $is_error( $call( $mint( $base_claims( array( 'iat' => time() - 400, 'exp' => time() - 100 ) ) ) ), 401, 'invalid_token' ) );
	lalia_erase_check( 'exp - iat > 300 → 401', $is_error( $call( $mint( $base_claims( array( 'exp' => time() + 301 ) ) ) ), 401, 'invalid_token' ) );
	lalia_erase_check( 'iat in the future → 401', $is_error( $call( $mint( $base_claims( array( 'iat' => time() + 120, 'exp' => time() + 240 ) ) ) ), 401, 'invalid_token' ) );

	// --- endpoint: resolution and gates ---
	$mismatch = $call( $mint( $base_claims( array( 'sub' => (string) $carol, 'email' => "pe.bob.$stamp@example.com" ) ) ) );
	lalia_erase_check( 'sub + different e-mail → 409 email_mismatch, nobody deleted', $is_error( $mismatch, 409, 'email_mismatch' ) && get_user_by( 'id', $carol ) && get_user_by( 'id', $bob ) );
	lalia_erase_check( 'contributor → 403 protected_role, still exists', $is_error( $call( $mint( $base_claims( array( 'sub' => (string) $blog, 'email' => "pe.blogger.$stamp@example.com" ) ) ) ), 403, 'protected_role' ) && get_user_by( 'id', $blog ) );
	lalia_erase_check( 'administrator → 403 protected_role, still exists', $is_error( $call( $mint( $base_claims( array( 'sub' => (string) $admin, 'email' => "pe.admin.$stamp@example.com" ) ) ) ), 403, 'protected_role' ) && get_user_by( 'id', $admin ) );
	if ( $mgr ) {
		lalia_erase_check( 'shop_manager → 403 protected_role, still exists', $is_error( $call( $mint( $base_claims( array( 'sub' => (string) $mgr, 'email' => "pe.manager.$stamp@example.com" ) ) ) ), 403, 'protected_role' ) && get_user_by( 'id', $mgr ) );
	}
	$widen = function () {
		return array( 'customer' );
	};
	add_filter( 'lalia_portal_erasure_protected_roles', $widen );
	$roles = Lalia_Portal_Erasure::protected_roles();
	remove_filter( 'lalia_portal_erasure_protected_roles', $widen );
	lalia_erase_check( 'protected_roles filter can add roles but never removes the base list', in_array( 'customer', $roles, true ) && in_array( 'administrator', $roles, true ) && in_array( 'shop_manager', $roles, true ) );
	lalia_erase_check( 'sub that does not exist → 200 not_found (no e-mail fallback)', array( 200, array( 'ok' => true, 'deleted' => false, 'reason' => 'not_found' ) ) === $call( $mint( $base_claims( array( 'sub' => '999999999', 'email' => "pe.carol.$stamp@example.com" ) ) ) ) && get_user_by( 'id', $carol ) );
	lalia_erase_check( 'unknown e-mail, no sub → 200 not_found', array( 200, array( 'ok' => true, 'deleted' => false, 'reason' => 'not_found' ) ) === $call( $mint( $base_claims( array( 'email' => "nobody.$stamp@example.com" ) ) ) ) );

	// --- happy path by sub (claim e-mail lower-cased; stored one mixed-case) ---
	$alice_claims = $base_claims( array( 'sub' => (string) $alice, 'email' => "pe.alice.$stamp@example.com" ) );
	$alice_token  = $mint( $alice_claims );
	$deleted      = $call( $alice_token );
	lalia_erase_check( 'valid token by sub → 200 deleted', array( 200, array( 'ok' => true, 'deleted' => true, 'wp_user_id' => $alice ) ) === $deleted );
	lalia_erase_check( 'the user is gone', false === get_user_by( 'id', $alice ) && false === get_user_by( 'email', "pe.alice.$stamp@example.com" ) );
	lalia_erase_check( 'jti remembered in a transient', false !== get_transient( Lalia_Portal_Erasure::JTI_TRANSIENT_PREFIX . strtolower( $alice_claims['jti'] ) ) );
	lalia_erase_check( 'same token again → 409 replayed', $is_error( $call( $alice_token ), 409, 'replayed' ) );
	lalia_erase_check( 'fresh token for the deleted user → 200 not_found (idempotent)', array( 200, array( 'ok' => true, 'deleted' => false, 'reason' => 'not_found' ) ) === $call( $mint( $base_claims( array( 'sub' => (string) $alice, 'email' => "pe.alice.$stamp@example.com" ) ) ) ) );

	// --- e-mail-only path ---
	lalia_erase_check( 'valid token with empty sub resolves by e-mail → 200 deleted', array( 200, array( 'ok' => true, 'deleted' => true, 'wp_user_id' => $bob ) ) === $call( $mint( $base_claims( array( 'sub' => '', 'email' => "pe.bob.$stamp@example.com" ) ) ) ) && false === get_user_by( 'id', $bob ) );

	// --- token in the JSON body (hosts that strip Authorization) ---
	lalia_erase_check( 'token as JSON body field → 200 deleted', array( 200, array( 'ok' => true, 'deleted' => true, 'wp_user_id' => $dave ) ) === $call( $mint( $base_claims( array( 'sub' => (string) $dave, 'email' => "pe.dave.$stamp@example.com" ) ) ), 'body' ) && false === get_user_by( 'id', $dave ) );

	// --- WooCommerce: orders are kept and unlinked ---
	if ( function_exists( 'wc_create_order' ) ) {
		$order = wc_create_order( array( 'customer_id' => $erin ) );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'could not create a test order: ' . $order->get_error_message() );
		}
		$order_id = $order->get_id();
		lalia_erase_check( 'customer with an order → 200 deleted', array( 200, array( 'ok' => true, 'deleted' => true, 'wp_user_id' => $erin ) ) === $call( $mint( $base_claims( array( 'sub' => (string) $erin, 'email' => "pe.erin.$stamp@example.com" ) ) ) ) );
		$order = wc_get_order( $order_id );
		lalia_erase_check( 'the order survives the erasure', $order && (int) $order->get_id() === (int) $order_id );
		lalia_erase_check( 'the order is unlinked from the deleted customer (customer_id 0)', $order && 0 === (int) $order->get_customer_id() );
	} else {
		WP_CLI::log( 'SKIP: WooCommerce not active — order retention not checked' );
	}

	// --- no secret → 503 ---
	update_option( Lalia_Portal_SSO::OPTION_SECRET, '' );
	lalia_erase_check( 'empty secret → 503 no_secret', $is_error( $call( $good ), 503, 'no_secret' ) );
	update_option( Lalia_Portal_SSO::OPTION_SECRET, $secret );

	// --- logger ---
	Lalia_Portal_SSO_Logger::ensure_table();
	global $wpdb;
	// The later not_found probe logs a success row for the same id too; pin the deletion row.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Lalia_Portal_SSO_Logger::table() . ' WHERE event = %s AND user_id = %d AND status = %s AND message LIKE %s ORDER BY id DESC LIMIT 1', Lalia_Portal_Erasure::LOG_EVENT, $alice, 'success', 'deleted %' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	lalia_erase_check( 'log row for the deletion: event erase, no e-mail retained, jti in message', $row && '' === $row->user_email && false !== strpos( $row->message, 'deleted wp_user_id=' . $alice ) && false !== strpos( $row->message, strtolower( $alice_claims['jti'] ) ) );
	$denied = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Lalia_Portal_SSO_Logger::table() . ' WHERE event = %s AND user_id = %d AND status = %s ORDER BY id DESC LIMIT 1', Lalia_Portal_Erasure::LOG_EVENT, $blog, 'failed' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	lalia_erase_check( 'log row for the refused deletion names the reason', $denied && 0 === strpos( (string) $denied->message, 'protected_role' ) );
} finally {
	update_option( Lalia_Portal_SSO::OPTION_SECRET, $old_secret );
	update_option( Lalia_Portal_Erasure::OPTION_ENABLED, $old_enabled );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created_users as $uid ) {
		if ( get_user_by( 'id', $uid ) ) {
			wp_delete_user( $uid );
		}
	}
	if ( $order && ! is_wp_error( $order ) ) {
		$order->delete( true );
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Lalia_Portal_SSO_Logger::table() . ' WHERE event = %s AND created_at >= %s', Lalia_Portal_Erasure::LOG_EVENT, $started ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lalia_erase_jti_%' OR option_name LIKE '_transient_timeout_lalia_erase_jti_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

WP_CLI::log( "\n$checks checks, $failures failures" );
if ( $failures > 0 ) {
	WP_CLI::error( 'Portal erasure test suite failed' );
}
WP_CLI::success( 'Portal erasure test suite passed' );
