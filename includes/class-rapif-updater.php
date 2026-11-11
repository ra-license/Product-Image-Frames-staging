<?php
/**
 * Self-update from a private GitHub repo (added in 0.3.0).
 *
 * The same system as Universal Room Planner (7.17.0-7.20.0) and RA Promo
 * Manager, brought over as-is rather than re-solved: the bundled, unmodified
 * plugin-update-checker library, the shared RA Monitor Client check-in, and
 * "Update now" from R&A's Plugin Monitor.
 *
 * Staging and production check TWO SEPARATE private repos, not two branches
 * of one repo: plugin-update-checker prefers tags/releases over whatever
 * branch is set, so branch-only separation can't reliably keep a tagged
 * release away from production. A version only reaches live sites once it's
 * deliberately pushed to the production repo, after being verified on staging.
 *
 * Channel (defaults to production, the safe choice if nothing is set):
 *   define( 'RAPIF_UPDATE_CHANNEL', 'staging' ); // only ever on the staging site
 *
 * Each site also needs a read-only GitHub token that covers this plugin's
 * repo(s) (a token scoped to other repos silently fails here, the bug Room
 * Planner hit in 7.19.1). Either in wp-config.php (takes priority):
 *   define( 'RAPIF_GITHUB_UPDATE_TOKEN', 'github_pat_xxxxxxxxxxxxxxxxxxxx' );
 * ...or via WooCommerce > Product Image Frames. If neither is set, the key
 * already set for RA Promo Manager, Universal Room Planner or Woo Native
 * Dimensions on the same site is used (wp-config.php or saved), so a shared
 * R&A key works once it's allowed to read this repo too.
 *
 * @package RA_Product_Image_Frames
 */

defined( 'ABSPATH' ) || exit;

final class RAPIF_Updater {

	const SLUG            = 'ra-product-image-frames';
	const REPO_PRODUCTION = 'https://github.com/ra-license/R-A-Product-Image-Frames/';
	const REPO_STAGING    = 'https://github.com/ra-license/R-A-Product-Image-Frames-staging/';

	const OPTION_TOKEN   = 'rapif_github_update_token';
	const OPTION_CHANNEL = 'rapif_update_channel';

	/**
	 * Other R&A plugins' keys, tried in this order if this one has none:
	 * wp-config.php constant name => saved option name => plugin name.
	 * 0.3.1: constants too. Slone keeps Promo Manager's key in wp-config.php,
	 * so 0.3.0 (options only) found nothing there.
	 */
	const SHARED_TOKENS = array(
		array( 'RAPM_GITHUB_UPDATE_TOKEN', 'rapm_github_update_token', 'RA Promo Manager' ),
		array( 'URP_GITHUB_UPDATE_TOKEN', 'urp_github_update_token', 'Universal Room Planner' ),
		array( 'RMA_GITHUB_UPDATE_TOKEN', 'rma_github_update_token', 'Woo Native Dimensions' ),
	);

	/** @var object|null The update checker. */
	private static $checker = null;

	public static function channel() {
		if ( defined( 'RAPIF_UPDATE_CHANNEL' ) && RAPIF_UPDATE_CHANNEL ) {
			return 'staging' === RAPIF_UPDATE_CHANNEL ? 'staging' : 'production';
		}

		return 'staging' === get_option( self::OPTION_CHANNEL, 'production' ) ? 'staging' : 'production';
	}

	/**
	 * The key in use and where it came from.
	 *
	 * @return array array( 'token' => string, 'source' => string )
	 */
	public static function token_info() {
		if ( defined( 'RAPIF_GITHUB_UPDATE_TOKEN' ) && RAPIF_GITHUB_UPDATE_TOKEN ) {
			return array(
				'token'  => (string) RAPIF_GITHUB_UPDATE_TOKEN,
				'source' => 'wp-config.php',
			);
		}

		$own = (string) get_option( self::OPTION_TOKEN, '' );
		if ( '' !== $own ) {
			return array(
				'token'  => $own,
				'source' => 'this screen',
			);
		}

		foreach ( self::SHARED_TOKENS as $shared ) {
			list( $constant, $option, $plugin_name ) = $shared;

			if ( defined( $constant ) && constant( $constant ) ) {
				return array(
					'token'  => (string) constant( $constant ),
					'source' => $plugin_name,
				);
			}

			$saved = (string) get_option( $option, '' );
			if ( '' !== $saved ) {
				return array(
					'token'  => $saved,
					'source' => $plugin_name,
				);
			}
		}

		return array(
			'token'  => '',
			'source' => '',
		);
	}

	/**
	 * Runs at plugin load, not on a hook: the library needs to be set up
	 * before WordPress's own update check fires.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function init( $plugin_file ) {
		$dir = plugin_dir_path( $plugin_file );

		require_once $dir . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'staging' === self::channel() ? self::REPO_STAGING : self::REPO_PRODUCTION,
			$plugin_file,
			self::SLUG
		);
		$checker->setBranch( 'main' );

		$token = self::token_info();
		if ( '' !== $token['token'] ) {
			$checker->setAuthentication( $token['token'] );
		}

		self::$checker = $checker;

		// Auto-update on by default for this plugin on every site, so a version
		// promoted to the production repo applies everywhere with zero clicks
		// (same as Room Planner 7.20.0). A site opts out with
		// define( 'RA_PLUGINS_AUTO_UPDATE', false ); like every R&A plugin.
		$basename = plugin_basename( $plugin_file );
		add_filter(
			'auto_update_plugin',
			function ( $update, $item ) use ( $basename ) {
				if ( defined( 'RA_PLUGINS_AUTO_UPDATE' ) && ! RA_PLUGINS_AUTO_UPDATE ) {
					return $update;
				}
				if ( isset( $item->plugin ) && $item->plugin === $basename ) {
					return true;
				}
				return $update;
			},
			10,
			2
		);

		// Daily check-in to R&A's Plugin Monitor (help.ramarketing.com), via the
		// shared lib/ra-monitor-client, the same file bundled into every R&A
		// plugin. Reports this site's R&A plugins, their versions, and whether
		// the last update check actually reached GitHub. No tokens or customer
		// data. Turn off per site with define( 'RA_MONITOR_DISABLE', true );
		require_once $dir . 'lib/ra-monitor-client/ra-monitor-client.php';
		RA_Monitor_Client::register(
			array(
				'file'      => $plugin_file,
				'slug'      => self::SLUG,
				'checker'   => $checker,
				'has_token' => '' !== $token['token'],
				'channel'   => self::channel(),
			)
		);

		// "Update now" from the Plugin Monitor. This site asks the monitor every
		// 15 minutes whether there's anything for it and, when asked, installs
		// the newest release on its own channel with WordPress's own automatic
		// updater. The monitor never sends files, only "update". Turn off per
		// site with define( 'RA_MONITOR_REMOTE_UPDATES', false );
		require_once $dir . 'lib/ra-monitor-client/ra-monitor-updates.php';
		RA_Monitor_Updates::register(
			array(
				'file'    => $plugin_file,
				'slug'    => self::SLUG,
				'checker' => $checker,
				'channel' => self::channel(),
			)
		);
	}

	/**
	 * @return object|null The update checker, for "Check for updates" links.
	 */
	public static function checker() {
		return self::$checker;
	}

	public static function sanitize_channel( $value ) {
		return 'staging' === $value ? 'staging' : 'production';
	}

	public static function sanitize_token( $value ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $value );
	}
}
