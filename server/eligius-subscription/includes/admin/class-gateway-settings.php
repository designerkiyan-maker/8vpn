<?php
/**
 * Gateway Settings admin page (docs 08 §5 "Gateway Settings", Roadmap P4).
 *
 * TD-6 (owner decision): Sandbox-first — the environment selector only
 * offers Sandbox; Production is displayed as LOCKED and is refused by
 * Class_Gateway_Factory::PRODUCTION_LOCKED regardless of stored settings.
 *
 * Secrets: the merchant ID is stored as a non-autoloaded option (or the
 * ELIGIUS_ZARINPAL_MERCHANT_ID wp-config constant which wins) and is only
 * ever rendered masked — never in full, never in logs.
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Payment\Class_Gateway_Factory;

class Class_Gateway_Settings {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_page'], 20);
        add_action('admin_post_eligius_sub_save_gateway', [$this, 'handle_save']);
        add_action('admin_post_eligius_sub_test_gateway', [$this, 'handle_test']);
    }

    public function register_page() {
        add_submenu_page(
            'eligius-sub',
            'Payment Settings',
            'Payment Settings',
            'manage_options',
            'eligius-payments',
            [$this, 'render']
        );
    }

    private function guard() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eligius_sub_gateway')) {
            wp_die('نشانه امنیتی نامعتبر است.');
        }
    }

    public function handle_save() {
        $this->guard();

        $enabled   = isset($_POST['zarinpal_enabled']) ? 1 : 0;
        $merchant  = sanitize_text_field(wp_unslash($_POST['zarinpal_merchant'] ?? ''));

        // Only overwrite the stored merchant when a new value was typed
        // (masked display means empty input = keep existing).
        if ('' !== $merchant) {
            update_option('eligius_zarinpal_merchant_id', $merchant, false);
        }

        // TD-6: environment is FORCED to sandbox — production stays locked.
        update_option('eligius_zarinpal_environment', 'sandbox', false);
        update_option('eligius_zarinpal_enabled', $enabled, false);

        wp_safe_redirect(add_query_arg(['eligius_msg' => 'saved'], admin_url('admin.php?page=eligius-payments')));
        exit;
    }

    public function handle_test() {
        $this->guard();

        $adapter = Class_Gateway_Factory::create('zarinpal');
        $result = 'ZarinPal is not active/configured (enable it, set a merchant ID, keep Sandbox).';
        if ($adapter) {
            try {
                // Sandbox-only connectivity probe — a minimal request; never
                // saved, never shown to users, no real money (sandbox env).
                $init = $adapter->initiate_payment([
                    'amount_irt'  => 10000,
                    'description' => 'Eligius connectivity test',
                    'callback_url'=> $adapter->get_callback_url(),
                ]);
                $result = !empty($init['authority'])
                    ? 'Sandbox connection OK — authority received.'
                    : 'Sandbox responded without an authority.';
            } catch (\Exception $e) {
                $result = 'Sandbox connection FAILED: ' . $e->getMessage();
            }
        }

        wp_safe_redirect(add_query_arg(['eligius_msg' => rawurlencode($result)], admin_url('admin.php?page=eligius-payments')));
        exit;
    }

    private static function masked(string $value): string {
        if ('' === $value) return '';
        $len = strlen($value);
        return substr($value, 0, min(4, $len)) . str_repeat('•', max(4, $len - 4));
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $enabled     = (bool) get_option('eligius_zarinpal_enabled', false);
        $merchant    = (string) get_option('eligius_zarinpal_merchant_id', '');
        $constSet    = defined('ELIGIUS_ZARINPAL_MERCHANT_ID') && '' !== ELIGIUS_ZARINPAL_MERCHANT_ID;
        $hasMerchant = $constSet || '' !== $merchant;
        $env         = 'sandbox (locked)';

        $msg = isset($_GET['eligius_msg']) ? sanitize_text_field(wp_unslash($_GET['eligius_msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('تنظیمات پرداخت', 'eligius-subscription'); ?></h1>

            <?php if ('saved' === $msg) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('تنظیمات ذخیره شد.', 'eligius-subscription'); ?></p></div>
            <?php elseif ('' !== $msg) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <?php if (Class_Gateway_Factory::PRODUCTION_LOCKED) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e('پرداخت واقعی قفل است (TD-6، اولویت با تست). در این مرحله فقط محیط Sandbox قابل استفاده است.', 'eligius-subscription'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('eligius_sub_gateway'); ?>
                <input type="hidden" name="action" value="eligius_sub_save_gateway" />

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('زرین‌پال', 'eligius-subscription'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="zarinpal_enabled" value="1" <?php checked($enabled); ?> />
                                <?php esc_html_e('فعال‌سازی درگاه (فقط Sandbox)', 'eligius-subscription'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('محیط', 'eligius-subscription'); ?></th>
                        <td>
                            <code>Sandbox</code>
                            <span style="color:#d63638; margin-left:8px;">
                                <?php echo esc_html('Production: LOCKED (' . $env . ')'); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="zarinpal_merchant"><?php esc_html_e('شناسه پذیرنده (Merchant ID)', 'eligius-subscription'); ?></label></th>
                        <td>
                            <input type="text" id="zarinpal_merchant" name="zarinpal_merchant"
                                   autocomplete="off"
                                   placeholder="<?php echo esc_attr($constSet ? esc_html__('(provided by wp-config constant)') : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'); ?>"
                                   class="regular-text" />
                            <?php if ($hasMerchant) : ?>
                                <p style="color:#646970;">
                                    <?php printf(
                                        esc_html__('Configured (masked): %s%s', 'eligius-subscription'),
                                        '<code>' . esc_html(self::masked($constSet ? ELIGIUS_ZARINPAL_MERCHANT_ID : $merchant)) . '</code>',
                                        $constSet ? ' — wp-config constant takes precedence' : ''
                                    ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('آدرس بازگشت (Callback)', 'eligius-subscription'); ?></th>
                        <td><code><?php echo esc_html(Class_Gateway_Factory::callback_url('zarinpal')); ?></code></td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('ذخیره تنظیمات', 'eligius-subscription')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <?php wp_nonce_field('eligius_sub_gateway'); ?>
                <input type="hidden" name="action" value="eligius_sub_test_gateway" />
                <?php submit_button(esc_html__('تست اتصال Sandbox', 'eligius-subscription'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }
}
