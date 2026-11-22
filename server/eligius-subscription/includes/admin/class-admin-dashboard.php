<?php
/**
 * Admin dashboard (doc 01 §2.1 admin/class-admin-dashboard.php).
 * Skeleton stats page per Roadmap P1 "Admin UI: Dashboard: stats".
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_License_Repository;

class Class_Admin_Dashboard {

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $stats  = Class_License_Repository::stats() ?: ['active' => 0, 'trials' => 0, 'revoked' => 0, 'expired' => 0, 'total' => 0];
        $issuer = \Eligius_Sub\License\Class_License_Issuer::instance();
        $kid    = $issuer->key_id();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Eligius — داشبورد اشتراک', 'eligius-subscription'); ?></h1>

            <?php if ($issuer->is_fallback_key()) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e('کلید امضای لایسنس در دیتابیس ذخیره شده (حالت توسعه). برای production، ثابت ELIGIUS_SUB_SIGNING_PRIVATE_KEY را در wp-config.php به‌صورت کلید خصوصی Ed25519 base64 تعریف کنید.', 'eligius-subscription'); ?>
                </p></div>
            <?php endif; ?>

            <p style="color:#646970;">
                <?php
                printf(
                    /* translators: %s: signing key id */
                    esc_html__('Signing key id: %s (rotation support: multiple kids accepted — doc 04 §6.1)', 'eligius-subscription'),
                    esc_html($kid)
                );
                ?>
            </p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;">
                <?php
                $cards = [
                    ['label' => 'Active licenses', 'value' => $stats['active'], 'color' => '#00a32a'],
                    ['label' => 'Active trials', 'value' => $stats['trials'], 'color' => '#0073aa'],
                    ['label' => 'Expired', 'value' => $stats['expired'], 'color' => '#dba617'],
                    ['label' => 'Revoked', 'value' => $stats['revoked'], 'color' => '#d63638'],
                    ['label' => 'Total rows', 'value' => $stats['total'], 'color' => '#50575e'],
                ];
                foreach ($cards as $card) :
                    ?>
                    <div style="min-width:170px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr($card['color']); ?>;padding:12px 16px;">
                        <div style="font-size:28px;font-weight:600;"><?php echo esc_html($card['value']); ?></div>
                        <div style="color:#50575e;"><?php echo esc_html($card['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="margin-top:16px;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eligius-licenses')); ?>">
                    <?php esc_html_e('مدیریت لایسنس‌ها', 'eligius-subscription'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eligius-audit-logs')); ?>">
                    <?php esc_html_e('لاگ‌های امنیتی', 'eligius-subscription'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
