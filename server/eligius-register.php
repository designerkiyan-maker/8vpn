<?php
/**
 * Plugin Name: Eligius Register + Updates
 * Description: REST endpoints for the Eligius Android app.
 *              1) POST /wp-json/eligius/v1/register  — user registration with
 *                 first/last name, email, mobile (doubles as username), Iranian
 *                 national code and password. Full server-side validation.
 *              2) GET  /wp-json/eligius/v1/version   — the latest released app
 *                 version + APK download URL, powering the in-app updater
 *                 (the app never talks to GitHub or any third-party service).
 *
 * Install: drop this file into wp-content/mu-plugins/ (create the folder if
 * needed). It loads automatically — nothing to activate.
 *
 * ┌────────────────────────────────────────────────────────────────────────┐
 * │  WHEN YOU PUBLISH A NEW APK: edit the two lines marked ✎ below and    │
 * │  upload the new APK to the URL you set. That is the whole release      │
 * │  process — every installed app picks it up on its next update check.   │
 * └────────────────────────────────────────────────────────────────────────┘
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Setup helper key — used to open /wp-json/eligius/v1/ftpinfo in a browser:
 *   https://wp.mrkiyan.ir/wp-json/eligius/v1/ftpinfo?key=eligius-setup-7f3a9c41d2b8
 */
if ( ! defined( 'ELIGIUS_SETUP_KEY' ) ) {
	define( 'ELIGIUS_SETUP_KEY', 'eligius-setup-7f3a9c41d2b8');
}

/* Fallback if no APK has been uploaded to uploads/eligius/ yet.             */
if ( ! defined( 'ELIGIUS_LATEST_VERSION' ) ) {
	define( 'ELIGIUS_LATEST_VERSION', '1.3.5' );
}
if ( ! defined( 'ELIGIUS_LATEST_APK_URL' ) ) {
	define( 'ELIGIUS_LATEST_APK_URL', 'https://wp.mrkiyan.ir/wp-content/uploads/eligius/Eligius-universal.apk' );
}

/**
 * Finds the newest uploaded build: scans wp-content/uploads/eligius/ for
 * Eligius-<version>-universal.apk (uploaded automatically by GitHub Actions
 * after every tag build) and returns the highest version found.
 */
function eligius_latest_apk() {
	$dir = WP_CONTENT_DIR . '/uploads/eligius';
	if ( ! is_dir( $dir ) ) return null;
	$best_version = null;
	$best_name    = null;
	foreach ( glob( $dir . '/Eligius-*.apk' ) ?: array() as $file ) {
		$name = basename( $file );
		if ( preg_match( '/^Eligius-(\d+(?:\.\d+){1,3})-universal\.apk$/i', $name, $m ) ) {
			if ( $best_version === null || version_compare( $m[1], $best_version, '>' ) ) {
				$best_version = $m[1];
				$best_name    = $name;
			}
		}
	}
	if ( $best_version === null ) return null;
	return array(
		'version' => $best_version,
		'apk_url' => content_url( 'uploads/eligius/' . $best_name ),
	);
}

/* ──────────────────────────────  version  ────────────────────────────── */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/version', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$auto = eligius_latest_apk();
			if ( $auto !== null ) {
				return rest_ensure_response( array(
					'version' => $auto['version'],
					'apk_url' => $auto['apk_url'],
				) );
			}
			// Fallback: no APK uploaded yet — report the constant below.
			return rest_ensure_response( array(
				'version' => ELIGIUS_LATEST_VERSION,
				'apk_url' => ELIGIUS_LATEST_APK_URL,
			) );
		},
	) );
} );

/* ────────────────────────  ftpinfo (setup helper)  ────────────────────── */
/* Open https://wp.mrkiyan.ir/wp-json/eligius/v1/ftpinfo in your browser
 * WHILE LOGGED INTO wp-admin as administrator. It prints the exact server
 * path of the eligius uploads folder — use its tail (relative to your FTP
 * login directory) as the ELIGIUS_FTP_PATH secret in GitHub.               */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/ftpinfo', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $req ) {
			// Browser-friendly: append ?key=<ELIGIUS_SETUP_KEY from this file>.
			return current_user_can( 'manage_options' )
				|| ( defined( 'ELIGIUS_SETUP_KEY' )
				     && hash_equals( ELIGIUS_SETUP_KEY, (string) $req->get_param( 'key' ) ) );
		},
		'callback'            => function () {
			$dir = WP_CONTENT_DIR . '/uploads/eligius';
			if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
			return rest_ensure_response( array(
				'absolute_server_path' => $dir,
				'dir_exists'           => is_dir( $dir ),
				'writable'             => is_writable( $dir ),
				'apks_found'           => array_map( 'basename', glob( $dir . '/*.apk' ) ?: array() ),
				'hint'                 => 'FTP_PATH = this absolute path, relative to where your FTP client starts.',
			) );
		},
	) );
} );

/* ──────────────────────────────  usage  ────────────────────────────── */
/* Lifetime per-user traffic/duration, stored in the WordPress database.
 * The app reports each finished session (POST, JWT Bearer) and reads the
 * accumulated totals (GET). Survives app updates and reinstalls.        */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/usage', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'is_user_logged_in',   // valid JWT Bearer = logged in
			'callback'            => 'eligius_usage_get',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'is_user_logged_in',
			'callback'            => 'eligius_usage_post',
			'args'                => array(
				'rx'       => array( 'required' => true, 'type' => 'integer' ),
				'tx'       => array( 'required' => true, 'type' => 'integer' ),
				'duration' => array( 'required' => true, 'type' => 'integer' ),
			),
		),
	) );
} );

/** Sanity cap per report: 30 days of duration, 1 TB per direction. */
function eligius_usage_cap( $v, $max ) {
	return min( max( 0, (int) $v ), $max );
}

function eligius_usage_get() {
	$uid = get_current_user_id();
	return rest_ensure_response( array(
		'rx_bytes'    => (int) get_user_meta( $uid, 'eligius_total_rx', true ),
		'tx_bytes'    => (int) get_user_meta( $uid, 'eligius_total_tx', true ),
		'duration_ms' => (int) get_user_meta( $uid, 'eligius_total_duration', true ),
		'sessions'    => (int) get_user_meta( $uid, 'eligius_sessions', true ),
	) );
}

function eligius_usage_post( WP_REST_Request $req ) {
	$uid = get_current_user_id();
	$rx  = eligius_usage_cap( $req->get_param( 'rx' ), 2 ** 40 );              // 1 TB
	$tx  = eligius_usage_cap( $req->get_param( 'tx' ), 2 ** 40 );
	$dur = eligius_usage_cap( $req->get_param( 'duration' ), 30 * 86400 * 1000 ); // 30 days

	update_user_meta( $uid, 'eligius_total_rx', (int) get_user_meta( $uid, 'eligius_total_rx', true ) + $rx );
	update_user_meta( $uid, 'eligius_total_tx', (int) get_user_meta( $uid, 'eligius_total_tx', true ) + $tx );
	update_user_meta( $uid, 'eligius_total_duration', (int) get_user_meta( $uid, 'eligius_total_duration', true ) + $dur );
	update_user_meta( $uid, 'eligius_sessions', (int) get_user_meta( $uid, 'eligius_sessions', true ) + 1 );

	return eligius_usage_get();
}

/* ──────────────────────────────  register  ───────────────────────────── */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/register', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'eligius_register_handler',
		'args'                => array(
			'username'      => array( 'required' => true,  'type' => 'string' ),
			'email'         => array( 'required' => true,  'type' => 'string' ),
			'password'      => array( 'required' => true,  'type' => 'string' ),
			'first_name'    => array( 'required' => false, 'type' => 'string' ),
			'last_name'     => array( 'required' => false, 'type' => 'string' ),
			'mobile'        => array( 'required' => true,  'type' => 'string' ),
			'national_code' => array( 'required' => true,  'type' => 'string' ),
		),
	) );
} );

/** Iranian national code checksum (10 digits, mod 11). */
function eligius_valid_national_code( $code ) {
	if ( ! preg_match( '/^\d{10}$/', $code ) ) return false;
	if ( preg_match( '/^(\d)\1{9}$/', $code ) ) return false;
	$sum = 0;
	for ( $i = 0; $i < 9; $i++ ) $sum += ( (int) $code[ $i ] ) * ( 10 - $i );
	$r = $sum % 11;
	$check = (int) $code[9];
	return ( $r < 2 ) ? ( $check === $r ) : ( $check === 11 - $r );
}

/** Basic throttle: max 5 registrations per IP per hour. */
function eligius_rate_limited() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0';
	$key = 'eligius_reg_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 5 ) return true;
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return false;
}

function eligius_register_handler( WP_REST_Request $req ) {

	if ( eligius_rate_limited() ) {
		return new WP_Error( 'eligius_throttled',
			'Too many attempts. Try again later. / تلاش بیش از حد. بعداً دوباره امتحان کنید.',
			array( 'status' => 429 ) );
	}

	$username      = sanitize_text_field( (string) $req->get_param( 'username' ) );
	$email         = sanitize_email( (string) $req->get_param( 'email' ) );
	$password      = (string) $req->get_param( 'password' );
	$first_name    = sanitize_text_field( (string) $req->get_param( 'first_name' ) );
	$last_name     = sanitize_text_field( (string) $req->get_param( 'last_name' ) );
	$mobile        = preg_replace( '/\D/', '', (string) $req->get_param( 'mobile' ) );
	$national_code = preg_replace( '/\D/', '', (string) $req->get_param( 'national_code' ) );

	// Normalize Iranian mobile: 9xxxxxxxxx / 989xxxxxxxxx / +989xxxxxxxxx -> 09xxxxxxxxx
	if ( strlen( $mobile ) === 10 && substr( $mobile, 0, 1 ) === '9' )   $mobile = '0' . $mobile;
	if ( strlen( $mobile ) === 12 && substr( $mobile, 0, 2 ) === '98' ) $mobile = '0' . substr( $mobile, 2 );
	if ( strlen( $mobile ) === 13 && substr( $mobile, 0, 3 ) === '+98') $mobile = '0' . substr( $mobile, 3 );

	if ( empty( $first_name ) || empty( $last_name ) ) {
		return new WP_Error( 'eligius_missing_name', 'First and last name are required. / نام و نام خانوادگی الزامی است.', array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'eligius_bad_email', 'Invalid email address. / ایمیل نامعتبر است.', array( 'status' => 400 ) );
	}
	if ( email_exists( $email ) ) {
		return new WP_Error( 'eligius_email_exists', 'This email is already registered. / این ایمیل قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
		return new WP_Error( 'eligius_bad_mobile', 'Invalid mobile number (09xxxxxxxxx). / شماره موبایل نامعتبر است.', array( 'status' => 400 ) );
	}
	if ( username_exists( $username ) ) {
		return new WP_Error( 'eligius_user_exists', 'This mobile number is already registered. / این شماره موبایل قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}
	if ( strlen( $password ) < 8 ) {
		return new WP_Error( 'eligius_weak_password', 'Password must be at least 8 characters. / رمز عبور باید حداقل ۸ کاراکتر باشد.', array( 'status' => 400 ) );
	}
	if ( ! eligius_valid_national_code( $national_code ) ) {
		return new WP_Error( 'eligius_bad_national_code', 'Invalid national code. / کد ملی نامعتبر است.', array( 'status' => 400 ) );
	}
	global $wpdb;
	$nc_owner = $wpdb->get_var( $wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'national_code' AND meta_value = %s LIMIT 1",
		$national_code
	) );
	if ( $nc_owner ) {
		return new WP_Error( 'eligius_nc_exists', 'This national code is already registered. / این کد ملی قبلاً ثبت شده است.', array( 'status' => 400 ) );
	}

	$user_id = wp_insert_user( array(
		'user_login'   => $username,
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

	// Standard WP fields + compatibility meta for site plugins:
	update_user_meta( $user_id, 'mobile', $mobile );            // our canonical key
	update_user_meta( $user_id, 'billing_phone', $mobile );     // WooCommerce convention
	update_user_meta( $user_id, 'national_code', $national_code );
	update_user_meta( $user_id, 'registered_via', 'eligius-app' );

	wp_new_user_notification( $user_id, null, 'user' );

	return rest_ensure_response( array(
		'success'  => true,
		'message'  => 'Account created. Please check your email, then sign in. / حساب ساخته شد. ایمیل را بررسی و سپس وارد شوید.',
		'username' => $username,
	) );
}
