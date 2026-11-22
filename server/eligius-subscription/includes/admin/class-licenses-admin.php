<?php
/**
 * Licenses management page (doc 01 §2.1 admin/class-licenses-admin.php).
 * Skeleton list + revoke/extend actions (Roadmap P1 AC: admin can revoke and
 * extend licenses from wp-admin).
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_License_Repository;

class Class_Licenses_Admin {

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $filters = [
            'user_id'  => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
            'status'   => isset($_GET['status']) ? sanitize_key($_GET['status']) : '',
            'plan_key' => isset($_GET['plan_key']) ? sanitize_key($_GET['plan_key']) : '',
        ];
        $page    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $rows    = Class_License_Repository::admin_list($filters, $page, 20);

        $msg = isset($_GET['eligius_msg']) ? sanitize_text_field(wp_unslash($_GET['eligius_msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('لایسنس‌ها', 'eligius-subscription'); ?></h1>
            <?php if ('license_revoked' === $msg) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('لایسنس لغو شد.', 'eligius-subscription'); ?></p></div>
            <?php elseif ('license_extended' === $msg) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('لایسنس تمدید شد.', 'eligius-subscription'); ?></p></div>
            <?php elseif ('' !== $msg) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="eligius-licenses" />
                <input type="number" name="user_id" placeholder="شناسه کاربر" value="<?php echo esc_attr($filters['user_id'] ?: ''); ?>" min="0" style="width:110px;" />
                <select name="status">
                    <option value="">— وضعیت —</option>
                    <?php foreach (['ACTIVE', 'TRIAL', 'EXPIRED', 'REVOKED', 'PENDING_PAYMENT'] as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html($status); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="plan_key" placeholder="کلید پلن" value="<?php echo esc_attr($filters['plan_key']); ?>" />
                <button class="button"><?php esc_html_e('فیلتر', 'eligius-subscription'); ?></button>
            </form>

            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>شناسه</th><th>کاربر</th><th>پلن</th><th>وضعیت</th><th>شروع</th><th>انقضا</th><th>دستگاه</th><th>منبع</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('لایسنسی یافت نشد.', 'eligius-subscription'); ?></td></tr>
                <?php else : foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->user_id); ?></td>
                        <td><?php echo esc_html($row->plan_key); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo esc_html($row->started_at); ?></td>
                        <td><?php echo esc_html($row->expires_at); ?></td>
                        <td><?php echo esc_html($row->device_id ?: '—'); ?></td>
                        <td><?php echo esc_html($row->source); ?></td>
                        <td>
                            <details>
                                <summary><?php esc_html_e('عملیات', 'eligius-subscription'); ?></summary>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;">
                                    <input type="hidden" name="action" value="eligius_sub_revoke_license" />
                                    <?php wp_nonce_field('eligius_sub_admin_action'); ?>
                                    <input type="hidden" name="license_id" value="<?php echo esc_attr($row->id); ?>" />
                                    <input type="text" name="reason" placeholder="<?php esc_attr_e('دلیل', 'eligius-subscription'); ?>" style="width:160px;" />
                                    <button class="button button-link-delete"><?php esc_html_e('لغو', 'eligius-subscription'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;">
                                    <input type="hidden" name="action" value="eligius_sub_extend_license" />
                                    <?php wp_nonce_field('eligius_sub_admin_action'); ?>
                                    <input type="hidden" name="license_id" value="<?php echo esc_attr($row->id); ?>" />
                                    <input type="number" name="days" min="1" max="3650" value="30" style="width:70px;" />
                                    <input type="text" name="reason" placeholder="<?php esc_attr_e('دلیل', 'eligius-subscription'); ?>" style="width:120px;" />
                                    <button class="button"><?php esc_html_e('تمدید (روز)', 'eligius-subscription'); ?></button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <p>
                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">«</a>
                <?php endif; ?>
                <?php esc_html_e('صفحه', 'eligius-subscription'); ?> <?php echo esc_html($page); ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">»</a>
            </p>
        </div>
        <?php
    }
}
