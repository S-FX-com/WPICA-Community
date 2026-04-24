# Blt Community

**Home-centric membership management for civic associations and community organizations.**

Blt Community is a WordPress plugin where the unit of membership is a **property address**, not an individual. Multiple residents can belong to one home. Not all homes are required to be members. Built to be deployed independently per community (West Point Island Civic Association, OBYC, Seacrest, etc.) — either as separate installs or as a WordPress Multisite network.

---

## Tech Stack

| Layer | Tool |
|---|---|
| Data / CPT | WordPress CPT + ACF Pro |
| Field Groups | ACF (registered in code — no JSON import needed) |
| Registration Form | SureForms |
| Member Content Gating | SureMembers |
| Email / CRM | SureContact |
| Dues Payment | SureForms + payment gateway |

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- ACF Pro (required for date picker and user fields)
- SureForms (registration form)
- SureMembers (content gating)

---

## Installation

### Option A — Upload a zip (recommended)

1. Download this repository as a zip file.
2. Rename the top-level folder inside the zip to `blt-community`.
3. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
4. Upload the zip and activate.

### Option B — Manual

1. Clone or download this repository into `/wp-content/plugins/blt-community/`.
2. Activate through **Plugins → Installed Plugins**.

---

## First-Run Setup

After activation the **Onboarding Wizard** launches automatically under **Community → Dashboard**.

| Field | Description |
|---|---|
| Community Name | Full display name (e.g., West Point Island Civic Association) |
| Short Slug | Lowercase, no spaces — used in URLs (e.g., `wpica`) |
| Default Annual Dues | Dollar amount charged each cycle |
| Admin Email | Receives application notifications |
| Reset Month / Day | Date when active memberships expire annually |

The wizard calculates and displays the **Next Expiration Date** live. After saving, the page becomes the permanent Community Dashboard.

---

## Admin Menu

All plugin pages live under a single **Community** top-level menu.

```
Community
├── Dashboard        ← Stat cards, settings, next expiration date
├── Homes            ← All addresses with status, code, and linked users
│   └── Add New Home
├── Applications     ← Pending / approved / rejected member applications
├── Address Codes    ← Review and resolve code collisions
├── Address Importer ← Paste-and-create bulk importer
└── Reports          ← Reports dashboard + CSV exports
```

---

## Features

### Home CPT

Each property address is a WordPress custom post type (`cmm_home`). The post **title is the address** — no separate address field needed. ACF fields are registered entirely in code and travel with the plugin across all installs.

**ACF Fields on each Home:**

| Field | Type | Notes |
|---|---|---|
| Address Code | Text | Auto-generated. Edit only to resolve a collision. |
| Membership Status | Radio | active / inactive / expired / approved_pending_payment / pending_review / rejected |
| Dues Paid Date | Date Picker | |
| Dues Amount Paid | Number | |
| Primary Contact | User | Single user — becomes `home_admin` when active |
| Linked Users | User (multi) | All residents — assigned roles based on status |

---

### Address Code Generation

Auto-generates a short alphanumeric code from each address on save.

**Algorithm:**

```
Input:  "4 West Shore Rd"
Step 1: Tokenize → ["4", "West", "Shore", "Rd"]
Step 2: House number = "4"
Step 3: Strip suffix (Rd) → ["West", "Shore"]
Step 4: "West" is a cardinal direction → "W"
        First 2 chars of "Shore" → "SH"
        Prefix = "WSH"
Step 5: Code = "WSH4"

Input:  "2 Bullard Dr"   → BUL2
Input:  "15 Oak Avenue"  → OAK15
```

**Cardinal directions recognized:** East/E → E, West/W → W, North/N → N, South/S → S

**Collision handling:** If two addresses produce the same code, a persistent admin notice appears linking to **Community → Address Codes** where you can manually override either conflicting value.

---

### Bulk Address Importer

**Community → Address Importer** — Paste one address per line. Each becomes a `cmm_home` post with:
- Status: `inactive`
- Address code: auto-generated
- Linked users: empty

After import, collisions are detected and surfaced immediately.

```
Import complete.
142 homes created.  3 skipped (already exist).
⚠ 2 address code collision(s) detected. Resolve now →
```

---

### Custom Roles

| Role | Capabilities | Assigned When |
|---|---|---|
| `home_admin` | Manage home, view dues, invite/remove users | Home goes `active` (primary contact) |
| `home_member` | Read, view gated content | Home goes `active` (additional residents) |
| `pending_applicant` | Read only, no gated content | Application submitted |

Roles are synced automatically via an `acf/save_post` hook whenever a home's status changes. On expiry, `home_admin` and `home_member` roles are removed from all linked users.

**SureMembers integration:**
1. Create an Access Group: **"Active Members"**
2. Restrict all member-only pages to this group
3. Allow roles: `home_admin`, `home_member`
4. The plugin assigns/removes roles — SureMembers enforces the gate automatically

---

### Gated Signup & Application Flow

```
1.  Visitor arrives at /register
2.  SureForms registration form loads
3.  Visitor types address → AJAX typeahead returns matching homes
    (only inactive and expired homes appear)
4.  Visitor selects address → address code auto-populates (read-only)
5.  Visitor fills name, email, password, submits
6.  WordPress account created with role: pending_applicant
7.  Home status → pending_review
8.  Admin receives email notification
9.  Admin reviews in Community → Applications
        Approve → status: approved_pending_payment
                  applicant receives email with payment link
        Reject  → status: rejected
                  applicant receives decline email (optional reason)
10. Applicant pays dues via SureForms payment form
11. On payment confirmed:
        Home status → active
        User role   → home_admin
        SureMembers access unlocked automatically
```

**REST endpoint for typeahead:**
```
GET /wp-json/cmm/v1/addresses?q=west
```
Returns up to 10 matching homes with `inactive` or `expired` status.

**SureForms Custom HTML block** — paste this into your registration form to wire up the address field:

```html
<div class="cmm-address-field" style="position:relative;">
    <label for="cmm-address-input">Your Property Address</label>
    <input type="text" id="cmm-address-input" name="cmm_address_text"
           placeholder="Start typing your address..."
           autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <input type="hidden" id="cmm-home-id" name="cmm_home_id">
    <div id="cmm-address-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;
         background:#fff;border:1px solid #ccc;border-radius:0 0 4px 4px;
         max-height:200px;overflow-y:auto;z-index:999;"></div>
    <div id="cmm-address-code-display"
         style="display:none;margin-top:6px;padding:6px 10px;background:#f0f7ff;
                border:1px solid #b0d0f0;border-radius:4px;
                font-family:monospace;font-size:1.1em;color:#2271b1;"></div>
</div>
```

**Standard SureForms fields to add below it:**
- First Name (required)
- Last Name (required)
- Email Address (required)
- Password (required)
- Checkbox: "I am the property owner or authorized resident" (required)

---

### Annual Dues Reset

A WordPress cron job (`cmm_dues_reset_event`) fires daily and checks whether today matches the configured reset month + day. When it matches:

1. All `active` homes flip to `expired`
2. All linked users lose `home_admin` / `home_member` roles
3. A persistent, per-user dismissible admin notice is stored

**Manual trigger** (for testing): **Community → Reports → Run Dues Reset Now**

**Membership status reference:**

| Status | Meaning |
|---|---|
| `active` | Dues paid, full access |
| `inactive` | Home exists, never joined |
| `expired` | Was active, lapsed on reset date |
| `approved_pending_payment` | Admin approved, awaiting dues |
| `pending_review` | Application submitted, awaiting admin |
| `rejected` | Application denied |

---

### Reports Dashboard

**Community → Reports**

**Summary cards:**
- Total Homes
- Active Members
- Inactive
- Expired
- Total Dues YTD

**CSV Exports:**
- **Active Members** — Address, code, primary contact name + email, dues date, amount
- **All Homes** — Same columns, all statuses
- **Dues Report** — Address, code, amount paid, date paid, year

**Table filters:** status dropdown, dues year, address/code search

---

### My Home Shortcode

Place `[cmm_my_home]` on any WordPress page to render the member-facing dashboard.

**Home Admin sees:**
- Address + membership status badge
- Dues paid date and amount
- Full member list with **Remove** buttons (AJAX)
- Invite form (name + email → sends 7-day token link)
- Renew link (if status is `expired`)

**Home Member sees:**
- Address + status badge
- Member list (read-only)

**Pending Applicant sees:**
- "Your application is under review" message + admin contact email

**Logged out / no home linked:**
- Link to login or registration page

**Invite Token Flow:**
1. Home admin enters name + email → clicks Send Invite
2. Plugin generates a 32-char token stored as a transient (7-day expiry)
3. Invited person receives email with a registration link containing the token
4. They register (or log in if they already have an account)
5. Token is consumed → user linked to home, role set to `home_member`

---

## File Structure

```
blt-community/
├── blt-community.php                   ← Main plugin file
├── readme.txt                          ← WordPress plugin directory readme
├── includes/
│   ├── class-cpt.php                   ← Registers Home CPT + admin menu
│   ├── class-acf-fields.php            ← Registers ACF field group in code
│   ├── class-onboarding.php            ← Onboarding wizard + Community Dashboard
│   ├── class-address-codes.php         ← Address code generation + collision detection
│   ├── class-importer.php              ← Bulk address paste importer
│   ├── class-roles.php                 ← Custom roles + ACF role-sync hook
│   ├── class-applications.php          ← Application management + REST typeahead
│   ├── class-dues.php                  ← Dues reset cron + admin notice
│   ├── class-reports.php               ← Reports dashboard + CSV exports
│   └── class-frontend.php              ← [cmm_my_home] shortcode + invite flow
├── templates/
│   ├── dashboard-my-home.php           ← Member-facing home dashboard template
│   ├── application-form.php            ← Registration form address field block
│   └── reports-page.php                ← Admin reports template (override point)
└── assets/
    ├── css/
    │   └── cmm-admin.css               ← Shared admin + frontend styles
    └── js/
        ├── cmm-admin.js                ← AJAX remove-user
        └── cmm-address-typeahead.js    ← Address autocomplete with debounce + keyboard nav
```

---

## Deploying for a New Community

1. Install the plugin on a new site (or new Multisite sub-site)
2. Activate → onboarding wizard runs automatically
3. Enter community name, slug, dues amount, and reset date
4. **Community → Address Importer** — paste all property addresses
5. **Community → Address Codes** — resolve any code collisions
6. Configure SureMembers access group (allow `home_admin` + `home_member`)
7. Drop `[cmm_my_home]` on the member dashboard page
8. Build the SureForms registration form with the Custom HTML address block above

**Separate installs vs. Multisite:**
- Use **separate installs** if each community manages its own site independently
- Use **Multisite** if you manage all communities from one WordPress backend

---

## Changelog

### 1.0.0
- Initial release
- Home CPT with Community admin menu, ACF fields registered in code
- Onboarding wizard and Community Dashboard with live stat cards
- Address code generator (WSH4 / BUL2 / OAK15 algorithm) with collision detection and override screen
- Bulk address importer with post-import collision check
- Custom roles: `home_admin`, `home_member`, `pending_applicant`
- ACF role-sync hook on home status save
- Gated signup with REST address typeahead (debounced, keyboard-navigable)
- Application admin screen: pending / approved / rejected + resend payment email
- Annual dues reset cron with per-user dismissible admin notice and manual trigger
- Reports dashboard: summary cards, 3 CSV exports, status/year/search filters
- `[cmm_my_home]` shortcode: role-aware member dashboard
- Invite token flow (7-day transient, email link, auto-accept)
- AJAX remove-user from home

---

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
