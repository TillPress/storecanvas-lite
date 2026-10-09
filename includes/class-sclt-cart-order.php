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
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'decrement_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_option_stock' ), 20, 1 );
	}

	/**
	 * Validate required options + limits + stock + variation targeting.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore
		$options      = array();
		if ( isset( $_POST['sclt_option'] ) && is_array( $_POST['sclt_option'] ) ) { // phpcs:ignore
			foreach ( wp_unslash( $_POST['sclt_option'] ) as $fid => $val ) { // phpcs:ignore
				$fid             = sanitize_key( $fid );
				$options[ $fid ] = is_array( $val )
					? array_map( 'sanitize_text_field', $val )
					: sanitize_text_field( $val );
			}
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

			// Per-option stock.
			$vals = is_array( $val ) ? $val : array( $val );
			foreach ( $vals as $one ) {
				if ( '' === (string) $one ) {
					continue;
				}
				$stock = SCLT_Product_Options::choice_stock( $field, $one );
				if ( null === $stock ) {
					continue;
				}
				$need = max( 1, (int) $quantity );
				if ( $stock < $need ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: field 2: choice 3: remaining */
							__( '“%1$s” option “%2$s” has insufficient stock (remaining: %3$d).', 'storecanvas-lite' ),
							$lab,
							SCLT_Product_Options::format_value_label( $field, $one ),
							$stock
						),
						'error'
					);
					$passed = false;
				}
			}
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart request; values are sanitized per field below.
		if ( isset( $_POST['sclt_option'] ) && is_array( $_POST['sclt_option'] ) ) {
			$options = array();
			$labels  = array();
			$extra   = 0.0;
			foreach ( wp_unslash( $_POST['sclt_option'] ) as $fid => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
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
				foreach ( wp_unslash( $_POST['sclt_option'] ) as $pf => $pv ) { // phpcs:ignore
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
				$qty = is_array( $value ) ? 0.0 : (float) $value;
				return $amount * $qty;
			case 'per_char':
				$text = is_array( $value ) ? implode( '', $value ) : (string) $value;
				return $amount * strlen( $text );
			default:
				return 0.0;
		}
	}

	public function set_cart_item_prices( $cart_item ) {
		if ( empty( $cart_item['sclt_price_extra'] ) || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return $cart_item;
		}
		$extra = (float) $cart_item['sclt_price_extra'];
		if ( 0.0 === $extra ) {
			return $cart_item;
		}
		$base  = (float) $cart_item['data']->get_price( 'edit' );
		$price = $base + $extra;
		// A negative options total (a discount) may reduce the price but never below zero.
		if ( $price < 0 ) {
			$price = 0.0;
		}
		$cart_item['data']->set_price( $price );
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
		return $this->set_cart_item_prices( $cart_item );
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
	 * Best-effort decrement of choice stock on order create.
	 *
	 * @param int $order_id Order ID.
	 */
	public function decrement_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_sclt_option_stock_applied' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$opts = $item->get_meta( SCLT_Plugin::CART_OPTIONS );
			if ( ! is_array( $opts ) || ! $opts ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$qty        = max( 1, (int) $item->get_quantity() );
			$this->adjust_product_choice_stock( $product_id, $opts, -$qty );
		}
		$order->update_meta_data( '_sclt_option_stock_applied', 1 );
		$order->save();
	}

	/**
	 * Best-effort restore on cancel/failed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function restore_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_sclt_option_stock_applied' ) ) {
			return;
		}
		if ( $order->get_meta( '_sclt_option_stock_restored' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$opts = $item->get_meta( SCLT_Plugin::CART_OPTIONS );
			if ( ! is_array( $opts ) || ! $opts ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$qty        = max( 1, (int) $item->get_quantity() );
			$this->adjust_product_choice_stock( $product_id, $opts, +$qty );
		}
		$order->update_meta_data( '_sclt_option_stock_restored', 1 );
		$order->save();
	}

	/**
	 * Adjust local product field choice stock_qty (not group fields — product-local only).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $options    Chosen options.
	 * @param int   $delta      Negative to decrement.
	 */
	private function adjust_product_choice_stock( $product_id, $options, $delta ) {
		$raw = get_post_meta( $product_id, SCLT_Plugin::META_OPTIONS, true );
		if ( ! is_array( $raw ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			return;
		}
		$changed = false;
		foreach ( $raw['fields'] as &$field ) {
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
						$changed             = true;
					}
				}
			}
			unset( $choice );
		}
		unset( $field );
		if ( $changed ) {
			update_post_meta( $product_id, SCLT_Plugin::META_OPTIONS, $raw );
		}
	}

	/**
	 * Options-only admin order-item display.
	 *
	 * @param int    $item_id Item ID.
	 * @param object $item    Order item.
	 * @param object $product Product.
	 */
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
