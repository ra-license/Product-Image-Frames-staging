<?php
/**
 * WooCommerce > Product Image Frames: progress of the light copies and the
 * Auto-Update Settings, worded the same as RA Promo Manager's.
 *
 * 0.3.0 put this under Settings. 0.3.2 moved it under WooCommerce, since it
 * only affects product photos and that's where store photo settings live
 * (Smart Image Resize and Woo Native Dimensions are there too). Without
 * WooCommerce it stays under Settings. The old Settings address forwards
 * to the new one.
 *
 * @package RA_Product_Image_Frames
 */

defined( 'ABSPATH' ) || exit;

final class RAPIF_Settings {

	const PAGE  = 'ra-product-image-frames';
	const GROUP = 'rapif_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'forward_old_address' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RAPIF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Whether the screen lives under WooCommerce on this site.
	 *
	 * @return bool
	 */
	private static function under_woocommerce() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Address of this screen.
	 *
	 * @return string
	 */
	public static function url() {
		return self::under_woocommerce()
			? admin_url( 'admin.php?page=' . self::PAGE )
			: admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function menu() {
		if ( self::under_woocommerce() ) {
			add_submenu_page(
				'woocommerce',
				__( 'Product Image Frames', 'ra-product-image-frames' ),
				__( 'Product Image Frames', 'ra-product-image-frames' ),
				'manage_options',
				self::PAGE,
				array( __CLASS__, 'render' )
			);
			return;
		}

		add_options_page(
			__( 'Product Image Frames', 'ra-product-image-frames' ),
			__( 'Product Image Frames', 'ra-product-image-frames' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The 0.3.0 address (Settings > Product Image Frames) forwards to
	 * WooCommerce > Product Image Frames instead of showing "Sorry, you are
	 * not allowed to access this page."
	 */
	public static function forward_old_address() {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'options-general.php' === $pagenow && self::PAGE === $page && self::under_woocommerce() && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function register() {
		register_setting(
			self::GROUP,
			RAPIF_Updater::OPTION_CHANNEL,
			array( 'sanitize_callback' => array( 'RAPIF_Updater', 'sanitize_channel' ) )
		);
		register_setting(
			self::GROUP,
			RAPIF_Updater::OPTION_TOKEN,
			array( 'sanitize_callback' => array( 'RAPIF_Updater', 'sanitize_token' ) )
		);
	}

	/**
	 * "Settings" link next to Deactivate on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = self::url();
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ra-product-image-frames' ) . '</a>' );

		return $links;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$channel_locked = defined( 'RAPIF_UPDATE_CHANNEL' ) && RAPIF_UPDATE_CHANNEL;
		$token_locked   = defined( 'RAPIF_GITHUB_UPDATE_TOKEN' ) && RAPIF_GITHUB_UPDATE_TOKEN;
		$token          = RAPIF_Updater::token_info();
		$own_token      = (string) get_option( RAPIF_Updater::OPTION_TOKEN, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Image Frames', 'ra-product-image-frames' ); ?></h1>

			<h2><?php esc_html_e( 'Light copies', 'ra-product-image-frames' ); ?></h2>
			<p><?php echo esc_html( RAPIF_Copies::status_line() ); ?></p>
			<p class="description"><?php esc_html_e( 'Each product photo gets a small, fast copy for category pages and one for the product page. Pages show the original photo until its copy is ready. Original photos are never changed.', 'ra-product-image-frames' ); ?></p>

			<h2>
				<?php
				echo esc_html(
					RAPIF_Safeguards::smart_image_resize_active()
						? __( 'Before removing Smart Image Resize', 'ra-product-image-frames' )
						: __( 'Old image plugins', 'ra-product-image-frames' )
				);
				?>
			</h2>
			<table class="widefat striped" style="max-width:900px;" role="presentation">
				<tbody>
				<?php foreach ( RAPIF_Safeguards::checklist() as $row ) : ?>
					<?php
					if ( true === $row['ok'] ) {
						$icon  = 'dashicons-yes-alt';
						$color = '#008a20';
						$state = __( 'Ready', 'ra-product-image-frames' );
					} elseif ( false === $row['ok'] ) {
						$icon  = 'dashicons-warning';
						$color = '#d63638';
						$state = __( 'Fix first', 'ra-product-image-frames' );
					} else {
						$icon  = 'dashicons-info';
						$color = '#996800';
						$state = __( 'Check', 'ra-product-image-frames' );
					}
					?>
					<tr>
						<td style="width:110px;white-space:nowrap;">
							<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="color:<?php echo esc_attr( $color ); ?>;" aria-hidden="true"></span>
							<strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $state ); ?></strong>
						</td>
						<td>
							<strong><?php echo esc_html( $row['label'] ); ?></strong><br />
							<?php echo esc_html( $row['detail'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( RAPIF_Safeguards::smart_image_resize_active() ) : ?>
				<p class="description"><?php esc_html_e( 'When every row says Ready (or you\'ve checked the Check rows): deactivate and delete Smart Image Resize PRO and ReThumbify Pro, then clear the WP Rocket and CDN caches.', 'ra-product-image-frames' ); ?></p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Auto-Update Settings', 'ra-product-image-frames' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Lets this site find and install new versions of Product Image Frames on its own, instead of someone uploading a zip file every time. Needs a one-time GitHub access key. Ask R&A Marketing for it if you don\'t have it.', 'ra-product-image-frames' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rapif_update_channel"><?php esc_html_e( 'Update Channel', 'ra-product-image-frames' ); ?></label></th>
						<td>
							<select id="rapif_update_channel" name="<?php echo esc_attr( RAPIF_Updater::OPTION_CHANNEL ); ?>" <?php disabled( $channel_locked ); ?>>
								<option value="production" <?php selected( RAPIF_Updater::channel(), 'production' ); ?>><?php esc_html_e( 'Production (default: only gets updates that were already tested)', 'ra-product-image-frames' ); ?></option>
								<option value="staging" <?php selected( RAPIF_Updater::channel(), 'staging' ); ?>><?php esc_html_e( 'Staging (gets new updates first, for testing)', 'ra-product-image-frames' ); ?></option>
							</select>
							<?php if ( $channel_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Set in wp-config.php, which takes priority, so this can\'t be changed here.', 'ra-product-image-frames' ); ?></p>
							<?php else : ?>
								<p class="description"><strong><?php esc_html_e( 'Leave this on "Production" for every real, live website.', 'ra-product-image-frames' ); ?></strong> <?php esc_html_e( 'Only pick "Staging" on the one test site used to check an update before it goes out to everyone else.', 'ra-product-image-frames' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rapif_github_token"><?php esc_html_e( 'GitHub Update Key', 'ra-product-image-frames' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="rapif_github_token" name="<?php echo esc_attr( RAPIF_Updater::OPTION_TOKEN ); ?>" value="<?php echo esc_attr( $own_token ); ?>" autocomplete="off" placeholder="github_pat_..." />
							<?php if ( $token_locked ) : ?>
								<p class="description"><?php esc_html_e( 'A key set in wp-config.php is already active and is used instead of this field.', 'ra-product-image-frames' ); ?></p>
							<?php elseif ( '' === $own_token && '' !== $token['token'] ) : ?>
								<p class="description">
									<?php
									/* translators: %s: another R&A plugin's name. */
									echo esc_html( sprintf( __( 'Using the key already saved for %s on this site. That works once the key is allowed to read Product Image Frames too. Paste a key here only if this plugin needs its own.', 'ra-product-image-frames' ), $token['source'] ) );
									?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Paste the key here once. It only needs to read Product Image Frames\' own code. It can\'t see or change anything else.', 'ra-product-image-frames' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<p class="description"><?php esc_html_e( 'To check for a new version right away, use "Check for updates" under Product Image Frames on the Plugins screen.', 'ra-product-image-frames' ); ?></p>
		</div>
		<?php
	}
}
