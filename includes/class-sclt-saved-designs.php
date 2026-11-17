<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "My designs": customers save a design and load it again later.
 *
 * Logged-in customers: the design is kept in their user meta (up to MAX designs) and
 * its images are copied to the protected "saved" folder, where they stay until the
 * customer deletes the design. Guests: the browser keeps the design (localStorage) and
 * the server copies its images to the "kept" folder, which the daily cleanup empties
 * after 90 days. Every design is checked with SCLT_Canvas::sanitize_design() on save
 * and again when it goes into the cart. Images are only ever handed out as signed,
 * expiring links. Nothing is sent to any outside service.
 */
class SCLT_Saved_Designs {

	const ACTION    = 'sclt_designs';
	const USER_META = '_sclt_saved_designs';
	const MAX       = 20;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'ajax' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Pure helpers (unit tested)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Clean a design name.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
		return '' === trim( $name ) ? __( 'My design', 'storecanvas-lite' ) : $name;
	}

	/**
	 * Well-formed stored list (drops anything that is not a saved design).
	 *
	 * @param mixed $list Stored user meta.
	 * @return array
	 */
	public static function clean_list( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['design']['layers'] ) || ! preg_match( '/^[a-f0-9]{12}$/', (string) $row['id'] ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Put a new design first and trim the list to $max.
	 *
	 * @param array $list  Current list.
	 * @param array $entry New entry.
	 * @param int   $max   Most designs kept.
	 * @return array [ new list, dropped entries ].
	 */
	public static function add_entry( $list, $entry, $max = self::MAX ) {
		$list = self::clean_list( $list );
		array_unshift( $list, $entry );
		$max = max( 1, (int) $max );
		return array( array_slice( $list, 0, $max ), array_slice( $list, $max ) );
	}

	/**
	 * Image files still used by a list of saved designs.
	 *
	 * @param array $list Saved designs.
	 * @return string[]
	 */
	public static function files_in_use( $list ) {
		$out = array();
		foreach ( self::clean_list( $list ) as $row ) {
			foreach ( (array) ( $row['design']['files'] ?? array() ) as $f ) {
				if ( SCLT_Canvas::is_valid_file_name( $f ) ) {
					$out[ $f ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Files of the dropped designs that no remaining design uses.
	 *
	 * @param array $dropped Removed designs.
	 * @param array $kept    Remaining designs.
	 * @return string[]
	 */
	public static function orphan_files( $dropped, $kept ) {
		return array_values( array_diff( self::files_in_use( $dropped ), self::files_in_use( $kept ) ) );
	}

	/**
	 * Designs of one product, newest first, as sent to the browser.
	 *
	 * @param array $list       Saved designs.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public static function for_product( $list, $product_id ) {
		$out = array();
		foreach ( self::clean_list( $list ) as $row ) {
			if ( (int) ( $row['product_id'] ?? 0 ) !== (int) $product_id ) {
				continue;
			}
			$out[] = array(
				'id'     => $row['id'],
				'name'   => (string) ( $row['name'] ?? '' ),
				'time'   => (int) ( $row['time'] ?? 0 ),
				'design' => array(
					'v'      => 2,
					'layers' => $row['design']['layers'],
				),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private static function fail( $message, $status = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	public function ajax() {
		if ( ! check_ajax_referer( SCLT_Canvas::NONCE, 'nonce', false ) ) {
			self::fail( __( 'This page has expired. Please reload it and try again.', 'storecanvas-lite' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$op         = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$settings   = SCLT_Canvas::get_settings( $product_id );
		if ( ! $product_id || empty( $settings['enabled'] ) ) {
			self::fail( __( 'Designs are not available for this product.', 'storecanvas-lite' ) );
		}
		$user_id = get_current_user_id();

		switch ( $op ) {
			case 'save':
				if ( ! SCLT_Canvas::upload_rate_ok( 'save' ) ) {
					self::fail( __( 'Too many saves. Please wait a few minutes and try again.', 'storecanvas-lite' ), 429 );
				}
				$json = isset( $_POST['design'] ) && is_string( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; whitelisted by sanitize_design().
				if ( '' === $json || strlen( $json ) > SCLT_Canvas::MAX_JSON ) {
					self::fail( __( 'The design could not be saved. Please try again.', 'storecanvas-lite' ) );
				}
				$design = SCLT_Canvas::sanitize_design( json_decode( $json, true ), $settings, array( 'SCLT_Canvas', 'locate' ) );
				if ( ! is_array( $design ) ) {
					self::fail( $design );
				}
				if ( ! $design['layers'] ) {
					self::fail( __( 'Add something to your design before saving it.', 'storecanvas-lite' ) );
				}
				if ( ! SCLT_Canvas::keep_files( $design['files'], $user_id ? 'saved' : 'kept' ) ) {
					self::fail( __( 'The design could not be saved. Please try again.', 'storecanvas-lite' ), 500 );
				}
				if ( ! $user_id ) {
					// Guests keep the design in their browser; the images now outlive the cart.
					wp_send_json_success( array( 'saved' => true ) );
				}
				$entry = array(
					'id'         => bin2hex( random_bytes( 6 ) ),
					'product_id' => $product_id,
					'name'       => self::clean_name( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_name() sanitizes.
					'time'       => time(),
					'design'     => array(
						'v'      => 2,
						'layers' => $design['layers'],
						'files'  => $design['files'],
					),
				);
				list( $list, $dropped ) = self::add_entry( get_user_meta( $user_id, self::USER_META, true ), $entry );
				update_user_meta( $user_id, self::USER_META, $list );
				self::delete_saved_files( self::orphan_files( $dropped, $list ) );
				wp_send_json_success( array( 'id' => $entry['id'] ) );
				break;

			case 'list':
				if ( ! $user_id ) {
					wp_send_json_success( array( 'designs' => array() ) );
				}
				wp_send_json_success( array( 'designs' => self::for_product( get_user_meta( $user_id, self::USER_META, true ), $product_id ) ) );
				break;

			case 'delete':
				if ( ! $user_id ) {
					self::fail( __( 'Please log in.', 'storecanvas-lite' ), 403 );
				}
				$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
				$list    = self::clean_list( get_user_meta( $user_id, self::USER_META, true ) );
				$keep    = array();
				$dropped = array();
				foreach ( $list as $row ) {
					if ( $row['id'] === $id ) {
						$dropped[] = $row;
					} else {
						$keep[] = $row;
					}
				}
				update_user_meta( $user_id, self::USER_META, $keep );
				self::delete_saved_files( self::orphan_files( $dropped, $keep ) );
				wp_send_json_success( array( 'deleted' => count( $dropped ) ) );
				break;

			case 'files':
				$names = isset( $_POST['files'] ) && is_string( $_POST['files'] ) ? json_decode( wp_unslash( $_POST['files'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each name is checked by is_valid_file_name().
				$urls  = array();
				foreach ( array_slice( is_array( $names ) ? $names : array(), 0, 30 ) as $name ) {
					// Names are 128-bit random, so knowing one is the permission to see it.
					// Files that belong to orders are never handed out here.
					if ( SCLT_Canvas::is_valid_file_name( $name ) && '' !== SCLT_Canvas::locate_in( $name, array( 'saved', 'kept', 'pending' ) ) ) {
						$urls[ $name ] = SCLT_Canvas::file_url( $name, DAY_IN_SECONDS );
					}
				}
				wp_send_json_success( array( 'urls' => $urls ) );
				break;

			default:
				self::fail( __( 'Unknown request.', 'storecanvas-lite' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Delete files from the saved folder (never from orders).
	 *
	 * @param string[] $names File names.
	 */
	private static function delete_saved_files( $names ) {
		foreach ( (array) $names as $name ) {
			$path = SCLT_Canvas::locate_in( $name, array( 'saved' ) );
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
	}
}
