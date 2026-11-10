<?php
/**
 * Light copies of product photos (added in 0.2.0).
 *
 * For each product photo this makes two copies from the clean original, one
 * that fits the product-page box and one that fits the catalog box. Copies
 * are only shrunk (never padded, never cropped) and saved as compressed WebP
 * when the server can make WebP. They live in uploads/rapif/ and are tracked
 * in the image's own "_rapif_copies" field. Originals are never changed.
 *
 * Work happens in the background on the server with WooCommerce's Action
 * Scheduler (WP-Cron if it's missing):
 * - Photos a page needed but had no copy for go on a short "wanted" list and
 *   are made first, any time of day.
 * - The sweep through every published product, newest first, runs only at
 *   night (8 PM to 7 AM site time by default).
 * - Each batch stops after about 20 seconds, then the next batch is scheduled
 *   a minute later.
 *
 * @package RA_Product_Image_Frames
 */

defined( 'ABSPATH' ) || exit;

final class RAPIF_Copies {

	/** Image field that records the copies. */
	const META = '_rapif_copies';

	/** Short list of photos a page needed first (not autoloaded). */
	const OPT_WANTED = 'rapif_wanted';

	/** Progress and counts. */
	const OPT_STATE = 'rapif_state';

	/** Background task name and group. */
	const HOOK  = 'rapif_make_copies';
	const GROUP = 'rapif';

	/** Bump to remake every copy (e.g. after changing how copies are made). */
	const FORMAT = 1;

	/** WebP / JPEG quality for copies. */
	const QUALITY = 82;

	/** Folder under uploads. */
	const DIR = 'rapif';

	/** Most photos the wanted list holds. */
	const WANTED_CAP = 5000;

	/** Seconds to wait before trying a photo that failed again. */
	const RETRY_AFTER = DAY_IN_SECONDS;

	/** @var array<int,bool> Photos this request needed and had no copy for. */
	private static $wanted = array();

	/** @var string|null Output type for copies. */
	private static $mime = null;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_copies' ) );
		add_action( 'init', array( __CLASS__, 'maybe_start' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'save_wanted' ) );
	}

	/* ---------------------------------------------------------------------
	 * Reading copies (storefront)
	 * ------------------------------------------------------------------- */

	/**
	 * Address of one light copy, or '' if it isn't ready yet. A missing copy
	 * puts the photo on the wanted list.
	 *
	 * @param int    $attachment_id Image ID.
	 * @param string $kind          catalog or single.
	 * @return string
	 */
	public static function url( $attachment_id, $kind ) {
		$attachment_id = (int) $attachment_id;
		$copies        = self::get( $attachment_id );

		if ( self::is_current( $attachment_id, $copies ) && ! empty( $copies[ $kind ]['file'] ) ) {
			$uploads = wp_get_upload_dir();
			return trailingslashit( $uploads['baseurl'] ) . $copies[ $kind ]['file'];
		}

		if ( ! self::failed_recently( $attachment_id, $copies ) ) {
			self::$wanted[ $attachment_id ] = true;
		}

		return '';
	}

	/**
	 * The copies record for one image.
	 *
	 * @param int $attachment_id Image ID.
	 * @return array
	 */
	private static function get( $attachment_id ) {
		$copies = get_post_meta( $attachment_id, self::META, true );

		return is_array( $copies ) ? $copies : array();
	}

	/**
	 * Whether an image's copies were made from its current file, at the
	 * current box sizes, by the current way of making copies.
	 *
	 * @param int   $attachment_id Image ID.
	 * @param array $copies        Copies record.
	 * @return bool
	 */
	private static function is_current( $attachment_id, $copies ) {
		if ( empty( $copies['format'] ) || self::FORMAT !== (int) $copies['format'] || ! empty( $copies['error'] ) ) {
			return false;
		}
		if ( ! isset( $copies['src'] ) || (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $copies['src'] ) {
			return false;
		}
		if ( ! isset( $copies['boxes'] ) || self::box_key() !== $copies['boxes'] ) {
			return false;
		}

		return ! empty( $copies['catalog']['file'] ) && ! empty( $copies['single']['file'] );
	}

	/**
	 * Whether making copies for this image failed less than a day ago.
	 *
	 * @param int   $attachment_id Image ID.
	 * @param array $copies        Copies record.
	 * @return bool
	 */
	private static function failed_recently( $attachment_id, $copies ) {
		if ( empty( $copies['error'] ) || empty( $copies['tried'] ) ) {
			return false;
		}
		if ( ! isset( $copies['src'] ) || (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $copies['src'] ) {
			return false; // A new file: worth another try.
		}

		return ( time() - (int) $copies['tried'] ) < self::RETRY_AFTER;
	}

	/**
	 * Box sizes as one short string, e.g. "1200x1200|600x450".
	 *
	 * @return string
	 */
	private static function box_key() {
		$single  = RAPIF_Plugin::box( 'single' );
		$catalog = RAPIF_Plugin::box( 'catalog' );

		return $single[0] . 'x' . $single[1] . '|' . $catalog[0] . 'x' . $catalog[1];
	}

	/**
	 * At the end of a storefront request, adds the photos it needed to the
	 * wanted list and makes sure the background task will run soon.
	 */
	public static function save_wanted() {
		if ( empty( self::$wanted ) ) {
			return;
		}

		$list = get_option( self::OPT_WANTED, array() );
		$list = is_array( $list ) ? array_map( 'intval', $list ) : array();
		$new  = array_diff( array_keys( self::$wanted ), $list );

		self::$wanted = array();

		if ( empty( $new ) ) {
			return;
		}

		// Newest needs first, so the photos a shopper is looking at right now
		// are made before older entries.
		$list = array_slice( array_merge( $new, $list ), 0, self::WANTED_CAP );
		update_option( self::OPT_WANTED, $list, false );

		self::schedule_soon();
	}

	/* ---------------------------------------------------------------------
	 * Background task
	 * ------------------------------------------------------------------- */

	/**
	 * On the first request after the plugin is turned on or updated, starts
	 * the background task.
	 */
	public static function maybe_start() {
		$state = self::state();

		if ( ! isset( $state['version'] ) || RAPIF_VERSION !== $state['version'] ) {
			$state['version'] = RAPIF_VERSION;
			self::save_state( $state );
			self::schedule_soon();
			return;
		}

		// Every 10 minutes at most: if work is left but nothing is scheduled
		// (for example a batch was cut off by the server), start again.
		if ( get_transient( 'rapif_check' ) ) {
			return;
		}
		set_transient( 'rapif_check', 1, 10 * MINUTE_IN_SECONDS );

		$wanted = get_option( self::OPT_WANTED, array() );
		if ( ( ! empty( $wanted ) || empty( $state['sweep_done'] ) ) && false === self::next_scheduled() ) {
			self::schedule_next( ! empty( $wanted ), $state );
		}
	}

	/**
	 * When the plugin is turned off: stop the background task, and make sure
	 * it starts again if the plugin is turned back on.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		delete_transient( 'rapif_lock' );

		$state = self::state();
		unset( $state['version'] );
		self::save_state( $state );
	}

	/**
	 * One background batch: wanted photos first, then (at night) the sweep.
	 */
	public static function run() {
		if ( get_transient( 'rapif_lock' ) ) {
			return;
		}
		set_transient( 'rapif_lock', 1, 2 * MINUTE_IN_SECONDS );

		$start  = microtime( true );
		$budget = (float) apply_filters( 'rapif_time_budget', 20 );
		$state  = self::state();

		// 1. Photos pages needed.
		$wanted = get_option( self::OPT_WANTED, array() );
		$wanted = is_array( $wanted ) ? array_values( array_map( 'intval', $wanted ) ) : array();
		$done   = array();

		foreach ( $wanted as $id ) {
			if ( ! self::time_left( $start, $budget ) ) {
				break;
			}
			$done[] = $id;
			$copies = self::get( $id );
			if ( ! self::is_current( $id, $copies ) && ! self::failed_recently( $id, $copies ) ) {
				self::make( $id, $state );
			}
		}

		// Re-read the list: pages may have added to it while this batch ran.
		$latest = get_option( self::OPT_WANTED, array() );
		$latest = is_array( $latest ) ? array_map( 'intval', $latest ) : array();
		$left   = array_values( array_diff( $latest, $done ) );
		update_option( self::OPT_WANTED, $left, false );

		// 2. Sweep every published product, newest first, at night only.
		if ( empty( $state['sweep_done'] ) && self::is_night() && self::time_left( $start, $budget ) ) {
			self::sweep( $state, $start, $budget );
		}

		$state['last_run'] = time();
		self::save_state( $state );
		delete_transient( 'rapif_lock' );

		self::schedule_next( ! empty( $left ), $state );
	}

	/**
	 * Works through published products from newest to oldest, making copies
	 * for each one's main photo and gallery photos.
	 *
	 * @param array $state  Progress, updated in place.
	 * @param float $start  When this batch started.
	 * @param float $budget Seconds this batch may use.
	 */
	private static function sweep( &$state, $start, $budget ) {
		global $wpdb;

		$cursor = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
		if ( $cursor <= 0 ) {
			$cursor = PHP_INT_MAX;
		}

		while ( self::time_left( $start, $budget ) ) {
			$products = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID < %d ORDER BY ID DESC LIMIT 20",
					$cursor
				)
			);

			if ( empty( $products ) ) {
				$state['sweep_done'] = time();
				return;
			}

			foreach ( $products as $product_id ) {
				foreach ( self::product_images( (int) $product_id ) as $id ) {
					if ( ! self::time_left( $start, $budget ) ) {
						return; // This product isn't finished; start here next time.
					}
					$copies = self::get( $id );
					if ( ! self::is_current( $id, $copies ) && ! self::failed_recently( $id, $copies ) ) {
						self::make( $id, $state );
					}
				}
				$cursor          = (int) $product_id;
				$state['cursor'] = $cursor;
			}
		}
	}

	/**
	 * A product's main photo and gallery photos.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private static function product_images( $product_id ) {
		$ids     = array( (int) get_post_thumbnail_id( $product_id ) );
		$gallery = (string) get_post_meta( $product_id, '_product_image_gallery', true );

		if ( '' !== $gallery ) {
			$ids = array_merge( $ids, array_map( 'intval', explode( ',', $gallery ) ) );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether there's still time in this batch for another photo. One photo
	 * can take a few seconds, so stop with some room to spare.
	 *
	 * @param float $start  When the batch started.
	 * @param float $budget Seconds the batch may use.
	 * @return bool
	 */
	private static function time_left( $start, $budget ) {
		return ( microtime( true ) - $start ) < max( 1, $budget - 4 );
	}

	/**
	 * Whether it's night at the store (8 PM to 7 AM site time by default).
	 *
	 * @return bool
	 */
	private static function is_night() {
		$hour  = (int) wp_date( 'G' );
		$start = (int) apply_filters( 'rapif_night_start', 20 );
		$end   = (int) apply_filters( 'rapif_night_end', 7 );

		return $start > $end ? ( $hour >= $start || $hour < $end ) : ( $hour >= $start && $hour < $end );
	}

	/* ---------------------------------------------------------------------
	 * Making copies
	 * ------------------------------------------------------------------- */

	/**
	 * Makes both copies of one photo from its clean file.
	 *
	 * @param int   $attachment_id Image ID.
	 * @param array $state         Progress, counts updated in place.
	 * @return bool
	 */
	public static function make( $attachment_id, &$state ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		$src  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return self::fail( $attachment_id, $src, 'The original file is missing.', $state );
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return self::fail( $attachment_id, $src, $editor->get_error_message(), $state );
		}

		$uploads = wp_get_upload_dir();
		$folder  = self::DIR . '/' . (int) floor( $attachment_id / 1000 );
		$dir     = trailingslashit( $uploads['basedir'] ) . $folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::fail( $attachment_id, $src, 'Could not create the uploads/rapif folder.', $state );
		}

		$mime = self::output_mime( $attachment_id );
		$ext  = self::extension_for( $mime, $path );
		$tag  = substr( md5( $src ), 0, 8 );
		$old  = self::get( $attachment_id );

		$copies = array(
			'format' => self::FORMAT,
			'src'    => $src,
			'boxes'  => self::box_key(),
			'made'   => time(),
		);

		// Biggest first, so the catalog copy is shrunk from the product-page
		// copy instead of decoding the original twice.
		foreach ( array( 'single', 'catalog' ) as $kind ) {
			list( $w, $h ) = RAPIF_Plugin::box( $kind );

			$size = $editor->get_size();
			if ( ! empty( $size['width'] ) && ( $size['width'] > $w || $size['height'] > $h ) ) {
				$resized = $editor->resize( $w, $h, false );
				if ( is_wp_error( $resized ) ) {
					return self::fail( $attachment_id, $src, $resized->get_error_message(), $state );
				}
			}

			$editor->set_quality( (int) apply_filters( 'rapif_quality', self::QUALITY, $kind ) );

			$name  = $attachment_id . '-' . $kind . '-' . $w . 'x' . $h . '-' . $tag . '.' . $ext;
			$saved = $editor->save( $dir . '/' . $name, $mime );
			if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
				$message = is_wp_error( $saved ) ? $saved->get_error_message() : 'The copy could not be saved.';
				return self::fail( $attachment_id, $src, $message, $state );
			}

			$copies[ $kind ] = array(
				'file'  => $folder . '/' . wp_basename( $saved['path'] ),
				'w'     => isset( $saved['width'] ) ? (int) $saved['width'] : 0,
				'h'     => isset( $saved['height'] ) ? (int) $saved['height'] : 0,
				'bytes' => (int) @filesize( $saved['path'] ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			);
		}

		update_post_meta( $attachment_id, self::META, $copies );
		self::delete_files( $old, $copies );

		$state['made']  = isset( $state['made'] ) ? (int) $state['made'] + 1 : 1;
		$state['bytes'] = ( isset( $state['bytes'] ) ? (int) $state['bytes'] : 0 ) + $copies['catalog']['bytes'] + $copies['single']['bytes'];

		return true;
	}

	/**
	 * Records that a photo's copies could not be made, so it's skipped for a
	 * day and the page keeps showing the original.
	 *
	 * @param int    $attachment_id Image ID.
	 * @param string $src           Its file.
	 * @param string $message       What went wrong.
	 * @param array  $state         Progress, counts updated in place.
	 * @return bool Always false.
	 */
	private static function fail( $attachment_id, $src, $message, &$state ) {
		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'format' => self::FORMAT,
				'src'    => $src,
				'error'  => wp_strip_all_tags( (string) $message ),
				'tried'  => time(),
			)
		);

		$state['failed']     = isset( $state['failed'] ) ? (int) $state['failed'] + 1 : 1;
		$state['last_error'] = '#' . $attachment_id . ': ' . wp_strip_all_tags( (string) $message );

		return false;
	}

	/**
	 * WebP when this server can make it, otherwise the photo's own type.
	 *
	 * @param int $attachment_id Image ID.
	 * @return string
	 */
	private static function output_mime( $attachment_id ) {
		if ( null === self::$mime ) {
			self::$mime = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : '';
		}

		if ( '' !== self::$mime ) {
			return self::$mime;
		}

		$own = (string) get_post_mime_type( $attachment_id );

		return in_array( $own, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ? $own : 'image/jpeg';
	}

	/**
	 * File extension for a copy. Some originals on these sites have no
	 * extension at all, so it comes from the type, not the original's name.
	 *
	 * @param string $mime Output type.
	 * @param string $path Original file.
	 * @return string
	 */
	private static function extension_for( $mime, $path ) {
		$map = array(
			'image/webp' => 'webp',
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
		);

		if ( isset( $map[ $mime ] ) ) {
			return $map[ $mime ];
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return '' !== $ext ? $ext : 'jpg';
	}

	/**
	 * Removes copy files that a newer record no longer uses.
	 *
	 * @param array $old Previous record.
	 * @param array $new New record (or empty to remove all).
	 */
	private static function delete_files( $old, $new = array() ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );

		foreach ( array( 'single', 'catalog' ) as $kind ) {
			if ( empty( $old[ $kind ]['file'] ) ) {
				continue;
			}
			$file = (string) $old[ $kind ]['file'];
			if ( ! empty( $new[ $kind ]['file'] ) && $new[ $kind ]['file'] === $file ) {
				continue;
			}
			// Only ever delete inside uploads/rapif/.
			if ( 0 !== strpos( $file, self::DIR . '/' ) || false !== strpos( $file, '..' ) ) {
				continue;
			}
			wp_delete_file( $base . $file );
		}
	}

	/**
	 * When an image is deleted from the Media Library, its copies go too.
	 *
	 * @param int $attachment_id Image ID.
	 */
	public static function delete_copies( $attachment_id ) {
		self::delete_files( self::get( (int) $attachment_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Makes sure a batch runs within about a minute (unless one is running).
	 */
	private static function schedule_soon() {
		$next = self::next_scheduled();

		if ( true === $next ) {
			return; // Running now; it re-reads the wanted list when it finishes.
		}
		if ( is_int( $next ) && $next <= time() + 2 * MINUTE_IN_SECONDS ) {
			return;
		}

		self::schedule_at( time() );
	}

	/**
	 * After a batch: the next one in a minute if there's work it may do now,
	 * otherwise at the start of tonight's sweep, otherwise nothing.
	 *
	 * @param bool  $wanted_left Whether wanted photos are still waiting.
	 * @param array $state       Progress.
	 */
	private static function schedule_next( $wanted_left, $state ) {
		$sweep_left = empty( $state['sweep_done'] );

		if ( $wanted_left || ( $sweep_left && self::is_night() ) ) {
			self::schedule_at( time() + MINUTE_IN_SECONDS );
		} elseif ( $sweep_left ) {
			self::schedule_at( self::tonight() );
		}
	}

	/**
	 * Timestamp of the next night start (8 PM site time by default).
	 *
	 * @return int
	 */
	private static function tonight() {
		$now   = current_datetime();
		$start = (int) apply_filters( 'rapif_night_start', 20 );
		$at    = $now->setTime( $start, 0 );

		if ( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}

		return $at->getTimestamp();
	}

	/**
	 * True if a batch is running, the time of the next waiting one, or false.
	 *
	 * @return bool|int
	 */
	private static function next_scheduled() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return as_next_scheduled_action( self::HOOK, null, self::GROUP );
		}

		return wp_next_scheduled( self::HOOK );
	}

	/**
	 * Replaces any waiting batch with exactly one at the given time.
	 *
	 * @param int $timestamp When to run.
	 */
	private static function schedule_at( $timestamp ) {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_schedule_single_action( (int) $timestamp, self::HOOK, array(), self::GROUP );
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( (int) $timestamp, self::HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Progress
	 * ------------------------------------------------------------------- */

	/**
	 * @return array
	 */
	private static function state() {
		$state = get_option( self::OPT_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array $state Progress.
	 */
	private static function save_state( $state ) {
		update_option( self::OPT_STATE, $state, true );
	}

	/**
	 * Plain-language progress for the Plugins screen.
	 *
	 * @return string
	 */
	public static function status_line() {
		$state  = self::state();
		$made   = isset( $state['made'] ) ? (int) $state['made'] : 0;
		$failed = isset( $state['failed'] ) ? (int) $state['failed'] : 0;
		$wanted = get_option( self::OPT_WANTED, array() );
		$wanted = is_array( $wanted ) ? count( $wanted ) : 0;

		$line = sprintf( 'Light copies made for %s photos', number_format_i18n( $made ) );

		if ( $made > 0 && ! empty( $state['bytes'] ) ) {
			$line .= sprintf( ' (about %s KB each)', number_format_i18n( round( $state['bytes'] / $made / 1024 ) ) );
		}
		if ( $failed > 0 ) {
			$line .= sprintf( '. %s could not be made, so those show the original', number_format_i18n( $failed ) );
		}
		if ( $wanted > 0 ) {
			$line .= sprintf( '. %s waiting', number_format_i18n( $wanted ) );
		}

		$line .= ! empty( $state['sweep_done'] )
			? '. Every product is done.'
			: '. The rest are made at night, newest products first.';

		if ( ! empty( $state['last_run'] ) ) {
			$line .= ' Last run: ' . wp_date( 'M j, g:i a', (int) $state['last_run'] ) . '.';
		}

		return $line;
	}
}
