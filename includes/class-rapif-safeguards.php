<?php
/**
 * Safeguards for taking Smart Image Resize off a site (added in 0.3.3).
 *
 * 1. WooCommerce stops remaking product photos. Verified in WooCommerce's
 *    own code (includes/class-wc-regenerate-images.php):
 *    - "woocommerce_background_image_regeneration": after the product photo
 *      settings are saved in the Customizer, WooCommerce queues a background
 *      job that remakes the thumbnails of every image on the site.
 *    - "woocommerce_resize_images": while pages load, WooCommerce remakes a
 *      product photo on the spot whenever its saved copy doesn't match the
 *      current settings. Once Smart Image Resize is gone, that's every
 *      non-square photo's padded 1200x1200 product-page copy.
 *    This plugin shows its own copies instead, so neither job is needed.
 *    Turn the safeguard off with add_filter( 'rapif_stop_wc_regeneration', '__return_false' );
 *
 * 2. Clean photos for code that asks for a product photo's web address only
 *    (no image tag), for example Promo Manager's Shop the Look, WooCommerce's
 *    category thumbnails, Fast Simon search and Omnisend product data built
 *    on the storefront, in the REST API or in scheduled tasks. It gets the
 *    light copy (or the original until the copy is ready), never the padded
 *    file. Ordinary admin screens are left alone, and so are Smart Image
 *    Resize's and ReThumbify's own background requests.
 *
 * 3. The "Before removing Smart Image Resize" checklist on WooCommerce >
 *    Product Image Frames reads from here.
 *
 * @package RA_Product_Image_Frames
 */

defined( 'ABSPATH' ) || exit;

final class RAPIF_Safeguards {

	/** Background (admin-ajax) requests that must see the real files. */
	const SKIP_AJAX_PREFIXES = array( 'wp_sir', 'rethumbify', 'regeneratethumbnail', 'woocommerce_regenerate' );

	/**
	 * Runs at plugin load, before WooCommerce sets up its image jobs on init.
	 */
	public static function init() {
		// Decided when WooCommerce asks (on init, after code snippets load).
		add_filter( 'woocommerce_background_image_regeneration', array( __CLASS__, 'maybe_false' ), 99 );
		add_filter( 'woocommerce_resize_images', array( __CLASS__, 'maybe_false' ), 99 );

		// After WooCommerce's own filter (priority 10).
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 20, 4 );
	}

	/**
	 * @param bool $value WooCommerce's own answer.
	 * @return bool False while the safeguard is on.
	 */
	public static function maybe_false( $value ) {
		return self::stops_wc_regeneration() ? false : $value;
	}

	/**
	 * @return bool Whether WooCommerce's photo remaking is switched off.
	 */
	public static function stops_wc_regeneration() {
		return (bool) apply_filters( 'rapif_stop_wc_regeneration', true );
	}

	/**
	 * Whether this request should get clean photo addresses.
	 *
	 * @return bool
	 */
	private static function context_allowed() {
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}

		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to skip other plugins' image jobs.
			$action = isset( $_REQUEST['action'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) : '';
			foreach ( self::SKIP_AJAX_PREFIXES as $prefix ) {
				if ( 0 === strpos( $action, $prefix ) ) {
					return false;
				}
			}
			return true;
		}

		return ! is_admin();
	}

	/**
	 * Clean address for WooCommerce's product photo sizes.
	 *
	 * @param array|false  $image         array( url, width, height, is_intermediate ) or false.
	 * @param int          $attachment_id Image ID.
	 * @param string|int[] $size          Requested size.
	 * @param bool         $icon          Whether an icon was asked for.
	 * @return array|false
	 */
	public static function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! $image || $icon || ! is_string( $size ) || ! isset( RAPIF_Plugin::SIZES[ $size ] ) ) {
			return $image;
		}
		if ( ! self::context_allowed() || ! RAPIF_Plugin::is_active() || ! wp_attachment_is_image( $attachment_id ) ) {
			return $image;
		}

		$kind  = RAPIF_Plugin::SIZES[ $size ];
		$clean = RAPIF_Plugin::clean_url( (int) $attachment_id, $kind );
		if ( '' === $clean ) {
			return $image;
		}

		// Keep the box size the store always used, so page layout doesn't move.
		list( $w, $h ) = RAPIF_Plugin::box( $kind );

		return array( $clean, $w, $h, true );
	}

	/* ---------------------------------------------------------------------
	 * Checklist
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a plugin whose folder or file name contains $needle is active.
	 *
	 * @param string $needle Part of the plugin's path.
	 * @return bool
	 */
	private static function plugin_active( $needle ) {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( false !== stripos( (string) $plugin, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Box sizes WooCommerce's own settings will give once Smart Image Resize
	 * is gone (read from the settings themselves, not from the sizes
	 * Smart Image Resize registers while it's on).
	 *
	 * @return array array( 'catalog' => int[2], 'single' => int[2] )
	 */
	private static function woocommerce_boxes() {
		$tw       = max( 1, (int) get_option( 'woocommerce_thumbnail_image_width', 300 ) );
		$cropping = (string) get_option( 'woocommerce_thumbnail_cropping', '1:1' );

		if ( 'custom' === $cropping ) {
			$cw = max( 1, (int) get_option( 'woocommerce_thumbnail_cropping_custom_width', 4 ) );
			$ch = max( 1, (int) get_option( 'woocommerce_thumbnail_cropping_custom_height', 3 ) );
			$th = (int) round( $tw * $ch / $cw );
		} else {
			// "1:1", and "Uncropped" (which this plugin frames as a square).
			$th = $tw;
		}

		$sw = max( 1, (int) get_option( 'woocommerce_single_image_width', 600 ) );

		return array(
			'catalog' => array( $tw, $th ),
			'single'  => array( $sw, $sw ),
		);
	}

	/**
	 * Checklist rows: array( 'ok' => bool|null, 'label' => string, 'detail' => string ).
	 * 'ok' null means "check this yourself".
	 *
	 * @return array[]
	 */
	public static function checklist() {
		$rows   = array();
		$sir_on = self::plugin_active( 'smart-image-resize' );
		$rt_on  = self::plugin_active( 'rethumbify' );

		if ( $sir_on ) {
			$woo   = self::woocommerce_boxes();
			$now_c = RAPIF_Plugin::box( 'catalog' );
			$now_s = RAPIF_Plugin::box( 'single' );

			$match_c = $woo['catalog'][0] === $now_c[0] && $woo['catalog'][1] === $now_c[1];
			$match_s = $woo['single'][0] === $now_s[0];

			$rows[] = array(
				'ok'     => $match_c && $match_s,
				'label'  => __( 'WooCommerce\'s photo settings match the frames', 'ra-product-image-frames' ),
				'detail' => $match_c && $match_s
					/* translators: 1: catalog width x height, 2: product page width. */
					? sprintf( __( 'Category photos %1$s, product page %2$s px wide. Removing Smart Image Resize won\'t change the frames or remake the light copies.', 'ra-product-image-frames' ), $now_c[0] . '×' . $now_c[1], $now_s[0] )
					/* translators: 1: frames now, 2: WooCommerce settings. */
					: sprintf( __( 'The frames are %1$s now, but WooCommerce\'s settings would make them %2$s. Before removing Smart Image Resize, set Appearance > Customize > WooCommerce > Product Images to match (Main image width %3$s, Thumbnail width %4$s, Thumbnail cropping Custom %5$s).', 'ra-product-image-frames' ), $now_c[0] . '×' . $now_c[1] . ' / ' . $now_s[0], $woo['catalog'][0] . '×' . $woo['catalog'][1] . ' / ' . $woo['single'][0], $now_s[0], $now_c[0], self::ratio_text( $now_c[0], $now_c[1] ) ),
			);
		}

		$rows[] = array(
			'ok'     => self::stops_wc_regeneration(),
			'label'  => __( 'WooCommerce won\'t remake every product photo', 'ra-product-image-frames' ),
			'detail' => self::stops_wc_regeneration()
				? __( 'Switched off by this plugin: no background job after photo settings are saved, and no resizing while pages load.', 'ra-product-image-frames' )
				: __( 'Turned back on by a code snippet on this site (rapif_stop_wc_regeneration).', 'ra-product-image-frames' ),
		);

		$rows[] = array(
			'ok'     => true,
			'label'  => __( 'Search, emails and feeds get clean photos', 'ra-product-image-frames' ),
			'detail' => __( 'Code that asks for a product photo\'s address (search, Shop the Look, email and feed data) gets the light copy or the original, not the old padded file.', 'ra-product-image-frames' ),
		);

		$rows[] = array(
			'ok'     => $rt_on ? null : true,
			'label'  => __( 'ReThumbify Pro', 'ra-product-image-frames' ),
			'detail' => $rt_on
				? __( 'Still on. It\'s only a manual "regenerate thumbnails" tool, so it can be deactivated and deleted now.', 'ra-product-image-frames' )
				: __( 'Not on this site.', 'ra-product-image-frames' ),
		);

		if ( $sir_on ) {
			$rows[] = array(
				'ok'     => null,
				'label'  => __( 'Category and brand images', 'ra-product-image-frames' ),
				'detail' => __( 'If Smart Image Resize is set to process "Product category images" or "Product brand images" (WooCommerce > Smart Image Resize > Settings), check those pages after removing it.', 'ra-product-image-frames' ),
			);
		} else {
			$rows[] = array(
				'ok'     => true,
				'label'  => __( 'Smart Image Resize', 'ra-product-image-frames' ),
				'detail' => __( 'Not on this site.', 'ra-product-image-frames' ),
			);
		}

		return $rows;
	}

	/**
	 * "4:3" from 600 and 450.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return string
	 */
	private static function ratio_text( $w, $h ) {
		$a = (int) $w;
		$b = (int) $h;
		while ( $b > 0 ) {
			$t = $b;
			$b = $a % $b;
			$a = $t;
		}
		$g = max( 1, $a );

		return ( (int) $w / $g ) . ':' . ( (int) $h / $g );
	}

	/**
	 * @return bool Whether Smart Image Resize is still on.
	 */
	public static function smart_image_resize_active() {
		return self::plugin_active( 'smart-image-resize' );
	}
}
