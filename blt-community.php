<?php
/**
 * Plugin Name: Blt Community
 * Description: Home-centric membership management for civic associations and community organizations.
 * Version:     1.1.0
 * Author:      BLT
 * Text Domain: cmm
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CMM_VERSION', '1.1.0' );
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
    'class-block',
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
    CMM_Block::init();
} );

register_activation_hook( __FILE__, function() {
    add_option( 'cmm_onboarding_complete', false );
    add_option( 'cmm_community_name',      '' );
    add_option( 'cmm_community_slug',      '' );
    add_option( 'cmm_dues_amount',         0 );
    add_option( 'cmm_dues_cycle',          'annual' );
    add_option( 'cmm_admin_email',         get_option( 'admin_email' ) );
    add_option( 'cmm_dues_reset_month',    '01' );
    add_option( 'cmm_dues_reset_day',      '01' );
    add_option( 'cmm_address_codes',       [] );

    if ( ! wp_next_scheduled( 'cmm_dues_reset_event' ) ) {
        wp_schedule_event( time(), 'daily', 'cmm_dues_reset_event' );
    }
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'cmm_dues_reset_event' );
} );
