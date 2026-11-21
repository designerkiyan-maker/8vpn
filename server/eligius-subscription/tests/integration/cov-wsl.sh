#!/bin/bash
# Phase 6 — backend coverage battery under PCOV (run INSIDE WSL, as root).
#   wsl -u root -e bash /mnt/e/xampp/htdocs/8VPN/server/eligius-subscription/tests/integration/cov-wsl.sh
#
# Windows drvfs + PCOV crash on instrumented requires, so the plugin and the
# suites are copied to /srv (native ext4) first. ALL steps run in ONE wsl
# invocation because the mariadb service dies between invocations (data on
# disk persists; only the running service is per-invocation).
#
# Each suite runs under /srv/cov-runner.php — Xdebug LINE coverage
# (xdebug_start_code_coverage at boot, collect at shutdown →
# /srv/cov/<name>.json). NOTE (Phase 6 finding): the locally built pcov 1.0.11
# loads on this PHP 8.3.6 build but instruments NOTHING (every collect() is
# empty, with or without pcov.directory) — Xdebug mode=coverage is the only
# working line-coverage backend here, and it is what produced the 69.9% baseline.
# Branch coverage is NOT measurable: XDEBUG_CC_BRANCH_CHECK segfaults on the
# plugin requires (documented, unchanged).
set -u
export ELIGIUS_WP_ROOT=/srv/wp/
export P6_PHP_BIN=/usr/bin/php

# Run from the native FS (coverage paths must live under /srv — drvfs copies
# are only the SOURCE for the refresh below).
cd /srv

PLUGIN_SRC=/mnt/e/xampp/htdocs/8VPN/server/eligius-subscription
PLUG=/srv/wp/wp-content/plugins/eligius-subscription

service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1
sleep 2

# Refresh the plugin + suite copies from the repo (drvfs → native FS).
rm -rf "$PLUG"
cp -r "$PLUGIN_SRC" "$PLUG"
rm -rf /srv/suites /srv/unit-tree
mkdir -p /srv/suites /srv/cov
cp "$PLUG"/tests/integration/*.php /srv/suites/
# Unit suite runs FROM the plugin copy itself: run-tests.php resolves the
# plugin root as dirname(__DIR__, 3) → /srv/wp/wp-content/plugins/
# eligius-subscription — the SAME paths cov-aggregate.php merges, so the unit
# contribution lands in the same per-class buckets as the integration suites.
rm -f /srv/cov/*.json

run_suite() { P6_COV_OUT="$2" php -d zend_extension=xdebug.so -d xdebug.mode=coverage /srv/cov-runner.php "$1"; }

echo "===== payment-flow =====";   run_suite /srv/suites/payment-flow-test-full.php   payment-flow-test-full
echo "===== transfer =====";      run_suite /srv/suites/transfer-security-test.php   transfer-security-test
echo "===== trial =====";         run_suite /srv/suites/trial-eligibility-test.php   trial-eligibility-test
echo "===== rate-limiter =====";  run_suite /srv/suites/rate-limiter-test.php        rate-limiter-test
echo "===== endpoint =====";      run_suite /srv/suites/endpoint-coverage-test.php   endpoint-coverage-test
echo "===== fail-closed =====";   run_suite /srv/suites/fail-closed-test.php         fail-closed-test
echo "===== rest-edges =====";    run_suite /srv/suites/rest-edges-test.php          rest-edges-test
echo "===== concurrency =====";   run_suite /srv/suites/payment-concurrency-test.php payment-concurrency-test
echo "===== unit =====";          run_suite "$PLUG/tests/unit/backend/run-tests.php" unit

echo "===== AGGREGATE (Line) ====="
php /srv/cov-aggregate.php
