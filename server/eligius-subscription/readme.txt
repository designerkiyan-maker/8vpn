=== Eligius Subscription Management ===
Contributors: eligiusteam
Tags: subscription, licensing, vpn
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: AGPL-3.0-or-later

Subscription, licensing, device binding, and payment management for the Eligius VPN Android client.

== Description ==

Backend WordPress plugin of the Eligius subscription system. It provides the
licensing core, device binding (one active device), trial management, signed
offline entitlements, and payment gateway integration for the Eligius Android
client (ir.mrkiyan.eligius).

Architecture documentation: docs/architecture/eligius-subscription/ (repository
commit cd20410).

== Installation ==

1. Upload the `eligius-subscription` directory to `wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.

Note: activating the plugin creates the eight wp_eligius_* tables (InnoDB),
seeds default plans, registers cron schedules, and requires the PHP sodium
extension (Ed25519 signing — doc 04 §4.2).

== Changelog ==

= 1.0.0 =
* Phase 0 scaffold: plugin skeleton, autoloader, activation/deactivation/
  uninstall guards, unit test harness, CI pipeline.
* Phase 1: licensing core — eight-table schema, device registration with
  one-active-device enforcement (CD-9), 30-day account-keyed trial, license
  status/validate/offline-entitlement/revocation-list endpoints, Ed25519-signed
  offline entitlements, device challenge/verify, OTP device transfer, admin
  dashboard/licences/users/audit-log pages, daily expiry + weekly cleanup cron.
