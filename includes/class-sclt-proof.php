<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic design proof email (Lite).
 *
 * On the order screen, Order actions > "Send design proof to customer" emails the
 * customer the preview of every designed item (each side), so they can check it and
 * reply with changes. It is information only: there are no approve/reject links
 * (proof approval is part of StoreCanvas Pro). An order note records each send.
 */
class SCLT_Proof {

	const ACTION = 'sclt_send_proof';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( $this, 'send' ) );
	}

	/**
	 * Designed items of an order with their preview links.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $ttl   Link lifetime.
	 * @return array [ [ name, images => [ [ label, url ] ] ] ].
	 */
	public static function items( $order, $ttl = 0 ) {
		$ttl    = $ttl > 0 ? (int) $ttl : (int) apply_filters( 'sclt_canvas_order_link_ttl', 60 * DAY_IN_SECONDS );
		$labels = SCLT_Canvas::side_labels();
		$out    = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}
		foreach ( $order->get_items() as $item ) {
			$design = is_object( $item ) && method_exists( $item, 'get_meta' ) ? $item->get_meta( SCLT_Canvas::ITEM_META ) : null;
			if ( ! SCLT_Canvas::stored_design_is_valid( $design ) ) {
				continue;
			}
			$images = array();
			foreach ( SCLT_Canvas::side_files( $design, 'previews' ) as $side => $file ) {
				$images[] = array(
					'label' => $labels[ $side ],
					'url'   => SCLT_Canvas::file_url( $file, $ttl ),
				);
			}
			$out[] = array(
				'name'    => (string) $item->get_name(),
				'qty'     => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1,
				'summary' => SCLT_Canvas::summary( $design ),
				'images'  => $images,
			);
		}
		return $out;
	}

	/**
	 * Email body for a proof. Pure apart from translation and escaping.
	 *
	 * @param string $order_number Order number.
	 * @param array  $items        items() result.
	 * @return string HTML.
	 */
	public static function body( $order_number, $items ) {
		/* translators: %s: order number */
		$html = '<p>' . esc_html( sprintf( __( 'Here is the proof of your design for order #%s. Please check spelling, colors and placement.', 'storecanvas-lite' ), $order_number ) ) . '</p>';
		foreach ( (array) $items as $it ) {
			$html .= '<h3 style="margin:16px 0 6px;">' . esc_html( $it['name'] ) . ( ! empty( $it['qty'] ) && $it['qty'] > 1 ? ' &times; ' . (int) $it['qty'] : '' ) . '</h3>';
			if ( ! empty( $it['summary'] ) ) {
				$html .= '<p style="margin:0 0 6px;color:#555;">' . esc_html( $it['summary'] ) . '</p>';
			}
			foreach ( (array) $it['images'] as $img ) {
				$html .= '<div style="display:inline-block;margin:0 10px 10px 0;text-align:center;vertical-align:top;">'
					. '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['label'] ) . '" width="240" style="max-width:240px;height:auto;border:1px solid #ddd;" />'
					. '<br /><small>' . esc_html( $img['label'] ) . '</small></div>';
			}
		}
		$html .= '<p>' . esc_html__( 'If anything needs to change, just reply to this email. If it all looks right, there is nothing you need to do.', 'storecanvas-lite' ) . '</p>';
		return $html;
	}

	/**
	 * Add the action only to orders that have a design (WooCommerce passes the order
	 * since 8.x; older versions always show it).
	 *
	 * @param array         $actions Order actions.
	 * @param WC_Order|null $order   Order.
	 * @return array
	 */
	public function order_actions( $actions, $order = null ) {
		if ( is_object( $order ) && ! self::items( $order, HOUR_IN_SECONDS ) ) {
			return $actions;
		}
		$actions[ self::ACTION ] = __( 'Send design proof to customer', 'storecanvas-lite' );
		return $actions;
	}

	/**
	 * Send the proof email.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function send( $order ) {
		if ( ! is_object( $order ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return false;
		}
		$items = self::items( $order );
		$to    = (string) $order->get_billing_email();
		if ( ! $items || ! is_email( $to ) ) {
			$order->add_order_note( __( 'Design proof not sent: the order has no customer design or no billing email.', 'storecanvas-lite' ) );
			return false;
		}
		/* translators: %s: order number */
		$subject = sprintf( __( 'Your design proof for order #%s', 'storecanvas-lite' ), $order->get_order_number() );
		$body    = self::body( $order->get_order_number(), $items );
		$sent    = false;
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer  = WC()->mailer();
			$message = $mailer->wrap_message( __( 'Your design proof', 'storecanvas-lite' ), $body );
			$sent    = (bool) $mailer->send( $to, $subject, $message );
		} else {
			$sent = (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
		$order->add_order_note(
			$sent
				/* translators: %s: email address */
				? sprintf( __( 'Design proof emailed to %s.', 'storecanvas-lite' ), $to )
				/* translators: %s: email address */
				: sprintf( __( 'Design proof could not be emailed to %s.', 'storecanvas-lite' ), $to )
		);
		return $sent;
	}
}
