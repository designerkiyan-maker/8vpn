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

Note: Phase 0 scaffold only — activating the plugin performs no database
changes. Tables, options, and cron jobs are introduced in Phase 1.

== Changelog ==

= 1.0.0 =
* Phase 0 scaffold: plugin skeleton, autoloader, activation/deactivation/
  uninstall guards, unit test harness, CI pipeline. No business logic yet.
