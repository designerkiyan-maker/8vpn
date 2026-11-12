# Eligius Subscription System — System Architecture

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

This document describes the complete system architecture for the Eligius subscription, licensing, and payment system. The design builds upon the existing WordPress-based backend and Android application, adding subscription management, device binding, license enforcement, and payment processing capabilities.

### 1.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           ELIGIUS SYSTEM ARCHITECTURE                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌──────────────┐    HTTPS/REST     ┌──────────────────────────────────┐  │
│  │   ANDROID    │ ◄───────────────► │        WORDPRESS BACKEND         │  │
│  │  APPLICATION │    /eligius/v1/   │  ┌────────────────────────────┐  │  │
│  │  (Kotlin/    │                   │  │  eligius-subscription      │  │  │
│  │   Compose)   │                   │  │  WordPress Plugin          │  │  │
│  │              │                   │  │  ┌──────────────────────┐  │  │  │
│  │ ┌──────────┐ │                   │  │  │ Core Modules         │  │  │  │
│  │ │AuthStore │ │◄──────────────────┤  │  │ • AuthIntegration    │  │  │  │
│  │ │LicenseSt.│ │    JWT + API      │  │  │ • DeviceManager      │  │  │  │
│  │ │DeviceId  │ │                   │  │  │ • LicenseManager     │  │  │  │
│  │ │SmsOtp    │ │                   │  │  │ • SubscriptionMgr    │  │  │  │
│  │ │SmsRetriever│                │  │  │ • PaymentGateway       │  │  │  │
│  │ │KeyStore   │                   │  │  │ • AdminDashboard     │  │  │  │
│  │ └──────────┘ │                   │  │  └──────────────────────┘  │  │  │
│  └──────────────┘                   │  │         ▲                  │  │  │
│         │                           │  │         │                  │  │  │
│         ▼                           │  │         │                  │  │  │
│  ┌──────────────┐                    │  │  ┌──────────────────┐    │  │  │
│  │  TUNNEL      │                    │  │  │  MariaDB         │    │  │  │
│  │  SERVICE     │                    │  │  │  (WordPress DB)  │    │  │  │
│  │ (Aether/     │                    │  │  │  ┌────────────┐  │    │  │  │
│  │  sing-box)   │                    │  │  │  │ Custom     │  │    │  │  │
│  │              │                    │  │  │  │ Tables     │  │    │  │  │
│  └──────────────┘                    │  │  │  └────────────┘  │    │  │  │
└─────────────────────────────────────┘  └──────────────────────┘    │  │
                                                                      │
                              ┌──────────────────────────────────────┘
                              │
                    ┌─────────▼─────────┐
                    │  IPPanel SMS      │
                    │  Gateway (OTP)    │
                    └───────────────────┘
```

### 1.2 Design Principles

1. **Backend-First Development** — All subscription logic lives in WordPress plugin; Android app is a thin client
2. **Server-Authoritative** — License validation, subscription status, payment verification all server-side
3. **Device Binding via Cryptography** — Android Keystore-backed keys, server-verified challenges
4. **Layered Security** — JWT for sessions, signed offline entitlements, server-verified challenges
5. **Iranian Payment Integration** — Provider-agnostic abstraction for ZarinPal, IDPay, etc.
6. **WordPress-Native** — Leverage existing WP infrastructure, users, JWT auth, wp-admin
7. **No VPN Core Changes** — TunnelService only gets a license gate; core untouched

---

## 2. WordPress Plugin Architecture

### 2.1 Plugin Structure

```
eligius-subscription/
├── eligius-subscription.php          # Plugin bootstrap
├── includes/
│   ├── class-eligius-subscription.php      # Main plugin class
│   ├── bootstrap.php                       # Autoloader & initialization
│   ├── activation.php                      # Activation hook
│   ├── deactivation.php                    # Deactivation hook
│   ├── uninstall.php                       # Uninstall cleanup
│   ├── admin/
│   │   ├── class-admin-dashboard.php       # Dashboard page
│   │   ├── class-users-admin.php           # Users management
│   │   ├── class-devices-admin.php         # Devices management
│   │   ├── class-plans-admin.php           # Plans management
│   │   ├── class-subscriptions-admin.php   # Subscriptions management
│   │   ├── class-payments-admin.php        # Payments management
│   │   ├── class-licenses-admin.php        # Licenses management
│   │   ├── class-audit-logs-admin.php      # Audit logs
│   │   ├── class-gateway-settings.php      # Gateway settings
│   │   ├── class-general-settings.php      # General settings
│   │   └── class-admin-menu.php            # Menu registration
│   ├── core/
│   │   ├── class-database.php              # Schema & migrations
│   │   ├── class-repository-base.php       # Base repository class
│   │   ├── class-device-repository.php     # Device data access
│   │   ├── class-license-repository.php    # License data access
│   │   ├── class-subscription-repository.php
│   │   ├── class-payment-repository.php
│   │   ├── class-audit-log-repository.php
│   │   └── class-device-transfer-repository.php
│   ├── auth/
│   │   ├── class-jwt-auth.php              # JWT validation & integration
│   │   ├── class-device-auth.php           # Device challenge/verify
│   │   └── class-otp-manager.php           # OTP integration (reuse existing)
│   ├── license/
│   │   ├── class-license-manager.php       # Core license logic
│   │   ├── class-license-issuer.php        # License creation/signing
│   │   ├── class-license-validator.php     # Validation & offline entitlements
│   │   ├── class-trial-manager.php         # Trial logic
│   │   └── class-device-transfer.php       # Device transfer workflow
│   ├── subscription/
│   │   ├── class-subscription-manager.php  # Subscription lifecycle
│   │   ├── class-plan-manager.php          # Plan configuration
│   │   └── class-renewal-processor.php     # Renewal logic
│   ├── payment/
│   │   ├── interface-gateway-adapter.php   # Payment gateway interface
│   │   ├── class-payment-processor.php     # Payment orchestration
│   │   ├── class-gateway-factory.php       # Gateway factory
│   │   └── gateways/
│   │       ├── class-zarinpal-adapter.php
│   │       ├── class-idpay-adapter.php
│   │       └── class-zibal-adapter.php
│   ├── api/
│   │   ├── class-api-controller.php        # Base controller
│   │   ├── class-auth-controller.php       # Auth endpoints
│   │   ├── class-device-controller.php     # Device endpoints
│   │   ├── class-plan-controller.php       # Plan endpoints
│   │   ├── class-subscription-controller.php
│   │   ├── class-payment-controller.php    # Payment endpoints
│   │   ├── class-license-controller.php    # License endpoints
│   │   └── class-admin-controller.php      # Admin endpoints
│   ├── security/
│   │   ├── class-rate-limiter.php          # Rate limiting
│   │   ├── class-nonce-manager.php         # Nonce management
│   │   ├── class-signature-verifier.php    # Signature verification
│   │   └── class-audit-logger.php          # Audit logging
│   ├── cron/
│   │   ├── class-cron-manager.php          # Cron job management
│   │   ├── class-expiry-processor.php      # Expiry processing
│   │   └── class-renewal-processor.php     # Auto-renewal (if applicable)
│   └── notifications/
│       ├── class-notification-manager.php
│       └── class-email-templates.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── dashboard.css
│   └── js/
│       ├── admin.js
│       └── dashboard.js
├── languages/
│   └── eligius-subscription.pot
├── tests/
│   ├── unit/
│   └── integration/
└── eligius-subscription.php           # Main plugin file
```

### 2.2 Bootstrap & Initialization

**Main Plugin File** (`eligius-subscription.php`):
```php
<?php
/**
 * Plugin Name: Eligius Subscription Management
 * Description: Subscription, licensing, device binding, and payment management for Eligius VPN
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Eligius Team
 * Text Domain: eligius-subscription
 */

if (!defined('ABSPATH')) { exit; }

define('ELIGIUS_SUB_VERSION', '1.0.0');
define('ELIGIUS_SUB_DB_VERSION', '1.0.0');
define('ELIGIUS_SUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ELIGIUS_SUB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load bootstrap
require_once __DIR__ . '/includes/bootstrap.php';

// Initialize plugin
Eligius_Subscription::instance();
```

**Bootstrap** (`includes/bootstrap.php`):
```php
<?php
if (!defined('ABSPATH')) { exit; }

spl_autoload_register(function ($class) {
    $prefix = 'Eligius_Sub\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = ELIGIUS_SUB_PLUGIN_DIR . 'includes/' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) { require_once $file; }
});

class Eligius_Subscription {
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->init_database();
        $this->load_textdomain();
    }
    
    private function init_hooks() {
        register_activation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(ELIGIUS_SUB_PLUGIN_FILE, [__CLASS__, 'uninstall']);
        
        add_action('plugins_loaded', [$this, 'load_components']);
        add_action('init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    public function activate() {
        require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/activation.php';
        Eligius_Sub_Activation::run();
    }
    
    public function deactivate() {
        // Flush rewrite rules, clear caches
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        // Only delete data if explicitly confirmed via option
        if (get_option('eligius_sub_confirm_uninstall', false)) {
            require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/uninstall.php';
            Eligius_Sub_Uninstall::run();
            delete_option('eligius_sub_confirm_uninstall');
        }
    }
    
    public function load_components() {
        // Load all core classes
        require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/core/class-database.php';
        require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/core/class-repository-base.php';
        // ... load all repositories, managers, controllers
    }
    
    public function register_rest_routes() {
        // Register all REST API endpoints
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('eligius-subscription', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}
```

### 2.3 Activation & Database Versioning

**Activation** (`includes/activation.php`):
```php
<?php
class Eligius_Sub_Activation {
    public static function run() {
        global $wpdb;
        $db = new Eligius_Sub_Database();
        $db->create_tables();
        
        // Create default plans
        self::create_default_plans();
        
        // Set default options
        add_option('eligius_sub_db_version', ELIGIUS_SUB_DB_VERSION);
        add_option('eligius_sub_grace_period_hours', 72);
        add_option('eligius_sub_trial_days', 30);
        add_option('eligius_sub_max_devices', 1);
        add_option('eligius_sub_grace_period_hours', 72);
        add_option('eligius_sub_license_key_rotation_days', 90);
        
        // Register cron jobs
        self::schedule_cron_jobs();
        
        flush_rewrite_rules();
    }
    
    private static function create_default_plans() {
        $plan_manager = new Eligius_Sub_Plan_Manager();
        $plans = [
            ['key' => 'trial', 'name' => 'Free Trial', 'days' => 30, 'price_irt' => 0, 'sort' => 0, 'is_trial' => true],
            ['key' => 'monthly', 'name' => 'Monthly', 'days' => 30, 'price_irt' => 150000, 'sort' => 1],
            ['key' => 'quarterly', 'name' => 'Quarterly', 'days' => 90, 'price_irt' => 400000, 'sort' => 2],
            ['key' => 'semiannual', 'name' => 'Semi-annual', 'days' => 180, 'price_irt' => 750000, 'sort' => 3],
            ['key' => 'annual', 'name' => 'Annual', 'days' => 365, 'price_irt' => 1400000, 'sort' => 4],
        ];
        
        foreach ($plans as $plan) {
            $plan_manager->create_plan($plan);
        }
    }
    
    private static function schedule_cron_jobs() {
        if (!wp_next_scheduled('eligius_sub_daily_expiry_check')) {
            wp_schedule_event(time(), 'daily', 'eligius_sub_daily_expiry_check');
        }
        if (!wp_next_scheduled('eligius_sub_daily_renewal_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'eligius_sub_daily_renewal_check');
        }
        if (!wp_next_scheduled('eligius_sub_weekly_audit_cleanup')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'eligius_sub_weekly_audit_cleanup');
        }
    }
}
```

### 2.3 Activation & Database Versioning

**Database Version Upgrades** (`includes/core/class-database.php`):
```php
<?php
class Eligius_Sub_Database {
    private $tables = [
        'eligius_devices' => "
            CREATE TABLE {$wpdb->prefix}eligius_devices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                device_hash CHAR(64) NOT NULL,
                public_key TEXT NOT NULL,
                label VARCHAR(64) DEFAULT '',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_device (user_id, device_hash),
                KEY idx_user_active (user_id, is_active),
                KEY idx_device_hash (device_hash)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_licenses' => "
            CREATE TABLE {$wpdb->prefix}eligius_licenses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                device_id BIGINT UNSIGNED NULL,
                plan_key VARCHAR(32) NOT NULL,
                plan_label VARCHAR(64) NOT NULL,
                duration_days SMALLINT UNSIGNED NOT NULL,
                started_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                status ENUM('ACTIVE','EXPIRED','REVOKED','PENDING_PAYMENT','TRIAL') NOT NULL DEFAULT 'PENDING_PAYMENT',
                source VARCHAR(32) NOT NULL DEFAULT 'payment', -- 'trial', 'payment', 'admin', 'renewal'
                payment_id BIGINT UNSIGNED NULL,
                previous_license_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_status (user_id, status),
                KEY idx_device_active (device_id, status),
                KEY idx_expires (expires_at),
                KEY idx_status_expires (status, expires_at),
                UNIQUE KEY uniq_user_active_plan (user_id, status, plan_key),
                FOREIGN KEY (device_id) REFERENCES {$wpdb->prefix}eligius_devices(id) ON DELETE SET NULL
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payments' => "
            CREATE TABLE {$wpdb->prefix}eligius_payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                license_id BIGINT UNSIGNED NULL,
                gateway VARCHAR(32) NOT NULL,
                gateway_ref VARCHAR(128) NOT NULL,
                authority VARCHAR(128) NULL,
                amount BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'IRT',
                plan_key VARCHAR(32) NOT NULL,
                plan_duration_days SMALLINT UNSIGNED NOT NULL,
                status ENUM('INITIATED','VERIFIED','FAILED','REFUNDED','EXPIRED') NOT NULL DEFAULT 'INITIATED',
                gateway_response JSON NULL,
                initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verified_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_user_status (user_id, status),
                KEY idx_license (license_id),
                KEY idx_gateway_ref (gateway_ref),
                KEY idx_initiated (initiated_at),
                FOREIGN KEY (license_id) REFERENCES {$wpdb->prefix}eligius_licenses(id) ON DELETE SET NULL
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_license_events' => "
            CREATE TABLE {$wpdb->prefix}eligius_license_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                license_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                device_id BIGINT UNSIGNED NULL,
                event_type VARCHAR(48) NOT NULL,
                old_status VARCHAR(32) NULL,
                new_status VARCHAR(32) NULL,
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_license_time (license_id, created_at),
                KEY idx_user_time (user_id, created_at),
                KEY idx_event_type (event_type),
                KEY idx_created (created_at)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_device_transfers' => "
            CREATE TABLE {$wpdb->prefix}eligius_device_transfers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                from_device_id BIGINT UNSIGNED NOT NULL,
                to_device_id BIGINT UNSIGNED NOT NULL,
                otp_code CHAR(6) NULL,
                status ENUM('PENDING','VERIFIED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'PENDING',
                initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_user_status (user_id, status),
                KEY idx_from_device (from_device_id),
                KEY idx_to_device (to_device_id),
                KEY idx_expires (expires_at),
                FOREIGN KEY (from_device_id) REFERENCES {$wpdb->prefix}eligius_devices(id) ON DELETE CASCADE,
                FOREIGN KEY (to_device_id) REFERENCES {$wpdb->prefix}eligius_devices(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
        'eligius_payment_idempotency' => "
            CREATE TABLE {$wpdb->prefix}eligius_payment_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway_ref VARCHAR(128) NOT NULL,
                gateway VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                request_payload JSON NULL,
                response_payload JSON NULL,
                processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_gateway_ref (gateway_ref),
                KEY idx_gateway_status (gateway, status)
            ) {$wpdb->get_charset_collate()}",
        
        'eligius_audit_logs' => "
            CREATE TABLE {$wpdb->prefix}eligius_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                license_id BIGINT UNSIGNED NULL,
                actor_user_id BIGINT UNSIGNED NULL, -- admin who performed action
                event_type VARCHAR(64) NOT NULL,
                severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                details JSON NULL,
                ip VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_license_time (license_id, created_at),
                KEY idx_type_time (event_type, created_at),
                KEY idx_severity_time (severity, created_at)
            ) {$wpdb->get_charset_collate()}",
    ];
    
    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        foreach ($this->tables as $table_name => $sql) {
            $full_name = $wpdb->prefix . $table_name;
            $wpdb->query($sql);
            // Add to wpdb tables list for proper prefixing in queries
            if (!in_array($table_name, $wpdb->tables())) {
                $wpdb->tables[] = $table_name;
            }
        }
    }
    
    public function upgrade($old_version) {
        global $wpdb;
        // Migration logic for each version
        if (version_compare($old_version, '1.1.0', '<')) {
            // Add new columns, indexes, etc.
        }
        update_option('eligius_sub_db_version', ELIGIUS_SUB_DB_VERSION);
    }
    
    public function uninstall() {
        global $wpdb;
        // Only delete if confirmed via option
        if (get_option('eligius_sub_confirm_uninstall', false)) {
            foreach (array_keys($this->tables) as $table) {
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$table");
            }
            delete_option('eligius_sub_db_version');
            delete_option('eligius_sub_confirm_uninstall');
        }
    }
}
```

### 2.4 Module Interaction Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    PLUGIN CORE LAYERS                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  HTTP LAYER (REST API)                                  │   │
│  │  ├── AuthController        /auth/*                       │   │
│  │  ├── DeviceController      /devices/*                    │   │
│  │  ├── PlanController        /plans/*                      │   │
│  │  ├── SubscriptionController /subscriptions/*             │   │
│  │  ├── PaymentController     /payments/*                   │   │
│  │  ├── LicenseController     /licenses/*                   │   │
│  │  └── AdminController       /admin/* (capability-check)  │   │
│  └─────────────────────────────────────────────────────────┘   │
│                            │                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  BUSINESS LOGIC LAYER                                   │   │
│  │  ├── AuthIntegration   ←→ WP JWT / existing OTP        │   │
│  │  ├── DeviceManager     ←→ DeviceRepository             │   │
│  │  ├── LicenseManager    ←→ LicenseRepository            │   │
│  │  ├── SubscriptionManager ←→ SubscriptionRepository     │   │
│  │  ├── PaymentProcessor  ←→ PaymentRepository            │   │
│  │  │                         ↑                             │   │
│  │  │                    GatewayFactory                     │   │
│  │  │                         │                             │   │
│  │  │              ┌──────────┼──────────┐                 │   │
│  │  │              ▼          ▼          ▼                  │   │
│  │  │         ZarinPal     IDPay       Zibal                │   │
│  │  │         Adapter      Adapter    Adapter              │   │
│  │  └─────────────────────────────────────────────────────┘   │
│                            │                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  DATA ACCESS LAYER                                      │   │
│  │  ├── DeviceRepository     → eligius_devices             │   │
│  │  ├── LicenseRepository    → eligius_licenses            │   │
│  │  ├── SubscriptionRepo     → eligius_licenses (view)     │   │
│  │  ├── PaymentRepository    → eligius_payments            │   │
│  │  ├── DeviceTransferRepo   → eligius_device_transfers    │   │
│  │  ├── AuditLogRepository   → eligius_audit_logs          │   │
│  │  └── IdempotencyRepository → payment_idempotency        │   │
│  └─────────────────────────────────────────────────────────┘   │
│                            │                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  INFRASTRUCTURE LAYER                                   │   │
│  │  ├── Database (wpdb + custom tables)                    │   │
│  │  ├── WP REST API + JWT Auth                             │   │
│  │  ├── WP Cron (expiry, renewal, audit cleanup)           │   │
│  │  ├── WP Admin (capability-based access)                 │   │
│  │  ├── WP Nonces + Rate Limiting                          │   │
│  │  └── Audit Logger (all financial/license events)        │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### 2.5 Core Module Responsibilities

| Module | File | Responsibility |
|--------|------|----------------|
| **Database** | `class-database.php` | Schema creation, migrations, versioning |
| **Repositories** | `class-*-repository.php` | Data access, no business logic |
| **AuthIntegration** | `class-auth-integration.php` | JWT validation, WP user sync, existing OTP reuse |
| **DeviceManager** | `class-device-manager.php` | Registration, challenge, verification, transfer |
| **LicenseManager** | `class-license-manager.php` | License lifecycle, validation, offline entitlements |
| **LicenseIssuer** | `class-license-issuer.php` | License creation, signing, blob generation |
| **LicenseValidator** | `class-license-validator.php` | Online/offline validation, signature verification |
| **TrialManager** | `class-trial-manager.php` | Trial logic |
| **DeviceTransfer** | `class-device-transfer.php` | Transfer workflow, OTP verification |
| **SubscriptionManager** | `class-subscription-manager.php` | Plans, subscription lifecycle, renewals |
| **PlanManager** | `class-plan-manager.php` | Plan CRUD, pricing, features |
| **PaymentProcessor** | `class-payment-processor.php` | Payment orchestration, S2S verification |
| **GatewayFactory** | `class-gateway-factory.php` | Provider abstraction, adapter registry |
| **Gateways** | `class-*-adapter.php` | ZarinPal, IDPay, Zibal adapters |
| **API Controllers** | `class-*-controller.php` | REST endpoints, validation, responses |
| **AdminDashboard** | `class-admin-dashboard.php` | wp-admin pages, capabilities, UI |
| **RateLimiter** | `class-rate-limiter.php` | Per-IP, per-user, per-endpoint limits |
| **AuditLogger** | `class-audit-logger.php` | All financial/security events |
| **CronManager** | `class-cron-manager.php` | Expiry, renewal, cleanup jobs |

---

## 3. Integration with Existing Components

### 3.1 Reused Components

| Existing Component | File | Reuse Strategy |
|--------------------|------|----------------|
| **JWT Authentication** | `eligius-otp.php` + `AuthStore.kt` | Extend `AuthIntegration` class to validate JWT tokens using existing `wp-json/jwt-auth/v1/token` |
| **OTP System** | `eligius-otp.php` | Reuse `eligius_otp_send_sms()`, rate limiter, national code validation |
| **User Registration** | `eligius-register.php` | Extend `AuthIntegration` to sync with new device/license tables |
| **Usage Tracking** | `eligius-register.php` (usage endpoints) | Extend `UsageRepository` to include license context |
| **Rate Limiting** | `eligius-otp.php` `eligius_rate_limited()` | Extend `RateLimiter` class with IP/user/endpoint granularity |
| **Database** | WordPress `$wpdb` | Use existing `$wpdb` connection, add custom tables |
| **Cron Jobs** | Existing `eligius-otp.php` cron patterns | Extend `CronManager` for expiry/renewal jobs |
| **Admin UI** | WordPress wp-admin | Build admin pages using WP admin APIs |

### 3.2 Components to Replace/Extend

| Existing | Action | Replacement/Extension |
|----------|--------|----------------------|
| `eligius-register.php` `/register` | **Keep** for legacy | Add device registration hook |
| `eligius-otp.php` `/register/otp-start` | **Extend** | Add `device_hash` parameter, device registration |
| `eligius-otp.php` `/register/otp-verify` | **Keep** | Return device binding status |
| `AuthStore.kt` `registerOtpStart` | **Extend** | Send `device_hash` with hash |
| `AuthStore.kt` `login` | **Extend** | Call `LicenseStore.validate()` after login |
| `TunnelService.kt` `startSession()` | **Add gate** | Call `LicenseStore.ensureValid()` before `run()` |

---

## 3. Integration with Existing Components

### 3.1 Reused Components

| Existing Component | File | Reuse Strategy |
|--------------------|------|----------------|
| **JWT Authentication** | `eligius-otp.php` + `AuthStore.kt` | Extend `AuthIntegration` class to validate JWT tokens using existing `wp-json/jwt-auth/v1/token` |
| **OTP System** | `eligius-otp.php` | Reuse `eligius_otp_send_sms()`, rate limiter, national code validation |
| **User Registration** | `eligius-register.php` | Extend `AuthIntegration` to sync with new device/license tables |
| **Usage Tracking** | `eligius-register.php` (usage endpoints) | Extend `UsageRepository` to include license context |
| **Rate Limiting** | `eligius-otp.php` `eligius_rate_limited()` | Extend `RateLimiter` class with IP/user/endpoint granularity |
| **Database** | WordPress `$wpdb` | Use existing `$wpdb` connection, add custom tables |
| **Cron Jobs** | Existing `eligius-otp.php` cron patterns | Extend `CronManager` for expiry/renewal jobs |
| **Admin UI** | WordPress wp-admin | Build admin pages using WP admin APIs |

### 3.2 Components to Replace/Extend

| Existing | Action | Replacement/Extension |
|----------|--------|----------------------|
| `eligius-register.php` `/register` | **Keep** for legacy | Add device registration hook |
| `eligius-otp.php` `/register/otp-start` | **Extend** | Add `device_hash` parameter, device registration |
| `eligius-otp.php` `/register/otp-verify` | **Keep** | Return device binding status |
| `AuthStore.kt` `registerOtpStart` | **Extend** | Send `device_hash` with hash |
| `AuthStore.kt` `login` | **Extend** | Call `LicenseStore.validate()` after login |
| `TunnelService.kt` `startSession()` | **Add gate** | Call `LicenseStore.ensureValid()` before `run()` |

---

## 4. Deployment Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     DEPLOYMENT TOPOLOGY                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   ┌─────────────┐         HTTPS          ┌──────────────────┐  │
│   │   ANDROID   │ ◄────────────────────► │   WORDPRESS      │  │
│   │   DEVICES   │   REST API /eligius/v1 │   (wp.mrkiyan.ir) │  │
│   └─────────────┘                        └────────┬─────────┘  │
│                                                   │           │
│                              ┌────────────────────┼──────────┐  │
│                              ▼                    ▼          ▼  │
│                     ┌─────────────┐        ┌───────────┐ ┌──────────┐
│                     │   MARIADB   │        │  IPPANEL  │ │  GATEWAY │
│                     │  (WordPress │        │   (SMS)   │ │  (Zarin- │
│                     │  Database)  │        │           │ │  Pal/    │
│                     │             │        │           │ │  IDPay)  │
│                     └─────────────┘        └───────────┘ └──────────┘
│                                                                 │
│   CI/CD: GitHub Actions ──build──► Release Assets ──► FTP ────►│
│                  (native libs, libbox, signed APKs)  /eligius/ │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Security Architecture Summary

| Layer | Mechanism |
|-------|-----------|
| **Transport** | TLS 1.2+ (enforced by server) |
| **Authentication** | JWT (RS256) via `wp-json/jwt-auth/v1/token` |
| **Device Identity** | Android Keystore ECDSA P-256, non-exportable *(proposed mechanism — TD-8)* |
| **Device Binding** | Server-verified challenge-response (ECDSA P-256) *(proposed mechanism — TD-8)* |
| **One Active Device** | Server-side enforcement with row-level locking (`FOR UPDATE`) — applies to trial AND paid plans; transfer via OTP deactivates the old device |
| **License Validation** | Online (JWT) + Offline (signed entitlement blob, 72h max) |
| **License Signing** | Ed25519 or ECDSA P-256, private key only on server |
| **Payment Verification** | Server-to-Server (S2S) with gateway API + HMAC |
| **Rate Limiting** | Per-IP, per-user, per-endpoint (WP transient + DB) |
| **Audit Logging** | Immutable append-only `eligius_audit_logs` table |
| **Key Rotation** | 90-day license signing key rotation, JWKS endpoint |
| **Revocation** | CRL-style list + online check, cached 5 min |

---

## 6. Confirmed Decisions

The following decisions have been explicitly approved by the owner and must be reflected consistently across all documents:

### Backend Architecture
- Backend: WordPress + dedicated plugin `eligius-subscription`
- Database: Independent MariaDB relational tables
- Admin Panel: Custom pages inside `wp-admin`
- Reuse existing WordPress infrastructure
- Reuse existing OTP and authentication capabilities where technically appropriate

### User Authentication
- Mobile number + password login **retained**
- SMS OTP login added as **optional** method
- User is **not forced** to use both password and OTP simultaneously

### Payment Gateway
- First gateway: **ZarinPal**
- Additional gateways: IDPay, Zibal
- Adapter-based architecture for easy extensibility
- Server-to-Server verification with Idempotency

### Offline Grace Period
- Duration: **72 hours**
- If network disconnected, user can use VPN until Offline Entitlement expires
- After 72 hours without server validation, VPN stops

### Subscription Revocation During Active Connection
- Online: CRL checked every 5 minutes, immediate tunnel stop on revocation
- Offline: New revocation not detectable; offline entitlement valid until 72h expiry
- CRL check alone insufficient — must specify how result affects `TunnelService`
- Avoid term "instant disconnect" without specifying timing constraints

### Trial
- Duration: **30 days**
- One trial per **account** (user_id) — primary anti-reset control, immune to reinstall by design
- Trial bound to the current device at activation; transfer with OTP verification
- Prerequisite: mobile number verified via **OTP** before trial grant
- Layered server-side rate limiting and passive anomaly flagging (device_hash / IP correlation) — **no hard IP blocks** (CGNAT/shared-IP users must never be locked out)
- **No mandatory national code** or other unjustified identity data collection
- Multi-account creation risk acknowledged as a separate, incompletely preventable risk (see 09-FINAL-DESIGN-REPORT.md → TD-1)

### One Active Device Policy
- Each account can use the VPN on **exactly one active device at any time** — this applies to the **free trial AND all paid plans** alike
- Transfer to a new device is possible **only via OTP verification** (device transfer workflow)
- After a successful transfer, the **previous device is immediately no longer authorized** (its `is_active` flag clears and license binding moves to the new device)
- The backend enforces this **server-side with concurrency control** (`SELECT … FOR UPDATE` on the user's device/license rows) so two devices can never simultaneously bind to the same account — see 02-DATABASE-DESIGN.md §2.8
- Enforcement point: license/device validation on every connect and every periodic tick — an unauthorized second device is rejected with `DEVICE_LIMIT_REACHED`

---

## 7. Remaining Technical Decisions

The canonical list of remaining technical decisions (TD-1 … TD-10) is maintained in **`09-FINAL-DESIGN-REPORT.md` → "Remaining Technical Decisions"**. Notably:

| # | Decision | Status |
|---|----------|--------|
| TD-2 | Payment currency unit (IRT vs IRR at gateway) | Blocking P4 |
| TD-4 | License signing algorithm — Ed25519 (sodium) vs ECDSA fallback | Blocking P1 — verify `sodium` on host |
| TD-6 | ZarinPal merchant account (sandbox/production) | Blocking P4 — owner action |
| TD-8 | Device-binding signature mechanism (ECDSA P-256 is a **proposal**, not yet owner-confirmed) | Blocking P2 |

All previously listed "open" items that the owner has since confirmed (gateway order, login model, offline grace, admin panel location, trial duration/binding) have been moved to §6 Confirmed Decisions and are no longer decisions.

---

## 8. Next Steps

With the architecture defined, the next documents will detail:

1. **02-DATABASE-DESIGN.md** — Complete relational schema with ER diagram
2. **03-API-SPECIFICATION.md** — Full REST API contracts
3. **04-SECURITY-AND-LICENSE.md** — Device binding, license, offline entitlements
4. **05-PAYMENT-AND-BUSINESS-LOGIC.md** — Subscription rules, payment flows
5. **06-ANDROID-INTEGRATION.md** — Android integration contract
6. **07-IMPLEMENTATION-ROADMAP.md** — Phased implementation plan
7. **08-ADMIN-PANEL-SPECIFICATION.md** — wp-admin interface design

All documents will reference actual file paths from this repository and maintain mutual consistency.