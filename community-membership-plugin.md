# Community Membership Manager — WordPress Plugin Specification

## Overview

**Community Membership Manager (CMM)** is a reusable WordPress plugin for civic associations
and community organizations. Membership is **home-centric** — the unit of membership is a
property address, not an individual. Multiple users can belong to one home. Not all homes are
required to be members.

Designed to be deployed independently per community (West Point Island Civic Association, OBYC,
Seacrest, etc.) — either as separate installs or as a WordPress Multisite network.

---

## Tech Stack Integration

| Layer | Tool |
|---|---|
| Data / CPT | WordPress CPT + ACF Pro |
| Field Groups | ACF (registered in code, no JSON import needed) |
| Registration Form | SureForms |
| Member Content Gating | SureMembers |
| Email / CRM | SureContact |
| Dues Payment | SureForms + payment gateway |

---

## Plugin File Structure

```
community-membership-manager/
├── community-membership-manager.php     ← Main plugin file
├── includes/
│   ├── class-cpt.php                    ← Registers Home CPT
│   ├── class-acf-fields.php             ← Registers ACF field group in code
│   ├── class-onboarding.php             ← Onboarding wizard + settings
│   ├── class-address-codes.php          ← Address code generation + collision detection
│   ├── class-importer.php               ← Bulk address paste importer
│   ├── class-roles.php                  ← Custom roles and capabilities
│   ├── class-applications.php           ← Gated signup application management
│   ├── class-dues.php                   ← Dues reset cron, expiry logic, admin notices
│   ├── class-reports.php                ← Reports dashboard + CSV exports
│   └── class-frontend.php               ← Frontend My Home dashboard shortcode
├── templates/
│   ├── dashboard-my-home.php            ← Member-facing home dashboard
│   ├── application-form.php             ← Registration/application form template
│   └── reports-page.php                 ← Admin reports template
├── assets/
│   ├── css/
│   │   └── cmm-admin.css
│   └── js/
│       ├── cmm-admin.js
│       └── cmm-address-typeahead.js     ← Address autocomplete for signup form
└── readme.txt
```

---

## Admin Menu Structure

All plugin pages live under a single top-level **Community** menu item.

```
Community
├── Dashboard        ← Settings overview, notices, next expiration date
├── Homes            ← CPT list (all addresses, status, linked users)
│   └── Add New Home
├── Applications     ← Pending / approved / rejected member applications
├── Address Codes    ← Review and resolve code collisions
├── Address Importer ← Paste-and-create bulk importer
├── Reports          ← Reports dashboard + CSV exports
└── Setup            ← Onboarding wizard (hidden after first run)
```

The CPT is registered with `'show_in_menu' => 'community-membership'` so WordPress
automatically nests Homes and Add New Home under the parent. All subpages use the same
parent slug. The first submenu item shares the parent slug to suppress the WordPress
duplicate entry.

---

## Step 1 — Main Plugin File

**File:** `community-membership-manager.php`

```php
<?php
/**
 * Plugin Name: Community Membership Manager
 * Description: Home-centric membership management for civic associations.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: cmm
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CMM_VERSION', '1.0.0' );
define( 'CMM_PATH',    plugin_dir_path( __FILE__ ) );
define( 'CMM_URL',     plugin_dir_url( __FILE__ ) );

foreach ( [
    'class-cpt',
    'class-acf-fields',
    'class-onboarding',
    'class-address-codes',
    'class-importer',
    'class-roles',
    'class-applications',
    'class-dues',
    'class-reports',
    'class-frontend',
] as $class ) {
    require_once CMM_PATH . 'includes/' . $class . '.php';
}

add_action( 'plugins_loaded', function() {
    CMM_CPT::init();
    CMM_ACF_Fields::init();
    CMM_Onboarding::init();
    CMM_Address_Codes::init();
    CMM_Importer::init();
    CMM_Roles::init();
    CMM_Applications::init();
    CMM_Dues::init();
    CMM_Reports::init();
    CMM_Frontend::init();
});

register_activation_hook( __FILE__, function() {
    add_option( 'cmm_onboarding_complete',  false );
    add_option( 'cmm_community_name',       '' );
    add_option( 'cmm_community_slug',       '' );
    add_option( 'cmm_dues_amount',          0 );
    add_option( 'cmm_dues_cycle',           'annual' );
    add_option( 'cmm_admin_email',          get_option('admin_email') );
    add_option( 'cmm_dues_reset_month',     '01' );
    add_option( 'cmm_dues_reset_day',       '01' );
    add_option( 'cmm_address_codes',        [] );

    if ( ! wp_next_scheduled( 'cmm_dues_reset_event' ) ) {
        wp_schedule_event( time(), 'daily', 'cmm_dues_reset_event' );
    }
});

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'cmm_dues_reset_event' );
});
```

---

## Step 2 — Register the Home CPT

**File:** `includes/class-cpt.php`

The post **title** is the home address. No separate address field is needed.

```php
<?php
class CMM_CPT {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_post_type( 'cmm_home', [
            'label'           => 'Homes',
            'labels'          => [
                'name'          => 'Homes',
                'singular_name' => 'Home',
                'add_new_item'  => 'Add New Home',
                'edit_item'     => 'Edit Home',
                'search_items'  => 'Search Homes',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'community-membership',
            'supports'        => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }
}
```

---

## Step 3 — ACF Field Group

**File:** `includes/class-acf-fields.php`

Registered entirely in code. Travels with the plugin across all installs with no JSON import.

```php
<?php
class CMM_ACF_Fields {

    public static function init() {
        add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
    }

    public static function register_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        acf_add_local_field_group([
            'key'      => 'group_cmm_home',
            'title'    => 'Home Membership Details',
            'location' => [[[ 
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'cmm_home',
            ]]],
            'fields' => [

                [
                    'key'          => 'field_cmm_address_code',
                    'label'        => 'Address Code',
                    'name'         => 'address_code',
                    'type'         => 'text',
                    'instructions' => 'Auto-generated. Edit only to resolve a collision.',
                ],

                [
                    'key'           => 'field_cmm_membership_status',
                    'label'         => 'Membership Status',
                    'name'          => 'membership_status',
                    'type'          => 'radio',
                    'choices'       => [
                        'active'                   => 'Active',
                        'inactive'                 => 'Inactive',
                        'expired'                  => 'Expired',
                        'approved_pending_payment' => 'Approved — Pending Payment',
                        'pending_review'           => 'Pending Admin Review',
                        'rejected'                 => 'Rejected',
                    ],
                    'default_value' => 'inactive',
                    'layout'        => 'horizontal',
                ],

                [
                    'key'            => 'field_cmm_dues_paid_date',
                    'label'          => 'Dues Paid Date',
                    'name'           => 'dues_paid_date',
                    'type'           => 'date_picker',
                    'display_format' => 'F j, Y',
                    'return_format'  => 'Y-m-d',
                ],

                [
                    'key'     => 'field_cmm_dues_amount_paid',
                    'label'   => 'Dues Amount Paid',
                    'name'    => 'dues_amount_paid',
                    'type'    => 'number',
                    'prepend' => '$',
                ],

                [
                    'key'           => 'field_cmm_primary_contact',
                    'label'         => 'Primary Contact',
                    'name'          => 'primary_contact',
                    'type'          => 'user',
                    'multiple'      => 0,
                    'return_format' => 'id',
                ],

                [
                    'key'           => 'field_cmm_linked_users',
                    'label'         => 'Linked Users',
                    'name'          => 'linked_users',
                    'type'          => 'user',
                    'multiple'      => 1,
                    'return_format' => 'id',
                ],
            ],
        ]);
    }
}
```

---

## Step 4 — Address Code Generation

**File:** `includes/class-address-codes.php`

### Generation Algorithm

```
Input:  "4 West Shore Rd"
Step 1: Tokenize → ["4", "West", "Shore", "Rd"]
Step 2: House number = "4"
Step 3: Strip suffix (Rd, Dr, Ave, St, Way, Ct, Ln, Blvd, Pl...) → ["West", "Shore"]
Step 4: First word "West" is a cardinal direction → abbreviate to "W"
        Take first 2 chars of next word "Shore" → "SH"
        Prefix = "W" + "SH" = "WSH"
Step 5: Code = "WSH" + "4" = "WSH4"

Input:  "7 W Shell Way"
Step 4: "W" is a cardinal abbreviation → "W"
        First 2 chars of "Shell" → "SH"
        Prefix = "WSH"
Step 5: Code = "WSH7"  ← COLLISION with WSH4 pattern, flagged if same number

Input:  "2 Bullard Dr"
Step 4: "Bullard" is not a directional
        First 3 chars = "BUL"
Step 5: Code = "BUL2"

Input:  "15 Oak Avenue"
Step 5: Code = "OAK15"
```

Cardinal directions recognized (full and abbreviated):
East/E → E, West/W → W, North/N → N, South/S → S

### Collision Handling

Collisions occur when two different addresses resolve to the same code (e.g. West Shore
and W Shell both produce WSH). The plugin detects this and displays a persistent admin
notice linking to the Address Codes settings screen for manual override.

```php
<?php
class CMM_Address_Codes {

    private static array $directionals = [
        'east' => 'E', 'west'  => 'W', 'north' => 'N', 'south' => 'S',
        'e'    => 'E', 'w'     => 'W', 'n'     => 'N', 's'     => 'S',
    ];

    private static array $suffixes = [
        'rd', 'dr', 'ave', 'st', 'way', 'ct',
        'ln', 'blvd', 'pl', 'ter', 'trl', 'cir',
    ];

    public static function init() {
        add_action( 'save_post_cmm_home', [ __CLASS__, 'generate_on_save' ], 10, 2 );
        add_action( 'admin_notices',      [ __CLASS__, 'collision_notice' ] );
        add_action( 'admin_menu',         [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_save_codes', [ __CLASS__, 'save_overrides' ] );
    }

    public static function generate_on_save( int $post_id, WP_Post $post ) {
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( get_field( 'address_code', $post_id ) ) return; // Never overwrite manual edits

        $code = self::generate( $post->post_title );
        update_field( 'address_code', $code, $post_id );
        self::check_collisions();
    }

    public static function generate( string $address ): string {
        $tokens  = preg_split( '/\s+/', trim( $address ) );
        $house   = is_numeric( $tokens[0] ) ? $tokens[0] : '';
        $words   = is_numeric( $tokens[0] ) ? array_slice( $tokens, 1 ) : $tokens;

        // Remove street suffix
        $words = array_values( array_filter( $words, fn($w) =>
            ! in_array( strtolower($w), self::$suffixes )
        ));

        $first = strtolower( $words[0] ?? '' );

        if ( isset( self::$directionals[$first] ) && isset( $words[1] ) ) {
            $dir    = self::$directionals[$first];
            $next   = strtoupper( substr( $words[1], 0, 2 ) );
            $prefix = $dir . $next;
        } else {
            $prefix = strtoupper( substr( $words[0] ?? 'UNK', 0, 3 ) );
        }

        return $prefix . $house;
    }

    public static function check_collisions(): array {
        $homes      = get_posts([ 'post_type' => 'cmm_home', 'posts_per_page' => -1 ]);
        $code_map   = [];
        $collisions = [];

        foreach ( $homes as $home ) {
            $code = get_field( 'address_code', $home->ID );
            if ( ! $code ) continue;

            if ( isset( $code_map[$code] ) ) {
                if ( ! isset( $collisions[$code] ) ) {
                    $collisions[$code] = [ $code_map[$code] ];
                }
                $collisions[$code][] = $home->ID;
            } else {
                $code_map[$code] = $home->ID;
            }
        }

        update_option( 'cmm_code_collisions', $collisions );
        return $collisions;
    }

    public static function collision_notice() {
        $collisions = get_option( 'cmm_code_collisions', [] );
        if ( empty( $collisions ) ) return;

        $count        = count( $collisions );
        $settings_url = admin_url( 'admin.php?page=cmm-address-codes' );
        $codes        = implode( ', ', array_keys( $collisions ) );

        echo '<div class="notice notice-error"><p>';
        echo "<strong>Community Membership:</strong> {$count} address code collision(s) detected: ";
        echo "<strong>{$codes}</strong>. ";
        echo "<a href='{$settings_url}'>Resolve in Address Code Settings &rarr;</a>";
        echo '</p></div>';
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Address Codes',
            'Address Codes',
            'manage_options',
            'cmm-address-codes',
            [ __CLASS__, 'render_settings' ]
        );
    }

    public static function render_settings() {
        $homes      = get_posts([ 'post_type' => 'cmm_home', 'posts_per_page' => -1 ]);
        $collisions = get_option( 'cmm_code_collisions', [] );
        $all_codes  = [];
        foreach ( $homes as $h ) {
            $all_codes[$h->ID] = get_field( 'address_code', $h->ID );
        }
        ?>
        <div class="wrap">
            <h1>Address Code Manager</h1>
            <?php if ( $collisions ): ?>
            <div class="notice notice-error inline">
                <p><strong><?php echo count($collisions); ?> collision(s):</strong>
                <?php echo implode(', ', array_keys($collisions)); ?>.
                Override the conflicting codes below.</p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('cmm_save_codes'); ?>
                <input type="hidden" name="action" value="cmm_save_codes">

                <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th>Current Code</th>
                            <th>Override</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $homes as $home ):
                        $code       = $all_codes[$home->ID] ?? '';
                        $is_collision = false;
                        foreach ( $collisions as $c_code => $ids ) {
                            if ( in_array( $home->ID, $ids ) ) {
                                $is_collision = true;
                                break;
                            }
                        }
                    ?>
                    <tr <?php if ($is_collision) echo 'style="background:#fff3f3;"'; ?>>
                        <td><?php echo esc_html( $home->post_title ); ?></td>
                        <td><code><?php echo esc_html( $code ); ?></code></td>
                        <td>
                            <input type="text" name="codes[<?php echo $home->ID; ?>]"
                                   value="<?php echo esc_attr( $code ); ?>"
                                   style="width:120px;font-family:monospace;"
                                   <?php if (!$is_collision) echo 'style="background:#f9f9f9"'; ?>>
                        </td>
                        <td>
                            <?php if ($is_collision): ?>
                                <span style="color:#d63638;">&#9888; Collision</span>
                            <?php else: ?>
                                <span style="color:#00a32a;">&#10003; OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save All Codes">
                </p>
            </form>
        </div>
        <?php
    }

    public static function save_overrides() {
        check_admin_referer('cmm_save_codes');
        $codes = $_POST['codes'] ?? [];
        foreach ( $codes as $post_id => $code ) {
            update_field( 'address_code', sanitize_text_field($code), (int)$post_id );
        }
        self::check_collisions();
        wp_redirect( admin_url('admin.php?page=cmm-address-codes&saved=1') );
        exit;
    }
}
```

---

## Step 5 — Onboarding Wizard + Settings

**File:** `includes/class-onboarding.php`

Runs automatically after first activation. After completion, the page becomes the
**Community Dashboard** — the settings home base.

### Settings Fields

```
Community Name:       [West Point Island Civic Association    ]
Short Slug:           [wpica    ]  lowercase, no spaces, used in URLs
Default Annual Dues:  [$ 150    ]
Admin Email:          [admin@wpica.org                        ]

── Dues Reset ─────────────────────────────────────────────────
Reset Month:          [ January ▾ ]
Reset Day:            [ 1       ▾ ]

                      Next Expiration Date:  January 1, 2027
                      (calculated live — updates on save)
────────────────────────────────────────────────────────────────
```

### Next Expiration Date Logic

```php
public static function get_next_expiration(): string {
    $month = (int) get_option( 'cmm_dues_reset_month', 1 );
    $day   = (int) get_option( 'cmm_dues_reset_day',   1 );

    $this_year = mktime( 0, 0, 0, $month, $day, (int) date('Y') );
    $next_year = mktime( 0, 0, 0, $month, $day, (int) date('Y') + 1 );

    return date( 'F j, Y', $this_year > time() ? $this_year : $next_year );
}
```

Display in settings page:
```php
echo 'Next Expiration Date: <strong>' . CMM_Onboarding::get_next_expiration() . '</strong>';
```

---

## Step 6 — Dues Reset Cron

**File:** `includes/class-dues.php`

### Behavior

- WordPress cron fires `cmm_dues_reset_event` daily
- On the configured month + day, all `active` homes flip to `expired`
- All linked users lose `home_admin` / `home_member` roles (SureMembers gate fires automatically)
- A persistent admin notice is stored and shown on next login
- Notice is per-user dismissible

### Status Reference

| Status | Meaning |
|---|---|
| `active` | Dues paid, full access |
| `inactive` | Home exists, never joined |
| `expired` | Was active, lapsed on reset date |
| `approved_pending_payment` | Admin approved, awaiting dues |
| `pending_review` | Application submitted, awaiting admin |
| `rejected` | Application denied |

```php
<?php
class CMM_Dues {

    public static function init() {
        add_action( 'cmm_dues_reset_event', [ __CLASS__, 'run_reset' ] );
        add_action( 'admin_notices',        [ __CLASS__, 'reset_notice' ] );
        add_action( 'admin_init',           [ __CLASS__, 'dismiss_notice' ] );
    }

    public static function run_reset() {
        $month = (int) get_option( 'cmm_dues_reset_month', 1 );
        $day   = (int) get_option( 'cmm_dues_reset_day',   1 );

        if ( (int) date('n') !== $month || (int) date('j') !== $day ) return;

        $active_homes = get_posts([
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'   => 'membership_status',
                'value' => 'active',
            ]],
        ]);

        $count = 0;
        foreach ( $active_homes as $home ) {
            update_field( 'membership_status', 'expired', $home->ID );

            $linked = get_field( 'linked_users', $home->ID ) ?: [];
            foreach ( $linked as $uid ) {
                $user = new WP_User( $uid );
                $user->remove_role( 'home_admin' );
                $user->remove_role( 'home_member' );
            }
            $count++;
        }

        update_option( 'cmm_dues_reset_notice', [
            'date'  => date('F j, Y'),
            'count' => $count,
        ]);
    }

    public static function reset_notice() {
        $notice = get_option( 'cmm_dues_reset_notice' );
        if ( ! $notice ) return;

        $key = 'cmm_reset_dismissed_' . sanitize_key( $notice['date'] );
        if ( get_user_meta( get_current_user_id(), $key, true ) ) return;

        $dismiss_url = add_query_arg( 'cmm_dismiss_reset', '1' );
        $reports_url = admin_url( 'admin.php?page=cmm-reports' );

        echo '<div class="notice notice-warning is-dismissible">';
        echo "<p><strong>Community Membership:</strong> Annual dues reset ran on {$notice['date']}. ";
        echo "{$notice['count']} home(s) set to <strong>Expired</strong>. ";
        echo "Members must renew to regain access. ";
        echo "<a href='{$reports_url}'>View Expired Homes &rarr;</a></p>";
        echo '</div>';
    }

    public static function dismiss_notice() {
        if ( ! isset( $_GET['cmm_dismiss_reset'] ) ) return;
        $notice = get_option( 'cmm_dues_reset_notice' );
        if ( ! $notice ) return;

        $key = 'cmm_reset_dismissed_' . sanitize_key( $notice['date'] );
        update_user_meta( get_current_user_id(), $key, true );
        wp_redirect( remove_query_arg('cmm_dismiss_reset') );
        exit;
    }
}
```

---

## Step 7 — Gated Signup and Application Flow

**File:** `includes/class-applications.php`

### Full Flow

```
1.  Visitor arrives at /join or /register
2.  SureForms registration form loads
3.  Visitor types address → AJAX typeahead returns matching cmm_home entries
    (only inactive and expired homes appear — active/pending homes are hidden)
4.  Visitor selects address → address code auto-populates (read-only display)
5.  Visitor fills name, email, password, submits
6.  WordPress account created with role: pending_applicant
7.  Home status set to: pending_review
8.  Admin receives email notification
9.  Admin reviews in Community → Applications
        Approve → home status: approved_pending_payment
                  applicant receives email with dues payment link
        Reject  → home status: rejected
                  applicant receives polite decline email
10. Applicant pays dues via SureForms payment form
11. On payment confirmed:
        Home status → active
        User role   → home_admin
        SureMembers access unlocked automatically via role
```

### REST Endpoint — Address Typeahead

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'cmm/v1', '/addresses', [
        'methods'             => 'GET',
        'callback'            => 'cmm_address_search',
        'permission_callback' => '__return_true',
    ]);
});

function cmm_address_search( WP_REST_Request $request ): array {
    $search = sanitize_text_field( $request->get_param('q') );
    if ( strlen($search) < 2 ) return [];

    $homes = get_posts([
        'post_type'      => 'cmm_home',
        'posts_per_page' => 10,
        's'              => $search,
        'meta_query'     => [[
            'key'     => 'membership_status',
            'value'   => [ 'inactive', 'expired' ],
            'compare' => 'IN',
        ]],
    ]);

    return array_map( fn($h) => [
        'id'           => $h->ID,
        'address'      => $h->post_title,
        'address_code' => get_field( 'address_code', $h->ID ),
    ], $homes );
}
```

### Typeahead JavaScript

**File:** `assets/js/cmm-address-typeahead.js`

```javascript
document.addEventListener('DOMContentLoaded', function () {
    const input      = document.getElementById('cmm-address-input');
    const codeBox    = document.getElementById('cmm-address-code-display');
    const hiddenId   = document.getElementById('cmm-home-id');
    const dropdown   = document.getElementById('cmm-address-dropdown');
    if (!input) return;

    let timer;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }

        timer = setTimeout(() => {
            fetch(`/wp-json/cmm/v1/addresses?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(results => {
                    dropdown.innerHTML = '';
                    if (!results.length) {
                        dropdown.innerHTML = '<div class="cmm-no-results">No matching addresses found</div>';
                    } else {
                        results.forEach(item => {
                            const opt = document.createElement('div');
                            opt.className = 'cmm-dropdown-option';
                            opt.textContent = item.address;
                            opt.addEventListener('click', () => {
                                input.value           = item.address;
                                hiddenId.value        = item.id;
                                codeBox.textContent   = 'Address Code: ' + item.address_code;
                                codeBox.style.display = 'block';
                                dropdown.style.display = 'none';
                            });
                            dropdown.appendChild(opt);
                        });
                    }
                    dropdown.style.display = 'block';
                });
        }, 250);
    });

    document.addEventListener('click', e => {
        if (!input.contains(e.target)) dropdown.style.display = 'none';
    });
});
```

### SureForms Registration Form

Build the form in SureForms. Replace the address field with a Custom HTML block:

```html
<div class="cmm-address-field" style="position:relative;">
    <label for="cmm-address-input">Your Property Address</label>
    <input type="text" id="cmm-address-input" name="cmm_address_text"
           placeholder="Start typing your address..."
           autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <input type="hidden" id="cmm-home-id" name="cmm_home_id">

    <div id="cmm-address-dropdown"
         style="display:none;position:absolute;top:100%;left:0;right:0;
                background:#fff;border:1px solid #ccc;border-radius:0 0 4px 4px;
                max-height:200px;overflow-y:auto;z-index:999;">
    </div>

    <div id="cmm-address-code-display"
         style="display:none;margin-top:6px;padding:6px 10px;background:#f0f7ff;
                border:1px solid #b0d0f0;border-radius:4px;
                font-family:monospace;font-size:1.1em;color:#2271b1;">
    </div>
</div>
```

**Remaining SureForms fields (standard):**
- First Name (required)
- Last Name (required)
- Email Address (required)
- Password (required)
- Checkbox: "I am the property owner or authorized resident" (required)

### Application Admin Screen — Community → Applications

```
Pending Applications (3)
─────────────────────────────────────────────────────────────────────
Name          Email              Address           Code    Submitted
─────────────────────────────────────────────────────────────────────
John Smith    john@email.com     2 Bullard Dr      BUL2    Apr 10
Jane Doe      jane@email.com     15 Oak Avenue     OAK15   Apr 11
Bob Jones     bob@email.com      4 West Shore Rd   WSH4    Apr 12
─────────────────────────────────────────────────────────────────────
[ Approve ]  [ Reject ]   ← per row, with optional rejection reason

─────────────────────────────────────────────────────────────
Approved — Awaiting Payment (1)
─────────────────────────────────────────────────────────────
John Smith    john@email.com     2 Bullard Dr      BUL2    Approved Apr 10
─────────────────────────────────────────────────────────────
[ Resend Payment Email ]
```

The "Approved — Awaiting Payment" section exists specifically to catch applicants who
fell off after approval without completing payment. One-click resend keeps the process
moving without admin overhead.

---

## Step 8 — Bulk Address Importer

**File:** `includes/class-importer.php`

Paste a list of addresses, one per line. Each becomes a Home post with:
- Status: `inactive`
- Address code: auto-generated
- Linked users: empty (filled as residents register)

After import, collisions are detected automatically and surfaced in the notice.

```
Import complete.
142 homes created.  3 skipped (already exist).
⚠ 2 address code collision(s) detected.  Resolve now →
```

---

## Step 9 — Custom Roles

**File:** `includes/class-roles.php`

| Role | Capabilities | Assigned When |
|---|---|---|
| `home_admin` | Manage home, view dues, invite/remove users | Home goes `active` (primary contact) |
| `home_member` | Read, view gated content | Home goes `active` (additional users) |
| `pending_applicant` | Read only, no gated content | Application submitted |

```php
<?php
class CMM_Roles {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_roles' ] );
    }

    public static function register_roles() {
        add_role( 'home_admin', 'Home Admin', [
            'read'            => true,
            'cmm_manage_home' => true,
            'cmm_view_dues'   => true,
        ]);

        add_role( 'home_member', 'Home Member', [
            'read'             => true,
            'cmm_view_content' => true,
        ]);

        add_role( 'pending_applicant', 'Pending Applicant', [
            'read' => true,
        ]);

        $admin = get_role('administrator');
        if ( $admin ) {
            foreach (['cmm_manage_home','cmm_view_dues','cmm_view_reports'] as $cap) {
                $admin->add_cap($cap);
            }
        }
    }
}
```

### SureMembers Integration

1. Create an Access Group: **"Active Members"**
2. Restrict all member-only pages/content to this group
3. Allow roles: `home_admin`, `home_member`
4. The CMM plugin assigns/removes roles — SureMembers enforces the gate automatically

### Role Sync Hook

Add to `class-applications.php` or a dedicated sync file:

```php
add_action( 'acf/save_post', function( $post_id ) {
    if ( get_post_type($post_id) !== 'cmm_home' ) return;

    $status     = get_field( 'membership_status', $post_id );
    $primary    = get_field( 'primary_contact',   $post_id );
    $linked     = get_field( 'linked_users',      $post_id ) ?: [];

    foreach ( $linked as $uid ) {
        $user = new WP_User($uid);
        $user->remove_role('home_admin');
        $user->remove_role('home_member');
        $user->remove_role('pending_applicant');

        switch ($status) {
            case 'active':
                $user->add_role( $uid == $primary ? 'home_admin' : 'home_member' );
                break;
            case 'approved_pending_payment':
            case 'pending_review':
                $user->add_role('pending_applicant');
                break;
        }
    }
}, 20 );
```

---

## Step 10 — Reports Dashboard

**File:** `includes/class-reports.php`

Located at **Community → Reports**.

### Summary Cards

```
┌──────────────┐ ┌───────────────┐ ┌──────────┐ ┌─────────┐ ┌────────────────┐
│ Total Homes  │ │ Active Members│ │ Inactive │ │ Expired │ │ Total Dues YTD │
│     142      │ │      87       │ │    44    │ │   11    │ │   $13,050.00   │
└──────────────┘ └───────────────┘ └──────────┘ └─────────┘ └────────────────┘
```

### CSV Exports

- **Active Members** — Address, code, primary contact name + email, dues date, amount
- **All Homes** — Same columns, all statuses included
- **Dues Report** — Address, code, amount paid, date paid, year

### Table Filters

- Filter by status (dropdown)
- Filter by dues year
- Search by address or code

---

## Step 11 — Frontend My Home Dashboard

Shortcode: `[cmm_my_home]` — place on any WordPress page.

### View by Role

**Home Admin sees:**
- Address + membership status badge
- Dues paid date
- Full member list with Remove buttons
- Invite form (name + email → sends token link, valid 7 days)
- Renew dues link (if status is `expired`)

**Home Member sees:**
- Address + status badge
- Member list (read-only)

**Pending Applicant sees:**
- "Your application is under review" message + admin contact email

**Logged out / no home linked:**
- Redirect to login or registration page

### Invite Token Flow

```
1. Home admin enters name + email → clicks Send Invite
2. Plugin generates a 32-char token, stored as a transient (7-day expiry)
   tied to home_id + invited email
3. Invited person receives email with registration link containing token
4. They register (or log in if they have an account)
5. Token is consumed → user assigned to home, role set to home_member
6. Token deleted
```

---

## Reusing for Multiple Communities

All settings use the `cmm_` prefix in `wp_options`. Each WordPress install or
Multisite sub-site gets its own fully isolated configuration, address list, and user base.

**Deploying for a new community (e.g. OBYC):**

1. Install plugin on new site (or new Multisite sub-site)
2. Activate → onboarding wizard runs automatically
3. Enter community name, slug, dues amount, reset date
4. Paste address list → all homes created, codes generated
5. Resolve any code collisions in Address Code settings
6. Configure SureMembers access group
7. Drop `[cmm_my_home]` shortcode on the member dashboard page
8. Launch registration page with SureForms form + typeahead

**Separate installs vs. Multisite:**
- Use **separate installs** if each community manages its own site independently
- Use **Multisite** if you manage all communities yourself from one WordPress backend

---

## Development Checklist

**Phase 1 — Core Foundation**
- [ ] Main plugin file and class loader
- [ ] Register Home CPT with Community menu parent
- [ ] Register ACF fields programmatically
- [ ] Register custom roles (home_admin, home_member, pending_applicant)

**Phase 2 — Settings and Admin**
- [ ] Onboarding wizard (community name, slug, dues, reset date)
- [ ] Next expiration date calculator and display
- [ ] Community Dashboard settings page (post-onboarding)
- [ ] Address code generation class
- [ ] Address code settings screen with collision alerts and overrides

**Phase 3 — Dues**
- [ ] Daily cron job registration
- [ ] Auto-flip active → expired on reset date
- [ ] Demote user roles on expiry
- [ ] Persistent admin notice with per-user dismiss

**Phase 4 — Address Importer**
- [ ] Paste textarea importer UI
- [ ] Bulk post creation with ACF defaults
- [ ] Auto-generate codes on import
- [ ] Post-import collision check and notice

**Phase 5 — Gated Signup**
- [ ] REST endpoint for address typeahead (inactive/expired only)
- [ ] Typeahead JavaScript with debounce
- [ ] SureForms Custom HTML block (address field + code display)
- [ ] Application submission handler
- [ ] Application admin screen (pending, approved, rejected sections)
- [ ] Approve action (status update + payment email)
- [ ] Reject action (status update + decline email with optional reason)
- [ ] Resend payment email button
- [ ] Role sync hook on ACF status save

**Phase 6 — Reports and Frontend**
- [ ] Reports dashboard with summary cards
- [ ] CSV exports (active members, all homes, dues)
- [ ] Status and year filters, address/code search
- [ ] Frontend My Home shortcode
- [ ] Invite token generation, email, and acceptance
- [ ] Remove user from home (AJAX)

**Phase 7 — Testing and Polish**
- [ ] Full flow: import → apply → approve → pay → access granted
- [ ] Manually trigger dues reset cron to verify expiry + notice
- [ ] Collision detection and resolution
- [ ] All three CSV exports
- [ ] Deploy fresh on a second community (OBYC or Seacrest)
- [ ] Verify SureMembers gating on role change

---

## Estimated Build Time

| Phase | Description | Hours |
|---|---|---|
| 1 | CPT, ACF, roles | 2 |
| 2 | Settings, onboarding, address codes | 4–5 |
| 3 | Dues cron and notices | 2 |
| 4 | Address importer | 1–2 |
| 5 | Gated signup and application flow | 5–7 |
| 6 | Reports and frontend dashboard | 4–5 |
| 7 | Testing and polish | 3 |
| | **Total** | **~21–26 hours** |

A mid-level WordPress developer with this specification in hand would price this
at **$2,500–$4,500** depending on their rate and how much testing they include.
