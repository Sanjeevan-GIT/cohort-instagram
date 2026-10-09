<?php
/**
 * Plugin Name:       Cohort Instagram
 * Description:       Secure Instagram Graph feed (Business/Creator). Caches last posts for the Cohort Creative theme. Tokens are encrypted and never exposed on the front end.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cohort Creative
 * Text Domain:       cohort-instagram
 * License:           GPL-2.0-or-later
 *
 * Optional wp-config.php constants:
 *   COHORT_INSTAGRAM_APP_ID
 *   COHORT_INSTAGRAM_APP_SECRET
 *   COHORT_INSTAGRAM_ENCRYPTION_KEY
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

define( 'COHORT_INSTAGRAM_VERSION', '1.0.0' );
define( 'COHORT_INSTAGRAM_FILE', __FILE__ );
define( 'COHORT_INSTAGRAM_DIR', plugin_dir_path( __FILE__ ) );

require_once COHORT_INSTAGRAM_DIR . 'includes/class-encryption.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-cache.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-fallbacks.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-settings.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-api.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-oauth.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-cron.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/class-admin.php';
require_once COHORT_INSTAGRAM_DIR . 'includes/functions.php';

/**
 * Bootstrap.
 */
function cohort_instagram_bootstrap() {
	Cohort_Instagram_Cron::init();
	if ( is_admin() ) {
		Cohort_Instagram_Admin::init();
	}
}
add_action( 'plugins_loaded', 'cohort_instagram_bootstrap' );

/**
 * Activation: ensure encryption key + schedule cron.
 */
function cohort_instagram_activate() {
	// Force encryption key generation.
	Cohort_Instagram_Encryption::encrypt( 'cohort-instagram-ping' );
	Cohort_Instagram_Cron::ensure_scheduled();
}
register_activation_hook( __FILE__, 'cohort_instagram_activate' );

/**
 * Deactivation: clear cron only (keep credentials until uninstall).
 */
function cohort_instagram_deactivate() {
	Cohort_Instagram_Cron::deactivate();
}
register_deactivation_hook( __FILE__, 'cohort_instagram_deactivate' );
