<?php
/**
 * Uninstall cleanup — removes tokens, cache, and settings.
 *
 * @package Cohort_Instagram
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cohort_instagram_settings' );
delete_option( 'cohort_instagram_secrets' );
delete_option( 'cohort_instagram_media_cache' );
delete_option( 'cohort_instagram_fallbacks' );
delete_option( 'cohort_instagram_enc_key' );

wp_clear_scheduled_hook( 'cohort_instagram_cron_refresh' );

$transients = array(
	'cohort_instagram_oauth_state',
	'cohort_instagram_rate_limit',
);

foreach ( $transients as $key ) {
	delete_transient( $key );
}
