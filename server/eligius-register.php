<?php
/**
 * Plugin Name: Eligius Register
 * Description: REST endpoint for the Eligius Android app — user registration with
 *              first name, last name, email, mobile and Iranian national code.
 *              Endpoint: POST /wp-json/eligius/v1/register
 *
 * Install: drop this file into wp-content/mu-plugins/ (create the folder if needed).
 * It loads automatically — nothing to activate, no settings.
 *
 * Payload (JSON):
 * {
 *   "username":     "09xxxxxxxxx",   // mobile, doubles as the login name
 *   "email":        "user@example.com",
 *   "password":     "at least 8 chars",
 *   "first_name":   "...",
 *   "last_name":    "...",
 *   "mobile":       "09xxxxxxxxx",
 *   "national_code":"10 digits"
 * }
 *
 * Success: 200 { "success": true, "message": "...", "username": "09..." }
 * Error:   4xx { "success": false, "message": "..." }   (message is safe to show in the app)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function () {
	register_rest_route( 'eligius/v1', '/register', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',   // public: it IS the registration endpoint
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
	if ( preg_match( '/^(\d)\1{9}$/', $code ) ) return false;   // all digits identical
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

	// Normalize Iranian mobile: 9xxxxxxxxx / 989xxxxxxxxx -> 09xxxxxxxxx
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
		return new WP_Error( 'eligius_insert_failed',
			$user_id->get_error_message(),
			array( 'status' => 400 ) );
	}

	update_user_meta( $user_id, 'mobile', $mobile );
	update_user_meta( $user_id, 'national_code', $national_code );
	update_user_meta( $user_id, 'registered_via', 'eligius-app' );

	// Sends the standard "your account is ready / set password" style notification email.
	wp_new_user_notification( $user_id, null, 'user' );

	return rest_ensure_response( array(
		'success'  => true,
		'message'  => 'Account created. Please check your email, then sign in. / حساب ساخته شد. ایمیل را بررسی و سپس وارد شوید.',
		'username' => $username,
	) );
}
