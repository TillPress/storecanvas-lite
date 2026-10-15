<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart item data + order item meta + option pricing + validation.
 */
class SCLT_Cart_Order {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		// Order confirmation, emails, My Account: show the customer's choices ("Sign wording: OPEN LATE")
		// instead of raw StoreCanvas Lite meta such as sclt_price_extra.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'format_order_item_meta' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'to_order_item' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_order_preview' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'set_cart_item_prices' ), 20, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'from_session' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'revalidate_cart_totals' ), 5, 1 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_option_stock' ), 6 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_quantity' ), 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_option_stock' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_cart_item', array( $this, 'validate_store_api_cart_item' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'decrement_option_stock' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'decrement_option_stock' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'decrement_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'on_create_refund' ), 20, 2 );
		add_action( 'woocommerce_restock_refunded_item', array( $this, 'on_restock_refunded_item' ), 10, 5 );
	}

	/**
	 * Cart items currently in the WooCommerce cart, or empty when WC is unavailable.
	 *
	 * @return array
	 */
	public static function current_cart_items() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woocommerce || empty( $woocommerce->cart ) || ! is_callable( array( $woocommerce->cart, 'get_cart' ) ) ) {
			return array();
		}
		return (array) $woocommerce->cart->get_cart();
	}

	/**
	 * Inventory owner key. Group fields share stock across products assigned to that group.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $field      Field definition.
	 * @param string $choice     Choice value.
	 * @return string
	 */
	public static function stock_owner_key( $product_id, $field, $choice ) {
		$fid = sanitize_key( (string) ( $field['id'] ?? '' ) );
		$cv  = (string) $choice;
		$gid = isset( $field['_sc_group_id'] ) ? (int) $field['_sc_group_id'] : 0;
		if ( $gid > 0 ) {
			return 'g:' . $gid . ':' . $fid . ':' . $cv;
		}
		return 'p:' . (int) $product_id . ':' . $fid . ':' . $cv;
	}

	/**
	 * Demand rows for one cart/order line. Unlimited-stock choices are omitted.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $options    Chosen options.
	 * @param int   $quantity   Line quantity.
	 * @param array $fields     Optional field list.
	 * @return array
	 */
	public static function demand_from_options( $product_id, $options, $quantity, $fields = null ) {
		$qty    = max( 1, (int) $quantity );
		$demand = array();
		if ( ! is_array( $options ) || ! $options ) {
			return $demand;
		}
		if ( null === $fields ) {
			$config = class_exists( 'SCLT_Product_Options' ) ? SCLT_Product_Options::get_config( $product_id ) : array();
			$fields = $config['fields'] ?? array();
		}
		$by_id = array();
		foreach ( (array) $fields as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$by_id[ $f['id'] ] = $f;
			}
		}
		foreach ( $options as $fid => $val ) {
			if ( ! isset( $by_id[ $fid ] ) ) {
				continue;
			}
			$field = $by_id[ $fid ];
			$vals  = is_array( $val ) ? $val : array( $val );
			foreach ( $vals as $one ) {
				if ( '' === (string) $one ) {
					continue;
				}
				$stock = SCLT_Product_Options::choice_stock( $field, $one );
				if ( null === $stock ) {
					continue;
				}
				$key = self::stock_owner_key( $product_id, $field, $one );
				if ( ! isset( $demand[ $key ] ) ) {
					$demand[ $key ] = array(
						'need'         => 0,
						'have'         => (int) $stock,
						'label'        => (string) ( $field['label'] ?? $fid ),
						'choice_label' => SCLT_Product_Options::format_value_label( $field, $one ),
					);
				}
				$demand[ $key ]['need'] += $qty;
			}
		}
		return $demand;
	}

	/**
	 * @param array $base Existing demand.
	 * @param array $add  Additional demand.
	 * @return array
	 */
	public static function merge_stock_demand( $base, $add ) {
		foreach ( (array) $add as $k => $row ) {
			if ( ! isset( $base[ $k ] ) ) {
				$base[ $k ] = $row;
				continue;
			}
			$base[ $k ]['need'] += (int) ( $row['need'] ?? 0 );
		}
		return $base;
	}

	/**
	 * @param array       $cart_items  Cart items.
	 * @param int         $add_pid     Product being added.
	 * @param array       $add_options Options for the added line.
	 * @param int         $add_qty     Qty for the added/updated line.
	 * @param string|null $exclude_key Cart item key to skip.
	 * @return array
	 */
	public static function aggregated_option_stock_demand( $cart_items, $add_pid = 0, $add_options = array(), $add_qty = 0, $exclude_key = null ) {
		$demand = array();
		foreach ( (array) $cart_items as $key => $item ) {
			if ( null !== $exclude_key && (string) $key === (string) $exclude_key ) {
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid  = (int) ( $item['product_id'] ?? 0 );
			$opts = isset( $item[ SCLT_Plugin::CART_OPTIONS ] ) && is_array( $item[ SCLT_Plugin::CART_OPTIONS ] ) ? $item[ SCLT_Plugin::CART_OPTIONS ] : array();
			$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$demand = self::merge_stock_demand( $demand, self::demand_from_options( $pid, $opts, $qty ) );
		}
		if ( $add_pid && $add_qty > 0 ) {
			$demand = self::merge_stock_demand( $demand, self::demand_from_options( $add_pid, $add_options, $add_qty ) );
		}
		return $demand;
	}

	/**
	 * @param array $demand Demand map.
	 * @return string
	 */
	public static function first_stock_shortfall( $demand ) {
		foreach ( (array) $demand as $row ) {
			$need = (int) ( $row['need'] ?? 0 );
			$have = (int) ( $row['have'] ?? 0 );
			if ( $need > $have ) {
				return sprintf(
					/* translators: 1: field 2: choice 3: remaining */
					__( '“%1$s” option “%2$s” has insufficient stock (remaining: %3$d).', 'storecanvas-lite' ),
					$row['label'] ?? '',
					$row['choice_label'] ?? '',
					$have
				);
			}
		}
		return '';
	}

	/**
	 * @param array       $cart_items  Cart.
	 * @param int         $add_pid     Added product.
	 * @param array       $add_options Added options.
	 * @param int         $add_qty     Added qty.
	 * @param string|null $exclude_key Skip key.
	 * @return string
	 */
	public static function validate_aggregated_option_stock( $cart_items, $add_pid = 0, $add_options = array(), $add_qty = 0, $exclude_key = null ) {
		$demand = self::aggregated_option_stock_demand( $cart_items, $add_pid, $add_options, $add_qty, $exclude_key );
		return self::first_stock_shortfall( $demand );
	}

	/**
	 * @param bool   $passed        Current result.
	 * @param string $cart_item_key Cart key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      New qty.
	 * @return bool
	 */
	public function validate_cart_quantity( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}
		$opts = isset( $values[ SCLT_Plugin::CART_OPTIONS ] ) && is_array( $values[ SCLT_Plugin::CART_OPTIONS ] ) ? $values[ SCLT_Plugin::CART_OPTIONS ] : array();
		$pid  = (int) ( $values['product_id'] ?? 0 );
		$err  = self::validate_aggregated_option_stock( self::current_cart_items(), $pid, $opts, $quantity, $cart_item_key );
		if ( $err ) {
			wc_add_notice( $err, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Checkout / cart-page stock revalidation.
	 */
	public function validate_cart_option_stock() {
		$err = self::validate_aggregated_option_stock( self::current_cart_items() );
		if ( $err ) {
			wc_add_notice( $err, 'error' );
		}
	}

	/**
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public function validate_checkout_option_stock( $data, $errors ) {
		$err = self::validate_aggregated_option_stock( self::current_cart_items() );
		if ( $err && is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'sclt_option_stock', $err );
		}
	}

	/**
	 * @param array $cart_item Cart item.
	 */
	public function validate_store_api_cart_item( $cart_item ) {
		$err = self::validate_aggregated_option_stock( self::current_cart_items() );
		if ( ! $err ) {
			return;
		}
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sclt_option_stock', esc_html( $err ), 400 );
		}
		wc_add_notice( $err, 'error' );
	}

	/**
	 * Raw submitted option values for an add-to-cart request (unsanitized; callers
	 * sanitize per field). File-type fields arrive in $_FILES, so the uploaded file's
	 * name is merged in under its field id. Only the sanitized file name is kept; the
	 * uploaded file itself is never moved, stored or read.
	 *
	 * @return array Field id => value.
	 */
	private static function posted_option_values() {
		$posted = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart request.
		if ( isset( $_POST['sclt_option'] ) && is_array( $_POST['sclt_option'] ) ) {
			$posted = wp_unslash( $_POST['sclt_option'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- sanitized per field by callers.
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart request.
		if ( isset( $_FILES['sclt_option']['name'] ) && is_array( $_FILES['sclt_option']['name'] ) ) {
			$errors = isset( $_FILES['sclt_option']['error'] ) && is_array( $_FILES['sclt_option']['error'] ) ? $_FILES['sclt_option']['error'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- integers compared below.
			foreach ( $_FILES['sclt_option']['name'] as $fid => $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- sanitized below.
				if ( ! is_string( $name ) || '' === $name || UPLOAD_ERR_NO_FILE === (int) ( $errors[ $fid ] ?? UPLOAD_ERR_NO_FILE ) ) {
					continue;
				}
				$posted[ $fid ] = sanitize_file_name( wp_basename( $name ) );
			}
		}
		return is_array( $posted ) ? $posted : array();
	}

	/**
	 * Validate required options + limits + stock + variation targeting.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore
		$options      = array();
		foreach ( self::posted_option_values() as $fid => $val ) {
			$fid             = sanitize_key( $fid );
			$options[ $fid ] = is_array( $val )
				? array_map( 'sanitize_text_field', $val )
				: sanitize_text_field( $val );
		}

		$config = SCLT_Product_Options::get_config( $product_id );
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( empty( $field['id'] ) || 'heading' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( ! SCLT_Product_Options::user_can_see_field( $field ) ) {
				continue;
			}
			if ( ! SCLT_Product_Options::field_matches_variation( $field, $variation_id ) ) {
				continue;
			}
			// Build temp options for show_if with this field's submitted value.
			if ( ! SCLT_Product_Options::field_is_visible( $field, $options ) ) {
				continue;
			}

			$fid  = $field['id'];
			$val  = $options[ $fid ] ?? '';
			$lab  = $field['label'] ?? $fid;
			$type = $field['type'] ?? 'text';

			$shape = self::validate_option_value( $field, $val );
			if ( true !== $shape ) {
				wc_add_notice( $shape, 'error' );
				$passed = false;
				continue;
			}

			// Required.
			if ( ! empty( $field['required'] ) ) {
				$empty = ( is_array( $val ) && ! array_filter( $val, 'strlen' ) ) || ( ! is_array( $val ) && '' === (string) $val );
				if ( 'checkbox' === $type ) {
					$empty = empty( $val );
				}
				if ( $empty ) {
					/* translators: %s: field label */
					wc_add_notice( sprintf( __( '“%s” is required.', 'storecanvas-lite' ), $lab ), 'error' );
					$passed = false;
					continue;
				}
			}

			// Char limits.
			if ( in_array( $type, SCLT_Product_Options::text_like_types(), true ) && is_string( $val ) && '' !== $val ) {
				$len = strlen( $val );
				if ( isset( $field['min_chars'] ) && $len < (int) $field['min_chars'] ) {
					/* translators: 1: field label 2: minimum characters */
					wc_add_notice( sprintf( __( '“%1$s” must be at least %2$d characters.', 'storecanvas-lite' ), $lab, (int) $field['min_chars'] ), 'error' );
					$passed = false;
				}
				if ( isset( $field['max_chars'] ) && $len > (int) $field['max_chars'] ) {
					/* translators: 1: field label 2: maximum characters */
					wc_add_notice( sprintf( __( '“%1$s” must be at most %2$d characters.', 'storecanvas-lite' ), $lab, (int) $field['max_chars'] ), 'error' );
					$passed = false;
				}
			}

			// Number min/max.
			if ( 'number' === $type && '' !== (string) $val && is_numeric( $val ) ) {
				$n = (float) $val;
				if ( isset( $field['min'] ) && $n < (float) $field['min'] ) {
					/* translators: 1: field label 2: minimum value */
					wc_add_notice( sprintf( __( '“%1$s” must be at least %2$s.', 'storecanvas-lite' ), $lab, $field['min'] ), 'error' );
					$passed = false;
				}
				if ( isset( $field['max'] ) && $n > (float) $field['max'] ) {
					/* translators: 1: field label 2: maximum value */
					wc_add_notice( sprintf( __( '“%1$s” must be at most %2$s.', 'storecanvas-lite' ), $lab, $field['max'] ), 'error' );
					$passed = false;
				}
			}

			// Email.
			if ( 'email' === $type && $val && ! is_email( $val ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '“%s” must be a valid email.', 'storecanvas-lite' ), $lab ), 'error' );
				$passed = false;
			}

			// Color hex.
			if ( 'color' === $type && $val && ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $val ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '“%s” must be a valid hex color.', 'storecanvas-lite' ), $lab ), 'error' );
				$passed = false;
			}

			// Date Y-m-d.
			if ( 'date' === $type && $val && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '“%s” must be a valid date.', 'storecanvas-lite' ), $lab ), 'error' );
				$passed = false;
			}

		}

		$stock_err = self::validate_aggregated_option_stock( self::current_cart_items(), $product_id, $options, $quantity );
		if ( $stock_err ) {
			wc_add_notice( $stock_err, 'error' );
			$passed = false;
		}

		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$config    = SCLT_Product_Options::get_config( $product_id );
		$field_map = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$field_map[ $f['id'] ] = $f;
			}
		}

		$posted = self::posted_option_values();
		if ( $posted ) {
			$options = array();
			$labels  = array();
			$extra   = 0.0;
			foreach ( $posted as $fid => $val ) {
				$fid = sanitize_key( $fid );
				if ( ! isset( $field_map[ $fid ] ) ) {
					continue;
				}
				$field = $field_map[ $fid ];
				if ( ! SCLT_Product_Options::user_can_see_field( $field ) ) {
					continue;
				}
				if ( ! SCLT_Product_Options::field_matches_variation( $field, $variation_id ) ) {
					continue;
				}

				$type = $field['type'] ?? 'text';
				if ( is_array( $val ) ) {
					$val = array_map( 'sanitize_text_field', $val );
				} else {
					$val = sanitize_text_field( $val );
					if ( 'color' === $type && $val ) {
						if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $val ) ) {
							$val = ( function_exists( 'sanitize_hex_color' ) ? ( sanitize_hex_color( $val ) ?: '' ) : '' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
						}
					}
					if ( 'date' === $type && $val && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
						$val = '';
					}
				}

				// Skip hidden by show_if — rebuild probe from all submitted values.
				$probe_all = array();
				foreach ( $posted as $pf => $pv ) {
					$probe_all[ sanitize_key( $pf ) ] = is_array( $pv ) ? array_map( 'sanitize_text_field', $pv ) : sanitize_text_field( $pv );
				}
				if ( ! SCLT_Product_Options::field_is_visible( $field, $probe_all ) ) {
					continue;
				}

				$options[ $fid ] = $val;
				$labels[ $fid ]  = array(
					'label' => $field['label'] ?? $fid,
					'value' => SCLT_Product_Options::format_value_label( $field, $val ),
				);
				$extra          += $this->field_price( $field, $val, $product_id, $variation_id );
			}
			if ( $options ) {
				$cart_item_data[ SCLT_Plugin::CART_OPTIONS ] = $options;
				$cart_item_data['sclt_option_labels']        = $labels;
				$cart_item_data['sclt_price_extra']          = $extra;
			}
		}

		if ( ! empty( $cart_item_data[ SCLT_Plugin::CART_OPTIONS ] ) ) {
			$cart_item_data['unique_key'] = md5( microtime() . wp_rand() );
		}

		return $cart_item_data;
	}

	private function field_price( $field, $value, $product_id, $variation_id = 0 ) {
		$type = $field['price_type'] ?? 'none';
		if ( 'none' === $type || '' === $value || null === $value ) {
			return 0.0;
		}
		if ( is_array( $value ) && ! array_filter( $value, 'strlen' ) ) {
			return 0.0;
		}
		if ( 'checkbox' === ( $field['type'] ?? '' ) && ! $value ) {
			return 0.0;
		}

		// Lookup-table pricing: the price comes from the selected choice(s), not a single amount.
		if ( 'lookup' === $type ) {
			return self::lookup_price( $field['choices'] ?? array(), $value );
		}

		$amount = (float) ( $field['price'] ?? 0 );

		// Base for percent pricing: the selected variation's own price when a variation is
		// chosen, else the product price. Using the parent price for a variation overcharged or
		// undercharged whenever variations were priced differently from the parent.
		$base = 0.0;
		if ( 'percent' === $type ) {
			$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
			if ( ! $product && $variation_id ) {
				$product = wc_get_product( $product_id );
			}
			$base = $product ? (float) $product->get_price() : 0.0;
		}

		return self::price_for( $type, $amount, $base, $value );
	}

	/**
	 * Sum the per-choice prices for the selected value(s) of a lookup-priced field (pure).
	 *
	 * @param array $choices Field choices, each optionally carrying a numeric 'price'.
	 * @param mixed $value   Selected value (scalar or array for multi-selects).
	 * @return float
	 */
	public static function lookup_price( $choices, $value ) {
		if ( ! is_array( $choices ) ) {
			return 0.0;
		}
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$total    = 0.0;
		foreach ( $choices as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['price'] ) ) {
				continue;
			}
			if ( in_array( (string) ( $c['value'] ?? '' ), $selected, true ) ) {
				$total += (float) $c['price'];
			}
		}
		return $total;
	}

	/** Upper bound for a per-unit quantity field, so a typed huge number can't explode a cart line. */
	const MAX_QTY = 10000;

	/**
	 * Whether a field accepts an array of values (multi-select / multi flag).
	 *
	 * @param array $field Field definition.
	 * @return bool
	 */
	public static function is_multi_value_field( $field ) {
		if ( ! is_array( $field ) ) {
			return false;
		}
		$type = $field['type'] ?? '';
		return 'multi_select' === $type || ! empty( $field['multi'] );
	}

	/**
	 * Known choice values for a field (pure).
	 *
	 * @param array $field Field definition.
	 * @return string[]
	 */
	public static function known_choice_values( $field ) {
		$out = array();
		if ( ! is_array( $field ) ) {
			return $out;
		}
		foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) {
				$v = (string) ( $c['value'] ?? '' );
			} else {
				$v = (string) $c;
			}
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/**
	 * Inclusive min/max for a per-quantity field (pure).
	 *
	 * @param array $field Field definition.
	 * @return int[] { min, max }
	 */
	public static function qty_bounds( $field ) {
		$min = 1;
		if ( is_array( $field ) && isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
			$min = max( 0, (int) $field['min'] );
		}
		if ( is_array( $field ) && isset( $field['option_min_qty'] ) && is_numeric( $field['option_min_qty'] ) ) {
			$min = max( $min, (int) $field['option_min_qty'] );
		}
		$max = self::MAX_QTY;
		if ( is_array( $field ) && isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
			$max = min( $max, max( $min, (int) $field['max'] ) );
		}
		if ( is_array( $field ) && isset( $field['option_max_qty'] ) && is_numeric( $field['option_max_qty'] ) ) {
			$max = min( $max, max( $min, (int) $field['option_max_qty'] ) );
		}
		return array( $min, $max );
	}

	/**
	 * Whether a submitted quantity is a plain whole number inside the field bounds (pure).
	 *
	 * @param mixed $value Submitted value.
	 * @param array $field Field definition.
	 * @return bool
	 */
	public static function is_valid_qty_input( $value, $field = array() ) {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) || null === $value ) {
			return false;
		}
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			return false;
		}
		$n = (int) $raw;
		list( $min, $max ) = self::qty_bounds( is_array( $field ) ? $field : array() );
		return $n >= $min && $n <= $max;
	}

	/**
	 * Schema check for one submitted option (pure).
	 *
	 * @param array $field Field definition.
	 * @param mixed $val   Submitted value.
	 * @return true|string True or an error message.
	 */
	public static function validate_option_value( $field, $val ) {
		if ( ! is_array( $field ) ) {
			return true;
		}
		$lab   = $field['label'] ?? ( $field['id'] ?? '' );
		$type  = $field['type'] ?? 'text';
		$multi = self::is_multi_value_field( $field );

		if ( $multi ) {
			$vals  = is_array( $val ) ? $val : ( ( '' === $val || null === $val ) ? array() : array( $val ) );
			$known = self::known_choice_values( $field );
			if ( $known ) {
				foreach ( $vals as $one ) {
					if ( '' === (string) $one ) {
						continue;
					}
					if ( ! in_array( (string) $one, $known, true ) ) {
						/* translators: %s: field label */
						return sprintf( __( '“%s” contains an invalid choice.', 'storecanvas-lite' ), $lab );
					}
				}
			}
			return true;
		}

		if ( is_array( $val ) ) {
			if ( array_filter( $val, 'strlen' ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” is not valid.', 'storecanvas-lite' ), $lab );
			}
			$val = '';
		}

		$known        = self::known_choice_values( $field );
		$choice_types = array( 'select', 'radio', 'image_choice', 'color_swatch' );
		if ( in_array( $type, $choice_types, true ) && $known && '' !== (string) $val ) {
			if ( ! in_array( (string) $val, $known, true ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” contains an invalid choice.', 'storecanvas-lite' ), $lab );
			}
		}

		if ( 'qty' === ( $field['price_type'] ?? '' ) && '' !== (string) $val ) {
			if ( ! self::is_valid_qty_input( $val, $field ) ) {
				list( $min, $max ) = self::qty_bounds( $field );
				/* translators: 1: field label 2: minimum 3: maximum */
				return sprintf( __( '“%1$s” must be a whole number from %2$d to %3$d.', 'storecanvas-lite' ), $lab, $min, $max );
			}
		}

		return true;
	}

	/**
	 * Customer-entered quantity as a safe whole number (pure).
	 *
	 * Only plain digits count: negatives, exponents (1e3), hex, and words become 0, so a
	 * customer can never lower the cart total or blow it up. Decimals round down to whole
	 * units; the result is capped at MAX_QTY.
	 *
	 * @param mixed $value Submitted field value.
	 * @return int
	 */
	public static function sanitize_qty( $value ) {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) || null === $value ) {
			return 0;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$n = (float) $value;
		} else {
			$s = trim( (string) $value );
			if ( 'NAN' === strtoupper( $s ) || 'INF' === strtoupper( $s ) || '-INF' === strtoupper( $s ) ) {
				return 0;
			}
			if ( ! preg_match( '/^\d+(\.\d+)?$/', $s ) ) {
				return 0;
			}
			$n = (float) $s;
		}
		if ( ! is_finite( $n ) || $n <= 0 ) {
			return 0;
		}
		return (int) min( self::MAX_QTY, floor( $n ) );
	}

	/**
	 * Pure price contribution for one option field.
	 *
	 * - flat:     the configured amount (may be negative for a discount).
	 * - percent:  amount% of the base product/variation price.
	 * - qty:      amount multiplied by the numeric value the customer entered (per-unit).
	 * - per_char: amount per character of the entered text.
	 *
	 * @param string $type   Price type.
	 * @param float  $amount Configured amount.
	 * @param float  $base   Base price for percent pricing.
	 * @param mixed  $value  Submitted field value.
	 * @return float
	 */
	public static function price_for( $type, $amount, $base, $value ) {
		$amount = (float) $amount;
		switch ( $type ) {
			case 'flat':
				return $amount;
			case 'percent':
				return (float) $base * ( $amount / 100 );
			case 'qty':
				return $amount * self::sanitize_qty( $value );
			case 'per_char':
				$text = is_array( $value ) ? implode( '', $value ) : (string) $value;
				return $amount * strlen( $text );
			default:
				return 0.0;
		}
	}

	public function set_cart_item_prices( $cart_item ) {
		// Capture the unmodified catalog price once. Never set_price() here —
		// woocommerce_before_calculate_totals is the only writer.
		return self::capture_unmodified_base_price( $cart_item );
	}

	/**
	 * Active catalog price (sale price when a sale is live) for a cart line.
	 *
	 * @param array $cart_item Cart item.
	 * @return float|null
	 */
	public static function fresh_catalog_price( $cart_item ) {
		if ( ! is_array( $cart_item ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$id  = $vid > 0 ? $vid : $pid;
		if ( $id <= 0 ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product && $vid > 0 && $pid > 0 ) {
			$product = wc_get_product( $pid );
		}
		if ( $product && is_object( $product ) && method_exists( $product, 'get_price' ) ) {
			return (float) $product->get_price();
		}
		return null;
	}

	/**
	 * Drop any legacy sclt_base_price snapshot (1.0.7 sessions). Base is read fresh each time.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function capture_unmodified_base_price( $cart_item ) {
		// No base-price snapshot is stored (1.11.3). A snapshot survived catalog price
		// changes across requests and was written back over the live price. Legacy
		// snapshots from older sessions are discarded here.
		if ( is_array( $cart_item ) && array_key_exists( 'sclt_base_price', $cart_item ) ) {
			unset( $cart_item['sclt_base_price'] );
		}
		return $cart_item;
	}

	/**
	 * Current catalog price (sale-aware) from a fresh product object. Never the mutated line price, never a stored snapshot.
	 *
	 * @param array $cart_item Cart item.
	 * @return float
	 */
	public static function unmodified_base_price( $cart_item ) {
		$fresh = self::fresh_catalog_price( is_array( $cart_item ) ? $cart_item : array() );
		return (float) ( null === $fresh ? 0.0 : $fresh );
	}

	/**
	 * Floor a line at zero once (not cumulatively).
	 *
	 * @param float $base  Unmodified catalog price.
	 * @param float $extra Option extra (may be negative).
	 * @return float
	 */
	public static function line_price_from_base( $base, $extra ) {
		$price = (float) $base + (float) $extra;
		return $price < 0 ? 0.0 : $price;
	}

	/**
	 * Whether this cart line carries Lite pricing.
	 *
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	public static function cart_item_is_customized( $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			return false;
		}
		$keys = array( 'sclt_price_extra', 'sclt_option_labels', SCLT_Plugin::CART_OPTIONS );
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $cart_item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write unit price from unmodified base + extra. Only called from calculate_totals.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function assign_cart_item_unit_price( $cart_item ) {
		if ( ! self::cart_item_is_customized( $cart_item ) ) {
			return $cart_item;
		}
		$cart_item = self::capture_unmodified_base_price( $cart_item );
		if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) || ! method_exists( $cart_item['data'], 'set_price' ) ) {
			return $cart_item;
		}
		$fresh = self::fresh_catalog_price( $cart_item );
		if ( null === $fresh ) {
			// Catalog price unavailable: leave the line untouched rather than guess.
			return $cart_item;
		}
		$base  = (float) $fresh;
		$extra = isset( $cart_item['sclt_price_extra'] ) ? (float) $cart_item['sclt_price_extra'] : 0.0;
		$cart_item['data']->set_price( self::line_price_from_base( $base, $extra ) );
		return $cart_item;
	}

	public function from_session( $cart_item, $values ) {
		if ( isset( $values['sclt_price_extra'] ) ) {
			$cart_item['sclt_price_extra'] = (float) $values['sclt_price_extra'];
		}
		if ( isset( $values[ SCLT_Plugin::CART_OPTIONS ] ) ) {
			$cart_item[ SCLT_Plugin::CART_OPTIONS ] = $values[ SCLT_Plugin::CART_OPTIONS ];
		}
		if ( isset( $values['sclt_option_labels'] ) ) {
			$cart_item['sclt_option_labels'] = $values['sclt_option_labels'];
		}
		$cart_item = self::capture_unmodified_base_price( $cart_item );
		$refreshed = self::refresh_cart_item_options( $cart_item );
		if ( false === $refreshed ) {
			wc_add_notice( __( 'A customized item was removed from your cart because its options are no longer valid.', 'storecanvas-lite' ), 'notice' );
			return array();
		}
		return $refreshed;
	}

	/**
	 * Re-check every Lite cart line against current option config and reprice.
	 * set_price() happens only here (woocommerce_before_calculate_totals).
	 *
	 * @param mixed $cart WC_Cart.
	 */
	public function revalidate_cart_totals( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			$item      = self::capture_unmodified_base_price( $item );
			$refreshed = self::refresh_cart_item_options( $item );
			if ( false === $refreshed ) {
				if ( method_exists( $cart, 'remove_cart_item' ) ) {
					$cart->remove_cart_item( $key );
				} elseif ( isset( $cart->cart_contents[ $key ] ) ) {
					unset( $cart->cart_contents[ $key ] );
				}
				wc_add_notice( __( 'A customized item was removed from your cart because its options are no longer valid.', 'storecanvas-lite' ), 'notice' );
				continue;
			}
			$refreshed = self::assign_cart_item_unit_price( $refreshed );
			if ( isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ] = $refreshed;
			}
		}
	}

	/**
	 * Re-validate stored option data and recompute the surcharge from current config.
	 *
	 * @param array      $cart_item Cart item.
	 * @param array|null $fields    Optional field list (tests).
	 * @return array|false Refreshed item, or false if options are invalid (remove).
	 */
	public static function refresh_cart_item_options( $cart_item, $fields = null ) {
		if ( ! is_array( $cart_item ) ) {
			return $cart_item;
		}
		$opt_key = class_exists( 'SCLT_Plugin' ) ? SCLT_Plugin::CART_OPTIONS : 'sclt_options';
		$options = isset( $cart_item[ $opt_key ] ) && is_array( $cart_item[ $opt_key ] ) ? $cart_item[ $opt_key ] : null;
		if ( ! $options ) {
			return $cart_item;
		}
		if ( null === $fields ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$config     = class_exists( 'SCLT_Product_Options' ) ? SCLT_Product_Options::get_config( $product_id ) : array();
			$fields     = $config['fields'] ?? array();
		}
		$valid = self::validate_stored_options( $options, $fields );
		if ( true !== $valid ) {
			return false;
		}
		$cart_item = self::capture_unmodified_base_price( $cart_item );
		$base     = self::unmodified_base_price( $cart_item );
		$cart_item['sclt_price_extra'] = self::extra_from_options( $options, $fields, $base );
		return $cart_item;
	}

	/**
	 * Schema-check every stored option against current field config (pure).
	 *
	 * @param array $options Chosen options.
	 * @param array $fields  Field definitions.
	 * @return true|string True or error message.
	 */
	public static function validate_stored_options( $options, $fields ) {
		$by_id = array();
		foreach ( (array) $fields as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$by_id[ $f['id'] ] = $f;
			}
		}
		foreach ( (array) $options as $fid => $val ) {
			if ( ! isset( $by_id[ $fid ] ) ) {
				continue;
			}
			$shape = self::validate_option_value( $by_id[ $fid ], $val );
			if ( true !== $shape ) {
				return $shape;
			}
		}
		return true;
	}

	/**
	 * Recompute option surcharge from current field config. Never trusts a stored extra.
	 *
	 * @param array $options Chosen options.
	 * @param array $fields  Field definitions.
	 * @param float $base    Product base price (percent pricing).
	 * @return float
	 */
	public static function extra_from_options( $options, $fields, $base = 0.0 ) {
		$by_id = array();
		foreach ( (array) $fields as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$by_id[ $f['id'] ] = $f;
			}
		}
		$extra = 0.0;
		foreach ( (array) $options as $fid => $val ) {
			if ( ! isset( $by_id[ $fid ] ) ) {
				continue;
			}
			$field = $by_id[ $fid ];
			$type  = $field['price_type'] ?? 'none';
			if ( 'none' === $type || '' === $val || null === $val ) {
				continue;
			}
			if ( is_array( $val ) && ! array_filter( $val, 'strlen' ) ) {
				continue;
			}
			if ( 'checkbox' === ( $field['type'] ?? '' ) && ! $val ) {
				continue;
			}
			if ( 'lookup' === $type ) {
				$extra += self::lookup_price( $field['choices'] ?? array(), $val );
				continue;
			}
			$amount = (float) ( $field['price'] ?? 0 );
			$extra += self::price_for( $type, $amount, (float) $base, $val );
		}
		return $extra;
	}

	public function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['sclt_option_labels'] ) && is_array( $cart_item['sclt_option_labels'] ) ) {
			foreach ( $cart_item['sclt_option_labels'] as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$item_data[] = array(
					'key'   => $meta['label'] ?? '',
					'value' => $meta['value'] ?? '',
				);
			}
		} elseif ( ! empty( $cart_item[ SCLT_Plugin::CART_OPTIONS ] ) && is_array( $cart_item[ SCLT_Plugin::CART_OPTIONS ] ) ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$labels     = array();
			if ( $product_id ) {
				foreach ( (array) ( SCLT_Product_Options::get_config( $product_id )['fields'] ?? array() ) as $f ) {
					if ( ! empty( $f['id'] ) ) {
						$labels[ $f['id'] ] = $f;
					}
				}
			}
			foreach ( $cart_item[ SCLT_Plugin::CART_OPTIONS ] as $key => $val ) {
				$field       = $labels[ $key ] ?? array(
					'id'    => $key,
					'label' => $key,
				);
				$item_data[] = array(
					'key'   => $field['label'] ?? $key,
					'value' => SCLT_Product_Options::format_value_label( $field, $val ),
				);
			}
		}
		if ( ! empty( $cart_item['sclt_price_extra'] ) ) {
			// Show any non-zero options total, including a negative (discount) so the cart line
			// matches what is actually charged.
			$item_data[] = array(
				'key'   => __( 'Options total', 'storecanvas-lite' ),
				'value' => wp_strip_all_tags( wc_price( (float) $cart_item['sclt_price_extra'] ) ),
			);
		}
		return $item_data;
	}

	/** Internal StoreCanvas Lite order-item meta keys (never shown as raw lines). */
	const INTERNAL_ITEM_META = array( 'sclt_options', 'sclt_option_labels', 'sclt_price_extra' );

	/**
	 * Readable lines for an order item's choices. Pure.
	 *
	 * @param mixed $labels sclt_option_labels meta: [ id => [ label, value ] ].
	 * @return array[] list of [ key, value ].
	 */
	public static function choice_lines( $labels ) {
		$out = array();
		if ( ! is_array( $labels ) ) {
			return $out;
		}
		foreach ( $labels as $id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = trim( (string) ( $row['label'] ?? $id ) );
			$v = $row['value'] ?? '';
			$v = is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : trim( (string) $v );
			if ( '' === $k || '' === $v ) {
				continue;
			}
			$out[] = array( 'key' => $k, 'value' => $v );
		}
		return $out;
	}

	/**
	 * @param array         $formatted Formatted meta (objects keyed by meta id).
	 * @param WC_Order_Item $item      Order item.
	 * @return array
	 */
	public function format_order_item_meta( $formatted, $item ) {
		if ( ! is_array( $formatted ) || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $formatted;
		}
		foreach ( $formatted as $mid => $m ) {
			if ( is_object( $m ) && isset( $m->key ) && in_array( $m->key, self::INTERNAL_ITEM_META, true ) ) {
				unset( $formatted[ $mid ] );
			}
		}
		$i = 0;
		foreach ( self::choice_lines( $item->get_meta( 'sclt_option_labels' ) ) as $line ) {
			$formatted[ 'sclt_choice_' . ( $i++ ) ] = (object) array(
				'key'           => $line['key'],
				'value'         => $line['value'],
				'display_key'   => esc_html( $line['key'] ),
				'display_value' => wpautop( esc_html( $line['value'] ) ),
			);
		}
		return $formatted;
	}

	/**
	 * Hide raw StoreCanvas Lite meta rows on the admin order screen (choices are listed instead).
	 *
	 * @param array $hidden Hidden meta keys.
	 * @return array
	 */
	public function hidden_order_itemmeta( $hidden ) {
		return array_values( array_unique( array_merge( (array) $hidden, self::INTERNAL_ITEM_META ) ) );
	}

	public function to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values[ SCLT_Plugin::CART_OPTIONS ] ) ) {
			$item->add_meta_data( SCLT_Plugin::CART_OPTIONS, $values[ SCLT_Plugin::CART_OPTIONS ], true );
		}
		if ( ! empty( $values['sclt_option_labels'] ) ) {
			$item->add_meta_data( 'sclt_option_labels', $values['sclt_option_labels'], true );
		}
		if ( ! empty( $values['sclt_price_extra'] ) ) {
			$item->add_meta_data( 'sclt_price_extra', (float) $values['sclt_price_extra'], true );
		}
	}

	/**
	 * Best-effort decrement of choice stock on order create / payment (idempotent).
	 *
	 * @param int $order_id Order ID.
	 */
	public function decrement_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		self::apply_order_option_stock( $order, -1 );
	}

	/**
	 * Restore remaining applied option stock on cancel/failed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function restore_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		self::apply_order_option_stock( $order, 1 );
	}

	/**
	 * WooCommerce refund created. Remember restock intent for the refund ID.
	 *
	 * @param mixed $refund Refund object.
	 * @param array $args   create_refund args.
	 */
	public function on_create_refund( $refund, $args = array() ) {
		$restock = ! empty( $args['restock_items'] );
		if ( isset( $_POST['restock_refunded_items'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$restock = (bool) $_POST['restock_refunded_items']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( is_object( $refund ) && method_exists( $refund, 'update_meta_data' ) ) {
			$refund->update_meta_data( '_sclt_restock_items', $restock ? 1 : 0 );
			if ( method_exists( $refund, 'save' ) ) {
				$refund->save();
			}
		}
	}

	/**
	 * Restore option stock for a refund only when restock was requested.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ) {
		if ( ! self::refund_requests_restock( $refund_id ) ) {
			return;
		}
		self::restore_refunded_option_stock( $order_id, $refund_id );
	}

	/**
	 * WC restock hook. Accepts either (item, qty, ...) or (product_id, old, new, order, product).
	 *
	 * @param mixed $first First argument.
	 * @param mixed $second Second argument.
	 * @param mixed $third Third argument.
	 * @param mixed $fourth Fourth argument.
	 * @param mixed $fifth Fifth argument.
	 */
	public function on_restock_refunded_item( $first, $second = null, $third = null, $fourth = null, $fifth = null ) {
		if ( is_object( $first ) && method_exists( $first, 'get_meta' ) ) {
			$item = $first;
			$qty  = abs( (int) $second );
			$order = is_object( $third ) ? $third : null;
			if ( $qty > 0 ) {
				self::restore_item_option_stock_qty( $order, $item, $qty, 0 );
			}
			return;
		}
	}

	/**
	 * Whether a refund asked Woo to restock.
	 *
	 * @param int $refund_id Refund ID.
	 * @return bool
	 */
	public static function refund_requests_restock( $refund_id ) {
		if ( isset( $_POST['restock_refunded_items'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$v = sanitize_text_field( wp_unslash( $_POST['restock_refunded_items'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only flag on Woo's own nonce-checked refund request.
			return ! empty( $v ) && 'no' !== $v && '0' !== (string) $v;
		}
		$refund_id = (int) $refund_id;
		if ( $refund_id <= 0 ) {
			return false;
		}
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		if ( ! $refund || ! is_object( $refund ) ) {
			return false;
		}
		if ( method_exists( $refund, 'get_meta' ) ) {
			$flag = $refund->get_meta( '_sclt_restock_items' );
			if ( '' !== $flag && null !== $flag ) {
				return (bool) $flag;
			}
			$flag = $refund->get_meta( '_restock_items' );
			if ( '' !== $flag && null !== $flag ) {
				return (bool) $flag;
			}
		}
		return false;
	}

	/**
	 * Apply remaining (sign -1) or restore remaining net (sign +1) per item.
	 *
	 * @param mixed $order Order.
	 * @param int   $sign  -1 or +1.
	 * @return bool Whether a mutation ran.
	 */
	public static function apply_order_option_stock( $order, $sign ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return false;
		}
		self::migrate_order_stock_flags( $order );
		$sign = (int) $sign < 0 ? -1 : 1;
		$ran  = false;
		$fail = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$opts = $item->get_meta( SCLT_Plugin::CART_OPTIONS );
			if ( ! is_array( $opts ) || ! $opts ) {
				continue;
			}
			$qty = max( 0, (int) $item->get_quantity() );
			list( $applied, $restored, $net ) = self::item_stock_state( $order, $item );
			if ( $sign < 0 ) {
				$need = $qty - $net;
				if ( $need <= 0 ) {
					continue;
				}
				$result = self::adjust_choice_stock_cas( $item->get_product_id(), $opts, -$need );
				if ( true === $result ) {
					self::set_item_int_meta( $item, '_sclt_stock_applied_qty', $applied + $need );
					self::persist_order_item( $item );
					$ran = true;
				} else {
					$fail = true;
					self::note_stock_failure( $order, $result, $item );
				}
			} else {
				if ( $net <= 0 ) {
					continue;
				}
				$result = self::adjust_choice_stock_cas( $item->get_product_id(), $opts, $net );
				if ( true === $result ) {
					self::set_item_int_meta( $item, '_sclt_stock_restored_qty', $restored + $net );
					self::persist_order_item( $item );
					$ran = true;
				} else {
					$fail = true;
					self::note_stock_failure( $order, $result, $item );
				}
			}
		}
		if ( $ran && ! $fail ) {
			if ( $sign < 0 ) {
				if ( method_exists( $order, 'update_meta_data' ) ) {
					$order->update_meta_data( '_sclt_option_stock_applied', 1 );
					$order->delete_meta_data( '_sclt_option_stock_restored' );
				}
			} else {
				if ( method_exists( $order, 'update_meta_data' ) ) {
					$order->update_meta_data( '_sclt_option_stock_restored', 1 );
				}
			}
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
		return $ran && ! $fail;
	}

	/**
	 * Restore refunded quantities for one refund ID (deduped).
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return bool
	 */
	public static function restore_refunded_option_stock( $order_id, $refund_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return false;
		}
		self::migrate_order_stock_flags( $order );
		$refund_id = (int) $refund_id;
		if ( $refund_id > 0 && method_exists( $order, 'get_meta' ) && $order->get_meta( '_sclt_stock_refund_' . $refund_id ) ) {
			return false;
		}
		$qtys = self::refunded_line_quantities( $refund_id );
		$ran  = false;
		$fail = false;
		$pending = 0;
		$done    = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$iid = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_id;
			$rq  = 0;
			if ( isset( $qtys[ $iid ] ) ) {
				$rq = (int) $qtys[ $iid ];
			} elseif ( 1 === count( $order->get_items() ) && $qtys ) {
				$rq = (int) reset( $qtys );
			}
			if ( $rq <= 0 ) {
				continue;
			}
			++$pending;
			if ( $refund_id > 0 && self::refund_line_is_processed( $order, $refund_id, $iid ) ) {
				++$done;
				continue;
			}
			if ( true === self::restore_item_option_stock_qty( $order, $item, $rq, $refund_id ) ) {
				$ran = true;
				++$done;
				self::mark_refund_line_processed( $order, $refund_id, $iid );
			} else {
				$fail = true;
				self::mark_refund_line_failed( $order, $refund_id, $iid );
			}
		}
		if ( $refund_id > 0 && method_exists( $order, 'update_meta_data' ) ) {
			if ( $pending > 0 && $done >= $pending && ! $fail ) {
				$order->update_meta_data( '_sclt_stock_refund_' . $refund_id, 1 );
				$order->delete_meta_data( '_sclt_stock_refund_' . $refund_id . '_failed' );
			}
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
		return $ran && ! $fail;
	}

	/**
	 * Restore up to $qty units of option stock for one item.
	 *
	 * @param mixed $order     Order.
	 * @param mixed $item      Order item.
	 * @param int   $qty       Quantity to restore.
	 * @param int   $refund_id Refund ID for dedupe.
	 * @return bool
	 */
	public static function restore_item_option_stock_qty( $order, $item, $qty, $refund_id = 0 ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return false;
		}
		$opts = $item->get_meta( SCLT_Plugin::CART_OPTIONS );
		if ( ! is_array( $opts ) || ! $opts ) {
			return false;
		}
		$qty = abs( (int) $qty );
		list( $applied, $restored, $net ) = self::item_stock_state( $order, $item );
		$give     = min( $qty, $net );
		if ( $give <= 0 ) {
			return true;
		}
		$result = self::adjust_choice_stock_cas( $item->get_product_id(), $opts, $give );
		if ( true !== $result ) {
			self::note_stock_failure( $order, $result, $item );
			return false;
		}
		self::set_item_int_meta( $item, '_sclt_stock_restored_qty', $restored + $give );
		self::persist_order_item( $item );
		if ( $order && is_object( $order ) && method_exists( $order, 'save' ) ) {
			$order->save();
		}
		return true;
	}

	/**
	 * Map refund line item quantities keyed by original order item ID.
	 *
	 * @param int $refund_id Refund ID.
	 * @return array
	 */
	public static function refunded_line_quantities( $refund_id ) {
		$out = array();
		$refund_id = (int) $refund_id;
		if ( $refund_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return $out;
		}
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || ! is_object( $refund ) || ! method_exists( $refund, 'get_items' ) ) {
			return $out;
		}
		foreach ( $refund->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$orig = 0;
			if ( method_exists( $item, 'get_meta' ) ) {
				$orig = (int) $item->get_meta( '_refunded_item_id' );
			}
			$q = method_exists( $item, 'get_quantity' ) ? abs( (int) $item->get_quantity() ) : 0;
			if ( $orig > 0 && $q > 0 ) {
				$out[ $orig ] = ( isset( $out[ $orig ] ) ? $out[ $orig ] : 0 ) + $q;
			}
		}
		return $out;
	}

	/**
	 * @param mixed  $item Item.
	 * @param string $key  Meta key.
	 * @return int
	 */
	public static function item_int_meta( $item, $key ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return 0;
		}
		return max( 0, (int) $item->get_meta( $key ) );
	}

	/**
	 * Per-item applied/restored quantities, falling back to legacy order flags.
	 *
	 * @param mixed $order Order.
	 * @param mixed $item  Item.
	 * @return array{0:int,1:int,2:int} applied, restored, net.
	 */
	public static function item_stock_state( $order, $item ) {
		$applied  = self::item_int_meta( $item, '_sclt_stock_applied_qty' );
		$restored = self::item_int_meta( $item, '_sclt_stock_restored_qty' );
		if ( $applied <= 0 && $order && is_object( $order ) && method_exists( $order, 'get_meta' ) && $order->get_meta( '_sclt_option_stock_applied' ) ) {
			$applied = is_object( $item ) && method_exists( $item, 'get_quantity' ) ? max( 0, (int) $item->get_quantity() ) : 0;
			if ( $order->get_meta( '_sclt_option_stock_restored' ) ) {
				$restored = $applied;
			}
		}
		return array( $applied, $restored, max( 0, $applied - $restored ) );
	}

	/**
	 * @param mixed  $item  Item.
	 * @param string $key   Meta key.
	 * @param int    $value Value.
	 */
	public static function set_item_int_meta( $item, $key, $value ) {
		if ( is_object( $item ) && method_exists( $item, 'update_meta_data' ) ) {
			$item->update_meta_data( $key, (int) $value );
		}
	}

	/**
	 * @param mixed $item Item.
	 */
	public static function persist_order_item( $item ) {
		if ( ! is_object( $item ) ) {
			return;
		}
		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		} elseif ( method_exists( $item, 'save_meta_data' ) ) {
			$item->save_meta_data();
		}
	}

	/**
	 * @param int $refund_id Refund ID.
	 * @param int $item_id   Order item ID.
	 * @return string
	 */
	public static function refund_line_processed_key( $refund_id, $item_id ) {
		return '_sclt_stock_refund_' . (int) $refund_id . '_line_' . (int) $item_id;
	}

	/**
	 * @param mixed $order     Order.
	 * @param int   $refund_id Refund ID.
	 * @param int   $item_id   Order item ID.
	 * @return bool
	 */
	public static function refund_line_is_processed( $order, $refund_id, $item_id ) {
		$refund_id = (int) $refund_id;
		$item_id   = (int) $item_id;
		if ( $refund_id <= 0 || $item_id <= 0 || ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		return (bool) $order->get_meta( self::refund_line_processed_key( $refund_id, $item_id ) );
	}

	/**
	 * @param mixed $order     Order.
	 * @param int   $refund_id Refund ID.
	 * @param int   $item_id   Order item ID.
	 */
	public static function mark_refund_line_processed( $order, $refund_id, $item_id ) {
		$refund_id = (int) $refund_id;
		$item_id   = (int) $item_id;
		if ( $refund_id <= 0 || $item_id <= 0 || ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$order->update_meta_data( self::refund_line_processed_key( $refund_id, $item_id ), 1 );
		$order->delete_meta_data( '_sclt_stock_refund_' . $refund_id . '_failed' );
		$order->delete_meta_data( '_sclt_stock_refund_' . $refund_id . '_fail_line_' . $item_id );
	}

	/**
	 * @param mixed $order     Order.
	 * @param int   $refund_id Refund ID.
	 * @param int   $item_id   Order item ID.
	 */
	public static function mark_refund_line_failed( $order, $refund_id, $item_id ) {
		$refund_id = (int) $refund_id;
		$item_id   = (int) $item_id;
		if ( $refund_id <= 0 || ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$order->update_meta_data( '_sclt_stock_refund_' . $refund_id . '_failed', 1 );
		if ( $item_id > 0 ) {
			$order->update_meta_data( '_sclt_stock_refund_' . $refund_id . '_fail_line_' . $item_id, 1 );
		}
	}

	/**
	 * Drop cached post meta so the next read comes from the DB (never rewrite a snapshot).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate_post_meta_cache( $post_id ) {
		$post_id = (int) $post_id;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Convert legacy order-wide flags into per-item quantities once.
	 *
	 * @param mixed $order Order.
	 */
	public static function migrate_order_stock_flags( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		if ( ! $order->get_meta( '_sclt_option_stock_applied' ) ) {
			return;
		}
		$restored_all = (bool) $order->get_meta( '_sclt_option_stock_restored' );
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			if ( self::item_int_meta( $item, '_sclt_stock_applied_qty' ) > 0 ) {
				return;
			}
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}
			$qty = max( 0, (int) $item->get_quantity() );
			self::set_item_int_meta( $item, '_sclt_stock_applied_qty', $qty );
			if ( $restored_all ) {
				self::set_item_int_meta( $item, '_sclt_stock_restored_qty', $qty );
			}
			self::persist_order_item( $item );
		}
	}

	/**
	 * @param mixed  $order  Order.
	 * @param mixed  $result CAS result.
	 * @param mixed  $item   Item.
	 */
	public static function note_stock_failure( $order, $result, $item ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}
		$code = ( 'exhaustion' === $result ) ? 'exhaustion' : 'conflict';
		$order->add_order_note(
			sprintf(
				/* translators: %s: exhaustion or conflict */
				__( 'StoreCanvas Lite: option stock update failed (%s). The order transition did not finish reserving inventory.', 'storecanvas-lite' ),
				$code
			),
			false,
			true
		);
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Adjust product-local or shared-group choice stock (non-CAS wrapper).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $options    Chosen options.
	 * @param int   $delta      Negative to decrement.
	 */
	public static function adjust_choice_stock( $product_id, $options, $delta ) {
		self::adjust_choice_stock_cas( $product_id, $options, $delta, false );
	}

	/**
	 * Atomic stock update per inventory owner with bounded CAS retries.
	 *
	 * @param int  $product_id     Product ID.
	 * @param array $options       Chosen options.
	 * @param int  $delta          Negative to decrement.
	 * @param bool $fail_shortfall Fail instead of clamping a decrement below zero.
	 * @return bool|string true, false, 'exhaustion', or 'conflict'.
	 */
	public static function adjust_choice_stock_cas( $product_id, $options, $delta, $fail_shortfall = true ) {
		if ( ! is_array( $options ) || ! $options ) {
			return true;
		}
		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return true;
		}
		$config = class_exists( 'SCLT_Product_Options' ) ? SCLT_Product_Options::get_config( $product_id ) : array();
		$by_id  = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$by_id[ $f['id'] ] = $f;
			}
		}
		$local_opts = array();
		$group_opts = array();
		foreach ( $options as $fid => $val ) {
			$field = $by_id[ $fid ] ?? null;
			$gid   = is_array( $field ) ? (int) ( $field['_sc_group_id'] ?? 0 ) : 0;
			if ( $gid > 0 ) {
				$group_opts[ $gid ][ $fid ] = $val;
			} else {
				$local_opts[ $fid ] = $val;
			}
		}
		$overall = true;
		if ( $local_opts ) {
			$r = self::cas_update_stock_meta(
				(int) $product_id,
				SCLT_Plugin::META_OPTIONS,
				function ( &$raw ) use ( $local_opts, $delta, $fail_shortfall ) {
					if ( ! is_array( $raw ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
						return true;
					}
					$ok = self::mutate_choice_stock_fields( $raw['fields'], $local_opts, $delta, $fail_shortfall );
					return $ok ? true : 'exhaustion';
				}
			);
			if ( true !== $r ) {
				$overall = $r;
			}
		}
		if ( true !== $overall ) {
			return $overall;
		}
		foreach ( $group_opts as $gid => $gopts ) {
			$r = self::cas_update_stock_meta(
				(int) $gid,
				class_exists( 'SCLT_Option_Groups' ) ? SCLT_Option_Groups::META_FIELDS : '_sclt_group_fields',
				function ( &$fields ) use ( $gopts, $delta, $fail_shortfall ) {
					if ( ! is_array( $fields ) ) {
						return true;
					}
					$ok = self::mutate_choice_stock_fields( $fields, $gopts, $delta, $fail_shortfall );
					return $ok ? true : 'exhaustion';
				}
			);
			if ( true === $r && class_exists( 'SCLT_Option_Groups' ) && function_exists( 'delete_transient' ) ) {
				delete_transient( SCLT_Option_Groups::TRANSIENT );
			}
			if ( true !== $r ) {
				return $r;
			}
		}
		return true;
	}

	/**
	 * Compare-and-swap a post meta blob. Test seam: sc_option_stock_cas_pre_write.
	 *
	 * @param int      $owner_id  Post ID.
	 * @param string   $meta_key  Meta key.
	 * @param callable $mutator   function ( &$value ) { return true|'exhaustion'; }.
	 * @param int      $max_tries Bounded retries.
	 * @return bool|string
	 */
	public static function cas_update_stock_meta( $owner_id, $meta_key, $mutator, $max_tries = 8 ) {
		$owner_id  = (int) $owner_id;
		$max_tries = max( 1, (int) $max_tries );
		for ( $i = 0; $i < $max_tries; $i++ ) {
			$raw     = get_post_meta( $owner_id, $meta_key, true );
			$old_sql = self::stock_meta_sql_value( $raw );
			apply_filters( 'sclt_option_stock_cas_pre_write', $old_sql, $owner_id, $meta_key, $i );
			$current     = get_post_meta( $owner_id, $meta_key, true );
			$current_sql = self::stock_meta_sql_value( $current );
			if ( $current_sql !== $old_sql ) {
				continue;
			}
			$new    = $current;
			$result = call_user_func_array( $mutator, array( &$new ) );
			if ( true !== $result ) {
				return $result ? $result : 'exhaustion';
			}
			$new_sql = self::stock_meta_sql_value( $new );
			if ( $new_sql === $old_sql ) {
				return true;
			}
			if ( self::cas_write_post_meta( $owner_id, $meta_key, $old_sql, $new_sql, $new ) ) {
				return true;
			}
		}
		return 'conflict';
	}

	/**
	 * UPDATE postmeta SET meta_value=new WHERE post_id AND meta_key AND meta_value=old.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $old_sql  Serialized old value.
	 * @param string $new_sql  Serialized new value.
	 * @param mixed  $new_value Unserialized new value.
	 * @return bool
	 */
	public static function cas_write_post_meta( $post_id, $meta_key, $old_sql, $new_sql, $new_value ) {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->postmeta ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// Compare-and-swap on the exact old value: must hit the DB, never a cache.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s',
					$wpdb->postmeta,
					$new_sql,
					(int) $post_id,
					$meta_key,
					$old_sql
				)
			);
			if ( $rows ) {
				apply_filters( 'sclt_option_stock_cas_after_atomic', $post_id, $meta_key, $new_sql );
				self::invalidate_post_meta_cache( $post_id );
				return true;
			}
			return false;
		}
		$now = self::stock_meta_sql_value( get_post_meta( $post_id, $meta_key, true ) );
		if ( $now !== $old_sql ) {
			return false;
		}
		update_post_meta( $post_id, $meta_key, $new_value );
		apply_filters( 'sclt_option_stock_cas_after_atomic', $post_id, $meta_key, $new_sql );
		return true;
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function stock_meta_sql_value( $value ) {
		if ( function_exists( 'maybe_serialize' ) ) {
			return maybe_serialize( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}
		return (string) $value;
	}

	/**
	 * @param array $fields Fields (by ref).
	 * @param array $options Chosen values keyed by field id.
	 * @param int   $delta   Stock delta.
	 * @param bool  $fail_on_shortfall Fail decrement when stock is insufficient.
	 * @return bool
	 */
	public static function mutate_choice_stock_fields( &$fields, $options, $delta, $fail_on_shortfall = false ) {
		if ( $fail_on_shortfall && (int) $delta < 0 ) {
			$need = abs( (int) $delta );
			foreach ( $fields as $field ) {
				$fid = $field['id'] ?? '';
				if ( ! $fid || ! isset( $options[ $fid ] ) ) {
					continue;
				}
				$vals = is_array( $options[ $fid ] ) ? $options[ $fid ] : array( $options[ $fid ] );
				if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
					continue;
				}
				foreach ( $field['choices'] as $choice ) {
					if ( ! is_array( $choice ) || ! array_key_exists( 'stock_qty', $choice ) || null === $choice['stock_qty'] || '' === $choice['stock_qty'] ) {
						continue;
					}
					$cv = (string) ( $choice['value'] ?? '' );
					foreach ( $vals as $v ) {
						if ( (string) $v === $cv && (int) $choice['stock_qty'] < $need ) {
							return false;
						}
					}
				}
			}
		}
		$changed = false;
		foreach ( $fields as &$field ) {
			$fid = $field['id'] ?? '';
			if ( ! $fid || ! isset( $options[ $fid ] ) ) {
				continue;
			}
			$vals = is_array( $options[ $fid ] ) ? $options[ $fid ] : array( $options[ $fid ] );
			if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
				continue;
			}
			foreach ( $field['choices'] as &$choice ) {
				if ( ! is_array( $choice ) || ! array_key_exists( 'stock_qty', $choice ) || null === $choice['stock_qty'] || '' === $choice['stock_qty'] ) {
					continue;
				}
				$cv = (string) ( $choice['value'] ?? '' );
				foreach ( $vals as $v ) {
					if ( (string) $v === $cv ) {
						$choice['stock_qty'] = max( 0, (int) $choice['stock_qty'] + (int) $delta );
						$changed            = true;
					}
				}
			}
			unset( $choice );
		}
		unset( $field );
		return true;
	}

	public function admin_order_preview( $item_id, $item, $product ) {
		$options = $item->get_meta( SCLT_Plugin::CART_OPTIONS );
		$labels  = $item->get_meta( 'sclt_option_labels' );
		$extra   = $item->get_meta( 'sclt_price_extra' );
		if ( ! $options && ! $labels && ! $extra ) {
			return;
		}
		echo '<div class="sclt-order-item-meta" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #c3c4c7;">';
		echo '<strong>' . esc_html__( 'StoreCanvas Lite', 'storecanvas-lite' ) . '</strong>';
		if ( is_array( $labels ) && $labels ) {
			echo '<ul style="margin:4px 0 0 1em;">';
			foreach ( $labels as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				echo '<li>' . esc_html( ( $meta['label'] ?? '' ) . ': ' . ( $meta['value'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		} elseif ( is_array( $options ) ) {
			echo '<ul style="margin:4px 0 0 1em;">';
			foreach ( $options as $k => $v ) {
				echo '<li>' . esc_html( $k ) . ': ' . esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $extra ) {
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Options extra:', 'storecanvas-lite' ) . ' ' . wp_kses_post( wc_price( (float) $extra ) ) . '</p>';
		}
		echo '</div>';
	}
}
