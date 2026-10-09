<?php
/**
 * Fallback slides (Media Library) when Instagram feed is unavailable.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and resolves fallback slider images.
 */
class Cohort_Instagram_Fallbacks {

	const OPTION = 'cohort_instagram_fallbacks';
	const MAX    = 12;

	/**
	 * Raw stored rows.
	 *
	 * @return array<int,array{attachment_id:int,overlay_text:string,link:string}>
	 */
	public static function get_raw() {
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$rows[] = array(
				'attachment_id' => $id,
				'overlay_text'  => isset( $row['overlay_text'] ) ? sanitize_text_field( (string) $row['overlay_text'] ) : '',
				'link'          => isset( $row['link'] ) ? esc_url_raw( (string) $row['link'] ) : '',
			);
			if ( count( $rows ) >= self::MAX ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Replace fallback rows.
	 *
	 * @param array $rows Incoming rows.
	 */
	public static function save( array $rows ) {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0;
			if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$clean[] = array(
				'attachment_id' => $id,
				'overlay_text'  => isset( $row['overlay_text'] ) ? sanitize_text_field( (string) $row['overlay_text'] ) : '',
				'link'          => isset( $row['link'] ) ? esc_url_raw( (string) $row['link'] ) : '',
			);
			if ( count( $clean ) >= self::MAX ) {
				break;
			}
		}

		if ( empty( $clean ) ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $clean, false );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Normalized slides for the theme (same shape as live feed + overlay).
	 *
	 * @param int $limit Max items.
	 * @return array<int,array{id:string,url:string,permalink:string,alt:string,overlay_text:string,media_type:string,source:string}>
	 */
	public static function get_media( $limit = 6 ) {
		$limit = max( 1, min( self::MAX, (int) $limit ) );
		$items = array();

		foreach ( self::get_raw() as $row ) {
			$id  = (int) $row['attachment_id'];
			$url = wp_get_attachment_image_url( $id, 'cohort-card' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $id, 'large' );
			}
			if ( ! $url ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( ! $url ) {
				continue;
			}

			$overlay = (string) $row['overlay_text'];
			$alt     = $overlay;
			if ( '' === $alt ) {
				$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			}

			$items[] = array(
				'id'           => 'fallback-' . $id,
				'url'          => esc_url_raw( $url ),
				'permalink'    => (string) $row['link'],
				'alt'          => sanitize_text_field( $alt ),
				'overlay_text' => $overlay,
				'media_type'   => 'image',
				'source'       => 'fallback',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}
}
