<?php
/**
 * P9.1 — مدیریت پلن‌ها (فارسی): ساخت/ویرایش/حذف پلن‌های حجمی و زمان‌دار.
 *
 * دو خانواده پلن (تصمیم مالک، Phase 9):
 *   • پلن حجمی  — سهم گیگابایت دارد؛ با اتمام حجم اتصال بلاک می‌شود.
 *   • پلن زمان‌دار (نامحدود) — سهم حجم ندارد؛ با تاریخ انقضا پایان می‌یابد.
 *
 * داده‌ها در جدول wp_eligius_plans (ستون‌های plan_type/volume_gb — migration 1.2.0).
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

class Class_Plans_Admin {

    public static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', function () {
            add_submenu_page(
                'eligius-sub',
                'پلن‌ها',
                'پلن‌ها',
                'manage_options',
                'eligius-plans',
                [self::class, 'render']
            );
        });
        add_action('admin_post_eligius_plans_save', [self::class, 'handle_save']);
        add_action('admin_post_eligius_plans_delete', [self::class, 'handle_delete']);
        add_action('admin_post_eligius_plans_toggle', [self::class, 'handle_toggle']);
        add_action('admin_init', [\Eligius_Sub\Core\Class_Database::class, 'maybe_upgrade']);
    }

    /** محافظ مشترک (nonce + دسترسی). */
    private function guard() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eligius_plans_admin')) {
            wp_die('نشانه امنیتی نامعتبر است.');
        }
    }

    /** فهرست پلن‌ها به‌ترتیب نمایش. */
    private static function all_plans() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, plan_key, label, plan_type, volume_gb, duration_days,
                    price_irt, discount_percent, is_trial, is_active
             FROM {$wpdb->prefix}eligius_plans
             ORDER BY is_active DESC, plan_type ASC, sort ASC, id ASC",
            ARRAY_A
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی کافی ندارید.');
        }

        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $edit = null;
        if ($edit_id > 0) {
            global $wpdb;
            $edit = $wpdb->get_row($wpdb->prepare(
                "SELECT id, plan_key, label, plan_type, volume_gb, duration_days,
                        price_irt, discount_percent, is_trial, is_active
                 FROM {$wpdb->prefix}eligius_plans WHERE id = %d",
                $edit_id
            ), ARRAY_A);
        }

        $msg = isset($_GET['eligius_msg']) ? sanitize_text_field(wp_unslash($_GET['eligius_msg'])) : '';
        $messages = [
            'plan_saved'   => 'پلن با موفقیت ذخیره شد.',
            'plan_deleted' => 'پلن حذف شد.',
            'plan_toggled' => 'وضعیت پلن تغییر کرد.',
            'plan_error'   => 'خطا در ذخیره پلن — ورودی‌ها را بررسی کنید.',
        ];
        ?>
        <div class="wrap" dir="rtl">
            <h1>پلن‌ها</h1>
            <p>اینجا پلن‌های اشتراک را می‌سازید و ویرایش می‌کنید. پلن‌های فعال بلافاصله در اپ نمایش داده می‌شوند.</p>
            <?php if (isset($messages[$msg])) : ?>
                <div class="notice notice-<?php echo 'plan_error' === $msg ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html($messages[$msg]); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo $edit ? 'ویرایش پلن' : 'افزودن پلن جدید'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:640px;">
                <?php wp_nonce_field('eligius_plans_admin'); ?>
                <input type="hidden" name="action" value="eligius_plans_save" />
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($edit['id'] ?? 0); ?>" />
                <table class="form-table" dir="rtl">
                    <tr>
                        <th>نام پلن</th>
                        <td><input type="text" name="label" class="regular-text" required
                                   value="<?php echo esc_attr($edit['label'] ?? ''); ?>"
                                   placeholder="مثلاً: پلن الماس" /></td>
                    </tr>
                    <tr>
                        <th>نوع پلن</th>
                        <td>
                            <label><input type="radio" name="plan_type" value="volume"
                                <?php checked(($edit['plan_type'] ?? 'volume'), 'volume'); ?> /> حجمی (سهم گیگابایت دارد)</label><br />
                            <label><input type="radio" name="plan_type" value="timed"
                                <?php checked(($edit['plan_type'] ?? 'volume'), 'timed'); ?> /> زمان‌دار (نامحدود — فقط تاریخ انقضا)</label>
                        </td>
                    </tr>
                    <tr>
                        <th>حجم (گیگابایت)</th>
                        <td><input type="number" name="volume_gb" min="0" step="1" style="width:120px;"
                                   value="<?php echo esc_attr($edit['volume_gb'] ?? ''); ?>"
                                   placeholder="مثلاً: 100" />
                            <p class="description">فقط برای «حجمی». برای زمان‌دار خالی بگذارید.</p></td>
                    </tr>
                    <tr>
                        <th>مدت (روز)</th>
                        <td><input type="number" name="duration_days" min="1" step="1" required style="width:120px;"
                                   value="<?php echo esc_attr($edit['duration_days'] ?? 30); ?>" /></td>
                    </tr>
                    <tr>
                        <th>قیمت (تومان)</th>
                        <td><input type="number" name="price_irt" min="0" step="1" required style="width:160px;"
                                   value="<?php echo esc_attr($edit['price_irt'] ?? ''); ?>"
                                   placeholder="مثلاً: 988000" /></td>
                    </tr>
                    <tr>
                        <th>تخفیف (٪)</th>
                        <td><input type="number" name="discount_percent" min="0" max="100" style="width:100px;"
                                   value="<?php echo esc_attr($edit['discount_percent'] ?? 0); ?>" /></td>
                    </tr>
                    <tr>
                        <th>فعال</th>
                        <td><input type="checkbox" name="is_active" value="1"
                            <?php checked((int) ($edit['is_active'] ?? 1), 1); ?> />
                            <p class="description">پلن غیرفعال در اپ نمایش داده نمی‌شود و خرید مسیر ندارد.</p></td>
                    </tr>
                </table>
                <?php submit_button($edit ? 'ذخیره تغییرات' : 'افزودن پلن'); ?>
            </form>
            <?php if ($edit) : ?>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=eligius-plans')); ?>">+ افزودن پلن جدید</a></p>
            <?php endif; ?>

            <h2>فهرست پلن‌ها</h2>
            <table class="widefat striped" dir="rtl">
                <thead>
                    <tr>
                        <th>نام</th><th>نوع</th><th>حجم</th><th>مدت</th><th>قیمت (تومان)</th><th>وضعیت</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (self::all_plans() as $p) : ?>
                    <tr>
                        <td><?php echo esc_html($p['label']); ?>
                            <?php if ($p['is_trial']) : ?><em>(آزمایشی)</em><?php endif; ?></td>
                        <td><?php echo 'volume' === $p['plan_type'] ? 'حجمی' : 'زمان‌دار'; ?></td>
                        <td><?php echo 'volume' === $p['plan_type'] ? esc_html((int) $p['volume_gb']) . ' گیگ' : 'نامحدود'; ?></td>
                        <td><?php echo esc_html((int) $p['duration_days']); ?> روز</td>
                        <td><?php echo number_format_i18n((int) $p['price_irt']); ?></td>
                        <td><?php echo $p['is_active'] ? '<span style="color:green">فعال</span>' : '<span style="color:#a00">غیرفعال</span>'; ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=eligius-plans&edit=' . (int) $p['id'])); ?>">ویرایش</a> |
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                                  onsubmit="return confirm('این پلن حذف شود؟');">
                                <?php wp_nonce_field('eligius_plans_admin'); ?>
                                <input type="hidden" name="action" value="eligius_plans_delete" />
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr($p['id']); ?>" />
                                <button class="button-link delete" type="submit"
                                    <?php echo $p['is_trial'] ? 'disabled title="پلن آزمایشی حذف نمی‌شود"' : ''; ?>>حذف</button>
                            </form> |
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('eligius_plans_admin'); ?>
                                <input type="hidden" name="action" value="eligius_plans_toggle" />
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr($p['id']); ?>" />
                                <button class="button-link" type="submit"><?php echo $p['is_active'] ? 'غیرفعال کن' : 'فعال کن'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** ذخیره (افزودن/ویرایش). */
    public function handle_save() {
        $this->guard();
        global $wpdb;

        $id       = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $label    = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $type     = ('volume' === ($_POST['plan_type'] ?? '')) ? 'volume' : 'timed';
        $volume   = isset($_POST['volume_gb']) && $_POST['volume_gb'] !== '' ? (int) $_POST['volume_gb'] : null;
        $days     = max(1, (int) ($_POST['duration_days'] ?? 30));
        $price    = max(0, (int) ($_POST['price_irt'] ?? 0));
        $discount = min(100, max(0, (int) ($_POST['discount_percent'] ?? 0)));
        $active   = isset($_POST['is_active']) ? 1 : 0;

        // پلن حجمی بدون حجم → خطا
        if ('volume' === $type && (null === $volume || $volume < 1)) {
            wp_safe_redirect(add_query_arg('eligius_msg', 'plan_error', admin_url('admin.php?page=eligius-plans')));
            exit;
        }
        // پلن زمان‌دار → حجم null (نامحدود)
        if ('timed' === $type) {
            $volume = null;
        }

        $table = $wpdb->prefix . 'eligius_plans';
        if ($id > 0) {
            $ok = $wpdb->update($table, [
                'label'            => $label,
                'plan_type'        => $type,
                'volume_gb'        => $volume,
                'duration_days'    => $days,
                'price_irt'        => $price,
                'discount_percent' => $discount,
                'is_active'        => $active,
            ], ['id' => $id]);
            $msg = (false !== $ok) ? 'plan_saved' : 'plan_error';
        } else {
            $key = 'plan-' . substr(md5($label . microtime(true)), 0, 8);
            $ok = $wpdb->insert($table, [
                'plan_key'         => $key,
                'label'            => $label,
                'plan_type'        => $type,
                'volume_gb'        => $volume,
                'duration_days'    => $days,
                'price_irt'        => $price,
                'discount_percent' => $discount,
                'is_trial'         => 0,
                'sort'             => 99,
                'is_active'        => $active,
            ]);
            $msg = $ok ? 'plan_saved' : 'plan_error';
        }

        wp_safe_redirect(add_query_arg('eligius_msg', $msg, admin_url('admin.php?page=eligius-plans')));
        exit;
    }

    /** حذف پلن (پلن آزمایشی حذف نمی‌شود). */
    public function handle_delete() {
        $this->guard();
        global $wpdb;
        $id = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $trial = $wpdb->get_var($wpdb->prepare(
            "SELECT is_trial FROM {$wpdb->prefix}eligius_plans WHERE id = %d", $id));
        if (!$trial) {
            $wpdb->delete($wpdb->prefix . 'eligius_plans', ['id' => $id]);
        }
        wp_safe_redirect(add_query_arg('eligius_msg', 'plan_deleted', admin_url('admin.php?page=eligius-plans')));
        exit;
    }

    /** فعال/غیرفعال کردن. */
    public function handle_toggle() {
        $this->guard();
        global $wpdb;
        $id = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_plans SET is_active = 1 - is_active WHERE id = %d", $id));
        wp_safe_redirect(add_query_arg('eligius_msg', 'plan_toggled', admin_url('admin.php?page=eligius-plans')));
        exit;
    }
}
