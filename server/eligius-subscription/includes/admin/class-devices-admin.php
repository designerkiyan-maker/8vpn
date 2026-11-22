<?php
/**
 * Devices management admin page (docs 08 §3.3, Roadmap P5).
 *
 * Admin can:
 *  - Release (deactivate) the active device → user can register a new one
 *  - Block a device → backend-enforced rejection in all auth paths
 *  - These are SEPARATE from license revoke (Class_License_Manager::revoke)
 */
namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_Audit_Log_Repository;

class Class_Devices_Admin {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_eligius_sub_release_device', [$this, 'handle_release']);
        add_action('admin_post_eligius_sub_block_device', [$this, 'handle_block']);
        add_action('admin_post_eligius_sub_unblock_device', [$this, 'handle_unblock']);
    }

    public function handle_release() {
        \Eligius_Sub\Admin\Class_Admin_Menu::instance()->device_guard();
        $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
        $device = Class_Device_Repository::find($device_id);
        if (!$device) {
            $this->redirect_err('Device not found.');
            return;
        }

        Class_Device_Repository::set_active($device_id, false);
        // Also unbind the license (device_id → NULL so the license isn't stuck).
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            "UPDATE {$GLOBALS['wpdb']->prefix}eligius_licenses
              SET device_id = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE device_id = %d AND status IN ('ACTIVE','TRIAL')",
            $device_id
        ));

        Class_Audit_Log_Repository::insert_audit([
            'user_id'       => (int) $device->user_id,
            'device_id'     => $device_id,
            'actor_user_id' => get_current_user_id(),
            'event_type'    => 'admin.device.released',
            'severity'      => 'INFO',
        ]);

        $this->redirect_ok('Device released.');
    }

    public function handle_block() {
        \Eligius_Sub\Admin\Class_Admin_Menu::instance()->device_guard();
        $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
        $device = Class_Device_Repository::find($device_id);
        if (!$device) {
            $this->redirect_err('Device not found.');
            return;
        }

        Class_Device_Repository::set_blocked($device_id, true);
        Class_Device_Repository::set_active($device_id, false);
        // Also unbind the license.
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            "UPDATE {$GLOBALS['wpdb']->prefix}eligius_licenses
              SET device_id = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE device_id = %d AND status IN ('ACTIVE','TRIAL')",
            $device_id
        ));

        Class_Audit_Log_Repository::insert_audit([
            'user_id'       => (int) $device->user_id,
            'device_id'     => $device_id,
            'actor_user_id' => get_current_user_id(),
            'event_type'    => 'admin.device.blocked',
            'severity'      => 'CRITICAL',
        ]);

        $this->redirect_ok('Device blocked.');
    }

    public function handle_unblock() {
        \Eligius_Sub\Admin\Class_Admin_Menu::instance()->device_guard();
        $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
        Class_Device_Repository::set_blocked($device_id, false);

        Class_Audit_Log_Repository::insert_audit([
            'device_id'     => $device_id,
            'actor_user_id' => get_current_user_id(),
            'event_type'    => 'admin.device.unblocked',
            'severity'      => 'INFO',
        ]);

        $this->redirect_ok('Device unblocked.');
    }

    private function redirect_ok($msg) {
        wp_safe_redirect(add_query_arg(['eligius_device_msg' => rawurlencode($msg)], admin_url('admin.php?page=eligius-sub-devices')));
        exit;
    }

    private function redirect_err($msg) {
        wp_safe_redirect(add_query_arg(['eligius_device_msg' => rawurlencode($msg)], admin_url('admin.php?page=eligius-sub-devices')));
        exit;
    }

    public static function render_all() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $msg = isset($_GET['eligius_device_msg']) ? sanitize_text_field(wp_unslash($_GET['eligius_device_msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('دستگاه‌ها', 'eligius-subscription'); ?></h1>
            <?php if ('' !== $msg) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="eligius-devices" />
                <input type="number" name="user_id" placeholder="User ID" value="<?php echo esc_attr(isset($_GET['user_id']) ? (int) $_GET['user_id'] : ''); ?>" min="1" style="width:120px;" />
                <button class="button"><?php esc_html_e('فیلتر بر اساس کاربر', 'eligius-subscription'); ?></button>
            </form>

            <?php
            $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
            self::render_devices_table($user_id);
            ?>
        </div>
        <?php
    }

    public static function render_devices_table($user_id) {
        $devices = Class_Device_Repository::list_for_user($user_id);
        if (empty($devices)) {
            echo '<p>' . esc_html__('No devices registered.', 'eligius-subscription') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>شناسه</th><th>هش (کوتاه)</th><th>برچسب</th><th>فعال</th><th>مسدود</th><th>آخرین اتصال</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $d) : ?>
                <tr>
                    <td><?php echo esc_html($d->id); ?></td>
                    <td><code><?php echo esc_html(substr($d->device_hash, 0, 16)); ?>…</code></td>
                    <td><?php echo esc_html($d->label ?: '—'); ?></td>
                    <td><?php echo $d->is_active ? '✅' : '—'; ?></td>
                    <td><?php echo !empty($d->blocked) ? '🚫' : '—'; ?></td>
                    <td><?php echo esc_html($d->last_seen_at ?: '—'); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('eligius_sub_device_admin'); ?>
                            <input type="hidden" name="device_id" value="<?php echo esc_attr($d->id); ?>" />
                            <?php if ($d->is_active && empty($d->blocked)) : ?>
                                <input type="hidden" name="action" value="eligius_sub_release_device" />
                                <?php submit_button(__('Release', 'eligius-subscription'), 'small', 'submit', false); ?>
                            <?php elseif (empty($d->blocked)) : ?>
                                <input type="hidden" name="action" value="eligius_sub_block_device" />
                                <?php submit_button(__('Block', 'eligius-subscription'), 'small', 'submit', false); ?>
                            <?php else : ?>
                                <input type="hidden" name="action" value="eligius_sub_unblock_device" />
                                <?php submit_button(__('Unblock', 'eligius-subscription'), 'small', 'submit', false); ?>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
