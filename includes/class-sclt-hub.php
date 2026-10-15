<?php
/**
 * Help & demo page for StoreCanvas Lite: support ticket composer, demo data, and the
 * optional offer for the paid plugin.
 *
 * The only outbound request is the support ticket (SCLT_Support), sent to
 * tillpress.com when an administrator ticks the consent box and presses Send.
 *
 * @package StoreCanvasLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help & demo admin page.
 */
class SCLT_Hub {

	const PAGE         = 'sclt-help';
	const SUPPORT_MAIL = 'info@tillpress.com';
	const SUPPORT_URL  = 'https://tillpress.com/support/';
	const DOCS_URL     = 'https://tillpress.com/storecanvas-lite/';
	const FORUM_URL    = 'https://wordpress.org/support/plugin/storecanvas-lite/';
	const PRO_URL      = 'https://tillpress.com/storecanvas/';
	const OFFER_CODE   = 'LITE20';
	const OFFER_META   = 'sclt_offer_dismissed';

	/**
	 * Singleton instance.
	 *
	 * @var SCLT_Hub|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return SCLT_Hub
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_sclt_demo_create', array( $this, 'handle_demo_create' ) );
		add_action( 'admin_post_sclt_demo_remove', array( $this, 'handle_demo_remove' ) );
		add_action( 'admin_post_sclt_offer_dismiss', array( $this, 'handle_offer_dismiss' ) );
		add_filter( 'plugin_action_links_' . SCLT_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Capability required for the page and its actions.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Register the submenu page.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'StoreCanvas Lite help & demo', 'storecanvas-lite' ),
			__( 'StoreCanvas Lite help', 'storecanvas-lite' ),
			self::capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Admin URL of this page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'edit.php?post_type=product' ) );
	}

	/**
	 * Plugins-screen link.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Help & demo', 'storecanvas-lite' ) . '</a>';
		return $links;
	}

	/**
	 * Enqueue page assets only on this screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'sclt-hub', SCLT_PLUGIN_URL . 'assets/hub.css', array(), SCLT_VERSION );
		SCLT_Support::enqueue();
	}

	/**
	 * Whether the offer box should show (pure).
	 *
	 * @param bool $dismissed Whether this user dismissed it.
	 * @return bool
	 */
	public static function should_show_offer( $dismissed ) {
		return ! $dismissed;
	}

	/**
	 * Format report lines as "Label: value" text (pure).
	 *
	 * @param array $lines Label => value.
	 * @return string
	 */
	public static function format_report( $lines ) {
		$out = array();
		foreach ( (array) $lines as $label => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$out[] = $label . ': ' . (string) $value;
		}
		return implode( "\n", $out );
	}

	/**
	 * Environment lines. Never includes credentials, keys, or customer data.
	 *
	 * @return array
	 */
	public static function environment_lines() {
		global $wpdb;
		$theme   = wp_get_theme();
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'Plugin'         => 'StoreCanvas Lite ' . SCLT_VERSION,
			'Site'           => home_url( '/' ),
			'WordPress'      => get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ),
			'WooCommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : 'not active',
			'PHP'            => PHP_VERSION,
			'Database'       => ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : 'unknown',
			'Theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? ' (block theme)' : '' ),
			'HPOS'           => (bool) $hpos,
			'Language'       => get_locale(),
			'Memory limit'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'unknown',
			'WP_DEBUG'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'Active plugins' => $plugins,
		);
	}

	/**
	 * Full system report text for support tickets.
	 *
	 * @return string
	 */
	public static function system_report() {
		return self::format_report( array_merge( self::environment_lines(), SCLT_Demo::report_lines() ) );
	}

	/**
	 * Redirect back to this page with a status flag.
	 *
	 * @param string $state Status key.
	 */
	private function back( $state ) {
		wp_safe_redirect( self::page_url( array( 'sclt_hub' => $state ) ) );
		exit;
	}

	/**
	 * Guard for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'storecanvas-lite' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Create demo data.
	 */
	public function handle_demo_create() {
		$this->guard( 'sclt_demo_create' );
		$result = SCLT_Demo::create();
		$this->back( is_wp_error( $result ) ? 'failed' : 'created' );
	}

	/**
	 * Remove demo data.
	 */
	public function handle_demo_remove() {
		$this->guard( 'sclt_demo_remove' );
		SCLT_Demo::remove();
		$this->back( 'removed' );
	}

	/**
	 * Hide the offer box for the current user.
	 */
	public function handle_offer_dismiss() {
		$this->guard( 'sclt_offer_dismiss' );
		update_user_meta( get_current_user_id(), self::OFFER_META, 1 );
		$this->back( 'offer_hidden' );
	}

	/**
	 * One-button form posting to admin-post.php.
	 *
	 * @param string $action Action name (also the nonce action).
	 * @param string $label  Button label.
	 * @param string $class  Button class.
	 */
	private function action_button( $action, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sclt-hub-inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Status notice after an action.
	 */
	private function render_status() {
		$state = isset( $_GET['sclt_hub'] ) ? sanitize_key( wp_unslash( $_GET['sclt_hub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$map   = array(
			'created'      => array( 'success', __( 'Demo data created.', 'storecanvas-lite' ) ),
			'removed'      => array( 'success', __( 'Demo data removed.', 'storecanvas-lite' ) ),
			'failed'       => array( 'error', __( 'Demo data could not be created. Check that WooCommerce is active, then try again.', 'storecanvas-lite' ) ),
			'offer_hidden' => array( 'success', __( 'Offer hidden. It will not show again for your account.', 'storecanvas-lite' ) ),
		);
		if ( isset( $map[ $state ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $state ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $state ][1] ) . '</p></div>';
		}
	}

	/**
	 * Render the page.
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'storecanvas-lite' ) );
		}
		echo '<div class="wrap sclt-hub">';
		echo '<h1>' . esc_html__( 'StoreCanvas Lite help & demo', 'storecanvas-lite' ) . '</h1>';
		$this->render_status();
		echo '<div class="sclt-hub-grid">';

		// Demo.
		echo '<section class="sclt-hub-card"><h2>' . esc_html__( 'Try it with demo data', 'storecanvas-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates one hidden demo product with option fields (lookup pricing, per-character engraving, a show-if field, option stock) and a reusable option group, so you can try the product page, the live price, and the cart.', 'storecanvas-lite' ) . '</p>';
		if ( SCLT_Demo::exists() ) {
			echo '<ul class="sclt-hub-list">';
			foreach ( SCLT_Demo::links() as $link ) {
				echo '<li><a href="' . esc_url( $link['url'] ) . '"' . ( ! empty( $link['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $link['label'] ) . '</a></li>';
			}
			echo '</ul>';
			SCLT_Demo::render_extra();
			$this->action_button( 'sclt_demo_remove', __( 'Remove demo data', 'storecanvas-lite' ) );
		} else {
			$this->action_button( 'sclt_demo_create', __( 'Create demo data', 'storecanvas-lite' ), 'button button-primary' );
		}
		echo '<p class="description">' . esc_html__( 'Demo data is labelled "Demo", is never emailed or texted, and is deleted with one click here (and when you uninstall the plugin).', 'storecanvas-lite' ) . '</p>';
		echo '</section>';

		// Support.
		echo '<section class="sclt-hub-card"><h2>' . esc_html__( 'Get support', 'storecanvas-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Send a ticket straight to the Tillpress team from here. You get a ticket number right away and the reply comes by email. Nothing is sent until you tick the consent box and press Send.', 'storecanvas-lite' ) . '</p>';
		SCLT_Support::instance()->render_form();
		echo '<p>' . esc_html__( 'Other ways to reach us:', 'storecanvas-lite' ) . ' ';
		echo '<a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'storecanvas-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::FORUM_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress.org support forum', 'storecanvas-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support form on tillpress.com', 'storecanvas-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( 'mailto:' . self::SUPPORT_MAIL ) . '">' . esc_html( self::SUPPORT_MAIL ) . '</a></p>';
		echo '</section>';

		// Offer.
		if ( self::should_show_offer( (bool) get_user_meta( get_current_user_id(), self::OFFER_META, true ) ) ) {
			echo '<section class="sclt-hub-card sclt-hub-offer"><h2>' . esc_html__( 'StoreCanvas: 20% off for StoreCanvas Lite users', 'storecanvas-lite' ) . '</h2>';
			echo '<p>' . esc_html__( 'Adds the live design canvas (customers place artwork and text on product mockups), print-ready PNG/SVG/PDF exports, and a production desk.', 'storecanvas-lite' ) . '</p>';
			echo '<p>' . esc_html__( 'Price: $79/year (or $10 for one month). Enter this code at checkout on tillpress.com for 20% off a yearly license:', 'storecanvas-lite' ) . ' <code class="sclt-hub-code">' . esc_html( self::OFFER_CODE ) . '</code></p>';
			echo '<p class="sclt-hub-actions"><a class="button button-primary" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See StoreCanvas', 'storecanvas-lite' ) . '</a> ';
			$this->action_button( 'sclt_offer_dismiss', __( 'Hide this offer', 'storecanvas-lite' ), 'button-link' );
			echo '</p>';
			echo '<p class="description">' . esc_html__( 'StoreCanvas Lite is complete and is never locked. The paid plugin is a separate download; this code is optional.', 'storecanvas-lite' ) . '</p>';
			echo '</section>';
		}

		echo '</div></div>';
	}
}
