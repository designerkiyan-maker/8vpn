<?php
/**
 * Gateway factory (docs 07 §P4, CD-3).
 *
 * TD-6 (owner decision): Sandbox-first. Production adapters are HARD-LOCKED:
 * even with complete production settings the factory refuses to build a
 * production gateway while Class_Gateway_Factory::PRODUCTION_LOCKED is true
 * (default). No code path can bypass this without an explicit source change.
 *
 * Test hook: the `eligius_sub_payment_gateway_instance` filter lets tests
 * supply a Mock gateway instance (docs: "تست‌ها با Mock/Fixture معتبر").
 */

namespace Eligius_Sub\Payment;

defined('ABSPATH') || exit;

use Eligius_Sub\Payment\Gateways\Class_Zarinpal_Adapter;

class Class_Gateway_Factory {

    /** Production payments are locked until the owner explicitly flips this. */
    const PRODUCTION_LOCKED = true;

    /** Read the merchant ID: wp-config constant wins over the admin option. */
    public static function merchant_id(string $gateway_key): string {
        $const = 'ELIGIUS_' . strtoupper($gateway_key) . '_MERCHANT_ID';
        if (defined($const) && '' !== constant($const)) {
            return (string) constant($const);
        }
        return (string) get_option('eligius_' . $gateway_key . '_merchant_id', '');
    }

    /** Sandbox enabled for a gateway (admin option; sandbox-first policy). */
    public static function sandbox_enabled(string $gateway_key): bool {
        $env = get_option('eligius_' . $gateway_key . '_environment', 'sandbox');
        return ('production' !== $env);
    }

    /** Whether a gateway is enabled in admin settings. */
    public static function gateway_enabled(string $gateway_key): bool {
        return (bool) get_option('eligius_' . $gateway_key . '_enabled', false);
    }

    /** Public callback URL for a gateway. */
    public static function callback_url(string $gateway_key): string {
        return rest_url('eligius/v1/payments/callback/' . rawurlencode($gateway_key));
    }

    /**
     * Build an adapter instance for an enabled gateway, or null.
     *
     * @return Interface_Gateway_Adapter|null
     */
    public static function create(string $gateway_key) {
        // Test/mock hook (docs: "تست‌ها را با Mock/Fixture معتبر انجام بده").
        $mock = apply_filters('eligius_sub_payment_gateway_instance', null, $gateway_key);
        if ($mock instanceof Interface_Gateway_Adapter) {
            return $mock;
        }

        $env = self::detect_environment($gateway_key);

        if ('production' === $env && self::PRODUCTION_LOCKED) {
            // TD-6 hard lock: production stays unusable regardless of settings.
            return null;
        }

        if (!self::gateway_enabled($gateway_key)) {
            return null;
        }

        $merchant = self::merchant_id($gateway_key);
        if ('' === $merchant) {
            return null;
        }

        switch ($gateway_key) {
            case 'zarinpal':
                return new Class_Zarinpal_Adapter([
                    'merchant_id'  => $merchant,
                    'callback_url' => self::callback_url($gateway_key),
                    'sandbox'      => self::sandbox_enabled($gateway_key),
                ]);
            case 'idpay':
            case 'zibal':
                // Future phases — adapters not implemented yet (CD-3 ordering).
                return null;
        }

        return null;
    }

    /** Keys that may appear in the factory (enabled or not) for the admin UI. */
    public static function known_gateways(): array {
        return ['zarinpal', 'idpay', 'zibal'];
    }

    private static function detect_environment(string $gateway_key): string {
        if (defined('ELIGIUS_SUB_PRODUCTION') && ELIGIUS_SUB_PRODUCTION) {
            return 'production';
        }
        $env = get_option('eligius_' . $gateway_key . '_environment', 'sandbox');
        return ('production' === $env) ? 'production' : 'sandbox';
    }
}
