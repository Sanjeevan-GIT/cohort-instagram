<?php
/**
 * Instagram Graph API client (server-side only).
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to graph.instagram.com / api.instagram.com.
 */
class Cohort_Instagram_API {

	const GRAPH_BASE = 'https://graph.instagram.com';
	const API_BASE   = 'https://api.instagram.com';
	const SCOPES     = 'instagram_business_basic';

	/**
	 * Exchange authorization code for short-lived token, then long-lived.
	 *
	 * @param string $code OAuth code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$app_id     = Cohort_Instagram_Settings::get_app_id();
		$app_secret = Cohort_Instagram_Settings::get_app_secret();
		$redirect   = Cohort_Instagram_Settings::get_redirect_uri();

		if ( '' === $app_id || '' === $app_secret ) {
			return new WP_Error( 'cohort_ig_config', __( 'Instagram App ID or Secret is missing.', 'cohort-instagram' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/oauth/access_token',
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $redirect,
					'code'          => $code,
				),
			)
		);

		$data = self::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$short_token = isset( $data['access_token'] ) ? (string) $data['access_token'] : '';
		$user_id     = isset( $data['user_id'] ) ? (string) $data['user_id'] : '';

		if ( '' === $short_token ) {
			return new WP_Error( 'cohort_ig_token', __( 'Instagram did not return an access token.', 'cohort-instagram' ) );
		}

		$long = self::exchange_long_lived( $short_token );
		if ( is_wp_error( $long ) ) {
			return $long;
		}

		$expires_in = isset( $long['expires_in'] ) ? (int) $long['expires_in'] : ( 60 * DAY_IN_SECONDS );
		$token      = (string) $long['access_token'];

		Cohort_Instagram_Settings::update_secrets( array( 'access_token' => $token ) );
		Cohort_Instagram_Settings::update_settings(
			array(
				'user_id'          => $user_id,
				'token_expires_at' => time() + max( HOUR_IN_SECONDS, $expires_in ),
				'connected_at'     => time(),
			)
		);

		$profile = self::fetch_profile( $token );
		if ( ! is_wp_error( $profile ) && ! empty( $profile['username'] ) ) {
			Cohort_Instagram_Settings::update_settings(
				array(
					'username' => sanitize_text_field( (string) $profile['username'] ),
					'user_id'  => ! empty( $profile['id'] ) ? (string) $profile['id'] : $user_id,
				)
			);
		}

		$refresh = self::refresh_media_cache();
		if ( is_wp_error( $refresh ) ) {
			// Connected, but media fetch failed — keep token, surface error in cache.
			Cohort_Instagram_Cache::set_error( $refresh->get_error_message() );
		}

		return true;
	}

	/**
	 * @param string $short_token Short-lived user token.
	 * @return array|WP_Error
	 */
	public static function exchange_long_lived( $short_token ) {
		$app_secret = Cohort_Instagram_Settings::get_app_secret();
		$url        = add_query_arg(
			array(
				'grant_type'    => 'ig_exchange_token',
				'client_secret' => $app_secret,
				'access_token'  => $short_token,
			),
			self::GRAPH_BASE . '/access_token'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		$data     = self::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'cohort_ig_long_token', __( 'Could not obtain a long-lived Instagram token.', 'cohort-instagram' ) );
		}
		return $data;
	}

	/**
	 * Refresh long-lived token before expiry.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_token() {
		$token = Cohort_Instagram_Settings::get_access_token();
		if ( '' === $token ) {
			return new WP_Error( 'cohort_ig_no_token', __( 'Not connected to Instagram.', 'cohort-instagram' ) );
		}

		$url = add_query_arg(
			array(
				'grant_type'   => 'ig_refresh_token',
				'access_token' => $token,
			),
			self::GRAPH_BASE . '/refresh_access_token'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		$data     = self::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'cohort_ig_refresh', __( 'Token refresh failed.', 'cohort-instagram' ) );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : ( 60 * DAY_IN_SECONDS );
		Cohort_Instagram_Settings::update_secrets( array( 'access_token' => (string) $data['access_token'] ) );
		Cohort_Instagram_Settings::update_settings(
			array(
				'token_expires_at' => time() + max( HOUR_IN_SECONDS, $expires_in ),
			)
		);

		return true;
	}

	/**
	 * Fetch latest media and write cache.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_media_cache() {
		$token = Cohort_Instagram_Settings::get_access_token();
		if ( '' === $token ) {
			return new WP_Error( 'cohort_ig_no_token', __( 'Not connected to Instagram.', 'cohort-instagram' ) );
		}

		$settings = Cohort_Instagram_Settings::get_settings();
		$limit    = max( 1, min( 12, (int) $settings['media_limit'] ) );

		$url = add_query_arg(
			array(
				'fields'       => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp',
				'limit'        => $limit,
				'access_token' => $token,
			),
			self::GRAPH_BASE . '/me/media'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		$data     = self::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			$message = $data->get_error_message();
			// Auth / permission failures: clear cache so the theme falls back to ACF.
			if ( self::is_auth_failure( $message ) ) {
				Cohort_Instagram_Cache::set( array(), $message );
			} else {
				Cohort_Instagram_Cache::set_error( $message );
			}
			return $data;
		}

		$raw_items = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$items     = array();

		foreach ( $raw_items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = self::normalize_media_item( $row );
			if ( $normalized ) {
				$items[] = $normalized;
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		Cohort_Instagram_Cache::set( $items, '' );
		return true;
	}

	/**
	 * @param string $token Access token.
	 * @return array|WP_Error
	 */
	public static function fetch_profile( $token ) {
		$url = add_query_arg(
			array(
				'fields'       => 'id,username',
				'access_token' => $token,
			),
			self::GRAPH_BASE . '/me'
		);
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		return self::parse_response( $response );
	}

	/**
	 * @param array $row Graph media node.
	 * @return array|null
	 */
	private static function normalize_media_item( array $row ) {
		$type = isset( $row['media_type'] ) ? strtoupper( (string) $row['media_type'] ) : 'IMAGE';
		$url  = '';

		if ( in_array( $type, array( 'VIDEO', 'REELS' ), true ) ) {
			$url = ! empty( $row['thumbnail_url'] ) ? (string) $row['thumbnail_url'] : (string) ( $row['media_url'] ?? '' );
		} else {
			$url = ! empty( $row['media_url'] ) ? (string) $row['media_url'] : '';
			if ( '' === $url && ! empty( $row['thumbnail_url'] ) ) {
				$url = (string) $row['thumbnail_url'];
			}
		}

		if ( '' === $url ) {
			return null;
		}

		$caption = isset( $row['caption'] ) ? wp_strip_all_tags( (string) $row['caption'] ) : '';
		$alt     = $caption ? wp_html_excerpt( $caption, 100, '…' ) : '';

		return array(
			'id'         => isset( $row['id'] ) ? (string) $row['id'] : '',
			'url'        => esc_url_raw( $url ),
			'permalink'  => isset( $row['permalink'] ) ? esc_url_raw( (string) $row['permalink'] ) : '',
			'alt'        => sanitize_text_field( $alt ),
			'media_type' => sanitize_key( strtolower( $type ) ),
		);
	}

	/**
	 * @param array|WP_Error $response wp_remote_* response.
	 * @return array|WP_Error
	 */
	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cohort_ig_http', __( 'Could not reach Instagram. Check server outbound HTTPS.', 'cohort-instagram' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cohort_ig_json', __( 'Invalid response from Instagram.', 'cohort-instagram' ) );
		}

		if ( $code >= 400 || isset( $data['error'] ) ) {
			$message = __( 'Instagram API error.', 'cohort-instagram' );
			if ( isset( $data['error']['message'] ) ) {
				$message = sanitize_text_field( (string) $data['error']['message'] );
			} elseif ( isset( $data['error_message'] ) ) {
				$message = sanitize_text_field( (string) $data['error_message'] );
			}
			// Never include tokens in admin-facing errors.
			$message = preg_replace( '/access_token=[^&\s]+/i', 'access_token=[redacted]', $message );
			return new WP_Error( 'cohort_ig_api', $message );
		}

		return $data;
	}

	/**
	 * Whether an API error means the connection is unusable (show ACF on front).
	 *
	 * @param string $message Sanitized API error message.
	 * @return bool
	 */
	private static function is_auth_failure( $message ) {
		$message = strtolower( (string) $message );
		$needles = array(
			'invalid oauth',
			'oauth exception',
			'error validating access token',
			'access token has expired',
			'session has expired',
			'permission',
			'not authorized',
			'invalid_token',
			'cannot parse access token',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
