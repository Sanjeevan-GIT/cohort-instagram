<?php
/**
 * Settings + encrypted secret storage.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes plugin options. Secrets never leave encrypted storage / constants.
 */
class Cohort_Instagram_Settings {

	const OPTION_SETTINGS = 'cohort_instagram_settings';
	const OPTION_SECRETS  = 'cohort_instagram_secrets';

	/**
	 * Public (non-secret) settings defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'app_id'           => '',
			'user_id'          => '',
			'username'         => '',
			'token_expires_at' => 0,
			'connected_at'     => 0,
			'media_limit'      => 6,
		);
	}

	/**
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * @param array $patch Partial settings.
	 */
	public static function update_settings( array $patch ) {
		$current = self::get_settings();
		$allowed = array_keys( self::defaults() );
		foreach ( $patch as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$current[ $key ] = $value;
		}
		$current['media_limit'] = max( 1, min( 12, (int) $current['media_limit'] ) );
		update_option( self::OPTION_SETTINGS, $current, false );
	}

	/**
	 * App ID from constant or settings.
	 *
	 * @return string
	 */
	public static function get_app_id() {
		if ( defined( 'COHORT_INSTAGRAM_APP_ID' ) && COHORT_INSTAGRAM_APP_ID ) {
			return (string) COHORT_INSTAGRAM_APP_ID;
		}
		$settings = self::get_settings();
		return (string) ( $settings['app_id'] ?? '' );
	}

	/**
	 * App secret from constant or encrypted option.
	 *
	 * @return string
	 */
	public static function get_app_secret() {
		if ( defined( 'COHORT_INSTAGRAM_APP_SECRET' ) && COHORT_INSTAGRAM_APP_SECRET ) {
			return (string) COHORT_INSTAGRAM_APP_SECRET;
		}
		$secrets = self::get_secrets();
		return (string) ( $secrets['app_secret'] ?? '' );
	}

	/**
	 * @return string
	 */
	public static function get_access_token() {
		$secrets = self::get_secrets();
		return (string) ( $secrets['access_token'] ?? '' );
	}

	/**
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::get_access_token();
	}

	/**
	 * @return bool
	 */
	public static function credentials_configured() {
		return '' !== self::get_app_id() && '' !== self::get_app_secret();
	}

	/**
	 * OAuth redirect URI (must match Meta app settings exactly).
	 *
	 * No query string — Instagram often strips/replaces query args on return,
	 * which breaks code exchange when redirect_uri includes ?page=… params.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Decrypted secrets array.
	 *
	 * @return array{app_secret?:string,access_token?:string}
	 */
	private static function get_secrets() {
		$raw = get_option( self::OPTION_SECRETS, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$json = Cohort_Instagram_Encryption::decrypt( $raw );
		if ( '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Merge and re-encrypt secrets.
	 *
	 * @param array $patch Keys: app_secret, access_token. Empty string clears.
	 */
	public static function update_secrets( array $patch ) {
		$current = self::get_secrets();
		foreach ( array( 'app_secret', 'access_token' ) as $key ) {
			if ( ! array_key_exists( $key, $patch ) ) {
				continue;
			}
			$value = (string) $patch[ $key ];
			if ( '' === $value ) {
				unset( $current[ $key ] );
			} else {
				$current[ $key ] = $value;
			}
		}

		if ( empty( $current ) ) {
			delete_option( self::OPTION_SECRETS );
			return;
		}

		$encoded = Cohort_Instagram_Encryption::encrypt( wp_json_encode( $current ) );
		if ( '' === $encoded ) {
			return;
		}
		update_option( self::OPTION_SECRETS, $encoded, false );
	}

	/**
	 * Clear connection token + profile metadata (keeps app credentials).
	 */
	public static function disconnect() {
		self::update_secrets( array( 'access_token' => '' ) );
		self::update_settings(
			array(
				'user_id'          => '',
				'username'         => '',
				'token_expires_at' => 0,
				'connected_at'     => 0,
			)
		);
		Cohort_Instagram_Cache::clear();
	}

	/**
	 * Wipe everything including app credentials stored in options.
	 */
	public static function reset_all() {
		delete_option( self::OPTION_SETTINGS );
		delete_option( self::OPTION_SECRETS );
		Cohort_Instagram_Cache::clear();
	}
}
