<?php
/**
 * Audit logs viewer (doc 01 §2.1 admin/class-audit-logs-admin.php,
 * doc 03 §8.4). Skeleton list with severity/type filters.
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;

class Class_Audit_Logs_Admin {

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $filters = [
            'event_type' => isset($_GET['event_type']) ? sanitize_text_field(wp_unslash($_GET['event_type'])) : '',
            'severity'   => isset($_GET['severity']) ? sanitize_key($_GET['severity']) : '',
            'user_id'    => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
        ];
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $rows = Class_Audit_Log_Repository::admin_list($filters, $page, 30);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('لاگ‌های امنیتی', 'eligius-subscription'); ?></h1>

            <form method="get">
                <input type="hidden" name="page" value="eligius-audit-logs" />
                <input type="text" name="event_type" placeholder="event_type" value="<?php echo esc_attr($filters['event_type']); ?>" />
                <select name="severity">
                    <option value="">— severity —</option>
                    <?php foreach (['INFO', 'WARNING', 'CRITICAL'] as $severity) : ?>
                        <option value="<?php echo esc_attr($severity); ?>" <?php selected($filters['severity'], $severity); ?>><?php echo esc_html($severity); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="user_id" placeholder="User ID" value="<?php echo esc_attr($filters['user_id'] ?: ''); ?>" min="0" style="width:110px;" />
                <button class="button"><?php esc_html_e('فیلتر', 'eligius-subscription'); ?></button>
            </form>

            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>ID</th><th>Time</th><th>Event</th><th>Severity</th><th>User</th><th>License</th><th>Device</th><th>Actor</th><th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('موردی یافت نشد.', 'eligius-subscription'); ?></td></tr>
                <?php else : foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html($row->event_type); ?></td>
                        <td><?php echo esc_html($row->severity); ?></td>
                        <td><?php echo esc_html($row->user_id ?: '—'); ?></td>
                        <td><?php echo esc_html($row->license_id ?: '—'); ?></td>
                        <td><?php echo esc_html($row->device_id ?: '—'); ?></td>
                        <td><?php echo esc_html($row->actor_user_id ?: '—'); ?></td>
                        <td style="max-width:320px;word-break:break-all;">
                            <?php
                            // details is JSON — decode for display, never raw.
                            $decoded = json_decode((string) $row->details, true);
                            echo esc_html(is_array($decoded) ? wp_json_encode($decoded, JSON_UNESCAPED_UNICODE) : '');
                            ?>
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
