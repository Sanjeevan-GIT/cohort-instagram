<?php
/**
 * Public API for themes.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slides for the Instagram section: live cached feed, else plugin fallbacks.
 *
 * Never triggers live Meta API calls on the front end.
 *
 * @param int $limit Max items (1–12). Defaults to plugin setting.
 * @return array<int,array{id:string,url:string,permalink:string,alt:string,overlay_text:string,media_type:string,source:string}>
 */
function cohort_instagram_get_media( $limit = 0 ) {
	if ( $limit <= 0 ) {
		$settings = Cohort_Instagram_Settings::get_settings();
		$limit    = (int) $settings['media_limit'];
	}
	$limit = max( 1, min( 12, (int) $limit ) );

	if ( Cohort_Instagram_Settings::is_connected() ) {
		$live = Cohort_Instagram_Cache::get_media( $limit );
		if ( ! empty( $live ) ) {
			return $live;
		}
	}

	return Cohort_Instagram_Fallbacks::get_media( $limit );
}

/**
 * Whether the plugin can supply any slides (feed or fallbacks).
 *
 * @return bool
 */
function cohort_instagram_has_media() {
	return count( cohort_instagram_get_media( 1 ) ) > 0;
}
