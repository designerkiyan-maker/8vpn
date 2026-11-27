<?php
/**
 * Plugin Name: Eligius Subscription Management
 * Description: Subscription, licensing, device binding, and payment management for the Eligius VPN Android client.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Eligius Team
 * License: AGPL-3.0-or-later
 * Text Domain: eligius-subscription
 *
 * Phase 0 scaffold — structure per docs/architecture/eligius-subscription/01-SYSTEM-ARCHITECTURE.md §2.2.
 * No business logic lives in this phase: activation only verifies the environment.
 */

if (!defined('ABSPATH')) { exit; }

define('ELIGIUS_SUB_VERSION', '1.0.0');
define('ELIGIUS_SUB_DB_VERSION', '1.3.0');
define('ELIGIUS_SUB_PLUGIN_FILE', __FILE__);
define('ELIGIUS_SUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ELIGIUS_SUB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load bootstrap
require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/bootstrap.php';

// Initialize plugin
Eligius_Subscription::instance();
