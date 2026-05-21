<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCSingleItemCart' ) ) {
	class WCSingleItemCart {
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ) );
		}

		public function init() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			add_filter( 'woocommerce_is_sold_individually', array( $this, 'force_sold_individually' ), 10, 2 );
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'maybe_replace_existing_item_on_add' ), 10, 5 );
			add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'normalize_cart_to_single_item' ), 99 );
			add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'reset_quantity_to_one' ), 10, 4 );
		}

		public function force_sold_individually( $sold_individually, $product ) {
			return true;
		}

		public function maybe_replace_existing_item_on_add( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
			if ( ! $passed ) {
				return $passed;
			}
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return $passed;
			}
			if ( WC()->cart->is_empty() ) {
				return $passed;
			}
			$incoming_product_id   = (int) $product_id;
			$incoming_variation_id = (int) $variation_id;
			$cart_contains_same    = false;
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$current_product_id    = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				$current_variation_id  = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
				if ( $current_product_id === $incoming_product_id && $current_variation_id === $incoming_variation_id ) {
					$cart_contains_same = true;
					break;
				}
			}
			if ( ! $cart_contains_same ) {
				WC()->cart->empty_cart();
			}
			return $passed;
		}

		public function normalize_cart_to_single_item() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}
			$keep_first_key = null;
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( null === $keep_first_key ) {
					$keep_first_key = $cart_item_key;
					if ( isset( WC()->cart->cart_contents[ $cart_item_key ]['quantity'] ) ) {
						WC()->cart->set_quantity( $cart_item_key, 1, false );
					}
					continue;
				}
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

		public function reset_quantity_to_one( $cart_item_key, $quantity, $old_quantity, $cart ) {
			if ( $quantity !== 1 ) {
				$cart->set_quantity( $cart_item_key, 1, false );
			}
		}
	}

	new WCSingleItemCart();
}


