<?php
/**
 * Webhook endpoints for SureForms integration.
 *
 * POST /wp-json/cmm/v1/webhook/application  — fired on registration form submission
 * POST /wp-json/cmm/v1/webhook/payment      — fired on successful dues payment
 *
 * Both endpoints require a Bearer token in the Authorization header.
 * Secrets are auto-generated on first use and shown in Community → Dashboard.
 */
class CMM_Webhooks {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ] );
        add_action( 'admin_post_cmm_regenerate_webhook_secret', [ __CLASS__, 'regenerate_secret' ] );
    }

    // -------------------------------------------------------------------------
    // Secret management
    // -------------------------------------------------------------------------

    public static function get_secret( string $type ): string {
        $key    = 'cmm_webhook_secret_' . $type;
        $secret = get_option( $key, '' );
        if ( ! $secret ) {
            $secret = wp_generate_password( 32, false );
            update_option( $key, $secret );
        }
        return $secret;
    }

    private static function verify_secret( string $type, WP_REST_Request $request ): bool {
        $header = $request->get_header( 'authorization' );
        if ( ! $header ) return false;
        $token = preg_replace( '/^Bearer\s+/i', '', trim( $header ) );
        return hash_equals( self::get_secret( $type ), $token );
    }

    // -------------------------------------------------------------------------
    // REST registration
    // -------------------------------------------------------------------------

    public static function register_endpoints() {
        register_rest_route( 'cmm/v1', '/webhook/application', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_application' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'cmm/v1', '/webhook/payment', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_payment' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // -------------------------------------------------------------------------
    // Application webhook
    //
    // Expected JSON: { "home_id": 123, "email": "...", "first_name": "...", "last_name": "..." }
    //
    // SureForms creates the WP user before firing this webhook. We look up the
    // user by email, link them to the home, and set the home to pending_review.
    // If the user doesn't exist yet we create a minimal account as a fallback.
    // -------------------------------------------------------------------------

    public static function handle_application( WP_REST_Request $request ): WP_REST_Response {
        if ( ! self::verify_secret( 'application', $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }

        $params     = $request->get_json_params() ?: [];
        $home_id    = (int) ( $params['home_id'] ?? 0 );
        $email      = sanitize_email( $params['email'] ?? '' );
        $first_name = sanitize_text_field( $params['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $params['last_name'] ?? '' );

        if ( ! $home_id || ! $email ) {
            return new WP_REST_Response( [ 'error' => 'home_id and email are required' ], 400 );
        }

        $home = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) {
            return new WP_REST_Response( [ 'error' => 'Home not found' ], 404 );
        }

        $current_status = get_field( 'membership_status', $home_id );
        if ( ! in_array( $current_status, [ 'inactive', 'expired' ], true ) ) {
            return new WP_REST_Response( [
                'error' => "Home is not available for applications (status: {$current_status})",
            ], 409 );
        }

        // Find the user SureForms created, or create a minimal account as fallback.
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            $user_id = wp_create_user( $email, wp_generate_password(), $email );
            if ( is_wp_error( $user_id ) ) {
                return new WP_REST_Response( [ 'error' => $user_id->get_error_message() ], 500 );
            }
            if ( $first_name || $last_name ) {
                wp_update_user( [
                    'ID'           => $user_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => trim( "{$first_name} {$last_name}" ),
                ] );
            }
            $user = get_userdata( $user_id );
        }

        $user_id = $user->ID;

        // Link user to home and open the application.
        update_field( 'primary_contact',   $user_id,          $home_id );
        update_field( 'linked_users',      [ $user_id ],      $home_id );
        update_field( 'membership_status', 'pending_review',  $home_id );

        $user->add_role( 'pending_applicant' );

        self::notify_admin_new_application( $home_id, $user );

        return new WP_REST_Response( [
            'success' => true,
            'home_id' => $home_id,
            'user_id' => $user_id,
            'status'  => 'pending_review',
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // Payment webhook
    //
    // Expected JSON: { "home_id": 123, "amount": "150.00", "date": "2026-05-01" }
    //   OR           { "address_code": "WSH4", "amount": "150.00", "date": "2026-05-01" }
    //
    // Sets the home to active and records dues. Role sync runs immediately after
    // via CMM_Roles::sync_roles_on_save() since update_field() alone does not
    // fire the acf/save_post hook.
    // -------------------------------------------------------------------------

    public static function handle_payment( WP_REST_Request $request ): WP_REST_Response {
        if ( ! self::verify_secret( 'payment', $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }

        $params  = $request->get_json_params() ?: [];
        $amount  = (float) ( $params['amount'] ?? 0 );
        $date    = sanitize_text_field( $params['date'] ?? date( 'Y-m-d' ) );
        $home_id = (int) ( $params['home_id'] ?? 0 );

        if ( ! $home_id && ! empty( $params['address_code'] ) ) {
            $home_id = self::find_home_by_code( sanitize_text_field( $params['address_code'] ) );
        }

        if ( ! $home_id ) {
            return new WP_REST_Response( [ 'error' => 'home_id or address_code is required' ], 400 );
        }

        $home = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) {
            return new WP_REST_Response( [ 'error' => 'Home not found' ], 404 );
        }

        $current_status = get_field( 'membership_status', $home_id );
        if ( $current_status !== 'approved_pending_payment' ) {
            return new WP_REST_Response( [
                'error' => "Home is not awaiting payment (status: {$current_status})",
            ], 409 );
        }

        // Record payment details and activate.
        if ( $amount > 0 ) {
            update_field( 'dues_amount_paid', $amount, $home_id );
        }
        update_field( 'dues_paid_date',    $date,    $home_id );
        update_field( 'membership_status', 'active', $home_id );

        // update_field() does not fire acf/save_post, so sync roles explicitly.
        CMM_Roles::sync_roles_on_save( $home_id );

        return new WP_REST_Response( [
            'success' => true,
            'home_id' => $home_id,
            'status'  => 'active',
            'amount'  => $amount,
            'date'    => $date,
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // Admin action — regenerate a single secret
    // -------------------------------------------------------------------------

    public static function regenerate_secret() {
        check_admin_referer( 'cmm_regenerate_webhook_secret' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $type = sanitize_key( $_POST['secret_type'] ?? '' );
        if ( in_array( $type, [ 'application', 'payment' ], true ) ) {
            update_option( 'cmm_webhook_secret_' . $type, wp_generate_password( 32, false ) );
        }

        wp_redirect( admin_url( 'admin.php?page=community-membership&cmm_secrets_regenerated=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function find_home_by_code( string $code ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'address_code' AND meta_value = %s
             LIMIT 1",
            $code
        ) );
    }

    private static function notify_admin_new_application( int $home_id, WP_User $user ) {
        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $address     = get_the_title( $home_id );
        $review_url  = admin_url( 'admin.php?page=cmm-applications' );

        $subject = "New membership application — {$address}";
        $message = "A new membership application has been submitted.\n\n"
            . "Address: {$address}\n"
            . "Name:    {$user->display_name}\n"
            . "Email:   {$user->user_email}\n\n"
            . "Review it here:\n{$review_url}\n\n"
            . $community;

        wp_mail( $admin_email, $subject, $message, [ "From: {$community} <{$admin_email}>" ] );
    }
}
