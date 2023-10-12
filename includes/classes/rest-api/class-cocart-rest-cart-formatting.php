<?php
/**
 * REST API: CoCart\RestApi\CartFormatting
 *
 * @author  Sébastien Dumont
 * @package CoCart\RestApi
 * @since   3.0.0 Introduced.
 * @version 4.0.0
 */

namespace CoCart\RestApi;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles cart response formatting.
 *
 * @since 3.0.0 Introduced.
 */
class CartFormatting {

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @ignore Function ignored when parsed into Code Reference.
	 */
	public function __construct() {
		// Returns the cart contents without the cart item key as the parent array.
		add_filter( 'cocart_cart', array( $this, 'remove_items_parent_item_key' ), 0 );
		add_filter( 'cocart_cart', array( $this, 'remove_removed_items_parent_item_key' ), 0 );
		add_filter( 'cocart_session', array( $this, 'remove_items_parent_item_key' ), 0 );
		add_filter( 'cocart_session', array( $this, 'remove_removed_items_parent_item_key' ), 0 );

		// Format money values after giving 3rd party plugins or extensions a chance to manipulate them first.
		add_filter( 'cocart_cart_item_price', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 4 );
		add_filter( 'cocart_cart_item_subtotal', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 4 );
		add_filter( 'cocart_cart_item_subtotal_tax', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 4 );
		add_filter( 'cocart_cart_item_total', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 4 );
		add_filter( 'cocart_cart_item_tax', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 4 );
		add_filter( 'cocart_cart_totals_taxes_total', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 2 );
		add_filter( 'cocart_cart_cross_item_price', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 2 );
		add_filter( 'cocart_cart_cross_item_regular_price', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 2 );
		add_filter( 'cocart_cart_cross_item_sale_price', array( 'CoCart\Utilities\MonetaryFormatting', 'return_monetary_value' ), 99, 2 );
		add_filter( 'cocart_cart_fee_amount', array( 'CoCart\Utilities\MonetaryFormatting', 'format_money' ), 99, 2 );
		add_filter( 'cocart_cart_tax_line_amount', array( 'CoCart\Utilities\MonetaryFormatting', 'format_money' ), 99, 2 );
		add_filter( 'cocart_cart_totals', array( 'CoCart\Utilities\MonetaryFormatting', 'format_totals' ), 99, 2 );
		add_filter( 'cocart_session_totals', array( 'CoCart\Utilities\MonetaryFormatting', 'format_totals' ), 99, 2 );

		// Remove any empty cart item data objects.
		add_filter( 'cocart_cart_item_data', array( $this, 'clean_empty_cart_item_data' ), 0 );
	} // END __construct()

	/**
	 * Returns the cart contents without the cart item key as the parent array.
	 *
	 * @access public
	 *
	 * @since   3.0.0 Introduced.
	 * @version 3.1.0
	 *
	 * @param array $cart The cart data before modifying.
	 *
	 * @return array $cart The cart data after modifying.
	 */
	public function remove_items_parent_item_key( $cart ) {
		if ( isset( $cart['items'] ) ) {
			$new_items = array();

			foreach ( $cart['items'] as $item_key => $cart_item ) {
				$new_items[] = $cart_item;
			}

			// Override items returned.
			$cart['items'] = $new_items;
		}

		return $cart;
	} // END remove_items_parent_item_key()

	/**
	 * Returns the removed cart contents without the cart item key as the parent array.
	 *
	 * @access public
	 *
	 * @since   3.0.0 Introduced.
	 * @version 3.1.0
	 *
	 * @param array $cart The cart data before modifying.
	 *
	 * @return array $cart The cart data after modifying.
	 */
	public function remove_removed_items_parent_item_key( $cart ) {
		if ( isset( $cart['removed_items'] ) ) {
			$new_items = array();

			foreach ( $cart['removed_items'] as $item_key => $cart_item ) {
				$new_items[] = $cart_item;
			}

			// Override removed items returned.
			$cart['removed_items'] = $new_items;
		}

		return $cart;
	} // END remove_removed_items_parent_item_key()

	/**
	 * Remove any empty cart item data objects.
	 *
	 * @access public
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @param array $cart_item_data Cart item data before.
	 *
	 * @return array $cart_item_data Cart item data after.
	 */
	public function clean_empty_cart_item_data( $cart_item_data ) {
		foreach ( $cart_item_data as $item => $data ) {
			if ( empty( $data ) ) {
				unset( $cart_item_data[ $item ] );
			}
		}

		return $cart_item_data;
	} // END clean_empty_cart_item_data()

} // END class.

return new CartFormatting();
