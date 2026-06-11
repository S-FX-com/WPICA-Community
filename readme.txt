=== WPICA Community ===
Contributors:      blt
Tags:              membership, community, civic association, dues, HOA
Requires at least: 6.0
Tested up to:      6.7
Stable tag:        1.2.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Home-centric membership management for the West Point Island Civic Association.

== Description ==

WPICA Community is a full-featured membership management plugin built for the
West Point Island Civic Association. Membership is **home-centric** — the unit of
membership is a property address, not an individual. Multiple users can belong to one
home address.

**Core Features**

* **Home CPT** — Each address is a WordPress custom post type. The post title IS the address.
* **ACF Integration** — Field group registered in code; no JSON import required.
* **Address Code Generation** — Auto-generates short codes (e.g., WSH4, BUL2, OAK15) from addresses.
* **Collision Detection** — Flags duplicate codes and provides an override screen.
* **Bulk Address Importer** — Paste a list of addresses; each becomes a Home record instantly.
* **Custom Roles** — home_admin, home_member, and pending_applicant integrate with SureMembers.
* **Gated Signup Flow** — REST-powered address typeahead on the registration form.
* **Application Management** — Admin approve/reject queue with email notifications.
* **Annual Dues Reset** — Configurable cron flips active homes to expired on a set date.
* **Reports Dashboard** — Summary cards, filterable table, and three CSV exports.
* **My Home Shortcode** — [cmm_my_home] — member dashboard with invite flow and user removal.
* **Invite Token System** — Home admins invite household members via a 7-day token link.
* **Onboarding Wizard** — First-run wizard collects community name, slug, dues, and reset date.

**Integrations**

| Layer | Tool |
|---|---|
| Data / CPT | WordPress CPT + ACF Pro |
| Registration Form | SureForms |
| Member Content Gating | SureMembers |
| Email / CRM | SureContact |
| Dues Payment | SureForms + payment gateway |

**Multi-Community Ready**

All settings use the `cmm_` prefix in wp_options. Deploy one plugin per WordPress install
or use WordPress Multisite — each site gets fully isolated configuration, homes, and users.

== Installation ==

1. Upload the `blt-community` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. The onboarding wizard runs automatically — complete it to configure your community.
4. Paste your address list in **Community → Address Importer**.
5. Resolve any code collisions in **Community → Address Codes**.
6. Configure SureMembers to allow roles `home_admin` and `home_member`.
7. Drop `[cmm_my_home]` on your member dashboard page.
8. Build your registration form in SureForms with the Custom HTML address block.

== Frequently Asked Questions ==

= Does the plugin require ACF Pro? =
Yes. ACF Pro is required for the date picker and user fields used on the Home CPT.

= Can I deploy this for multiple communities? =
Yes. Use separate WordPress installs or WordPress Multisite sub-sites. Each site
gets its own fully isolated configuration, address list, and user base.

= How does the address code algorithm work? =
Given an address like "4 West Shore Rd":
1. Tokenize → ["4", "West", "Shore", "Rd"]
2. House number = "4"
3. Strip suffix (Rd, Dr, Ave, etc.) → ["West", "Shore"]
4. "West" is a cardinal direction → abbreviate to "W"
5. Take first 2 chars of next word "Shore" → "SH"
6. Code = "WSH" + "4" = **WSH4**

= What happens when two addresses produce the same code? =
A persistent admin notice appears with a link to **Community → Address Codes** where
you can manually override either conflicting code.

= How do I test the dues reset without waiting for the cron? =
Go to **Community → Reports** and click "Run Dues Reset Now". This immediately runs
the reset logic regardless of the configured date.

== Screenshots ==

1. Community Dashboard — settings overview with stat cards and next expiration date.
2. Address Code Manager — review and resolve code collisions.
3. Address Importer — paste addresses in bulk.
4. Application Queue — approve/reject pending applications.
5. Reports Dashboard — summary cards and filterable home table.
6. My Home Dashboard — member-facing shortcode with invite form.

== Changelog ==

= 1.2.0 =
* New: SureForms webhook integration — two REST endpoints automate the full membership pipeline.
* Application webhook (/wp-json/cmm/v1/webhook/application): links registrant to home, sets pending_review, notifies admin.
* Payment webhook (/wp-json/cmm/v1/webhook/payment): activates home, records dues, syncs roles automatically.
* Accepts home_id or address_code on the payment endpoint.
* Webhook URLs, secrets, and SureForms field mapping shown in Community -> Dashboard.
* Per-endpoint secret regeneration from the Dashboard.

= 1.1.0 =
* New: "CMM Address Lookup" Gutenberg block — search for it by name in SureForms or any block editor.
* Block is server-side rendered; configurable label, placeholder, and required toggle via sidebar.
* Automatically enqueues typeahead JS and frontend CSS when rendered.

= 1.0.0 =
* Initial release.
* Home CPT, ACF fields, onboarding wizard, address code generation.
* Bulk address importer with collision detection.
* Custom roles (home_admin, home_member, pending_applicant).
* Gated signup with REST address typeahead.
* Application management with approve/reject/resend email flow.
* Annual dues reset cron with per-user dismissible admin notice.
* Reports dashboard with three CSV exports.
* [cmm_my_home] shortcode with invite tokens and AJAX user removal.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
