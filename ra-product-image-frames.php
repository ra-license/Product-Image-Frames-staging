<?php
/**
 * Plugin Name: R&A Product Image Frames
 * Description: Keeps product photos the same size and lined up on category and product pages without painting bars into the photos. Each photo sits centered in a white box. Takes over the "make every photo the same shape" job from Smart Image Resize.
 * Version: 0.1.2
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
// Known trade-off: originals are larger files than the old copies, so
// category pages are heavier until 0.2.0 makes light, compressed copies.
// Turning the plugin off puts every page back exactly as it was.
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
// For a side-by-side check, a logged-in admin can add ?rapif=off to any page
// to see it without this plugin.

defined( 'ABSPATH' ) || exit;

define( 'RAPIF_VERSION', '0.1.2' );

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
	 * Frame shape to use when a size has no fixed shape of its own (for
	 * example WooCommerce's "Uncropped" setting). Square keeps the grid even.
	 */
	const FALLBACK_RATIO = array(
		'catalog' => '1 / 1',
		'single'  => '1 / 1',
		'gallery' => '1 / 1',
	);

	/** @var bool|null Cached result of is_active(). */
	private static $active = null;

	public static function init() {
		// Storefront only. Admin screens (including the Media Library) stay as
		// they are. AJAX is allowed because the theme loads more products,
		// filters and quick views through it.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Run before themes and lazy-load plugins (default priority 10), so they
		// copy the clean address we set.
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 5, 3 );
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
	private static function is_active() {
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
	 * Swaps a padded product photo copy for the clean original and marks the
	 * image so the stylesheet can frame it.
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

		$original = wp_get_attachment_image_url( $attachment->ID, 'full' );
		if ( ! $original ) {
			return $attr;
		}

		$kind = self::SIZES[ $size ];

		$attr['src'] = $original;

		// The other sizes in srcset are the padded copies, so the browser must
		// not pick one of them instead.
		unset( $attr['srcset'], $attr['sizes'] );

		$classes       = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		$attr['class'] = trim( $classes . ' rapif-frame rapif-' . $kind );

		return $attr;
	}

	/**
	 * Frame shape for one kind of image, as a CSS aspect-ratio value such as
	 * "600 / 450", or '' to keep the photo's own shape.
	 *
	 * @param string $kind catalog, single or gallery.
	 * @return string
	 */
	private static function frame_ratio( $kind ) {
		$names = array_keys( self::SIZES, $kind, true );
		$ratio = '';

		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			$subsizes = wp_get_registered_image_subsizes();
			foreach ( $names as $name ) {
				if ( empty( $subsizes[ $name ] ) ) {
					continue;
				}
				// The shape counts even when the size isn't marked "crop": Smart
				// Image Resize registers 600x450 without it and still makes every
				// copy exactly that shape (seen live on StarFine, 0.1.0).
				$w = (int) $subsizes[ $name ]['width'];
				$h = (int) $subsizes[ $name ]['height'];
				if ( $w > 0 && $h > 0 ) {
					$ratio = $w . ' / ' . $h;
				}
				break;
			}
		}

		// Next, WooCommerce's own image settings.
		if ( '' === $ratio && function_exists( 'wc_get_image_size' ) ) {
			$wc_names = array(
				'catalog' => 'thumbnail',
				'single'  => 'single',
				'gallery' => 'gallery_thumbnail',
			);
			$wc_size  = wc_get_image_size( $wc_names[ $kind ] );
			$w        = isset( $wc_size['width'] ) ? (int) $wc_size['width'] : 0;
			$h        = isset( $wc_size['height'] ) ? (int) $wc_size['height'] : 0;
			if ( $w > 0 && $h > 0 ) {
				$ratio = $w . ' / ' . $h;
			}
		}

		if ( '' === $ratio ) {
			$ratio = self::FALLBACK_RATIO[ $kind ];
		}

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

		$css  = 'img.rapif-frame{background-color:' . self::background() . ';object-fit:contain;object-position:center;}';

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
}

add_action( 'plugins_loaded', array( 'RAPIF_Plugin', 'init' ) );
