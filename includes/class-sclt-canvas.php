<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer design canvas (Lite): one print area on the product's main image.
 *
 * Product meta `_sclt_canvas` holds the merchant settings. On the product page the
 * customer adds text and images inside the print area; on add to cart the browser
 * sends the design JSON plus a PNG preview (data URL). Image layers are uploaded
 * one at a time through admin-ajax (nonce + rate limit) when the customer picks them.
 *
 * Files live in uploads/sclt-designs/<random>/pending until an order is placed, then
 * move to uploads/sclt-designs/<random>/ordered. The root folder carries deny-all
 * guards (.htaccess + index.php), every name is 32 random hex characters, and files
 * are only served through admin-post.php?action=sclt_design_file to staff or to the
 * bearer of a signed, expiring link. A daily cron removes pending files that were
 * never ordered. Nothing is sent to any outside service.
 */
class SCLT_Canvas {

	const META          = '_sclt_canvas';
	const CART_KEY      = 'sclt_design';
	const ITEM_META     = '_sclt_design';
	const DIR           = 'sclt-designs';
	const NONCE         = 'sclt_canvas';
	const CRON          = 'sclt_canvas_cleanup';
	const OPT_SECRET    = 'sclt_canvas_secret';
	const OPT_DIR       = 'sclt_canvas_dir';
	const FILE_ACTION   = 'sclt_design_file';
	const UPLOAD_ACTION = 'sclt_canvas_upload';
	const MAX_JSON      = 65536;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_panel' ), 18 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

		add_action( 'wp_ajax_' . self::UPLOAD_ACTION, array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_nopriv_' . self::UPLOAD_ACTION, array( $this, 'ajax_upload' ) );
		add_action( 'admin_post_' . self::FILE_ACTION, array( $this, 'serve_file' ) );
		add_action( 'admin_post_nopriv_' . self::FILE_ACTION, array( $this, 'serve_file' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 20, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 20, 3 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'drop_missing_designs' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'cart_thumbnail' ), 20, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( $this, 'store_api_images' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'to_order_item' ), 20, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'format_order_item_meta' ), 20, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_order_links' ), 20, 3 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_start' ), 0 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_end' ), 999 );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'email_item_preview' ), 20, 4 );

		// Shop/archive buttons of products that require a design open the product page.
		add_filter( 'woocommerce_product_supports', array( $this, 'loop_supports' ), 20, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'loop_url' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'loop_text' ), 20, 2 );

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'enabled'      => false,
			'required'     => false,
			'allow_upload' => true,
			'max_layers'   => 10,
			'fee'          => 0.0,
			'area'         => array(
				'x' => 25.0,
				'y' => 20.0,
				'w' => 50.0,
				'h' => 50.0,
			),
		);
	}

	/**
	 * Clamp merchant settings to safe values. Pure.
	 *
	 * @param mixed $raw Raw settings (post data or stored meta).
	 * @return array
	 */
	public static function sanitize_settings( $raw ) {
		$d   = self::default_settings();
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'required'     => ! empty( $raw['required'] ),
			'allow_upload' => array_key_exists( 'allow_upload', $raw ) ? ! empty( $raw['allow_upload'] ) : $d['allow_upload'],
			'max_layers'   => isset( $raw['max_layers'] ) && is_numeric( $raw['max_layers'] ) ? max( 1, min( 30, (int) $raw['max_layers'] ) ) : $d['max_layers'],
			'fee'          => isset( $raw['fee'] ) && is_numeric( $raw['fee'] ) ? max( 0.0, round( (float) $raw['fee'], 4 ) ) : 0.0,
		);
		$area = isset( $raw['area'] ) && is_array( $raw['area'] ) ? $raw['area'] : array();
		$num  = static function ( $v, $fallback ) {
			return is_numeric( $v ) ? (float) $v : (float) $fallback;
		};
		$x = max( 0.0, min( 95.0, $num( $area['x'] ?? null, $d['area']['x'] ) ) );
		$y = max( 0.0, min( 95.0, $num( $area['y'] ?? null, $d['area']['y'] ) ) );
		$w = max( 5.0, min( 100.0 - $x, $num( $area['w'] ?? null, $d['area']['w'] ) ) );
		$h = max( 5.0, min( 100.0 - $y, $num( $area['h'] ?? null, $d['area']['h'] ) ) );

		$out['area'] = array(
			'x' => round( $x, 2 ),
			'y' => round( $y, 2 ),
			'w' => round( $w, 2 ),
			'h' => round( $h, 2 ),
		);
		return $out;
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_settings( $product_id ) {
		return self::sanitize_settings( get_post_meta( (int) $product_id, self::META, true ) );
	}

	/**
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_enabled( $product_id ) {
		$s = self::get_settings( $product_id );
		return ! empty( $s['enabled'] );
	}

	/**
	 * Built-in font set. Keys are what the design JSON stores.
	 *
	 * @return array key => [ label, css ].
	 */
	public static function fonts() {
		return array(
			'sans'   => array(
				'label' => __( 'Sans', 'storecanvas-lite' ),
				'css'   => 'Arial, Helvetica, sans-serif',
			),
			'serif'  => array(
				'label' => __( 'Serif', 'storecanvas-lite' ),
				'css'   => 'Georgia, "Times New Roman", serif',
			),
			'mono'   => array(
				'label' => __( 'Typewriter', 'storecanvas-lite' ),
				'css'   => '"Courier New", Courier, monospace',
			),
			'bebas'  => array(
				'label' => 'Bebas Neue',
				'css'   => '"SCLT Bebas Neue", Impact, sans-serif',
			),
			'script' => array(
				'label' => 'Dancing Script',
				'css'   => '"SCLT Dancing Script", cursive',
			),
		);
	}

	/**
	 * Largest customer image upload, in bytes.
	 *
	 * @return int
	 */
	public static function max_upload_bytes() {
		$max = (int) apply_filters( 'sclt_canvas_max_upload_bytes', 5 * 1024 * 1024 );
		if ( function_exists( 'wp_max_upload_size' ) ) {
			$max = min( $max, (int) wp_max_upload_size() );
		}
		return max( 1024, $max );
	}

	/**
	 * Largest preview PNG/JPEG, in decoded bytes.
	 *
	 * @return int
	 */
	public static function max_preview_bytes() {
		return max( 1024, (int) apply_filters( 'sclt_canvas_max_preview_bytes', 3 * 1024 * 1024 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Pure validation helpers (unit tested)                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Stored file names are 32 random hex characters plus .png or .jpg. Anything else
	 * (path separators, dots, other extensions) is rejected, so a name can never point
	 * outside the design folder.
	 *
	 * @param mixed $name Candidate name.
	 * @return bool
	 */
	public static function is_valid_file_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[a-f0-9]{32}\.(png|jpg)$/D', $name );
	}

	/**
	 * Any file kept in the protected folder: design images (png/jpg) plus files customers
	 * attach to a file upload option field (gif, webp, pdf). Same strict random-name shape.
	 *
	 * @param mixed $name Candidate name.
	 * @return bool
	 */
	public static function is_stored_file_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[a-f0-9]{32}\.(png|jpg|gif|webp|pdf)$/D', $name );
	}

	/**
	 * Fresh random file name for a detected image type.
	 *
	 * @param string $ext png|jpg.
	 * @return string
	 */
	public static function new_file_name( $ext ) {
		return bin2hex( random_bytes( 16 ) ) . '.' . ( 'jpg' === $ext ? 'jpg' : 'png' );
	}

	/**
	 * Identify PNG/JPEG bytes and their size. Pure (GD-independent).
	 *
	 * @param string $bytes   Raw bytes.
	 * @param int    $max_dim Largest allowed width/height in pixels.
	 * @return array|string [ ext, mime, width, height ] or an error message.
	 */
	public static function inspect_image_bytes( $bytes, $max_dim = 8000 ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 24 ) {
			return __( 'The file is not a PNG or JPEG image.', 'storecanvas-lite' );
		}
		$is_png  = 0 === strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 );
		$is_jpeg = 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 );
		if ( ! $is_png && ! $is_jpeg ) {
			return __( 'The file is not a PNG or JPEG image.', 'storecanvas-lite' );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images are reported below.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return __( 'The file is not a PNG or JPEG image.', 'storecanvas-lite' );
		}
		$type = (int) $info[2];
		if ( ( IMAGETYPE_PNG === $type && ! $is_png ) || ( IMAGETYPE_JPEG === $type && ! $is_jpeg ) || ! in_array( $type, array( IMAGETYPE_PNG, IMAGETYPE_JPEG ), true ) ) {
			return __( 'The file is not a PNG or JPEG image.', 'storecanvas-lite' );
		}
		if ( (int) $info[0] > $max_dim || (int) $info[1] > $max_dim ) {
			/* translators: %d: maximum width/height in pixels */
			return sprintf( __( 'The image is too large. The maximum is %d pixels on each side.', 'storecanvas-lite' ), (int) $max_dim );
		}
		return array(
			'ext'    => IMAGETYPE_PNG === $type ? 'png' : 'jpg',
			'mime'   => IMAGETYPE_PNG === $type ? 'image/png' : 'image/jpeg',
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
		);
	}

	/**
	 * Decode a `data:image/png;base64,...` (or jpeg) URL with size limits. Pure.
	 *
	 * @param mixed $data_url  Posted value.
	 * @param int   $max_bytes Largest decoded size.
	 * @return array|string [ bytes, ext, mime, width, height ] or an error message.
	 */
	public static function decode_data_url( $data_url, $max_bytes ) {
		$bad = __( 'The design preview could not be read. Please try again.', 'storecanvas-lite' );
		if ( ! is_string( $data_url ) || '' === $data_url ) {
			return $bad;
		}
		// Reject before decoding anything larger than the limit allows (base64 is 4/3).
		if ( strlen( $data_url ) > (int) ceil( $max_bytes * 4 / 3 ) + 64 ) {
			return __( 'The design preview is too large.', 'storecanvas-lite' );
		}
		if ( ! preg_match( '#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#D', $data_url, $m ) ) {
			return $bad;
		}
		$bytes = base64_decode( $m[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the customer's canvas PNG data URL.
		if ( false === $bytes || '' === $bytes ) {
			return $bad;
		}
		if ( strlen( $bytes ) > $max_bytes ) {
			return __( 'The design preview is too large.', 'storecanvas-lite' );
		}
		$info = self::inspect_image_bytes( $bytes, 4000 );
		if ( ! is_array( $info ) ) {
			return $info;
		}
		if ( ( 'png' === $m[1] ) !== ( 'png' === $info['ext'] ) ) {
			return $bad;
		}
		$info['bytes'] = $bytes;
		return $info;
	}

	/**
	 * Clamp a number into a range, rounded.
	 *
	 * @param mixed $v   Value.
	 * @param float $min Minimum.
	 * @param float $max Maximum.
	 * @param float $def Default when not numeric.
	 * @return float
	 */
	private static function num( $v, $min, $max, $def ) {
		$v = is_numeric( $v ) ? (float) $v : (float) $def;
		if ( is_nan( $v ) || is_infinite( $v ) ) {
			$v = (float) $def;
		}
		return round( max( $min, min( $max, $v ) ), 4 );
	}

	/**
	 * Whitelist and bound the customer's design JSON. Pure, apart from the optional
	 * $file_exists check for image layers.
	 *
	 * @param mixed         $decoded     Decoded design ({ layers: [...] }).
	 * @param array         $settings    Product canvas settings.
	 * @param callable|null $file_exists Optional callback( name ) => bool.
	 * @return array|string [ layers, files ] or an error message.
	 */
	public static function sanitize_design( $decoded, $settings, $file_exists = null ) {
		$settings = self::sanitize_settings( $settings );
		if ( ! is_array( $decoded ) || ! isset( $decoded['layers'] ) || ! is_array( $decoded['layers'] ) ) {
			return __( 'The design could not be read. Please try again.', 'storecanvas-lite' );
		}
		if ( count( $decoded['layers'] ) > $settings['max_layers'] ) {
			/* translators: %d: maximum number of design layers */
			return sprintf( __( 'A design can have at most %d layers.', 'storecanvas-lite' ), (int) $settings['max_layers'] );
		}
		$fonts  = self::fonts();
		$layers = array();
		$files  = array();
		foreach ( $decoded['layers'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type  = isset( $raw['type'] ) ? (string) $raw['type'] : '';
			$layer = array(
				'type' => $type,
				'x'    => self::num( $raw['x'] ?? 0.5, -0.5, 1.5, 0.5 ),
				'y'    => self::num( $raw['y'] ?? 0.5, -0.5, 1.5, 0.5 ),
				's'    => self::num( $raw['s'] ?? 20, 1, 300, 20 ),
				'rot'  => self::num( $raw['rot'] ?? 0, -360, 360, 0 ),
			);
			if ( 'text' === $type ) {
				$text = isset( $raw['text'] ) && is_scalar( $raw['text'] ) ? sanitize_text_field( (string) $raw['text'] ) : '';
				$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
				if ( '' === trim( $text ) ) {
					continue;
				}
				$font  = isset( $raw['font'] ) && is_string( $raw['font'] ) && isset( $fonts[ $raw['font'] ] ) ? $raw['font'] : 'sans';
				$color = isset( $raw['color'] ) && is_string( $raw['color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/D', $raw['color'] ) ? strtolower( $raw['color'] ) : '#000000';

				$layer['text']  = $text;
				$layer['font']  = $font;
				$layer['color'] = $color;
				$layer['bold']  = ! empty( $raw['bold'] );
			} elseif ( 'image' === $type ) {
				if ( empty( $settings['allow_upload'] ) ) {
					return __( 'Image uploads are not available for this product.', 'storecanvas-lite' );
				}
				$file = $raw['file'] ?? '';
				if ( ! self::is_valid_file_name( $file ) ) {
					return __( 'An uploaded image in this design is not valid. Please upload it again.', 'storecanvas-lite' );
				}
				if ( is_callable( $file_exists ) && ! call_user_func( $file_exists, $file ) ) {
					return __( 'An uploaded image in this design has expired. Please upload it again.', 'storecanvas-lite' );
				}
				$layer['file'] = $file;
				$files[]       = $file;
			} else {
				continue;
			}
			$layers[] = $layer;
		}
		return array(
			'layers' => $layers,
			'files'  => array_values( array_unique( $files ) ),
		);
	}

	/**
	 * Re-check a design array already stored on a cart line / order item. Pure.
	 *
	 * @param mixed $design Stored design.
	 * @return bool
	 */
	public static function stored_design_is_valid( $design ) {
		if ( ! is_array( $design ) || empty( $design['layers'] ) || ! is_array( $design['layers'] ) ) {
			return false;
		}
		if ( ! self::is_valid_file_name( $design['preview'] ?? '' ) ) {
			return false;
		}
		foreach ( (array) ( $design['files'] ?? array() ) as $f ) {
			if ( ! self::is_valid_file_name( $f ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every stored file a design references (preview first).
	 *
	 * @param array $design Stored design.
	 * @return string[]
	 */
	public static function design_files( $design ) {
		if ( ! is_array( $design ) ) {
			return array();
		}
		$out = array();
		if ( self::is_valid_file_name( $design['preview'] ?? '' ) ) {
			$out[] = $design['preview'];
		}
		foreach ( (array) ( $design['files'] ?? array() ) as $f ) {
			if ( self::is_valid_file_name( $f ) ) {
				$out[] = $f;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Design fee for a cart line (0 when the line has no design). Used by
	 * SCLT_Cart_Order::refresh_cart_item_options so the fee goes through the same
	 * server-side pricing as every other option.
	 *
	 * @param array $cart_item Cart item.
	 * @return float
	 */
	public static function fee_for_cart_item( $cart_item ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::CART_KEY ]['layers'] ) ) {
			return 0.0;
		}
		$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		if ( $pid <= 0 ) {
			return 0.0;
		}
		$s = self::get_settings( $pid );
		return (float) $s['fee'];
	}

	/* ------------------------------------------------------------------ */
	/* Signed links                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Per-site HMAC key (stored, never output).
	 *
	 * @return string
	 */
	private static function secret() {
		$s = (string) get_option( self::OPT_SECRET, '' );
		if ( strlen( $s ) < 32 ) {
			$s = bin2hex( random_bytes( 32 ) );
			update_option( self::OPT_SECRET, $s, false );
		}
		return $s . ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' );
	}

	/**
	 * @param string $name    File name.
	 * @param int    $expires Unix time.
	 * @return string
	 */
	public static function sign( $name, $expires ) {
		return hash_hmac( 'sha256', $name . '|' . (int) $expires, self::secret() );
	}

	/**
	 * @param string $name    File name.
	 * @param int    $expires Unix time.
	 * @param string $sig     Signature.
	 * @param int    $now     Current time (tests).
	 * @return bool
	 */
	public static function verify( $name, $expires, $sig, $now = 0 ) {
		$now = $now ? (int) $now : time();
		if ( ! self::is_stored_file_name( $name ) || (int) $expires < $now || ! is_string( $sig ) || '' === $sig ) {
			return false;
		}
		return hash_equals( self::sign( $name, (int) $expires ), $sig );
	}

	/**
	 * Signed, expiring URL for a stored design file.
	 *
	 * @param string $name     File name.
	 * @param int    $ttl      Lifetime in seconds.
	 * @param bool   $download Send as attachment.
	 * @param string $label    Optional download file name (the customer's original name).
	 * @return string
	 */
	public static function file_url( $name, $ttl = DAY_IN_SECONDS, $download = false, $label = '' ) {
		if ( ! self::is_stored_file_name( $name ) ) {
			return '';
		}
		$exp  = time() + max( 60, (int) $ttl );
		$args = array(
			'action' => self::FILE_ACTION,
			'f'      => $name,
			'e'      => $exp,
			's'      => self::sign( $name, $exp ),
		);
		if ( $download ) {
			$args['dl'] = 1;
		}
		if ( '' !== $label ) {
			$args['n'] = rawurlencode( $label );
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Absolute path of uploads/sclt-designs (no trailing slash), or '' without uploads.
	 *
	 * @return string
	 */
	public static function root_dir() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		return untrailingslashit( $up['basedir'] ) . '/' . self::DIR;
	}

	/**
	 * Folder for pending (in a cart) or ordered files, created and guarded on demand.
	 *
	 * @param string $state pending|ordered.
	 * @param bool   $create Create when missing.
	 * @return string Absolute path without trailing slash, or ''.
	 */
	public static function storage_dir( $state = 'pending', $create = true ) {
		$root = self::root_dir();
		if ( '' === $root ) {
			return '';
		}
		$key = (string) get_option( self::OPT_DIR, '' );
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $key ) ) {
			if ( ! $create ) {
				return '';
			}
			$key = bin2hex( random_bytes( 10 ) );
			update_option( self::OPT_DIR, $key, false );
		}
		$state = 'ordered' === $state ? 'ordered' : 'pending';
		$dir   = $root . '/' . $key . '/' . $state;
		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( $create ) {
			self::protect( $root, true );
			self::protect( $root . '/' . $key, false );
			self::protect( $dir, false );
		}
		return $dir;
	}

	/**
	 * Write the directory guards once.
	 *
	 * @param string $dir      Absolute path.
	 * @param bool   $htaccess Also write a deny-all .htaccess.
	 */
	private static function protect( $dir, $htaccess ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file in the plugin's own uploads folder.
		}
		if ( $htaccess && ! file_exists( $dir . '/.htaccess' ) ) {
			$rules = "# StoreCanvas Lite customer designs: served only through admin-post.php?action=sclt_design_file\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n";
			file_put_contents( $dir . '/.htaccess', $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- deny-all guard in the plugin's own uploads folder.
		}
	}

	/**
	 * Absolute path of a stored file (ordered first, then pending), or ''.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function locate( $name ) {
		if ( ! self::is_stored_file_name( $name ) ) {
			return '';
		}
		foreach ( array( 'ordered', 'pending' ) as $state ) {
			$dir = self::storage_dir( $state, false );
			if ( '' !== $dir && is_file( $dir . '/' . $name ) ) {
				return $dir . '/' . $name;
			}
		}
		return '';
	}

	/**
	 * Re-encode and store a validated preview image. Returns the stored file name or ''.
	 *
	 * @param array $img decode_data_url() result.
	 * @return string
	 */
	public static function store_preview( $img ) {
		if ( ! is_array( $img ) || empty( $img['bytes'] ) ) {
			return '';
		}
		$dir = self::storage_dir( 'pending' );
		if ( '' === $dir || ! wp_is_writable( $dir ) ) {
			return '';
		}
		$name = self::new_file_name( 'png' );
		$path = $dir . '/' . $name;

		// Re-encode through GD so only pixel data is kept (drops any trailing payload).
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagepng' ) ) {
			$im = @imagecreatefromstring( $img['bytes'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid data is handled below.
			if ( ! $im ) {
				return '';
			}
			imagealphablending( $im, false );
			imagesavealpha( $im, true );
			$ok = imagepng( $im, $path, 6 );
			imagedestroy( $im );
			if ( ! $ok ) {
				return '';
			}
		} else {
			// No GD: keep the bytes only when they are a verified PNG.
			if ( 'png' !== $img['ext'] ) {
				return '';
			}
			file_put_contents( $path, $img['bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- verified PNG written to the plugin's own uploads folder.
		}
		$check = is_file( $path ) ? self::inspect_image_bytes( (string) file_get_contents( $path ), 4000 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $check ) ) {
			wp_delete_file( $path );
			return '';
		}
		return $name;
	}

	/**
	 * Move a design's files from pending to ordered (kept with the order).
	 *
	 * @param array $design Stored design.
	 */
	public static function mark_ordered( $design ) {
		self::move_to_ordered( self::design_files( $design ) );
	}

	/**
	 * Move stored files from pending to ordered (kept with the order).
	 *
	 * @param string[] $names Stored file names.
	 */
	public static function move_to_ordered( $names ) {
		$pending = self::storage_dir( 'pending', false );
		if ( '' === $pending ) {
			return;
		}
		$ordered = self::storage_dir( 'ordered' );
		foreach ( (array) $names as $name ) {
			if ( ! self::is_stored_file_name( $name ) ) {
				continue;
			}
			$src = $pending . '/' . $name;
			if ( is_file( $src ) && ! is_file( $ordered . '/' . $name ) ) {
				rename( $src, $ordered . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- move within the plugin's own uploads folder.
			}
		}
	}

	/**
	 * Daily cron: delete pending (never ordered) files older than the retention window.
	 *
	 * @param int $now Current time (tests).
	 * @return int Files removed.
	 */
	public static function cleanup( $now = 0 ) {
		$now  = $now ? (int) $now : time();
		$days = max( 1, (int) apply_filters( 'sclt_canvas_pending_days', 7 ) );
		$dir  = self::storage_dir( 'pending', false );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( ! self::is_stored_file_name( $name ) ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && filemtime( $path ) < $now - $days * DAY_IN_SECONDS ) {
				wp_delete_file( $path );
				++$removed;
			}
		}
		return $removed;
	}

	/* ------------------------------------------------------------------ */
	/* Storefront                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Current product on a single product page with the canvas on, or null.
	 *
	 * @return WC_Product|null
	 */
	private static function current_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		return ( $product && self::is_enabled( $product->get_id() ) ) ? $product : null;
	}

	public function enqueue_front() {
		if ( ! self::current_product() ) {
			return;
		}
		wp_enqueue_style( 'sclt-canvas', SCLT_PLUGIN_URL . 'assets/canvas.css', array(), SCLT_VERSION );
		wp_enqueue_script( 'sclt-canvas', SCLT_PLUGIN_URL . 'assets/canvas.js', array(), SCLT_VERSION, true );
	}

	public function enqueue_admin( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $post || 'product' !== get_post_type( $post ) ) {
			return;
		}
		wp_enqueue_script( 'sclt-admin-canvas', SCLT_PLUGIN_URL . 'assets/admin-canvas.js', array(), SCLT_VERSION, true );
	}

	/**
	 * Canvas configuration handed to canvas.js.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function front_config( $product ) {
		$s     = self::get_settings( $product->get_id() );
		$img   = (int) $product->get_image_id();
		$bg    = $img ? (string) wp_get_attachment_image_url( $img, 'large' ) : '';
		$fonts = array();
		foreach ( self::fonts() as $key => $f ) {
			$fonts[] = array(
				'key'   => $key,
				'label' => $f['label'],
				'css'   => $f['css'],
			);
		}
		return array(
			'productId'   => (int) $product->get_id(),
			'bg'          => $bg,
			'area'        => $s['area'],
			'allowUpload' => (bool) $s['allow_upload'],
			'maxLayers'   => (int) $s['max_layers'],
			'required'    => (bool) $s['required'],
			'maxBytes'    => self::max_upload_bytes(),
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'action'      => self::UPLOAD_ACTION,
			'nonce'       => wp_create_nonce( self::NONCE ),
			'fonts'       => $fonts,
			'i18n'        => array(
				'newText'     => __( 'Your text', 'storecanvas-lite' ),
				'noLayers'    => __( 'No layers yet', 'storecanvas-lite' ),
				'textLayer'   => __( 'Text', 'storecanvas-lite' ),
				'imageLayer'  => __( 'Image', 'storecanvas-lite' ),
				'maxLayers'   => __( 'This design already has the maximum number of layers.', 'storecanvas-lite' ),
				'badType'     => __( 'Please choose a PNG or JPEG image.', 'storecanvas-lite' ),
				'tooBig'      => __( 'That image is too large.', 'storecanvas-lite' ),
				'uploading'   => __( 'Uploading image…', 'storecanvas-lite' ),
				'uploaded'    => __( 'Image added.', 'storecanvas-lite' ),
				'uploadFail'  => __( 'The image could not be uploaded. Please try again.', 'storecanvas-lite' ),
				'required'    => __( 'Please add your design before adding this product to the cart.', 'storecanvas-lite' ),
				'selected'    => __( 'Selected:', 'storecanvas-lite' ),
				'deleted'     => __( 'Layer deleted.', 'storecanvas-lite' ),
				'canvasLabel' => __( 'Design preview. Select a layer, then use the arrow keys to move it, + and - to resize, [ and ] to rotate, Delete to remove.', 'storecanvas-lite' ),
			),
		);
	}

	public function render_panel() {
		global $product;
		if ( ! $product instanceof WC_Product || ! self::is_enabled( $product->get_id() ) ) {
			return;
		}
		$s      = self::get_settings( $product->get_id() );
		$config = self::front_config( $product );
		$uid    = 'sclt-canvas-' . (int) $product->get_id();
		?>
		<div class="sclt-canvas" id="<?php echo esc_attr( $uid ); ?>" data-sclt-canvas="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<p class="sclt-canvas__title"><strong><?php esc_html_e( 'Design it', 'storecanvas-lite' ); ?></strong>
			<?php if ( $s['required'] ) : ?>
				<abbr class="required" title="<?php esc_attr_e( 'required', 'storecanvas-lite' ); ?>">*</abbr>
			<?php endif; ?>
			<?php if ( $s['fee'] > 0 ) : ?>
				<span class="sclt-canvas__fee">
				<?php
				/* translators: %s: design fee (formatted price) */
				echo wp_kses_post( sprintf( __( 'Design fee: %s', 'storecanvas-lite' ), wc_price( (float) $s['fee'] ) ) );
				?>
				</span>
			<?php endif; ?>
			</p>
			<div class="sclt-canvas__stage-wrap">
				<canvas class="sclt-canvas__stage" width="800" height="800" tabindex="0" role="application" aria-roledescription="<?php esc_attr_e( 'design canvas', 'storecanvas-lite' ); ?>" aria-label="<?php echo esc_attr( $config['i18n']['canvasLabel'] ); ?>"></canvas>
			</div>
			<div class="sclt-canvas__tools">
				<div class="sclt-canvas__row">
					<label class="sclt-canvas__grow"><span><?php esc_html_e( 'Text', 'storecanvas-lite' ); ?></span>
						<input type="text" class="sclt-canvas__text" maxlength="200" placeholder="<?php esc_attr_e( 'Type your text', 'storecanvas-lite' ); ?>" />
					</label>
					<button type="button" class="button sclt-canvas__btn" data-sclt-act="add-text"><?php esc_html_e( 'Add text', 'storecanvas-lite' ); ?></button>
				</div>
				<?php if ( $s['allow_upload'] ) : ?>
					<div class="sclt-canvas__row">
						<label class="sclt-canvas__grow"><span><?php esc_html_e( 'Upload image (PNG or JPEG)', 'storecanvas-lite' ); ?></span>
							<input type="file" class="sclt-canvas__file" accept="image/png,image/jpeg" />
						</label>
					</div>
				<?php endif; ?>
				<div class="sclt-canvas__row">
					<label><span><?php esc_html_e( 'Layer', 'storecanvas-lite' ); ?></span>
						<select class="sclt-canvas__layers"></select>
					</label>
					<label class="sclt-canvas__text-only"><span><?php esc_html_e( 'Font', 'storecanvas-lite' ); ?></span>
						<select class="sclt-canvas__font">
							<?php foreach ( self::fonts() as $key => $f ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="sclt-canvas__text-only"><span><?php esc_html_e( 'Color', 'storecanvas-lite' ); ?></span>
						<input type="color" class="sclt-canvas__color" value="#000000" />
					</label>
					<label class="sclt-canvas__text-only sclt-canvas__check"><input type="checkbox" class="sclt-canvas__bold" /> <span><?php esc_html_e( 'Bold', 'storecanvas-lite' ); ?></span></label>
				</div>
				<div class="sclt-canvas__row">
					<label class="sclt-canvas__grow"><span><?php esc_html_e( 'Size', 'storecanvas-lite' ); ?></span>
						<input type="range" class="sclt-canvas__size" min="3" max="200" step="1" value="20" />
					</label>
					<label class="sclt-canvas__grow"><span><?php esc_html_e( 'Rotate', 'storecanvas-lite' ); ?></span>
						<input type="range" class="sclt-canvas__rot" min="-180" max="180" step="1" value="0" />
					</label>
				</div>
				<div class="sclt-canvas__row">
					<button type="button" class="button sclt-canvas__btn" data-sclt-act="forward"><?php esc_html_e( 'Bring forward', 'storecanvas-lite' ); ?></button>
					<button type="button" class="button sclt-canvas__btn" data-sclt-act="backward"><?php esc_html_e( 'Send backward', 'storecanvas-lite' ); ?></button>
					<button type="button" class="button sclt-canvas__btn" data-sclt-act="delete"><?php esc_html_e( 'Delete layer', 'storecanvas-lite' ); ?></button>
				</div>
				<p class="sclt-canvas__msg" role="status" aria-live="polite"></p>
			</div>
			<input type="hidden" name="sclt_design" class="sclt-canvas__json" value="" />
			<input type="hidden" name="sclt_design_preview" class="sclt-canvas__preview" value="" />
			<input type="hidden" name="sclt_canvas_nonce" value="<?php echo esc_attr( $config['nonce'] ); ?>" />
		</div>
		<?php
	}

	/**
	 * Whether a simple product needs a design before it can go in the cart.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	private static function needs_design( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'simple' ) ) {
			return false;
		}
		$s = self::get_settings( $product->get_id() );
		return $s['enabled'] && $s['required'];
	}

	public function loop_supports( $supports, $feature, $product ) {
		return ( 'ajax_add_to_cart' === $feature && self::needs_design( $product ) ) ? false : $supports;
	}

	public function loop_url( $url, $product ) {
		return ( self::needs_design( $product ) && ! is_product() ) ? get_permalink( $product->get_id() ) : $url;
	}

	public function loop_text( $text, $product ) {
		return ( self::needs_design( $product ) && ! is_product() ) ? __( 'Design it', 'storecanvas-lite' ) : $text;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX image upload + file proxy                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Per-IP throttle for the unauthenticated upload endpoint and for stored previews.
	 *
	 * @param string $bucket up (image uploads) or design (previews stored on add to cart).
	 * @return bool
	 */
	public static function upload_rate_ok( $bucket = 'up' ) {
		$max = 'up' === $bucket
			? (int) apply_filters( 'sclt_canvas_uploads_per_hour', 30 )
			: (int) apply_filters( 'sclt_canvas_designs_per_hour', 60 );
		if ( $max <= 0 ) {
			return true;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'sclt_cv_' . ( 'up' === $bucket ? 'up' : 'dz' ) . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Validate one $_FILES entry before it is moved. Pure apart from is_uploaded_file.
	 *
	 * @param mixed $file      $_FILES['file'].
	 * @param int   $max_bytes Size limit.
	 * @param bool  $check_uploaded Require is_uploaded_file (false only in tests).
	 * @return true|string
	 */
	public static function check_upload_entry( $file, $max_bytes, $check_uploaded = true ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['size'], $file['error'] ) || is_array( $file['tmp_name'] ) ) {
			return __( 'No image was received.', 'storecanvas-lite' );
		}
		if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] ) {
			return __( 'That image is too large.', 'storecanvas-lite' );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return __( 'No image was received.', 'storecanvas-lite' );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max_bytes ) {
			return __( 'That image is too large.', 'storecanvas-lite' );
		}
		if ( $check_uploaded && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return __( 'No image was received.', 'storecanvas-lite' );
		}
		$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, (int) $max_bytes + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp upload.
		if ( strlen( $head ) > $max_bytes ) {
			return __( 'That image is too large.', 'storecanvas-lite' );
		}
		$info = self::inspect_image_bytes( $head, 8000 );
		return is_array( $info ) ? true : $info;
	}

	public function ajax_upload() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Please reload it and try again.', 'storecanvas-lite' ) ), 403 );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$settings   = self::get_settings( $product_id );
		if ( ! $product_id || empty( $settings['enabled'] ) || empty( $settings['allow_upload'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Image uploads are not available for this product.', 'storecanvas-lite' ) ), 400 );
		}
		if ( ! self::upload_rate_ok() ) {
			wp_send_json_error( array( 'message' => __( 'Too many uploads. Please wait a few minutes and try again.', 'storecanvas-lite' ) ), 429 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- file array; validated by check_upload_entry() and wp_handle_upload().
		$file  = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		$check = self::check_upload_entry( $file, self::max_upload_bytes() );
		if ( true !== $check ) {
			wp_send_json_error( array( 'message' => $check ), 400 );
		}

		$dir = self::storage_dir( 'pending' );
		if ( '' === $dir ) {
			wp_send_json_error( array( 'message' => __( 'The image could not be saved.', 'storecanvas-lite' ) ), 500 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$info     = self::inspect_image_bytes( (string) file_get_contents( $file['tmp_name'] ), 8000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp upload.
		$name     = self::new_file_name( is_array( $info ) ? $info['ext'] : 'png' );
		$dir_cb   = static function ( $dirs ) use ( $dir ) {
			$dirs['path']   = $dir;
			$dirs['subdir'] = '';
			$dirs['url']    = '';
			$dirs['error']  = false;
			return $dirs;
		};
		$name_cb  = static function () use ( $name ) {
			return $name;
		};
		$file['name'] = $name;
		add_filter( 'upload_dir', $dir_cb, 999 );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'action'                   => self::UPLOAD_ACTION,
				'unique_filename_callback' => $name_cb,
				'mimes'                    => array(
					'png'      => 'image/png',
					'jpg|jpeg' => 'image/jpeg',
				),
			)
		);
		remove_filter( 'upload_dir', $dir_cb, 999 );

		$path = $dir . '/' . $name;
		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || ! is_file( $path ) ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			wp_send_json_error( array( 'message' => __( 'The image could not be saved.', 'storecanvas-lite' ) ), 400 );
		}
		$after = self::inspect_image_bytes( (string) file_get_contents( $path ), 8000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $after ) || substr( $name, -3 ) !== $after['ext'] ) {
			wp_delete_file( $path );
			wp_send_json_error( array( 'message' => __( 'The file is not a PNG or JPEG image.', 'storecanvas-lite' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'file'   => $name,
				'width'  => $after['width'],
				'height' => $after['height'],
			)
		);
	}

	/**
	 * Stream a stored design file to staff or to the bearer of a valid signed link.
	 */
	public function serve_file() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorised by capability or HMAC signature below.
		$name = isset( $_GET['f'] ) ? sanitize_file_name( wp_unslash( $_GET['f'] ) ) : '';
		$exp  = isset( $_GET['e'] ) ? absint( $_GET['e'] ) : 0;
		$sig  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$dl   = ! empty( $_GET['dl'] );
		$want = isset( $_GET['n'] ) ? sanitize_file_name( rawurldecode( wp_unslash( $_GET['n'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_file_name().
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_stored_file_name( $name ) ) {
			status_header( 404 );
			exit;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) && ! self::verify( $name, $exp, $sig ) ) {
			status_header( 403 );
			exit;
		}
		$path = self::locate( $name );
		if ( '' === $path ) {
			status_header( 404 );
			exit;
		}
		$ext   = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$mimes = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
		);
		$mime  = $mimes[ $ext ] ?? 'application/octet-stream';
		// PDFs are always downloaded, never rendered inside the site.
		if ( 'pdf' === $ext ) {
			$dl = true;
		}
		$base     = self::is_valid_file_name( $name ) ? 'design-' : 'upload-';
		$filename = $base . substr( $name, 0, 8 ) . '.' . $ext;
		// A friendlier name (the customer's own) is allowed only with the stored file's extension.
		if ( '' !== $want && strtolower( (string) pathinfo( $want, PATHINFO_EXTENSION ) ) === $ext && preg_match( '/^[A-Za-z0-9._-]{1,120}$/', $want ) ) {
			$filename = $want;
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $dl ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'" );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a protected local file.
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Cart                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Raw design JSON + preview from the add-to-cart request.
	 *
	 * @return array [ json, preview, nonce ].
	 */
	private static function posted() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the canvas nonce is verified by the callers.
		$json    = isset( $_POST['sclt_design'] ) && is_string( $_POST['sclt_design'] ) ? wp_unslash( $_POST['sclt_design'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; whitelisted by sanitize_design().
		$preview = isset( $_POST['sclt_design_preview'] ) && is_string( $_POST['sclt_design_preview'] ) ? wp_unslash( $_POST['sclt_design_preview'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- data URL; validated by decode_data_url().
		$nonce   = isset( $_POST['sclt_canvas_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sclt_canvas_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return array( $json, $preview, $nonce );
	}

	/**
	 * Validate the posted design for a product. Pure apart from nonce + file lookup.
	 *
	 * @param string        $json        Design JSON.
	 * @param string        $preview     Preview data URL.
	 * @param string        $nonce       Canvas nonce.
	 * @param array         $settings    Product settings.
	 * @param callable|null $file_exists File lookup.
	 * @return array|string|null [ design, image ] / error / null when no design was sent.
	 */
	public static function validate_request( $json, $preview, $nonce, $settings, $file_exists = null ) {
		$settings = self::sanitize_settings( $settings );
		$json     = is_string( $json ) ? trim( $json ) : '';
		if ( '' === $json ) {
			return null;
		}
		if ( ! wp_verify_nonce( (string) $nonce, self::NONCE ) ) {
			return __( 'This page has expired. Please reload it and add your design again.', 'storecanvas-lite' );
		}
		if ( strlen( $json ) > self::MAX_JSON ) {
			return __( 'The design is too large.', 'storecanvas-lite' );
		}
		$decoded = json_decode( $json, true );
		$design  = self::sanitize_design( $decoded, $settings, $file_exists );
		if ( ! is_array( $design ) ) {
			return $design;
		}
		if ( ! $design['layers'] ) {
			return null;
		}
		$img = self::decode_data_url( $preview, self::max_preview_bytes() );
		if ( ! is_array( $img ) ) {
			return $img;
		}
		return array(
			'design' => $design,
			'image'  => $img,
		);
	}

	/** @var array Per-request cache of validated designs (validation runs before add_cart_item_data). */
	private $validated = array();

	public function validate_add_to_cart( $passed, $product_id, $quantity ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		$settings = self::get_settings( $product_id );
		if ( empty( $settings['enabled'] ) ) {
			return $passed;
		}
		list( $json, $preview, $nonce ) = self::posted();
		$result                         = self::validate_request( $json, $preview, $nonce, $settings, array( __CLASS__, 'locate' ) );
		if ( is_string( $result ) ) {
			wc_add_notice( $result, 'error' );
			return false;
		}
		if ( is_array( $result ) && ! self::upload_rate_ok( 'design' ) ) {
			wc_add_notice( __( 'Too many designs from your connection. Please wait a few minutes and try again.', 'storecanvas-lite' ), 'error' );
			return false;
		}
		if ( null === $result && $settings['required'] ) {
			wc_add_notice( __( 'Please add your design before adding this product to the cart.', 'storecanvas-lite' ), 'error' );
			return false;
		}
		$this->validated[ (int) $product_id ] = $result;
		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		$product_id = (int) $product_id;
		if ( ! array_key_exists( $product_id, $this->validated ) ) {
			// Programmatic add to cart (no validation pass): never accept an unvalidated design.
			return $cart_item_data;
		}
		$result = $this->validated[ $product_id ];
		unset( $this->validated[ $product_id ] );
		if ( ! is_array( $result ) ) {
			return $cart_item_data;
		}
		$preview = self::store_preview( $result['image'] );
		if ( '' === $preview ) {
			return $cart_item_data;
		}
		$cart_item_data[ self::CART_KEY ] = array(
			'v'       => 1,
			'layers'  => $result['design']['layers'],
			'files'   => $result['design']['files'],
			'preview' => $preview,
		);
		$cart_item_data['unique_key']     = md5( $preview . wp_rand() );
		return $cart_item_data;
	}

	/**
	 * Remove cart lines whose design files are gone (expired pending uploads).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function drop_missing_designs( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			$design = $item[ self::CART_KEY ];
			$ok     = self::stored_design_is_valid( $design );
			foreach ( $ok ? self::design_files( $design ) : array() as $f ) {
				if ( '' === self::locate( $f ) ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( __( 'A customized item was removed from your cart because its design has expired. Please design it again.', 'storecanvas-lite' ), 'notice' );
			}
		}
	}

	/**
	 * Thumbnail <img> for a design preview.
	 *
	 * @param string $name  File name.
	 * @param int    $width CSS width.
	 * @param int    $ttl   Link lifetime.
	 * @return string
	 */
	public static function preview_img( $name, $width = 80, $ttl = DAY_IN_SECONDS ) {
		$url = self::file_url( $name, $ttl );
		if ( '' === $url ) {
			return '';
		}
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Your design', 'storecanvas-lite' ) . '" width="' . (int) $width . '" style="max-width:' . (int) $width . 'px;height:auto;border:1px solid #ddd;" />';
	}

	/**
	 * Short summary of a design, e.g. "2 text, 1 image".
	 *
	 * @param array $design Stored design.
	 * @return string
	 */
	public static function summary( $design ) {
		$text  = 0;
		$image = 0;
		foreach ( (array) ( $design['layers'] ?? array() ) as $l ) {
			if ( 'image' === ( $l['type'] ?? '' ) ) {
				++$image;
			} else {
				++$text;
			}
		}
		$parts = array();
		if ( $text ) {
			/* translators: %d: number of text layers */
			$parts[] = sprintf( _n( '%d text', '%d texts', $text, 'storecanvas-lite' ), $text );
		}
		if ( $image ) {
			/* translators: %d: number of image layers */
			$parts[] = sprintf( _n( '%d image', '%d images', $image, 'storecanvas-lite' ), $image );
		}
		return implode( ', ', $parts );
	}

	public function display_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! self::stored_design_is_valid( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$design      = $cart_item[ self::CART_KEY ];
		$item_data[] = array(
			'key'     => __( 'Custom design', 'storecanvas-lite' ),
			'value'   => self::summary( $design ),
			'display' => esc_html( self::summary( $design ) ),
		);
		return $item_data;
	}

	public function cart_thumbnail( $thumbnail, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! self::stored_design_is_valid( $cart_item[ self::CART_KEY ] ) ) {
			return $thumbnail;
		}
		$img = self::preview_img( $cart_item[ self::CART_KEY ]['preview'], 100 );
		return '' !== $img ? $img : $thumbnail;
	}

	public function store_api_images( $images, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! self::stored_design_is_valid( $cart_item[ self::CART_KEY ] ) ) {
			return $images;
		}
		$url = self::file_url( $cart_item[ self::CART_KEY ]['preview'] );
		if ( '' === $url ) {
			return $images;
		}
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => __( 'Your design', 'storecanvas-lite' ),
				'alt'       => __( 'Your design', 'storecanvas-lite' ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Orders                                                              */
	/* ------------------------------------------------------------------ */

	public function to_order_item( $item, $cart_item_key, $values ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		if ( empty( $values[ self::CART_KEY ] ) || ! self::stored_design_is_valid( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$design = $values[ self::CART_KEY ];
		self::mark_ordered( $design );
		$item->add_meta_data( self::ITEM_META, $design, true );
	}

	public function hidden_order_itemmeta( $hidden ) {
		$hidden   = (array) $hidden;
		$hidden[] = self::ITEM_META;
		return array_values( array_unique( $hidden ) );
	}

	/**
	 * Show the design preview in order details, My Account and emails.
	 *
	 * @param array         $formatted Formatted meta.
	 * @param WC_Order_Item $item      Order item.
	 * @return array
	 */
	public function format_order_item_meta( $formatted, $item ) {
		if ( ! is_array( $formatted ) || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $formatted;
		}
		$design = $item->get_meta( self::ITEM_META );
		if ( ! self::stored_design_is_valid( $design ) ) {
			return $formatted;
		}
		$ttl = (int) apply_filters( 'sclt_canvas_order_link_ttl', 60 * DAY_IN_SECONDS );
		if ( $this->in_email ) {
			// Email templates strip <img> from item meta: link here, picture via email_item_preview().
			$display = esc_html( self::summary( $design ) ) . ' <a href="' . esc_url( self::file_url( $design['preview'], $ttl ) ) . '">' . esc_html__( 'View design', 'storecanvas-lite' ) . '</a>';
		} else {
			$display = '<p>' . self::preview_img( $design['preview'], 120, $ttl ) . '<br />' . esc_html( self::summary( $design ) ) . '</p>';
		}

		$formatted['sclt_design'] = (object) array(
			'key'           => __( 'Custom design', 'storecanvas-lite' ),
			'value'         => self::summary( $design ),
			'display_key'   => esc_html__( 'Custom design', 'storecanvas-lite' ),
			'display_value' => $display,
		);
		return $formatted;
	}

	/** @var bool True while WooCommerce renders an email's order table. */
	private $in_email = false;

	public function email_start() {
		$this->in_email = true;
	}

	public function email_end() {
		$this->in_email = false;
	}

	/**
	 * HTML emails: the design preview under the item.
	 *
	 * @param int           $item_id    Item ID.
	 * @param WC_Order_Item $item       Item.
	 * @param WC_Order      $order      Order.
	 * @param bool          $plain_text Plain-text email.
	 */
	public function email_item_preview( $item_id, $item, $order, $plain_text = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		if ( ! $this->in_email || $plain_text || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}
		$design = $item->get_meta( self::ITEM_META );
		if ( ! self::stored_design_is_valid( $design ) ) {
			return;
		}
		$ttl = (int) apply_filters( 'sclt_canvas_order_link_ttl', 60 * DAY_IN_SECONDS );
		echo '<div class="sclt-email-design" style="margin-top:6px;">' . wp_kses_post( self::preview_img( $design['preview'], 120, $ttl ) ) . '</div>';
	}

	/**
	 * Admin order screen: download links for the preview and the customer's originals.
	 *
	 * @param int           $item_id Item ID.
	 * @param WC_Order_Item $item    Item.
	 * @param mixed         $product Product.
	 */
	public function admin_order_links( $item_id, $item, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$design = $item->get_meta( self::ITEM_META );
		if ( ! self::stored_design_is_valid( $design ) ) {
			return;
		}
		echo '<div class="sclt-design-files" style="margin-top:6px;">';
		echo '<strong>' . esc_html__( 'Design files:', 'storecanvas-lite' ) . '</strong> ';
		$links   = array();
		$links[] = '<a href="' . esc_url( self::file_url( $design['preview'], HOUR_IN_SECONDS, true ) ) . '">' . esc_html__( 'Preview (PNG)', 'storecanvas-lite' ) . '</a>';
		$n       = 0;
		foreach ( (array) $design['files'] as $f ) {
			++$n;
			/* translators: %d: uploaded image number */
			$links[] = '<a href="' . esc_url( self::file_url( $f, HOUR_IN_SECONDS, true ) ) . '">' . esc_html( sprintf( __( 'Customer image %d', 'storecanvas-lite' ), $n ) ) . '</a>';
		}
		echo wp_kses_post( implode( ' &middot; ', $links ) );
		$texts = array();
		foreach ( (array) $design['layers'] as $l ) {
			if ( 'text' === ( $l['type'] ?? '' ) && isset( $l['text'] ) ) {
				$fonts   = self::fonts();
				$font    = $fonts[ $l['font'] ?? 'sans' ]['label'] ?? '';
				$texts[] = '“' . $l['text'] . '” (' . $font . ', ' . ( $l['color'] ?? '' ) . ')';
			}
		}
		if ( $texts ) {
			echo '<br /><span class="description">' . esc_html__( 'Text:', 'storecanvas-lite' ) . ' ' . esc_html( implode( '; ', $texts ) ) . '</span>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Admin product tab                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Canvas section of the StoreCanvas Lite product tab.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function render_admin_section( $product_id ) {
		$s   = self::get_settings( $product_id );
		$img = (int) get_post_thumbnail_id( $product_id );
		$bg  = $img ? (string) wp_get_attachment_image_url( $img, 'medium_large' ) : '';
		?>
		<div class="options_group sclt-admin-section sclt-canvas-admin">
			<h4 style="padding-left:12px;"><?php esc_html_e( 'Customer design canvas', 'storecanvas-lite' ); ?></h4>
			<p class="form-field" style="padding-left:12px;">
				<label class="sclt-inline"><input type="checkbox" name="sclt_canvas[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Let customers design this product (text and images on the main product image)', 'storecanvas-lite' ); ?></label><br />
				<label class="sclt-inline"><input type="checkbox" name="sclt_canvas[required]" value="1" <?php checked( $s['required'] ); ?> /> <?php esc_html_e( 'A design is required to add to cart', 'storecanvas-lite' ); ?></label><br />
				<label class="sclt-inline"><input type="checkbox" name="sclt_canvas[allow_upload]" value="1" <?php checked( $s['allow_upload'] ); ?> /> <?php esc_html_e( 'Allow image uploads (PNG/JPEG)', 'storecanvas-lite' ); ?></label>
			</p>
			<p class="form-field sclt-canvas-nums" style="padding-left:12px;">
				<label class="sclt-inline"><?php esc_html_e( 'Max layers', 'storecanvas-lite' ); ?> <input type="number" name="sclt_canvas[max_layers]" min="1" max="30" step="1" value="<?php echo esc_attr( (string) $s['max_layers'] ); ?>" style="width:70px;" /></label>
				<label class="sclt-inline">
				<?php
				/* translators: %s: currency symbol */
				echo esc_html( sprintf( __( 'Design fee (%s)', 'storecanvas-lite' ), get_woocommerce_currency_symbol() ) );
				?>
				<input type="number" name="sclt_canvas[fee]" min="0" step="0.01" value="<?php echo esc_attr( (string) $s['fee'] ); ?>" style="width:90px;" /></label>
			</p>
			<p class="form-field sclt-canvas-nums" style="padding-left:12px;">
				<span class="description"><?php esc_html_e( 'Print area, in % of the main product image. Drag or resize the box on the image, or type the numbers.', 'storecanvas-lite' ); ?></span><br />
				<?php
				$labels = array(
					'x' => __( 'Left', 'storecanvas-lite' ),
					'y' => __( 'Top', 'storecanvas-lite' ),
					'w' => __( 'Width', 'storecanvas-lite' ),
					'h' => __( 'Height', 'storecanvas-lite' ),
				);
				foreach ( $labels as $k => $label ) :
					?>
					<label class="sclt-inline"><?php echo esc_html( $label ); ?> <input type="number" class="sclt-canvas-area" data-k="<?php echo esc_attr( $k ); ?>" name="sclt_canvas[area][<?php echo esc_attr( $k ); ?>]" min="0" max="100" step="0.5" value="<?php echo esc_attr( (string) $s['area'][ $k ] ); ?>" style="width:70px;" /></label>
				<?php endforeach; ?>
			</p>
			<div class="sclt-canvas-editor" style="padding:0 12px 12px;">
				<?php if ( $bg ) : ?>
					<div class="sclt-canvas-editor__box" style="position:relative;display:inline-block;max-width:360px;line-height:0;touch-action:none;user-select:none;">
						<img src="<?php echo esc_url( $bg ); ?>" alt="" style="max-width:100%;height:auto;display:block;" draggable="false" />
						<div class="sclt-canvas-editor__rect" tabindex="0" aria-label="<?php esc_attr_e( 'Print area. Arrow keys move it; Shift and arrow keys resize it.', 'storecanvas-lite' ); ?>" style="position:absolute;border:2px dashed #2271b1;background:rgba(34,113,177,.15);cursor:move;box-sizing:border-box;">
							<span class="sclt-canvas-editor__handle" style="position:absolute;right:-7px;bottom:-7px;width:14px;height:14px;background:#2271b1;border-radius:2px;cursor:nwse-resize;"></span>
						</div>
					</div>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Set a product image to draw the print area on it. The numbers above work without one.', 'storecanvas-lite' ); ?></p>
				<?php endif; ?>
				<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Need several views or print areas, clipart, templates or print-ready PDF/SVG files? Those are in the paid StoreCanvas plugin.', 'storecanvas-lite' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save handler (called from SCLT_Admin_Product::save, which checks the capability;
	 * WordPress core verifies the product-edit nonce before woocommerce_process_product_meta).
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save_settings( $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- product edit screen (core nonce); every key is clamped by sanitize_settings().
		$raw = isset( $_POST['sclt_canvas'] ) && is_array( $_POST['sclt_canvas'] ) ? wp_unslash( $_POST['sclt_canvas'] ) : array();
		if ( ! isset( $raw['allow_upload'] ) ) {
			$raw['allow_upload'] = 0;
		}
		update_post_meta( (int) $product_id, self::META, self::sanitize_settings( $raw ) );
	}
}
