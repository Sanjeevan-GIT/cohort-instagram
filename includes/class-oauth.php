<?php
/**
 * OAuth (Instagram Login) — CSRF state + secure callback.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authorization URL + callback handler.
 */
class Cohort_Instagram_OAuth {

	const STATE_TRANSIENT = 'cohort_instagram_oauth_state';
	const STATE_TTL       = 600;

	/**
	 * Build Instagram authorize URL.
	 *
	 * @return string|WP_Error
	 */
	public static function get_authorize_url() {
		if ( ! Cohort_Instagram_Settings::credentials_configured() ) {
			return new WP_Error( 'cohort_ig_config', __( 'Save App ID and App Secret before connecting.', 'cohort-instagram' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT . '_' . get_current_user_id(), $state, self::STATE_TTL );

		return add_query_arg(
			array(
				'client_id'     => Cohort_Instagram_Settings::get_app_id(),
				'redirect_uri'  => Cohort_Instagram_Settings::get_redirect_uri(),
				'response_type' => 'code',
				'scope'         => Cohort_Instagram_API::SCOPES,
				'state'         => $state,
			),
			'https://www.instagram.com/oauth/authorize'
		);
	}

	/**
	 * Handle OAuth return from Instagram on admin-post.php (or legacy paths).
	 */
	public static function maybe_handle_callback() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! self::is_oauth_callback_request() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Instagram.', 'cohort-instagram' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via OAuth state + capability.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		if ( $error ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error_description'] ) ) : $error;
			self::redirect_with_notice( 'error', $desc );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';

		$expected = get_transient( self::STATE_TRANSIENT . '_' . get_current_user_id() );
		delete_transient( self::STATE_TRANSIENT . '_' . get_current_user_id() );

		if ( ! $code || ! $state || ! is_string( $expected ) || ! hash_equals( $expected, $state ) ) {
			self::redirect_with_notice( 'error', __( 'Invalid OAuth state. Please try connecting again.', 'cohort-instagram' ) );
			return;
		}

		$result = Cohort_Instagram_API::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message() );
			return;
		}

		self::redirect_with_notice( 'success', __( 'Instagram connected. Media cache updated.', 'cohort-instagram' ) );
	}

	/**
	 * Whether the current admin request is an Instagram OAuth callback.
	 *
	 * @return bool
	 */
	private static function is_oauth_callback_request() {
		global $pagenow;

		if ( ! in_array( $pagenow, array( 'admin-post.php', 'options-general.php' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; state verified later.
		$has_code  = ! empty( $_GET['code'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_error = ! empty( $_GET['error'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state     = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';

		if ( '' === $state || ( ! $has_code && ! $has_error ) ) {
			return false;
		}

		// Only claim callbacks that match our pending Connect attempt.
		$expected = get_transient( self::STATE_TRANSIENT . '_' . get_current_user_id() );
		return is_string( $expected ) && hash_equals( $expected, $state );
	}

	/**
	 * @param string $type success|error.
	 * @param string $message Notice text.
	 */
	private static function redirect_with_notice( $type, $message ) {
		set_transient(
			'cohort_instagram_admin_notice_' . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
			),
			120
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=cohort-instagram' ) );
		exit;
	}
}
