<?php
/**
 * Users management page (doc 01 §2.1 admin/class-users-admin.php).
 * Skeleton list: subscription status + device count per account (Roadmap P1
 * "Admin UI: Users list").
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_UTC_Time;

class Class_Users_Admin {

    public static function render() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $search   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page     = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 30;
        $offset   = ($page - 1) * $per_page;

        $users_table  = $wpdb->users;
        $lic_table    = $wpdb->prefix . 'eligius_licenses';
        $dev_table    = $wpdb->prefix . 'eligius_devices';

        $where_sql  = '';
        $where_args = [];
        if ('' !== $search) {
            $where_sql   = 'WHERE (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $like        = '%' . $wpdb->esc_like($search) . '%';
            $where_args  = [$like, $like, $like];
        }

        $now_mysql = Class_UTC_Time::mysql_utc();
        $sql = "SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered,
                    (SELECT l.status FROM `{$lic_table}` l
                      WHERE l.user_id = u.ID AND l.status IN ('ACTIVE','TRIAL') AND l.expires_at > %s
                      ORDER BY l.expires_at DESC LIMIT 1) AS sub_status,
                    (SELECT l.plan_key FROM `{$lic_table}` l
                      WHERE l.user_id = u.ID AND l.status IN ('ACTIVE','TRIAL') AND l.expires_at > %s
                      ORDER BY l.expires_at DESC LIMIT 1) AS plan_key,
                    (SELECT COUNT(*) FROM `{$dev_table}` d WHERE d.user_id = u.ID) AS devices_count
                FROM `{$users_table}` u
                {$where_sql}
                ORDER BY u.ID DESC
                LIMIT %d OFFSET %d";
        $where_args[] = $per_page;
        $where_args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $where_args));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('کاربران', 'eligius-subscription'); ?></h1>

            <form method="get">
                <input type="hidden" name="page" value="eligius-users" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search username / email', 'eligius-subscription'); ?>" />
                <button class="button"><?php esc_html_e('جستجو', 'eligius-subscription'); ?></button>
            </form>

            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>شناسه</th><th>نام کاربری</th><th>ایمیل</th><th>نام</th><th>تاریخ عضویت</th><th>اشتراک</th><th>پلن</th><th>دستگاه‌ها</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="8"><?php esc_html_e('کاربری یافت نشد.', 'eligius-subscription'); ?></td></tr>
                <?php else : foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->ID); ?></td>
                        <td><?php echo esc_html($row->user_login); ?></td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo esc_html($row->display_name); ?></td>
                        <td><?php echo esc_html($row->user_registered); ?></td>
                        <td><?php echo esc_html($row->sub_status ?: '—'); ?></td>
                        <td><?php echo esc_html($row->plan_key ?: '—'); ?></td>
                        <td><?php echo esc_html($row->devices_count); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <p>
                <?php esc_html_e('صفحه', 'eligius-subscription'); ?> <?php echo esc_html($page); ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">»</a>
            </p>
        </div>
        <?php
    }
}
