<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen (not when it's
 * turned off). Removes the light copies this plugin made and its own records.
 * Original uploads are never touched.
 *
 * @package RA_Product_Image_Frames
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'rapif_make_copies', array(), 'rapif' );
}
wp_clear_scheduled_hook( 'rapif_make_copies' );

delete_option( 'rapif_state' );
delete_option( 'rapif_wanted' );
delete_option( 'rapif_github_update_token' );
delete_option( 'rapif_update_channel' );
delete_transient( 'rapif_lock' );
delete_transient( 'rapif_check' );
delete_post_meta_by_key( '_rapif_copies' );

// Remove uploads/rapif/ and everything in it (only this plugin writes there).
$rapif_uploads = wp_get_upload_dir();
$rapif_dir     = trailingslashit( $rapif_uploads['basedir'] ) . 'rapif';

if ( is_dir( $rapif_dir ) && 'rapif' === basename( $rapif_dir ) ) {
	$rapif_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $rapif_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $rapif_items as $rapif_item ) {
		if ( $rapif_item->isDir() ) {
			@rmdir( $rapif_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			wp_delete_file( $rapif_item->getPathname() );
		}
	}
	@rmdir( $rapif_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
