# Eligius Subscription System — Admin Panel Specification

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

This document specifies the WordPress admin panel interface for managing Eligius subscriptions, licenses, devices, payments, and users. The admin panel is built as a dedicated WordPress plugin (`eligius-subscription`) integrated into the native wp-admin interface.

### 1.1 Design Principles

1. **Native WordPress Experience** — Uses WP List Table, Meta Boxes, Settings API, Nonce verification
2. **Role-Based Access** — `manage_options` capability required; future granular capabilities
3. **Performance** — Server-side pagination, AJAX filtering, bulk actions
4. **Auditability** — Every action logged to `eligius_audit_logs`
5. **Consistency** — Follows WordPress admin design patterns (WP Admin CSS, Dashicons)

---

## 2. Menu Structure

### 2.1 Top-Level Menu

```
Dashboard (WP)
  ├── Posts
  ├── Media
  ├── Pages
  ├── Comments
  ├── Appearance
  ├── Plugins
  ├── Users
  ├── Tools
  ├── Settings
  └── ☰ Eligius VPN          ← NEW TOP-LEVEL MENU
        ├── Dashboard
        ├── Users
        ├── Devices
        ├── Subscriptions
        ├── Payments
        ├── Licenses
        ├── Audit Logs
        ├── Gateway Settings
        └── General Settings
```

**Capability Required:** `manage_options` (super admin / administrator)

---

## 2. Page Specifications

### 2.1 Eligius Dashboard (`/wp-admin/admin.php?page=eligius-sub-dashboard`)

**Purpose:** High-level overview of subscription business health.

**Layout:**
```
┌─────────────────────────────────────────────────────────────────┐
│ Eligius VPN Dashboard                                    [Refresh]│
├──────────┬──────────────┬──────────────┬────────────────────────┤
│ Active   │ Trials       │ Revenue (30d)│ Churn Rate (30d)       │
│ Licenses │ Active       │ 1.2B IRR     │ 2.3%                   │
│ 1,234    │ 56           │ ↑ 12%        │ ↓ 0.5%                 │
├──────────┼──────────────┼──────────────┼────────────────────────┤
│ Revenue Trend (30 days) - [Line Chart: Daily revenue, 30 points] │
├──────────────────────────────────────────────────────────────────┤
│ Plan Distribution                    │ Top Gateways (30d)       │
│ [Donut: Monthly 40%, Annual 30%,     │ [Bar: ZarinPal 60%,      │
│  Quarterly 15%, Semi-annual 10%,     │  IDPay 25%, Zibal 15%]  │
│  Trial 5%]                           │                          │
└─────────────────────────────────────────────────────────────────┘
```

**Widgets (configurable, draggable):**
- Active Licenses (count, trend)
- Active Trials (count, conversion rate)
- Revenue (30d, 90d, 365d)
- Churn Rate
- Payment Success Rate
- Active Devices
- Payment Gateway Health (success rate per gateway)

**Actions:**
- Refresh button (AJAX)
- Date range picker (7d, 30d, 90d, 1y, custom)
- Export CSV button

---

### 3.2 Users Management (`/wp-admin/admin.php?page=eligius-sub-users`)

**Purpose:** View and manage all Eligius users with subscription context.

**Table Columns:**
| Column | Sortable | Filter | Description |
|--------|----------|--------|-------------|
| User | ✓ | Search | Username (mobile), click → WP user edit |
| Email | ✓ | Search | Email address |
| Name | ✓ | — | First + Last name |
| Status | ✓ | Dropdown | Active / Expired / Trial / No Subscription |
| Plan | ✓ | Dropdown | Plan key (Monthly, Annual, etc.) |
| Expires | ✓ | Date range | Expiration date (red if < 7 days) |
| Devices | ✓ | — | Count / View link |
| Registered | ✓ | Date range | Registration date |
| Last Login | ✓ | — | Last activity |
| Actions | — | — | View Details, Impersonate, Suspend |

**Filters (Sidebar):**
- Subscription Status: All / Active / Expired / Trial / Revoked / None
- Plan: All / Monthly / Quarterly / Semi-annual / Annual / Trial
- Date Registered: Last 7d / 30d / 90d / Custom
- Has Device: Yes / No
- Gateway: All / ZarinPal / IDPay / Zibal

**Bulk Actions (dropdown + Apply):**
- Extend Subscription (+30/90/180/365 days)
- Send Renewal Reminder Email
- Export CSV
- Revoke Licenses (with confirmation modal)

**Row Actions (hover):**
- View Details → Modal with full history
- Impersonate Login (admin only, logs audit)
- Suspend / Reactivate License
- View Devices
- View Payments
- View Audit Log

**Pagination:** 50 per page, AJAX load

---

### 3.3 Devices Management (`/wp-admin/admin.php?page=eligius-sub-devices`)

**Purpose:** View and manage registered devices.

**Policy statement (CD-9 — displayed as a notice at the top of the page):**
> هر حساب کاربری در هر لحظه فقط **یک دستگاه فعال** دارد — برای Trial و تمام پلن‌های پولی. انتقال به دستگاه جدید فقط با **تأیید OTP** امکان‌پذیر است؛ پس از انتقال موفق، دستگاه قبلی به‌صورت خودکار غیرفعال (`is_active=0`) و اتصال لایسنس به دستگاه جدید منتقل می‌شود (تراکنش اتمیک با قفل ردیفی — 02 §2.8). این صفحه هر دو دستگاه را نشان می‌دهد و ادمین می‌تواند هر کدام را آزاد کند.

**Table Columns:**
| Column | Sortable | Filter | Description |
|--------|----------|--------|-------------|
| Device | — | — | Label + Hash (truncated) |
| User | ✓ | Search | Username / Email link |
| Status | ✓ | Dropdown | Active / Inactive / Pending Transfer |
| Bound License | ✓ | — | Plan key + expiry |
| Registered | ✓ | Date range | Registration timestamp |
| Last Seen | ✓ | — | Last activity |
| Actions | — | — | Deactivate, Force Transfer, View Transfers |

**Filters:**
- Status: All / Active / Inactive / Pending Transfer
- User: Search by username/email
- Date Registered: Range

**Bulk Actions:**
- Deactivate Selected
- Force Transfer (admin initiates transfer)

**Row Actions:**
- Deactivate / Reactivate
- View Transfer History
- Force Transfer (admin bypasses OTP)

---

### 3.4 Subscriptions Management (`/wp-admin/admin.php?page=eligius-sub-subscriptions`)

**Purpose:** Full subscription lifecycle management.

**Tabs:**
1. **Active** — Currently active subscriptions
2. **Expiring Soon** — Expiring within 7 days
3. **Expired** — Expired but not renewed
4. **Trials** — Active trials
5. **Revoked** — Revoked licenses

**Table Columns (per tab):**
| Column | Sortable | Description |
|--------|----------|-------------|
| User | ✓ | Username + email |
| Plan | ✓ | Plan label + duration |
| Status | ✓ | Badge: ACTIVE/EXPIRED/TRIAL/REVOKED/PENDING |
| Started | ✓ | Start date |
| Expires | ✓ | Red if < 7 days |
| Device | — | Device label + hash |
| Source | — | payment / trial / renewal / admin |
| Payment | — | Link to payment record |
| Actions | — | Extend, Revoke, View History, Transfer Device |

**Quick Actions (inline):**
- **Extend** → Modal: +30/90/180/365 days / Custom days
- **Revoke** → Confirmation modal with reason
- **Extend from Expiry** / **Extend from Now** toggle

**Bulk Actions:**
- Extend Selected (+30/90/180/365 days)
- Revoke Selected (with reason)
- Export CSV

---

### 3.5 Payments Management (`/wp-admin/admin.php?page=eligius-sub-payments`)

**Purpose:** Payment monitoring, reconciliation, refunds.

**Tabs:**
1. **All Payments**
2. **Pending Verification** (INITIATED)
3. **Verified** (VERIFIED)
3. **Failed** (FAILED)
4. **Refunded** (REFUNDED)

**Table Columns:**
| Column | Sortable | Filter | Description |
|--------|----------|--------|-------------|
| ID | ✓ | — | Payment ID |
| User | ✓ | Search | Username + email |
| Gateway | ✓ | Dropdown | ZarinPal / IDPay / Zibal |
| Amount | ✓ | Range | Amount in IRR |
| Plan | ✓ | Dropdown | Plan key |
| Status | ✓ | Dropdown | Badge: INITIATED/VERIFIED/FAILED/REFUNDED |
| Gateway Ref | — | Search | Gateway authority/token |
| Ref ID | — | Search | Gateway transaction ID |
| Initiated | ✓ | Date range | Timestamp |
| Verified | ✓ | Date range | Timestamp |
| Actions | — | — | View Details, Refund, Retry Verification |

**Filters:**
- Gateway: All / ZarinPal / IDPay / Zibal
- Status: All / INITIATED / VERIFIED / FAILED / REFUNDED
- Date Range: Initiated / Verified
- Amount Range: Min / Max

**Bulk Actions:**
- Retry Verification (for INITIATED/FAILED)
- Export CSV
- Export Reconciliation Report (CSV: gateway, date, amounts, counts)

**Row Actions:**
- View Details → Modal with full gateway response
- Refund → Modal: amount, reason, confirm
- Retry Verification (for failed/pending)
- View Audit Log

**Refund Modal:**
```
┌─────────────────────────────────────┐
│ Process Refund                      │
├─────────────────────────────────────┤
│ Payment: #789 (1,400,000 IRR)       │
│ Gateway: ZarinPal                   │
│ Ref ID: 1234567890                  │
├─────────────────────────────────────┤
│ Refund Amount: [1400000] IRT        │
│ Reason: [Dropdown: Customer Request │
│          / Duplicate / Fraud /      │
│          Technical Error / Other]   │
│ Notes: [Textarea]                   │
├─────────────────────────────────────┤
│ [Cancel] [Process Refund]           │
└─────────────────────────────────────┘
```

**Refund Action:**
1. Call gateway refund API (S2S)
2. Update payment status → REFUNDED
3. Revoke associated license (set REVOKED/EXPIRED)
3. Log audit event
4. Notify user

---

### 3.6 Licenses Management (`/wp-admin/admin.php?page=eligius-sub-licenses`)

**Purpose:** Detailed license lifecycle management.

**Tabs:** Same as Subscriptions tab but with more detail columns.

**Additional Columns:**
| Column | Description |
|--------|-------------|
| License ID | Link to detail modal |
| Previous License | Link to previous license (renewal chain) |
| Payment ID | Link to payment record |
| Offline Entitlement | Valid / Expired / None |

**License Detail Modal:**
```
┌─────────────────────────────────────────────────────────────┐
│ License #12345 — Annual Plan                          [×]  │
├─────────────────────────────────────────────────────────────┤
│ User:          Ali Rezaei (09123456789)              [View] │
│ Plan:          Annual (365 days)                        │
│ Status:        ACTIVE                    [Badge: green] │
│ Started:       2026-11-10 12:00:00                      │
│ Expires:       2027-11-10 12:00:00      (365 days left) │
│ Device:        My Phone (a1b2c3d4...)         [View]    │
│ Payment:       #789 — ZarinPal — 1,400,000 IRR    [View] │
│ Source:        payment                                 │
│ Previous:      #120 (Monthly, expired)           [View] │
├─────────────────────────────────────────────────────────────┤
│ [Extend +30d] [Extend +90d] [Extend +365d] [Revoke]       │
└─────────────────────────────────────────────────────────────┘
```

**Actions:**
- Extend (+30/90/180/365 days / Custom)
- Revoke (with reason)
- Force Renewal (create payment link)
- View Audit History

---

### 3.6 Audit Logs (`/wp-admin/admin.php?page=eligius-sub-audit-logs`)

**Purpose:** Immutable audit trail for compliance and debugging.

**Table Columns:**
| Column | Sortable | Filter | Description |
|--------|----------|--------|-------------|
| Time | ✓ | Date range | Precise timestamp |
| Severity | ✓ | Dropdown | INFO / WARNING / CRITICAL (color coded) |
| Event Type | ✓ | Dropdown | Grouped: payment.*, license.*, device.*, auth.*, admin.* |
| User | ✓ | Search | Username / ID |
| License | — | Search | License ID |
| Device | — | Search | Device hash |
| Actor | — | Search | Admin user who acted |
| IP | — | Search | IPv4/IPv6 |
| Details | — | — | JSON preview (click to expand) |

**Filters:**
- Severity: All / INFO / WARNING / CRITICAL
- Event Type: Multi-select (grouped by prefix)
- User: Search
- Date Range: Presets + Custom
- Severity: Multi-select

**Row Expansion:** Click row → expands JSON details panel

**Export:** CSV / JSON (filtered results)

**Retention Notice:** Banner showing "Logs retained for 5 years per policy"

---

### 3.7 Gateway Settings (`/wp-admin/admin.php?page=eligius-sub-gateway-settings`)

**Layout:** Accordion per gateway

```
┌─────────────────────────────────────────────────────────────┐
│ ▼ ZarinPal                                              [Save]│
├─────────────────────────────────────────────────────────────┤
│ Enabled:              [☑] Yes  [ ] No                       │
│ Merchant ID:          [xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx] │
│ Callback URL:         https://wp.mrkiyan.ir/.../zarinpal    │
│ Sandbox Mode:         [☑] Enabled                           │
│ Test Merchant ID:     [sandbox-xxxx...]                     │
├─────────────────────────────────────────────────────────────┤
│ ▼ IDPay                                                 [Save]│
├─────────────────────────────────────────────────────────────┤
│ Enabled:              [☑] Yes  [ ] No                       │
│ API Key:              [xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx] │
│ Callback URL:         https://wp.mrkiyan.ir/.../idpay       │
│ Sandbox Mode:         [☑] Enabled                           │
├─────────────────────────────────────────────────────────────┤
│ ▼ Zibal                                                 [Save]│
├─────────────────────────────────────────────────────────────┤
│ Enabled:              [ ] Yes  [☑] No                       │
│ Merchant ID:          [                                      ]│
│ Callback URL:         https://wp.mrkiyan.ir/.../zibal       │
└─────────────────────────────────────────────────────────────┘
```

**Validation:**
- Merchant ID format validation per gateway
- Callback URL must match site URL + `/wp-json/eligius/v1/payments/callback/{gateway}`
- Test connection button (calls gateway ping endpoint)

---

### 3.8 General Settings (`/wp-admin/admin.php?page=eligius-sub-general-settings`)

**Sections:**

**General**
- Trial Duration (days): `[30]` (default 30)
- Max Devices Per User: `[1]` (default 1)
- Offline Grace Period (hours): `[72]` (default 72)
- License Signing Key Rotation (days): `[90]` (default 90)
- Auto-Renewal Default: `[ ] Enabled`

**Trial Settings**
- Trial Duration (days): `[30]`
- Allow Trial Re-grant (after expiry): `[ ] Yes`
- Trial per Device / per Account: `[Device]` (radio)

**Payment Settings**
- Default Gateway: `[ZarinPal]` (dropdown)
- Currency: `IRT` (read-only)
- Minimum Amount: `10000` IRR
- Callback Timeout (seconds): `1800`

**Security**
- Max OTP Attempts: `[5]`
- OTP TTL (seconds): `[600]`
- Device Transfer Limit (per day): `[3]`
- Device Transfer OTP TTL (minutes): `[10]`
- Rate Limit: Auth (req/hr): `[5]`
- Rate Limit: API (req/min): `[100]`

**Notifications**
- Expiry Warning Days: `[7, 3, 1]` (comma-separated)
- Renewal Reminder Days: `[3, 1]`
- Payment Failure Notification: `[☑] Enabled`
- Expiry Notification: `[☑] Enabled`

**Advanced**
- Maintenance Mode: `[ ] Enable` (blocks new connections)
- Debug Logging: `[ ] Enable` (verbose audit logs)
- Maintenance Message: `[Textarea]`

---

## 4. User Interface Components

### 4.1 Design System

**Colors (Eligius Brand):**
```css
:root {
  --eligius-primary: #7E6BFF;
  --eligius-primary-dark: #5A4AE8;
  --eligius-primary-light: #B07CFF;
  --eligius-secondary: #2A1650;
  --eligius-accent: #FF6B6B;
  --eligius-success: #28C76F;
  --eligius-warning: #FFB800;
  --eligius-error: #FF4757;
  --eligius-surface: #1A1A2E;
  --eligius-surface-hover: #2A2A4A;
  --eligius-text: #ECE7F8;
  --eligius-text-muted: #B3A8CE;
}
```

**Typography:**
- Headings: `font-family: 'Vazirmatn', 'IRANSans', sans-serif;`
- Body: `font-family: 'Vazirmatn', 'IRANSans', sans-serif;`
- Mono: `font-family: 'JetBrains Mono', monospace;`

**Components (WP Admin compatible):**
- Tables: `.wp-list-table` with `.eligius-sub-table` modifier
- Buttons: `.button-primary`, `.button-secondary`, `.button-danger`
- Badges: `.badge-active`, `.badge-expired`, `.badge-trial`, `.badge-revoked`
- Modals: `.eligius-modal` (WP-style overlay)
- Forms: `.form-table` with `.regular-text`, `.small-text`

---

## 4. Capabilities & Permissions

### 5.1 Capability Matrix

| Page/Action | Required Capability | Default Role |
|-------------|---------------------|--------------|
| View Dashboard | `eligius_sub_dashboard` | Administrator |
| View Users | `eligius_sub_users` | Administrator |
| Manage Users (impersonate, suspend) | `eligius_sub_manage_users` | Administrator |
| View Devices | `eligius_sub_devices` | Administrator |
| Manage Devices (deactivate, transfer) | `eligius_sub_manage_devices` | Administrator |
| View Subscriptions | `eligius_sub_subscriptions` | Administrator |
| Manage Subscriptions (extend, revoke) | `eligius_sub_manage_subscriptions` | Administrator |
| View Payments | `eligius_sub_payments` | Administrator |
| Manage Payments (refund, retry) | `eligius_sub_manage_payments` | Administrator |
| View Licenses | `eligius_sub_licenses` | Administrator |
| Manage Licenses (extend, revoke) | `eligius_sub_manage_licenses` | Administrator |
| View Audit Logs | `eligius_sub_audit_logs` | Administrator |
| Manage Gateway Settings | `eligius_sub_gateway_settings` | Administrator |
| Manage General Settings | `eligius_sub_manage_settings` | Administrator |

**Default Assignment (on activation):**
```php
$role = get_role('administrator');
foreach ($capabilities as $cap) {
    $role->add_cap($cap);
}
```

---

## 4. AJAX Endpoints (Admin)

| Endpoint | Method | Action | Nonce |
|----------|--------|--------|-------|
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_extend_license` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_revoke_license` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_bulk_extend` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_bulk_revoke` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_refund_payment` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_retry_verification` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | POST | `eligius_sub_device_transfer` | `eligius_sub_ajax_nonce` |
| `wp-admin/admin-ajax.php` | GET | `eligius_sub_export_csv` | `eligius_sub_ajax_nonce` |

**Response Format:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed"
}
```

---

## 5. Export & Reporting

### 7.1 CSV Export (All List Tables)

**Endpoint:** `POST /wp-admin/admin-ajax.php?action=eligius_sub_export_csv`

**Parameters:**
```json
{
  "page": "users|devices|subscriptions|payments|licenses",
  "filters": { ... },  // Current filter state
  "columns": ["id", "user", "plan", "status", ...]
}
```

**Response:** CSV file download

### 7.2 Reconciliation Report

**Endpoint:** `GET /admin.php?page=eligius-sub-payments&report=reconciliation&date_from=2026-11-01&date_to=2026-11-30`

**Output:** CSV with columns:
```
Date, Gateway, Total Transactions, Successful, Failed, Total Amount (IRT), Success Rate
2026-11-01, zarinpal, 150, 145, 5, 210000000, 96.7%
2026-11-01, idpay, 80, 78, 2, 96000000, 97.5%
...
TOTAL, , 230, 223, 7, 306000000, 96.9%
```

---

## 6. Notifications & Alerts

### 6.1 Admin Notices (WP Admin Bar)

| Trigger | Notice Type | Dismissible |
|---------|-------------|-------------|
| Gateway misconfigured | Error | No |
| Expiring licenses > 100 | Warning | Yes |
| Payment gateway error rate > 5% | Critical | No |
| New version available | Info | Yes |
| Trial conversion rate < 10% | Warning | Yes |

### 6.2 Email Notifications (Admin)

| Event | Recipients | Template |
|-------|------------|----------|
| Payment failed (high value) | Admins | `eligius_admin_payment_failed` |
| License revoked (admin action) | Admins | `eligius_admin_license_revoked` |
| Trial conversion rate drop | Admins | `eligius_admin_trial_conversion` |
| Gateway error rate spike | Admins | `eligius_admin_gateway_error` |

---

## 7. Accessibility & RTL Support

- **RTL First:** All layouts RTL-native (`dir="rtl"`)
- **Keyboard Navigation:** Full tab order, focus indicators
- **Screen Readers:** ARIA labels, live regions for dynamic content
- **Color Contrast:** WCAG AA minimum (4.5:1)
- **Focus Management:** Modal traps focus, returns on close

---

## 8. Performance Requirements

| Metric | Target |
|--------|--------|
| Dashboard load (p95) | < 2s |
| List table load (50 rows) | < 1s |
| AJAX action response | < 500ms |
| CSV export (10k rows) | < 10s |
| Dashboard auto-refresh | 30s interval |

---

*End of Admin Panel Specification*