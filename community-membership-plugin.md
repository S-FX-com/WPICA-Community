# Community Membership Manager — WordPress Plugin

## Overview

This document outlines the full specification and code for the **Community Membership Manager** plugin. It is designed to be reusable across multiple communities (West Point Island Civic Association, OBYC, Seacrest, etc.) and handles home-centric membership — where the unit of membership is a **home**, not an individual user.

Each community instance gets:
- A Custom Post Type (CPT) for Homes
- ACF field group auto-registration
- An onboarding wizard to configure the community
- An address importer (paste-and-create)
- A reporting dashboard
- A frontend "My Home" management dashboard for members

---

## Plugin File Structure

```
community-membership-manager/
├── community-membership-manager.php   ← Main plugin file
├── includes/
│   ├── class-cpt.php                  ← Registers the Home CPT
│   ├── class-acf-fields.php           ← Registers ACF field group programmatically
│   ├── class-onboarding.php           ← Onboarding wizard (admin)
│   ├── class-importer.php             ← Address paste importer
│   ├── class-roles.php                ← Custom roles & capabilities
│   ├── class-reports.php              ← Reports dashboard
│   └── class-frontend.php             ← Frontend "My Home" dashboard
├── templates/
│   ├── dashboard-my-home.php          ← Frontend member dashboard template
│   └── reports-page.php               ← Admin reports template
├── assets/
│   ├── css/
│   │   └── cmm-admin.css
│   └── js/
│       └── cmm-admin.js
└── readme.txt
```

---

## Step 1 — Main Plugin File

**File:** `community-membership-manager.php`

This is the entry point. It loads all classes and defines global constants.

```php
<?php
/**
 * Plugin Name: Community Membership Manager
 * Description: Home-centric membership management for civic associations and communities.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: cmm
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CMM_VERSION',  '1.0.0' );
define( 'CMM_PATH',     plugin_dir_path( __FILE__ ) );
define( 'CMM_URL',      plugin_dir_url( __FILE__ ) );

// Autoload classes
foreach ( [
    'class-cpt',
    'class-acf-fields',
    'class-onboarding',
    'class-importer',
    'class-roles',
    'class-reports',
    'class-frontend',
] as $class ) {
    require_once CMM_PATH . 'includes/' . $class . '.php';
}

// Boot
add_action( 'plugins_loaded', function() {
    CMM_CPT::init();
    CMM_ACF_Fields::init();
    CMM_Onboarding::init();
    CMM_Importer::init();
    CMM_Roles::init();
    CMM_Reports::init();
    CMM_Frontend::init();
});

// On activation: set onboarding flag
register_activation_hook( __FILE__, function() {
    add_option( 'cmm_onboarding_complete', false );
    add_option( 'cmm_community_name', '' );
    add_option( 'cmm_dues_amount', 0 );
    add_option( 'cmm_dues_cycle', 'annual' );
});
```

---

## Step 2 — Register the Home CPT

**File:** `includes/class-cpt.php`

```php
<?php
class CMM_CPT {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        $community = get_option( 'cmm_community_name', 'Community' );

        register_post_type( 'cmm_home', [
            'label'               => $community . ' Homes',
            'labels'              => [
                'name'          => $community . ' Homes',
                'singular_name' => 'Home',
                'add_new_item'  => 'Add New Home',
                'edit_item'     => 'Edit Home',
                'search_items'  => 'Search Homes',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'community-membership',
            'supports'            => [ 'title' ],  // Title = Address
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'show_in_rest'        => false,
        ]);
    }
}
```

> **Note:** The post `title` field is the home address. No separate address field needed.

---

## Step 3 — ACF Field Group (Programmatic Registration)

**File:** `includes/class-acf-fields.php`

This registers the ACF field group in code so it travels with the plugin — no importing/exporting JSON needed.

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
                'value'    => 'cmm_home' 
            ]]],
            'fields'   => [

                // Membership Status
                [
                    'key'           => 'field_cmm_membership_status',
                    'label'         => 'Membership Status',
                    'name'          => 'membership_status',
                    'type'          => 'radio',
                    'choices'       => [
                        'active'   => 'Active',
                        'inactive' => 'Inactive',
                        'expired'  => 'Expired',
                    ],
                    'default_value' => 'inactive',
                    'layout'        => 'horizontal',
                ],

                // Dues Paid Date
                [
                    'key'           => 'field_cmm_dues_paid_date',
                    'label'         => 'Dues Paid Date',
                    'name'          => 'dues_paid_date',
                    'type'          => 'date_picker',
                    'display_format'=> 'F j, Y',
                    'return_format' => 'Y-m-d',
                ],

                // Dues Amount Paid
                [
                    'key'           => 'field_cmm_dues_amount_paid',
                    'label'         => 'Dues Amount Paid',
                    'name'          => 'dues_amount_paid',
                    'type'          => 'number',
                    'prepend'       => '$',
                ],

                // Primary Contact (Home Admin)
                [
                    'key'           => 'field_cmm_primary_contact',
                    'label'         => 'Primary Contact',
                    'name'          => 'primary_contact',
                    'type'          => 'user',
                    'multiple'      => 0,
                    'return_format' => 'id',
                ],

                // Linked Users (all household members)
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

## Step 4 — Onboarding Wizard

**File:** `includes/class-onboarding.php`

The onboarding wizard runs once after activation and collects:
- Community name
- Default dues amount
- Dues cycle (annual/seasonal)
- Admin email for notifications

```php
<?php
class CMM_Onboarding {

    public static function init() {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',    [ __CLASS__, 'redirect_to_onboarding' ] );
        add_action( 'admin_post_cmm_save_onboarding', [ __CLASS__, 'save' ] );
    }

    public static function redirect_to_onboarding() {
        if ( ! get_option( 'cmm_onboarding_complete' ) && ! isset( $_GET['page'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=cmm-onboarding' ) );
            exit;
        }
    }

    public static function register_menu() {
        // Main menu
        add_menu_page(
            'Community Membership',
            'Community Membership',
            'manage_options',
            'community-membership',
            [ __CLASS__, 'render_settings' ],
            'dashicons-groups',
            30
        );

        // Onboarding submenu (hidden after completion)
        if ( ! get_option( 'cmm_onboarding_complete' ) ) {
            add_submenu_page(
                'community-membership',
                'Setup Wizard',
                'Setup Wizard',
                'manage_options',
                'cmm-onboarding',
                [ __CLASS__, 'render_wizard' ]
            );
        }
    }

    public static function render_wizard() {
        ?>
        <div class="wrap">
            <h1>🏘 Community Membership Manager — Setup</h1>
            <p>Let's configure your community. This only runs once.</p>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('cmm_onboarding'); ?>
                <input type="hidden" name="action" value="cmm_save_onboarding">

                <table class="form-table">
                    <tr>
                        <th>Community Name</th>
                        <td><input type="text" name="community_name" class="regular-text" 
                             placeholder="e.g. West Point Island Civic Association" required></td>
                    </tr>
                    <tr>
                        <th>Short Name / Slug</th>
                        <td><input type="text" name="community_slug" class="regular-text" 
                             placeholder="e.g. wpica" required>
                        <p class="description">Used in URLs and IDs. Lowercase, no spaces.</p></td>
                    </tr>
                    <tr>
                        <th>Default Annual Dues ($)</th>
                        <td><input type="number" name="dues_amount" class="small-text" value="0"></td>
                    </tr>
                    <tr>
                        <th>Dues Cycle</th>
                        <td>
                            <select name="dues_cycle">
                                <option value="annual">Annual</option>
                                <option value="seasonal">Seasonal</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Admin Notification Email</th>
                        <td><input type="email" name="admin_email" class="regular-text" 
                             value="<?php echo get_option('admin_email'); ?>"></td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary button-large" 
                           value="Save & Continue →">
                </p>
            </form>
        </div>
        <?php
    }

    public static function save() {
        check_admin_referer( 'cmm_onboarding' );

        update_option( 'cmm_community_name',  sanitize_text_field( $_POST['community_name'] ) );
        update_option( 'cmm_community_slug',  sanitize_key( $_POST['community_slug'] ) );
        update_option( 'cmm_dues_amount',     absint( $_POST['dues_amount'] ) );
        update_option( 'cmm_dues_cycle',      sanitize_key( $_POST['dues_cycle'] ) );
        update_option( 'cmm_admin_email',     sanitize_email( $_POST['admin_email'] ) );
        update_option( 'cmm_onboarding_complete', true );

        wp_redirect( admin_url( 'admin.php?page=cmm-importer&onboarding=1' ) );
        exit;
    }

    public static function render_settings() {
        // Settings page after onboarding (to revisit config)
        echo '<div class="wrap"><h1>Community Membership — Settings</h1>';
        echo '<p>Community: <strong>' . get_option('cmm_community_name') . '</strong></p>';
        echo '</div>';
    }
}
```

> **Flow:** Plugin activates → wizard runs once → redirects to Address Importer automatically.

---

## Step 5 — Address Importer

**File:** `includes/class-importer.php`

Paste a list of addresses (one per line) and the plugin creates all Home posts in bulk, defaulting to `inactive`.

```php
<?php
class CMM_Importer {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_import_addresses', [ __CLASS__, 'process' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Address Importer',
            'Address Importer',
            'manage_options',
            'cmm-importer',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render() {
        $onboarding = isset( $_GET['onboarding'] );
        ?>
        <div class="wrap">
            <h1>🏠 Address Importer</h1>

            <?php if ( $onboarding ): ?>
            <div class="notice notice-success">
                <p><strong>Setup complete!</strong> Now paste your address list below to create all home entries.</p>
            </div>
            <?php endif; ?>

            <p>Paste one address per line. Each address will become a Home entry with status set to <strong>Inactive</strong>.</p>
            <p><em>Example:</em></p>
            <pre style="background:#f0f0f0;padding:10px;display:inline-block;">
123 Oak Street
456 Maple Avenue
789 Shore Road
            </pre>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('cmm_import'); ?>
                <input type="hidden" name="action" value="cmm_import_addresses">

                <p>
                    <textarea name="addresses" rows="20" cols="60" 
                              placeholder="Paste addresses here, one per line..."
                              style="font-family:monospace;"></textarea>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="skip_duplicates" value="1" checked>
                        Skip addresses that already exist
                    </label>
                </p>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Import Addresses">
                </p>
            </form>
        </div>
        <?php
    }

    public static function process() {
        check_admin_referer( 'cmm_import' );

        $raw           = sanitize_textarea_field( $_POST['addresses'] ?? '' );
        $skip_dupes    = ! empty( $_POST['skip_duplicates'] );
        $addresses     = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

        $created  = 0;
        $skipped  = 0;
        $existing = [];

        // Build list of existing home titles for dupe check
        if ( $skip_dupes ) {
            $existing_posts = get_posts([
                'post_type'      => 'cmm_home',
                'posts_per_page' => -1,
                'fields'         => 'all',
            ]);
            foreach ( $existing_posts as $p ) {
                $existing[] = strtolower( trim( $p->post_title ) );
            }
        }

        foreach ( $addresses as $address ) {
            if ( $skip_dupes && in_array( strtolower( $address ), $existing ) ) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post([
                'post_title'  => $address,
                'post_type'   => 'cmm_home',
                'post_status' => 'publish',
            ]);

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_field( 'membership_status', 'inactive', $post_id );
                $created++;
            }
        }

        // Store result in transient for display
        set_transient( 'cmm_import_result', [
            'created' => $created,
            'skipped' => $skipped,
        ], 60 );

        wp_redirect( admin_url( 'admin.php?page=cmm-importer&imported=1' ) );
        exit;
    }
}
```

> **After saving:** Add a check at the top of `render()` for the `imported=1` query param to display a success notice showing how many were created vs. skipped.

---

## Step 6 — Custom Roles

**File:** `includes/class-roles.php`

```php
<?php
class CMM_Roles {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_roles' ] );
    }

    public static function register_roles() {

        // Home Admin — can manage their own home's members, see dues status
        add_role( 'home_admin', 'Home Admin', [
            'read'              => true,
            'cmm_manage_home'   => true,  // Can invite/remove users from their home
            'cmm_view_dues'     => true,  // Can see their home's dues status
        ]);

        // Home Member — can log in and access member content, cannot manage
        add_role( 'home_member', 'Home Member', [
            'read'             => true,
            'cmm_view_content' => true,  // Access to member-gated content
        ]);

        // Optionally add CMM caps to existing admin role
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'cmm_manage_home' );
            $admin->add_cap( 'cmm_view_dues' );
            $admin->add_cap( 'cmm_view_reports' );
        }
    }
}
```

> **SureMembers Integration:** In SureMembers, create an access group called "Active Members" and restrict your member content to users with the `home_member` or `home_admin` role. The plugin sets these roles — SureMembers enforces the gate.

---

## Step 7 — Reports Dashboard

**File:** `includes/class-reports.php`

```php
<?php
class CMM_Reports {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Reports',
            'Reports',
            'manage_options',
            'cmm-reports',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render() {
        $stats = self::get_stats();
        ?>
        <div class="wrap">
            <h1>📊 Membership Reports — <?php echo esc_html( get_option('cmm_community_name') ); ?></h1>

            <!-- Summary Cards -->
            <div style="display:flex;gap:20px;margin:20px 0;flex-wrap:wrap;">
                <?php 
                $cards = [
                    [ 'label' => 'Total Homes',    'value' => $stats['total'],    'color' => '#2271b1' ],
                    [ 'label' => 'Active Members', 'value' => $stats['active'],   'color' => '#00a32a' ],
                    [ 'label' => 'Inactive',        'value' => $stats['inactive'], 'color' => '#72777c' ],
                    [ 'label' => 'Expired',         'value' => $stats['expired'],  'color' => '#d63638' ],
                    [ 'label' => 'Total Dues YTD',  'value' => '$' . number_format($stats['dues_ytd'], 2), 'color' => '#8c6e00' ],
                ];
                foreach ( $cards as $card ): ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 30px;
                            min-width:140px;text-align:center;border-top:4px solid <?php echo $card['color']; ?>;">
                    <div style="font-size:2em;font-weight:bold;color:<?php echo $card['color']; ?>;">
                        <?php echo esc_html( $card['value'] ); ?>
                    </div>
                    <div style="color:#666;margin-top:4px;"><?php echo esc_html( $card['label'] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Export Buttons -->
            <p>
                <a href="<?php echo admin_url('admin.php?page=cmm-reports&export=active'); ?>" 
                   class="button">⬇ Export Active Members (CSV)</a>
                &nbsp;
                <a href="<?php echo admin_url('admin.php?page=cmm-reports&export=all'); ?>" 
                   class="button">⬇ Export All Homes (CSV)</a>
                &nbsp;
                <a href="<?php echo admin_url('admin.php?page=cmm-reports&export=dues'); ?>" 
                   class="button">⬇ Export Dues Report (CSV)</a>
            </p>

            <!-- Full Table -->
            <h2>All Homes</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Primary Contact</th>
                        <th>Linked Users</th>
                        <th>Dues Paid Date</th>
                        <th>Dues Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $stats['homes'] as $home ): 
                    $status       = get_field( 'membership_status', $home->ID );
                    $dues_date    = get_field( 'dues_paid_date', $home->ID );
                    $dues_amount  = get_field( 'dues_amount_paid', $home->ID );
                    $primary_id   = get_field( 'primary_contact', $home->ID );
                    $linked       = get_field( 'linked_users', $home->ID ) ?: [];
                    $primary_name = $primary_id ? get_userdata( $primary_id )->display_name : '—';

                    $status_colors = [
                        'active'   => '#00a32a',
                        'inactive' => '#72777c',
                        'expired'  => '#d63638',
                    ];
                    $color = $status_colors[ $status ] ?? '#72777c';
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $home->post_title ); ?></strong></td>
                    <td><span style="color:<?php echo $color; ?>;font-weight:bold;">
                        <?php echo esc_html( ucfirst( $status ) ); ?>
                    </span></td>
                    <td><?php echo esc_html( $primary_name ); ?></td>
                    <td><?php echo count( $linked ); ?> user(s)</td>
                    <td><?php echo $dues_date ? date( 'M j, Y', strtotime($dues_date) ) : '—'; ?></td>
                    <td><?php echo $dues_amount ? '$' . number_format($dues_amount, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php

        // Handle CSV exports
        if ( isset( $_GET['export'] ) ) {
            self::export_csv( $_GET['export'] );
        }
    }

    private static function get_stats(): array {
        $all_homes = get_posts([
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
        ]);

        $stats = [
            'total'    => count( $all_homes ),
            'active'   => 0,
            'inactive' => 0,
            'expired'  => 0,
            'dues_ytd' => 0,
            'homes'    => $all_homes,
        ];

        $current_year = date('Y');

        foreach ( $all_homes as $home ) {
            $status = get_field( 'membership_status', $home->ID );
            if ( isset( $stats[ $status ] ) ) $stats[ $status ]++;

            // Sum dues paid this calendar year
            $dues_date   = get_field( 'dues_paid_date', $home->ID );
            $dues_amount = get_field( 'dues_amount_paid', $home->ID );
            if ( $dues_date && $dues_amount && date('Y', strtotime($dues_date)) == $current_year ) {
                $stats['dues_ytd'] += floatval( $dues_amount );
            }
        }

        return $stats;
    }

    private static function export_csv( string $type ) {
        $all_homes = get_posts([
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
        ]);

        $rows    = [];
        $rows[]  = [ 'Address', 'Status', 'Primary Contact', 'Primary Email', 'Linked Users', 'Dues Paid Date', 'Dues Amount' ];

        foreach ( $all_homes as $home ) {
            $status  = get_field( 'membership_status', $home->ID );
            if ( $type === 'active' && $status !== 'active' ) continue;

            $primary_id    = get_field( 'primary_contact', $home->ID );
            $primary_user  = $primary_id ? get_userdata( $primary_id ) : null;
            $linked        = get_field( 'linked_users', $home->ID ) ?: [];
            $dues_date     = get_field( 'dues_paid_date', $home->ID );
            $dues_amount   = get_field( 'dues_amount_paid', $home->ID );

            $rows[] = [
                $home->post_title,
                ucfirst( $status ),
                $primary_user ? $primary_user->display_name : '',
                $primary_user ? $primary_user->user_email : '',
                count( $linked ),
                $dues_date ?: '',
                $dues_amount ? '$' . number_format($dues_amount, 2) : '',
            ];
        }

        $filename = 'cmm-' . $type . '-' . date('Y-m-d') . '.csv';
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $fp = fopen( 'php://output', 'w' );
        foreach ( $rows as $row ) fputcsv( $fp, $row );
        fclose( $fp );
        exit;
    }
}
```

---

## Step 8 — Frontend "My Home" Dashboard

**File:** `includes/class-frontend.php`

Register a shortcode `[cmm_my_home]` that members see when logged in. Drop it on any page.

```php
<?php
class CMM_Frontend {

    public static function init() {
        add_shortcode( 'cmm_my_home', [ __CLASS__, 'render_dashboard' ] );
        add_action( 'wp_ajax_cmm_remove_user',  [ __CLASS__, 'ajax_remove_user' ] );
        add_action( 'wp_ajax_cmm_invite_user',  [ __CLASS__, 'ajax_invite_user' ] );
    }

    public static function render_dashboard(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view your home details.</p>';
        }

        $user_id  = get_current_user_id();
        $home_id  = get_user_meta( $user_id, 'cmm_home_id', true );

        if ( ! $home_id ) {
            return '<p>Your account is not linked to a home. Please contact the association administrator.</p>';
        }

        $home          = get_post( $home_id );
        $status        = get_field( 'membership_status', $home_id );
        $dues_date     = get_field( 'dues_paid_date', $home_id );
        $primary_id    = get_field( 'primary_contact', $home_id );
        $linked_users  = get_field( 'linked_users', $home_id ) ?: [];
        $is_admin      = ( $primary_id == $user_id ) || current_user_can( 'administrator' );

        $status_badge = [
            'active'   => '<span style="color:#00a32a;font-weight:bold;">✅ Active</span>',
            'inactive' => '<span style="color:#72777c;font-weight:bold;">⬜ Inactive</span>',
            'expired'  => '<span style="color:#d63638;font-weight:bold;">❌ Expired</span>',
        ][ $status ] ?? '';

        ob_start();
        include CMM_PATH . 'templates/dashboard-my-home.php';
        return ob_get_clean();
    }

    public static function ajax_remove_user() {
        check_ajax_referer( 'cmm_frontend', 'nonce' );

        $user_id      = get_current_user_id();
        $remove_id    = intval( $_POST['remove_user_id'] );
        $home_id      = get_user_meta( $user_id, 'cmm_home_id', true );
        $primary_id   = get_field( 'primary_contact', $home_id );

        // Only home admin or site admin can remove users
        if ( $primary_id != $user_id && ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Remove from linked_users ACF field
        $linked = get_field( 'linked_users', $home_id ) ?: [];
        $linked = array_diff( $linked, [ $remove_id ] );
        update_field( 'linked_users', array_values( $linked ), $home_id );

        // Clear home ID from user meta
        delete_user_meta( $remove_id, 'cmm_home_id' );

        wp_send_json_success( 'User removed' );
    }

    public static function ajax_invite_user() {
        check_ajax_referer( 'cmm_frontend', 'nonce' );

        $user_id    = get_current_user_id();
        $home_id    = get_user_meta( $user_id, 'cmm_home_id', true );
        $email      = sanitize_email( $_POST['invite_email'] );
        $first_name = sanitize_text_field( $_POST['invite_first'] );
        $last_name  = sanitize_text_field( $_POST['invite_last'] );

        // Generate token, store it
        $token = wp_generate_password( 32, false );
        set_transient( 'cmm_invite_' . $token, [
            'home_id'    => $home_id,
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ], DAY_IN_SECONDS * 7 );  // Token valid 7 days

        $invite_url = add_query_arg( 'cmm_invite', $token, wp_registration_url() );
        $home_title = get_the_title( $home_id );
        $inviter    = get_userdata( $user_id )->display_name;
        $site_name  = get_bloginfo( 'name' );

        wp_mail(
            $email,
            "You've been added to {$home_title} on {$site_name}",
            "{$inviter} has added you to {$home_title} on the {$site_name} website.\n\n" .
            "Click the link below to create your account and gain access:\n\n{$invite_url}\n\n" .
            "This link expires in 7 days."
        );

        wp_send_json_success( 'Invitation sent to ' . $email );
    }
}
```

---

## Step 9 — Frontend Dashboard Template

**File:** `templates/dashboard-my-home.php`

```php
<div class="cmm-dashboard" style="font-family:sans-serif;max-width:700px;">

    <h2 style="border-bottom:2px solid #ddd;padding-bottom:10px;">
        🏠 <?php echo esc_html( $home->post_title ); ?>
    </h2>

    <table style="width:100%;margin-bottom:20px;">
        <tr>
            <td style="padding:8px 0;color:#666;width:160px;">Membership Status</td>
            <td><?php echo $status_badge; ?></td>
        </tr>
        <tr>
            <td style="padding:8px 0;color:#666;">Dues Paid</td>
            <td><?php echo $dues_date ? date( 'F j, Y', strtotime($dues_date) ) : 'Not on record'; ?></td>
        </tr>
    </table>

    <h3>Members at This Home</h3>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="background:#f0f0f0;">
                <th style="text-align:left;padding:8px;">Name</th>
                <th style="text-align:left;padding:8px;">Email</th>
                <th style="text-align:left;padding:8px;">Role</th>
                <?php if ( $is_admin ): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $linked_users as $uid ):
            $u = get_userdata( $uid );
            if ( ! $u ) continue;
        ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:8px;"><?php echo esc_html( $u->display_name ); ?></td>
                <td style="padding:8px;"><?php echo esc_html( $u->user_email ); ?></td>
                <td style="padding:8px;">
                    <?php echo $uid == $primary_id ? '⭐ Home Admin' : 'Member'; ?>
                </td>
                <?php if ( $is_admin && $uid != $primary_id ): ?>
                <td style="padding:8px;">
                    <button class="cmm-remove-user" data-uid="<?php echo $uid; ?>" 
                            style="color:#d63638;background:none;border:1px solid #d63638;
                                   cursor:pointer;padding:2px 8px;border-radius:3px;">
                        Remove
                    </button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $is_admin ): ?>
    <div style="margin-top:20px;padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
        <h4 style="margin-top:0;">+ Invite Someone to This Home</h4>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="text"  id="cmm-invite-first" placeholder="First Name" style="padding:6px;border:1px solid #ccc;border-radius:3px;">
            <input type="text"  id="cmm-invite-last"  placeholder="Last Name"  style="padding:6px;border:1px solid #ccc;border-radius:3px;">
            <input type="email" id="cmm-invite-email" placeholder="Email"      style="padding:6px;border:1px solid #ccc;border-radius:3px;min-width:220px;">
            <button id="cmm-invite-btn" style="background:#2271b1;color:#fff;border:none;
                    padding:6px 14px;cursor:pointer;border-radius:3px;">Send Invite</button>
        </div>
        <p id="cmm-invite-msg" style="margin-top:8px;color:#00a32a;display:none;"></p>
    </div>
    <?php endif; ?>

</div>

<script>
const cmmNonce = '<?php echo wp_create_nonce("cmm_frontend"); ?>';
const cmmAjax  = '<?php echo admin_url("admin-ajax.php"); ?>';

document.querySelectorAll('.cmm-remove-user').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Remove this person from your home?')) return;
        fetch(cmmAjax, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'cmm_remove_user',
                nonce: cmmNonce,
                remove_user_id: this.dataset.uid
            })
        }).then(() => location.reload());
    });
});

const inviteBtn = document.getElementById('cmm-invite-btn');
if (inviteBtn) {
    inviteBtn.addEventListener('click', function() {
        const first = document.getElementById('cmm-invite-first').value;
        const last  = document.getElementById('cmm-invite-last').value;
        const email = document.getElementById('cmm-invite-email').value;
        if (!email) return alert('Please enter an email address.');

        fetch(cmmAjax, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'cmm_invite_user',
                nonce: cmmNonce,
                invite_first: first,
                invite_last: last,
                invite_email: email
            })
        })
        .then(r => r.json())
        .then(data => {
            const msg = document.getElementById('cmm-invite-msg');
            msg.style.display = 'block';
            msg.textContent = data.data;
        });
    });
}
</script>
```

---

## Step 10 — SureMembers Integration

You don't need custom code here — just configure SureMembers in the admin:

1. **Create an Access Group** called "Active Members"
2. **Restrict content** (pages, posts, or entire sections) to this group
3. **Add members to this group** via the `home_admin` and `home_member` roles
4. When a home's status changes to `active`, the plugin assigns `home_admin` to the primary contact and `home_member` to all linked users
5. SureMembers handles the gate — the plugin handles the data

Add this hook to `class-frontend.php` or a new `class-suremember-sync.php`:

```php
// When membership status is saved, sync roles
add_action( 'acf/save_post', function( $post_id ) {
    if ( get_post_type( $post_id ) !== 'cmm_home' ) return;

    $status       = get_field( 'membership_status', $post_id );
    $primary_id   = get_field( 'primary_contact', $post_id );
    $linked_users = get_field( 'linked_users', $post_id ) ?: [];

    foreach ( $linked_users as $uid ) {
        $user = new WP_User( $uid );
        $user->remove_role( 'home_admin' );
        $user->remove_role( 'home_member' );

        if ( $status === 'active' ) {
            $role = ( $uid == $primary_id ) ? 'home_admin' : 'home_member';
            $user->add_role( $role );
        }
    }
}, 20 );
```

---

## Reusing for Multiple Communities

Because all settings are stored in `wp_options` with the `cmm_` prefix, each WordPress install (or multisite sub-site) gets its own isolated configuration. To deploy for OBYC or Seacrest:

1. Install the plugin on a new WordPress site
2. Activate → onboarding wizard runs
3. Enter the new community name/slug
4. Import that community's addresses
5. Done — completely independent instance

If you use **WordPress Multisite**, one plugin install covers all communities and each sub-site has its own `wp_options` table.

---

## Development Checklist

- [ ] Create plugin folder and main file
- [ ] Register CPT (`class-cpt.php`)
- [ ] Register ACF fields programmatically (`class-acf-fields.php`)
- [ ] Build onboarding wizard (`class-onboarding.php`)
- [ ] Build address importer (`class-importer.php`)
- [ ] Register custom roles (`class-roles.php`)
- [ ] Build reports dashboard + CSV export (`class-reports.php`)
- [ ] Build frontend dashboard shortcode (`class-frontend.php`)
- [ ] Build invite token handler (extend `class-frontend.php`)
- [ ] Add ACF save hook to sync SureMembers roles
- [ ] Test full flow: import → activate home → invite users → gate content
- [ ] Test CSV exports (active, all, dues)
- [ ] Test on a second community instance

---

## Estimated Build Time

| Task | Time |
|---|---|
| CPT + ACF registration | 1 hour |
| Onboarding wizard | 1–2 hours |
| Address importer | 1 hour |
| Roles + SureMembers sync | 1 hour |
| Reports + CSV export | 2–3 hours |
| Frontend dashboard | 2–3 hours |
| Invite flow + token handling | 1–2 hours |
| **Total** | **~10–14 hours** |

A freelance WordPress developer would price this at **$1,500–$3,000**. With this spec in hand, a mid-level dev could execute it cleanly.
