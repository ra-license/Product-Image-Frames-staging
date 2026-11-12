<?php
/**
 * Plugin Name: R&A Product Image Frames
 * Description: Keeps product photos the same size and lined up on category and product pages without painting bars into the photos. Each photo sits centered in a white box, and the plugin makes light, compressed copies of product photos in the background.
 * Version: 0.3.4
 * Author: RA Marketing
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * License: GPL-2.0-or-later
 * Text Domain: ra-product-image-frames
 */

// v0.1.0 (2026-11-10): first version, the stopgap for the black bars.
//
// Why this exists: Smart Image Resize PRO makes every product photo the same
// shape by painting padding into a new copy of the file. In 1.18.0 a blank
// "Unified Background Color" started painting that padding black, so
// thousands of copies on StarFine, Slone and Indian River now carry black
// bars. The original uploads were never touched (36 of 36 sampled on
// StarFine were clean).
//
// What 0.1.0 does, on the storefront only:
// - When WooCommerce or the theme asks for a catalog, product-page or gallery
//   copy of a product photo, the page shows the clean original instead.
// - The page itself draws the uniform box: a white frame in the same shape the
//   store already uses (4:3 catalog, 1:1 product page on StarFine), with the
//   photo centered inside and nothing cropped (CSS aspect-ratio + object-fit:
//   contain). No pixels are painted, so a color setting can't ruin files.
// - Uses only WordPress's own image tag filter. Nothing here reads or depends
//   on Smart Image Resize's code, so its updates can't break this.
//
// v0.1.1 (2026-11-10): fix found live on StarFine right after 0.1.0 went on.
// The catalog and product-page frames were missing because Smart Image Resize
// registers its sizes without WordPress's "crop" flag, and 0.1.0 only trusted
// cropped sizes. Now any size with a set width and height gives the frame its
// shape, then WooCommerce's image settings, then square.
//
// v0.1.2 (2026-11-10): fix found live on Indian River. WP Rocket's "Remove
// Unused CSS" emptied this plugin's style tag, so photos were swapped but not
// framed. The style tag is now on WP Rocket's keep list.
//
// v0.2.0 (2026-11-10): light copies (includes/class-rapif-copies.php).
// - For each product photo the plugin makes two copies from the clean
//   original: one that fits the product-page box and one that fits the
//   catalog box. Each copy is only shrunk, never padded or cropped, and is
//   saved as compressed WebP (or the original format if the server can't
//   make WebP). Copies live in uploads/rapif/; originals are never changed.
// - The page uses a copy as soon as it exists and the original until then,
//   so nothing ever looks broken while copies are being made.
// - Copies are made on the server in the background with WooCommerce's
//   Action Scheduler: no browser tab needs to stay open. Photos someone has
//   just looked at go first, any time of day. The full sweep of every product
//   (newest first) runs only at night, 8 PM to 7 AM site time, to keep
//   daytime load low. Each batch stops after 20 seconds.
// - Variation photos (when a shopper picks a color) now get the same clean
//   treatment; 0.1.x missed them.
// - The Plugins screen shows how many copies have been made.
//
// v0.3.0 (2026-11-10): updates itself from GitHub, the same system as
// Universal Room Planner and RA Promo Manager (includes/class-rapif-updater.php):
// bundled plugin-update-checker, RA Monitor Client check-in and "Update now",
// auto-update on by default, production/staging repos. New Settings >
// Product Image Frames screen for the GitHub key and channel, plus progress.
//
// v0.3.1 (2026-11-10): fix found live on Slone. The update key is also
// borrowed from another R&A plugin's wp-config.php constant (Promo Manager,
// Room Planner, Woo Native Dimensions), not only from their saved settings.
// Also the first version delivered by the new GitHub updates.
//
// v0.3.2 (2026-11-11): the plugin's screen moved from Settings to
// WooCommerce > Product Image Frames, next to the other store photo settings.
// The old address forwards to the new one.
//
// v0.3.3 (2026-11-12): safeguards for removing Smart Image Resize
// (includes/class-rapif-safeguards.php). WooCommerce no longer remakes product
// photos (no background job after its photo settings are saved, no resizing
// while pages load). Code that asks only for a product photo's web address
// (Shop the Look, category thumbnails, search/email/feed data) gets the clean
// copy too. WooCommerce > Product Image Frames shows a "Before removing Smart
// Image Resize" checklist.
//
// v0.3.4 (2026-11-12): bundles RA Monitor Client 1.3.2, so R&A's Plugin
// Monitor sees this plugin (1.3.1 left it out of every check-in and the
// monitor called it "probably deleted"). This plugin loads before the other
// R&A plugins, so its copy is the one each site uses.
//
// For a side-by-side check, a logged-in admin can add ?rapif=off to any page
// to see it without this plugin.

defined( 'ABSPATH' ) || exit;

define( 'RAPIF_VERSION', '0.3.4' );
define( 'RAPIF_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-rapif-copies.php';
require_once __DIR__ . '/includes/class-rapif-updater.php';
require_once __DIR__ . '/includes/class-rapif-settings.php';
require_once __DIR__ . '/includes/class-rapif-safeguards.php';

// Set up before WordPress's own update check fires (not on a hook).
RAPIF_Updater::init( __FILE__ );

// Before WooCommerce sets up its image jobs on init (0.3.3).
RAPIF_Safeguards::init();

final class RAPIF_Plugin {

	/**
	 * Image size names WooCommerce and themes ask for, and which frame each one
	 * gets. The shop_* names are WooCommerce's older aliases.
	 */
	const SIZES = array(
		'woocommerce_thumbnail'         => 'catalog',
		'shop_catalog'                  => 'catalog',
		'woocommerce_single'            => 'single',
		'shop_single'                   => 'single',
		'woocommerce_gallery_thumbnail' => 'gallery',
		'shop_thumbnail'                => 'gallery',
	);

	/**
	 * Box to use when a size has no fixed shape of its own (for example
	 * WooCommerce's "Uncropped" setting). Square keeps the grid even.
	 */
	const FALLBACK_BOX = array(
		'catalog' => array( 600, 600 ),
		'single'  => array( 1200, 1200 ),
		'gallery' => array( 150, 150 ),
	);

	/** @var bool|null Cached result of is_active(). */
	private static $active = null;

	/** @var array Cached result of box(). */
	private static $boxes = array();

	public static function init() {
		RAPIF_Copies::init();

		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
			RAPIF_Settings::init();
		}

		// Storefront only. Admin screens (including the Media Library) stay as
		// they are. AJAX is allowed because the theme loads more products,
		// filters, quick views and variation photos through it.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Run before themes and lazy-load plugins (default priority 10), so they
		// copy the clean address we set.
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 5, 3 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_variation' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );

		// WP Rocket's "Remove Unused CSS" empties inline style tags and serves
		// its own saved copy of the page's CSS instead, which doesn't have the
		// frames until it's rebuilt. Keep our few lines in place. (Filter from
		// WP Rocket 3.11+; does nothing on sites without WP Rocket.)
		add_filter( 'rocket_rucss_inline_atts_exclusions', array( __CLASS__, 'keep_styles_in_rocket' ) );
	}

	/**
	 * Tells WP Rocket's Remove Unused CSS to leave this plugin's style tag alone.
	 *
	 * @param array $exclusions Patterns matched against a style tag's attributes.
	 * @return array
	 */
	public static function keep_styles_in_rocket( $exclusions ) {
		$exclusions   = is_array( $exclusions ) ? $exclusions : array();
		$exclusions[] = 'ra-product-image-frames-inline-css';

		return $exclusions;
	}

	/**
	 * Whether the plugin should act on this request.
	 */
	public static function is_active() {
		if ( null !== self::$active ) {
			return self::$active;
		}

		$active = true;

		// ?rapif=off lets a logged-in admin compare the page without the plugin.
		// Logged-in admins skip the page cache, so visitors never see it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$switch = isset( $_GET['rapif'] ) ? sanitize_key( wp_unslash( $_GET['rapif'] ) ) : '';
		if ( 'off' === $switch && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$active = false;
		}

		/**
		 * Filters whether R&A Product Image Frames acts on this request.
		 *
		 * @param bool $active True to frame product photos.
		 */
		self::$active = (bool) apply_filters( 'rapif_enabled', $active );

		return self::$active;
	}

	/**
	 * Swaps a padded product photo copy for a clean one (the light copy if it's
	 * ready, otherwise the original) and marks the image so the stylesheet can
	 * frame it.
	 *
	 * @param array        $attr       Image tag attributes.
	 * @param WP_Post      $attachment The image.
	 * @param string|int[] $size       The size the page asked for.
	 * @return array
	 */
	public static function filter_image_attributes( $attr, $attachment, $size ) {
		if ( ! is_array( $attr ) || ! is_string( $size ) || ! isset( self::SIZES[ $size ] ) ) {
			return $attr;
		}
		if ( ! ( $attachment instanceof WP_Post ) || ! wp_attachment_is_image( $attachment->ID ) ) {
			return $attr;
		}
		if ( ! self::is_active() ) {
			return $attr;
		}

		$kind  = self::SIZES[ $size ];
		$clean = self::clean_url( $attachment->ID, $kind );
		if ( '' === $clean ) {
			return $attr;
		}

		$attr['src'] = $clean;

		// The other sizes in srcset are the padded copies, so the browser must
		// not pick one of them instead.
		unset( $attr['srcset'], $attr['sizes'] );

		$classes       = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		$attr['class'] = trim( $classes . ' rapif-frame rapif-' . $kind );

		return $attr;
	}

	/**
	 * Clean address for one product photo: the light copy if it's ready,
	 * otherwise the original upload.
	 *
	 * @param int    $attachment_id Image ID.
	 * @param string $kind          catalog, single or gallery.
	 * @return string '' if the image has no file.
	 */
	public static function clean_url( $attachment_id, $kind ) {
		$copy = RAPIF_Copies::url( $attachment_id, 'single' === $kind ? 'single' : 'catalog' );
		if ( '' !== $copy ) {
			return $copy;
		}

		$original = wp_get_attachment_image_url( $attachment_id, 'full' );

		return $original ? $original : '';
	}

	/**
	 * Same treatment for the photo WooCommerce swaps in when a shopper picks a
	 * variation (a color or size). WooCommerce builds that data without the
	 * image tag filter, so 0.1.x showed the padded copy there.
	 *
	 * @param array $data      Variation data sent to the page.
	 * @param mixed $product   Parent product.
	 * @param mixed $variation The variation.
	 * @return array
	 */
	public static function filter_variation( $data, $product, $variation ) {
		if ( ! is_array( $data ) || empty( $data['image_id'] ) || empty( $data['image'] ) || ! is_array( $data['image'] ) ) {
			return $data;
		}
		if ( ! self::is_active() || ! wp_attachment_is_image( (int) $data['image_id'] ) ) {
			return $data;
		}

		$id     = (int) $data['image_id'];
		$single = self::clean_url( $id, 'single' );
		$small  = self::clean_url( $id, 'catalog' );
		if ( '' === $single || '' === $small ) {
			return $data;
		}

		$data['image']['src']                   = $single;
		$data['image']['srcset']                = '';
		$data['image']['sizes']                 = '';
		$data['image']['thumb_src']             = $small;
		$data['image']['gallery_thumbnail_src'] = $small;

		return $data;
	}

	/**
	 * Width and height of the box for one kind of image, from the image size
	 * the store has set up.
	 *
	 * @param string $kind catalog, single or gallery.
	 * @return int[] array( width, height )
	 */
	public static function box( $kind ) {
		if ( isset( self::$boxes[ $kind ] ) ) {
			return self::$boxes[ $kind ];
		}

		$names = array_keys( self::SIZES, $kind, true );
		$w     = 0;
		$h     = 0;

		// The shape counts even when the size isn't marked "crop": Smart Image
		// Resize registers 600x450 without it and still makes every copy exactly
		// that shape (seen live on StarFine, 0.1.0).
		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			$subsizes = wp_get_registered_image_subsizes();
			foreach ( $names as $name ) {
				if ( ! empty( $subsizes[ $name ] ) ) {
					$w = (int) $subsizes[ $name ]['width'];
					$h = (int) $subsizes[ $name ]['height'];
					break;
				}
			}
		}

		// Next, WooCommerce's own image settings.
		if ( ( $w <= 0 || $h <= 0 ) && function_exists( 'wc_get_image_size' ) ) {
			$wc_names = array(
				'catalog' => 'thumbnail',
				'single'  => 'single',
				'gallery' => 'gallery_thumbnail',
			);
			$wc_size  = wc_get_image_size( $wc_names[ $kind ] );
			$w        = isset( $wc_size['width'] ) ? (int) $wc_size['width'] : 0;
			$h        = isset( $wc_size['height'] ) ? (int) $wc_size['height'] : 0;
		}

		// A width with no height means "any height": use a square box.
		if ( $w > 0 && $h <= 0 ) {
			$h = $w;
		}
		if ( $w <= 0 || $h <= 0 ) {
			list( $w, $h ) = self::FALLBACK_BOX[ $kind ];
		}

		/**
		 * Filters the box size for one kind of product photo.
		 *
		 * @param int[]  $box  array( width, height ) in pixels.
		 * @param string $kind catalog, single or gallery.
		 */
		$box = apply_filters( 'rapif_box', array( $w, $h ), $kind );
		$w   = isset( $box[0] ) ? max( 1, (int) $box[0] ) : $w;
		$h   = isset( $box[1] ) ? max( 1, (int) $box[1] ) : $h;

		self::$boxes[ $kind ] = array( $w, $h );

		return self::$boxes[ $kind ];
	}

	/**
	 * Frame shape for one kind of image, as a CSS aspect-ratio value such as
	 * "600 / 450".
	 *
	 * @param string $kind catalog, single or gallery.
	 * @return string
	 */
	private static function frame_ratio( $kind ) {
		list( $w, $h ) = self::box( $kind );
		$ratio         = $w . ' / ' . $h;

		/**
		 * Filters the frame shape for one kind of product photo.
		 *
		 * @param string $ratio CSS aspect-ratio value ("4 / 3"), or '' for none.
		 * @param string $kind  catalog, single or gallery.
		 */
		$ratio = (string) apply_filters( 'rapif_aspect_ratio', $ratio, $kind );

		// Only numbers, spaces, dots and one slash are allowed into the CSS.
		return preg_match( '#^\s*\d+(\.\d+)?\s*/\s*\d+(\.\d+)?\s*$#', $ratio ) ? trim( $ratio ) : '';
	}

	/**
	 * Frame background color.
	 *
	 * @return string Hex color.
	 */
	private static function background() {
		/**
		 * Filters the frame background color.
		 *
		 * @param string $color Hex color. Default white.
		 */
		$color = sanitize_hex_color( (string) apply_filters( 'rapif_background', '#ffffff' ) );

		return $color ? $color : '#ffffff';
	}

	public static function enqueue_styles() {
		if ( ! self::is_active() ) {
			return;
		}

		$css = 'img.rapif-frame{background-color:' . self::background() . ';object-fit:contain;object-position:center;}';

		foreach ( array( 'catalog', 'single', 'gallery' ) as $kind ) {
			$ratio = self::frame_ratio( $kind );
			if ( '' !== $ratio ) {
				$css .= 'img.rapif-' . $kind . '{aspect-ratio:' . $ratio . ';height:auto;}';
			}
		}

		wp_register_style( 'ra-product-image-frames', false, array(), RAPIF_VERSION );
		wp_enqueue_style( 'ra-product-image-frames' );
		wp_add_inline_style( 'ra-product-image-frames', $css );
	}

	/**
	 * Progress line under the plugin's name on the Plugins screen.
	 *
	 * @param string[] $meta Links and text under the plugin description.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public static function plugin_row_meta( $meta, $file ) {
		if ( plugin_basename( RAPIF_FILE ) !== $file ) {
			return $meta;
		}

		$meta[] = esc_html( RAPIF_Copies::status_line() );

		return $meta;
	}
}

add_action( 'plugins_loaded', array( 'RAPIF_Plugin', 'init' ) );
register_deactivation_hook( __FILE__, array( 'RAPIF_Copies', 'deactivate' ) );
