<?php
/**
 * Plugin Name: Eligius OTP Registration
 * Description: Two-step mobile-verified registration for the Eligius Android app.
 *              1) POST /wp-json/eligius/v1/register/otp-start  — validates the whole
 *                 registration, creates the account (username = mobile), sends a
 *                 6-digit OTP via the IPPanel SMS gateway.
 *              2) POST /wp-json/eligius/v1/register/otp-verify — verifies the code,
 *                 marks the mobile as verified.
 * Requires:    IPPanel credentials in the constants below (filled once).
 *
 * Install: upload this file to wp-content/plugins/eligius-otp.php and activate
 * from wp-admin. If it ever fatals, WordPress deactivates it automatically
 * (safe for the live site — unlike mu-plugins).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ──────────────────────────────  config  ────────────────────────────── */

/* IPPanel SMS gateway — used for the registration mobile OTP. Fill BOTH:
 *   1) Panel → Developers → Access Keys → create → ELIGIUS_IPPANEL_KEY
 *   2) Your sender line (خط ارسال) → ELIGIUS_IPPANEL_FROM (e.g. +983000505)
 * OPTIONAL: create an OTP pattern (text containing the placeholder %code%)
 * in the panel and put its code into ELIGIUS_IPPANEL_OTP_PATTERN. If empty,
 * a plain SMS «کد تایید الیژیوس: XXXXXX» is sent instead.                  */
if ( ! defined( 'ELIGIUS_IPPANEL_KEY' ) ) {
	define( 'ELIGIUS_IPPANEL_KEY', '' );
}
if ( ! defined( 'ELIGIUS_IPPANEL_FROM' ) ) {
	define( 'ELIGIUS_IPPANEL_FROM', '' );
}
if ( ! defined( 'ELIGIUS_IPPANEL_OTP_PATTERN' ) ) {
	define( 'ELIGIUS_IPPANEL_OTP_PATTERN', '' );
}

/* The rate limiter normally comes from the eligius-register.php mu-plugin.
 * Guard anyway so a missing mu-plugin can never fatal this plugin.         */
if ( ! function_exists( 'eligius_rate_limited' ) ) {
	function eligius_rate_limited() { return false; }
}

/* ──────────────────────────────  helpers  ────────────────────────────── */

/** Iranian national code checksum (10 digits, mod 11). */
function eligius_otp_valid_national_code( $code ) {
	if ( ! preg_match( '/^\d{10}$/', $code ) ) return false;
	if ( preg_match( '/^(\d)\1{9}$/', $code ) ) return false;
	$sum = 0;
	for ( $i = 0; $i < 9; $i++ ) $sum += ( (int) $code[ $i ] ) * ( 10 - $i );
	$r = $sum % 11;
	$check = (int) $code[9];
	return ( $r < 2 ) ? ( $check === $r ) : ( $check === 11 - $r );
}

/** Normalizes Iranian mobile to 09xxxxxxxxx; null if invalid. */
function eligius_otp_normalize_mobile( $raw ) {
	$m = preg_replace( '/\D/', '', (string) $raw );
	if ( strlen( $m ) === 10 && substr( $m, 0, 1 ) === '9' )   $m = '0' . $m;
	if ( strlen( $m ) === 12 && substr( $m, 0, 2 ) === '98' ) $m = '0' . substr( $m, 2 );
	if ( strlen( $m ) === 13 && substr( $m, 0, 3 ) === '+98') $m = '0' . substr( $m, 3 );
	if ( ! preg_match( '/^09\d{9}$/', $m ) ) return null;
	return $m;
}

function eligius_otp_mobile_e164( $mobile ) {
	return '+98' . substr( $mobile, 1 );
}

/** Sends the OTP via IPPanel Edge: pattern SMS (fast lane) if configured, with
 *  automatic fallback to plain webservice SMS if the pattern send ever fails
 *  (e.g. pattern still pending approval) — the OTP must never be undeliverable.
 *  Endpoint/shape VERIFIED live against edge.ippanel.com (2026-11-08):
 *  POST https://edge.ippanel.com/v1/api/send — webservice keeps recipients INSIDE
 *  params; pattern wants recipients at TOP level (single recipient) + flat params. */
function eligius_otp_send_sms( $mobile, $code ) {
	$key  = ELIGIUS_IPPANEL_KEY;
	$from = ELIGIUS_IPPANEL_FROM;
	if ( $key === '' || $from === '' ) {
		return new WP_Error( 'eligius_sms_not_configured',
			'SMS gateway is not configured. / درگاه پیامک تنظیم نشده است.',
			array( 'status' => 500 ) );
	}
	if ( ELIGIUS_IPPANEL_OTP_PATTERN !== '' ) {
		$via_pattern = eligius_otp_send_ippanel( array(
			'sending_type' => 'pattern',
			'from_number'  => $from,
			'code'         => ELIGIUS_IPPANEL_OTP_PATTERN,
			'recipients'   => array( eligius_otp_mobile_e164( $mobile ) ),
			'params'       => array( 'code' => $code ),
		) );
		if ( ! is_wp_error( $via_pattern ) ) return true;   // fast lane delivered
		// else fall through to the plain webservice send below.
	}
	return eligius_otp_send_ippanel( array(
		'sending_type' => 'webservice',
		'from_number'  => $from,
		'message'      => 'کد تایید الیژیوس: ' . $code,
		'params'       => array( 'recipients' => array( eligius_otp_mobile_e164( $mobile ) ) ),
	) );
}

/** Low-level IPPanel Edge send: returns true or a WP_Error. */
function eligius_otp_send_ippanel( $body ) {
	$res = wp_remote_post( 'https://edge.ippanel.com/v1/api/send', array(
		'timeout' => 15,
		'headers' => array(
			'Authorization' => ELIGIUS_IPPANEL_KEY,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( $body ),
	) );
	if ( is_wp_error( $res ) ) return $res;
	$status = wp_remote_retrieve_response_code( $res );
	$json   = json_decode( wp_remote_retrieve_body( $res ), true );
	$ok     = $status === 200 && isset( $json['meta']['status'] ) && $json['meta']['status'];
	if ( ! $ok ) {
		return new WP_Error( 'eligius_sms_failed', 'SMS gateway rejected the message. / ارسال پیامک ناموفق بود.', array( 'status' => 502 ) );
	}
	return true;
}

/* ─────────────────────────  routes  ───────────────────────── */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/register/otp-start', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'eligius_otp_start_handler',
		'args'                => array(
			'mobile'        => array( 'required' => true,  'type' => 'string' ),
			'email'         => array( 'required' => true,  'type' => 'string' ),
			'password'      => array( 'required' => true,  'type' => 'string' ),
			'first_name'    => array( 'required' => true,  'type' => 'string' ),
			'last_name'     => array( 'required' => true,  'type' => 'string' ),
			'national_code' => array( 'required' => true,  'type' => 'string' ),
		),
	) );
	register_rest_route( 'eligius/v1', '/register/otp-verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'eligius_otp_verify_handler',
		'args'                => array(
			'mobile' => array( 'required' => true, 'type' => 'string' ),
			'code'   => array( 'required' => true, 'type' => 'string' ),
		),
	) );
} );

/**
 * Step 1: validates the whole registration, creates the account
 * (username = mobile), generates a 6-digit OTP, sends it via IPPanel,
 * and stores a 10-minute hashed code + user id in a transient.
 *
 * RESEND/RETRY: if the mobile already has an account that was never verified
 * (eligius_mobile_verified meta missing), the same call re-uses it — updates
 * the details and sends a fresh code — so a user who timed out or fat-fingered
 * the code is never stuck with an orphan account.
 */
function eligius_otp_start_handler( WP_REST_Request $req ) {

	if ( eligius_rate_limited() ) {
		return new WP_Error( 'eligius_throttled',
			'Too many attempts. Try again later. / تلاش بیش از حد. بعداً دوباره امتحان کنید.',
			array( 'status' => 429 ) );
	}

	$mobile        = eligius_otp_normalize_mobile( $req->get_param( 'mobile' ) );
	$email         = sanitize_email( (string) $req->get_param( 'email' ) );
	$password      = (string) $req->get_param( 'password' );
	$first_name    = sanitize_text_field( (string) $req->get_param( 'first_name' ) );
	$last_name     = sanitize_text_field( (string) $req->get_param( 'last_name' ) );
	$national_code = preg_replace( '/\D/', '', (string) $req->get_param( 'national_code' ) );

	if ( empty( $first_name ) || empty( $last_name ) ) {
		return new WP_Error( 'eligius_missing_name', 'First and last name are required. / نام و نام خانوادگی الزامی است.', array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'eligius_bad_email', 'Invalid email address. / ایمیل نامعتبر است.', array( 'status' => 400 ) );
	}
	if ( $mobile === null ) {
		return new WP_Error( 'eligius_bad_mobile', 'Invalid mobile number (09xxxxxxxxx). / شماره موبایل نامعتبر است.', array( 'status' => 400 ) );
	}

	// Existing account for this mobile: reusable ONLY while still unverified.
	$existing_id = username_exists( $mobile );
	if ( $existing_id && get_user_meta( (int) $existing_id, 'eligius_mobile_verified', true ) ) {
		return new WP_Error( 'eligius_user_exists', 'This mobile number is already registered. / این شماره موبایل قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}
	$created = ! $existing_id;

	// Email must be free — except when it belongs to this very (unverified) account.
	$email_owner = email_exists( $email );
	if ( $email_owner && (int) $email_owner !== (int) $existing_id ) {
		return new WP_Error( 'eligius_email_exists', 'This email is already registered. / این ایمیل قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}
	if ( strlen( $password ) < 8 ) {
		return new WP_Error( 'eligius_weak_password', 'Password must be at least 8 characters. / رمز عبور باید حداقل ۸ کاراکتر باشد.', array( 'status' => 400 ) );
	}
	if ( ! eligius_otp_valid_national_code( $national_code ) ) {
		return new WP_Error( 'eligius_bad_national_code', 'Invalid national code. / کد ملی نامعتبر است.', array( 'status' => 400 ) );
	}
	global $wpdb;
	$nc_owner = $wpdb->get_var( $wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'national_code' AND meta_value = %s LIMIT 1",
		$national_code
	) );
	if ( $nc_owner && (int) $nc_owner !== (int) $existing_id ) {
		return new WP_Error( 'eligius_nc_exists', 'This national code is already registered. / این کد ملی قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}

	if ( $created ) {
		$user_id = wp_insert_user( array(
			'user_login'   => $mobile,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'eligius_insert_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
		}
	} else {
		// Re-use the unverified account: refresh the details the user re-entered.
		$user_id = wp_update_user( array(
			'ID'           => (int) $existing_id,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
		) );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'eligius_insert_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
		}
		$user_id = (int) $existing_id;
	}

	// Standard WP fields + compatibility meta for site plugins:
	update_user_meta( $user_id, 'mobile', $mobile );            // our canonical key
	update_user_meta( $user_id, 'billing_phone', $mobile );     // WooCommerce convention
	update_user_meta( $user_id, 'national_code', $national_code );
	update_user_meta( $user_id, 'registered_via', 'eligius-app' );

	$otp = (string) random_int( 100000, 999999 );
	set_transient(
		'eligius_otp_' . md5( $mobile ),
		wp_json_encode( array(
			'h'     => hash( 'sha256', $otp . wp_salt() ),
			'uid'   => $user_id,
			'exp'   => time() + 10 * MINUTE_IN_SECONDS,
			'tries' => 0,
		) ),
		10 * MINUTE_IN_SECONDS
	);

	$sms = eligius_otp_send_sms( $mobile, $otp );
	if ( is_wp_error( $sms ) ) {
		// The SMS failed — if we created the account just now, delete it so the
		// user can retry cleanly; an account we merely re-used is kept.
		if ( $created ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		return $sms;
	}

	return rest_ensure_response( array(
		'sent'       => true,
		'expires_in' => 600,
		'username'   => $mobile,
	) );
}

/**
 * Step 2: verifies the 6-digit code (max 5 attempts, 10 minutes, single use)
 * and marks the mobile as verified.
 */
function eligius_otp_verify_handler( WP_REST_Request $req ) {
	$mobile = eligius_otp_normalize_mobile( $req->get_param( 'mobile' ) );
	$code   = preg_replace( '/\D/', '', (string) $req->get_param( 'code' ) );
	$tkey   = 'eligius_otp_' . md5( (string) $mobile );
	$data   = json_decode( get_transient( $tkey ), true );

	if ( ! is_array( $data ) || empty( $data['uid'] ) ) {
		return new WP_Error( 'eligius_otp_expired', 'The code expired. Request a new one. / کد منقضی شده است. دوباره درخواست کنید.', array( 'status' => 400 ) );
	}
	if ( (int) $data['exp'] < time() ) {
		delete_transient( $tkey );
		return new WP_Error( 'eligius_otp_expired', 'The code expired. Request a new one. / کد منقضی شده است. دوباره درخواست کنید.', array( 'status' => 400 ) );
	}
	if ( (int) $data['tries'] >= 5 ) {
		delete_transient( $tkey );
		return new WP_Error( 'eligius_otp_locked', 'Too many wrong attempts. Request a new code. / تلاش بیش از حد. کد جدید بگیرید.', array( 'status' => 400 ) );
	}
	if ( ! hash_equals( (string) $data['h'], hash( 'sha256', $code . wp_salt() ) ) ) {
		$data['tries'] = (int) $data['tries'] + 1;
		set_transient( $tkey, wp_json_encode( $data ), 10 * MINUTE_IN_SECONDS );
		$left = 5 - (int) $data['tries'];
		return new WP_Error( 'eligius_otp_wrong',
			'Wrong code. Attempts left: ' . $left . ' / کد اشتباه است. تلاش باقی‌مانده: ' . $left,
			array( 'status' => 400 ) );
	}

	delete_transient( $tkey );
	update_user_meta( (int) $data['uid'], 'eligius_mobile_verified', 1 );

	return rest_ensure_response( array(
		'verified' => true,
		'username' => $mobile,
	) );
}
