# WPICA Community

**Home-centric membership management for the West Point Island Civic Association.**

WPICA Community is a WordPress plugin where the unit of membership is a **property address**, not an individual. Multiple residents can belong to one home. Not every home is required to be a member. Dedicated to the West Point Island Civic Association (WPICA); the architecture remains community-agnostic (all settings live under the `cmm_` prefix), so it can be spun off for other communities (OBYC, Seacrest, etc.) in the future. Formerly published as "BLT Community" — internal file names, prefixes, and the GitHub repository keep the original `blt-community` naming for update continuity.

---

## Table of Contents

1. [Tech Stack](#tech-stack)
2. [Requirements & Dependencies](#requirements--dependencies)
3. [Installation](#installation)
4. [First-Run Setup](#first-run-setup)
5. [Admin Menu](#admin-menu)
6. [How It Works — Core Concepts](#how-it-works--core-concepts)
7. [Features](#features)
   - [Home CPT](#home-cpt)
   - [Address Code Generation](#address-code-generation)
   - [Bulk Address Importer](#bulk-address-importer)
   - [Custom Roles](#custom-roles)
   - [Gated Signup & Application Flow](#gated-signup--application-flow)
   - [CMM Address Lookup Block](#cmm-address-lookup-block)
   - [Annual Dues Reset](#annual-dues-reset)
   - [Reports Dashboard](#reports-dashboard)
   - [My Home Shortcode](#my-home-shortcode)
8. [File Structure](#file-structure)
9. [Deploying for a New Community](#deploying-for-a-new-community)
10. [Multi-Community & Multisite](#multi-community--multisite)
11. [Changelog](#changelog)
12. [License](#license)

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

## Requirements & Dependencies

### WordPress Core
- WordPress 6.0+
- PHP 8.0+

### Required Plugins

The following plugins must be installed and activated before WPICA Community will function fully. Each requires specific manual configuration after the plugin is activated — see the sections below.

| Plugin | Why it's needed | Where to get it |
|---|---|---|
| **ACF Pro** | Date picker and User fields on the Home CPT. The free version of ACF does not include these field types. | advancedcustomfields.com |
| **SureForms** | Powers the member registration form. The CMM Address Lookup block appears in the SureForms block panel. | sureforms.com |
| **SureMembers** | Enforces content gating for active members. The plugin assigns/removes roles; SureMembers enforces the gate. | suremembers.com |

### Optional but Recommended

| Plugin | Purpose |
|---|---|
| **SureContact** | Transactional and CRM emails (application notifications, payment links, invite tokens) |

---

### Dependency Configuration

The plugin assigns WordPress roles but does **not** automatically configure the third-party plugins for you. The following one-time manual setup is required in each dependency before the plugin works end-to-end.

#### ACF Pro

No extra configuration needed. ACF Pro must simply be installed and activated. The plugin registers its own field group (`group_cmm_home`) in PHP — no JSON import, no UI setup.

#### SureMembers

SureMembers gates content based on Access Groups. You need **one Access Group** for active members:

1. Go to **SureMembers → Access Groups → Add New**
2. Name it **Active Members** (or any name you prefer)
3. Under **Who Can Access**, set the allowed role to **`home_admin`**

> **Note:** Currently, the plugin only requires `home_admin` to be added to the SureMembers access group. `home_member` is assigned to additional household members after a home admin invites them — if you also want those users to pass the gate, add `home_member` to the allowed roles list as well.

4. Assign all member-only pages and content to this Access Group
5. The plugin handles role assignment/removal automatically — SureMembers enforces the gate

#### SureForms — Registration Form

SureForms must be configured to handle the registration form. The plugin provides the address typeahead field and processes the submitted data, but you need to build and wire up the form manually:

1. Create a new form in **SureForms → Add New**
2. Add the **CMM Address Lookup** block (search by name in the block inserter)
3. Add the remaining fields: First Name, Last Name, Email Address, Password, and a required "I am the property owner or authorized resident" checkbox
4. Configure form submission to create a WordPress user account and call the CMM application handler

#### SureForms — Webhooks

The plugin exposes two REST endpoints that SureForms can POST to on form submission. Configure them under **Community → Dashboard → SureForms Webhook Configuration**, which shows the exact URLs, secrets, and field mappings.

Both endpoints use **POST / JSON** and require an `Authorization: Bearer <secret>` header. Each secret is auto-generated and can be regenerated from the Dashboard.

**Registration form → Application webhook**

`POST /wp-json/cmm/v1/webhook/application`

| JSON field | Map to |
|---|---|
| `home_id` | Hidden field — auto-populated by the CMM Address Lookup block |
| `email` | Email Address field |
| `first_name` | First Name field |
| `last_name` | Last Name field |

What it does: looks up the WordPress user by email (SureForms creates the user first), links them to the home as primary contact, sets home status to `pending_review`, assigns `pending_applicant` role, and emails the admin.

**Payment form → Payment confirmation webhook**

`POST /wp-json/cmm/v1/webhook/payment`

| JSON field | Map to |
|---|---|
| `home_id` | Hidden field — pass through from registration form |
| `amount` | Payment amount (numeric) |
| `date` | Payment date in `YYYY-MM-DD` — defaults to today if omitted |

What it does: verifies the home is in `approved_pending_payment` status, records the dues amount and date, sets home status to `active`, and syncs roles (primary contact becomes `home_admin`; SureMembers gate opens automatically).

---

## Installation

### Option A — Upload a zip (recommended)

1. Download this repository as a zip file.
2. Rename the top-level folder inside the zip to `blt-community`.
3. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
4. Upload the zip and activate.

### Option B — Manual (git clone)

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

The wizard calculates and displays the **Next Expiration Date** live. After saving, the same page becomes the permanent **Community Dashboard**.

---

## Admin Menu

All plugin pages live under a single **Community** top-level menu.

```
Community
├── Dashboard        ← Stat cards, community settings, next expiration date
├── Homes            ← All addresses with membership status, code, and linked users
│   └── Add New Home
├── Applications     ← Pending / approved / rejected member applications
├── Address Codes    ← Review and manually resolve code collisions
├── Address Importer ← Paste-and-create bulk importer
└── Reports          ← Reports dashboard + CSV exports
```

---

## How It Works — Core Concepts

### Membership is per address

Each property is stored as a `cmm_home` WordPress custom post type. The **post title is the address** — no separate address field is needed. ACF Pro fields attached to each home track its membership status, dues history, and which WordPress users live there.

### Application pipeline

```
Visitor registers → pending_review
Admin approves   → approved_pending_payment (payment email sent)
Payment received → active (roles assigned, SureMembers gate opens)
Annual reset     → expired (roles removed, gate closes)
Resident renews  → active again
```

### Role-based access

Three custom roles integrate with SureMembers for automatic content gating:

| Role | Access |
|---|---|
| `home_admin` | Full member access + manage household |
| `home_member` | Full member access (read-only household) |
| `pending_applicant` | No gated content; application-under-review state |

Roles are assigned and removed automatically whenever a home's membership status changes.

---

## Features

### Home CPT

Each property address is a WordPress custom post type (`cmm_home`). The post **title is the address**. ACF fields are registered entirely in code — they travel with the plugin across all installs without a JSON import.

**ACF Fields on each Home:**

| Field | Type | Notes |
|---|---|---|
| Address Code | Text | Auto-generated. Edit only to resolve a collision. |
| Membership Status | Radio | `active` / `inactive` / `expired` / `approved_pending_payment` / `pending_review` / `rejected` |
| Dues Paid Date | Date Picker | |
| Dues Amount Paid | Number | |
| Primary Contact | User | Single user — becomes `home_admin` when active |
| Linked Users | User (multi) | All residents — roles assigned based on status |

---

### Address Code Generation

Auto-generates a short alphanumeric identifier from each address on save.

**Algorithm:**

```
Input:  "4 West Shore Rd"
Step 1: Tokenize → ["4", "West", "Shore", "Rd"]
Step 2: House number = "4"
Step 3: Strip street suffix (Rd) → ["West", "Shore"]
Step 4: "West" is a cardinal direction → abbreviate to "W"
        First 2 chars of next word "Shore" → "SH"
        Prefix = "WSH"
Step 5: Code = "WSH4"

More examples:
  "2 Bullard Dr"   → BUL2
  "15 Oak Avenue"  → OAK15
  "7 W Shell Way"  → WSH7   (← potential collision with WSH4 pattern)
```

**Cardinal directions recognized:** East / E → `E`, West / W → `W`, North / N → `N`, South / S → `S`

**Non-directional streets:** First 3 characters of the street name are used as the prefix (e.g., `OAK`, `BUL`).

**Collision handling:** If two addresses produce the same code (e.g., "West Shore" and "W Shell" both produce `WSH`), a persistent admin notice appears at the top of every admin screen, linking to **Community → Address Codes** where you can manually override either conflicting value. The code field for a home is never overwritten once manually edited.

---

### Bulk Address Importer

**Community → Address Importer** — paste one address per line. Each line becomes a `cmm_home` post with:
- Status: `inactive`
- Address code: auto-generated
- Linked users: empty (filled as residents register)

After import, the plugin immediately runs a collision check and surfaces any conflicts in the result notice:

```
Import complete.
142 homes created.  3 skipped (already exist).
⚠ 2 address code collision(s) detected. Resolve now →
```

---

### Custom Roles

| Role | Capabilities | Assigned When |
|---|---|---|
| `home_admin` | Manage home, view dues, invite/remove household members | Home goes `active` (primary contact) |
| `home_member` | Read, view gated content | Home goes `active` (additional residents) |
| `pending_applicant` | Read only — no gated content | Application submitted |

Roles are synced automatically via an `acf/save_post` hook whenever a home's status changes in the admin. On expiry (annual reset or manual status change), `home_admin` and `home_member` roles are removed from all linked users, closing the SureMembers gate instantly.

**SureMembers integration:**

1. Create an Access Group: **"Active Members"**
2. Restrict all member-only pages to this group
3. Allow role: `home_admin` (add `home_member` too if additional household members should also pass the gate)
4. The plugin assigns and removes roles — SureMembers enforces the gate automatically

> See [Dependency Configuration → SureMembers](#suremembers) for full setup instructions.

---

### Gated Signup & Application Flow

```
1.  Visitor arrives at /register
2.  SureForms registration form loads
3.  Visitor types their address → AJAX typeahead returns matching homes
    (only homes with status inactive or expired appear — active/pending are hidden)
4.  Visitor selects their address → address code auto-populates (read-only)
5.  Visitor fills name, email, password, submits
6.  WordPress account created with role: pending_applicant
7.  Home status set to: pending_review
8.  Admin receives email notification
9.  Admin reviews in Community → Applications:
        Approve → status: approved_pending_payment
                  applicant receives email with payment link
        Reject  → status: rejected
                  applicant receives decline email (optional reason)
10. Applicant pays dues via SureForms payment form
11. SureForms fires the payment webhook → home status → active, dues recorded, roles synced
    SureMembers access unlocked automatically via role assignment
```

**REST endpoint for typeahead:**
```
GET /wp-json/cmm/v1/addresses?q=west
```
Returns up to 10 matching homes (titles containing the query) with `inactive` or `expired` status.

**Application admin screen — Community → Applications:**

The admin screen shows three sections:

- **Pending Applications** — new submissions awaiting review, with Approve / Reject buttons per row
- **Approved — Awaiting Payment** — applicants who were approved but haven't paid yet, with a Resend Payment Email button to follow up without overhead
- **Rejected** — declined applications for audit trail

---

### CMM Address Lookup Block

The recommended way to wire up the address typeahead on your registration form. Instead of pasting raw HTML, search for **"CMM Address Lookup"** in the SureForms block inserter.

**What the block does:**
- Renders the full typeahead address field with live REST-powered search
- Automatically enqueues the required JavaScript and CSS
- Is configurable from the block sidebar (Label, Placeholder, Required toggle)
- Is server-side rendered, so it always reflects the current plugin state
- Integrates with SureForms' block panel via the `srfm_register_additional_blocks` filter

**Standard SureForms fields to add below the block:**

| Field | Required |
|---|---|
| First Name | Yes |
| Last Name | Yes |
| Email Address | Yes |
| Password | Yes |
| Checkbox: "I am the property owner or authorized resident" | Yes |

**Manual HTML alternative (advanced):**

If you need to build the address field without the block (e.g., in a non-SureForms form or custom template), paste this Custom HTML block into your form:

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

Note: When using the manual HTML block, you must also ensure `cmm-address-typeahead.js` and `cmm-admin.css` are enqueued on the page. The CMM Address Lookup block handles this automatically.

---

### Annual Dues Reset

A WordPress cron job (`cmm_dues_reset_event`) fires daily and checks whether today matches the configured reset month + day. When it matches:

1. All `active` homes flip to `expired`
2. All linked users lose `home_admin` / `home_member` roles
3. SureMembers gate closes automatically (no manual action required)
4. A persistent, per-user dismissible admin notice is stored and shown on the next admin page load

**Manual trigger** (for testing or off-cycle resets): **Community → Reports → Run Dues Reset Now**

**Membership status reference:**

| Status | Meaning |
|---|---|
| `active` | Dues paid — full member access |
| `inactive` | Home exists but has never joined |
| `expired` | Was active; lapsed on the configured reset date |
| `approved_pending_payment` | Admin approved the application; awaiting dues payment |
| `pending_review` | Application submitted; awaiting admin decision |
| `rejected` | Application was denied |

---

### Reports Dashboard

**Community → Reports**

**Summary stat cards:**

| Card | Shows |
|---|---|
| Total Homes | Count of all `cmm_home` posts |
| Active Members | Homes with status `active` |
| Inactive | Homes with status `inactive` |
| Expired | Homes with status `expired` |
| Total Dues YTD | Sum of dues paid amounts in the current calendar year |

**CSV Exports:**

| Export | Columns |
|---|---|
| Active Members | Address, code, primary contact name + email, dues paid date, amount |
| All Homes | Same columns — all statuses included |
| Dues Report | Address, code, amount paid, date paid, year |

**Table filters:** filter by membership status, filter by dues year, search by address or code.

---

### My Home Shortcode

Place `[cmm_my_home]` on any WordPress page to render the member-facing dashboard. The display is role-aware.

**Home Admin sees:**
- Address + membership status badge
- Dues paid date and amount
- Full household member list with **Remove** buttons (AJAX — no page reload)
- Invite form (name + email → sends a 7-day token link)
- Renew link (if status is `expired`)

**Home Member sees:**
- Address + membership status badge
- Household member list (read-only)

**Pending Applicant sees:**
- "Your application is under review" message + admin contact email

**Logged out / no home linked:**
- Link to login or registration page

**Invite Token Flow:**

```
1. Home admin enters name + email → clicks Send Invite
2. Plugin generates a 32-character token stored as a WordPress transient (7-day expiry)
3. Invited person receives email with a registration link containing the token
4. They register (or log in if they already have a WordPress account)
5. Token is consumed → user linked to the home, role set to home_member
6. Token deleted
```

---

## File Structure

```
blt-community/
├── blt-community.php                   ← Main plugin file (loader + hooks)
├── readme.txt                          ← WordPress plugin directory readme
├── includes/
│   ├── class-cpt.php                   ← Registers Home CPT + Community admin menu
│   ├── class-acf-fields.php            ← Registers ACF field group in code
│   ├── class-onboarding.php            ← Onboarding wizard + Community Dashboard
│   ├── class-address-codes.php         ← Address code generation + collision detection
│   ├── class-importer.php              ← Bulk address paste importer
│   ├── class-roles.php                 ← Custom role registration + ACF role-sync hook
│   ├── class-applications.php          ← Application management + REST typeahead endpoint
│   ├── class-dues.php                  ← Dues reset cron + admin notice
│   ├── class-reports.php               ← Reports dashboard + CSV exports
│   ├── class-frontend.php              ← [cmm_my_home] shortcode + invite flow
│   └── class-block.php                 ← Registers CMM Address Lookup block with SureForms
├── blocks/
│   └── address-lookup/                 ← CMM Address Lookup Gutenberg block
│       ├── block.json                  ← Block metadata (name, attributes, supports)
│       ├── block.php                   ← Server-side render class (BltCommunity\Blocks\Address_Lookup\Block)
│       ├── index.js                    ← Block editor script
│       ├── index.asset.php             ← Asset manifest
│       └── editor.css                  ← Block editor styles
├── templates/
│   ├── dashboard-my-home.php           ← Member-facing My Home dashboard template
│   ├── application-form.php            ← Registration form address field (legacy template)
│   └── reports-page.php                ← Admin reports page template
└── assets/
    ├── css/
    │   └── cmm-admin.css               ← Shared admin + frontend styles
    └── js/
        ├── cmm-admin.js                ← AJAX remove-user from home
        └── cmm-address-typeahead.js    ← Address autocomplete (debounce + keyboard navigation)
```

---

## Deploying for a New Community

Follow these steps for each new community (e.g., OBYC, Seacrest):

1. Install the plugin on a new WordPress site (or a new Multisite sub-site)
2. Activate → the onboarding wizard launches automatically
3. Fill in community name, short slug, default dues amount, admin email, and dues reset date
4. **Community → Address Importer** — paste all property addresses, one per line
5. **Community → Address Codes** — review and resolve any code collisions
6. In SureMembers, create an Access Group called **"Active Members"** and restrict all member-only content to it, allowing roles `home_admin` and `home_member`
7. Create a member dashboard page and add the `[cmm_my_home]` shortcode
8. Build the registration form in SureForms:
   - Add the **CMM Address Lookup** block (search by name in the block inserter)
   - Add First Name, Last Name, Email, Password fields
   - Add a required checkbox: "I am the property owner or authorized resident"
9. Set up the SureForms payment form for dues collection
10. In SureForms, configure the **application webhook** on the registration form and the **payment webhook** on the payment form — both URLs and secrets are shown in **Community → Dashboard → SureForms Webhook Configuration**
11. Test the full flow: register → approve → pay → verify access is granted automatically

---

## Multi-Community & Multisite

All plugin options use the `cmm_` prefix in `wp_options`. Every WordPress install (or Multisite sub-site) gets its own fully isolated configuration, address list, and user base.

**Separate installs:** choose this if each community manages its own WordPress site independently. Simpler to administer per-community; no shared infrastructure.

**WordPress Multisite:** choose this if you (as the network admin) manage all communities from a single WordPress backend. Each sub-site runs the same plugin with its own data. Easier to push plugin updates across all communities at once.

---

## Changelog

### 1.2.0
- New: SureForms webhook integration — two REST endpoints (`/webhook/application`, `/webhook/payment`) automate the full membership pipeline
- Application webhook: receives registration form submission, links user to home, sets `pending_review`, notifies admin
- Payment webhook: receives payment confirmation, sets home to `active`, records dues, syncs roles — SureMembers gate opens automatically
- Accepts `home_id` or `address_code` on the payment endpoint
- Webhook URLs, Bearer secrets, and SureForms field mapping guide shown in Community → Dashboard
- Per-endpoint secret regeneration from the Dashboard

### 1.1.0
- New: **CMM Address Lookup** Gutenberg block — search for it by name in the SureForms block inserter
- Block is server-side rendered; label, placeholder, and required toggle are configurable from the block sidebar
- Block automatically enqueues the typeahead JavaScript and frontend CSS when rendered — no manual asset enqueueing needed
- Block integrates with SureForms via the `srfm_register_additional_blocks` filter

### 1.0.0
- Initial release
- Home CPT (`cmm_home`) with Community admin menu; ACF fields registered entirely in code
- Onboarding wizard and Community Dashboard with live stat cards and next expiration date
- Address code generator (WSH4 / BUL2 / OAK15 algorithm) with collision detection and override screen
- Bulk address importer with post-import collision check
- Custom roles: `home_admin`, `home_member`, `pending_applicant`
- ACF `save_post` role-sync hook — roles update automatically when home status changes
- Gated signup with REST address typeahead (debounced, keyboard-navigable, inactive/expired homes only)
- Application admin screen: pending / approved-awaiting-payment / rejected sections with resend email
- Annual dues reset cron with per-user dismissible admin notice and manual trigger
- Reports dashboard: five summary stat cards, three CSV exports, status / year / search filters
- `[cmm_my_home]` shortcode: role-aware member dashboard (admin, member, applicant, logged-out views)
- Invite token flow (7-day transient, email link, auto-accept on registration or login)
- AJAX remove-user from home

---

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
