<?php
/**
 * File upload option fields: type and size limits, safe storage, order download links.
 *
 * Customer files are checked (allowed extension, real content, size), saved under a
 * random name in the same protected folder as design files (uploads/sclt-designs),
 * moved to the order's folder at checkout, and offered to shop staff as download links.
 * Files of carts that never became orders are removed by the daily cleanup.
 *
 * @package StoreCanvasLite
 */

defined( 'ABSPATH' ) || exit;

class SCLT_Option_Files {

	/** Cart item key: field id => [ file, name, size ]. */
	const CART_KEY = 'sclt_option_files';

	/** Order item meta (hidden, underscore). */
	const ITEM_META = '_sclt_option_files';

	/** Default per-field limit in megabytes. */
	const DEFAULT_MAX_MB = 5;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_order_links' ), 25, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Field settings (pure)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * File types a merchant can allow: extension => label. SVG, HTML, scripts and
	 * archives are never offered because they can carry code.
	 *
	 * @return string[]
	 */
	public static function type_labels() {
		return array(
			'jpg'  => 'JPG',
			'png'  => 'PNG',
			'gif'  => 'GIF',
			'webp' => 'WebP',
			'pdf'  => 'PDF',
		);
	}

	/**
	 * Types used when a field does not choose any.
	 *
	 * @return string[]
	 */
	public static function default_types() {
		return array( 'jpg', 'png', 'pdf' );
	}

	/**
	 * Clean a list of extensions (array or "jpg, png") to known types. Pure.
	 *
	 * @param mixed $raw Raw list.
	 * @return string[]
	 */
	public static function sanitize_types( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,.;|]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $t ) {
			$t = strtolower( trim( (string) $t ) );
			if ( 'jpeg' === $t ) {
				$t = 'jpg';
			}
			if ( isset( self::type_labels()[ $t ] ) && ! in_array( $t, $out, true ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Allowed types for a field.
	 *
	 * @param array $field Field definition.
	 * @return string[]
	 */
	public static function field_types( $field ) {
		$types = self::sanitize_types( is_array( $field ) ? ( $field['file_types'] ?? array() ) : array() );
		return $types ? $types : self::default_types();
	}

	/**
	 * Largest file for a field, in bytes (also capped by the server's upload limit).
	 *
	 * @param array $field Field definition.
	 * @return int
	 */
	public static function field_max_bytes( $field ) {
		$mb = is_array( $field ) && isset( $field['max_mb'] ) && is_numeric( $field['max_mb'] ) && (float) $field['max_mb'] > 0
			? (float) $field['max_mb']
			: self::DEFAULT_MAX_MB;
		$max = (int) round( $mb * 1024 * 1024 );
		if ( function_exists( 'wp_max_upload_size' ) ) {
			$server = (int) wp_max_upload_size();
			if ( $server > 0 ) {
				$max = min( $max, $server );
			}
		}
		return max( 1024, $max );
	}

	/**
	 * Value for the file input's accept attribute, e.g. ".jpg,.jpeg,.png,.pdf".
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	public static function accept_attr( $field ) {
		$parts = array();
		foreach ( self::field_types( $field ) as $t ) {
			$parts[] = '.' . $t;
			if ( 'jpg' === $t ) {
				$parts[] = '.jpeg';
			}
		}
		return implode( ',', $parts );
	}

	/**
	 * Customer-facing hint, e.g. "JPG, PNG or PDF, up to 5 MB."
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	public static function hint( $field ) {
		$labels = array();
		foreach ( self::field_types( $field ) as $t ) {
			$labels[] = self::type_labels()[ $t ];
		}
		$last  = array_pop( $labels );
		$types = $labels ? implode( ', ', $labels ) . ' ' . __( 'or', 'storecanvas-lite' ) . ' ' . $last : $last;
		$mb    = self::field_max_bytes( $field ) / ( 1024 * 1024 );
		$size  = ( abs( $mb - round( $mb ) ) < 0.05 ) ? (string) (int) round( $mb ) : number_format( (float) $mb, 1 );
		/* translators: 1: file types such as "JPG, PNG or PDF" 2: size in MB */
		return sprintf( __( '%1$s, up to %2$s MB.', 'storecanvas-lite' ), $types, $size );
	}

	/* ------------------------------------------------------------------ */
	/* Content checks (pure)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Real type of a file from its first bytes, or '' when it is none of the known types.
	 *
	 * @param string $head First bytes of the file (16 or more).
	 * @return string jpg|png|gif|webp|pdf|''
	 */
	public static function sniff( $head ) {
		if ( ! is_string( $head ) || strlen( $head ) < 12 ) {
			return '';
		}
		if ( "\x89PNG\r\n\x1a\n" === substr( $head, 0, 8 ) ) {
			return 'png';
		}
		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'jpg';
		}
		if ( 'GIF87a' === substr( $head, 0, 6 ) || 'GIF89a' === substr( $head, 0, 6 ) ) {
			return 'gif';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'webp';
		}
		if ( '%PDF-' === substr( $head, 0, 5 ) ) {
			return 'pdf';
		}
		return '';
	}

	/**
	 * Normalised extension of a customer's file name ("photo.JPEG" => "jpg").
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function name_ext( $name ) {
		$ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		return 'jpeg' === $ext ? 'jpg' : $ext;
	}

	/**
	 * Check one uploaded file against a field's limits before it is stored.
	 *
	 * @param mixed $file           Single $_FILES-style entry (name, tmp_name, size, error).
	 * @param array $field          Field definition.
	 * @param bool  $check_uploaded Require is_uploaded_file (false only in tests).
	 * @return string|WP_Error The file's extension (jpg|png|gif|webp|pdf) or an error to show.
	 */
	public static function check( $file, $field, $check_uploaded = true ) {
		$lab = is_array( $field ) ? ( $field['label'] ?? ( $field['id'] ?? '' ) ) : '';
		if ( ! is_array( $file ) || ! isset( $file['name'], $file['tmp_name'], $file['size'], $file['error'] ) || is_array( $file['tmp_name'] ) ) {
			/* translators: %s: field label */
			return new WP_Error( 'sclt_file', sprintf( __( 'The file for “%s” was not received. Please try again.', 'storecanvas-lite' ), $lab ) );
		}
		$max = self::field_max_bytes( $field );
		/* translators: 1: field label 2: file size limit such as "5 MB" */
		$too_big = sprintf( __( 'The file for “%1$s” is too large. The limit is %2$s.', 'storecanvas-lite' ), $lab, size_format( $max ) );
		if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] ) {
			return new WP_Error( 'sclt_file', $too_big );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			/* translators: %s: field label */
			return new WP_Error( 'sclt_file', sprintf( __( 'The file for “%s” was not received. Please try again.', 'storecanvas-lite' ), $lab ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max ) {
			return new WP_Error( 'sclt_file', $too_big );
		}
		$types = self::field_types( $field );
		/* translators: 1: field label 2: allowed types such as "JPG, PNG or PDF, up to 5 MB." */
		$bad_type = sprintf( __( 'The file for “%1$s” is not an allowed type. Allowed: %2$s', 'storecanvas-lite' ), $lab, self::hint( $field ) );
		$ext      = self::name_ext( $file['name'] );
		if ( ! in_array( $ext, $types, true ) ) {
			return new WP_Error( 'sclt_file', $bad_type );
		}
		if ( $check_uploaded && ! is_uploaded_file( $file['tmp_name'] ) ) {
			/* translators: %s: field label */
			return new WP_Error( 'sclt_file', sprintf( __( 'The file for “%s” was not received. Please try again.', 'storecanvas-lite' ), $lab ) );
		}
		if ( filesize( $file['tmp_name'] ) > $max ) {
			return new WP_Error( 'sclt_file', $too_big );
		}
		$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- first bytes of a local temp upload.
		if ( self::sniff( $head ) !== $ext ) {
			// The name says one type but the content is another (or unknown).
			return new WP_Error( 'sclt_file', $bad_type );
		}
		return $ext;
	}

	/* ------------------------------------------------------------------ */
	/* Request + storage                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The uploaded file for one option field from $_FILES['sclt_option'], or null when none.
	 *
	 * @param string $fid Field id.
	 * @return array|null
	 */
	public static function posted_file( $fid ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- WooCommerce add-to-cart request; the entry is validated by check().
		if ( ! isset( $_FILES['sclt_option']['name'][ $fid ] ) ) {
			return null;
		}
		$f    = $_FILES['sclt_option'];
		$file = array(
			'name'     => is_string( $f['name'][ $fid ] ) ? $f['name'][ $fid ] : '',
			'type'     => isset( $f['type'][ $fid ] ) && is_string( $f['type'][ $fid ] ) ? $f['type'][ $fid ] : '',
			'tmp_name' => isset( $f['tmp_name'][ $fid ] ) ? $f['tmp_name'][ $fid ] : '',
			'error'    => isset( $f['error'][ $fid ] ) && ! is_array( $f['error'][ $fid ] ) ? (int) $f['error'][ $fid ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $f['size'][ $fid ] ) && ! is_array( $f['size'][ $fid ] ) ? (int) $f['size'][ $fid ] : 0,
		);
		// phpcs:enable
		if ( '' === $file['name'] || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return null;
		}
		return $file;
	}

	/**
	 * Move a checked upload into the protected folder under a random name.
	 *
	 * @param array  $file $_FILES-style entry that passed check().
	 * @param string $ext  Extension check() returned.
	 * @return string Stored file name, or '' on failure.
	 */
	public static function store( $file, $ext ) {
		$dir = SCLT_Canvas::storage_dir( 'pending' );
		if ( '' === $dir || ! wp_is_writable( $dir ) ) {
			return '';
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$path = $dir . '/' . $name;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$mimes  = array(
			'jpg'  => array( 'jpg|jpeg' => 'image/jpeg' ),
			'png'  => array( 'png' => 'image/png' ),
			'gif'  => array( 'gif' => 'image/gif' ),
			'webp' => array( 'webp' => 'image/webp' ),
			'pdf'  => array( 'pdf' => 'application/pdf' ),
		);
		$dir_cb = static function ( $dirs ) use ( $dir ) {
			$dirs['path']   = $dir;
			$dirs['subdir'] = '';
			$dirs['url']    = '';
			$dirs['error']  = false;
			return $dirs;
		};
		$name_cb = static function () use ( $name ) {
			return $name;
		};
		$file['name'] = $name;
		add_filter( 'upload_dir', $dir_cb, 999 );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'unique_filename_callback' => $name_cb,
				'mimes'                    => $mimes[ $ext ] ?? array(),
			)
		);
		remove_filter( 'upload_dir', $dir_cb, 999 );
		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || ! is_file( $path ) ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			return '';
		}
		return $name;
	}

	/**
	 * Stored files of a cart line or order item meta, keeping only well-formed rows. Pure.
	 *
	 * @param mixed $files Field id => [ file, name, size ].
	 * @return array
	 */
	public static function clean_list( $files ) {
		$out = array();
		foreach ( (array) $files as $fid => $row ) {
			if ( ! is_array( $row ) || ! SCLT_Canvas::is_stored_file_name( $row['file'] ?? '' ) ) {
				continue;
			}
			$out[ sanitize_key( (string) $fid ) ] = array(
				'file' => $row['file'],
				'name' => sanitize_file_name( (string) ( $row['name'] ?? '' ) ),
				'size' => max( 0, (int) ( $row['size'] ?? 0 ) ),
			);
		}
		return $out;
	}

	/**
	 * Checkout: keep the files with the order.
	 *
	 * @param WC_Order_Item_Product $item   Order item.
	 * @param array                 $values Cart item.
	 */
	public static function to_order_item( $item, $values ) {
		$files = self::clean_list( $values[ self::CART_KEY ] ?? array() );
		if ( ! $files ) {
			return;
		}
		SCLT_Canvas::move_to_ordered( wp_list_pluck( $files, 'file' ) );
		$item->add_meta_data( self::ITEM_META, $files, true );
	}

	/**
	 * Admin order screen: download links for the customer's files.
	 *
	 * @param int           $item_id Item ID.
	 * @param WC_Order_Item $item    Item.
	 * @param mixed         $product Product.
	 */
	public function admin_order_links( $item_id, $item, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$files = self::clean_list( $item->get_meta( self::ITEM_META ) );
		if ( ! $files ) {
			return;
		}
		$links = array();
		foreach ( $files as $row ) {
			$label = '' !== $row['name'] ? $row['name'] : $row['file'];
			if ( '' === SCLT_Canvas::locate( $row['file'] ) ) {
				$links[] = esc_html( $label ) . ' <span class="description">(' . esc_html__( 'file missing', 'storecanvas-lite' ) . ')</span>';
				continue;
			}
			$links[] = '<a href="' . esc_url( SCLT_Canvas::file_url( $row['file'], HOUR_IN_SECONDS, true, $row['name'] ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<div class="sclt-option-files" style="margin-top:6px;"><strong>' . esc_html__( 'Customer files:', 'storecanvas-lite' ) . '</strong> ';
		echo wp_kses_post( implode( ' &middot; ', $links ) );
		echo '</div>';
	}
}
