<?php
/**
 * Class: CoCart\Session\Handler.
 *
 * @author  Sébastien Dumont
 * @package CoCart\Classes
 * @since   2.1.0 Introduced.
 * @version 4.0.0
 */

namespace CoCart\Session;

use CoCart\RestApi\Authentication;
use CoCart\Abstracts\Session;
use CoCart\Logger;
use \WC_Customer as Customer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles session data for the cart.
 *
 * Our session handler is forked from "WC_Session_Handler" class with our added
 * variables and adjustments to accommodate the required support for handling
 * customers cart sessions via the REST API for a true headless experience.
 *
 * All native session functionality works as normal on the frontend.
 *
 * @since 2.1.0 Introduced.
 */
class Handler extends Session {

	/**
	 * Cookie name used for the cart.
	 *
	 * @access protected
	 *
	 * @var string cookie name
	 */
	protected $_cookie;

	/**
	 * True when the cookie exists.
	 *
	 * @access protected
	 *
	 * @var bool Based on whether a cookie exists.
	 */
	protected $_has_cookie = false;

	/**
	 * Table name for cart data.
	 *
	 * @access protected
	 *
	 * @var string Custom cart table name
	 */
	protected $_table;

	/**
	 * Constructor for the session class.
	 *
	 * @access public
	 *
	 * @ignore Function ignored when parsed into Code Reference.
	 */
	public function __construct() {
		$this->_cookie = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		$this->_table  = $GLOBALS['wpdb']->prefix . 'cocart_carts';
	}

	/**
	 * Init hooks and cart data.
	 *
	 * @uses Authentication::is_rest_api_request()
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Rest requests don't require the use of cookies.
	 */
	public function init() {
		if ( Authentication::is_rest_api_request() ) {
			$this->_cart_source = 'cocart';

			$this->init_session_cocart();

			$this->set_cart_hash();

			add_action( 'shutdown', array( $this, 'save_cart' ), 20 );
		} else {
			$this->_cart_source = 'woocommerce';

			$this->init_session_cookie();

			add_action( 'woocommerce_set_cart_cookies', array( $this, 'set_customer_session_cookie' ), 10 );
			add_action( 'shutdown', array( $this, 'save_data' ), 20 );
		}

		add_action( 'wp_logout', array( $this, 'destroy_cart' ) );

		/**
		 * When a user is logged out, ensure they have a unique nonce by using the customer ID.
		 *
		 * @since 2.1.2 Introduced.
		 * @since 4.0.0 No longer needed for API requests.
		 */
		if ( ! Authentication::is_rest_api_request() && ! is_user_logged_in() ) {
			add_filter( 'nonce_user_logged_out', array( $this, 'maybe_update_nonce_user_logged_out' ), 10, 2 );
		}
	} // END init()

	/**
	 * Setup cart session.
	 *
	 * This is the native session setup that relies on a cookie.
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Removed parameter $current_user_id
	 */
	public function init_session_cookie() {
		// Get cart cookie... if any.
		$cookie = $this->get_session_cookie();

		// Does a cookie exist?
		if ( $cookie ) {
			// Get cookie details.
			$this->_cart_key        = $cookie[0];
			$this->_cart_expiration = $cookie[1];
			$this->_cart_expiring   = $cookie[2];
			$this->_cart_user_id    = ! empty( $cookie[4] ) ? $cookie[4] : strval( get_current_user_id() );
			$this->_customer_id     = ! empty( $cookie[5] ) ? $cookie[5] : strval( get_current_user_id() );
			$this->_has_cookie      = true;
			$this->_data            = $this->get_cart_data();

			if ( ! $this->is_session_cookie_valid() ) {
				$this->destroy_session();
				$this->set_session_expiration();
			}

			// If the user logged in, update cart.
			if ( is_user_logged_in() && strval( get_current_user_id() ) !== $this->_cart_user_id ) {
				// Generate new cart key after caching old cart key.
				$guest_cart_key  = $this->_cart_key;
				$this->_cart_key = $this->generate_key();

				// Set new user ID.
				$this->_cart_user_id = strval( get_current_user_id() );
				$this->_customer_id  = strval( get_current_user_id() );

				$this->_dirty = true;

				// Save cart data for user and destroy previous cart session.
				$this->save_data( $guest_cart_key );

				// Update customer ID details.
				$this->update_customer_id( $this->_cart_user_id );

				// Set new cookie for cart.
				$this->set_customer_session_cookie( true );
			}

			// Update cart if its close to expiring.
			if ( time() > $this->_cart_expiring ) {
				$this->set_session_expiration();
				$this->update_cart_timestamp( $this->_cart_key, $this->_cart_expiration );
			}
		} else {
			// New guest customer or recover cart session if no cookie.
			$this->_cart_user_id = get_current_user_id();
			$this->_customer_id  = get_current_user_id();
			$this->_cart_key     = $this->_cart_user_id > 0 ? $this->get_cart_key_last_used_by_user_id( $this->_cart_user_id ) : $this->generate_key();

			/*
			 * If cart recovered is not found to be used for managing a cart for a customer on their behalf, i.e. POS
			 * then we need a fresh cart session.
			 */
			if ( ! $this->is_session_controlled_by_user( $this->_cart_key, $this->_cart_user_id ) ) {
				$this->_cart_key = $this->generate_key();
			}

			$this->set_session_expiration();

			$this->_data = $this->get_cart_data();
		}
	} // END init_session_cookie()

	/**
	 * Checks if session cookie is expired, or belongs to a logged out user.
	 *
	 * @access private
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @return bool Whether session cookie is valid.
	 */
	private function is_session_cookie_valid() {
		// If session is expired, session cookie is invalid.
		if ( time() > $this->_cart_expiration ) {
			return false;
		}

		// If user has logged out, session cookie is invalid.
		if ( ! is_user_logged_in() && ! $this->is_customer_guest( $this->_cart_user_id ) ) {
			return false;
		}

		// Session from a different user is not valid. (Although from a guest user will be valid)
		if ( is_user_logged_in() && ! $this->is_customer_guest( $this->_cart_user_id ) && strval( get_current_user_id() ) !== $this->_cart_user_id ) {
			return false;
		}

		return true;
	} // END is_session_cookie_valid()

	/**
	 * Setup cart session.
	 *
	 * Cart session is decoupled without the use of a cookie.
	 *
	 * Supports customers guest and registered. It also allows
	 * administrators to create a cart session and associate a
	 * registered customer.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 */
	public function init_session_cocart() {
		/*
		 * Current user ID. If user is NOT logged in then the customer is a guest.
		 */
		$current_user_id     = get_current_user_id();
		$this->_cart_user_id = $current_user_id > 0 ? $current_user_id : 0;

		/*
		 * Get the cart key by the logged in user ID, if any.
		 */
		$this->_cart_key = $this->_cart_user_id > 0 ? $this->get_cart_key_by_user_id( $this->_cart_user_id ) : '';

		/*
		 * Get customer ID by the logged in user ID, if any.
		 */
		$this->_customer_id = $this->_cart_user_id > 0 ? $this->_cart_user_id : $this->get_customer_id_from_cart_key( $this->_cart_key );

		/*
		 * If the user logged in is not a customer then we either look up a cart
		 * session on behalf of a requested customer or create a new session for them later.
		 */
		if ( ! $this->is_user_customer( $this->_cart_user_id ) && $this->get_requested_customer() > 0 ) {
			$requested_cart     = $this->get_cart_key_for_customer_id( $this->_cart_user_id, $this->get_requested_customer() );
			$this->_cart_key    = ! empty( $requested_cart ) ? $requested_cart : $this->generate_key();
			$this->_customer_id = $this->get_requested_customer();
		}

		if ( ! empty( $this->_cart_key ) ) {
			// Get cart.
			$this->_data = $this->get_cart_data();

			// Update cart if its close to expiring.
			if ( time() > $this->_cart_expiring || empty( $this->_cart_expiring ) ) {
				$this->set_cart_expiration();
				$this->update_cart_timestamp( $this->_cart_key, $this->_cart_expiration );
			}
		} else {
			// New cart session created.
			$this->_cart_key = $this->_cart_user_id > 0 && ! $this->is_user_customer( $this->_cart_user_id ) ? $this->get_cart_key_last_used_by_user_id( $this->_cart_user_id ) : $this->generate_key();
			$this->_data     = $this->get_cart_data();
			$this->set_cart_expiration();
		}
	} // END init_session_cocart()

	/**
	 * Get requested cart.
	 *
	 * Either returns the cart key from the URL or via header.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @return string Cart key.
	 */
	public function get_requested_cart() {
		$cart_key = ''; // Leave blank to start.

		// Are we requesting via url parameter?
		if ( isset( $_REQUEST['cart_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cart_key = (string) trim( sanitize_key( wp_unslash( $_REQUEST['cart_key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Are we requesting via custom header?
		if ( ! empty( $_SERVER['HTTP_COCART_API_CART_KEY'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cart_key = (string) trim( sanitize_key( wp_unslash( $_SERVER['HTTP_COCART_API_CART_KEY'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$cart_key = apply_filters( 'cocart_get_requested_cart', $cart_key );

		return $cart_key;
	} // END get_requested_cart()

	/**
	 * Get requested customer.
	 *
	 * Returns the customer ID via the custom header.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @return int Customer ID.
	 */
	public function get_requested_customer() {
		$customer_id = 0;

		if ( ! empty( $_SERVER['HTTP_COCART_API_CUSTOMER'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$customer_id = (int) trim( sanitize_key( wp_unslash( $_SERVER['HTTP_COCART_API_CUSTOMER'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $customer_id;
	} // END get_requested_customer()

	/**
	 * Is Cookie support enabled?
	 *
	 * Determines if a cookie should manage the cart for customers.
	 *
	 * @access public
	 *
	 * @since      2.1.0 Introduced.
	 * @deprecated 4.0.0 No replacement.
	 *
	 * @return bool
	 */
	public function is_cookie_supported() {
		cocart_do_deprecated_action( 'cocart_cookie_supported', '4.0.0', null, sprintf( __( '%s is no longer used.', 'cart-rest-api-for-woocommerce' ), __FUNCTION__ ) );

		return apply_filters( 'cocart_cookie_supported', true );
	} // END is_cookie_supported()

	/**
	 * Sets the cart cookie on-demand (usually after adding an item to the cart).
	 *
	 * Warning: Cookies will only be set if this is called before the headers are sent.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Added cart user ID and customer ID as additional values at the end.
	 *
	 * @param bool $set Should the cart cookie be set.
	 */
	public function set_customer_cart_cookie( $set = true ) {
		if ( $set ) {
			$to_hash           = $this->_cart_key . '|' . $this->_cart_expiration;
			$cookie_hash       = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value      = $this->_cart_key . '||' . $this->_cart_expiration . '||' . $this->_cart_expiring . '||' . $cookie_hash . '||' . $this->_cart_user_id . '||' . $this->_customer_id;
			$this->_has_cookie = true;

			// If no cookie exists or does not match the value then create a new.
			if ( ! isset( $_COOKIE[ $this->_cookie ] ) || $_COOKIE[ $this->_cookie ] !== $cookie_value ) {
				cocart_setcookie( $this->_cookie, $cookie_value, $this->_cart_expiration, $this->use_secure_cookie(), true );
			}
		}
	} // END set_customer_cart_cookie()

	/**
	 * Backwards compatibility function for setting cart cookie.
	 *
	 * Since the cookie name (as of WooCommerce 2.1) is prepended with wp,
	 * cache systems like batcache will not cache pages when set.
	 *
	 * @access public
	 *
	 * @param bool $set Should the cart cookie be set.
	 *
	 * @since 2.6.0 Introduced.
	 */
	public function set_customer_session_cookie( $set = true ) {
		$this->set_customer_cart_cookie( $set );
	} // END set_customer_session_cookie()

	/**
	 * Returns the cookie name.
	 *
	 * @access public
	 *
	 * @return string
	 */
	public function get_cookie_name() {
		return $this->_cookie;
	} // END get_cookie_name()

	/**
	 * Should the cart cookie be secure?
	 *
	 * @access protected
	 *
	 * @return bool
	 */
	protected function use_secure_cookie() {
		return apply_filters( 'cocart_cart_use_secure_cookie', wc_site_is_https() && is_ssl() );
	} // END use_secure_cookie()

	/**
	 * Set a cookie - wrapper for setcookie using WP constants.
	 *
	 * @access public
	 *
	 * @since      2.1.0 Introduced.
	 * @deprecated 4.0.0 Uses cocart_setcookie() instead.
	 *
	 * @param string  $name     Name of the cookie being set.
	 * @param string  $value    Value of the cookie.
	 * @param integer $expire   Expiry of the cookie.
	 * @param bool    $secure   Whether the cookie should be served only over https.
	 * @param bool    $httponly Whether the cookie is only accessible over HTTP, not scripting languages like JavaScript. @since 2.7.2.
	 */
	public function cocart_setcookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) {
		cocart_deprecated_function( 'CoCart\Session\Handler::cocart_setcookie', '4.0', 'cocart_setcookie' );

		if ( ! headers_sent() ) {
			/**
			 * samesite - Set to None by default and only available to those using PHP 7.3 or above.
			 *
			 * @since 2.9.1.
			 */
			if ( version_compare( PHP_VERSION, '7.3.0', '>=' ) ) {
				setcookie( $name, $value, apply_filters( 'cocart_set_cookie_options', array( 'expires' => $expire, 'secure' => $secure, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'httponly' => apply_filters( 'cocart_cookie_httponly', $httponly, $name, $value, $expire, $secure ), 'samesite' => apply_filters( 'cocart_cookie_samesite', 'Lax' ) ), $name, $value ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			} else {
				setcookie( $name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, apply_filters( 'cocart_cookie_httponly', $httponly, $name, $value, $expire, $secure ) );
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			headers_sent( $file, $line );
			trigger_error( "{$name} cookie cannot be set - headers already sent by {$file} on line {$line}", E_USER_NOTICE ); // @codingStandardsIgnoreLine
		}
	} // END cocart_setcookie()

	/**
	 * Return true if the current customer has an active cart.
	 *
	 * Either a cookie, a user ID or a cart key to retrieve values.
	 *
	 * @access public
	 *
	 * @return bool
	 */
	public function has_session() {
		// Check cookie first for native cart.
		if ( isset( $_COOKIE[ $this->_cookie ] ) || $this->_has_cookie ) {
			return true;
		}

		// Current user ID. If value is above zero then user is logged in.
		$current_user_id = strval( get_current_user_id() );
		if ( is_user_logged_in() || is_numeric( $current_user_id ) && $current_user_id > 0 ) {
			return true;
		}

		// If we are loading a session via REST API then identify cart key.
		if ( ! empty( $this->_cart_key ) && Authentication::is_rest_api_request() ) {
			return true;
		}

		return false;
	} // END has_session()

	/**
	 * Set session expiration.
	 *
	 * PHP session expiration is set to 48 hours by default.
	 *
	 * @access public
	 */
	public function set_session_expiration() {
		$this->_cart_expiring   = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours.
		$this->_cart_expiration = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours.
	} // END set_session_expiration()

	/**
	 * Set cart expiration.
	 *
	 * This session expiration is used for the REST API and is set for 7 days by default.
	 *
	 * @access public
	 */
	public function set_cart_expiration() {
		$this->_cart_expiring   = time() + intval( apply_filters( 'cocart_cart_expiring', DAY_IN_SECONDS * 6 ) ); // 6 Days.
		$this->_cart_expiration = time() + intval( apply_filters( 'cocart_cart_expiration', DAY_IN_SECONDS * 7 ) ); // 7 Days.
	} // END set_cart_expiration()

	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * @uses Handler::generate_key()
	 *
	 * @access public
	 *
	 * @since 2.6.0 Introduced.
	 * @since 4.0.0 Now uses `generate_key()` if customer ID is empty.
	 *
	 * @return string
	 */
	public function generate_customer_id() {
		$customer_id = '';

		$current_user_id = strval( get_current_user_id() );
		if ( is_numeric( $current_user_id ) && $current_user_id > 0 ) {
			$customer_id = $current_user_id;
		}

		if ( empty( $customer_id ) ) {
			$customer_id = $this->generate_key();
		}

		return $customer_id;
	} // END generate_customer_id()

	/**
	 * Generate a unique key.
	 *
	 * Uses Portable PHP password hashing framework to generate a unique cryptographically strong ID.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @return string A unique key.
	 */
	public function generate_key() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';

		$hasher      = new \PasswordHash( 8, false );
		$customer_id = apply_filters( 'cocart_generate_key', md5( $hasher->get_random_bytes( 32 ) ), $hasher );

		return $customer_id;
	} // END generate_key()

	/**
	 * Checks if this is an auto-generated customer ID.
	 *
	 * @access private
	 *
	 * @param string|int $customer_id Customer ID to check.
	 *
	 * @return bool Whether customer ID is randomly generated.
	 */
	private function is_customer_guest( $customer_id ) {
		$customer_id = strval( $customer_id );

		if ( empty( $customer_id ) ) {
			return true;
		}

		// Almost all random $customer_ids will have some letters in it, while all actual ids will be integers.
		if ( strval( (int) $customer_id ) !== $customer_id ) {
			return true;
		}

		// Performance hack to potentially save a DB query, when same user as $customer_id is logged in.
		if ( is_user_logged_in() && strval( get_current_user_id() ) === $customer_id ) {
			return false;
		} else {
			$customer = new Customer( $customer_id );

			if ( 0 === $customer->get_id() ) {
				return true;
			}
		}

		return false;
	} // END is_customer_guest()

	/**
	 * Get session unique ID for requests if session is initialized or user ID if logged in.
	 *
	 * @access public
	 *
	 * @return string
	 */
	public function get_customer_unique_id() {
		$customer_id = '';

		if ( $this->has_session() && $this->_cart_user_id ) {
			$customer_id = $this->_cart_user_id;
		} elseif ( is_user_logged_in() ) {
			$customer_id = (string) get_current_user_id();
		}

		return $customer_id;
	} // END get_customer_unique_id()

	/**
	 * Get the cart cookie, if set. Otherwise return false.
	 *
	 * Cart cookies without a cart key are invalid.
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Added $cart_key, $user_id, $customer_id to return from cookie value.
	 *
	 * @return bool|array
	 */
	public function get_session_cookie() {
		$cookie_value = isset( $_COOKIE[ $this->_cookie ] ) ? wp_unslash( $_COOKIE[ $this->_cookie ] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $cookie_value ) || ! is_string( $cookie_value ) ) {
			return false;
		}

		$parsed_cookie = explode( '||', $cookie_value );

		/*
		 * Check if the cookie value is less than the default WooCommerce normally sets.
		 *
		 * Returns false if the cookie value is missing data CoCart requires for it's session handling.
		 */
		if ( count( $parsed_cookie ) < 6 ) {
			return false;
		}

		list( $cart_key, $cart_expiration, $cart_expiring, $cookie_hash, $user_id, $customer_id ) = $parsed_cookie;

		if ( empty( $cart_key ) ) {
			return false;
		}

		// Validate hash.
		$to_hash = $cart_key . '|' . $cart_expiration;
		$hash    = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

		if ( empty( $cookie_hash ) || ! hash_equals( $hash, $cookie_hash ) ) {
			return false;
		}

		return array( $cart_key, $cart_expiration, $cart_expiring, $cookie_hash, $user_id, $customer_id );
	} // END get_session_cookie()

	/**
	 * Get cart data.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_cart_data() {
		return $this->has_session() ? (array) $this->get_cart( $this->_cart_key ) : array();
	} // END get_cart_data()

	/**
	 * Get session data.
	 *
	 * @access public
	 * @return array
	 */
	public function get_session_data() {
		return $this->get_cart_data();
	} // END get_session_data()

	/**
	 * Gets a cache prefix. This is used in cart names so the entire
	 * cache can be invalidated with 1 function call.
	 *
	 * @access public
	 *
	 * @since   2.1.0 Introduced.
	 * @version 3.0.0
	 *
	 * @return string
	 */
	public function get_cache_prefix() {
		return \WC_Cache_Helper::get_cache_prefix( COCART_CART_CACHE_GROUP );
	} // END get_cache_prefix()

	/**
	 * Save cart data and delete previous cart data.
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Saves the new `cart_user_id` and `cart_customer` data to the cart session.
	 *
	 * @param int $old_cart_key Cart key used before.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function save_cart( $old_cart_key = 0 ) {
		if ( $this->has_session() ) {
			global $wpdb;

			/**
			 * Deprecated filter: `cocart_empty_cart_expiration` as it is no longer needed.
			 *
			 * @deprecated 2.7.2 No replacement.
			 */
			cocart_do_deprecated_action( 'cocart_empty_cart_expiration', '2.7.2', null );

			/**
			 * Checks if data is still validated to create a cart or update a cart in session.
			 *
			 * @since 2.7.2 Introduced.
			 * @since 4.0.0 Replaced `_cart_user_id` with `_cart_key`. Added log error if cart is not valid.
			 */
			$this->_data = $this->is_cart_data_valid( $this->_data, $this->_cart_key );

			if ( ! $this->_data || empty( $this->_data ) || is_null( $this->_data ) ) {
				Logger::log( __( 'Cart data not valid or the session had not loaded during a request. No session saved.', 'cart-rest-api-for-woocommerce' ), 'info' );

				return true;
			}

			/**
			 * Filter source of cart.
			 *
			 * @since 3.0.0 Introduced.
			 *
			 * @param string $cart_source
			 */
			$cart_source = apply_filters( 'cocart_cart_source', $this->_cart_source );

			/**
			 * Set the cart hash.
			 *
			 * @since 3.0.0 Introduced.
			 */
			$this->set_cart_hash();

			// Save or update cart data.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}cocart_carts (`cart_key`, `cart_user_id`, `cart_customer`, `cart_value`, `cart_created`, `cart_expiry`, `cart_source`, `cart_hash`) VALUES (%s, %d, %d, %s, %d, %d, %s, %s)
 					ON DUPLICATE KEY UPDATE `cart_value` = VALUES(`cart_value`), `cart_expiry` = VALUES(`cart_expiry`), `cart_hash` = VALUES(`cart_hash`)",
					$this->_cart_key,
					(int) $this->_cart_user_id,
					(int) $this->_customer_id,
					maybe_serialize( $this->_data ),
					time(),
					(int) $this->_cart_expiration,
					$cart_source,
					$this->_cart_hash
				)
			);

			wp_cache_set( $this->get_cache_prefix() . $this->_cart_key, $this->_data, COCART_CART_CACHE_GROUP, $this->_cart_expiration - time() );

			/**
			 * Fires after cart data is saved.
			 *
			 * @since 4.0.0 Introduced.
			 *
			 * @param int    $cart_key Cart ID.
			 * @param int    $cart_user_id User ID.
			 * @param int    $customer_id Customer ID.
			 * @param array  $data Cart data.
			 * @param int    $cart_expiration Cart expiration.
			 * @param string $cart_source Cart source.
			 */
			do_action( 'cocart_save_cart', $this->_cart_key, $this->_cart_user_id, $this->_customer_id, $this->_data, $this->_cart_expiration, $cart_source );

			$this->_dirty = false;

			// Previous cart session is no longer used so we delete it to prevent duplication.
			if ( $this->_cart_key !== $old_cart_key ) {
				$this->delete_cart( $old_cart_key );
			}
		}
	} // END save_cart()

	/**
	 * Backwards compatibility for other plugins to
	 * save data and delete guest session.
	 *
	 * @access public
	 *
	 * @since 3.0.13 Introduced.
	 * @since 4.0.0 Added dirty when the session needs saving.
	 *
	 * @param int $old_session_key session ID before user logs in.
	 */
	public function save_data( $old_session_key = 0 ) {
		if ( $this->_dirty && $this->has_session() ) {
			$this->save_cart( $old_session_key );
		}
	} // END save_data()

	/**
	 * Destroy all cart data.
	 *
	 * @access public
	 */
	public function destroy_cart() {
		$this->delete_cart( $this->_cart_key );
		$this->forget_cart();
	} // END destroy_cart()

	/**
	 * Backwards compatibility for other plugins to
	 * destroy all session data.
	 *
	 * @access public
	 *
	 * @since 3.0.13 Introduced.
	 */
	public function destroy_session() {
		$this->destroy_cart();
	} // END destroy_session()

	/**
	 * Destroy cart cookie.
	 *
	 * @access public
	 *
	 * @since 3.0.0 Introduced.
	 * @since 4.0.0 Use cocart_setcookie() to set cookie instead.
	 */
	public function destroy_cookie() {
		cocart_setcookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, $this->use_secure_cookie(), true );
	} // END destroy_cookie()

	/**
	 * Forget all cart data without destroying it.
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Added default values for `_cart_user_id` and `_customer_id`.
	 */
	public function forget_cart() {
		if ( ! is_admin() ) {
			include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
		}

		// Empty cart.
		if ( function_exists( 'wc_empty_cart' ) ) {
			wc_empty_cart();
		}

		$this->_data         = array();
		$this->_cart_key     = $this->generate_key();
		$this->_cart_user_id = 0;
		$this->_customer_id  = 0;
	} // END forget_cart()

	/**
	 * Backwards compatibility for other plugins to
	 * forget cart data without destroying it.
	 *
	 * @access public
	 *
	 * @since 3.0.0 Introduced.
	 */
	public function forget_session() {
		$this->destroy_cookie();

		$this->forget_cart();

		$this->_dirty = false;
	} // END forget_session()

	/**
	 * When a user is logged out, ensure they have a unique nonce by using the user ID.
	 *
	 * @access public
	 *
	 * @since      2.1.2 Introduced.
	 * @deprecated 4.0.0 Use CoCart\Session\Handler::maybe_update_nonce_user_logged_out() instead.
	 *
	 * @param int $uid User ID.
	 *
	 * @return string
	 */
	public function nonce_user_logged_out( $uid ) {
		cocart_deprecated_function( 'CoCart\Session\Handler::nonce_user_logged_out', '4.0', 'CoCart\Session\Handler::maybe_update_nonce_user_logged_out' );

		return $this->has_session() && $this->_cart_user_id ? $this->_cart_user_id : $uid;
	} // END nonce_user_logged_out()

	/**
	 * When a user is logged out, ensure they have a unique nonce to manage cart and more using the customer/session ID.
	 * This filter runs everything `wp_verify_nonce()` and `wp_create_nonce()` gets called.
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int    $uid    User ID.
	 * @param string $action The nonce action.
	 *
	 * @return int|string
	 */
	public function maybe_update_nonce_user_logged_out( $uid, $action ) {
		if ( \Automattic\WooCommerce\Utilities\StringUtil::starts_with( $action, 'woocommerce' ) ) {
			return $this->has_session() && $this->_cart_user_id ? $this->_cart_user_id : $uid;
		}

		return $uid;
	} // END maybe_update_nonce_user_logged_out()

	/**
	 * Cleanup cart data from the database and clear caches.
	 *
	 * @access public
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function cleanup_sessions() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE cart_expiry < %d", time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Invalidate cache group.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( COCART_CART_CACHE_GROUP );
		}
	} // END cleanup_sessions()

	/**
	 * Returns the cart.
	 *
	 * @access public
	 *
	 * @param string $cart_key The cart key.
	 * @param mixed  $default  Default cart value.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string|array
	 */
	public function get_cart( string $cart_key, $default = false ) {
		global $wpdb;

		// Try to get it from the cache, it will return false if not present or if object cache not in use.
		$value = wp_cache_get( $this->get_cache_prefix() . $cart_key, COCART_CART_CACHE_GROUP );

		if ( false === $value ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT cart_value FROM $this->_table WHERE cart_key = %s", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( is_null( $value ) ) {
				$value = $default;
			}

			$cache_duration = $this->_cart_expiration - time();
			if ( 0 < $cache_duration ) {
				wp_cache_add( $this->get_cache_prefix() . $cart_key, $value, COCART_CART_CACHE_GROUP, $cache_duration );
			}
		}

		return maybe_unserialize( $value );
	} // END get_cart()

	/**
	 * Returns the session.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param string $cart_key The cart key.
	 * @param mixed  $default  Default cart value.
	 *
	 * @return string|array
	 */
	public function get_session( $cart_key, $default = false ) {
		return $this->get_cart( $cart_key, $default );
	} // END get_session()

	/**
	 * Returns the timestamp the cart was created.
	 *
	 * @access public
	 *
	 * @since      3.1.0 Introduced.
	 * @deprecated 4.0.0 Uses cocart_get_timestamp() instead.
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string
	 */
	public function get_cart_created( $cart_key ) {
		cocart_deprecated_function( 'CoCart\Session\Handler::get_cart_created', '4.0', 'cocart_get_timestamp' );

		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT cart_created FROM $this->_table WHERE cart_key = %s", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $value;
	} // END get_cart_created()

	/**
	 * Returns the timestamp the cart expires.
	 *
	 * @access public
	 *
	 * @since      3.1.0 Introduced.
	 * @deprecated 4.0.0 Uses cocart_get_timestamp() instead.
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.s
	 *
	 * @return string
	 */
	public function get_cart_expiration( $cart_key ) {
		cocart_deprecated_function( 'CoCart\Session\Handler::get_cart_expiration', '4.0', 'cocart_get_timestamp' );

		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT cart_expiry FROM $this->_table WHERE cart_key = %s", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $value;
	} // END get_cart_expiration()

	/**
	 * Returns the source of the cart.
	 *
	 * @access public
	 *
	 * @since      3.1.0 Introduced.
	 * @deprecated 4.0.0 Uses cocart_get_source() instead.
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string
	 */
	public function get_cart_source( $cart_key ) {
		cocart_deprecated_function( 'CoCart\Session\Handler::get_cart_source', '4.0', 'cocart_get_source' );

		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT cart_source FROM $this->_table WHERE cart_key = %s", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $value;
	} // END get_cart_source()

	/**
	 * Create a blank new cart and returns cart key if successful.
	 *
	 * @uses Handler::generate_key()
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 * @since 4.0.0 Added cart customer.
	 *
	 * @param string $cart_key        The cart key passed to create the cart.
	 * @param int    $cart_customer   The customer ID.
	 * @param array  $cart_value      The cart data.
	 * @param int    $cart_expiration Timestamp of cart expiration.
	 * @param string $cart_source     Cart source.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return $cart_key The cart key if successful, false otherwise.
	 */
	public function create_new_cart( $cart_key = '', $cart_customer = 0, $cart_value = array(), $cart_expiration = '', $cart_source = '' ) {
		global $wpdb;

		if ( empty( $cart_key ) ) {
			$cart_key = $this->generate_key();
		}

		if ( empty( $cart_expiration ) ) {
			$cart_expiration = time() + intval( apply_filters( 'cocart_cart_expiring', DAY_IN_SECONDS * 7 ) );
		}

		if ( empty( $cart_source ) ) {
			$cart_source = apply_filters( 'cocart_cart_source', $this->_cart_source );
		}

		$result = $wpdb->insert(
			$this->_table,
			array(
				'cart_key'      => $cart_key,
				'cart_user_id'  => (int) $cart_customer,
				'cart_customer' => (int) $cart_customer,
				'cart_value'    => maybe_serialize( $cart_value ),
				'cart_created'  => time(),
				'cart_expiry'   => (int) $cart_expiration,
				'cart_source'   => $cart_source,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%d', '%s' )
		);

		// Returns the cart key if cart successfully created.
		if ( $result ) {
			return $cart_key;
		}
	} // END create_new_cart()

	/**
	 * Update cart.
	 *
	 * @access public
	 *
	 * @param string $cart_key Cart to update.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function update_cart( $cart_key ) {
		global $wpdb;

		$wpdb->update(
			$this->_table,
			array(
				'cart_value'  => maybe_serialize( $this->_data ),
				'cart_expiry' => (int) $this->_cart_expiration,
			),
			array( 'cart_key' => $cart_key ),
			array( '%s', '%d' ),
			array( '%s' )
		);
	} // END update_cart()

	/**
	 * Delete the cart from the cache and database.
	 *
	 * @access public
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function delete_cart( $cart_key ) {
		global $wpdb;

		// Delete cache.
		wp_cache_delete( $this->get_cache_prefix() . $cart_key, COCART_CART_CACHE_GROUP );

		// Delete cart from database.
		$wpdb->delete( $this->_table, array( 'cart_key' => $cart_key ), array( '%s' ) );
	} // END delete_cart()

	/**
	 * Update the cart expiry timestamp.
	 *
	 * @access public
	 *
	 * @param string $cart_key  The cart key.
	 * @param int    $timestamp Timestamp to expire the cookie.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function update_cart_timestamp( $cart_key, $timestamp ) {
		global $wpdb;

		$wpdb->update(
			$this->_table,
			array( 'cart_expiry' => $timestamp ),
			array( 'cart_key' => $cart_key ),
			array( '%d' ),
			array( '%s' )
		);
	} // END update_cart_timestamp()

	/**
	 * Checks if data is still validated to create a cart or update a cart in session.
	 *
	 * @access protected
	 *
	 * @since 2.7.2 Introduced.
	 * @since 4.0.0 Added $cart_key parameter.
	 *
	 * @param array  $data     The cart data to validate.
	 * @param string $cart_key The cart key.
	 *
	 * @return array $data Returns the original cart data or a boolean value.
	 */
	protected function is_cart_data_valid( $data, $cart_key ) {
		if ( ! empty( $data ) && empty( $this->get_cart( $cart_key ) ) ) {
			// If the cart value is empty then the cart data is not valid.
			if ( ! isset( $data['cart'] ) || empty( maybe_unserialize( $data['cart'] ) ) ) {
				$data = false;
			}
		}

		$data = apply_filters( 'cocart_is_cart_data_valid', $data );

		return $data;
	} // END is_cart_data_valid()

	/**
	 * Whether the cookie is only accessible over HTTP.
	 * Returns true by default for the frontend and false by default via the REST API.
	 *
	 * @uses Authentication::is_rest_api_request()
	 *
	 * @access protected
	 *
	 * @since 2.7.2 Introduced.
	 * @deprecated 4.0.0 No longer used.
	 *
	 * @return boolean
	 */
	protected function use_httponly() {
		cocart_deprecated_function( 'CoCart\Session\Handler::use_httponly', '4.0' );

		$httponly = true;

		if ( Authentication::is_rest_api_request() ) {
			$httponly = false;
		}

		return $httponly;
	} // END use_httponly()

	/**
	 * Set the cart hash based on the carts contents and total.
	 *
	 * @access public
	 *
	 * @since   3.0.0 Introduced.
	 * @version 3.0.3
	 */
	public function set_cart_hash() {
		$cart_session = $this->get( 'cart' );
		$cart_totals  = $this->get( 'cart_totals' );

		$cart_total = isset( $cart_totals ) ? maybe_unserialize( $cart_totals ) : array( 'total' => 0 );
		$hash       = ! empty( $cart_session ) ? md5( wp_json_encode( $cart_session ) . $cart_total['total'] ) : '';

		$this->_cart_hash = $hash;
	} // END set_cart_hash()

	/**
	 * Get the session table name.
	 *
	 * @access public
	 *
	 * @since 3.0.0 Introduced.
	 */
	public function get_table_name() {
		return $this->_table;
	} // END get_table_name()

	/**
	 * Get the user ID by looking up the cart key.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return bool|int Returns the user ID or false if not found.
	 */
	public function get_user_id_by_cart_key( $cart_key ) {
		global $wpdb;

		$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT cart_user_id FROM $this->_table WHERE cart_key = %d", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_null( $user_id ) ) {
			return false;
		}

		return $user_id;
	} // END get_user_id_by_cart_key()

	/**
	 * Get the customer ID by looking up the cart key.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param string $cart_key The cart key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return int Returns the customer ID.
	 */
	public function get_customer_id_from_cart_key( $cart_key ) {
		// If no cart key provided then we can skip the DB look up.
		if ( empty( $cart_key ) ) {
			return 0;
		}

		global $wpdb;

		$customer_id = $wpdb->get_var( $wpdb->prepare( "SELECT cart_customer FROM $this->_table WHERE cart_key = %s", $cart_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Return zero if we can't find an ID.
		if ( is_null( $customer_id ) ) {
			return 0;
		}

		return $customer_id;
	} // END get_customer_id_from_cart_key()

	/**
	 * Get the cart key by looking up the user ID.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return bool|string Returns the cart key or false if not found.
	 */
	public function get_cart_key_by_user_id( $user_id = 0 ) {
		if ( ! is_int( $user_id ) || $user_id === 0 ) {
			return false;
		}

		global $wpdb;

		$cart_key = $wpdb->get_var( $wpdb->prepare( "SELECT cart_key FROM $this->_table WHERE cart_user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_null( $cart_key ) ) {
			return false;
		}

		return $cart_key;
	} // END get_cart_key_by_user_id()

	/**
	 * Get the cart key last used by the user ID.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return bool|string Returns the cart key or false if no user provided.
	 */
	public function get_cart_key_last_used_by_user_id( $user_id = 0 ) {
		if ( ! is_int( $user_id ) || $user_id === 0 ) {
			return false;
		}

		global $wpdb;

		$cart_key = $wpdb->get_var( $wpdb->prepare( "SELECT cart_key FROM $this->_table WHERE cart_user_id = %d ORDER BY cart_expiry DESC", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// If no previous cart exists then provide a new cart key.
		if ( is_null( $cart_key ) ) {
			$cart_key = $this->generate_key();
		}

		return $cart_key;
	} // END get_cart_key_last_used_by_user_id()

	/**
	 * Get the cart key by looking up the customer ID managed by user.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int $user_id     The user ID who is managing the customer.
	 * @param int $customer_id The customer ID.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return bool|string Returns the cart key or false if not found.
	 */
	protected function get_cart_key_for_customer_id( $user_id = 0, $customer_id = 0 ) {
		if ( ! is_int( $user_id ) || $user_id === 0 ) {
			return false;
		}

		if ( ! is_int( $customer_id ) || $customer_id === 0 ) {
			return false;
		}

		global $wpdb;

		$cart_key = $wpdb->get_var( $wpdb->prepare( "SELECT cart_key FROM $this->_table WHERE cart_user_id = %d AND cart_customer = %d", $user_id, $customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_null( $cart_key ) ) {
			return false;
		}

		return $cart_key;
	} // END get_cart_key_for_customer_id()

	/**
	 * Purpose of this function is to check if the session can be controlled
	 * natively by the current user logged in.
	 *
	 * This is only required for WordPress origin, not the REST API.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param string $cart_key Cart key to validate with.
	 * @param int    $user_id  User ID to identify control of session.
	 *
	 * @return bool True|False
	 */
	protected function is_session_controlled_by_user( $cart_key = '', $user_id = 0 ) {
		if ( empty( $cart_key ) ) {
			return false;
		}

		if ( ! is_int( $user_id ) || $user_id === 0 ) {
			return false;
		}

		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( "SELECT cart_key FROM $this->_table WHERE cart_key = %s AND cart_customer = %d", $cart_key, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_null( $result ) ) {
			return false;
		}

		return true;
	} // is_session_controlled_by_user()

	/**
	 * Updates the customer ID to the cart.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int $customer_id The customer ID.
	 *
	 * @ignore Function ignored when parsed into Code Reference.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	protected function update_customer_id( int $customer_id = 0 ) {
		global $wpdb;

		if ( empty( $customer_id ) || $customer_id === 0 ) {
			$customer_id = $this->_customer_id;
		}

		// If customer ID is not an integer then we can't update the cart.
		if ( ! is_int( $customer_id ) ) {
			return;
		}

		$wpdb->update(
			$this->_table,
			array( 'cart_customer' => $customer_id ),
			array( 'cart_key' => $this->_cart_key ),
			array( '%d' ),
			array( '%s' )
		);
	} // END update_customer_id()

	/**
	 * Detect if the user is a customer.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return bool Returns true if user is a customer, otherwise false.
	 */
	public function is_user_customer( $user_id ) {
		if ( ! is_int( $user_id ) || $user_id === 0 ) {
			return false;
		}

		$current_user = get_userdata( $user_id );

		if ( ! empty( $current_user ) ) {
			$user_roles = $current_user->roles;

			if ( in_array( 'customer', $user_roles ) ) {
				return true;
			}
		}

		return false;
	} // END is_user_customer()

} // END class
