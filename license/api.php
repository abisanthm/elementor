<?php
namespace ElementorPro\License;

use Elementor\Core\Common\Modules\Connect\Module as ConnectModule;
use ElementorPro\Plugin;
use ElementorPro\Modules\Tiers\Module as Tiers;
use Elementor\Api as Core_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class API {

	const PRODUCT_NAME = 'Elementor Pro';

	/**
	 * @deprecated 3.8.0
	 */
	const STORE_URL = 'https://my.elementor.com/api/v1/licenses/';

	const BASE_URL = 'https://my.elementor.com/api/v2/';

	const RENEW_URL = 'https://go.elementor.com/renew/';

	// License Statuses
	const STATUS_EXPIRED = 'expired';
	const STATUS_SITE_INACTIVE = 'site_inactive';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_REQUEST_LOCKED = 'request_locked';
	const STATUS_MISSING = 'missing';
	const STATUS_HTTP_ERROR = 'http_error';

	/**
	 * @deprecated 3.8.0
	 */
	const STATUS_VALID = 'valid';
	/**
	 * @deprecated 3.8.0
	 */
	const STATUS_INVALID = 'invalid';

	/**
	 * @deprecated 3.8.0
	 */
	const STATUS_DISABLED = 'disabled';

	/**
	 * @deprecated 3.8.0
	 */
	const STATUS_REVOKED = 'revoked';

	// Features
	const FEATURE_PRO_TRIAL = 'pro_trial';

	// Requests lock config.
	const REQUEST_LOCK_TTL = MINUTE_IN_SECONDS;
	const REQUEST_LOCK_OPTION_NAME = '_elementor_pro_api_requests_lock';

	const TRANSIENT_KEY_PREFIX = 'elementor_pro_remote_info_api_data_';

	const LICENCE_TIER_KEY = 'tier';
	const LICENCE_GENERATION_KEY = 'generation';

	// Tiers.
	const TIER_ESSENIAL = 'essential';
	const TIER_ADVANCED = 'advanced';
	const TIER_EXPERT = 'expert';
	const TIER_AGENCY = 'agency';

	// Generations.
	const GENERATION_ESSENTIAL_OCT2023 = 'essential-oct2023';
	const GENERATION_EMPTY = 'empty';

	const BC_VALIDATION_CALLBACK = 'should_allow_all_features';

	protected static $transient_data = [];

	private static function remote_post( $endpoint, $body_args = [] ) {
		// This function is still used by activate_license and deactivate_license.
		// For update checks and package downloads, we'll bypass it in get_version and get_plugin_package_url.
		$use_home_url = true;

		/**
		 * The license API uses `home_url()` function to retrieve the URL. This hook allows
		 * developers to use `get_site_url()` instead of `home_url()` to set the URL.
		 *
		 * When set to `true` (default) it uses `home_url()`.
		 * When set to `false` it uses `get_site_url()`.
		 *
		 * @param boolean $use_home_url Whether to use `home_url()` or `get_site_url()`.
		 */
		$use_home_url = apply_filters( 'elementor_pro/license/api/use_home_url', $use_home_url );

		$body_args = wp_parse_args(
			$body_args,
			[
				'api_version' => ELEMENTOR_PRO_VERSION,
				'item_name' => self::PRODUCT_NAME,
				'site_lang' => get_bloginfo( 'language' ),
				'url' => $use_home_url ? home_url() : get_site_url(),
			]
		);

		$response = wp_remote_post( self::BASE_URL . $endpoint, [
			'timeout' => 40,
			'body' => $body_args,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error( 'no_json', esc_html__( 'An error occurred, please try again', 'elementor-pro' ) );
		}

		return $data;
	}

	public static function activate_license( $license_key ) {
		$body_args = [
			'license' => $license_key,
		];

		$license_data = self::remote_post( 'license/activate', $body_args );

		return $license_data;
	}

	public static function deactivate_license() {
		$body_args = [
			'license' => '',
		];

		$license_data = self::remote_post( 'license/deactivate', $body_args );

		return $license_data;
	}

	public static function set_transient( $cache_key, $value, $expiration = '+12 hours' ) {
		$data = [
			'timeout' => strtotime( $expiration, current_time( 'timestamp' ) ),
			'value' => json_encode( $value ),
		];

		$updated = update_option( $cache_key, $data, false );
		if ( false === $updated ) {
			self::$transient_data[ $cache_key ] = $data;
		}
	}

	private static function get_transient( $cache_key ) {
		$cache = self::$transient_data[ $cache_key ] ?? get_option( $cache_key );

		if ( empty( $cache['timeout'] ) ) {
			return false;
		}

		if ( current_time( 'timestamp' ) > $cache['timeout'] && is_user_logged_in() ) {
			return false;
		}

		return json_decode( $cache['value'], true );
	}

	public static function set_license_data( $license_data, $expiration = null ) {
		if ( null === $expiration ) {
			$expiration = '+12 hours';

			self::set_transient( Admin::LICENSE_DATA_FALLBACK_OPTION_NAME, $license_data, '+24 hours' );
		}

		self::set_transient( Admin::LICENSE_DATA_OPTION_NAME, $license_data, $expiration );
	}

	/**
	 * Check if another request is in progress.
	 *
	 * @param string $name Request name
	 *
	 * @return bool
	 */
	public static function is_request_running( $name ) {
		$requests_lock = get_option( self::REQUEST_LOCK_OPTION_NAME, [] );
		if ( isset( $requests_lock[ $name ] ) ) {
			if ( $requests_lock[ $name ] > time() - self::REQUEST_LOCK_TTL ) {
				return true;
			}
		}

		$requests_lock[ $name ] = time();
		update_option( self::REQUEST_LOCK_OPTION_NAME, $requests_lock );

		return false;
	}

	public static function get_license_data( $force_request = false ) {
		// Bypass license check by always returning a valid license data.
		return [
			'success' => true,
			'license' => 'valid', // Set to 'valid'
			'payment_id' => '0',
			'license_limit' => '999', // A large number for activations
			'site_count' => '1',
			'activations_left' => '998',
			'expires' => 'lifetime', // Set to lifetime
			'recurring' => true,
			'features' => [
				'pro_trial',
				'template_access_level_1',
				'template_access_level_2',
				'template_access_level_3',
				'template_access_level_4',
				'template_access_level_5',
			],
			'tier' => static::TIER_AGENCY, // Highest tier
			'generation' => static::GENERATION_ESSENTIAL_OCT2023,
		];
	}

	public static function get_version( $force_update = true, $additional_status = '' ) {
		$cache_key = self::TRANSIENT_KEY_PREFIX . ELEMENTOR_PRO_VERSION;

		$info_data = self::get_transient( $cache_key );

		if ( $force_update || false === $info_data ) {
			if ( self::is_request_running( 'get_version' ) ) {
				if ( false !== $info_data ) {
					return $info_data;
				}

				return new \WP_Error( esc_html__( 'Another check is in progress.', 'elementor-pro' ) );
			}

			// Custom GitHub API endpoint for version info
			$github_info_url = 'https://raw.githubusercontent.com/your-github-username/your-repo-name/main/info.json'; // IMPORTANT: Replace with your actual GitHub username and repository name

			$response = wp_remote_get( $github_info_url, [
				'timeout' => 40,
			] );

			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'http_error', esc_html__( 'HTTP Error: Could not fetch update info from GitHub.', 'elementor-pro' ) );
			}

			$info_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $info_data ) || ! is_array( $info_data ) || empty( $info_data['new_version'] ) || empty( $info_data['download_link'] ) ) {
				return new \WP_Error( 'invalid_response', esc_html__( 'Invalid update info from GitHub.', 'elementor-pro' ) );
			}

			self::set_transient( $cache_key, $info_data );
		}

		return $info_data;
	}

	public static function get_plugin_package_url( $version ) {
		// Custom GitHub release download URL
		// IMPORTANT: Replace with your actual GitHub username, repository name, and desired version/filename
		return 'https://github.com/abisanthm/elementor/archive/refs/tags/elementor.zip';
	}

	public static function get_previous_versions() {
		// You might want to implement a custom logic here to fetch previous versions from your GitHub releases
		// For simplicity, returning an empty array or an error if not needed.
		return []; // Or implement custom logic similar to get_version but for release assets.
	}

	public static function get_errors() {
		return [
			'no_activations_left' => esc_html__( 'All features unlocked. No activations limit.', 'elementor-pro' ),
			'expired' => esc_html__( 'License is active (modified).', 'elementor-pro' ),
			'missing' => esc_html__( 'License is active (modified).', 'elementor-pro' ),
			'cancelled' => esc_html__( 'License is active (modified).', 'elementor-pro' ),
			'key_mismatch' => esc_html__( 'License is active (modified).', 'elementor-pro' ),
		];
	}

	public static function get_error_message( $error ) {
		$errors = self::get_errors();

		if ( isset( $errors[ $error ] ) ) {
			$error_msg = $errors[ $error ];
		} else {
			$error_msg = esc_html__( 'An error occurred. Please check your internet connection and try again. If the problem persists, contact our support.', 'elementor-pro' ) . ' (' . $error . ')';
		}

		return $error_msg;
	}

	public static function is_license_active() {
		// Always return true to bypass license checks.
		return true;
	}

	public static function is_license_expired() {
		// Always return false to indicate the license is not expired.
		return false;
	}

	public static function is_licence_pro_trial() {
		return true; // Always true for all features
	}

	public static function is_licence_has_feature( $feature_name, $license_check_validator = null ) {
		return true; // Always true for all features
	}

	private static function custom_licence_validator_passed( $license_check_validator ) {
		return true; // Always true for all features
	}

	private static function should_allow_all_features() {
		return true; // Always true for all features
	}

	private static function is_frontend() {
		return ! is_admin() && ! Plugin::elementor()->preview->is_preview_mode();
	}

	/*
	 * We can consider removing this function and it's usages at a future point if
	 * we feel confident that all user's Licence Caches has been refreshed
	 * and should definitely contain a tier and generation.
	 */
	private static function licence_supports_tiers() {
		return true; // Always true for all features
	}

	public static function is_need_to_show_upgrade_promotion() {
		return false; // Never show upgrade promotion
	}

	private static function is_licence_tier( $tier ) {
		return true; // Always true for highest tier
	}

	private static function is_licence_generation( $generation ) {
		return true; // Always true for latest generation
	}

	public static function filter_active_features( $features ) {
		return array_values( $features ); // Return all features
	}

	public static function get_promotion_widgets() {
		return []; // No promotion widgets
	}

	/*
	 * Check if the Licence is not Expired and also has a Feature.
	 * Needed because even Expired Licences keep the features array for BC.
	 */
	public static function active_licence_has_feature( $feature_name ) {
		return true; // Always active and has feature
	}

	public static function is_license_about_to_expire() {
		return false; // Never about to expire
	}

	/**
	 * @param string $library_type
	 *
	 * @return int
	 */
	public static function get_library_access_level( $library_type = 'template' ) {
		return ConnectModule::ACCESS_LEVEL_PRO; // Always highest access level
	}

	/**
	 * The license API uses "tiers" and "generations".
	 * Because we don't use the same logic, and have a flat list of prioritized tiers & generations,
	 * we take the generation if exists and fallback to the tier otherwise.
	 *
	 * For example:
	 * [ 'tier' => 'essential', 'generation' => 'essential-oct2023' ] => 'essential-oct2023'
	 * [ 'tier' => 'essential', 'generation' => 'empty' ] => 'essential'
	 * [ 'tier' => '', 'generation' => '' ] => 'essential-oct2023'
	 * [] => 'essential-oct2023'
	 *
	 * @return string
	 */
	public static function get_access_tier() {
		return 'agency'; // Always return highest tier
	}
}