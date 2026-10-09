<?php
/**
 * WP-Cron: refresh token + media cache.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled maintenance.
 */
class Cohort_Instagram_Cron {

	const HOOK = 'cohort_instagram_cron_refresh';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function ensure_scheduled() {
		if ( 'hourly' === wp_get_schedule( self::HOOK ) ) {
			return;
		}

		// Clear any older interval (e.g. twicedaily) or duplicate events, then schedule hourly.
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Refresh token if near expiry, then refresh media.
	 */
	public static function run() {
		if ( ! Cohort_Instagram_Settings::is_connected() ) {
			return;
		}

		$settings   = Cohort_Instagram_Settings::get_settings();
		$expires_at = (int) $settings['token_expires_at'];

		// Refresh when fewer than 7 days remain.
		if ( $expires_at > 0 && $expires_at < ( time() + ( 7 * DAY_IN_SECONDS ) ) ) {
			$result = Cohort_Instagram_API::refresh_token();
			if ( is_wp_error( $result ) ) {
				Cohort_Instagram_Cache::set_error( $result->get_error_message() );
				// Still try media with existing token.
			}
		}

		Cohort_Instagram_API::refresh_media_cache();
	}
}
