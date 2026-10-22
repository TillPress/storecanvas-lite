<?php
/**
 * Plugin Name:       StoreCanvas Lite
 * Plugin URI:        https://tillpress.com/storecanvas/
 * Description:       Free WooCommerce product options and a customer design canvas: 15 field types, conditional logic, per-choice/lookup, percent, quantity and per-character pricing, per-choice option stock, and text/image designs on one print area.
 * Version:           1.3.1
 * Author:            Tillpress
 * Author URI:        https://tillpress.com
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:  11.2
 * Requires Plugins:  woocommerce
 * Text Domain:       storecanvas-lite
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCLT_VERSION', '1.3.1' );
define( 'SCLT_BRAND_NAME', 'StoreCanvas Lite' );
define( 'SCLT_PLUGIN_FILE', __FILE__ );
define( 'SCLT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCLT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCLT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-plugin.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-product-options.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-option-groups.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-cart-order.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-qty-tiers.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-canvas.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-option-files.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-admin-product.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-demo.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-support.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-hub.php';
require_once SCLT_PLUGIN_DIR . 'includes/class-sclt-review-nudge.php';

/**
 * Whether the paid StoreCanvas plugin is active. Lite steps aside so a store
 * never renders duplicate option fields or charges option prices twice.
 *
 * @return bool
 */
function sclt_pro_is_active() {
	return defined( 'SC_VERSION' ) || class_exists( 'SC_Plugin', false );
}

/**
 * Bootstrap when WooCommerce is active.
 */
function sclt_init() {
	if ( sclt_pro_is_active() ) {
		add_action( 'admin_notices', 'sclt_pro_active_notice' );
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sclt_woocommerce_missing_notice' );
		return;
	}

	SCLT_Plugin::instance();
	SCLT_Product_Options::instance();
	SCLT_Option_Groups::instance();
	SCLT_Cart_Order::instance();
	SCLT_Qty_Tiers::instance();
	SCLT_Canvas::instance();
	SCLT_Option_Files::instance();
	SCLT_Review_Nudge::instance();
	if ( is_admin() ) {
		SCLT_Admin_Product::instance();
		SCLT_Support::instance();
		SCLT_Hub::instance();
	}
}
add_action( 'plugins_loaded', 'sclt_init', 20 );

// Pending (never ordered) design uploads are pruned daily, even while Pro is active.
add_action( SCLT_Canvas::CRON, array( 'SCLT_Canvas', 'cleanup' ) );

/**
 * Stop the daily design cleanup when the plugin is deactivated.
 */
function sclt_deactivate() {
	wp_clear_scheduled_hook( SCLT_Canvas::CRON );
}
register_deactivation_hook( __FILE__, 'sclt_deactivate' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SCLT_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SCLT_PLUGIN_FILE, true );
		}
	}
);

function sclt_pro_active_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	echo '<div class="notice notice-info"><p>';
	echo esc_html__( 'StoreCanvas (paid) is active, so StoreCanvas Lite is idle. You can deactivate Lite — the paid plugin already includes these product option tools.', 'storecanvas-lite' );
	echo '</p></div>';
}

function sclt_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo wp_kses_post(
		sprintf(
			/* translators: %s: plugin name */
			__( '<strong>%s</strong> requires WooCommerce to be active.', 'storecanvas-lite' ),
			SCLT_BRAND_NAME
		)
	);
	echo '</p></div>';
}
