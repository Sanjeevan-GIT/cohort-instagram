<?php
/**
 * Media cache (front-end never talks to Meta).
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores normalized media items in an option.
 */
class Cohort_Instagram_Cache {

	const OPTION = 'cohort_instagram_media_cache';

	/**
	 * @return array{items:array,updated_at:int,error:string}
	 */
	public static function get() {
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return array(
			'items'      => isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array(),
			'updated_at' => isset( $data['updated_at'] ) ? (int) $data['updated_at'] : 0,
			'error'      => isset( $data['error'] ) ? (string) $data['error'] : '',
		);
	}

	/**
	 * @param array  $items Normalized media.
	 * @param string $error Last error message (admin only).
	 */
	public static function set( array $items, $error = '' ) {
		update_option(
			self::OPTION,
			array(
				'items'      => array_values( $items ),
				'updated_at' => time(),
				'error'      => sanitize_text_field( (string) $error ),
			),
			false
		);
	}

	/**
	 * Keep previous items on soft failure; store error for admin.
	 *
	 * @param string $error Error message.
	 */
	public static function set_error( $error ) {
		$current = self::get();
		self::set( $current['items'], $error );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Public items for the theme (never includes tokens).
	 *
	 * @param int $limit Max items.
	 * @return array<int,array{id:string,url:string,permalink:string,alt:string,media_type:string}>
	 */
	public static function get_media( $limit = 6 ) {
		$limit   = max( 1, min( 12, (int) $limit ) );
		$cache   = self::get();
		$items   = array();
		foreach ( $cache['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			$url = $url ? esc_url_raw( $url ) : '';
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$permalink = isset( $item['permalink'] ) ? esc_url_raw( (string) $item['permalink'] ) : '';
			$items[] = array(
				'id'           => isset( $item['id'] ) ? (string) $item['id'] : '',
				'url'          => $url,
				'permalink'    => $permalink,
				'alt'          => isset( $item['alt'] ) ? sanitize_text_field( (string) $item['alt'] ) : '',
				'overlay_text' => '',
				'media_type'   => isset( $item['media_type'] ) ? sanitize_key( (string) $item['media_type'] ) : 'image',
				'source'       => 'feed',
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}
}
