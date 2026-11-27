<?php
/**
 * P9.1 — مدیریت پلن‌ها (فارسی): ساخت/ویرایش/حذف پلن‌های حجمی، زمان‌دار و رایگان.
 *
 * سه خانواده پلن (تصمیم مالک، Phase 9 + P9.1.3):
 *   • پلن حجمی   — سهم گیگابایت دارد؛ با اتمام حجم اتصال بلاک می‌شود.
 *   • پلن زمان‌دار — سهم حجم ندارد؛ با تاریخ انقضا پایان می‌یابد.
 *   • پلن رایگان  — قیمت صفر (بدون درگاه)؛ حجم اختیاری (خالی = نامحدود).
 *
 * P9.1.3: فرم افزودن/ویرایش به‌صورت مودال + UI مدرن هم‌رنگ با تم اپ
 * (بنفش #6C3AD9، گرادیان #7E6BFF→#B07CFF، کانتینر #EADCFF).
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
    private static function guard() {
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

        $msg = isset($_GET['eligius_msg']) ? sanitize_text_field(wp_unslash($_GET['eligius_msg'])) : '';
        $messages = [
            'plan_saved'   => 'پلن با موفقیت ذخیره شد.',
            'plan_deleted' => 'پلن حذف شد.',
            'plan_toggled' => 'وضعیت پلن تغییر کرد.',
            'plan_error'   => 'خطا در ذخیره پلن — ورودی‌ها را بررسی کنید.',
        ];
        $plans = self::all_plans();
        ?>
        <div class="wrap epa-wrap" dir="rtl">
            <style>
                .epa-wrap { max-width: 1080px; }
                .epa-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; margin: 8px 0 20px; }
                .epa-header h1 { margin: 0 0 5px; font-size: 25px; color: #1C1330; }
                .epa-sub { margin: 0; color: #7D749B; font-size: 13px; }
                .epa-btn-primary {
                    background: linear-gradient(135deg, #7E6BFF, #B07CFF); border: 0; border-radius: 14px;
                    color: #fff; font-size: 14px; font-weight: 600; padding: 10px 24px; cursor: pointer;
                    box-shadow: 0 6px 16px rgba(126, 107, 255, .35); transition: filter .15s;
                }
                .epa-btn-primary:hover { filter: brightness(1.07); }
                .epa-notice { display: flex; gap: 8px; align-items: center; border-radius: 12px;
                    padding: 11px 14px; margin: 0 0 18px; font-size: 13.5px; font-weight: 600; }
                .epa-notice.ok  { background: #E6F4EA; color: #1E7B34; border: 1px solid #CBE8D3; }
                .epa-notice.err { background: #FDE8E8; color: #B3261E; border: 1px solid #F5C9C9; }
                .epa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
                .epa-card { background: #fff; border: 1px solid #DED4F1; border-radius: 16px;
                    padding: 16px; box-shadow: 0 2px 10px rgba(42, 7, 99, .06); }
                .epa-card-off { opacity: .55; }
                .epa-card-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
                .epa-name { font-size: 16px; font-weight: 700; color: #2A0763; flex: 1; }
                .epa-chip { font-size: 11px; border-radius: 999px; padding: 3px 10px; font-weight: 600; white-space: nowrap; }
                .epa-chip-volume { background: #EADCFF; color: #2A0763; }
                .epa-chip-timed  { background: #E8E0F6; color: #4C4364; }
                .epa-chip-free   { background: #FBE4F8; color: #A030B5; }
                .epa-chip-trial  { background: #2A0763; color: #fff; }
                .epa-meta { display: flex; gap: 16px; margin-top: 12px; color: #4C4364; font-size: 13px; flex-wrap: wrap; }
                .epa-meta b { color: #1C1330; }
                .epa-foot { display: flex; align-items: center; gap: 10px; margin-top: 14px;
                    border-top: 1px dashed #DED4F1; padding-top: 12px; }
                .epa-dot { width: 9px; height: 9px; border-radius: 50%; background: #2E9E5B; display: inline-block; margin-inline-end: 5px; }
                .epa-dot-off { background: #D93A3A; }
                .epa-state { font-size: 12.5px; font-weight: 600; color: #4C4364; flex: 1; white-space: nowrap; }
                .epa-inline-form { display: inline-flex; align-items: center; margin: 0; }
                .epa-switch-btn { appearance: none; -webkit-appearance: none; background: none; border: 0;
                    padding: 0; margin: 0; cursor: pointer; display: inline-flex; align-items: center; }
                .epa-switch-btn:focus-visible { outline: 2px solid rgba(108, 58, 217, .4); outline-offset: 3px; border-radius: 999px; }
                .epa-switch-btn .epa-track { width: 42px; height: 23px; background: #C9BFDE; border-radius: 999px;
                    position: relative; transition: background .2s; flex: none; display: inline-block; }
                .epa-switch-btn .epa-track::after { content: ''; position: absolute; top: 3px; inset-inline-start: 3px;
                    width: 17px; height: 17px; border-radius: 50%; background: #fff;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, .25); transition: transform .2s; }
                .epa-switch-btn[aria-pressed="true"] .epa-track { background: #6C3AD9; }
                .epa-switch-btn[aria-pressed="true"] .epa-track::after { transform: translateX(-19px); }
                .epa-switch-btn:hover .epa-track { filter: brightness(.94); }
                .epa-link { appearance: none; -webkit-appearance: none; background: none; border: 0; color: #6C3AD9;
                    cursor: pointer; font-size: 13px; font-weight: 600; padding: 4px 6px; }
                .epa-link:hover { color: #7E6BFF; }
                .epa-link.delete { color: #D93A3A; }
                .epa-link[disabled] { color: #B9B0CC; cursor: not-allowed; }
                .epa-modal { border: 0; border-radius: 20px; padding: 0; max-width: 520px; width: 92vw;
                    box-shadow: 0 24px 60px rgba(13, 10, 26, .35); background: #F7F4FC; }
                .epa-modal::backdrop { background: rgba(13, 10, 26, .55); backdrop-filter: blur(2px); }
                .epa-modal-head { display: flex; align-items: center; justify-content: space-between;
                    padding: 15px 20px; background: linear-gradient(135deg, #7E6BFF, #B07CFF);
                    border-radius: 20px 20px 0 0; }
                .epa-modal-head h2 { margin: 0; font-size: 17px; color: #fff; }
                .epa-x { background: rgba(255, 255, 255, .18); border: 0; color: #fff; border-radius: 10px;
                    width: 30px; height: 30px; cursor: pointer; font-size: 14px; }
                .epa-x:hover { background: rgba(255, 255, 255, .3); }
                .epa-modal-body { padding: 18px 20px 4px; }
                .epa-field { margin-bottom: 15px; }
                .epa-field > label { display: block; font-size: 13px; font-weight: 600; color: #2A0763; margin-bottom: 6px; }
                .epa-field > label.epa-switch { display: inline-flex; margin-bottom: 0; }
                .epa-input { width: 100%; box-sizing: border-box; border: 1px solid #DED4F1; border-radius: 12px;
                    padding: 9px 12px; font-size: 14px; background: #fff; }
                .epa-input:focus { outline: 2px solid rgba(126, 107, 255, .25); border-color: #7E6BFF; }
                .epa-input:disabled { background: #EFEAF7; color: #9A8FB5; }
                .epa-types { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
                .epa-type { position: relative; }
                .epa-type input { position: absolute; opacity: 0; inset: 0; cursor: pointer; margin: 0; }
                .epa-type span { display: block; text-align: center; border: 1.5px solid #DED4F1; border-radius: 12px;
                    padding: 9px 6px; font-size: 13px; font-weight: 600; color: #4C4364; background: #fff; transition: all .15s; }
                .epa-type input:checked + span { background: #EADCFF; border-color: #6C3AD9; color: #2A0763;
                    box-shadow: 0 0 0 2px rgba(108, 58, 217, .15); }
                .epa-hint { font-size: 12px; color: #7D749B; margin: 6px 0 0; }
                .epa-trial-hint { background: #EADCFF; color: #2A0763; border-radius: 12px; padding: 10px 12px;
                    font-size: 12.5px; margin-bottom: 14px; }
                .epa-switch { display: inline-flex; align-items: center; gap: 9px; cursor: pointer;
                    font-size: 13px; font-weight: 600; color: #2A0763; }
                .epa-switch input { display: none; }
                .epa-switch .epa-track { width: 42px; height: 23px; background: #C9BFDE; border-radius: 999px;
                    position: relative; transition: background .2s; flex: none; }
                .epa-switch .epa-track::after { content: ''; position: absolute; top: 3px; inset-inline-start: 3px;
                    width: 17px; height: 17px; border-radius: 50%; background: #fff;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, .2); transition: transform .2s; }
                .epa-switch input:checked + .epa-track { background: #6C3AD9; }
                .epa-switch input:checked + .epa-track::after { transform: translateX(-19px); }
                .epa-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px 18px; }
                .epa-ghost { background: #fff; border: 1px solid #DED4F1; border-radius: 12px;
                    color: #4C4364; padding: 9px 18px; font-size: 14px; cursor: pointer; }
                .epa-submit { background: linear-gradient(135deg, #7E6BFF, #B07CFF); border: 0; border-radius: 12px;
                    color: #fff; font-size: 14px; font-weight: 700; padding: 9px 26px; cursor: pointer;
                    box-shadow: 0 6px 14px rgba(126, 107, 255, .35); }
                .epa-submit:hover { filter: brightness(1.07); }
            </style>

            <div class="epa-header">
                <div>
                    <h1>پلن‌ها</h1>
                    <p class="epa-sub">پلن‌های اشتراک را بسازید و مدیریت کنید — پلن‌های فعال بلافاصله در اپ نمایش داده می‌شوند.</p>
                </div>
                <button type="button" class="epa-btn-primary" id="epa-add">＋ افزودن پلن</button>
            </div>

            <?php if (isset($messages[$msg])) :
                $ok = 'plan_error' !== $msg; ?>
                <div class="epa-notice <?php echo $ok ? 'ok' : 'err'; ?>">
                    <span><?php echo esc_html($messages[$msg]); ?></span>
                </div>
            <?php endif; ?>

            <div class="epa-grid">
                <?php foreach ($plans as $p) :
                    $type = in_array($p['plan_type'], ['volume', 'timed', 'free'], true) ? $p['plan_type'] : 'timed';
                    $type_fa = ['volume' => 'حجمی', 'timed' => 'زمان‌دار', 'free' => 'رایگان'][$type];
                    ?>
                    <div class="epa-card <?php echo (int) $p['is_active'] ? '' : 'epa-card-off'; ?>">
                        <div class="epa-card-head">
                            <span class="epa-name"><?php echo esc_html($p['label']); ?></span>
                            <?php if ((int) $p['is_trial']) : ?>
                                <span class="epa-chip epa-chip-trial">آزمایشی</span>
                            <?php endif; ?>
                            <span class="epa-chip epa-chip-<?php echo esc_attr($type); ?>"><?php echo esc_html($type_fa); ?></span>
                        </div>
                        <div class="epa-meta">
                            <span>حجم: <b><?php
                                echo ('timed' === $type || null === $p['volume_gb'])
                                    ? 'نامحدود'
                                    : esc_html((int) $p['volume_gb']) . ' گیگ';
                            ?></b></span>
                            <span>مدت: <b><?php echo esc_html((int) $p['duration_days']); ?> روز</b></span>
                            <span>قیمت: <b><?php
                                echo 'free' === $type
                                    ? 'رایگان'
                                    : esc_html(number_format_i18n((int) $p['price_irt'])) . ' تومان'
                                      . ((int) $p['discount_percent'] > 0 ? ' (−' . esc_html((int) $p['discount_percent']) . '٪)' : '');
                            ?></b></span>
                        </div>
                        <div class="epa-foot">
                            <span class="epa-state">
                                <span class="epa-dot <?php echo (int) $p['is_active'] ? '' : 'epa-dot-off'; ?>"></span>
                                <?php echo (int) $p['is_active'] ? 'فعال' : 'غیرفعال'; ?>
                            </span>
                            <button type="button" class="epa-link epa-edit"
                                data-plan="<?php echo esc_attr(wp_json_encode([
                                    'id'               => (int) $p['id'],
                                    'label'            => $p['label'],
                                    'plan_type'        => $type,
                                    'volume_gb'        => null === $p['volume_gb'] ? null : (int) $p['volume_gb'],
                                    'duration_days'    => (int) $p['duration_days'],
                                    'price_irt'        => (int) $p['price_irt'],
                                    'discount_percent' => (int) $p['discount_percent'],
                                    'is_active'        => (int) $p['is_active'],
                                    'is_trial'         => (int) $p['is_trial'],
                                ])); ?>">ویرایش</button>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="epa-inline-form"
                                  onsubmit="return confirm('وضعیت این پلن تغییر کند؟');">
                                <?php wp_nonce_field('eligius_plans_admin'); ?>
                                <input type="hidden" name="action" value="eligius_plans_toggle" />
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr($p['id']); ?>" />
                                <button type="submit" class="epa-switch-btn"
                                        aria-pressed="<?php echo (int) $p['is_active'] ? 'true' : 'false'; ?>"
                                        title="<?php echo (int) $p['is_active'] ? 'غیرفعال کردن پلن' : 'فعال کردن پلن'; ?>">
                                    <span class="epa-track"></span>
                                </button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                                  onsubmit="return confirm('این پلن حذف شود؟');">
                                <?php wp_nonce_field('eligius_plans_admin'); ?>
                                <input type="hidden" name="action" value="eligius_plans_delete" />
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr($p['id']); ?>" />
                                <button class="epa-link delete" type="submit"
                                    <?php echo (int) $p['is_trial'] ? 'disabled title="پلن آزمایشی حذف نمی‌شود"' : ''; ?>>حذف</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <dialog id="epa-modal" class="epa-modal" dir="rtl">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="epa-form">
                    <?php wp_nonce_field('eligius_plans_admin'); ?>
                    <input type="hidden" name="action" value="eligius_plans_save" />
                    <input type="hidden" name="plan_id" value="0" id="epa-plan-id" />
                    <div class="epa-modal-head">
                        <h2 id="epa-modal-title">افزودن پلن</h2>
                        <button type="button" class="epa-x" id="epa-close">✕</button>
                    </div>
                    <div class="epa-modal-body">
                        <div id="epa-trial-hint" class="epa-trial-hint" style="display:none">
                            پلن آزمایشی: نام/مدت/حجم این پلن روی «تریال‌های جدید» اعمال می‌شود
                            (تریال‌های گرفته‌شده تغییر نمی‌کنند). حذف این پلن مجاز نیست.
                        </div>
                        <div class="epa-field">
                            <label for="epa-label">نام پلن</label>
                            <input type="text" name="label" id="epa-label" class="epa-input" required
                                   placeholder="مثلاً: پلن الماس" />
                        </div>
                        <div class="epa-field">
                            <label>نوع پلن</label>
                            <div class="epa-types">
                                <label class="epa-type">
                                    <input type="radio" name="plan_type" value="volume" />
                                    <span>حجمی</span>
                                </label>
                                <label class="epa-type">
                                    <input type="radio" name="plan_type" value="timed" />
                                    <span>زمان‌دار</span>
                                </label>
                                <label class="epa-type">
                                    <input type="radio" name="plan_type" value="free" />
                                    <span>رایگان</span>
                                </label>
                            </div>
                        </div>
                        <div class="epa-field" id="epa-volume-row">
                            <label for="epa-volume">حجم (گیگابایت)</label>
                            <input type="number" name="volume_gb" id="epa-volume" class="epa-input" min="0" step="1"
                                   placeholder="مثلاً: 100" />
                            <p class="epa-hint" id="epa-volume-hint"></p>
                        </div>
                        <div class="epa-field">
                            <label for="epa-days">مدت (روز)</label>
                            <input type="number" name="duration_days" id="epa-days" class="epa-input" min="1" step="1"
                                   value="30" required />
                        </div>
                        <div class="epa-field" id="epa-price-row">
                            <label for="epa-price">قیمت (تومان)</label>
                            <input type="number" name="price_irt" id="epa-price" class="epa-input" min="0" step="1"
                                   placeholder="مثلاً: 988000" />
                        </div>
                        <div class="epa-field" id="epa-discount-row">
                            <label for="epa-discount">تخفیف (٪)</label>
                            <input type="number" name="discount_percent" id="epa-discount" class="epa-input"
                                   min="0" max="100" value="0" />
                        </div>
                        <div class="epa-field">
                            <div>
                                <label class="epa-switch">
                                    <input type="checkbox" name="is_active" value="1" id="epa-active" />
                                    <span class="epa-track"></span>
                                    پلن فعال باشد
                                </label>
                            </div>
                            <p class="epa-hint">پلن غیرفعال در اپ نمایش داده نمی‌شود و خرید مسیر ندارد.</p>
                        </div>
                    </div>
                    <div class="epa-modal-foot">
                        <button type="button" class="epa-ghost" id="epa-cancel">انصراف</button>
                        <button type="submit" class="epa-submit" id="epa-submit">افزودن پلن</button>
                    </div>
                </form>
            </dialog>

            <script>
            (function () {
                var modal = document.getElementById('epa-modal');
                var form  = document.getElementById('epa-form');
                if (!modal || !form) return;

                function applyType(t) {
                    var isV = 'volume' === t, isF = 'free' === t, isT = 'timed' === t;
                    var v = form.elements['volume_gb'];
                    document.getElementById('epa-volume-row').style.display = isT ? 'none' : '';
                    if (isT) { v.value = ''; }
                    v.required = isV;
                    document.getElementById('epa-volume-hint').textContent =
                        isV ? 'سهم گیگابایت این پلن (الزامی).'
                            : 'خالی = نامحدود (برای پلن رایگان اختیاری است).';
                    var price = form.elements['price_irt'];
                    price.disabled = isF;
                    price.required = !isF;
                    if (isF) { price.value = 0; }
                    document.getElementById('epa-discount-row').style.display = isF ? 'none' : '';
                    if (isF) { form.elements['discount_percent'].value = 0; }
                }
                Array.prototype.forEach.call(form.querySelectorAll('input[name="plan_type"]'), function (r) {
                    r.addEventListener('change', function () { applyType(r.value); });
                });

                function openModal(plan) {
                    form.reset();
                    document.getElementById('epa-plan-id').value = plan ? plan.id : 0;
                    document.getElementById('epa-modal-title').textContent = plan ? 'ویرایش پلن' : 'افزودن پلن';
                    document.getElementById('epa-submit').textContent = plan ? 'ذخیره تغییرات' : 'افزودن پلن';
                    if (plan) {
                        form.elements['label'].value = plan.label;
                        form.elements['plan_type'].value = plan.plan_type || 'timed';
                        form.elements['volume_gb'].value =
                            (null === plan.volume_gb || undefined === plan.volume_gb) ? '' : plan.volume_gb;
                        form.elements['duration_days'].value = plan.duration_days;
                        form.elements['price_irt'].value = plan.price_irt;
                        form.elements['discount_percent'].value = plan.discount_percent;
                        form.elements['is_active'].checked = ('1' === String(plan.is_active));
                    } else {
                        form.elements['plan_type'].value = 'volume';
                        form.elements['discount_percent'].value = 0;
                        form.elements['is_active'].checked = true;
                    }
                    document.getElementById('epa-trial-hint').style.display =
                        (plan && '1' === String(plan.is_trial)) ? '' : 'none';
                    applyType(form.elements['plan_type'].value);
                    if (modal.showModal) { modal.showModal(); } else { modal.setAttribute('open', ''); }
                }

                document.getElementById('epa-add').addEventListener('click', function () { openModal(null); });
                Array.prototype.forEach.call(document.querySelectorAll('.epa-edit'), function (b) {
                    b.addEventListener('click', function () {
                        try { openModal(JSON.parse(b.getAttribute('data-plan'))); } catch (e) { /* noop */ }
                    });
                });
                document.getElementById('epa-close').addEventListener('click', function () { modal.close(); });
                document.getElementById('epa-cancel').addEventListener('click', function () { modal.close(); });
            })();
            </script>
        </div>
        <?php
    }

    /** ذخیره (افزودن/ویرایش). */
    public static function handle_save() {
        self::guard();
        global $wpdb;

        $id    = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $type  = ($_POST['plan_type'] ?? '');
        if (!in_array($type, ['volume', 'timed', 'free'], true)) {
            $type = 'timed';
        }
        $active = isset($_POST['is_active']) ? 1 : 0;
        $days   = max(1, (int) ($_POST['duration_days'] ?? 30));

        // حجم: volume → الزامی؛ timed → نامحدود؛ free → اختیاری (خالی = نامحدود).
        $volume = null;
        if ('volume' === $type) {
            $volume = isset($_POST['volume_gb']) && $_POST['volume_gb'] !== '' ? (int) $_POST['volume_gb'] : null;
            if (null === $volume || $volume < 1) {
                // پلن حجمی بدون حجم → خطا
                wp_safe_redirect(add_query_arg('eligius_msg', 'plan_error', admin_url('admin.php?page=eligius-plans')));
                exit;
            }
        } elseif ('free' === $type) {
            if (isset($_POST['volume_gb']) && $_POST['volume_gb'] !== '' && (int) $_POST['volume_gb'] >= 1) {
                $volume = (int) $_POST['volume_gb'];
            }
        }

        // قیمت: رایگان → صفر قفل‌شده (بدون درگاه).
        $price    = ('free' === $type) ? 0 : max(0, (int) ($_POST['price_irt'] ?? 0));
        $discount = ('free' === $type) ? 0 : min(100, max(0, (int) ($_POST['discount_percent'] ?? 0)));

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
    public static function handle_delete() {
        self::guard();
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
    public static function handle_toggle() {
        self::guard();
        global $wpdb;
        $id = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_plans SET is_active = 1 - is_active WHERE id = %d", $id));
        wp_safe_redirect(add_query_arg('eligius_msg', 'plan_toggled', admin_url('admin.php?page=eligius-plans')));
        exit;
    }
}
