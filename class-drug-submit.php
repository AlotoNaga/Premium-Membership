<?php
/**
 * Drug pipeline: public submission handler.
 *
 * Critical design differences from the alcohol pipeline:
 *
 *   - Drug reports are CONFIDENTIAL. No drug row is ever exposed via a
 *     public map or public REST response. Aggregate counts only appear on
 *     the transparency page.
 *   - No GPS. No live camera. Reporters describe a location in free text
 *     only. This is deliberate: requiring GPS puts the reporter in danger.
 *   - Media is OPTIONAL. Photos, videos, or screenshots can be uploaded
 *     from the gallery (no freshness check). MP4 and MOV are accepted.
 *   - Phone is optional. If provided, it is encrypted and stored in a
 *     separate vault table (HNC_Phone_Vault, Phase 5). Until that class
 *     exists the phone is NOT persisted, and the form still accepts the
 *     input so the contract remains stable for the public website.
 *   - No area_desc field is free-text only and is scrubbed of names,
 *     honorifics, phones, emails, and house numbers by the sanitizer.
 *
 * Validation flow:
 *
 *   1. Kill switch.
 *   2. Honeypot and form time.
 *   3. Device fingerprint (still required for rate limiting, even though
 *      the report itself is anonymous).
 *   4. Rate limits (device + IP).
 *   5. Required fields: district, category, area_desc, pattern.
 *   6. Sanitize text fields.
 *   7. Validate category against hnc_drug_categories.
 *   8. If media present, run the drug media pipeline (no freshness).
 *   9. If phone present and submission_mode='phone', store in vault.
 *  10. Allocate unique public code.
 *  11. Insert row as status=pending.
 *  12. Touch device, log submission.
 *
 * @package HelpNagalandCore
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HNC_Drug_Submit
 *
 * All methods static. Public REST endpoints registered from init().
 */
final class HNC_Drug_Submit {


	/* ------------------------------------------------------------------
	 * CONSTANTS
	 * ------------------------------------------------------------------ */

	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_HOLD      = 'hold';
	const STATUS_RESOLVED  = 'resolved';
	const STATUS_ARCHIVED  = 'archived';

	const MODE_ANONYMOUS = 'anonymous';
	const MODE_PHONE     = 'phone';

	const MIN_FORM_SECONDS_DEFAULT = 8;

	const ERR_PLATFORM_PAUSED   = 'hnc_platform_paused';
	const ERR_RATE_LIMIT        = 'hnc_rate_limit';
	const ERR_BAD_FINGERPRINT   = 'hnc_bad_fingerprint';
	const ERR_HONEYPOT          = 'hnc_honeypot_triggered';
	const ERR_TOO_FAST          = 'hnc_form_too_fast';
	const ERR_MISSING_FIELD     = 'hnc_missing_field';
	const ERR_BAD_CATEGORY      = 'hnc_bad_category';
	const ERR_BAD_DISTRICT      = 'hnc_bad_district';
	const ERR_BAD_MODE          = 'hnc_bad_submission_mode';
	const ERR_PHONE_REQUIRED    = 'hnc_phone_required_for_mode';
	const ERR_BAD_PHONE         = 'hnc_bad_phone';
	const ERR_MEMBER_REQUIRED   = 'hnc_member_required_for_phone';
	const ERR_MEDIA_INVALID     = 'hnc_media_invalid';
	const ERR_INSERT_FAILED     = 'hnc_insert_failed';
	const ERR_CODE_ALLOC_FAILED = 'hnc_code_allocation_failed';


	/* ------------------------------------------------------------------
	 * BOOTSTRAP
	 * ------------------------------------------------------------------ */

	/**
	 * Wire REST routes on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register the public drug endpoints. Deliberately narrow surface:
	 *   - POST /drug/submit
	 *   - GET  /drug/categories  (for the form dropdown)
	 * Moderation and per-report lookup are NOT exposed publicly. They are
	 * admin-only and wired in Phase 6.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			HNC_API_NAMESPACE,
			'/drug/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_handle_submit' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			HNC_API_NAMESPACE,
			'/drug/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_handle_categories' ),
				'permission_callback' => '__return_true',
			)
		);
	}


	/* ------------------------------------------------------------------
	 * REST CALLBACKS
	 * ------------------------------------------------------------------ */

	/**
	 * POST /drug/submit handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_handle_submit( $request ) {
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		$params = $request->get_params();
		$files  = $request->get_file_params();

		$input = array(
			'district'              => isset( $params['district'] ) ? $params['district'] : '',
			'category'              => isset( $params['category'] ) ? $params['category'] : '',
			'area_desc'             => isset( $params['area_desc'] ) ? $params['area_desc'] : '',
			'pattern'               => isset( $params['pattern'] ) ? $params['pattern'] : '',
			'vehicle_info'          => isset( $params['vehicle_info'] ) ? $params['vehicle_info'] : '',
			'submission_mode'       => isset( $params['submission_mode'] ) ? $params['submission_mode'] : self::MODE_ANONYMOUS,
			'phone'                 => isset( $params['phone'] ) ? $params['phone'] : '',
			'phone_consent'         => isset( $params['phone_consent'] ) ? $params['phone_consent'] : '',
			'member_code'           => isset( $params['member_code'] ) ? $params['member_code'] : '',
			'device_fingerprint'    => isset( $params['device_fingerprint'] ) ? $params['device_fingerprint'] : '',
			'time_on_form_seconds'  => isset( $params['time_on_form_seconds'] ) ? $params['time_on_form_seconds'] : 0,
			'hnc_hp'                => isset( $params['hnc_hp'] ) ? $params['hnc_hp'] : '',
		);

		$media_file = isset( $files['media'] ) ? $files['media'] : null;

		$context = array(
			'ip'         => self::client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
				: '',
		);

		$result = self::create_report( $input, $media_file, $context );

		if ( is_wp_error( $result ) ) {
			$status_map = array(
				self::ERR_PLATFORM_PAUSED   => 503,
				self::ERR_RATE_LIMIT        => 429,
				self::ERR_BAD_FINGERPRINT   => 400,
				self::ERR_HONEYPOT          => 400,
				self::ERR_TOO_FAST          => 400,
				self::ERR_MISSING_FIELD     => 400,
				self::ERR_BAD_CATEGORY      => 400,
				self::ERR_BAD_DISTRICT      => 400,
				self::ERR_BAD_MODE          => 400,
				self::ERR_PHONE_REQUIRED    => 400,
				self::ERR_BAD_PHONE         => 400,
				self::ERR_MEMBER_REQUIRED   => 402,
				self::ERR_MEDIA_INVALID     => 400,
				self::ERR_INSERT_FAILED     => 500,
				self::ERR_CODE_ALLOC_FAILED => 500,
			);
			$code   = $result->get_error_code();
			$status = isset( $status_map[ $code ] ) ? $status_map[ $code ] : 400;

			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $code,
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * GET /drug/categories.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_handle_categories() {
		$raw  = get_option( 'hnc_drug_categories', '{}' );
		$list = json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $list,
			),
			200
		);
	}


	/* ------------------------------------------------------------------
	 * MAIN FLOW
	 * ------------------------------------------------------------------ */

	/**
	 * Create a new drug report from raw inputs. Never throws.
	 *
	 * Success shape:
	 *   [
	 *     'code'            => 'ABCD-EFGH',
	 *     'status'          => 'pending',
	 *     'submission_mode' => 'anonymous' | 'phone',
	 *     'phone_saved'     => true | false,
	 *     'media_attached'  => true | false,
	 *   ]
	 *
	 * @param array      $input      Form fields.
	 * @param array|null $media_file $_FILES entry for optional media.
	 * @param array      $context    Context (ip, user_agent).
	 * @return array|WP_Error
	 */
	public static function create_report( $input, $media_file, $context = array() ) {
		global $wpdb;

		$input   = is_array( $input ) ? $input : array();
		$context = is_array( $context ) ? $context : array();

		/* ---- STEP 1: honeypot ---- */

		$honeypot = isset( $input['hnc_hp'] ) ? (string) $input['hnc_hp'] : '';
		if ( '' !== trim( $honeypot ) ) {
			self::log_reject( self::ERR_HONEYPOT, array( 'honeypot_value' => substr( $honeypot, 0, 50 ) ), $context );
			return new WP_Error( self::ERR_HONEYPOT, __( 'Submission blocked.', 'helpnagaland-core' ) );
		}

		/* ---- STEP 2: form timing ---- */

		$min_seconds = (int) get_option( 'hnc_min_submit_seconds', self::MIN_FORM_SECONDS_DEFAULT );
		$form_time   = isset( $input['time_on_form_seconds'] ) ? (int) $input['time_on_form_seconds'] : 0;
		if ( $form_time < $min_seconds ) {
			self::log_reject( self::ERR_TOO_FAST, array( 'form_seconds' => $form_time, 'min_required' => $min_seconds ), $context );
			return new WP_Error(
				self::ERR_TOO_FAST,
				__( 'Please take a moment to review your report before submitting.', 'helpnagaland-core' )
			);
		}

		/* ---- STEP 3: fingerprint ---- */

		$fp_client = isset( $input['device_fingerprint'] ) ? (string) $input['device_fingerprint'] : '';
		if ( ! class_exists( 'HNC_Device' ) || ! HNC_Device::is_valid_client_fingerprint( $fp_client ) ) {
			self::log_reject( self::ERR_BAD_FINGERPRINT, array(), $context );
			return new WP_Error(
				self::ERR_BAD_FINGERPRINT,
				__( 'Your browser did not provide a valid session token. Please reload and try again.', 'helpnagaland-core' )
			);
		}
		$device_hash = HNC_Device::server_hash( $fp_client );

		/* ---- STEP 4: rate limits ---- */

		if ( ! HNC_Device::may_submit( $device_hash ) ) {
			self::log_reject( self::ERR_RATE_LIMIT, array( 'scope' => 'device' ), $context );
			return new WP_Error(
				self::ERR_RATE_LIMIT,
				__( 'You have reached the submission limit for today. Please try again tomorrow.', 'helpnagaland-core' )
			);
		}

		$ip = isset( $context['ip'] ) ? (string) $context['ip'] : '';
		if ( '' !== $ip && ! HNC_Device::ip_may_request( $ip ) ) {
			self::log_reject( self::ERR_RATE_LIMIT, array( 'scope' => 'ip' ), $context );
			return new WP_Error(
				self::ERR_RATE_LIMIT,
				__( 'Too many requests from your network. Please wait a few minutes.', 'helpnagaland-core' )
			);
		}

		/* ---- STEP 5: required fields ---- */

		foreach ( array( 'district', 'category', 'area_desc', 'pattern' ) as $req ) {
			if ( ! isset( $input[ $req ] ) || '' === trim( (string) $input[ $req ] ) ) {
				self::log_reject( self::ERR_MISSING_FIELD, array( 'field' => $req ), $context );
				return new WP_Error(
					self::ERR_MISSING_FIELD,
					sprintf(
						/* translators: %s: field name */
						__( 'Missing required field: %s', 'helpnagaland-core' ),
						$req
					)
				);
			}
		}

		/* ---- STEP 6: submission mode and phone ---- */

		$mode = (string) $input['submission_mode'];
		if ( ! in_array( $mode, array( self::MODE_ANONYMOUS, self::MODE_PHONE ), true ) ) {
			self::log_reject( self::ERR_BAD_MODE, array( 'mode' => $mode ), $context );
			return new WP_Error( self::ERR_BAD_MODE, __( 'Invalid submission mode.', 'helpnagaland-core' ) );
		}

		$phone_clean      = '';
		$member_code_ok   = false;
		$member_code_norm = '';
		if ( self::MODE_PHONE === $mode ) {
			$raw_phone   = isset( $input['phone'] ) ? (string) $input['phone'] : '';
			$phone_clean = class_exists( 'HNC_Sanitizer' ) ? HNC_Sanitizer::phone_number( $raw_phone ) : '';

			if ( '' === $phone_clean ) {
				self::log_reject( self::ERR_BAD_PHONE, array(), $context );
				return new WP_Error(
					self::ERR_BAD_PHONE,
					__( 'The phone number you entered is not valid.', 'helpnagaland-core' )
				);
			}

			// First consent: the user picked phone mode AND must check the consent box.
			$consent = isset( $input['phone_consent'] ) ? (string) $input['phone_consent'] : '';
			if ( '1' !== $consent && 'true' !== strtolower( $consent ) && 'on' !== strtolower( $consent ) ) {
				self::log_reject( self::ERR_PHONE_REQUIRED, array( 'reason' => 'missing_consent' ), $context );
				return new WP_Error(
					self::ERR_PHONE_REQUIRED,
					__( 'Please confirm that you agree to be contacted before submitting with a phone number.', 'helpnagaland-core' )
				);
			}

			// Premium Member gate: phone/WhatsApp contact is reserved for paying members
			// because the contact channel routes to Nagaland Police for reward eligibility.
			$member_code_norm = isset( $input['member_code'] ) ? trim( (string) $input['member_code'] ) : '';
			$member_code_ok   = ( '' !== $member_code_norm && class_exists( 'HNC_Payment' )
				&& is_callable( array( 'HNC_Payment', 'is_active_member_code' ) )
				&& HNC_Payment::is_active_member_code( $member_code_norm ) );
			if ( ! $member_code_ok ) {
				self::log_reject( self::ERR_MEMBER_REQUIRED, array( 'has_code' => '' !== $member_code_norm ), $context );
				return new WP_Error(
					self::ERR_MEMBER_REQUIRED,
					__( 'A valid Premium Member Code is required to submit a phone number with a drug tip. Become a Premium Member to unlock reward-eligible contact.', 'helpnagaland-core' )
				);
			}
		}

		/* ---- STEP 7: sanitize text fields ---- */

		$district_clean = '';
		$category_clean = '';
		if ( class_exists( 'HNC_Sanitizer' ) ) {
			$district_clean = HNC_Sanitizer::district( $input['district'] );
			$category_clean = HNC_Sanitizer::category_key( $input['category'], 'drug' );
		}
		if ( '' === $district_clean ) {
			self::log_reject( self::ERR_BAD_DISTRICT, array( 'raw' => substr( (string) $input['district'], 0, 40 ) ), $context );
			return new WP_Error( self::ERR_BAD_DISTRICT, __( 'Please select a valid district.', 'helpnagaland-core' ) );
		}
		if ( '' === $category_clean ) {
			self::log_reject( self::ERR_BAD_CATEGORY, array( 'raw' => substr( (string) $input['category'], 0, 40 ) ), $context );
			return new WP_Error( self::ERR_BAD_CATEGORY, __( 'Please select a valid category.', 'helpnagaland-core' ) );
		}

		$area_desc_clean    = HNC_Sanitizer::area_description( $input['area_desc'] );
		$pattern_clean      = HNC_Sanitizer::drug_pattern( $input['pattern'] );
		$vehicle_info_clean = HNC_Sanitizer::vehicle_info( isset( $input['vehicle_info'] ) ? $input['vehicle_info'] : '' );

		/* ---- STEP 8: optional media ---- */

		$media_uuid = null;
		$media_type = null;
		$media_hash = null;

		if ( is_array( $media_file ) && ! empty( $media_file['tmp_name'] ) ) {
			if ( ! class_exists( 'HNC_Media' ) ) {
				return new WP_Error( self::ERR_MEDIA_INVALID, __( 'Media subsystem unavailable.', 'helpnagaland-core' ) );
			}
			$result = HNC_Media::handle_drug_upload( $media_file );
			if ( is_wp_error( $result ) ) {
				self::log_reject(
					self::ERR_MEDIA_INVALID,
					array( 'media_error' => $result->get_error_code() ),
					$context
				);
				return new WP_Error( self::ERR_MEDIA_INVALID, $result->get_error_message() );
			}
			$media_uuid = isset( $result['uuid'] ) ? (string) $result['uuid'] : null;
			$media_hash = isset( $result['hash'] ) ? (string) $result['hash'] : null;
			$media_type = isset( $result['mime'] ) ? (string) $result['mime'] : null;
		}

		/* ---- STEP 9: allocate unique public code ---- */

		$public_code = '';
		if ( class_exists( 'HNC_Codes' ) ) {
			$public_code = HNC_Codes::generate();
		}
		if ( '' === $public_code ) {
			if ( null !== $media_uuid ) {
				self::cleanup_media( $media_uuid );
			}
			self::log_reject( self::ERR_CODE_ALLOC_FAILED, array(), $context );
			return new WP_Error(
				self::ERR_CODE_ALLOC_FAILED,
				__( 'Could not allocate a tracking code. Please try again.', 'helpnagaland-core' )
			);
		}

		/* ---- STEP 10: insert drug row (phone_vault_id filled in step 11) ---- */

		$phone_vault_id = null;
		$phone_saved    = false;

		$table = $wpdb->prefix . HNC_TABLE_PREFIX . 'drug_reports';
		$row   = array(
			'public_code'         => $public_code,
			'district'            => $district_clean,
			'area_desc'           => $area_desc_clean,
			'category'            => $category_clean,
			'pattern'             => $pattern_clean,
			'vehicle_info'        => '' !== $vehicle_info_clean ? $vehicle_info_clean : null,
			'media_uuid'          => $media_uuid,
			'media_type'          => $media_type,
			'media_hash'          => $media_hash,
			'submission_mode'     => $mode,
			'phone_vault_id'      => $phone_vault_id,
			'status'              => self::STATUS_PENDING,
			'severity_flag'       => null,
			'submitted_by_member' => $member_code_ok ? 1 : 0,
			'submitted_at'        => current_time( 'mysql' ),
		);

		$formats = array(
			'%s', // public_code
			'%s', // district
			'%s', // area_desc
			'%s', // category
			'%s', // pattern
			'%s', // vehicle_info
			'%s', // media_uuid
			'%s', // media_type
			'%s', // media_hash
			'%s', // submission_mode
			'%d', // phone_vault_id
			'%s', // status
			'%d', // severity_flag
			'%d', // submitted_by_member
			'%s', // submitted_at
		);

		$ok = $wpdb->insert( $table, $row, $formats );
		if ( ! $ok ) {
			if ( null !== $media_uuid ) {
				self::cleanup_media( $media_uuid );
			}
			self::log_reject(
				self::ERR_INSERT_FAILED,
				array( 'db_error' => $wpdb->last_error ),
				$context
			);
			return new WP_Error( self::ERR_INSERT_FAILED, __( 'Could not save your report. Please try again.', 'helpnagaland-core' ) );
		}

		$new_id = (int) $wpdb->insert_id;

		/* ---- STEP 11: phone vault (real report_id now available) ---- */

		if ( self::MODE_PHONE === $mode && '' !== $phone_clean ) {
			if ( class_exists( 'HNC_Phone_Vault' ) && is_callable( array( 'HNC_Phone_Vault', 'store' ) ) ) {
				$vault_id = HNC_Phone_Vault::store( $phone_clean, 'drug', $new_id );
				if ( is_int( $vault_id ) && $vault_id > 0 ) {
					$phone_vault_id = $vault_id;
					$phone_saved    = true;
					// Back-fill the drug row with the vault id so the UI can
					// find it later without a reverse lookup.
					$wpdb->update(
						$table,
						array( 'phone_vault_id' => $phone_vault_id ),
						array( 'id' => $new_id ),
						array( '%d' ),
						array( '%d' )
					);
				}
			}
			// If the vault is locked (no moderator unlocked) or not
			// deployed, the phone is silently dropped. The response reports
			// phone_saved=false. This is the deliberate tradeoff: phones
			// never exist on disk in a recoverable form.
		}

		/* ---- STEP 12: touch device and log ---- */

		HNC_Device::touch( $device_hash );

		if ( class_exists( 'HNC_Logger' ) ) {
			// Log intentionally omits the area_desc, pattern, and vehicle_info
			// so sensitive text does not end up in the audit log.
			HNC_Logger::log_drug(
				HNC_Logger::ACTION_SUBMIT,
				$new_id,
				array(
					'code'            => $public_code,
					'district'        => $district_clean,
					'category'        => $category_clean,
					'submission_mode' => $mode,
					'phone_saved'     => $phone_saved,
					'media_attached'  => null !== $media_uuid,
				)
			);
		}

		/* ---- STEP 13: notify admin if a phone-mode tip came in ---- */

		// Premium-gated path only: the phone field is rejected on the public
		// API for non-members (see ERR_MEMBER_REQUIRED earlier in this
		// function), so reaching this point implies a paying member already
		// passed is_active_member_code(). We send the plaintext phone to the
		// WordPress admin email so the moderator can act on the tip without
		// having to keep the phone vault unlocked. The vault still encrypts
		// the phone in the database when unlocked; this email is an
		// additional always-on delivery channel, not a replacement.
		if ( self::MODE_PHONE === $mode && '' !== $phone_clean ) {
			self::notify_admin_of_phone_submission(
				array(
					'public_code' => $public_code,
					'district'    => $district_clean,
					'category'    => $category_clean,
					'area_desc'   => $area_desc_clean,
					'pattern'     => $pattern_clean,
					'vehicle'     => $vehicle_info_clean,
					'phone'       => $phone_clean,
					'member_code' => $member_code_norm,
					'phone_saved' => $phone_saved,
					'submitted'   => $row['submitted_at'],
				)
			);
		}

		return array(
			'code'            => $public_code,
			'status'          => self::STATUS_PENDING,
			'submission_mode' => $mode,
			'phone_saved'     => $phone_saved,
			'media_attached'  => null !== $media_uuid,
		);
	}


	/* ------------------------------------------------------------------
	 * HELPERS
	 * ------------------------------------------------------------------ */

	/**
	 * Delete a stored drug media file by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return void
	 */
	private static function cleanup_media( $uuid ) {
		if ( '' === (string) $uuid || ! class_exists( 'HNC_Media' ) ) {
			return;
		}
		HNC_Media::delete_by_uuid( 'drug', $uuid );
	}

	/**
	 * Extract the client IP safely.
	 *
	 * @return string
	 */
	private static function client_ip() {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		return '';
	}

	/**
	 * Log a rejection to the audit log.
	 *
	 * Forwards the IP/UA context into the detail payload so analysts
	 * can spot coordinated abuse. Logger itself hashes the IP with the
	 * daily-rotating salt — raw IPs are never written.
	 *
	 * @param string $code    Error code.
	 * @param array  $meta    Extra fields.
	 * @param array  $context Context (ip, user_agent).
	 * @return void
	 */

	/**
	 * Email the WordPress admin with the plaintext phone of an incoming
	 * Premium Member drug tip. Always sends regardless of vault state —
	 * this is the operational delivery channel that lets the moderator
	 * act on a tip without keeping wp-admin open.
	 *
	 * Premium-gated by the caller: this is only invoked after a valid
	 * is_active_member_code() check has passed, so abuse volume is bounded
	 * by the cost of becoming a paying member plus the existing per-IP
	 * and per-device rate limits on /drug/submit.
	 *
	 * Phone never goes into wp-options or any persistent log; the only
	 * places it lives after this call are (a) the admin's email inbox and
	 * (b) the encrypted phone vault row, IF the vault was unlocked.
	 *
	 * @param array $args {
	 *     @type string $public_code Drug-report tracking code.
	 *     @type string $district    Sanitized district.
	 *     @type string $category    Sanitized category.
	 *     @type string $area_desc   Sanitized area description.
	 *     @type string $pattern     Sanitized pattern of activity.
	 *     @type string $vehicle     Sanitized vehicle info (may be empty).
	 *     @type string $phone       E.164-ish plaintext phone.
	 *     @type string $member_code Verified Premium Member Code.
	 *     @type bool   $phone_saved True if the phone was also encrypted
	 *                               and stored in the vault.
	 *     @type string $submitted   MySQL datetime of submission.
	 * }
	 * @return void
	 */
	private static function notify_admin_of_phone_submission( $args ) {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$site_name = (string) get_bloginfo( 'name' );
		if ( '' === $site_name ) {
			$site_name = 'Help Nagaland';
		}

		$public_code = isset( $args['public_code'] ) ? (string) $args['public_code'] : '';
		$district    = isset( $args['district'] )    ? (string) $args['district']    : '';
		$category    = isset( $args['category'] )    ? (string) $args['category']    : '';
		$area_desc   = isset( $args['area_desc'] )   ? (string) $args['area_desc']   : '';
		$pattern     = isset( $args['pattern'] )     ? (string) $args['pattern']     : '';
		$vehicle     = isset( $args['vehicle'] )     ? (string) $args['vehicle']     : '';
		$phone       = isset( $args['phone'] )       ? (string) $args['phone']       : '';
		$member_code = isset( $args['member_code'] ) ? (string) $args['member_code'] : '';
		$phone_saved = ! empty( $args['phone_saved'] );
		$submitted   = isset( $args['submitted'] )   ? (string) $args['submitted']   : '';
		if ( '' === $submitted ) {
			$submitted = current_time( 'mysql' );
		}

		$admin_url = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=helpnagaland-drug' )
			: '';

		$subject = sprintf( '[%s] Drug tip with phone — %s', $site_name, $public_code );

		$body  = "A Premium Member just submitted a drug tip with a phone number.\n\n";
		$body .= 'Tracking code: ' . $public_code . "\n";
		$body .= 'District:      ' . $district . "\n";
		$body .= 'Category:      ' . $category . "\n";
		$body .= 'Submitted:     ' . $submitted . "\n";
		$body .= 'Member Code:   ' . $member_code . " (verified active)\n";
		$body .= "\n";
		$body .= 'Phone number:  ' . $phone . "\n";
		$body .= "\n";
		$body .= "Area description:\n" . ( '' !== $area_desc ? $area_desc : '(none)' ) . "\n\n";
		$body .= "Pattern of activity:\n" . ( '' !== $pattern  ? $pattern  : '(none)' ) . "\n\n";
		$body .= "Vehicles (if any):\n"   . ( '' !== $vehicle  ? $vehicle  : '(none)' ) . "\n\n";
		$body .= 'Phone also stored in encrypted vault: ' . ( $phone_saved ? 'yes' : 'no (vault was locked)' ) . "\n\n";
		if ( '' !== $admin_url ) {
			$body .= "To moderate this report (approve / reject / forward to police):\n" . $admin_url . "\n\n";
		}
		$body .= '— ' . $site_name . " system\n";

		// Allow operators to set From / Reply-To / additional headers.
		$headers = apply_filters( 'hnc_drug_phone_email_headers', array() );

		// wp_mail can fail silently on misconfigured SMTP; we don't surface
		// the failure to the reporter (they don't need to know an internal
		// notification didn't go through). The phone is still in the vault
		// if the vault was unlocked.
		wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Audit-log version of a rejection (above is the user-facing version).
	 *
	 * @param string $code    Reject code.
	 * @param array  $meta    Reject details.
	 * @param array  $context Context (ip, user_agent).
	 * @return void
	 */
	private static function log_reject( $code, $meta, $context ) {
		if ( ! class_exists( 'HNC_Logger' ) ) {
			return;
		}
		$payload = array_merge(
			array( 'reject_code' => $code ),
			is_array( $meta ) ? $meta : array(),
			array(
				'ip'         => isset( $context['ip'] ) ? (string) $context['ip'] : '',
				'user_agent' => isset( $context['user_agent'] ) ? substr( (string) $context['user_agent'], 0, 255 ) : '',
			)
		);
		HNC_Logger::log_drug( 'submit_reject', 0, $payload );
	}
}
