<?php
/**
 * Razorpay donation payment processor.
 *
 * Single-file payment class for Help Nagaland Core. Accepts donations
 * through Razorpay in five modes: one-time, weekly, monthly, twice-yearly,
 * and yearly. Owns three database tables (donations, payment events,
 * customers), exposes REST endpoints under hnc/v1/payment/*, verifies
 * Razorpay signatures server-side, processes Razorpay webhooks
 * idempotently, and runs a daily reconciliation cron to repair any
 * donations whose webhooks were lost.
 *
 * ============================================================
 * INTEGRATION PLAYBOOK
 * ============================================================
 *
 * This class self-bootstraps via HNC_Payment::init(). Wire it up in three
 * places, mirroring the existing pattern used for HNC_Alcohol_Submit and
 * HNC_Drug_Submit.
 *
 * STEP 1 — Bootstrap (helpnagaland-core.php)
 * ------------------------------------------
 * Inside the plugins_loaded callback, somewhere after the Phase 6 REST
 * router block, add:
 *
 *     if ( class_exists( 'HNC_Payment' ) ) {
 *         HNC_Payment::init();
 *     }
 *
 * STEP 2 — Schema (includes/class-hnc-schema.php)
 * -----------------------------------------------
 * Three CREATE TABLE blocks need to be added. The exact paste-ready text
 * is at the end of this file in the comment block titled
 * "PASTE-READY SCHEMA HOOKS FOR class-hnc-schema.php". After pasting:
 *
 *   - Add three table-name helpers: table_donations(), table_payment_events(),
 *     table_payment_customers().
 *   - Add three create_*() private methods.
 *   - Add the three new tables to all_tables().
 *   - Add the three new statements to install().
 *   - Bump HNC_DB_VERSION in helpnagaland-core.php to 1.2.0.
 *
 * STEP 3 — Cron registration
 * --------------------------
 * Two scheduled events power the reconciliation and PII-purge crons. The
 * paste-ready snippet at the bottom of this file (titled "PASTE-READY CRON
 * REGISTRATION") wires them up. In short:
 *
 *     wp_schedule_event( time() + 600,  'hourly', HNC_Payment::HOOK_PAYMENT_RECONCILE );
 *     wp_schedule_event( time() + 3600, 'daily',  HNC_Payment::HOOK_PAYMENT_PURGE_PII );
 *
 * The action callbacks themselves are auto-registered inside HNC_Payment::init().
 *
 * STEP 4 — Configure Razorpay credentials
 * ---------------------------------------
 * Two storage paths are supported. Constants in wp-config.php win over
 * options if both are set.
 *
 *   wp-config.php (recommended for production):
 *     define( 'HNC_RAZORPAY_LIVE_KEY_ID',         'rzp_live_xxx' );
 *     define( 'HNC_RAZORPAY_LIVE_KEY_SECRET',     'xxxxxxxxxxxx' );
 *     define( 'HNC_RAZORPAY_LIVE_WEBHOOK_SECRET', 'xxxxxxxxxxxx' );
 *     define( 'HNC_RAZORPAY_TEST_KEY_ID',         'rzp_test_xxx' );
 *     define( 'HNC_RAZORPAY_TEST_KEY_SECRET',     'xxxxxxxxxxxx' );
 *     define( 'HNC_RAZORPAY_TEST_WEBHOOK_SECRET', 'xxxxxxxxxxxx' );
 *
 *   wp-admin (acceptable for staging):
 *     hnc_payment_razorpay_live_key_id
 *     hnc_payment_razorpay_live_key_secret
 *     hnc_payment_razorpay_live_webhook_secret
 *     hnc_payment_razorpay_test_key_id
 *     hnc_payment_razorpay_test_key_secret
 *     hnc_payment_razorpay_test_webhook_secret
 *
 *   Mode toggle (option only, no constant — operator should be able to
 *   switch this without editing wp-config.php):
 *     hnc_payment_live_mode  (1 = live, 0 = test, default 0)
 *
 * STEP 5 — Configure the Razorpay webhook
 * ---------------------------------------
 * In the Razorpay dashboard, set the webhook URL to:
 *
 *     https://helpnagaland.com/wp-json/hnc/v1/payment/webhook
 *
 * Subscribe to ALL of the following events:
 *
 *   payment.authorized, payment.captured, payment.failed,
 *   order.paid,
 *   refund.created, refund.processed, refund.failed,
 *   subscription.authenticated, subscription.activated,
 *   subscription.charged, subscription.pending,
 *   subscription.halted, subscription.cancelled,
 *   subscription.completed, subscription.paused,
 *   subscription.resumed, subscription.updated.
 *
 * Set the webhook secret to a strong random value (at least 32 chars)
 * and store it as HNC_RAZORPAY_LIVE_WEBHOOK_SECRET (and the test variant
 * for the test webhook).
 *
 * ============================================================
 * REST API SURFACE
 * ============================================================
 *
 * All routes are under HNC_API_NAMESPACE = "hnc/v1".
 *
 *   PUBLIC (rate-limited per-IP, killswitch-aware):
 *     GET  /payment/config
 *     POST /payment/order
 *     POST /payment/subscription
 *     POST /payment/verify
 *
 *   WEBHOOK (rate-limited per-IP, but BYPASSES killswitch):
 *     POST /payment/webhook
 *
 *   ADMIN (capability-gated, rate-limited per-user, audit-logged):
 *     POST /payment/admin/refund
 *     POST /payment/admin/cancel-subscription
 *     GET  /payment/admin/donation
 *
 * ============================================================
 * EXTENSIBILITY
 * ============================================================
 *
 * Filters (return value modifies behaviour):
 *
 *   hnc_payment_currency               (string)  — override "INR"
 *   hnc_payment_min_amount_paise       (int)     — override 10000  (₹100)
 *   hnc_payment_max_amount_paise       (int)     — override 50000000 (₹5L)
 *   hnc_payment_allowed_frequencies    (array)   — restrict frequencies
 *   hnc_payment_intent_token_ttl       (int)     — override 600s
 *   hnc_payment_request_timeout        (int)     — override HTTP timeout (15s)
 *   hnc_payment_pii_retention_days     (int)     — override 365
 *   hnc_payment_log_detail             (array)   — last-mile redaction hook
 *
 * Actions (fired for downstream listeners):
 *
 *   hnc_payment_donation_recorded         ($donation_id, $row)
 *   hnc_payment_donation_authorized       ($donation_id, $row)
 *   hnc_payment_donation_captured         ($donation_id, $row)
 *   hnc_payment_donation_failed           ($donation_id, $row, $reason)
 *   hnc_payment_subscription_authenticated($donation_id, $row)
 *   hnc_payment_subscription_activated    ($donation_id, $row)
 *   hnc_payment_subscription_charged      ($donation_id, $charge_event)
 *   hnc_payment_subscription_cancelled    ($donation_id, $row, $by_admin)
 *   hnc_payment_refund_processed          ($donation_id, $row, $refund_event)
 *
 * Cron hooks (fire on schedule):
 *
 *   HNC_Payment::HOOK_PAYMENT_RECONCILE   hourly  — sync stuck donations
 *   HNC_Payment::HOOK_PAYMENT_PURGE_PII   daily   — null donor PII past retention
 *
 * @package HelpNagalandCore
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HNC_Payment
 */
final class HNC_Payment {


	/* ------------------------------------------------------------------
	 * VERSION
	 * ------------------------------------------------------------------ */

	/**
	 * Schema version for this class's tables. The maybe_upgrade() runner
	 * compares this against the option `hnc_payment_schema_version` and
	 * runs forward-only migrations until they match.
	 *
	 * Bump this whenever a migration is added to migrations_table().
	 */
	const SCHEMA_VERSION = 1;


	/* ------------------------------------------------------------------
	 * STATUS, TYPE, FREQUENCY, MODE
	 * ------------------------------------------------------------------ */

	// Donation row status. Lifecycle for one-time:
	//   created -> authorized -> captured (success)
	//   created -> failed
	//   captured -> refunded (full) | partially_refunded
	// Lifecycle for subscription:
	//   created -> authenticated -> active -> [charged events]
	//   active -> halted | paused | cancelled | completed
	const STATUS_CREATED            = 'created';
	const STATUS_AUTHORIZED         = 'authorized';
	const STATUS_CAPTURED           = 'captured';
	const STATUS_FAILED             = 'failed';
	const STATUS_AUTHENTICATED      = 'authenticated';
	const STATUS_ACTIVE             = 'active';
	const STATUS_PENDING            = 'pending';
	const STATUS_HALTED             = 'halted';
	const STATUS_PAUSED             = 'paused';
	const STATUS_RESUMED            = 'resumed';
	const STATUS_CANCELLED          = 'cancelled';
	const STATUS_COMPLETED          = 'completed';
	const STATUS_REFUNDED           = 'refunded';
	const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

	// Donation type.
	const TYPE_ONETIME      = 'onetime';
	const TYPE_SUBSCRIPTION = 'subscription';

	// Frequency (subscriptions only; one-time donations store frequency = 'once').
	const FREQ_ONCE        = 'once';
	const FREQ_WEEKLY      = 'weekly';
	const FREQ_MONTHLY     = 'monthly';
	const FREQ_HALFYEARLY  = 'halfyearly';
	const FREQ_YEARLY      = 'yearly';

	// Provider currently supported. Reserved for future expansion.
	const PROVIDER_RAZORPAY = 'razorpay';


	/* ------------------------------------------------------------------
	 * AMOUNT BOUNDS (in paise — money is stored as paise integers ALWAYS)
	 * ------------------------------------------------------------------
	 *
	 * Razorpay's minimum charge is ₹1 (100 paise) but we set our floor
	 * higher to discourage spam orders. Operator can adjust via the
	 * hnc_payment_min_amount_paise / hnc_payment_max_amount_paise filters.
	 */
	const DEFAULT_MIN_AMOUNT_PAISE = 9900;      // ₹99
	const DEFAULT_MAX_AMOUNT_PAISE = 50000000;  // ₹5,00,000

	/** Allowed currencies. Razorpay primarily supports INR for domestic. */
	const ALLOWED_CURRENCIES = array( 'INR' );


	/* ------------------------------------------------------------------
	 * INTENT TOKEN
	 * ------------------------------------------------------------------ */

	/** Default lifetime of an intent token in seconds. Filterable. */
	const INTENT_TOKEN_TTL = 600;


	/* ------------------------------------------------------------------
	 * RATE-LIMIT ACTION KEYS
	 * ------------------------------------------------------------------ */

	const RL_KEY_CONFIG    = 'payment_config';
	const RL_KEY_ORDER     = 'payment_order';
	const RL_KEY_SUBSCRIBE = 'payment_subscribe';
	const RL_KEY_VERIFY    = 'payment_verify';
	const RL_KEY_WEBHOOK   = 'payment_webhook';
	const RL_KEY_ADMIN     = 'payment_admin';


	/* ------------------------------------------------------------------
	 * AUDIT-LOG ACTION CONSTANTS
	 * ------------------------------------------------------------------
	 *
	 * Passed as the $action argument to HNC_Logger::log_system /
	 * log_security. Keeping them here (not on HNC_Logger) so this class
	 * remains drop-in without modifying the logger.
	 */
	const ACTION_ORDER_CREATED        = 'payment_order_created';
	const ACTION_SUBSCRIPTION_CREATED = 'payment_subscription_created';
	const ACTION_VERIFY_OK            = 'payment_verify_ok';
	const ACTION_VERIFY_FAIL          = 'payment_verify_fail';
	const ACTION_WEBHOOK_RECEIVED     = 'payment_webhook_received';
	const ACTION_WEBHOOK_SIG_FAIL     = 'payment_webhook_sig_fail';
	const ACTION_WEBHOOK_DUPLICATE    = 'payment_webhook_duplicate';
	const ACTION_PAYMENT_AUTHORIZED   = 'payment_authorized';
	const ACTION_PAYMENT_CAPTURED     = 'payment_captured';
	const ACTION_PAYMENT_FAILED       = 'payment_failed';
	const ACTION_REFUND_CREATED       = 'payment_refund_created';
	const ACTION_REFUND_PROCESSED     = 'payment_refund_processed';
	const ACTION_REFUND_FAILED        = 'payment_refund_failed';
	const ACTION_SUB_AUTHENTICATED    = 'payment_sub_authenticated';
	const ACTION_SUB_ACTIVATED        = 'payment_sub_activated';
	const ACTION_SUB_CHARGED          = 'payment_sub_charged';
	const ACTION_SUB_HALTED           = 'payment_sub_halted';
	const ACTION_SUB_PAUSED           = 'payment_sub_paused';
	const ACTION_SUB_RESUMED          = 'payment_sub_resumed';
	const ACTION_SUB_CANCELLED        = 'payment_sub_cancelled';
	const ACTION_SUB_COMPLETED        = 'payment_sub_completed';
	const ACTION_SUB_UPDATED          = 'payment_sub_updated';
	const ACTION_ADMIN_REFUND         = 'payment_admin_refund';
	const ACTION_ADMIN_CANCEL_SUB     = 'payment_admin_cancel_sub';
	const ACTION_RECONCILE_RUN        = 'payment_reconcile_run';
	const ACTION_PURGE_PII_RUN        = 'payment_purge_pii_run';
	const ACTION_SCHEMA_UPGRADE       = 'payment_schema_upgrade';

	// Cron hook names — the operator wires these into HNC_Cron (or wp_schedule_event)
	// using the paste-ready snippet at the bottom of this file.
	const HOOK_PAYMENT_RECONCILE = 'hnc_payment_cron_reconcile';
	const HOOK_PAYMENT_PURGE_PII = 'hnc_payment_cron_purge_pii';

	// Reconciliation tuning — kept conservative so we don't hammer Razorpay.
	const RECONCILE_BATCH_SIZE   = 50;   // donations per cron tick
	const RECONCILE_AGE_HOURS    = 1;    // ignore donations younger than this
	const RECONCILE_MAX_AGE_DAYS = 30;   // ignore donations older than this
	const PURGE_PII_BATCH_SIZE   = 200;  // donations per cron tick


	/* ==================================================================
	 * BOOTSTRAP
	 * ==================================================================
	 *
	 * init() is called once per request from the main plugin bootstrap.
	 * It registers REST routes and runs the schema upgrade check. It
	 * MUST be safe to call on every page load (idempotent).
	 */

	/**
	 * Wire the class into WordPress. Idempotent — if init() has already
	 * run in this request, subsequent calls are no-ops.
	 *
	 * @return void
	 */
	public static function init() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		// Schema migration check. Cheap when no upgrade is needed
		// (single get_option compare).
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 9 );

		// Route registration.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Cron handlers. The actual wp_schedule_event() / HNC_Cron registration
		// lives in the main bootstrap (see paste-ready snippet at bottom of
		// this file) — here we just register the action callbacks so when the
		// scheduled event fires, our methods run.
		add_action( self::HOOK_PAYMENT_RECONCILE, array( __CLASS__, 'cron_reconcile' ) );
		add_action( self::HOOK_PAYMENT_PURGE_PII, array( __CLASS__, 'cron_purge_pii' ) );
	}


	/* ==================================================================
	 * REST ROUTE REGISTRATION
	 * ==================================================================
	 *
	 * The route surface is final. All handlers are fully implemented.
	 */

	/**
	 * Register every REST route exposed by this class.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {

		// ----- Public: GET /payment/config -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/config',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_config' ),
			)
		);

		// ----- Public: POST /payment/order (one-time) -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/order',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_create_order' ),
			)
		);

		// ----- Public: POST /payment/subscription (recurring) -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/subscription',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_create_subscription' ),
			)
		);

		// ----- Public: POST /payment/verify -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/verify',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_verify' ),
			)
		);

		// ----- Webhook: POST /payment/webhook -----
		// Bypasses killswitch on purpose — Razorpay would otherwise retry
		// for hours and pollute their dashboard.
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_webhook' ),
			)
		);

		// ----- Admin: POST /payment/admin/refund -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/admin/refund',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_admin_refund' ),
			)
		);

		// ----- Admin: POST /payment/admin/cancel-subscription -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/admin/cancel-subscription',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_admin_cancel_subscription' ),
			)
		);

		// ----- Admin: GET /payment/admin/donation -----
		register_rest_route(
			HNC_API_NAMESPACE,
			'/payment/admin/donation',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_admin_get_donation' ),
			)
		);
	}

	/**
	 * Permission callback for admin routes.
	 * Reuses the standard WP capability used elsewhere for sensitive ops.
	 *
	 * @return bool
	 */
	public static function admin_permission_check() {
		// Match HNC_Killswitch's capability fallback pattern: prefer the
		// custom capability if HNC_Roles defines one, fall back to
		// manage_options.
		if ( class_exists( 'HNC_Roles' ) && defined( 'HNC_Roles::CAP_OPERATE_KILLSWITCH' ) ) {
			// We deliberately do NOT reuse CAP_OPERATE_KILLSWITCH — payment
			// admin ops are a different responsibility. For now we lock
			// down to manage_options and let the operator add a custom
			// cap later if desired.
			return current_user_can( 'manage_options' );
		}
		return current_user_can( 'manage_options' );
	}


	/* ==================================================================
	 * REST HANDLERS
	 * ==================================================================
	 *
	 * All routes (public, webhook, admin) are fully implemented.
	 */

	/**
	 * GET /payment/config — returns public config + a short-lived intent token.
	 *
	 * The intent token must be echoed back on /payment/order and
	 * /payment/subscription. This protects those endpoints from being
	 * scripted directly without first hitting /payment/config.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_config( $request ) {
		// 1) Killswitch — frontend gets a clean 503 to render a maintenance UI.
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		// 2) Rate limit (loose — config is cheap and frontend may refresh).
		if ( class_exists( 'HNC_Api_Ratelimit' ) ) {
			$rl = HNC_Api_Ratelimit::public_ip( self::RL_KEY_CONFIG, 60, 60 );
			$rl_response = self::ratelimit_to_response( $rl );
			if ( $rl_response ) {
				return $rl_response;
			}
		}

		// 3) Bail with a non-200 if Razorpay isn't configured for the active mode.
		// We do NOT silently return empty config — the frontend should display a
		// clear "donations unavailable" state.
		if ( ! self::is_configured() ) {
			return self::error_response(
				'hnc_payment_not_configured',
				__( 'Premium Membership is temporarily unavailable.', 'helpnagaland-core' ),
				503
			);
		}

		// 4) Issue a fresh intent token.
		$token_data = self::issue_intent_token();

		return new WP_REST_Response(
			array(
				'ok'                      => true,
				'razorpay_key_id'         => self::razorpay_key_id(),
				'live_mode'               => self::is_live_mode(),
				'currency'                => self::currency(),
				'min_amount_paise'        => self::min_amount_paise(),
				'max_amount_paise'        => self::max_amount_paise(),
				'allowed_frequencies'     => self::allowed_frequencies(),
				'intent_token'            => $token_data['token'],
				'intent_token_expires_in' => $token_data['expires_in'],
				'name'                    => __( 'Help Nagaland — Premium Membership', 'helpnagaland-core' ),
				'description'             => __( 'Premium Membership – helpnagaland.com', 'helpnagaland-core' ),
			),
			200
		);
	}

	/**
	 * POST /payment/order — create a one-time donation order.
	 *
	 * Body (JSON):
	 *   intent_token      (string, REQUIRED) — from /payment/config
	 *   amount            (int|string, REQUIRED) — paise by default
	 *   amount_unit       ('paise'|'rupees', default 'paise')
	 *   donor_name        (string, optional)
	 *   donor_email       (string, optional but recommended for receipts)
	 *   donor_phone       (string, optional)
	 *   donor_anonymous   (bool, default false)
	 *   notes             (string, optional, max 500 chars)
	 *   idempotency_key   (string, optional — auto-generated if absent)
	 *
	 * Response (success):
	 *   { ok, donation_id, public_code, razorpay_order_id, razorpay_key_id,
	 *     amount_paise, currency, name, description, prefill, notes }
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_create_order( $request ) {
		// Killswitch.
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		// Rate limit — tight on order creation.
		if ( class_exists( 'HNC_Api_Ratelimit' ) ) {
			$rl = HNC_Api_Ratelimit::public_ip( self::RL_KEY_ORDER, 20, 60 );
			$rl_response = self::ratelimit_to_response( $rl );
			if ( $rl_response ) {
				return $rl_response;
			}
		}

		if ( ! self::is_configured() ) {
			return self::error_response(
				'hnc_payment_not_configured',
				__( 'Premium Membership is temporarily unavailable.', 'helpnagaland-core' ),
				503
			);
		}

		$params = self::parse_request_json( $request );

		// Intent token check.
		$intent = isset( $params['intent_token'] ) ? (string) $params['intent_token'] : '';
		if ( ! self::verify_intent_token( $intent ) ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					'payment_intent_invalid',
					array(
						'route'  => 'order',
						'reason' => 'token_invalid_or_expired',
					)
				);
			}
			return self::error_response(
				'hnc_invalid_intent',
				__( 'Your session has expired. Please refresh the page and try again.', 'helpnagaland-core' ),
				400
			);
		}

		// Amount.
		$unit = isset( $params['amount_unit'] ) ? (string) $params['amount_unit'] : 'paise';
		$unit = ( 'rupees' === $unit ) ? 'rupees' : 'paise';
		$raw_amount = isset( $params['amount'] ) ? $params['amount'] : '';
		$amount_paise = self::sanitize_amount_to_paise( $raw_amount, $unit );
		if ( ! self::is_valid_amount( $amount_paise ) ) {
			return self::error_response(
				'hnc_invalid_amount',
				sprintf(
					/* translators: 1: minimum rupees, 2: maximum rupees */
					__( 'Amount must be between ₹%1$d and ₹%2$d.', 'helpnagaland-core' ),
					(int) ( self::min_amount_paise() / 100 ),
					(int) ( self::max_amount_paise() / 100 )
				),
				400
			);
		}

		// Donor fields. Email is OPTIONAL for one-time donations.
		$anonymous   = self::sanitize_anonymous_flag( isset( $params['donor_anonymous'] ) ? $params['donor_anonymous'] : false );
		$donor_email = self::sanitize_donor_email( isset( $params['donor_email'] ) ? $params['donor_email'] : '' );
		// If email was provided but is invalid, reject — we won't silently drop it.
		if ( '' !== ( isset( $params['donor_email'] ) ? trim( (string) $params['donor_email'] ) : '' ) && '' === $donor_email ) {
			return self::error_response(
				'hnc_invalid_email',
				__( 'The email address looks invalid. Please check and try again.', 'helpnagaland-core' ),
				400
			);
		}
		$donor_name  = $anonymous ? '' : self::sanitize_donor_name( isset( $params['donor_name'] ) ? $params['donor_name'] : '' );
		$donor_phone = self::sanitize_phone_e164( isset( $params['donor_phone'] ) ? $params['donor_phone'] : '' );
		$notes       = self::sanitize_notes( isset( $params['notes'] ) ? $params['notes'] : '' );

		// Idempotency.
		$idempotency_key = self::compute_idempotency_key(
			isset( $params['idempotency_key'] ) ? $params['idempotency_key'] : '',
			$amount_paise,
			self::FREQ_ONCE,
			$donor_email
		);

		// Replay / resume / race-loss handling.
		//
		// Three cases when an existing row with this idempotency_key is found:
		//
		//   A) Has provider_order_id  -> Razorpay already has the order. Return
		//                                its current state. Idempotent success.
		//
		//   B) No provider_order_id   -> Insert succeeded on a previous attempt
		//                                but the Razorpay call did not. Reuse
		//                                the row instead of inserting a new one
		//                                (which would fail on the UNIQUE key).
		//                                We refresh donor fields with the new
		//                                input so the donor can correct typos.
		//
		//   C) Concurrent race        -> Two simultaneous requests with the
		//                                same idempotency_key. The DB UNIQUE
		//                                constraint serializes; the loser's
		//                                INSERT returns false. We re-find and
		//                                fall through to case A or B.
		$existing = null;
		if ( '' !== $idempotency_key ) {
			$existing = self::find_donation_by( 'idempotency_key', $idempotency_key );
			if ( $existing && ! empty( $existing['provider_order_id'] ) ) {
				// Case A — already at Razorpay. Return as-is.
				return self::build_order_response( $existing );
			}
		}

		// Optionally register donor at Razorpay (for receipts + customer continuity).
		$customer = null;
		if ( '' !== $donor_email ) {
			$customer = self::ensure_customer( $donor_email, $donor_name, $donor_phone );
		}

		$donation_fields = array(
			'type'                 => self::TYPE_ONETIME,
			'frequency'            => self::FREQ_ONCE,
			'status'               => self::STATUS_CREATED,
			'amount_paise'         => $amount_paise,
			'currency'             => self::currency(),
			'donor_name'           => $anonymous || '' === $donor_name ? null : $donor_name,
			'donor_email'          => '' === $donor_email ? null : $donor_email,
			'donor_email_hash'     => '' === $donor_email ? null : self::hash_email( $donor_email ),
			'donor_phone_e164'     => '' === $donor_phone ? null : $donor_phone,
			'donor_anonymous'      => $anonymous,
			'customer_id'          => $customer ? (int) $customer['local_id'] : null,
			'provider_customer_id' => $customer ? $customer['provider_customer_id'] : null,
			'ip_hash'              => self::hash_ip( self::client_ip() ),
			'user_agent'           => self::request_user_agent( $request ),
			'idempotency_key'      => '' === $idempotency_key ? null : $idempotency_key,
			'notes'                => '' === $notes ? null : $notes,
		);

		$donation_id = 0;
		$donation    = null;

		if ( $existing ) {
			// Case B — resume the half-created row. Update donor fields with
			// the latest input (in case the donor corrected typos on retry),
			// then proceed to the Razorpay call.
			$donation_id = (int) $existing['id'];
			$resume_changes = array_intersect_key(
				$donation_fields,
				array_flip( array(
					'donor_name', 'donor_email', 'donor_email_hash',
					'donor_phone_e164', 'donor_anonymous', 'customer_id',
					'provider_customer_id', 'notes',
				) )
			);
			self::update_donation( $donation_id, $resume_changes );
			$donation = self::get_donation( $donation_id );
		} else {
			// Fresh path.
			$donation_id = self::insert_donation( $donation_fields );

			// Case C — concurrent race. INSERT failed because another
			// request just won the UNIQUE-key race. Re-find and recurse
			// the case-A / case-B logic.
			if ( $donation_id <= 0 && '' !== $idempotency_key ) {
				$racer = self::find_donation_by( 'idempotency_key', $idempotency_key );
				if ( $racer ) {
					if ( ! empty( $racer['provider_order_id'] ) ) {
						return self::build_order_response( $racer );
					}
					// Racer is also half-created. Both requests racing to
					// resume — return a "still processing" 202 so the donor
					// retries. The reconciliation cron will eventually
					// complete one of them.
					return self::error_response(
						'hnc_donation_in_progress',
						__( 'Your membership payment is being processed. Please wait a moment and try again.', 'helpnagaland-core' ),
						202
					);
				}
			}
			if ( $donation_id <= 0 ) {
				return self::error_response(
					'hnc_donation_create_failed',
					__( 'Could not create membership. Please try again.', 'helpnagaland-core' ),
					500
				);
			}
			$donation = self::get_donation( $donation_id );
		}

		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_create_failed',
				__( 'Could not load membership.', 'helpnagaland-core' ),
				500
			);
		}

		// Create Razorpay order.
		$order_payload = array(
			'amount'          => $amount_paise,
			'currency'        => self::currency(),
			'receipt'         => $donation['public_code'],
			'payment_capture' => 1, // auto-capture on success
			'notes'           => array(
				'donation_id' => (string) $donation_id,
				'public_code' => $donation['public_code'],
				'platform'    => 'helpnagaland.com',
			),
		);
		if ( $customer ) {
			$order_payload['notes']['provider_customer_id'] = $customer['provider_customer_id'];
		}

		$rzp_idempotency = 'order_' . $donation['public_code'];
		$result = self::razorpay_post( '/orders', $order_payload, $rzp_idempotency );

		if ( ! $result['ok'] ) {
			self::update_donation( $donation_id, array( 'status' => self::STATUS_FAILED ) );
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					'payment_order_create_failed',
					array(
						'donation_id' => $donation_id,
						'error_code'  => $result['error_code'],
						'http_code'   => $result['http_code'],
					)
				);
			}
			do_action( 'hnc_payment_donation_failed', $donation_id, $donation, (string) $result['error_code'] );
			return self::error_response(
				'hnc_provider_error',
				__( 'Could not create payment order. Please try again.', 'helpnagaland-core' ),
				502,
				array( 'error_code' => $result['error_code'] )
			);
		}

		$razorpay_order_id = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		if ( '' === $razorpay_order_id ) {
			self::update_donation( $donation_id, array( 'status' => self::STATUS_FAILED ) );
			return self::error_response(
				'hnc_provider_error',
				__( 'Payment provider returned an invalid response.', 'helpnagaland-core' ),
				502
			);
		}

		self::update_donation( $donation_id, array( 'provider_order_id' => $razorpay_order_id ) );

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_ORDER_CREATED,
				array(
					'donation_id'  => $donation_id,
					'public_code'  => $donation['public_code'],
					'amount_paise' => $amount_paise,
					'frequency'    => self::FREQ_ONCE,
					'masked_email' => self::mask_email( $donor_email ),
				)
			);
		}

		do_action( 'hnc_payment_donation_recorded', $donation_id, self::get_donation( $donation_id ) );

		// Return the latest snapshot (after the order_id update).
		return self::build_order_response( self::get_donation( $donation_id ) );
	}

	/**
	 * POST /payment/subscription — create a recurring donation.
	 *
	 * Body (JSON):
	 *   intent_token      (string, REQUIRED)
	 *   amount            (int|string, REQUIRED) — paise per charge
	 *   amount_unit       ('paise'|'rupees', default 'paise')
	 *   frequency         (string, REQUIRED) — weekly|monthly|halfyearly|yearly
	 *   donor_name        (string, optional)
	 *   donor_email       (string, REQUIRED for subscriptions)
	 *   donor_phone       (string, optional)
	 *   donor_anonymous   (bool, default false) — hides name in public/admin views
	 *   notes             (string, optional)
	 *   idempotency_key   (string, optional)
	 *
	 * Note: the donor_anonymous flag does NOT skip email collection — Razorpay
	 * requires a customer object for recurring billing. The flag only controls
	 * whether the donor's name appears in transparency / admin reports.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_create_subscription( $request ) {
		// Killswitch.
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		// Rate limit (very tight on subscription creation).
		if ( class_exists( 'HNC_Api_Ratelimit' ) ) {
			$rl = HNC_Api_Ratelimit::public_ip( self::RL_KEY_SUBSCRIBE, 10, 60 );
			$rl_response = self::ratelimit_to_response( $rl );
			if ( $rl_response ) {
				return $rl_response;
			}
		}

		if ( ! self::is_configured() ) {
			return self::error_response(
				'hnc_payment_not_configured',
				__( 'Premium Membership is temporarily unavailable.', 'helpnagaland-core' ),
				503
			);
		}

		$params = self::parse_request_json( $request );

		$intent = isset( $params['intent_token'] ) ? (string) $params['intent_token'] : '';
		if ( ! self::verify_intent_token( $intent ) ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					'payment_intent_invalid',
					array(
						'route'  => 'subscription',
						'reason' => 'token_invalid_or_expired',
					)
				);
			}
			return self::error_response(
				'hnc_invalid_intent',
				__( 'Your session has expired. Please refresh the page and try again.', 'helpnagaland-core' ),
				400
			);
		}

		// Frequency — must be a recurring frequency, not 'once'.
		$frequency = self::sanitize_frequency( isset( $params['frequency'] ) ? $params['frequency'] : '' );
		if ( '' === $frequency || self::FREQ_ONCE === $frequency ) {
			return self::error_response(
				'hnc_invalid_frequency',
				__( 'Please choose a recurring plan: monthly or yearly.', 'helpnagaland-core' ),
				400
			);
		}

		// Amount.
		$unit = isset( $params['amount_unit'] ) ? (string) $params['amount_unit'] : 'paise';
		$unit = ( 'rupees' === $unit ) ? 'rupees' : 'paise';
		$amount_paise = self::sanitize_amount_to_paise( isset( $params['amount'] ) ? $params['amount'] : '', $unit );
		if ( ! self::is_valid_amount( $amount_paise ) ) {
			return self::error_response(
				'hnc_invalid_amount',
				sprintf(
					/* translators: 1: minimum rupees, 2: maximum rupees */
					__( 'Amount must be between ₹%1$d and ₹%2$d.', 'helpnagaland-core' ),
					(int) ( self::min_amount_paise() / 100 ),
					(int) ( self::max_amount_paise() / 100 )
				),
				400
			);
		}

		// Strict plan-price enforcement: each frequency has exactly one price
		// (configured in wp-admin → Membership → Settings). Reject any amount
		// that does not match. Stops API tampering and keeps the catalog as
		// "fixed-price service" rather than "any amount = donation".
		$expected_paise = self::expected_plan_price_paise( $frequency );
		if ( $expected_paise > 0 && $amount_paise !== $expected_paise ) {
			return self::error_response(
				'hnc_amount_does_not_match_plan',
				sprintf(
					/* translators: 1: frequency, 2: expected rupees */
					__( 'The %1$s Premium Membership plan is ₹%2$d. Please refresh the page and try again.', 'helpnagaland-core' ),
					$frequency,
					(int) ( $expected_paise / 100 )
				),
				400
			);
		}

		// Email is REQUIRED for subscriptions.
		$donor_email = self::sanitize_donor_email( isset( $params['donor_email'] ) ? $params['donor_email'] : '' );
		if ( '' === $donor_email ) {
			return self::error_response(
				'hnc_email_required',
				__( 'A valid email address is required for recurring membership.', 'helpnagaland-core' ),
				400
			);
		}

		$anonymous   = self::sanitize_anonymous_flag( isset( $params['donor_anonymous'] ) ? $params['donor_anonymous'] : false );
		$donor_name  = $anonymous ? '' : self::sanitize_donor_name( isset( $params['donor_name'] ) ? $params['donor_name'] : '' );
		$donor_phone = self::sanitize_phone_e164( isset( $params['donor_phone'] ) ? $params['donor_phone'] : '' );
		$notes       = self::sanitize_notes( isset( $params['notes'] ) ? $params['notes'] : '' );

		// Idempotency.
		$idempotency_key = self::compute_idempotency_key(
			isset( $params['idempotency_key'] ) ? $params['idempotency_key'] : '',
			$amount_paise,
			$frequency,
			$donor_email
		);

		// Replay / resume / race-loss handling. See rest_create_order for the
		// three-case explanation; identical pattern here, but checking
		// provider_subscription_id instead of provider_order_id.
		$existing = null;
		if ( '' !== $idempotency_key ) {
			$existing = self::find_donation_by( 'idempotency_key', $idempotency_key );
			if ( $existing && ! empty( $existing['provider_subscription_id'] ) ) {
				return self::build_subscription_response( $existing );
			}
		}

		// Customer is required for subscriptions (Razorpay subscription needs one).
		$customer = self::ensure_customer( $donor_email, $donor_name, $donor_phone );
		if ( ! $customer ) {
			return self::error_response(
				'hnc_customer_failed',
				__( 'Could not register member with payment provider. Please try again.', 'helpnagaland-core' ),
				502
			);
		}

		// Plan (cached per mode/frequency/amount/currency).
		$plan_id = self::ensure_plan( $frequency, $amount_paise, self::currency() );
		if ( ! $plan_id ) {
			return self::error_response(
				'hnc_plan_failed',
				__( 'Could not set up subscription plan. Please try again.', 'helpnagaland-core' ),
				502
			);
		}

		$donation_fields = array(
			'type'                 => self::TYPE_SUBSCRIPTION,
			'frequency'            => $frequency,
			'status'               => self::STATUS_CREATED,
			'amount_paise'         => $amount_paise,
			'currency'             => self::currency(),
			'donor_name'           => $anonymous || '' === $donor_name ? null : $donor_name,
			'donor_email'          => $donor_email,
			'donor_email_hash'     => self::hash_email( $donor_email ),
			'donor_phone_e164'     => '' === $donor_phone ? null : $donor_phone,
			'donor_anonymous'      => $anonymous,
			'customer_id'          => (int) $customer['local_id'],
			'provider_customer_id' => $customer['provider_customer_id'],
			'provider_plan_id'     => $plan_id,
			'ip_hash'              => self::hash_ip( self::client_ip() ),
			'user_agent'           => self::request_user_agent( $request ),
			'idempotency_key'      => '' === $idempotency_key ? null : $idempotency_key,
			'notes'                => '' === $notes ? null : $notes,
		);

		$donation_id = 0;
		$donation    = null;

		if ( $existing ) {
			// Resume the half-created row. Refresh fields the donor might have
			// corrected on retry; the plan_id is recomputed from the same
			// (frequency, amount, currency) so it's safe to update.
			$donation_id = (int) $existing['id'];
			$resume_changes = array_intersect_key(
				$donation_fields,
				array_flip( array(
					'donor_name', 'donor_email', 'donor_email_hash',
					'donor_phone_e164', 'donor_anonymous', 'customer_id',
					'provider_customer_id', 'provider_plan_id', 'notes',
				) )
			);
			self::update_donation( $donation_id, $resume_changes );
			$donation = self::get_donation( $donation_id );
		} else {
			$donation_id = self::insert_donation( $donation_fields );

			// Race-loss recovery — re-find on UNIQUE-key violation.
			if ( $donation_id <= 0 && '' !== $idempotency_key ) {
				$racer = self::find_donation_by( 'idempotency_key', $idempotency_key );
				if ( $racer ) {
					if ( ! empty( $racer['provider_subscription_id'] ) ) {
						return self::build_subscription_response( $racer );
					}
					return self::error_response(
						'hnc_donation_in_progress',
						__( 'Your membership payment is being processed. Please wait a moment and try again.', 'helpnagaland-core' ),
						202
					);
				}
			}
			if ( $donation_id <= 0 ) {
				return self::error_response(
					'hnc_donation_create_failed',
					__( 'Could not create membership. Please try again.', 'helpnagaland-core' ),
					500
				);
			}
			$donation = self::get_donation( $donation_id );
		}

		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_create_failed',
				__( 'Could not load membership.', 'helpnagaland-core' ),
				500
			);
		}

		// Create the Razorpay subscription.
		$sub_payload = array(
			'plan_id'         => $plan_id,
			'total_count'     => self::plan_total_count( $frequency ),
			'quantity'        => 1,
			'customer_notify' => 1,
			'notes'           => array(
				'donation_id'          => (string) $donation_id,
				'public_code'          => $donation['public_code'],
				'platform'             => 'helpnagaland.com',
				'frequency'            => $frequency,
				'provider_customer_id' => $customer['provider_customer_id'],
			),
		);

		$rzp_idempotency = 'sub_' . $donation['public_code'];
		$result = self::razorpay_post( '/subscriptions', $sub_payload, $rzp_idempotency );

		if ( ! $result['ok'] ) {
			self::update_donation( $donation_id, array( 'status' => self::STATUS_FAILED ) );
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					'payment_subscription_create_failed',
					array(
						'donation_id' => $donation_id,
						'error_code'  => $result['error_code'],
						'http_code'   => $result['http_code'],
					)
				);
			}
			do_action( 'hnc_payment_donation_failed', $donation_id, $donation, (string) $result['error_code'] );
			return self::error_response(
				'hnc_provider_error',
				__( 'Could not create subscription. Please try again.', 'helpnagaland-core' ),
				502,
				array( 'error_code' => $result['error_code'] )
			);
		}

		$sub_id = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		if ( '' === $sub_id ) {
			self::update_donation( $donation_id, array( 'status' => self::STATUS_FAILED ) );
			return self::error_response(
				'hnc_provider_error',
				__( 'Payment provider returned an invalid response.', 'helpnagaland-core' ),
				502
			);
		}

		self::update_donation( $donation_id, array( 'provider_subscription_id' => $sub_id ) );

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUBSCRIPTION_CREATED,
				array(
					'donation_id'  => $donation_id,
					'public_code'  => $donation['public_code'],
					'amount_paise' => $amount_paise,
					'frequency'    => $frequency,
					'masked_email' => self::mask_email( $donor_email ),
				)
			);
		}

		do_action( 'hnc_payment_donation_recorded', $donation_id, self::get_donation( $donation_id ) );

		return self::build_subscription_response( self::get_donation( $donation_id ) );
	}

	/**
	 * POST /payment/verify — server-side signature check after Razorpay Checkout.
	 *
	 * The frontend calls this with the values returned by Razorpay Checkout.
	 * On success, the donation row advances to "authorized" (one-time) or
	 * "authenticated" (subscription). The webhook later moves it to "captured"
	 * / "active" once Razorpay confirms the money has actually moved.
	 *
	 * Body (JSON):
	 *   type                       'onetime' | 'subscription'  (REQUIRED)
	 *   razorpay_payment_id        (string, REQUIRED)
	 *   razorpay_signature         (string, REQUIRED)
	 *   razorpay_order_id          (string, REQUIRED for onetime)
	 *   razorpay_subscription_id   (string, REQUIRED for subscription)
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_verify( $request ) {
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		if ( class_exists( 'HNC_Api_Ratelimit' ) ) {
			$rl = HNC_Api_Ratelimit::public_ip( self::RL_KEY_VERIFY, 30, 60 );
			$rl_response = self::ratelimit_to_response( $rl );
			if ( $rl_response ) {
				return $rl_response;
			}
		}

		$params = self::parse_request_json( $request );

		$type = isset( $params['type'] ) ? (string) $params['type'] : '';
		if ( ! in_array( $type, array( self::TYPE_ONETIME, self::TYPE_SUBSCRIPTION ), true ) ) {
			return self::error_response(
				'hnc_invalid_type',
				__( 'Missing or invalid membership type.', 'helpnagaland-core' ),
				400
			);
		}

		$payment_id = isset( $params['razorpay_payment_id'] ) ? trim( (string) $params['razorpay_payment_id'] ) : '';
		$signature  = isset( $params['razorpay_signature'] ) ? trim( (string) $params['razorpay_signature'] ) : '';

		if ( '' === $payment_id || '' === $signature ) {
			return self::error_response(
				'hnc_missing_fields',
				__( 'Missing payment id or signature.', 'helpnagaland-core' ),
				400
			);
		}

		// One-time path.
		if ( self::TYPE_ONETIME === $type ) {
			$order_id = isset( $params['razorpay_order_id'] ) ? trim( (string) $params['razorpay_order_id'] ) : '';
			if ( '' === $order_id ) {
				return self::error_response(
					'hnc_missing_fields',
					__( 'Missing order id.', 'helpnagaland-core' ),
					400
				);
			}

			$donation = self::find_donation_by( 'provider_order_id', $order_id );
			if ( ! $donation ) {
				return self::error_response(
					'hnc_donation_not_found',
					__( 'Membership record not found.', 'helpnagaland-core' ),
					404
				);
			}

			$valid = self::verify_payment_signature_onetime( $order_id, $payment_id, $signature );
			if ( ! $valid ) {
				if ( class_exists( 'HNC_Logger' ) ) {
					HNC_Logger::log_security(
						self::ACTION_VERIFY_FAIL,
						array(
							'donation_id' => (int) $donation['id'],
							'type'        => $type,
						)
					);
				}
				return self::error_response(
					'hnc_signature_failed',
					__( 'Payment verification failed.', 'helpnagaland-core' ),
					400
				);
			}

			self::update_donation(
				(int) $donation['id'],
				array(
					'status'              => self::STATUS_AUTHORIZED,
					'provider_payment_id' => $payment_id,
				)
			);

			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					self::ACTION_VERIFY_OK,
					array(
						'donation_id' => (int) $donation['id'],
						'type'        => $type,
						'public_code' => $donation['public_code'],
					)
				);
			}

			do_action( 'hnc_payment_donation_authorized', (int) $donation['id'], self::get_donation( (int) $donation['id'] ) );

			return new WP_REST_Response(
				array(
					'ok'          => true,
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'status'      => self::STATUS_AUTHORIZED,
				),
				200
			);
		}

		// Subscription path.
		$subscription_id = isset( $params['razorpay_subscription_id'] ) ? trim( (string) $params['razorpay_subscription_id'] ) : '';
		if ( '' === $subscription_id ) {
			return self::error_response(
				'hnc_missing_fields',
				__( 'Missing subscription id.', 'helpnagaland-core' ),
				400
			);
		}

		$donation = self::find_donation_by( 'provider_subscription_id', $subscription_id );
		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_not_found',
				__( 'Membership record not found.', 'helpnagaland-core' ),
				404
			);
		}

		// Note the reversed argument order — payment_id|subscription_id.
		$valid = self::verify_payment_signature_subscription( $payment_id, $subscription_id, $signature );
		if ( ! $valid ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					self::ACTION_VERIFY_FAIL,
					array(
						'donation_id' => (int) $donation['id'],
						'type'        => $type,
					)
				);
			}
			return self::error_response(
				'hnc_signature_failed',
				__( 'Payment verification failed.', 'helpnagaland-core' ),
				400
			);
		}

		self::update_donation(
			(int) $donation['id'],
			array(
				'status'              => self::STATUS_AUTHENTICATED,
				'provider_payment_id' => $payment_id,
			)
		);

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_VERIFY_OK,
				array(
					'donation_id' => (int) $donation['id'],
					'type'        => $type,
					'public_code' => $donation['public_code'],
				)
			);
		}

		do_action( 'hnc_payment_subscription_authenticated', (int) $donation['id'], self::get_donation( (int) $donation['id'] ) );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'donation_id' => (int) $donation['id'],
				'public_code' => $donation['public_code'],
				'status'      => self::STATUS_AUTHENTICATED,
			),
			200
		);
	}

	/**
	 * POST /payment/webhook — Razorpay webhook receiver.
	 *
	 * IMPORTANT BEHAVIOURS:
	 *
	 *   - Bypasses the killswitch on purpose. If the operator pauses the
	 *     platform with the killswitch, Razorpay would otherwise retry the
	 *     webhook for hours, polluting their dashboard. Webhook processing
	 *     is internal and safe to keep running.
	 *
	 *   - Reads the RAW request body (signed bytes) — NOT the parsed JSON.
	 *     Any reformatting of the body before HMAC would invalidate the
	 *     signature. We use $request->get_body(), with php://input as the
	 *     fallback for unusual server configs.
	 *
	 *   - Records every inbound event in the events ledger BEFORE deciding
	 *     what to do with it, so an audit trail exists even for events with
	 *     bad signatures or unknown types.
	 *
	 *   - Idempotent on (provider, provider_event_id). Razorpay can replay
	 *     the same event up to ~6 times if our 200 is delayed; the dedup
	 *     check on insert means we never double-process.
	 *
	 *   - Returns 200 fast. Razorpay's webhook timeout is short (~5 sec),
	 *     so handlers must avoid slow operations. Heavy work belongs in
	 *     reconciliation cron, not the webhook hot path.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_webhook( $request ) {
		// NO killswitch on this endpoint. See class docblock.

		// Generous rate limit — Razorpay can burst.
		if ( class_exists( 'HNC_Api_Ratelimit' ) ) {
			$rl = HNC_Api_Ratelimit::public_ip( self::RL_KEY_WEBHOOK, 300, 60 );
			$rl_response = self::ratelimit_to_response( $rl );
			if ( $rl_response ) {
				return $rl_response;
			}
		}

		// 1) Read RAW body. WP_REST_Request gives us bytes via get_body().
		$raw_body = (string) $request->get_body();
		if ( '' === $raw_body ) {
			// Fallback to php://input. WP normally fills get_body() but some
			// stacks (FastCGI variants) leave it empty for non-form posts.
			$raw_body = (string) @file_get_contents( 'php://input' );
		}
		if ( '' === $raw_body ) {
			return self::error_response(
				'hnc_empty_body',
				__( 'Empty request body.', 'helpnagaland-core' ),
				400
			);
		}

		// 2) Pull the signature header. Razorpay sends X-Razorpay-Signature.
		$received_sig = (string) $request->get_header( 'x_razorpay_signature' );

		// 3) Verify signature against RAW body.
		$sig_ok = self::verify_webhook_signature( $raw_body, $received_sig );

		// 4) Parse payload. Even if signature failed, we still want to log
		//    enough metadata to investigate (the event id is useful for
		//    correlating with the Razorpay dashboard).
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					'payment_webhook_bad_json',
					array( 'sig_ok' => $sig_ok ? 1 : 0 )
				);
			}
			return self::error_response(
				'hnc_invalid_json',
				__( 'Invalid JSON.', 'helpnagaland-core' ),
				400
			);
		}

		$event_id   = isset( $payload['id'] ) ? (string) $payload['id'] : '';
		$event_type = isset( $payload['event'] ) ? (string) $payload['event'] : '';
		if ( '' === $event_id || '' === $event_type ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					'payment_webhook_missing_fields',
					array( 'sig_ok' => $sig_ok ? 1 : 0 )
				);
			}
			return self::error_response(
				'hnc_missing_event_fields',
				__( 'Missing event id or type.', 'helpnagaland-core' ),
				400
			);
		}

		// 5) Locate the related Razorpay object and try to link to a donation.
		list( $object_type, $object_id ) = self::extract_webhook_object( $payload );
		$donation_id_for_event = self::find_donation_for_webhook( $payload, $object_type, $object_id );

		// 6) Record event row. Returns 0 on duplicate (already-seen event id)
		//    OR on failure — both branches are handled below.
		$event_row_id = self::record_event(
			array(
				'donation_id'          => $donation_id_for_event,
				'provider'             => self::PROVIDER_RAZORPAY,
				'provider_event_id'    => $event_id,
				'event_type'           => $event_type,
				'provider_object_type' => $object_type,
				'provider_object_id'   => $object_id,
				'signature_ok'         => $sig_ok ? 1 : 0,
				'ip_hash'              => self::hash_ip( self::client_ip() ),
			)
		);

		// 7) Bad signature — log + record event row outcome + reject. We do
		//    NOT silently 200 a bad-sig request: that would let an attacker
		//    teach Razorpay to stop retrying real events.
		if ( ! $sig_ok ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					self::ACTION_WEBHOOK_SIG_FAIL,
					array(
						'event_id'   => $event_id,
						'event_type' => $event_type,
					)
				);
			}
			if ( $event_row_id > 0 ) {
				self::mark_event_processed( $event_row_id, self::EVENT_OUTCOME_SIG_FAIL, array() );
			}
			return self::error_response(
				'hnc_signature_failed',
				__( 'Signature verification failed.', 'helpnagaland-core' ),
				401
			);
		}

		// 8) Duplicate event — already processed. ACK with 200 so Razorpay
		//    stops retrying.
		if ( 0 === $event_row_id ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					self::ACTION_WEBHOOK_DUPLICATE,
					array(
						'event_id'   => $event_id,
						'event_type' => $event_type,
					)
				);
			}
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'duplicate' => true,
				),
				200
			);
		}

		// 9) Log the receipt of a brand-new, signature-valid event.
		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_WEBHOOK_RECEIVED,
				array(
					'event_id'    => $event_id,
					'event_type'  => $event_type,
					'donation_id' => $donation_id_for_event,
				)
			);
		}

		// 10) Dispatch to a type-specific handler. The dispatcher itself must
		//     never throw — we still wrap in try/catch as defence in depth.
		try {
			$dispatch = self::dispatch_webhook_event( $event_type, $payload, $donation_id_for_event, $event_row_id );
		} catch ( Throwable $t ) {
			$dispatch = array(
				'outcome' => self::EVENT_OUTCOME_ERROR,
				'detail'  => array( 'error' => $t->getMessage() ),
			);
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					'payment_webhook_handler_exception',
					array(
						'event_id'   => $event_id,
						'event_type' => $event_type,
						'error'      => $t->getMessage(),
					)
				);
			}
		}

		// 11) Persist outcome.
		$outcome_code   = isset( $dispatch['outcome'] ) ? (string) $dispatch['outcome'] : self::EVENT_OUTCOME_UNHANDLED;
		$outcome_detail = isset( $dispatch['detail'] ) && is_array( $dispatch['detail'] ) ? $dispatch['detail'] : array();
		self::mark_event_processed( $event_row_id, $outcome_code, $outcome_detail );

		// 12) Always 200 to a signed, deduped event — even if our handler
		//     decided "skip". A 5xx here would trigger Razorpay retries,
		//     which we don't want for events we've already recorded.
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'outcome' => $outcome_code,
			),
			200
		);
	}

	/**
	 * POST /payment/admin/refund — issue a refund for a donation.
	 *
	 * Body (JSON):
	 *   donation_id   (int, required) OR public_code (string, required)
	 *   amount_paise  (int, optional) — defaults to remaining refundable balance
	 *   reason        (string, optional) — recorded in Razorpay notes + log
	 *
	 * Notes:
	 *   - Initiates the refund at Razorpay; the donation row is updated by
	 *     the refund.processed webhook (single source of truth).
	 *   - Killswitch-aware. If you've paused the platform you've paused
	 *     money movement — including admin refunds.
	 *   - Capability-gated AND audit-logged. Every call lands in the
	 *     security log with the admin user id.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_admin_refund( $request ) {
		// Killswitch applies — admin money-movement should pause too.
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		if ( ! self::is_configured() ) {
			return self::error_response(
				'hnc_payment_not_configured',
				__( 'Payment provider is not configured.', 'helpnagaland-core' ),
				503
			);
		}

		$params   = self::parse_request_json( $request );
		$donation = self::admin_resolve_donation( $params );
		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_not_found',
				__( 'Membership record not found.', 'helpnagaland-core' ),
				404
			);
		}

		$payment_id = (string) $donation['provider_payment_id'];
		if ( '' === $payment_id ) {
			return self::error_response(
				'hnc_no_payment_to_refund',
				__( 'This membership has no captured payment to refund.', 'helpnagaland-core' ),
				400
			);
		}

		$bad_states = array( self::STATUS_REFUNDED, self::STATUS_FAILED, self::STATUS_CREATED, self::STATUS_AUTHORIZED );
		if ( in_array( (string) $donation['status'], $bad_states, true ) ) {
			return self::error_response(
				'hnc_invalid_state_for_refund',
				/* translators: %s: current donation status */
				sprintf( __( 'Payment status %s cannot be refunded.', 'helpnagaland-core' ), (string) $donation['status'] ),
				400
			);
		}

		$amount_paid      = (int) $donation['amount_paid_paise'];
		$already_refunded = (int) $donation['amount_refunded_paise'];
		$remaining        = max( 0, $amount_paid - $already_refunded );
		if ( $remaining <= 0 ) {
			return self::error_response(
				'hnc_already_fully_refunded',
				__( 'No refundable balance remaining.', 'helpnagaland-core' ),
				400
			);
		}

		// Default to refunding the full remaining balance.
		$amount = isset( $params['amount_paise'] ) ? (int) $params['amount_paise'] : $remaining;
		if ( $amount <= 0 || $amount > $remaining ) {
			return self::error_response(
				'hnc_invalid_refund_amount',
				/* translators: %d: maximum refundable amount in paise */
				sprintf( __( 'Refund amount must be between 1 and %d paise.', 'helpnagaland-core' ), $remaining ),
				400
			);
		}

		$reason = self::sanitize_notes( isset( $params['reason'] ) ? $params['reason'] : '' );

		$payload = array(
			'amount' => $amount,
			'speed'  => 'normal',
			'notes'  => array(
				'donation_id' => (string) $donation['id'],
				'public_code' => (string) $donation['public_code'],
				'admin_user'  => (string) get_current_user_id(),
			),
		);
		if ( '' !== $reason ) {
			$payload['notes']['reason'] = $reason;
		}

		// Idempotent: if the admin double-clicks, Razorpay returns the same refund.
		$idempotency = 'admin_refund_' . $donation['public_code'] . '_' . $amount;
		$result      = self::razorpay_post( '/payments/' . $payment_id . '/refund', $payload, $idempotency );

		if ( ! $result['ok'] ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					self::ACTION_ADMIN_REFUND,
					array(
						'donation_id' => (int) $donation['id'],
						'public_code' => $donation['public_code'],
						'amount'      => $amount,
						'state'       => 'failed',
						'admin_user'  => (int) get_current_user_id(),
						'error_code'  => $result['error_code'],
					)
				);
			}
			return self::error_response(
				'hnc_refund_failed',
				__( 'Refund could not be initiated.', 'helpnagaland-core' ),
				502,
				array( 'provider_error' => $result['error_code'] )
			);
		}

		$refund_id     = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		$refund_status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_security(
				self::ACTION_ADMIN_REFUND,
				array(
					'donation_id'   => (int) $donation['id'],
					'public_code'   => $donation['public_code'],
					'amount'        => $amount,
					'refund_id'     => $refund_id,
					'refund_status' => $refund_status,
					'admin_user'    => (int) get_current_user_id(),
					'state'         => 'initiated',
				)
			);
		}

		// Donation row will be updated by the refund.processed webhook.
		// Return a snapshot of the row as-is for the admin UI.
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'donation' => self::get_donation( (int) $donation['id'] ),
				'razorpay' => array(
					'refund_id' => $refund_id,
					'status'    => $refund_status,
					'amount'    => $amount,
				),
			),
			200
		);
	}

	/**
	 * POST /payment/admin/cancel-subscription — cancel an active subscription.
	 *
	 * Body (JSON):
	 *   donation_id          (int, required) OR public_code (string, required)
	 *   cancel_at_cycle_end  (bool, default false) — if true, finishes current
	 *                        cycle then cancels; otherwise cancels immediately
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_admin_cancel_subscription( $request ) {
		if ( class_exists( 'HNC_Killswitch' ) ) {
			$blocked = HNC_Killswitch::guard_public_endpoint();
			if ( $blocked ) {
				return $blocked;
			}
		}

		if ( ! self::is_configured() ) {
			return self::error_response(
				'hnc_payment_not_configured',
				__( 'Payment provider is not configured.', 'helpnagaland-core' ),
				503
			);
		}

		$params   = self::parse_request_json( $request );
		$donation = self::admin_resolve_donation( $params );
		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_not_found',
				__( 'Membership record not found.', 'helpnagaland-core' ),
				404
			);
		}

		if ( self::TYPE_SUBSCRIPTION !== (string) $donation['type'] ) {
			return self::error_response(
				'hnc_not_a_subscription',
				__( 'This membership is not a subscription.', 'helpnagaland-core' ),
				400
			);
		}

		$sub_id = (string) $donation['provider_subscription_id'];
		if ( '' === $sub_id ) {
			return self::error_response(
				'hnc_no_subscription_id',
				__( 'No Razorpay subscription id is recorded for this membership.', 'helpnagaland-core' ),
				400
			);
		}

		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( in_array( (string) $donation['status'], $terminal, true ) ) {
			return self::error_response(
				'hnc_already_terminal',
				__( 'Subscription is already in a terminal state.', 'helpnagaland-core' ),
				400
			);
		}

		$cancel_at_cycle_end = ! empty( $params['cancel_at_cycle_end'] ) ? 1 : 0;

		$payload = array( 'cancel_at_cycle_end' => $cancel_at_cycle_end );
		$result  = self::razorpay_post( '/subscriptions/' . $sub_id . '/cancel', $payload );

		if ( ! $result['ok'] ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_security(
					self::ACTION_ADMIN_CANCEL_SUB,
					array(
						'donation_id'         => (int) $donation['id'],
						'public_code'         => $donation['public_code'],
						'state'               => 'failed',
						'admin_user'          => (int) get_current_user_id(),
						'cancel_at_cycle_end' => $cancel_at_cycle_end,
						'error_code'          => $result['error_code'],
					)
				);
			}
			return self::error_response(
				'hnc_cancel_failed',
				__( 'Cancellation could not be processed.', 'helpnagaland-core' ),
				502,
				array( 'provider_error' => $result['error_code'] )
			);
		}

		// For immediate cancellation, update the donation row right away —
		// the subscription.cancelled webhook is also expected, but giving the
		// admin UI instant feedback is worth the (idempotent) double-update.
		// For end-of-cycle, leave status alone — webhook will fire when cycle ends.
		if ( ! $cancel_at_cycle_end ) {
			self::update_donation(
				(int) $donation['id'],
				array(
					'status'       => self::STATUS_CANCELLED,
					'cancelled_at' => current_time( 'mysql', true ),
				)
			);

			do_action(
				'hnc_payment_subscription_cancelled',
				(int) $donation['id'],
				self::get_donation( (int) $donation['id'] ),
				true // by_admin
			);
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_security(
				self::ACTION_ADMIN_CANCEL_SUB,
				array(
					'donation_id'         => (int) $donation['id'],
					'public_code'         => $donation['public_code'],
					'admin_user'          => (int) get_current_user_id(),
					'cancel_at_cycle_end' => $cancel_at_cycle_end,
					'state'               => 'initiated',
				)
			);
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'donation' => self::get_donation( (int) $donation['id'] ),
				'razorpay' => array(
					'status'              => isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '',
					'cancel_at_cycle_end' => $cancel_at_cycle_end,
				),
			),
			200
		);
	}

	/**
	 * GET /payment/admin/donation — fetch a single donation row + its event log.
	 *
	 * Query string:
	 *   donation_id  (int, required) OR public_code (string, required)
	 *   limit        (int, optional, default 50, max 200) — events to return
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_admin_get_donation( $request ) {
		// Read-only — no killswitch (admins must be able to investigate during pauses).

		$donation_id = (int) $request->get_param( 'donation_id' );
		$public_code = (string) $request->get_param( 'public_code' );
		$limit       = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 200 ) {
			$limit = 50;
		}

		$donation = self::admin_resolve_donation( array(
			'donation_id' => $donation_id,
			'public_code' => $public_code,
		) );
		if ( ! $donation ) {
			return self::error_response(
				'hnc_donation_not_found',
				__( 'Membership record not found.', 'helpnagaland-core' ),
				404
			);
		}

		$events = self::get_events_for_donation( (int) $donation['id'], $limit );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'donation' => $donation,
				'events'   => $events,
			),
			200
		);
	}

	/**
	 * Helper: build a 501 response for stubbed routes.
	 *
	 * @param string $route_id Identifier for the route, used in logs.
	 * @return WP_REST_Response
	 */
	private static function not_yet_implemented( $route_id ) {
		return new WP_REST_Response(
			array(
				'code'    => 'hnc_payment_not_yet_implemented',
				'message' => __( 'This payment route is not yet available.', 'helpnagaland-core' ),
				'data'    => array(
					'status' => 501,
					'route'  => $route_id,
				),
			),
			501
		);
	}


	/* ==================================================================
	 * CONFIGURATION ACCESSORS
	 * ==================================================================
	 *
	 * All config reads go through these methods. wp-config.php constants
	 * win over options. Filters are applied last so site code can override
	 * either source.
	 */

	/**
	 * True if live mode is enabled. Stored only as an option (no constant
	 * override) so the operator can flip modes without redeploying.
	 *
	 * @return bool
	 */
	public static function is_live_mode() {
		$enabled = (int) get_option( 'hnc_payment_live_mode', 0 ) === 1;
		return (bool) apply_filters( 'hnc_payment_live_mode', $enabled );
	}

	/**
	 * Razorpay key id (public).
	 *
	 * @return string
	 */
	public static function razorpay_key_id() {
		$live = self::is_live_mode();

		// wp-config.php constant takes precedence.
		$const_name = $live ? 'HNC_RAZORPAY_LIVE_KEY_ID' : 'HNC_RAZORPAY_TEST_KEY_ID';
		if ( defined( $const_name ) ) {
			$value = (string) constant( $const_name );
		} else {
			$option_key = $live ? 'hnc_payment_razorpay_live_key_id' : 'hnc_payment_razorpay_test_key_id';
			$value      = (string) get_option( $option_key, '' );
		}

		return (string) apply_filters( 'hnc_payment_razorpay_key_id', $value, $live );
	}

	/**
	 * Razorpay key secret. NEVER returned to the frontend. NEVER logged.
	 *
	 * @return string
	 */
	public static function razorpay_key_secret() {
		$live = self::is_live_mode();

		$const_name = $live ? 'HNC_RAZORPAY_LIVE_KEY_SECRET' : 'HNC_RAZORPAY_TEST_KEY_SECRET';
		if ( defined( $const_name ) ) {
			$value = (string) constant( $const_name );
		} else {
			$option_key = $live ? 'hnc_payment_razorpay_live_key_secret' : 'hnc_payment_razorpay_test_key_secret';
			$value      = (string) get_option( $option_key, '' );
		}

		// NOTE: this filter exists for testability (e.g. injecting a mock
		// secret in unit tests). Production code MUST NOT log the result.
		return (string) apply_filters( 'hnc_payment_razorpay_key_secret', $value, $live );
	}

	/**
	 * Razorpay webhook secret. NEVER returned to the frontend. NEVER logged.
	 *
	 * @return string
	 */
	public static function razorpay_webhook_secret() {
		$live = self::is_live_mode();

		$const_name = $live ? 'HNC_RAZORPAY_LIVE_WEBHOOK_SECRET' : 'HNC_RAZORPAY_TEST_WEBHOOK_SECRET';
		if ( defined( $const_name ) ) {
			$value = (string) constant( $const_name );
		} else {
			$option_key = $live ? 'hnc_payment_razorpay_live_webhook_secret' : 'hnc_payment_razorpay_test_webhook_secret';
			$value      = (string) get_option( $option_key, '' );
		}

		return (string) apply_filters( 'hnc_payment_razorpay_webhook_secret', $value, $live );
	}

	/**
	 * Currency. Defaults to INR. Filterable, but result MUST be in
	 * ALLOWED_CURRENCIES — invalid filter results fall back to INR.
	 *
	 * @return string
	 */
	public static function currency() {
		$value = (string) apply_filters( 'hnc_payment_currency', 'INR' );
		return in_array( $value, self::ALLOWED_CURRENCIES, true ) ? $value : 'INR';
	}

	/**
	 * Minimum donation amount in paise.
	 *
	 * @return int
	 */
	public static function min_amount_paise() {
		$default = self::DEFAULT_MIN_AMOUNT_PAISE;
		$value   = (int) apply_filters( 'hnc_payment_min_amount_paise', $default );
		return $value > 0 ? $value : $default;
	}

	/**
	 * Configured plan price (paise) for a given frequency. Returns 0 if no
	 * fixed price is configured for that frequency, in which case the strict
	 * plan-match check is skipped and only the global min/max bounds apply.
	 *
	 * @param string $frequency 'monthly' | 'yearly'
	 * @return int paise, or 0 if not configured
	 */
	public static function expected_plan_price_paise( $frequency ) {
		$option_key = '';
		if ( self::FREQ_MONTHLY === $frequency ) {
			$option_key = 'hnc_payment_monthly_amount_inr';
		} elseif ( self::FREQ_YEARLY === $frequency ) {
			$option_key = 'hnc_payment_yearly_amount_inr';
		}
		if ( '' === $option_key ) {
			return 0;
		}
		$inr = (int) get_option( $option_key, 0 );
		return $inr > 0 ? $inr * 100 : 0;
	}

	/**
	 * Maximum donation amount in paise.
	 *
	 * @return int
	 */
	public static function max_amount_paise() {
		$default = self::DEFAULT_MAX_AMOUNT_PAISE;
		$value   = (int) apply_filters( 'hnc_payment_max_amount_paise', $default );
		return $value >= self::min_amount_paise() ? $value : $default;
	}

	/**
	 * Allowed frequencies. Operator can restrict via filter (e.g. disable
	 * weekly during a campaign). The set is always intersected with the
	 * platform-supported list.
	 *
	 * @return string[]
	 */
	public static function allowed_frequencies() {
		$supported = array(
			self::FREQ_ONCE,
			self::FREQ_WEEKLY,
			self::FREQ_MONTHLY,
			self::FREQ_HALFYEARLY,
			self::FREQ_YEARLY,
		);
		$filtered  = apply_filters( 'hnc_payment_allowed_frequencies', $supported );
		if ( ! is_array( $filtered ) ) {
			return $supported;
		}
		// Keep only values that are in the supported list.
		$filtered = array_values( array_intersect( $supported, $filtered ) );
		return ! empty( $filtered ) ? $filtered : $supported;
	}

	/**
	 * Intent token TTL (seconds). Filterable, clamped to a sane range.
	 *
	 * @return int
	 */
	public static function intent_token_ttl() {
		$value = (int) apply_filters( 'hnc_payment_intent_token_ttl', self::INTENT_TOKEN_TTL );
		// Clamp: at least 60s (frontend latency), at most 1 hour.
		if ( $value < 60 ) {
			return 60;
		}
		if ( $value > 3600 ) {
			return 3600;
		}
		return $value;
	}

	/**
	 * Outbound HTTP request timeout (seconds) for Razorpay API calls.
	 *
	 * @return int
	 */
	public static function request_timeout() {
		$value = (int) apply_filters( 'hnc_payment_request_timeout', 15 );
		if ( $value < 5 ) {
			return 5;
		}
		if ( $value > 60 ) {
			return 60;
		}
		return $value;
	}

	/**
	 * PII retention in days. Donation rows older than this have donor
	 * email/name purged by the reconciliation cron. Set to 0 to disable
	 * purging entirely (default 365 = one year).
	 *
	 * @return int
	 */
	public static function pii_retention_days() {
		$value = (int) apply_filters( 'hnc_payment_pii_retention_days', 365 );
		return $value < 0 ? 0 : $value;
	}

	/**
	 * True if Razorpay credentials for the active mode are configured.
	 * Used by the GET /payment/config endpoint and by health_check().
	 *
	 * @return bool
	 */
	public static function is_configured() {
		// All three secrets must be present. If webhook_secret is missing,
		// donations would create successfully but every webhook would fail
		// signature verification, leaving donations stuck in 'authorized'
		// forever (donor charged, no receipt issued). is_configured() must
		// be the single, conservative truth — handlers route around it
		// with 503 errors.
		return self::razorpay_key_id() !== ''
			&& self::razorpay_key_secret() !== ''
			&& self::razorpay_webhook_secret() !== '';
	}


	/* ==================================================================
	 * SCHEMA HELPERS
	 * ==================================================================
	 *
	 * Table names are owned by HNC_Schema; this class only delegates.
	 * If HNC_Schema does not yet expose the helpers (because the operator
	 * hasn't pasted the new table blocks yet), we fall back to building
	 * the names ourselves. This keeps the file safe to upload first.
	 */

	/**
	 * Check whether a public_code corresponds to an active Premium Member.
	 *
	 * Used by the report submission endpoints to gate the WhatsApp/phone
	 * field on drug tips and to flag alcohol pins as member-submitted on
	 * the public map. A code is "active" when its donation row is in any
	 * of these statuses:
	 *   - captured / authorized        (one-time payment confirmed)
	 *   - active / authenticated /
	 *     resumed / charged / paused   (subscription is running or
	 *                                  temporarily paused but not cancelled)
	 *
	 * Cancelled, halted, refunded, completed, failed, and never-captured
	 * codes return false.
	 *
	 * @param string $code The Member Code (public_code on the donation row).
	 * @return bool True if the code is currently a paying Premium Member.
	 */
	public static function is_active_member_code( $code ) {
		$code = is_string( $code ) ? trim( $code ) : '';
		if ( '' === $code ) {
			return false;
		}
		// Defensive length check to prevent accidental long inputs hitting the DB.
		if ( strlen( $code ) > 16 ) {
			return false;
		}
		global $wpdb;
		$table = self::table_donations();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE public_code = %s LIMIT 1", $code )
		);
		if ( ! is_string( $status ) || '' === $status ) {
			return false;
		}
		$active_statuses = array(
			self::STATUS_CAPTURED,
			self::STATUS_AUTHORIZED,
			self::STATUS_ACTIVE,
			self::STATUS_AUTHENTICATED,
			self::STATUS_RESUMED,
			self::STATUS_PAUSED,
		);
		return in_array( $status, $active_statuses, true );
	}

	/**
	 * Full name of the donations table.
	 *
	 * @return string
	 */
	public static function table_donations() {
		if ( method_exists( 'HNC_Schema', 'table_donations' ) ) {
			return HNC_Schema::table_donations();
		}
		global $wpdb;
		return $wpdb->prefix . HNC_TABLE_PREFIX . 'donations';
	}

	/**
	 * Full name of the payment events ledger table.
	 *
	 * @return string
	 */
	public static function table_events() {
		if ( method_exists( 'HNC_Schema', 'table_payment_events' ) ) {
			return HNC_Schema::table_payment_events();
		}
		global $wpdb;
		return $wpdb->prefix . HNC_TABLE_PREFIX . 'payment_events';
	}

	/**
	 * Full name of the payment customers table.
	 *
	 * @return string
	 */
	public static function table_customers() {
		if ( method_exists( 'HNC_Schema', 'table_payment_customers' ) ) {
			return HNC_Schema::table_payment_customers();
		}
		global $wpdb;
		return $wpdb->prefix . HNC_TABLE_PREFIX . 'payment_customers';
	}


	/* ==================================================================
	 * SCHEMA MIGRATION FRAMEWORK
	 * ==================================================================
	 *
	 * HNC_Schema::install() handles initial table creation when the
	 * operator pastes the new blocks. This framework handles forward-only
	 * migrations within this class's own version line, separate from the
	 * plugin-wide HNC_DB_VERSION.
	 *
	 * Each migration is an ordered, idempotent step. We track applied
	 * versions in the option `hnc_payment_schema_version`. To add a
	 * migration, append a new case to migrations_table() and bump
	 * SCHEMA_VERSION.
	 */

	/**
	 * Idempotent schema upgrade runner. Cheap when there is nothing to do.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'hnc_payment_schema_version', 0 );
		if ( $installed >= self::SCHEMA_VERSION ) {
			return;
		}

		// Verify the tables exist before trying to migrate. If the operator
		// hasn't yet pasted the schema blocks into HNC_Schema, exit
		// quietly — install() needs to run first via the normal db-version
		// path. Logging this once helps surface the misconfiguration.
		if ( ! self::tables_exist() ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					self::ACTION_SCHEMA_UPGRADE,
					array(
						'state' => 'tables_missing',
						'note'  => 'HNC_Schema does not yet have payment tables installed.',
					)
				);
			}
			return;
		}

		// Run each pending migration in order.
		$migrations = self::migrations_table();
		foreach ( $migrations as $version => $callable ) {
			if ( $version <= $installed ) {
				continue;
			}
			try {
				call_user_func( $callable );
				update_option( 'hnc_payment_schema_version', (int) $version );
				if ( class_exists( 'HNC_Logger' ) ) {
					HNC_Logger::log_system(
						self::ACTION_SCHEMA_UPGRADE,
						array(
							'from' => $installed,
							'to'   => (int) $version,
						)
					);
				}
				$installed = (int) $version;
			} catch ( Throwable $t ) {
				// Log and stop. Subsequent page loads will retry.
				if ( class_exists( 'HNC_Logger' ) ) {
					HNC_Logger::log_system(
						self::ACTION_SCHEMA_UPGRADE,
						array(
							'state' => 'failed',
							'at'    => (int) $version,
							'error' => $t->getMessage(),
						)
					);
				}
				return;
			}
		}
	}

	/**
	 * Ordered map of schema_version => migration callable.
	 * Migrations must be idempotent and forward-only.
	 *
	 * @return array
	 */
	private static function migrations_table() {
		// Version 1: tables created by HNC_Schema::install(). No-op here —
		// we just record that we are at v1 so future migrations can build
		// on the assumption that v1 columns exist.
		return array(
			1 => static function () {
				// Intentional no-op. Tables come from HNC_Schema.
			},
			// 2 => array( __CLASS__, 'migrate_to_v2' ),  // example for future
		);
	}

	/**
	 * Cheap existence check for our three tables. Used by maybe_upgrade().
	 *
	 * @return bool
	 */
	private static function tables_exist() {
		global $wpdb;
		$tables = array(
			self::table_donations(),
			self::table_events(),
			self::table_customers(),
		);
		foreach ( $tables as $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
			if ( $found !== $t ) {
				return false;
			}
		}
		return true;
	}


	/* ==================================================================
	 * MONEY + INPUT SANITISATION
	 * ==================================================================
	 *
	 * Money discipline:
	 *   - Amounts are always stored and passed as integer paise.
	 *   - We never use floats for money. ever.
	 *   - User input arrives as either rupees-as-string ("499.50") or
	 *     paise-as-int (49950). Use sanitize_amount_to_paise() at the
	 *     boundary to normalise.
	 *
	 * Donor PII discipline:
	 *   - Donor name and email DO NOT pass through HNC_Sanitizer's
	 *     sanitize_free_text() — that strip would mangle real names
	 *     (e.g. "Dr. Aloto Naga" -> "[name removed] Naga"). We use the
	 *     lighter sanitize_text_field + length cap pattern instead.
	 *   - Email is stored both raw (for receipts) and as a SHA-256 hash
	 *     (for de-duplication and reconciliation without exposing PII
	 *     in logs).
	 */

	/**
	 * Convert any acceptable amount input to integer paise. Returns 0 if
	 * the input cannot be parsed as a positive amount.
	 *
	 * Acceptable inputs:
	 *   - integer paise: 49950, "49950"
	 *   - rupee string with two decimals: "499.50", "499", "1,999.00"
	 *   - amount + unit hint via $unit param
	 *
	 * @param mixed  $raw  Raw input.
	 * @param string $unit 'paise' (default) or 'rupees'.
	 * @return int Integer paise. 0 means invalid.
	 */
	public static function sanitize_amount_to_paise( $raw, $unit = 'paise' ) {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return 0;
		}

		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return 0;
		}

		// Strip Indian-style thousands separators and currency symbols.
		$s = str_replace( array( ',', '₹', 'Rs.', 'Rs', 'INR' ), '', $s );
		$s = trim( $s );

		// Reject anything that is not a non-negative decimal number.
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $s ) ) {
			return 0;
		}

		if ( 'rupees' === $unit ) {
			// Multiply by 100 without floating-point drift.
			$parts = explode( '.', $s );
			$rupees = (int) $parts[0];
			$paise_part = isset( $parts[1] ) ? str_pad( $parts[1], 2, '0', STR_PAD_RIGHT ) : '00';
			$paise_part = (int) $paise_part;
			$total = ( $rupees * 100 ) + $paise_part;
			return $total > 0 ? $total : 0;
		}

		// Paise: must be an integer.
		if ( strpos( $s, '.' ) !== false ) {
			return 0;
		}
		$total = (int) $s;
		return $total > 0 ? $total : 0;
	}

	/**
	 * Validate that an amount in paise is within configured bounds.
	 *
	 * @param int $paise Amount in paise.
	 * @return bool
	 */
	public static function is_valid_amount( $paise ) {
		$paise = (int) $paise;
		return $paise >= self::min_amount_paise() && $paise <= self::max_amount_paise();
	}

	/**
	 * Validate a frequency string against the configured allowlist.
	 *
	 * @param string $raw Raw input.
	 * @return string Valid frequency, or '' if invalid.
	 */
	public static function sanitize_frequency( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		return in_array( $raw, self::allowed_frequencies(), true ) ? $raw : '';
	}

	/**
	 * Validate a currency code against the allowlist.
	 *
	 * @param string $raw Raw input.
	 * @return string Valid currency, or '' if invalid.
	 */
	public static function sanitize_currency( $raw ) {
		$raw = strtoupper( trim( (string) $raw ) );
		return in_array( $raw, self::ALLOWED_CURRENCIES, true ) ? $raw : '';
	}

	/**
	 * Sanitize a donor name. Light touch — does NOT strip honorifics or
	 * names like HNC_Sanitizer does, because in this context the name IS
	 * the data we want to keep.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function sanitize_donor_name( $raw ) {
		$s = (string) $raw;
		// Remove HTML and control chars.
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$s = wp_strip_all_tags( $s, true );
		} else {
			$s = strip_tags( $s );
		}
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		// Collapse whitespace.
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = trim( $s );
		// Cap at 120 chars (matches partner.name in your schema).
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, 120, 'UTF-8' );
		}
		return substr( $s, 0, 120 );
	}

	/**
	 * Sanitize a donor email. Returns '' if not a valid email.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function sanitize_donor_email( $raw ) {
		$s = sanitize_email( (string) $raw );
		if ( '' === $s || ! is_email( $s ) ) {
			return '';
		}
		// Cap at 190 chars (DB column width).
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, 190, 'UTF-8' );
		}
		return substr( $s, 0, 190 );
	}

	/**
	 * Mask an email for safe inclusion in logs: "abc***@example.com".
	 *
	 * @param string $email Raw email.
	 * @return string Masked. Empty string if input is not an email.
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		if ( '' === $email || strpos( $email, '@' ) === false ) {
			return '';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $local, 'UTF-8' ) : strlen( $local );
		if ( $len <= 3 ) {
			return $local[0] . '***@' . $domain;
		}
		$visible = function_exists( 'mb_substr' ) ? mb_substr( $local, 0, 3, 'UTF-8' ) : substr( $local, 0, 3 );
		return $visible . '***@' . $domain;
	}

	/**
	 * Stable, salted hash of an email for de-duplication and lookup.
	 * Reuses the daily IP salt option already maintained by the platform —
	 * NOT ideal for long-term joins (rotates daily), so we additionally
	 * mix a payment-specific salt that is created once and never rotated.
	 *
	 * @param string $email Raw email.
	 * @return string 64-char hex SHA-256.
	 */
	public static function hash_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return '';
		}
		$salt = (string) get_option( 'hnc_payment_email_salt', '' );
		if ( '' === $salt ) {
			// Create a one-time salt the first time we are called.
			$salt = wp_generate_password( 64, true, true );
			update_option( 'hnc_payment_email_salt', $salt, false );
		}
		return hash( 'sha256', $salt . '|' . $email );
	}

	/**
	 * Hash an IP for safe storage in the events table. Defers to
	 * HNC_Logger::hash_ip if available so we share the daily-rotated salt.
	 *
	 * @param string $ip Raw IP.
	 * @return string 64-char hex SHA-256, or '' if input empty.
	 */
	public static function hash_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		if ( class_exists( 'HNC_Logger' ) && method_exists( 'HNC_Logger', 'hash_ip' ) ) {
			return HNC_Logger::hash_ip( $ip );
		}
		// Fallback: own salt.
		$salt = (string) get_option( 'hnc_ip_daily_salt', 'fallback' );
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Read the client IP using the same trusted-proxy convention the rest
	 * of the plugin uses (see HNC_Api_Ratelimit). Returns '' if unavailable.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$trust = (bool) apply_filters( 'hnc_trust_forwarded_for', false );
		if ( $trust && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$raw   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts = array_map( 'trim', explode( ',', $raw ) );
			foreach ( $parts as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		return '';
	}

	/**
	 * Build a consistent error response. Use this from every REST handler
	 * so the frontend sees one shape for failures.
	 *
	 * @param string $code     Machine-readable code.
	 * @param string $message  Human-readable message (will be translated by caller).
	 * @param int    $status   HTTP status.
	 * @param array  $extra    Optional extra payload.
	 * @return WP_REST_Response
	 */
	public static function error_response( $code, $message, $status = 400, $extra = array() ) {
		$data = array( 'status' => (int) $status );
		if ( ! empty( $extra ) && is_array( $extra ) ) {
			$data = array_merge( $data, $extra );
		}
		$response = new WP_REST_Response(
			array(
				'code'    => (string) $code,
				'message' => (string) $message,
				'data'    => $data,
			),
			(int) $status
		);
		return $response;
	}

	/**
	 * Convert a HNC_Api_Ratelimit WP_Error into a REST response with the
	 * Retry-After header set. Returns the response if rate-limited, or
	 * null if the input is null (i.e. not rate-limited).
	 *
	 * @param mixed $rl_result Result of HNC_Api_Ratelimit::*().
	 * @return WP_REST_Response|null
	 */
	public static function ratelimit_to_response( $rl_result ) {
		if ( null === $rl_result ) {
			return null;
		}
		if ( ! is_wp_error( $rl_result ) ) {
			return null;
		}
		$err_data    = $rl_result->get_error_data();
		$status      = isset( $err_data['status'] ) ? (int) $err_data['status'] : 429;
		$retry_after = isset( $err_data['retry_after'] ) ? (int) $err_data['retry_after'] : 60;

		$response = new WP_REST_Response(
			array(
				'code'    => $rl_result->get_error_code(),
				'message' => $rl_result->get_error_message(),
				'data'    => array(
					'status'      => $status,
					'retry_after' => $retry_after,
				),
			),
			$status
		);
		$response->header( 'Retry-After', (string) $retry_after );
		return $response;
	}


	/* ==================================================================
	 * RAZORPAY HTTP CLIENT
	 * ==================================================================
	 *
	 * Thin wrapper around wp_remote_request() with:
	 *   - HTTP Basic auth (active mode's key_id:key_secret)
	 *   - JSON request body encoding
	 *   - Configurable timeout (see request_timeout() filter)
	 *   - Optional X-Razorpay-Idempotency header
	 *   - Retry-with-backoff on 5xx and network errors (up to HTTP_RETRY_MAX)
	 *   - Structured result shape — never throws
	 *   - Latency tracking with slow-request logging
	 *
	 * Result shape (always returned, never throws):
	 *
	 *   array(
	 *       'ok'         => bool,            // true on 2xx
	 *       'http_code'  => int,             // 0 on network error
	 *       'body'       => array,           // decoded JSON body, [] on parse fail
	 *       'error_code' => string|null,     // Razorpay code (e.g. BAD_REQUEST_ERROR)
	 *       'error_desc' => string|null,     // Razorpay description text
	 *       'attempts'   => int,             // how many HTTP calls were made
	 *       'latency_ms' => int,             // total elapsed milliseconds
	 *   )
	 */

	const RAZORPAY_API_BASE          = 'https://api.razorpay.com/v1';
	const HTTP_RETRY_MAX             = 3;
	const HTTP_RETRY_BACKOFF_BASE_MS = 500;
	const HTTP_SLOW_THRESHOLD_MS     = 2000;

	/**
	 * Issue a request to the Razorpay REST API. Always returns a structured
	 * array — never throws, never returns false/null.
	 *
	 * @param string      $method          HTTP method: GET, POST, PUT, DELETE.
	 * @param string      $path            Path under /v1/, with or without leading slash.
	 * @param array|null  $body            Body to JSON-encode. Ignored for GET.
	 * @param string|null $idempotency_key Optional idempotency key.
	 * @return array Result shape (see class docblock above).
	 */
	private static function razorpay_request( $method, $path, $body = null, $idempotency_key = null ) {
		$start = microtime( true );

		$key_id     = self::razorpay_key_id();
		$key_secret = self::razorpay_key_secret();

		if ( '' === $key_id || '' === $key_secret ) {
			return self::http_failure_result( 'hnc_not_configured', 'Razorpay credentials are not configured for the active mode.', 0, $start, 0 );
		}

		$method = strtoupper( (string) $method );
		$path   = '/' . ltrim( (string) $path, '/' );
		$url    = self::RAZORPAY_API_BASE . $path;

		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $key_id . ':' . $key_secret ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'User-Agent'    => 'HelpNagalandCore/' . ( defined( 'HNC_VERSION' ) ? HNC_VERSION : '0' ) . ' (+https://helpnagaland.com)',
		);
		if ( null !== $idempotency_key && '' !== (string) $idempotency_key ) {
			// Razorpay accepts X-Razorpay-Idempotency on some endpoints. On
			// endpoints that ignore it, the header is harmless. Server-side
			// idempotency in this plugin lives on the donations.idempotency_key
			// UNIQUE column — this header is defence-in-depth.
			$headers['X-Razorpay-Idempotency'] = (string) $idempotency_key;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => self::request_timeout(),
			'redirection' => 0,
			'sslverify'   => true,
		);

		if ( null !== $body && 'GET' !== $method ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return self::http_failure_result( 'hnc_encode_failed', 'Failed to JSON-encode request body.', 0, $start, 0 );
			}
			$args['body'] = $encoded;
		}

		$attempts      = 0;
		$last_response = null;
		$last_code     = 0;

		for ( $i = 0; $i < self::HTTP_RETRY_MAX; $i++ ) {
			$attempts++;
			$response      = wp_remote_request( $url, $args );
			$last_response = $response;

			if ( is_wp_error( $response ) ) {
				// Network-level failure (DNS, TCP, TLS, timeout). Retry with
				// backoff if we have attempts remaining.
				if ( $i < self::HTTP_RETRY_MAX - 1 ) {
					self::retry_sleep( $i );
					continue;
				}
				$err_msg = $response->get_error_message();
				return self::http_failure_result( 'hnc_network_error', $err_msg, 0, $start, $attempts );
			}

			$last_code = (int) wp_remote_retrieve_response_code( $response );

			// Retry on 5xx only. 4xx means we sent something wrong — retrying
			// won't help, and would waste time.
			if ( $last_code >= 500 && $last_code < 600 && $i < self::HTTP_RETRY_MAX - 1 ) {
				self::retry_sleep( $i );
				continue;
			}

			break;
		}

		$latency  = (int) ( ( microtime( true ) - $start ) * 1000 );
		$raw_body = (string) wp_remote_retrieve_body( $last_response );
		$decoded  = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$ok = ( $last_code >= 200 && $last_code < 300 );

		$error_code = null;
		$error_desc = null;
		if ( ! $ok && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$error_code = isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : null;
			$error_desc = isset( $decoded['error']['description'] ) ? (string) $decoded['error']['description'] : null;
		}

		// Slow-request log — useful for spotting Razorpay performance drift.
		if ( $latency > self::HTTP_SLOW_THRESHOLD_MS && class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				'payment_http_slow',
				array(
					'method'     => $method,
					'path'       => $path,
					'http_code'  => $last_code,
					'latency_ms' => $latency,
					'attempts'   => $attempts,
				)
			);
		}

		// Error log — does NOT include request body (could leak donor PII).
		if ( ! $ok && class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				'payment_http_error',
				array(
					'method'     => $method,
					'path'       => $path,
					'http_code'  => $last_code,
					'error_code' => $error_code,
					'attempts'   => $attempts,
					'latency_ms' => $latency,
				)
			);
		}

		return array(
			'ok'         => $ok,
			'http_code'  => $last_code,
			'body'       => $decoded,
			'error_code' => $error_code,
			'error_desc' => $error_desc,
			'attempts'   => $attempts,
			'latency_ms' => $latency,
		);
	}

	/**
	 * Convenience wrapper: GET.
	 *
	 * @param string $path Path under /v1/.
	 * @return array
	 */
	public static function razorpay_get( $path ) {
		return self::razorpay_request( 'GET', $path, null, null );
	}

	/**
	 * Convenience wrapper: POST.
	 *
	 * @param string      $path            Path under /v1/.
	 * @param array       $body            Body, will be JSON-encoded.
	 * @param string|null $idempotency_key Optional idempotency key.
	 * @return array
	 */
	public static function razorpay_post( $path, $body, $idempotency_key = null ) {
		return self::razorpay_request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * Convenience wrapper: DELETE. Razorpay uses POST /<resource>/<id>/cancel
	 * for most cancellations, so this is rarely needed — kept for completeness.
	 *
	 * @param string $path Path under /v1/.
	 * @return array
	 */
	public static function razorpay_delete( $path ) {
		return self::razorpay_request( 'DELETE', $path, null, null );
	}

	/**
	 * Sleep for the i'th retry. Exponential: 500ms, 1000ms, 2000ms.
	 *
	 * @param int $i Zero-based retry index.
	 * @return void
	 */
	private static function retry_sleep( $i ) {
		$ms = self::HTTP_RETRY_BACKOFF_BASE_MS * (int) pow( 2, max( 0, (int) $i ) );
		// Cap at 5 seconds so we don't blow past the request_timeout.
		$ms = min( $ms, 5000 );
		usleep( $ms * 1000 );
	}

	/**
	 * Build a structured failure result.
	 *
	 * @param string $code      Internal error code.
	 * @param string $desc      Human description.
	 * @param int    $http_code HTTP status (0 if not reached).
	 * @param float  $start     Microtime of request start.
	 * @param int    $attempts  Number of attempts made.
	 * @return array
	 */
	private static function http_failure_result( $code, $desc, $http_code, $start, $attempts ) {
		return array(
			'ok'         => false,
			'http_code'  => (int) $http_code,
			'body'       => array(),
			'error_code' => (string) $code,
			'error_desc' => (string) $desc,
			'attempts'   => (int) $attempts,
			'latency_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
		);
	}


	/* ==================================================================
	 * SIGNATURE VERIFICATION
	 * ==================================================================
	 *
	 * Two flavours, both HMAC-SHA256 with hash_equals (constant-time).
	 *
	 * 1) Webhook signature
	 *      Razorpay sends X-Razorpay-Signature on the webhook POST.
	 *      Verified against the RAW request body using webhook_secret.
	 *
	 * 2) Payment-completion signature (frontend to /payment/verify)
	 *      One-time:     HMAC of "order_id|payment_id"      using key_secret
	 *      Subscription: HMAC of "payment_id|subscription_id" using key_secret
	 *
	 *      Note the reversed argument order for subscriptions — this is a
	 *      Razorpay quirk. Getting it wrong is a common integration bug.
	 */

	/**
	 * Verify the webhook signature against the raw request body.
	 *
	 * @param string $raw_body     Raw request body (php://input bytes).
	 * @param string $received_sig Value of X-Razorpay-Signature header.
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $received_sig ) {
		$secret = self::razorpay_webhook_secret();
		if ( '' === $secret ) {
			return false;
		}
		$received_sig = (string) $received_sig;
		if ( '' === $received_sig || '' === (string) $raw_body ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $raw_body, $secret );
		return hash_equals( $expected, $received_sig );
	}

	/**
	 * Verify the checkout-completion signature for a one-time payment.
	 * Razorpay payload format: HMAC of "{order_id}|{payment_id}".
	 *
	 * @param string $order_id     razorpay_order_id from checkout response.
	 * @param string $payment_id   razorpay_payment_id from checkout response.
	 * @param string $received_sig razorpay_signature from checkout response.
	 * @return bool
	 */
	public static function verify_payment_signature_onetime( $order_id, $payment_id, $received_sig ) {
		$secret = self::razorpay_key_secret();
		if ( '' === $secret ) {
			return false;
		}
		$order_id     = (string) $order_id;
		$payment_id   = (string) $payment_id;
		$received_sig = (string) $received_sig;
		if ( '' === $order_id || '' === $payment_id || '' === $received_sig ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $order_id . '|' . $payment_id, $secret );
		return hash_equals( $expected, $received_sig );
	}

	/**
	 * Verify the checkout-completion signature for a subscription payment.
	 * Razorpay payload format: HMAC of "{payment_id}|{subscription_id}".
	 * Note the order — payment_id comes FIRST for subscriptions.
	 *
	 * @param string $payment_id      razorpay_payment_id.
	 * @param string $subscription_id razorpay_subscription_id.
	 * @param string $received_sig    razorpay_signature.
	 * @return bool
	 */
	public static function verify_payment_signature_subscription( $payment_id, $subscription_id, $received_sig ) {
		$secret = self::razorpay_key_secret();
		if ( '' === $secret ) {
			return false;
		}
		$payment_id      = (string) $payment_id;
		$subscription_id = (string) $subscription_id;
		$received_sig    = (string) $received_sig;
		if ( '' === $payment_id || '' === $subscription_id || '' === $received_sig ) {
			return false;
		}
		// Razorpay quirk: payment_id|subscription_id (reversed vs orders).
		$expected = hash_hmac( 'sha256', $payment_id . '|' . $subscription_id, $secret );
		return hash_equals( $expected, $received_sig );
	}


	/* ==================================================================
	 * DB WRITE HELPERS
	 * ==================================================================
	 *
	 * All DB access for the donations + events + customers tables routes
	 * through these helpers. Direct $wpdb usage from REST handlers and
	 * webhook handlers is forbidden.
	 *
	 * Conventions:
	 *   - All write methods are idempotent where possible (UNIQUE keys
	 *     handle dedup at the DB layer).
	 *   - All read methods return associative arrays (ARRAY_A) for
	 *     consistency with $wpdb->insert/update.
	 *   - Column names that touch user input are whitelisted before being
	 *     interpolated into SQL.
	 */

	const EVENT_OUTCOME_PROCESSED = 'processed';
	const EVENT_OUTCOME_SKIPPED   = 'skipped';
	const EVENT_OUTCOME_DUPLICATE = 'duplicate';
	const EVENT_OUTCOME_SIG_FAIL  = 'sig_fail';
	const EVENT_OUTCOME_ERROR     = 'error';
	const EVENT_OUTCOME_UNHANDLED = 'unhandled';

	/**
	 * Authoritative map of donations columns to their wpdb format codes.
	 * Only columns listed here can be written via insert_donation /
	 * update_donation. Anything else passed by callers is silently dropped
	 * (defence against typo'd column names ending up in WHERE clauses).
	 *
	 * @return array<string,string>
	 */
	private static function donation_column_formats() {
		return array(
			'public_code'              => '%s',
			'receipt_no'               => '%s',
			'provider'                 => '%s',
			'type'                     => '%s',
			'frequency'                => '%s',
			'status'                   => '%s',
			'amount_paise'             => '%d',
			'currency'                 => '%s',
			'amount_paid_paise'        => '%d',
			'amount_refunded_paise'    => '%d',
			'charge_count'             => '%d',
			'provider_order_id'        => '%s',
			'provider_payment_id'      => '%s',
			'provider_subscription_id' => '%s',
			'provider_plan_id'         => '%s',
			'provider_customer_id'     => '%s',
			'customer_id'              => '%d',
			'donor_name'               => '%s',
			'donor_email'              => '%s',
			'donor_email_hash'         => '%s',
			'donor_phone_e164'         => '%s',
			'donor_anonymous'          => '%d',
			'ip_hash'                  => '%s',
			'user_agent'               => '%s',
			'idempotency_key'          => '%s',
			'notes'                    => '%s',
			'created_at'               => '%s',
			'updated_at'               => '%s',
			'captured_at'              => '%s',
			'cancelled_at'             => '%s',
			'pii_purged_at'            => '%s',
		);
	}

	/**
	 * Insert a donation row. Auto-fills public_code, created_at, updated_at
	 * if not provided. Returns the inserted row id, or 0 on failure.
	 *
	 * @param array $data Column => value map. Unknown columns are dropped.
	 * @return int
	 */
	public static function insert_donation( $data ) {
		global $wpdb;

		$allowed = self::donation_column_formats();
		$now     = current_time( 'mysql', true );

		// Defaults — caller's $data always wins.
		$defaults = array(
			'created_at' => $now,
			'updated_at' => $now,
			'provider'   => self::PROVIDER_RAZORPAY,
			'currency'   => self::currency(),
			'status'     => self::STATUS_CREATED,
		);
		if ( ! isset( $data['public_code'] ) || '' === (string) $data['public_code'] ) {
			$defaults['public_code'] = self::generate_public_code();
		}

		$merged = array_merge( $defaults, $data );

		$row     = array();
		$formats = array();
		foreach ( $merged as $col => $val ) {
			if ( ! isset( $allowed[ $col ] ) ) {
				continue;
			}
			$row[ $col ] = $val;
			$formats[]   = $allowed[ $col ];
		}

		if ( empty( $row ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( self::table_donations(), $row, $formats );
		if ( false === $result ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update specific columns on a donation row. updated_at is auto-set.
	 *
	 * @param int   $id      Donation row id.
	 * @param array $changes Column => value map. Unknown columns dropped.
	 * @return bool True if the UPDATE statement ran without error.
	 */
	public static function update_donation( $id, $changes ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 || empty( $changes ) || ! is_array( $changes ) ) {
			return false;
		}

		$allowed = self::donation_column_formats();

		if ( ! isset( $changes['updated_at'] ) ) {
			$changes['updated_at'] = current_time( 'mysql', true );
		}

		$row     = array();
		$formats = array();
		foreach ( $changes as $col => $val ) {
			if ( ! isset( $allowed[ $col ] ) ) {
				continue;
			}
			$row[ $col ] = $val;
			$formats[]   = $allowed[ $col ];
		}

		if ( empty( $row ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::table_donations(),
			$row,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Fetch a donation row by id.
	 *
	 * @param int $id Donation row id.
	 * @return array|null Associative row, or null if not found.
	 */
	public static function get_donation( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table_donations();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a donation by a non-id lookup column. Field names are whitelisted
	 * to prevent unsafe interpolation.
	 *
	 * @param string $field Column name. Must be in the allowed list.
	 * @param string $value Value to match.
	 * @return array|null
	 */
	public static function find_donation_by( $field, $value ) {
		global $wpdb;

		$allowed_fields = array(
			'provider_order_id',
			'provider_payment_id',
			'provider_subscription_id',
			'idempotency_key',
			'public_code',
			'receipt_no',
			'donor_email_hash',
		);
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			return null;
		}
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}

		$table = self::table_donations();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %s LIMIT 1", $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record a webhook (or admin-triggered) event in the events ledger.
	 * Idempotent: a duplicate (provider, provider_event_id) returns 0
	 * without error. Callers should treat 0 as "skip processing".
	 *
	 * @param array $data Event row data:
	 *                    - donation_id (int|null)
	 *                    - provider (string)
	 *                    - provider_event_id (string, REQUIRED)
	 *                    - event_type (string, REQUIRED)
	 *                    - provider_object_type (string|null)
	 *                    - provider_object_id (string|null)
	 *                    - signature_ok (int 0/1)
	 *                    - ip_hash (string|null)
	 * @return int Inserted row id, or 0 if duplicate / failure.
	 */
	public static function record_event( $data ) {
		global $wpdb;

		if ( empty( $data['provider_event_id'] ) || empty( $data['event_type'] ) ) {
			return 0;
		}

		$provider          = isset( $data['provider'] ) ? (string) $data['provider'] : self::PROVIDER_RAZORPAY;
		$provider_event_id = (string) $data['provider_event_id'];

		// Cheap dedup check before INSERT — avoids relying on AUTO_INCREMENT
		// gaps from failed inserts.
		$existing = self::find_event_by_provider_id( $provider, $provider_event_id );
		if ( $existing ) {
			return 0;
		}

		$insert = array(
			'donation_id'          => isset( $data['donation_id'] ) && $data['donation_id'] > 0 ? (int) $data['donation_id'] : null,
			'provider'             => $provider,
			'provider_event_id'    => substr( $provider_event_id, 0, 80 ),
			'event_type'           => substr( (string) $data['event_type'], 0, 60 ),
			'provider_object_type' => isset( $data['provider_object_type'] ) && '' !== (string) $data['provider_object_type'] ? substr( (string) $data['provider_object_type'], 0, 20 ) : null,
			'provider_object_id'   => isset( $data['provider_object_id'] ) && '' !== (string) $data['provider_object_id'] ? substr( (string) $data['provider_object_id'], 0, 60 ) : null,
			'signature_ok'         => ! empty( $data['signature_ok'] ) ? 1 : 0,
			'ip_hash'              => isset( $data['ip_hash'] ) && '' !== (string) $data['ip_hash'] ? (string) $data['ip_hash'] : null,
			'received_at'          => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			self::table_events(),
			$insert,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $result ) {
			// Race: another request inserted the same event between our check
			// and INSERT. Treat as duplicate.
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark an event row as processed and record the outcome.
	 *
	 * @param int    $event_id Event row id.
	 * @param string $outcome  One of EVENT_OUTCOME_* constants.
	 * @param array  $detail   Optional structured detail; will be JSON-encoded.
	 * @return bool
	 */
	public static function mark_event_processed( $event_id, $outcome, $detail = array() ) {
		global $wpdb;
		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			return false;
		}

		$detail_json = null;
		if ( ! empty( $detail ) && is_array( $detail ) ) {
			$encoded = wp_json_encode( $detail );
			if ( is_string( $encoded ) ) {
				$detail_json = substr( $encoded, 0, 65000 );
			}
		}

		$row = array(
			'processed_at'   => current_time( 'mysql', true ),
			'outcome'        => substr( (string) $outcome, 0, 30 ),
			'outcome_detail' => $detail_json,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::table_events(),
			$row,
			array( 'id' => $event_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Find an event row by provider + provider_event_id. Used by record_event
	 * for dedup, and by webhook handlers needing context.
	 *
	 * @param string $provider          Provider name (default 'razorpay').
	 * @param string $provider_event_id Provider's event id.
	 * @return array|null
	 */
	public static function find_event_by_provider_id( $provider, $provider_event_id ) {
		global $wpdb;
		$provider          = (string) $provider;
		$provider_event_id = (string) $provider_event_id;
		if ( '' === $provider_event_id ) {
			return null;
		}
		$table = self::table_events();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE provider = %s AND provider_event_id = %s LIMIT 1",
			$provider,
			$provider_event_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Generate a unique public-facing donation code, e.g. "HD-A8K2X9YF".
	 * 11 chars total — fits comfortably in the public_code VARCHAR(20) column
	 * and the UNIQUE KEY catches the vanishingly rare collision.
	 *
	 * @return string
	 */
	public static function generate_public_code() {
		return 'HD-' . strtoupper( wp_generate_password( 8, false, false ) );
	}


	/* ==================================================================
	 * CUSTOMER MANAGEMENT
	 * ==================================================================
	 *
	 * ensure_customer() resolves an (email, name, phone) tuple into a
	 * Razorpay customer_id, caching the result in the payment_customers
	 * table keyed on email_hash. Subsequent donations from the same donor
	 * skip the Razorpay round-trip.
	 *
	 * Returned shape:
	 *   array(
	 *       'local_id'             => int,    // payment_customers.id
	 *       'provider_customer_id' => string, // Razorpay cust_xxx id
	 *       'is_new'               => bool,   // true if just created
	 *   )
	 * or null on failure (caller should fall back to anonymous donation).
	 */

	/**
	 * Resolve an (email, name, phone) tuple to a Razorpay customer record.
	 *
	 * @param string $email Donor email (will be sanitised + validated).
	 * @param string $name  Donor name (optional).
	 * @param string $phone Donor phone (optional, in any common format).
	 * @return array|null
	 */
	public static function ensure_customer( $email, $name = '', $phone = '' ) {
		$email = self::sanitize_donor_email( $email );
		if ( '' === $email ) {
			return null;
		}

		$email_hash = self::hash_email( $email );
		if ( '' === $email_hash ) {
			return null;
		}

		// 1. Cache hit?
		$existing = self::find_customer_by_email_hash( $email_hash );
		if ( $existing && ! empty( $existing['provider_customer_id'] ) ) {
			return array(
				'local_id'             => (int) $existing['id'],
				'provider_customer_id' => (string) $existing['provider_customer_id'],
				'is_new'               => false,
			);
		}

		// 2. Create at Razorpay.
		$name_clean  = self::sanitize_donor_name( $name );
		$phone_clean = self::sanitize_phone_e164( $phone );

		$payload = array(
			'email'         => $email,
			'fail_existing' => 0, // returns existing customer instead of erroring
		);
		if ( '' !== $name_clean ) {
			$payload['name'] = $name_clean;
		}
		if ( '' !== $phone_clean ) {
			$payload['contact'] = $phone_clean;
		}
		$payload['notes'] = array(
			'platform' => 'helpnagaland.com',
		);

		// Idempotency key: deterministic from email_hash so concurrent
		// requests for the same donor coalesce.
		$idempotency = 'cust_' . substr( $email_hash, 0, 32 );

		$result = self::razorpay_post( '/customers', $payload, $idempotency );
		if ( ! $result['ok'] ) {
			return null;
		}

		$rzp_customer_id = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		if ( '' === $rzp_customer_id ) {
			return null;
		}

		// 3. Persist locally.
		$local_id = self::upsert_customer(
			array(
				'provider_customer_id' => $rzp_customer_id,
				'email'                => $email,
				'email_hash'           => $email_hash,
				'name'                 => $name_clean,
				'phone_e164'           => '' !== $phone_clean ? $phone_clean : null,
			)
		);

		return array(
			'local_id'             => $local_id,
			'provider_customer_id' => $rzp_customer_id,
			'is_new'               => true,
		);
	}

	/**
	 * Sanitise a phone number to E.164 format. Delegates to HNC_Sanitizer
	 * if available (which has Indian-specific rules), otherwise applies a
	 * minimal fallback.
	 *
	 * @param string $raw Raw input.
	 * @return string E.164 string like "+919876543210", or '' if invalid.
	 */
	public static function sanitize_phone_e164( $raw ) {
		if ( class_exists( 'HNC_Sanitizer' ) && method_exists( 'HNC_Sanitizer', 'phone_number' ) ) {
			return (string) HNC_Sanitizer::phone_number( $raw );
		}
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
			return '';
		}
		return '+' . $digits;
	}

	/**
	 * Find a customer row by email_hash.
	 *
	 * @param string $email_hash 64-char SHA-256 hex.
	 * @return array|null
	 */
	private static function find_customer_by_email_hash( $email_hash ) {
		global $wpdb;
		$email_hash = (string) $email_hash;
		if ( '' === $email_hash ) {
			return null;
		}
		$table = self::table_customers();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s LIMIT 1", $email_hash );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a customer row, or update missing fields on the existing row.
	 * Race-safe: if a concurrent insert wins, we fall back to UPDATE.
	 *
	 * @param array $data Customer data.
	 * @return int Local row id, or 0 on failure.
	 */
	private static function upsert_customer( $data ) {
		global $wpdb;

		$existing = self::find_customer_by_email_hash( $data['email_hash'] );
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			// Fill in any fields that were previously empty.
			$changes = array( 'updated_at' => $now );
			foreach ( array( 'provider_customer_id', 'email', 'name', 'phone_e164' ) as $col ) {
				if ( ! empty( $data[ $col ] ) && empty( $existing[ $col ] ) ) {
					$changes[ $col ] = $data[ $col ];
				}
			}
			if ( count( $changes ) > 1 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					self::table_customers(),
					$changes,
					array( 'id' => (int) $existing['id'] )
				);
			}
			return (int) $existing['id'];
		}

		$insert = array(
			'provider'             => self::PROVIDER_RAZORPAY,
			'provider_customer_id' => isset( $data['provider_customer_id'] ) ? (string) $data['provider_customer_id'] : '',
			'email'                => isset( $data['email'] ) && '' !== (string) $data['email'] ? (string) $data['email'] : null,
			'email_hash'           => (string) $data['email_hash'],
			'name'                 => isset( $data['name'] ) && '' !== (string) $data['name'] ? (string) $data['name'] : null,
			'phone_e164'           => isset( $data['phone_e164'] ) && '' !== (string) $data['phone_e164'] ? (string) $data['phone_e164'] : null,
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			self::table_customers(),
			$insert,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $result ) {
			// Race lost — find the winner.
			$existing = self::find_customer_by_email_hash( $data['email_hash'] );
			return $existing ? (int) $existing['id'] : 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a customer row by local id. Used by admin endpoints.
	 *
	 * @param int $id Local customer id.
	 * @return array|null
	 */
	public static function get_customer( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table_customers();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}


	/* ==================================================================
	 * PLAN MANAGEMENT
	 * ==================================================================
	 *
	 * Razorpay subscriptions require a Plan resource (period + interval +
	 * amount). We create one plan per (mode, frequency, amount, currency)
	 * combination and cache the plan_id in a wp_option so subsequent
	 * subscriptions reuse it.
	 *
	 * Frequency mapping:
	 *   weekly     -> period=weekly,  interval=1,  total_count=520 (10 yrs)
	 *   monthly    -> period=monthly, interval=1,  total_count=120 (10 yrs)
	 *   halfyearly -> period=monthly, interval=6,  total_count=20  (10 yrs)
	 *   yearly     -> period=yearly,  interval=1,  total_count=10  (10 yrs)
	 *
	 * total_count is required by Razorpay. The 10-year horizon balances
	 * "effectively forever" against accidental indefinite billing if the
	 * platform ever shuts down. Donors can cancel at any time.
	 */

	/**
	 * Resolve a (frequency, amount_paise, currency) to a Razorpay plan id.
	 * Creates the plan at Razorpay if not already cached.
	 *
	 * @param string $frequency    One of FREQ_WEEKLY|FREQ_MONTHLY|FREQ_HALFYEARLY|FREQ_YEARLY.
	 * @param int    $amount_paise Amount per charge, in paise.
	 * @param string $currency     ISO currency code. Defaults to configured currency.
	 * @return string|null Razorpay plan id, or null on failure.
	 */
	public static function ensure_plan( $frequency, $amount_paise, $currency = '' ) {
		$frequency    = self::sanitize_frequency( $frequency );
		$amount_paise = (int) $amount_paise;
		if ( '' === $currency ) {
			$currency = self::currency();
		}
		$currency = self::sanitize_currency( $currency );

		if ( '' === $frequency || self::FREQ_ONCE === $frequency ) {
			return null;
		}
		if ( ! self::is_valid_amount( $amount_paise ) ) {
			return null;
		}
		if ( '' === $currency ) {
			return null;
		}

		$params = self::frequency_to_plan_params( $frequency );
		if ( ! $params ) {
			return null;
		}

		$mode      = self::is_live_mode() ? 'live' : 'test';
		$cache_key = "{$mode}|{$frequency}|{$amount_paise}|{$currency}";
		$cache     = get_option( 'hnc_payment_plan_cache', array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		if ( isset( $cache[ $cache_key ] ) && '' !== (string) $cache[ $cache_key ] ) {
			return (string) $cache[ $cache_key ];
		}

		$payload = array(
			'period'   => $params['period'],
			'interval' => $params['interval'],
			'item'     => array(
				'name'        => 'Help Nagaland Premium Membership (' . ucfirst( $frequency ) . ')',
				'amount'      => $amount_paise,
				'currency'    => $currency,
				'description' => 'Recurring ' . $frequency . ' Premium Membership – helpnagaland.com',
			),
			'notes'    => array(
				'platform'  => 'helpnagaland.com',
				'frequency' => $frequency,
				'mode'      => $mode,
			),
		);

		$idempotency = 'plan_' . substr( hash( 'sha256', $cache_key ), 0, 48 );
		$result      = self::razorpay_post( '/plans', $payload, $idempotency );
		if ( ! $result['ok'] ) {
			return null;
		}

		$plan_id = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		if ( '' === $plan_id ) {
			return null;
		}

		$cache[ $cache_key ] = $plan_id;
		update_option( 'hnc_payment_plan_cache', $cache, false );

		return $plan_id;
	}

	/**
	 * Map a frequency constant to Razorpay plan parameters.
	 *
	 * @param string $frequency Frequency.
	 * @return array|null Array with period, interval, total_count keys, or null.
	 */
	private static function frequency_to_plan_params( $frequency ) {
		$map = array(
			self::FREQ_WEEKLY     => array(
				'period'      => 'weekly',
				'interval'    => 1,
				'total_count' => 520,
			),
			self::FREQ_MONTHLY    => array(
				'period'      => 'monthly',
				'interval'    => 1,
				'total_count' => 120,
			),
			self::FREQ_HALFYEARLY => array(
				'period'      => 'monthly',
				'interval'    => 6,
				'total_count' => 20,
			),
			self::FREQ_YEARLY     => array(
				'period'      => 'yearly',
				'interval'    => 1,
				'total_count' => 10,
			),
		);
		return isset( $map[ $frequency ] ) ? $map[ $frequency ] : null;
	}

	/**
	 * Public accessor for the total_count of a frequency. Used by
	 * /payment/subscription handler when creating the subscription.
	 *
	 * @param string $frequency Frequency.
	 * @return int 0 if not a recurring frequency.
	 */
	public static function plan_total_count( $frequency ) {
		$params = self::frequency_to_plan_params( $frequency );
		return $params ? (int) $params['total_count'] : 0;
	}


	/* ==================================================================
	 * RECEIPT NUMBER GENERATOR
	 * ==================================================================
	 *
	 * Format: HNC/YYYY-YY/000001
	 *   - HNC          : platform prefix
	 *   - YYYY-YY      : Indian fiscal year (April 1 to March 31)
	 *   - 000001       : zero-padded 6-digit sequence number, resets each FY
	 *
	 * The sequence is atomically incremented using MySQL's LAST_INSERT_ID()
	 * trick on the wp_options table, which is safe under concurrent inserts:
	 *
	 *   INSERT INTO wp_options (option_name, option_value, autoload)
	 *   VALUES (%s, LAST_INSERT_ID(1), 'no')
	 *   ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value+1)
	 *
	 * After this single statement, SELECT LAST_INSERT_ID() returns the new
	 * counter value for THIS connection, regardless of insert vs update path.
	 *
	 * IMPORTANT: only call next_receipt_number() when a donation has been
	 * confirmed captured. Calling it for failed/abandoned orders would leave
	 * gaps in the receipt sequence — unacceptable for 80G compliance.
	 */

	/**
	 * Reserve and return the next receipt number for the current fiscal year.
	 *
	 * @return string Receipt number, e.g. "HNC/2026-27/000042".
	 */
	public static function next_receipt_number() {
		list( $fy_start, $fy_end_short ) = self::current_fiscal_year();
		$option_key                      = sprintf( 'hnc_payment_receipt_counter_%d_%02d', $fy_start, $fy_end_short );
		$next                            = self::atomic_increment_option( $option_key );
		if ( $next < 1 ) {
			$next = 1;
		}
		return sprintf( 'HNC/%d-%02d/%06d', $fy_start, $fy_end_short, $next );
	}

	/**
	 * Compute the current Indian fiscal year (April 1 to March 31).
	 * Returned as ($start_year_4_digit, $end_year_2_digit).
	 *
	 * Examples:
	 *   2026-04-01 to 2027-03-31 -> array(2026, 27)
	 *   2027-01-15               -> array(2026, 27)  (still in FY 2026-27)
	 *   2027-04-01               -> array(2027, 28)
	 *
	 * @return array{0:int,1:int}
	 */
	public static function current_fiscal_year() {
		$ts    = current_time( 'timestamp', true ); // UTC
		$year  = (int) gmdate( 'Y', $ts );
		$month = (int) gmdate( 'n', $ts );
		if ( $month >= 4 ) {
			return array( $year, ( $year + 1 ) % 100 );
		}
		return array( $year - 1, $year % 100 );
	}

	/**
	 * Atomically increment an integer option value. Returns the new value.
	 *
	 * Implementation: single INSERT ... ON DUPLICATE KEY UPDATE statement
	 * on wp_options. The LAST_INSERT_ID(expr) trick stores the new counter
	 * value as the connection's last_insert_id so a subsequent SELECT
	 * retrieves it without a race window.
	 *
	 * @param string $option_name The option key to use as the counter.
	 * @return int New counter value (>= 1), or 0 on failure.
	 */
	private static function atomic_increment_option( $option_name ) {
		global $wpdb;
		$option_name = (string) $option_name;
		if ( '' === $option_name ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, LAST_INSERT_ID(1), 'no')
			 ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)",
			$option_name
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );

		// Bust WP options cache since we wrote directly via SQL.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		return $next > 0 ? $next : 0;
	}


	/* ==================================================================
	 * INTENT TOKEN
	 * ==================================================================
	 *
	 * Short-lived HMAC token issued by /payment/config and required on
	 * /payment/order and /payment/subscription. Prevents random scripts
	 * from creating orders against our Razorpay account without first
	 * loading the config endpoint.
	 *
	 * Format: "<iat>.<nonce>.<sig>" — three dot-separated segments,
	 * URL-safe (no base64), trivially parsed.
	 *
	 *   iat   = unix seconds when the token was issued
	 *   nonce = 16 hex chars from random_bytes(8)
	 *   sig   = HMAC-SHA256(iat.nonce, intent_secret)
	 *
	 * The intent secret is auto-generated on first use and stored as a
	 * (non-autoloaded) wp_option. It rotates only if the operator deletes
	 * the option manually.
	 */

	/**
	 * Issue a fresh intent token. TTL is filterable via intent_token_ttl().
	 *
	 * @return array{token:string, expires_in:int}
	 */
	public static function issue_intent_token() {
		$secret  = self::intent_token_secret();
		$iat     = time();
		$nonce   = bin2hex( random_bytes( 8 ) );
		$payload = $iat . '.' . $nonce;
		$sig     = hash_hmac( 'sha256', $payload, $secret );
		return array(
			'token'      => $payload . '.' . $sig,
			'expires_in' => self::intent_token_ttl(),
		);
	}

	/**
	 * Verify an intent token: format, signature, and freshness.
	 *
	 * @param string $token Token value from the request body.
	 * @return bool
	 */
	public static function verify_intent_token( $token ) {
		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > 200 ) {
			return false;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		list( $iat_str, $nonce, $sig ) = $parts;
		if ( ! ctype_digit( $iat_str ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-f0-9]+$/', $nonce ) || ! preg_match( '/^[a-f0-9]+$/', $sig ) ) {
			return false;
		}
		$iat = (int) $iat_str;
		$now = time();
		// Reject tokens claiming to be from more than 60s in the future
		// (clock skew tolerance) — guards against forged future-dated tokens.
		if ( $iat > $now + 60 ) {
			return false;
		}
		if ( $iat + self::intent_token_ttl() < $now ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $iat_str . '.' . $nonce, self::intent_token_secret() );
		return hash_equals( $expected, $sig );
	}

	/**
	 * Lazily-created server-side secret for signing intent tokens. NOT
	 * autoloaded — only read by /payment/config, /payment/order,
	 * /payment/subscription handlers.
	 *
	 * @return string
	 */
	private static function intent_token_secret() {
		$secret = (string) get_option( 'hnc_payment_intent_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'hnc_payment_intent_secret', $secret, false );
		}
		return $secret;
	}


	/* ==================================================================
	 * REST HANDLER HELPERS
	 * ==================================================================
	 *
	 * Small utilities used across the REST handlers above. Kept in their
	 * own section so the handler bodies stay focused on the donation flow.
	 */

	/**
	 * Read JSON body params from the request, with sensible fallbacks.
	 * Prefers JSON-decoded body (Content-Type: application/json), then
	 * falls back to form-encoded params.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private static function parse_request_json( $request ) {
		$json = $request->get_json_params();
		if ( is_array( $json ) ) {
			return $json;
		}
		$body_params = $request->get_body_params();
		if ( is_array( $body_params ) && ! empty( $body_params ) ) {
			return $body_params;
		}
		// Last-resort: try decoding the raw body in case Content-Type was
		// missing or generic.
		$raw = (string) $request->get_body();
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Sanitise a boolean-ish "anonymous" flag from the request body.
	 *
	 * @param mixed $raw Raw value (bool|int|string).
	 * @return int 0 or 1.
	 */
	public static function sanitize_anonymous_flag( $raw ) {
		if ( true === $raw || 1 === $raw ) {
			return 1;
		}
		if ( is_string( $raw ) ) {
			$lower = strtolower( trim( $raw ) );
			if ( in_array( $lower, array( '1', 'true', 'on', 'yes' ), true ) ) {
				return 1;
			}
		}
		return 0;
	}

	/**
	 * Sanitise a free-form notes field. Light touch — strips HTML and
	 * control chars, normalises whitespace, caps at 500 chars. Does NOT
	 * strip names/phones/emails; admin views show notes verbatim.
	 *
	 * @param string $raw
	 * @return string
	 */
	public static function sanitize_notes( $raw ) {
		$s = (string) $raw;
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$s = wp_strip_all_tags( $s, true );
		} else {
			$s = strip_tags( $s );
		}
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = trim( $s );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, 500, 'UTF-8' );
		}
		return substr( $s, 0, 500 );
	}

	/**
	 * Build (or accept and validate) an idempotency key for the request.
	 *
	 * Caller-supplied keys are sanitised and used verbatim. If the caller
	 * didn't supply one, we synthesise a deterministic key from request
	 * characteristics so that a double-clicked submission within the same
	 * minute coalesces into a single Razorpay order.
	 *
	 * @param string $supplied     Optional caller-supplied key.
	 * @param int    $amount_paise
	 * @param string $frequency
	 * @param string $email
	 * @return string Idempotency key (max 80 chars), or '' if nothing usable.
	 */
	private static function compute_idempotency_key( $supplied, $amount_paise, $frequency, $email ) {
		$supplied = trim( (string) $supplied );
		if ( '' !== $supplied ) {
			$clean = preg_replace( '/[^a-zA-Z0-9_\-:.]/', '', $supplied );
			if ( '' !== $clean ) {
				return 'cli_' . substr( $clean, 0, 76 );
			}
		}
		$ip      = self::client_ip();
		$minute  = (int) floor( time() / 60 );
		$email_h = '' !== $email ? substr( self::hash_email( $email ), 0, 8 ) : 'anon';
		$payload = $ip . '|' . $minute . '|' . (int) $amount_paise . '|' . $frequency . '|' . $email_h;
		return 'auto_' . substr( hash( 'sha256', $payload ), 0, 60 );
	}

	/**
	 * Read the User-Agent header safely (truncated to 255 chars to fit
	 * the donations.user_agent column).
	 *
	 * @param WP_REST_Request $request
	 * @return string
	 */
	private static function request_user_agent( $request ) {
		$ua = (string) $request->get_header( 'user_agent' );
		if ( '' === $ua && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
		}
		$ua = preg_replace( '/[\x00-\x1F\x7F]/u', '', $ua );
		return substr( $ua, 0, 255 );
	}

	/**
	 * Build the response body for /payment/order. Frontend hands this
	 * directly to Razorpay Checkout's options object.
	 *
	 * @param array $donation Donation row (associative).
	 * @return WP_REST_Response
	 */
	private static function build_order_response( $donation ) {
		return new WP_REST_Response(
			array(
				'ok'                => true,
				'donation_id'       => (int) $donation['id'],
				'public_code'       => (string) $donation['public_code'],
				'razorpay_key_id'   => self::razorpay_key_id(),
				'razorpay_order_id' => (string) $donation['provider_order_id'],
				'amount_paise'      => (int) $donation['amount_paise'],
				'currency'          => (string) $donation['currency'],
				'name'              => __( 'Help Nagaland — Premium Membership', 'helpnagaland-core' ),
				'description'       => __( 'Premium Membership – helpnagaland.com', 'helpnagaland-core' ),
				'prefill'           => array(
					'name'    => isset( $donation['donor_name'] ) ? (string) $donation['donor_name'] : '',
					'email'   => isset( $donation['donor_email'] ) ? (string) $donation['donor_email'] : '',
					'contact' => isset( $donation['donor_phone_e164'] ) ? (string) $donation['donor_phone_e164'] : '',
				),
				'notes'             => array(
					'public_code' => (string) $donation['public_code'],
				),
			),
			200
		);
	}

	/**
	 * Build the response body for /payment/subscription.
	 *
	 * @param array $donation Donation row (associative).
	 * @return WP_REST_Response
	 */
	private static function build_subscription_response( $donation ) {
		$frequency = isset( $donation['frequency'] ) ? (string) $donation['frequency'] : '';
		return new WP_REST_Response(
			array(
				'ok'                       => true,
				'donation_id'              => (int) $donation['id'],
				'public_code'              => (string) $donation['public_code'],
				'razorpay_key_id'          => self::razorpay_key_id(),
				'razorpay_subscription_id' => (string) $donation['provider_subscription_id'],
				'amount_paise'             => (int) $donation['amount_paise'],
				'currency'                 => (string) $donation['currency'],
				'frequency'                => $frequency,
				'name'                     => __( 'Help Nagaland — Premium Membership', 'helpnagaland-core' ),
				'description'              => sprintf(
					/* translators: %s: frequency label like "Monthly" */
					__( '%s Premium Membership – helpnagaland.com', 'helpnagaland-core' ),
					ucfirst( $frequency )
				),
				'prefill'                  => array(
					'name'    => isset( $donation['donor_name'] ) ? (string) $donation['donor_name'] : '',
					'email'   => isset( $donation['donor_email'] ) ? (string) $donation['donor_email'] : '',
					'contact' => isset( $donation['donor_phone_e164'] ) ? (string) $donation['donor_phone_e164'] : '',
				),
			),
			200
		);
	}


	/* ==================================================================
	 * WEBHOOK HELPERS
	 * ==================================================================
	 *
	 * The webhook handler does three pieces of work that benefit from
	 * being broken out:
	 *
	 *   - extract_webhook_object: pull (object_type, object_id) from the
	 *     payload regardless of which event fired
	 *   - find_donation_for_webhook: try multiple linkage paths to find
	 *     the donation row this event relates to
	 *   - dispatch_webhook_event: route the event to its type-specific
	 *     handler. Type handlers themselves are filled in Chunk 4.
	 */

	/**
	 * Extract (object_type, object_id) from a Razorpay webhook payload.
	 *
	 * Razorpay nests the relevant entity under payload.<type>.entity.
	 * For most events <type> is one of: payment, subscription, order, refund.
	 *
	 * @param array $payload Decoded webhook body.
	 * @return array{0:string|null, 1:string|null}
	 */
	private static function extract_webhook_object( $payload ) {
		if ( ! isset( $payload['payload'] ) || ! is_array( $payload['payload'] ) ) {
			return array( null, null );
		}
		$candidates = array( 'payment', 'subscription', 'order', 'refund' );
		foreach ( $candidates as $type ) {
			if ( isset( $payload['payload'][ $type ]['entity']['id'] ) ) {
				return array( $type, (string) $payload['payload'][ $type ]['entity']['id'] );
			}
		}
		return array( null, null );
	}

	/**
	 * Find the donation row that a webhook event relates to. Tries each
	 * available linkage path (object id direct, then via parent ids found
	 * inside the entity body).
	 *
	 * @param array       $payload     Decoded webhook body.
	 * @param string|null $object_type 'payment' | 'subscription' | 'order' | 'refund'.
	 * @param string|null $object_id   The object's Razorpay id.
	 * @return int|null Donation id, or null if no match.
	 */
	private static function find_donation_for_webhook( $payload, $object_type, $object_id ) {
		if ( null === $object_id || '' === $object_id ) {
			return null;
		}

		switch ( $object_type ) {
			case 'order':
				$d = self::find_donation_by( 'provider_order_id', $object_id );
				if ( $d ) {
					return (int) $d['id'];
				}
				break;

			case 'payment':
				$d = self::find_donation_by( 'provider_payment_id', $object_id );
				if ( $d ) {
					return (int) $d['id'];
				}
				// Payment events arrive before we've recorded payment_id locally.
				// Fall through via the entity's order_id / subscription_id.
				if ( isset( $payload['payload']['payment']['entity']['order_id'] ) ) {
					$d = self::find_donation_by( 'provider_order_id', (string) $payload['payload']['payment']['entity']['order_id'] );
					if ( $d ) {
						return (int) $d['id'];
					}
				}
				if ( isset( $payload['payload']['payment']['entity']['subscription_id'] ) ) {
					$d = self::find_donation_by( 'provider_subscription_id', (string) $payload['payload']['payment']['entity']['subscription_id'] );
					if ( $d ) {
						return (int) $d['id'];
					}
				}
				break;

			case 'subscription':
				$d = self::find_donation_by( 'provider_subscription_id', $object_id );
				if ( $d ) {
					return (int) $d['id'];
				}
				break;

			case 'refund':
				if ( isset( $payload['payload']['refund']['entity']['payment_id'] ) ) {
					$d = self::find_donation_by( 'provider_payment_id', (string) $payload['payload']['refund']['entity']['payment_id'] );
					if ( $d ) {
						return (int) $d['id'];
					}
				}
				break;
		}
		return null;
	}

	/**
	 * Route a webhook event to its handler. Returns array{outcome, detail}.
	 *
	 * Skips events outside the payment family (Razorpay sends product-launch
	 * pings and similar, depending on dashboard settings — ignoring keeps
	 * log noise down).
	 *
	 * Each handler is private and follows a strict contract:
	 *   - tolerates a null donation (we may receive an event for an object
	 *     that isn't tracked locally) and returns SKIPPED in that case
	 *   - never throws — exceptions are caught at the dispatcher and at
	 *     rest_webhook; even so, handlers should defensively guard array
	 *     access on the payload
	 *   - never rolls back terminal states (CANCELLED, COMPLETED, REFUNDED)
	 *     no matter what order webhooks arrive in
	 *   - logs via HNC_Logger and fires the relevant do_action() so site
	 *     code can react
	 *
	 * @param string   $event_type   e.g. 'payment.captured'
	 * @param array    $payload      Decoded webhook body
	 * @param int|null $donation_id  Linked donation id (or null)
	 * @param int      $event_row_id Row id in the events ledger
	 * @return array{outcome:string, detail:array}
	 */
	private static function dispatch_webhook_event( $event_type, $payload, $donation_id, $event_row_id ) {
		$family_ok = (
			0 === strpos( $event_type, 'payment.' ) ||
			0 === strpos( $event_type, 'subscription.' ) ||
			0 === strpos( $event_type, 'order.' ) ||
			0 === strpos( $event_type, 'refund.' )
		);
		if ( ! $family_ok ) {
			return array(
				'outcome' => self::EVENT_OUTCOME_SKIPPED,
				'detail'  => array( 'reason' => 'event_family_not_relevant' ),
			);
		}

		// Resolve the donation row once. Each handler tolerates null.
		$donation = $donation_id ? self::get_donation( (int) $donation_id ) : null;

		switch ( $event_type ) {
			case 'payment.authorized':
				return self::handle_payment_authorized( $payload, $donation );
			case 'payment.captured':
				return self::handle_payment_captured( $payload, $donation );
			case 'payment.failed':
				return self::handle_payment_failed( $payload, $donation );

			case 'order.paid':
				return self::handle_order_paid( $payload, $donation );

			case 'refund.created':
				return self::handle_refund_created( $payload, $donation );
			case 'refund.processed':
				return self::handle_refund_processed( $payload, $donation );
			case 'refund.failed':
				return self::handle_refund_failed( $payload, $donation );

			case 'subscription.authenticated':
				return self::handle_sub_authenticated( $payload, $donation );
			case 'subscription.activated':
				return self::handle_sub_activated( $payload, $donation );
			case 'subscription.charged':
				return self::handle_sub_charged( $payload, $donation );
			case 'subscription.pending':
				return self::handle_sub_pending( $payload, $donation );
			case 'subscription.halted':
				return self::handle_sub_halted( $payload, $donation );
			case 'subscription.cancelled':
				return self::handle_sub_cancelled( $payload, $donation );
			case 'subscription.completed':
				return self::handle_sub_completed( $payload, $donation );
			case 'subscription.paused':
				return self::handle_sub_paused( $payload, $donation );
			case 'subscription.resumed':
				return self::handle_sub_resumed( $payload, $donation );
			case 'subscription.updated':
				return self::handle_sub_updated( $payload, $donation );
		}

		// In-family but not in our handler map (e.g. an event Razorpay
		// adds in a future API version). Recorded with full provenance —
		// the reconciliation cron can replay if a handler is added later.
		return array(
			'outcome' => self::EVENT_OUTCOME_UNHANDLED,
			'detail'  => array( 'event_type' => $event_type ),
		);
	}


	/* ==================================================================
	 * WEBHOOK EVENT HANDLERS
	 * ==================================================================
	 *
	 * Seventeen private handlers, one per Razorpay event type we subscribe
	 * to. Each returns array{outcome:string, detail:array} for the events
	 * ledger.
	 *
	 * Status-machine guards:
	 *   - We never roll back from CANCELLED, COMPLETED, REFUNDED.
	 *   - Receipt numbers are reserved at first-success only (one-time
	 *     capture, or first subscription charge), and never re-issued.
	 *   - Subscription per-charge events update charge_count / amount_paid
	 *     atomically by reading the row inside the same handler call.
	 */

	/**
	 * payment.authorized — money is held but not yet captured.
	 * One-time: status -> AUTHORIZED. Subscription: just track payment_id.
	 */
	private static function handle_payment_authorized( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity     = isset( $payload['payload']['payment']['entity'] ) ? $payload['payload']['payment']['entity'] : array();
		$payment_id = isset( $entity['id'] ) ? (string) $entity['id'] : '';

		$changes = array();
		if ( '' !== $payment_id ) {
			$changes['provider_payment_id'] = $payment_id;
		}
		// Advance one-time donations to AUTHORIZED, but never roll back from CAPTURED+.
		$do_not_advance = array( self::STATUS_CAPTURED, self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED, self::STATUS_FAILED );
		if ( self::TYPE_ONETIME === $donation['type'] && ! in_array( (string) $donation['status'], $do_not_advance, true ) ) {
			$changes['status'] = self::STATUS_AUTHORIZED;
		}

		if ( ! empty( $changes ) ) {
			self::update_donation( (int) $donation['id'], $changes );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_PAYMENT_AUTHORIZED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'payment_id'  => $payment_id,
					'type'        => $donation['type'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED, 'detail' => array( 'payment_id' => $payment_id ) );
	}

	/**
	 * payment.captured — terminal success for one-time donations.
	 * For subscriptions, the per-charge state is owned by subscription.charged;
	 * here we just record the payment_id so refunds can find it.
	 */
	private static function handle_payment_captured( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity      = isset( $payload['payload']['payment']['entity'] ) ? $payload['payload']['payment']['entity'] : array();
		$payment_id  = isset( $entity['id'] ) ? (string) $entity['id'] : '';
		$amount_paid = isset( $entity['amount'] ) ? (int) $entity['amount'] : 0;

		// Subscriptions: defer state to subscription.charged. Just track payment_id.
		if ( self::TYPE_SUBSCRIPTION === $donation['type'] ) {
			if ( '' !== $payment_id ) {
				self::update_donation( (int) $donation['id'], array( 'provider_payment_id' => $payment_id ) );
			}
			return array(
				'outcome' => self::EVENT_OUTCOME_PROCESSED,
				'detail'  => array( 'note' => 'subscription_payment_recorded', 'payment_id' => $payment_id ),
			);
		}

		// One-time: terminal success.
		$changes = array(
			'status'              => self::STATUS_CAPTURED,
			'provider_payment_id' => $payment_id,
			'amount_paid_paise'   => $amount_paid,
			'captured_at'         => current_time( 'mysql', true ),
			'charge_count'        => 1,
		);
		// Reserve a receipt number iff we don't already have one.
		if ( empty( $donation['receipt_no'] ) ) {
			$changes['receipt_no'] = self::next_receipt_number();
		}

		self::update_donation( (int) $donation['id'], $changes );

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_PAYMENT_CAPTURED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'amount'      => $amount_paid,
					'receipt_no'  => isset( $changes['receipt_no'] ) ? $changes['receipt_no'] : $donation['receipt_no'],
				)
			);
		}

		do_action( 'hnc_payment_donation_captured', (int) $donation['id'], self::get_donation( (int) $donation['id'] ) );

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'payment_id' => $payment_id, 'amount' => $amount_paid ),
		);
	}

	/**
	 * payment.failed — one-time terminal failure, or per-charge subscription
	 * failure (Razorpay retries the charge per its retry policy).
	 */
	private static function handle_payment_failed( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity     = isset( $payload['payload']['payment']['entity'] ) ? $payload['payload']['payment']['entity'] : array();
		$payment_id = isset( $entity['id'] ) ? (string) $entity['id'] : '';
		$reason     = isset( $entity['error_description'] ) ? (string) $entity['error_description'] : 'unknown';
		$err_code   = isset( $entity['error_code'] ) ? (string) $entity['error_code'] : '';

		// Subscription per-charge failure: don't update donation status —
		// subscription.halted will fire if all retries exhaust.
		if ( self::TYPE_SUBSCRIPTION === $donation['type'] ) {
			if ( class_exists( 'HNC_Logger' ) ) {
				HNC_Logger::log_system(
					self::ACTION_PAYMENT_FAILED,
					array(
						'donation_id' => (int) $donation['id'],
						'public_code' => $donation['public_code'],
						'note'        => 'subscription_charge_failed',
						'reason'      => $reason,
						'error_code'  => $err_code,
					)
				);
			}
			return array(
				'outcome' => self::EVENT_OUTCOME_PROCESSED,
				'detail'  => array( 'note' => 'subscription_charge_failed', 'reason' => $reason ),
			);
		}

		// One-time: don't roll back if already captured (out-of-order webhook).
		$do_not_overwrite = array( self::STATUS_CAPTURED, self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED );
		$changes = array( 'provider_payment_id' => $payment_id );
		if ( ! in_array( (string) $donation['status'], $do_not_overwrite, true ) ) {
			$changes['status'] = self::STATUS_FAILED;
			$changes['notes']  = self::sanitize_notes( 'Payment failed: ' . $reason );
		}
		self::update_donation( (int) $donation['id'], $changes );

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_PAYMENT_FAILED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'reason'      => $reason,
					'error_code'  => $err_code,
				)
			);
		}

		do_action( 'hnc_payment_donation_failed', (int) $donation['id'], self::get_donation( (int) $donation['id'] ), $reason );

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'reason' => $reason, 'error_code' => $err_code ),
		);
	}

	/**
	 * order.paid — fires when an order is fully paid. Redundant with
	 * payment.captured for our flow; recorded for completeness.
	 */
	private static function handle_order_paid( $payload, $donation ) {
		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'note' => 'order_paid_acknowledged' ),
		);
	}

	/**
	 * refund.created — refund has been initiated but not yet processed.
	 * Logged only; refund.processed does the actual donation update.
	 */
	private static function handle_refund_created( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity    = isset( $payload['payload']['refund']['entity'] ) ? $payload['payload']['refund']['entity'] : array();
		$refund_id = isset( $entity['id'] ) ? (string) $entity['id'] : '';
		$amount    = isset( $entity['amount'] ) ? (int) $entity['amount'] : 0;

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_REFUND_CREATED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'refund_id'   => $refund_id,
					'amount'      => $amount,
				)
			);
		}

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'refund_id' => $refund_id, 'amount' => $amount ),
		);
	}

	/**
	 * refund.processed — money has actually returned to the donor.
	 * Updates amount_refunded_paise and transitions to REFUNDED or
	 * PARTIALLY_REFUNDED depending on totals.
	 */
	private static function handle_refund_processed( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity    = isset( $payload['payload']['refund']['entity'] ) ? $payload['payload']['refund']['entity'] : array();
		$refund_id = isset( $entity['id'] ) ? (string) $entity['id'] : '';
		$amount    = isset( $entity['amount'] ) ? (int) $entity['amount'] : 0;

		$current_refunded = (int) $donation['amount_refunded_paise'];
		$new_refunded     = $current_refunded + $amount;
		$original_paid    = (int) $donation['amount_paid_paise'];

		$new_status = ( $original_paid > 0 && $new_refunded >= $original_paid )
			? self::STATUS_REFUNDED
			: self::STATUS_PARTIALLY_REFUNDED;

		self::update_donation(
			(int) $donation['id'],
			array(
				'amount_refunded_paise' => $new_refunded,
				'status'                => $new_status,
			)
		);

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_REFUND_PROCESSED,
				array(
					'donation_id'    => (int) $donation['id'],
					'public_code'    => $donation['public_code'],
					'refund_id'      => $refund_id,
					'amount'         => $amount,
					'total_refunded' => $new_refunded,
					'new_status'     => $new_status,
				)
			);
		}

		do_action( 'hnc_payment_refund_processed', (int) $donation['id'], self::get_donation( (int) $donation['id'] ), $entity );

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array(
				'refund_id'      => $refund_id,
				'amount'         => $amount,
				'total_refunded' => $new_refunded,
				'new_status'     => $new_status,
			),
		);
	}

	/**
	 * refund.failed — refund did not go through. Logged only;
	 * donation row remains in its prior state.
	 */
	private static function handle_refund_failed( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$entity    = isset( $payload['payload']['refund']['entity'] ) ? $payload['payload']['refund']['entity'] : array();
		$refund_id = isset( $entity['id'] ) ? (string) $entity['id'] : '';

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_REFUND_FAILED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
					'refund_id'   => $refund_id,
				)
			);
		}

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'refund_id' => $refund_id ),
		);
	}

	/**
	 * subscription.authenticated — donor's mandate has been confirmed
	 * but subscription is not yet active. State -> AUTHENTICATED unless
	 * already further along.
	 */
	private static function handle_sub_authenticated( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$protected = array(
			self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_CANCELLED,
			self::STATUS_COMPLETED, self::STATUS_HALTED,
		);
		if ( ! in_array( (string) $donation['status'], $protected, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_AUTHENTICATED ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_AUTHENTICATED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.activated — subscription is live and Razorpay is
	 * authorised to charge it on schedule. State -> ACTIVE unless
	 * already in a terminal state.
	 */
	private static function handle_sub_activated( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( ! in_array( (string) $donation['status'], $terminal, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_ACTIVE ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_ACTIVATED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		do_action( 'hnc_payment_subscription_activated', (int) $donation['id'], self::get_donation( (int) $donation['id'] ) );

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.charged — a recurring charge succeeded. Increments
	 * charge_count, accumulates amount_paid_paise, sets captured_at +
	 * receipt_no on the FIRST charge.
	 */
	private static function handle_sub_charged( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$payment_entity = isset( $payload['payload']['payment']['entity'] ) ? $payload['payload']['payment']['entity'] : array();
		$payment_id     = isset( $payment_entity['id'] ) ? (string) $payment_entity['id'] : '';
		$amount         = isset( $payment_entity['amount'] ) ? (int) $payment_entity['amount'] : 0;

		$current_paid  = (int) $donation['amount_paid_paise'];
		$current_count = (int) $donation['charge_count'];

		$changes = array(
			'amount_paid_paise'   => $current_paid + $amount,
			'charge_count'        => $current_count + 1,
			'provider_payment_id' => $payment_id, // most recent
		);

		// First charge — set captured_at, receipt_no, and promote status.
		if ( 0 === $current_count ) {
			$changes['captured_at'] = current_time( 'mysql', true );
			if ( empty( $donation['receipt_no'] ) ) {
				$changes['receipt_no'] = self::next_receipt_number();
			}
			$promotable = array( self::STATUS_CREATED, self::STATUS_AUTHENTICATED );
			if ( in_array( (string) $donation['status'], $promotable, true ) ) {
				$changes['status'] = self::STATUS_ACTIVE;
			}
		}

		self::update_donation( (int) $donation['id'], $changes );

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_CHARGED,
				array(
					'donation_id'  => (int) $donation['id'],
					'public_code'  => $donation['public_code'],
					'charge_count' => $changes['charge_count'],
					'amount'       => $amount,
					'payment_id'   => $payment_id,
				)
			);
		}

		do_action( 'hnc_payment_subscription_charged', (int) $donation['id'], $payment_entity );

		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array(
				'payment_id'   => $payment_id,
				'charge_count' => $changes['charge_count'],
				'amount'       => $amount,
			),
		);
	}

	/**
	 * subscription.pending — subscription is in a pending state (Razorpay
	 * is awaiting confirmation, e.g. mandate not yet approved).
	 */
	private static function handle_sub_pending( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED, self::STATUS_ACTIVE );
		if ( ! in_array( (string) $donation['status'], $terminal, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_PENDING ) );
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.halted — Razorpay has stopped retrying after repeated
	 * charge failures. Donor would need to update their card and resume.
	 */
	private static function handle_sub_halted( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( ! in_array( (string) $donation['status'], $terminal, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_HALTED ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_HALTED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.cancelled — subscription terminated (donor or admin
	 * action). Sets cancelled_at and fires the cancellation hook.
	 */
	private static function handle_sub_cancelled( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		// CANCELLED is terminal but idempotent — re-fire-safe.
		self::update_donation(
			(int) $donation['id'],
			array(
				'status'       => self::STATUS_CANCELLED,
				'cancelled_at' => empty( $donation['cancelled_at'] ) ? current_time( 'mysql', true ) : $donation['cancelled_at'],
			)
		);

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_CANCELLED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		do_action( 'hnc_payment_subscription_cancelled', (int) $donation['id'], self::get_donation( (int) $donation['id'] ), false );

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.completed — subscription reached its total_count.
	 * Donor paid the planned full term. Terminal, distinct from CANCELLED.
	 */
	private static function handle_sub_completed( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		// Don't override CANCELLED (cancelled-near-end edge case).
		if ( self::STATUS_CANCELLED !== (string) $donation['status'] ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_COMPLETED ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_COMPLETED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.paused — temporary pause (donor or admin).
	 */
	private static function handle_sub_paused( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( ! in_array( (string) $donation['status'], $terminal, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_PAUSED ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_PAUSED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.resumed — paused subscription is back to active.
	 */
	private static function handle_sub_resumed( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( ! in_array( (string) $donation['status'], $terminal, true ) ) {
			self::update_donation( (int) $donation['id'], array( 'status' => self::STATUS_ACTIVE ) );
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_RESUMED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}

		return array( 'outcome' => self::EVENT_OUTCOME_PROCESSED );
	}

	/**
	 * subscription.updated — Razorpay-side change to plan / quantity /
	 * notify settings. We don't auto-sync (any change should be operator-
	 * driven); just log and acknowledge.
	 */
	private static function handle_sub_updated( $payload, $donation ) {
		if ( ! $donation ) {
			return array( 'outcome' => self::EVENT_OUTCOME_SKIPPED, 'detail' => array( 'reason' => 'no_donation_match' ) );
		}
		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_SUB_UPDATED,
				array(
					'donation_id' => (int) $donation['id'],
					'public_code' => $donation['public_code'],
				)
			);
		}
		return array(
			'outcome' => self::EVENT_OUTCOME_PROCESSED,
			'detail'  => array( 'note' => 'subscription_updated_acknowledged' ),
		);
	}


	/* ==================================================================
	 * ADMIN HELPERS
	 * ==================================================================
	 *
	 * Small utilities used by the rest_admin_* handlers.
	 */

	/**
	 * Resolve an admin lookup (by donation_id OR public_code) to a donation row.
	 *
	 * @param array $params Either ['donation_id' => int] or ['public_code' => string].
	 * @return array|null
	 */
	private static function admin_resolve_donation( $params ) {
		if ( ! is_array( $params ) ) {
			return null;
		}
		if ( ! empty( $params['donation_id'] ) ) {
			return self::get_donation( (int) $params['donation_id'] );
		}
		if ( ! empty( $params['public_code'] ) ) {
			return self::find_donation_by( 'public_code', (string) $params['public_code'] );
		}
		return null;
	}

	/**
	 * Fetch the most recent N events for a donation, newest first.
	 *
	 * @param int $donation_id Donation row id.
	 * @param int $limit       Max rows to return (1..200).
	 * @return array<int,array>
	 */
	private static function get_events_for_donation( $donation_id, $limit = 50 ) {
		global $wpdb;
		$donation_id = (int) $donation_id;
		$limit       = max( 1, min( 200, (int) $limit ) );
		if ( $donation_id <= 0 ) {
			return array();
		}
		$table = self::table_events();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE donation_id = %d ORDER BY id DESC LIMIT %d",
			$donation_id,
			$limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}


	/* ==================================================================
	 * RECONCILIATION CRON
	 * ==================================================================
	 *
	 * Catches the failure modes that webhooks alone can't:
	 *   - webhook never delivered (Razorpay outage / our 5xx response)
	 *   - donor closed checkout window before webhook fired
	 *   - server was down during webhook delivery + retry exhausted
	 *
	 * Every hour, we sweep donations that are >1 hour old, <30 days old,
	 * and still in a non-terminal state. For each, we ask Razorpay what
	 * the actual state is and reconcile if needed.
	 *
	 * For one-time donations: if Razorpay says the order is paid and there's
	 * a captured payment, synthesise a payment.captured event in the events
	 * ledger and dispatch it through the normal handler. Idempotent via the
	 * deterministic synthetic event id.
	 *
	 * For subscriptions: just sync the subscription's status. We don't try
	 * to reconcile individual charges (matching Razorpay charge ids against
	 * our charge_count is fragile); a missed charge.processed webhook will
	 * leave amount_paid_paise off by one charge but the subscription
	 * continues correctly.
	 */

	/**
	 * Cron entry point. Wired via the HOOK_PAYMENT_RECONCILE action.
	 *
	 * @return void
	 */
	public static function cron_reconcile() {
		if ( ! self::is_configured() ) {
			return;
		}

		$start  = microtime( true );
		$found  = 0;
		$synced = 0;
		$failed = 0;

		global $wpdb;
		$table = self::table_donations();

		$non_terminal = array(
			self::STATUS_CREATED,
			self::STATUS_AUTHORIZED,
			self::STATUS_AUTHENTICATED,
			self::STATUS_PENDING,
		);
		$placeholders = implode( ',', array_fill( 0, count( $non_terminal ), '%s' ) );

		$args = array_merge(
			$non_terminal,
			array( self::RECONCILE_AGE_HOURS, self::RECONCILE_MAX_AGE_DAYS, self::RECONCILE_BATCH_SIZE )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status IN ({$placeholders})
			   AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
			   AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 ORDER BY created_at ASC
			 LIMIT %d",
			$args
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$found = count( $rows );

		foreach ( $rows as $row ) {
			try {
				if ( self::reconcile_donation( $row ) ) {
					$synced++;
				}
			} catch ( Throwable $t ) {
				$failed++;
				if ( class_exists( 'HNC_Logger' ) ) {
					HNC_Logger::log_system(
						'payment_reconcile_exception',
						array(
							'donation_id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
							'error'       => $t->getMessage(),
						)
					);
				}
			}
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_RECONCILE_RUN,
				array(
					'found'       => $found,
					'synced'      => $synced,
					'failed'      => $failed,
					'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
				)
			);
		}
	}

	/**
	 * Reconcile a single donation. Dispatches to per-type reconciler.
	 *
	 * @param array $donation Donation row.
	 * @return bool True if a state change was applied.
	 */
	private static function reconcile_donation( $donation ) {
		if ( self::TYPE_ONETIME === (string) $donation['type'] && ! empty( $donation['provider_order_id'] ) ) {
			return self::reconcile_onetime( $donation );
		}
		if ( self::TYPE_SUBSCRIPTION === (string) $donation['type'] && ! empty( $donation['provider_subscription_id'] ) ) {
			return self::reconcile_subscription( $donation );
		}
		return false;
	}

	/**
	 * Reconcile a one-time donation by querying Razorpay's order endpoint.
	 *
	 * @param array $donation Donation row.
	 * @return bool
	 */
	private static function reconcile_onetime( $donation ) {
		$order_id = (string) $donation['provider_order_id'];
		$order    = self::razorpay_get( '/orders/' . $order_id );
		if ( ! $order['ok'] ) {
			return false;
		}

		$status = isset( $order['body']['status'] ) ? (string) $order['body']['status'] : '';
		if ( 'paid' !== $status ) {
			return false; // not yet paid; nothing to reconcile
		}

		$payments = self::razorpay_get( '/orders/' . $order_id . '/payments' );
		if ( ! $payments['ok'] ) {
			return false;
		}

		$items = isset( $payments['body']['items'] ) && is_array( $payments['body']['items'] ) ? $payments['body']['items'] : array();
		foreach ( $items as $payment ) {
			if ( isset( $payment['status'] ) && 'captured' === $payment['status'] ) {
				return self::synthesize_captured_event( $donation, $payment );
			}
		}
		return false;
	}

	/**
	 * Record a synthetic payment.captured event in the ledger and dispatch
	 * it. Deterministic event id ensures duplicate-skip on subsequent runs.
	 *
	 * @param array $donation Donation row.
	 * @param array $payment  Razorpay payment entity.
	 * @return bool
	 */
	private static function synthesize_captured_event( $donation, $payment ) {
		$payment_id      = isset( $payment['id'] ) ? (string) $payment['id'] : '';
		$synth_event_id  = 'reconcile_' . (string) $donation['provider_order_id'];

		$event_row_id = self::record_event(
			array(
				'donation_id'          => (int) $donation['id'],
				'provider'             => self::PROVIDER_RAZORPAY,
				'provider_event_id'    => $synth_event_id,
				'event_type'           => 'payment.captured',
				'provider_object_type' => 'payment',
				'provider_object_id'   => $payment_id,
				'signature_ok'         => 1, // fetched directly from Razorpay API
			)
		);

		if ( 0 === $event_row_id ) {
			// Already reconciled — handler ran on a previous cron tick.
			return false;
		}

		$synth_payload = array(
			'event'   => 'payment.captured',
			'id'      => $synth_event_id,
			'payload' => array(
				'payment' => array( 'entity' => $payment ),
			),
		);

		$outcome = self::handle_payment_captured( $synth_payload, $donation );
		self::mark_event_processed(
			$event_row_id,
			isset( $outcome['outcome'] ) ? $outcome['outcome'] : self::EVENT_OUTCOME_UNHANDLED,
			isset( $outcome['detail'] ) && is_array( $outcome['detail'] ) ? $outcome['detail'] : array()
		);

		return true;
	}

	/**
	 * Reconcile a subscription by syncing its status from Razorpay.
	 *
	 * @param array $donation Donation row.
	 * @return bool
	 */
	private static function reconcile_subscription( $donation ) {
		$sub_id = (string) $donation['provider_subscription_id'];
		$result = self::razorpay_get( '/subscriptions/' . $sub_id );
		if ( ! $result['ok'] ) {
			return false;
		}

		$rzp_status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';
		$status_map = array(
			'created'        => self::STATUS_CREATED,
			'authenticated'  => self::STATUS_AUTHENTICATED,
			'active'         => self::STATUS_ACTIVE,
			'pending'        => self::STATUS_PENDING,
			'halted'         => self::STATUS_HALTED,
			'cancelled'      => self::STATUS_CANCELLED,
			'completed'      => self::STATUS_COMPLETED,
			'paused'         => self::STATUS_PAUSED,
			'expired'        => self::STATUS_COMPLETED, // treat expired as completed
		);
		if ( ! isset( $status_map[ $rzp_status ] ) ) {
			return false;
		}

		$want_status = $status_map[ $rzp_status ];
		if ( (string) $donation['status'] === $want_status ) {
			return false; // already in sync
		}

		// Don't roll back from terminal states even if Razorpay disagrees.
		$terminal = array( self::STATUS_CANCELLED, self::STATUS_COMPLETED );
		if ( in_array( (string) $donation['status'], $terminal, true ) ) {
			return false;
		}

		$changes = array( 'status' => $want_status );
		if ( self::STATUS_CANCELLED === $want_status && empty( $donation['cancelled_at'] ) ) {
			$changes['cancelled_at'] = current_time( 'mysql', true );
		}
		self::update_donation( (int) $donation['id'], $changes );
		return true;
	}


	/* ==================================================================
	 * PII PURGE CRON
	 * ==================================================================
	 *
	 * Daily sweep that nulls out donor_name / donor_email / donor_phone /
	 * user_agent on donation rows older than the retention period AND in
	 * a terminal state. Receipt-relevant fields (receipt_no, donor_email_hash,
	 * ip_hash, public_code, amount_paise) are preserved — they're either
	 * derivable, irreversibly hashed, or required for tax compliance.
	 *
	 * Active subscriptions are NEVER purged (we may need to email the donor
	 * about a charge failure). Customer rows in payment_customers are left
	 * alone — operator can purge those manually if a donor exercises a
	 * deletion right under DPDP Act 2023.
	 */

	/**
	 * Cron entry point. Wired via the HOOK_PAYMENT_PURGE_PII action.
	 *
	 * @return void
	 */
	public static function cron_purge_pii() {
		global $wpdb;

		$retention_days = self::pii_retention_days();
		if ( $retention_days <= 0 ) {
			return; // retention disabled
		}

		$start = microtime( true );

		$terminal = array(
			self::STATUS_CAPTURED,
			self::STATUS_FAILED,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_REFUNDED,
			self::STATUS_PARTIALLY_REFUNDED,
		);
		$placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

		$table = self::table_donations();
		$args  = array_merge( $terminal, array( $retention_days, self::PURGE_PII_BATCH_SIZE ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE pii_purged_at IS NULL
			   AND status IN ({$placeholders})
			   AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 LIMIT %d",
			$args
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $sql );

		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		$purged = 0;
		$now    = current_time( 'mysql', true );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			// $wpdb->update converts PHP null to SQL NULL.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				array(
					'donor_name'       => null,
					'donor_email'      => null,
					'donor_phone_e164' => null,
					'user_agent'       => null,
					'pii_purged_at'    => $now,
					'updated_at'       => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false !== $result ) {
				$purged++;
			}
		}

		if ( class_exists( 'HNC_Logger' ) ) {
			HNC_Logger::log_system(
				self::ACTION_PURGE_PII_RUN,
				array(
					'purged'         => $purged,
					'retention_days' => $retention_days,
					'duration_ms'    => (int) ( ( microtime( true ) - $start ) * 1000 ),
				)
			);
		}
	}


	/* ==================================================================
	 * HEALTH CHECK
	 * ==================================================================
	 *
	 * Returns a snapshot of payment-system health for ops dashboards and
	 * monitoring. Pure read; safe to call as often as needed.
	 */

	/**
	 * Operational health snapshot. Returns:
	 *
	 *   array(
	 *     'mode'                       => 'live'|'test',
	 *     'configured'                 => bool,
	 *     'currency'                   => 'INR',
	 *     'fiscal_year'                => '2026-27',
	 *     'schema_version'             => '1.2.0',
	 *     'plugin_version'             => '1.1.7',
	 *     'webhook_seen_24h'           => bool,
	 *     'webhook_count_24h'          => int,
	 *     'sig_fail_count_24h'         => int,    // hostile/misconfig signal
	 *     'pending_over_24h_count'     => int,    // donations stuck in non-terminal
	 *     'active_subscriptions'       => int,
	 *     'captured_count_fytd'        => int,    // captured donations this FY
	 *     'captured_total_paise_fytd'  => int,    // sum of amounts captured this FY
	 *   )
	 *
	 * @return array
	 */
	public static function health_check() {
		global $wpdb;

		$donations_table = self::table_donations();
		$events_table    = self::table_events();

		// Webhook activity in the last 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$webhook_24h = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$events_table}
			 WHERE received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
			   AND signature_ok = 1"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sig_fail_24h = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$events_table}
			 WHERE received_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
			   AND signature_ok = 0"
		);

		// Pending donations stuck >24h in non-terminal state.
		$non_terminal = array(
			self::STATUS_CREATED,
			self::STATUS_AUTHORIZED,
			self::STATUS_AUTHENTICATED,
			self::STATUS_PENDING,
		);
		$placeholders = implode( ',', array_fill( 0, count( $non_terminal ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$donations_table}
			 WHERE status IN ({$placeholders})
			   AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
			$non_terminal
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$pending_over_24h = (int) $wpdb->get_var( $sql );

		// Active subscriptions count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_subs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$donations_table}
			 WHERE type = %s AND status = %s",
			self::TYPE_SUBSCRIPTION,
			self::STATUS_ACTIVE
		) );

		// FYTD captured.
		list( $fy_start, $fy_end_short ) = self::current_fiscal_year();
		$fy_start_dt = sprintf( '%d-04-01 00:00:00', $fy_start );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$captured_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$donations_table}
			 WHERE captured_at IS NOT NULL AND captured_at >= %s",
			$fy_start_dt
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$captured_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount_paid_paise - amount_refunded_paise), 0) FROM {$donations_table}
			 WHERE captured_at IS NOT NULL AND captured_at >= %s",
			$fy_start_dt
		) );

		return array(
			'mode'                      => self::is_live_mode() ? 'live' : 'test',
			'configured'                => self::is_configured(),
			'config_detail'             => array(
				'key_id_set'         => self::razorpay_key_id() !== '',
				'key_secret_set'     => self::razorpay_key_secret() !== '',
				'webhook_secret_set' => self::razorpay_webhook_secret() !== '',
			),
			'currency'                  => self::currency(),
			'fiscal_year'               => sprintf( '%d-%02d', $fy_start, $fy_end_short ),
			'schema_version'            => (string) get_option( 'hnc_payment_schema_version', '0' ),
			'plugin_version'            => defined( 'HNC_VERSION' ) ? (string) HNC_VERSION : 'unknown',
			'webhook_seen_24h'          => $webhook_24h > 0,
			'webhook_count_24h'         => $webhook_24h,
			'sig_fail_count_24h'        => $sig_fail_24h,
			'pending_over_24h_count'    => $pending_over_24h,
			'active_subscriptions'      => $active_subs,
			'captured_count_fytd'       => $captured_count,
			'captured_total_paise_fytd' => $captured_total,
		);
	}
}


/* ==================================================================================
 * PASTE-READY SCHEMA HOOKS FOR class-hnc-schema.php
 * ==================================================================================
 *
 * Step-by-step for the operator. Open includes/class-hnc-schema.php and:
 *
 * --- A. Add three table-name helpers, alongside the existing ones ---
 *
 *     public static function table_donations() {
 *         global $wpdb;
 *         return $wpdb->prefix . HNC_TABLE_PREFIX . 'donations';
 *     }
 *
 *     public static function table_payment_events() {
 *         global $wpdb;
 *         return $wpdb->prefix . HNC_TABLE_PREFIX . 'payment_events';
 *     }
 *
 *     public static function table_payment_customers() {
 *         global $wpdb;
 *         return $wpdb->prefix . HNC_TABLE_PREFIX . 'payment_customers';
 *     }
 *
 * --- B. Add three entries to all_tables() ---
 *
 *     public static function all_tables() {
 *         return array(
 *             self::table_alcohol_reports(),
 *             self::table_drug_reports(),
 *             self::table_phone_vault(),
 *             self::table_audit_log(),
 *             self::table_device_fingerprints(),
 *             self::table_transparency_snapshots(),
 *             self::table_partners(),
 *             self::table_partner_forwards(),
 *             self::table_donations(),          // NEW
 *             self::table_payment_events(),     // NEW
 *             self::table_payment_customers(),  // NEW
 *         );
 *     }
 *
 * --- C. Add three statements to install() ---
 *
 *     $statements = array(
 *         self::create_alcohol_reports( $charset_collate ),
 *         self::create_drug_reports( $charset_collate ),
 *         self::create_phone_vault( $charset_collate ),
 *         self::create_audit_log( $charset_collate ),
 *         self::create_device_fingerprints( $charset_collate ),
 *         self::create_transparency_snapshots( $charset_collate ),
 *         self::create_partners( $charset_collate ),
 *         self::create_partner_forwards( $charset_collate ),
 *         self::create_donations( $charset_collate ),          // NEW
 *         self::create_payment_events( $charset_collate ),     // NEW
 *         self::create_payment_customers( $charset_collate ),  // NEW
 *     );
 *
 * --- D. Add three private create methods at the bottom of the class ---
 *
 *
 *     private static function create_donations( $charset_collate ) {
 *         $table = self::table_donations();
 *         return "CREATE TABLE {$table} (
 *             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *             public_code VARCHAR(20) NOT NULL,
 *             receipt_no VARCHAR(40) NULL,
 *             provider VARCHAR(20) NOT NULL DEFAULT 'razorpay',
 *             type VARCHAR(20) NOT NULL,
 *             frequency VARCHAR(20) NOT NULL,
 *             status VARCHAR(30) NOT NULL DEFAULT 'created',
 *             amount_paise BIGINT UNSIGNED NOT NULL,
 *             currency VARCHAR(3) NOT NULL DEFAULT 'INR',
 *             amount_paid_paise BIGINT UNSIGNED NOT NULL DEFAULT 0,
 *             amount_refunded_paise BIGINT UNSIGNED NOT NULL DEFAULT 0,
 *             charge_count INT UNSIGNED NOT NULL DEFAULT 0,
 *             provider_order_id VARCHAR(60) NULL,
 *             provider_payment_id VARCHAR(60) NULL,
 *             provider_subscription_id VARCHAR(60) NULL,
 *             provider_plan_id VARCHAR(60) NULL,
 *             provider_customer_id VARCHAR(60) NULL,
 *             customer_id BIGINT UNSIGNED NULL,
 *             donor_name VARCHAR(120) NULL,
 *             donor_email VARCHAR(190) NULL,
 *             donor_email_hash CHAR(64) NULL,
 *             donor_phone_e164 VARCHAR(20) NULL,
 *             donor_anonymous TINYINT(1) NOT NULL DEFAULT 0,
 *             ip_hash CHAR(64) NULL,
 *             user_agent VARCHAR(255) NULL,
 *             idempotency_key VARCHAR(80) NULL,
 *             notes TEXT NULL,
 *             created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *             captured_at DATETIME NULL,
 *             cancelled_at DATETIME NULL,
 *             pii_purged_at DATETIME NULL,
 *             PRIMARY KEY  (id),
 *             UNIQUE KEY public_code (public_code),
 *             UNIQUE KEY receipt_no (receipt_no),
 *             UNIQUE KEY provider_order_id (provider_order_id),
 *             UNIQUE KEY provider_subscription_id (provider_subscription_id),
 *             UNIQUE KEY idempotency_key (idempotency_key),
 *             KEY status_created (status, created_at),
 *             KEY type_status (type, status),
 *             KEY frequency (frequency),
 *             KEY donor_email_hash (donor_email_hash),
 *             KEY customer_id (customer_id),
 *             KEY provider_payment_id (provider_payment_id),
 *             KEY provider_customer_id (provider_customer_id)
 *         ) {$charset_collate};";
 *     }
 *
 *
 *     private static function create_payment_events( $charset_collate ) {
 *         $table = self::table_payment_events();
 *         return "CREATE TABLE {$table} (
 *             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *             donation_id BIGINT UNSIGNED NULL,
 *             provider VARCHAR(20) NOT NULL DEFAULT 'razorpay',
 *             provider_event_id VARCHAR(80) NOT NULL,
 *             event_type VARCHAR(60) NOT NULL,
 *             provider_object_type VARCHAR(20) NULL,
 *             provider_object_id VARCHAR(60) NULL,
 *             signature_ok TINYINT(1) NOT NULL DEFAULT 0,
 *             ip_hash CHAR(64) NULL,
 *             received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *             processed_at DATETIME NULL,
 *             outcome VARCHAR(30) NULL,
 *             outcome_detail TEXT NULL,
 *             PRIMARY KEY  (id),
 *             UNIQUE KEY provider_event_id (provider, provider_event_id),
 *             KEY donation_id (donation_id),
 *             KEY event_type (event_type),
 *             KEY provider_object (provider_object_type, provider_object_id),
 *             KEY received_at (received_at)
 *         ) {$charset_collate};";
 *     }
 *
 *
 *     private static function create_payment_customers( $charset_collate ) {
 *         $table = self::table_payment_customers();
 *         return "CREATE TABLE {$table} (
 *             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *             provider VARCHAR(20) NOT NULL DEFAULT 'razorpay',
 *             provider_customer_id VARCHAR(60) NOT NULL,
 *             email VARCHAR(190) NULL,
 *             email_hash CHAR(64) NOT NULL,
 *             name VARCHAR(120) NULL,
 *             phone_e164 VARCHAR(20) NULL,
 *             total_donated_paise BIGINT UNSIGNED NOT NULL DEFAULT 0,
 *             donation_count INT UNSIGNED NOT NULL DEFAULT 0,
 *             first_donated_at DATETIME NULL,
 *             last_donated_at DATETIME NULL,
 *             created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *             pii_purged_at DATETIME NULL,
 *             PRIMARY KEY  (id),
 *             UNIQUE KEY provider_customer (provider, provider_customer_id),
 *             UNIQUE KEY email_hash (email_hash),
 *             KEY last_donated_at (last_donated_at)
 *         ) {$charset_collate};";
 *     }
 *
 * --- E. Bump versions ---
 *
 *   In helpnagaland-core.php, change:
 *     define( 'HNC_DB_VERSION', '1.0.0' );
 *   to:
 *     define( 'HNC_DB_VERSION', '1.2.0' );
 *
 * On the next page load the bootstrap will detect the version mismatch
 * and call HNC_Schema::install(), which will create the three new tables.
 *
 * ==================================================================================
 */


/* ==================================================================================
 * PASTE-READY CRON REGISTRATION
 * ==================================================================================
 *
 * Two scheduled events drive the payment system's housekeeping:
 *
 *   - HOOK_PAYMENT_RECONCILE   hourly   sync stuck donations with Razorpay
 *   - HOOK_PAYMENT_PURGE_PII   daily    null donor PII past retention period
 *
 * The action callbacks are auto-registered inside HNC_Payment::init() — all
 * the operator needs to do is SCHEDULE the events. There are two ways to do
 * this; pick one based on whether the rest of Help Nagaland Core uses an
 * HNC_Cron coordinator class.
 *
 * ============================================================
 * OPTION A — If HNC_Cron exists
 * ============================================================
 *
 * Open includes/class-hnc-cron.php and find ensure_all_scheduled() (or
 * whatever method registers all scheduled events). Add:
 *
 *     // Payment cron jobs.
 *     if ( class_exists( 'HNC_Payment' ) ) {
 *         if ( ! wp_next_scheduled( HNC_Payment::HOOK_PAYMENT_RECONCILE ) ) {
 *             wp_schedule_event( time() + 600, 'hourly', HNC_Payment::HOOK_PAYMENT_RECONCILE );
 *         }
 *         if ( ! wp_next_scheduled( HNC_Payment::HOOK_PAYMENT_PURGE_PII ) ) {
 *             wp_schedule_event( time() + 3600, 'daily', HNC_Payment::HOOK_PAYMENT_PURGE_PII );
 *         }
 *     }
 *
 * Make sure ensure_all_scheduled() is itself called from the plugin's
 * activation hook AND on every plugins_loaded (so adopting a new plugin
 * version that adds new crons picks them up automatically).
 *
 * ============================================================
 * OPTION B — Direct registration in helpnagaland-core.php
 * ============================================================
 *
 * If there's no central cron coordinator, drop this snippet into the main
 * bootstrap (helpnagaland-core.php), inside or just after the plugins_loaded
 * callback that calls HNC_Payment::init():
 *
 *     add_action( 'init', function() {
 *         if ( ! class_exists( 'HNC_Payment' ) ) {
 *             return;
 *         }
 *         if ( ! wp_next_scheduled( HNC_Payment::HOOK_PAYMENT_RECONCILE ) ) {
 *             wp_schedule_event( time() + 600, 'hourly', HNC_Payment::HOOK_PAYMENT_RECONCILE );
 *         }
 *         if ( ! wp_next_scheduled( HNC_Payment::HOOK_PAYMENT_PURGE_PII ) ) {
 *             wp_schedule_event( time() + 3600, 'daily', HNC_Payment::HOOK_PAYMENT_PURGE_PII );
 *         }
 *     } );
 *
 * ============================================================
 * Cleanup on plugin deactivation
 * ============================================================
 *
 * In the plugin's deactivation hook (register_deactivation_hook), add:
 *
 *     wp_clear_scheduled_hook( 'hnc_payment_cron_reconcile' );
 *     wp_clear_scheduled_hook( 'hnc_payment_cron_purge_pii' );
 *
 * Use the literal hook strings (not the class constants) since the plugin
 * may be deactivated while the class isn't loaded.
 *
 * ============================================================
 * Verifying it's working
 * ============================================================
 *
 *   1) Visit Tools > Site Health > Status > "WP Cron Test" — both hooks
 *      should appear in the schedule with their next-run timestamps.
 *
 *   2) Trigger manually for testing (WP-CLI):
 *        wp cron event run hnc_payment_cron_reconcile
 *        wp cron event run hnc_payment_cron_purge_pii
 *
 *   3) Check the system log table afterwards — each run records a
 *      payment_reconcile_run / payment_purge_pii_run row with stats.
 *
 *   4) For real-time monitoring, call HNC_Payment::health_check() —
 *      returns a snapshot showing webhook activity, pending counts,
 *      FYTD totals, etc.
 *
 * ============================================================
 * Notes on WP-Cron reliability
 * ============================================================
 *
 * WP-Cron is "lazy" by default — events fire only when someone visits a
 * page on the site. For a low-traffic site or guaranteed reliability,
 * disable WP-Cron and run a real cron job:
 *
 *   In wp-config.php:
 *     define( 'DISABLE_WP_CRON', true );
 *
 *   In Hostinger's Cron Job manager (or similar), add a job that runs
 *   every 5 minutes (cron expression "* /5 * * * *" — write it without the
 *   space when you paste it into the cron tab) and runs:
 *
 *     curl -s https://helpnagaland.com/wp-cron.php?doing_wp_cron > /dev/null
 *
 * Even if reconciliation cron is delayed, no money is lost — webhooks are
 * the primary path. Reconciliation only catches the rare cases where the
 * webhook never arrived.
 *
 * ==================================================================================
 */