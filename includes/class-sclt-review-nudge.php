<?php
/**
 * WordPress.org review notice for StoreCanvas Lite.
 *
 * @package StoreCanvasLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks for a wordpress.org review once the store has really used Lite.
 *
 * Shown to administrators only when at least 5 orders have used StoreCanvas
 * Lite options or a customer design and 14 days have passed since install. Every
 * merchant sees the same choices (review, get help, later, dismiss): there is
 * no rating question and no routing of unhappy users away from the review
 * page, which wordpress.org forbids. "Later" can be used once and hides the
 * notice for 30 days; after that only "Dismiss" remains, and dismissal is
 * permanent. No outbound HTTP.
 */
class SCLT_Review_Nudge {

	const MIN_ORDERS   = 5;
	const MIN_DAYS     = 14;
	const SNOOZE_DAYS  = 30;

	const OPTION_INSTALLED_AT = 'sclt_installed_at';
	const OPTION_DISMISSED    = 'sclt_review_nudge_dismissed';
	const OPTION_SNOOZED      = 'sclt_review_nudge_snoozed_until';
	const OPTION_COUNT        = 'sclt_review_nudge_orders';
	const ORDER_FLAG          = '_sclt_review_counted';

	const REVIEW_URL = 'https://wordpress.org/support/plugin/storecanvas-lite/reviews/#new-post';
	const ACTION     = 'sclt_review_nudge';

	/**
	 * Singleton instance.
	 *
	 * @var SCLT_Review_Nudge|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return SCLT_Review_Nudge
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks and record install time if missing.
	 */
	private function __construct() {
		self::maybe_store_install_time();
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'count_order' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'count_order' ), 30, 1 );
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Store the install timestamp once if it is missing.
	 */
	public static function maybe_store_install_time() {
		if ( false === get_option( self::OPTION_INSTALLED_AT, false ) ) {
			add_option( self::OPTION_INSTALLED_AT, time(), '', false );
		}
	}

	/**
	 * Pure threshold check, kept free of WordPress calls for the test suite.
	 *
	 * @param int  $order_count Orders that used Lite options or a design.
	 * @param int  $installed_at  Unix timestamp of first install.
	 * @param int  $now           Current unix timestamp.
	 * @param bool $dismissed     Whether the notice was dismissed for good.
	 * @param int  $snoozed_until Unix timestamp the "later" snooze ends (0 = never snoozed).
	 * @return bool
	 */
	public static function should_show( $order_count, $installed_at, $now, $dismissed, $snoozed_until = 0 ) {
		if ( $dismissed ) {
			return false;
		}
		if ( (int) $order_count < self::MIN_ORDERS ) {
			return false;
		}
		$installed_at = (int) $installed_at;
		if ( $installed_at <= 0 || (int) $now - $installed_at < self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( (int) $snoozed_until > (int) $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the one allowed "later" snooze is still available.
	 *
	 * @param int $snoozed_until Stored snooze end (0 = never snoozed).
	 * @return bool
	 */
	public static function can_snooze( $snoozed_until ) {
		return (int) $snoozed_until <= 0;
	}

	/**
	 * Whether an order's items carry StoreCanvas Lite options or a design.
	 *
	 * @param array $item_meta_keys One list of meta keys per line item.
	 * @return bool
	 */
	public static function order_uses_lite( array $item_meta_keys ) {
		foreach ( $item_meta_keys as $keys ) {
			foreach ( (array) $keys as $key ) {
				if ( SCLT_Plugin::CART_OPTIONS === $key || SCLT_Canvas::ITEM_META === $key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Count a placed order once if it used Lite options or a design.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 */
	public function count_order( $order ) {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order || ! method_exists( $order, 'get_items' ) || $order->get_meta( self::ORDER_FLAG ) ) {
			return;
		}
		$keys = array();
		foreach ( $order->get_items() as $item ) {
			$row = array();
			foreach ( $item->get_meta_data() as $meta ) {
				$row[] = $meta->key;
			}
			$keys[] = $row;
		}
		if ( ! self::order_uses_lite( $keys ) ) {
			return;
		}
		$order->update_meta_data( self::ORDER_FLAG, 1 );
		$order->save_meta_data();
		update_option( self::OPTION_COUNT, (int) get_option( self::OPTION_COUNT, 0 ) + 1, false );
	}

	/**
	 * Handle "later" and "dismiss" links (GET + nonce).
	 */
	public function maybe_handle_action() {
		if ( ! isset( $_GET[ self::ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::ACTION );
		$what = sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) );
		if ( 'later' === $what && self::can_snooze( (int) get_option( self::OPTION_SNOOZED, 0 ) ) ) {
			update_option( self::OPTION_SNOOZED, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS, false );
		} else {
			update_option( self::OPTION_DISMISSED, 1, false );
		}
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( remove_query_arg( array( self::ACTION, '_wpnonce' ), $redirect ) );
		exit;
	}

	/**
	 * Render the notice on the dashboard, plugins, orders and Lite screens.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ok     = array( 'dashboard', 'plugins', 'edit-shop_order', 'woocommerce_page_wc-orders', 'edit-product', 'product_page_sclt-help' );
		if ( ! $screen || ! in_array( $screen->id, $ok, true ) ) {
			return;
		}
		$dismissed = (bool) get_option( self::OPTION_DISMISSED, 0 );
		$snoozed   = (int) get_option( self::OPTION_SNOOZED, 0 );
		$installed = (int) get_option( self::OPTION_INSTALLED_AT, 0 );
		if ( $dismissed || $installed <= 0 || time() - $installed < self::MIN_DAYS * DAY_IN_SECONDS || $snoozed > time() ) {
			return;
		}
		$count = (int) get_option( self::OPTION_COUNT, 0 );
		if ( ! self::should_show( $count, $installed, time(), $dismissed, $snoozed ) ) {
			return;
		}

		$help_url    = SCLT_Hub::page_url();
		$later_url   = wp_nonce_url( add_query_arg( self::ACTION, 'later' ), self::ACTION );
		$dismiss_url = wp_nonce_url( add_query_arg( self::ACTION, 'dismiss' ), self::ACTION );

		echo '<div class="notice notice-info sclt-review-nudge"><p>';
		echo esc_html__( 'Your customers have been ordering with StoreCanvas Lite options for a while now. If it has been useful, a short review on WordPress.org helps other store owners find it. If something is not working, tell us and we will help.', 'storecanvas-lite' );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Leave a review', 'storecanvas-lite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $help_url ) . '">' . esc_html__( 'Get help', 'storecanvas-lite' ) . '</a> ';
		if ( self::can_snooze( $snoozed ) ) {
			echo '<a href="' . esc_url( $later_url ) . '">' . esc_html__( 'Maybe later', 'storecanvas-lite' ) . '</a> &middot; ';
		}
		echo '<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'storecanvas-lite' ) . '</a>';
		echo '</p></div>';
	}
}
