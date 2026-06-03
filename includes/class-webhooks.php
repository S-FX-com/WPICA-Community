<?php
/**
 * Webhook endpoints for SureForms integration.
 *
 * POST /wp-json/cmm/v1/webhook/application
 * POST /wp-json/cmm/v1/webhook/payment
 *
 * Both endpoints are equivalent: each accepts the same merged payload
 * (applicant fields + payment fields, all individually optional) and
 * activates the home immediately — no admin approval step. Two URLs exist
 * only because SureForms ties one webhook per form; point either or both
 * at the unified membership form.
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
    // Application webhook — accepts merged payload, activates immediately.
    // -------------------------------------------------------------------------

    public static function handle_application( WP_REST_Request $request ): WP_REST_Response {
        if ( ! self::verify_secret( 'application', $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }
        return self::handle_unified_webhook( $request );
    }

    // -------------------------------------------------------------------------
    // Payment webhook — accepts merged payload, activates immediately.
    // -------------------------------------------------------------------------

    public static function handle_payment( WP_REST_Request $request ): WP_REST_Response {
        if ( ! self::verify_secret( 'payment', $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }
        return self::handle_unified_webhook( $request );
    }

    // -------------------------------------------------------------------------
    // Shared request parsing + delegation to the activation helper.
    //
    // Expected JSON (all fields except a home identifier are optional):
    //   { "address": "196 Pershing Blvd",
    //     "email": "...", "first_name": "...", "last_name": "...",
    //     "amount": "150.00", "date": "2026-06-03" }
    //
    // Legacy alternatives: { "home_id": 123, ... }  |  { "address_code": "PER196", ... }
    // -------------------------------------------------------------------------

    private static function handle_unified_webhook( WP_REST_Request $request ): WP_REST_Response {
        $params     = $request->get_json_params() ?: [];
        $address    = sanitize_text_field( $params['address'] ?? '' );
        $home_id    = (int) ( $params['home_id'] ?? 0 );
        $email      = sanitize_email( $params['email'] ?? '' );
        $first_name = sanitize_text_field( $params['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $params['last_name'] ?? '' );
        $amount     = (float) ( $params['amount'] ?? 0 );
        $date       = sanitize_text_field( $params['date'] ?? '' );

        if ( ! $home_id && $address ) {
            $home_id = self::find_home_by_address( $address );
        }
        if ( ! $home_id && ! empty( $params['address_code'] ) ) {
            $home_id = self::find_home_by_code( sanitize_text_field( $params['address_code'] ) );
        }

        if ( ! $home_id ) {
            return new WP_REST_Response( [ 'error' => 'address, home_id, or address_code is required' ], 400 );
        }

        $result = self::process_membership_activation(
            $home_id, $email, $first_name, $last_name, $amount, $date
        );

        if ( isset( $result['error'] ) ) {
            $status_code = $result['status_code'] ?? 400;
            unset( $result['status_code'] );
            return new WP_REST_Response( $result, $status_code );
        }

        return new WP_REST_Response( $result, 200 );
    }

    // -------------------------------------------------------------------------
    // Core activation logic — shared by both endpoints.
    //
    // Resolves (or creates) the payer's user account, installs them as primary
    // contact (demoting any previous primary to a linked co-member), records
    // the dues payment, and flips the home to active. Idempotent — re-posting
    // the same payload is safe.
    // -------------------------------------------------------------------------

    public static function process_membership_activation(
        int    $home_id,
        string $email,
        string $first_name,
        string $last_name,
        float  $amount,
        string $date,
        array  $member_data = []
    ): array {
        $home = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) {
            return [ 'error' => 'Home not found', 'status_code' => 404 ];
        }

        $existing_primary = get_field( 'primary_contact', $home_id );
        $existing_uid     = $existing_primary
            ? (int) ( is_object( $existing_primary ) ? $existing_primary->ID : $existing_primary )
            : 0;

        // Resolve the payer. Prefer the submitted email; fall back to the
        // existing primary contact for renewal flows where the caller omits
        // applicant fields.
        $user = $email ? get_user_by( 'email', $email ) : null;
        if ( ! $user && ! $email && $existing_uid ) {
            $user = get_userdata( $existing_uid );
        }
        if ( ! $user && $email ) {
            $username = ! empty( $member_data['username'] ) ? sanitize_user( (string) $member_data['username'], true ) : '';
            $password = ! empty( $member_data['password'] ) ? (string) $member_data['password'] : wp_generate_password();
            $login    = $username ?: $email;
            if ( username_exists( $login ) ) {
                return [ 'error' => "Username '{$login}' is already taken", 'status_code' => 409 ];
            }
            $user_id = wp_create_user( $login, $password, $email );
            if ( is_wp_error( $user_id ) ) {
                return [ 'error' => $user_id->get_error_message(), 'status_code' => 500 ];
            }
            $user = get_userdata( $user_id );
        }

        if ( ! $user ) {
            return [ 'error' => 'email is required to activate this membership', 'status_code' => 400 ];
        }

        $user_id      = (int) $user->ID;
        $address_code = (string) get_field( 'address_code', $home_id );

        // Refresh names if the payload provided them; keep existing values
        // for fields the payload omits.
        if ( $first_name || $last_name ) {
            $fn = $first_name ?: (string) $user->first_name;
            $ln = $last_name  ?: (string) $user->last_name;
            wp_update_user( [
                'ID'           => $user_id,
                'first_name'   => $fn,
                'last_name'    => $ln,
                'display_name' => trim( "{$fn} {$ln}" ) ?: $user->display_name,
            ] );
            $user = get_userdata( $user_id );
        }

        // Normalize existing linked_users to a plain int array (ACF may return
        // user objects depending on field config).
        $linked     = get_field( 'linked_users', $home_id ) ?: [];
        $linked_ids = array_map( fn( $u ) => is_object( $u ) ? (int) $u->ID : (int) $u, $linked );

        // Primary-contact handover: new payer always becomes primary; previous
        // primary stays on the home as a linked co-member.
        $replaced_primary = false;
        if ( $existing_uid && $existing_uid !== $user_id ) {
            $replaced_primary = true;
            if ( ! in_array( $existing_uid, $linked_ids, true ) ) {
                $linked_ids[] = $existing_uid;
            }
        }
        if ( ! in_array( $user_id, $linked_ids, true ) ) {
            $linked_ids[] = $user_id;
        }

        update_field( 'primary_contact', $user_id,    $home_id );
        update_field( 'linked_users',    $linked_ids, $home_id );

        if ( $amount > 0 ) {
            update_field( 'dues_amount_paid', $amount, $home_id );
        }
        update_field( 'dues_paid_date',    $date ?: date( 'Y-m-d' ), $home_id );
        update_field( 'membership_status', 'active',                  $home_id );

        // Household-level fields (children, directory listing) only update
        // when the caller passes them — webhook callers omit them.
        if ( isset( $member_data['children'] ) ) {
            update_field( 'children_list', sanitize_textarea_field( (string) $member_data['children'] ), $home_id );
        }
        if ( array_key_exists( 'directory_listed', $member_data ) ) {
            update_field( 'directory_listed', ! empty( $member_data['directory_listed'] ) ? 1 : 0, $home_id );
        }

        // Contact-level fields (mobile, spouse, off-island address) go on
        // the primary contact's user meta. Helper only overwrites non-empty
        // keys so renewal payloads don't clobber existing data with blanks.
        CMM_Roles::set_member_meta( $user_id, $member_data );

        // Clear any legacy conflict postmeta opportunistically.
        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        CMM_Roles::set_home_meta( $user_id, $home_id, $address_code );
        // update_field() doesn't fire acf/save_post, so sync roles explicitly.
        CMM_Roles::sync_roles_on_save( $home_id );

        self::send_welcome_email( $user_id, $home_id, $amount );

        return [
            'success'          => true,
            'home_id'          => $home_id,
            'user_id'          => $user_id,
            'status'           => 'active',
            'amount'           => $amount,
            'date'             => $date ?: date( 'Y-m-d' ),
            'replaced_primary' => $replaced_primary,
        ];
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

    /**
     * Find a home by its full address text (post title). Case-insensitive on
     * most MySQL collations. Used when SureForms sends the address label rather
     * than the numeric ID.
     */
    private static function find_home_by_address( string $address ): int {
        $posts = get_posts( [
            'post_type'   => 'cmm_home',
            'title'       => $address,
            'numberposts' => 1,
            'post_status' => 'publish',
            'fields'      => 'ids',
        ] );
        return $posts ? (int) $posts[0] : 0;
    }

    private static function find_home_by_code( string $code ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'address_code' AND meta_value = %s
             LIMIT 1",
            $code
        ) );
    }

    // -------------------------------------------------------------------------
    // Welcome / payment receipt email — sent on every activation.
    // -------------------------------------------------------------------------

    private static function send_welcome_email( int $user_id, int $home_id, float $amount_paid ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $dues        = number_format( (float) get_option( 'cmm_dues_amount', 0 ), 2 );
        $address     = get_the_title( $home_id );
        $payment_url = get_option( 'cmm_payment_url', home_url( '/membership-payment/' ) );
        $paid_date   = (string) get_field( 'dues_paid_date', $home_id );
        $login_url   = wp_login_url();

        // One-time password setup URL — useful for accounts created by webhook
        // without a known password, and as a safety net for any user who wants
        // to reset. Valid for ~24 hours per WP defaults.
        $reset_key          = get_password_reset_key( $user );
        $password_setup_url = is_wp_error( $reset_key )
            ? wp_lostpassword_url()
            : network_site_url(
                'wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode( $user->user_login ),
                'login'
            );

        $default_subject = 'Welcome to {community_name} — your membership is active';
        $default_body    = "Hi {first_name},\n\n"
            . "Thank you for your membership at {address}.\n\n"
            . "Payment received: \${amount_paid} on {paid_date}.\n\n"
            . "Your membership is now active. Log in any time to manage your home and household members:\n"
            . "{login_url}\n\n"
            . "Need to set or reset your password? Use this one-time link (valid for 24 hours):\n"
            . "{password_setup_url}\n\n"
            . "Questions? Reply to this email or contact {admin_email}.\n\n"
            . "Thank you,\n{community_name}";

        $subject_tpl = get_option( 'cmm_approval_email_subject', $default_subject );
        $body_tpl    = get_option( 'cmm_approval_email_body',    $default_body );

        $replacements = [
            '{first_name}'         => $user->first_name,
            '{last_name}'          => $user->last_name,
            '{address}'            => $address,
            '{dues_amount}'        => $dues,
            '{amount_paid}'        => number_format( $amount_paid, 2 ),
            '{paid_date}'          => $paid_date,
            '{payment_url}'        => $payment_url,
            '{login_url}'          => $login_url,
            '{password_setup_url}' => $password_setup_url,
            '{community_name}'     => $community,
            '{admin_email}'        => $admin_email,
        ];

        $subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

        wp_mail( $user->user_email, $subject, $body, [ "From: {$community} <{$admin_email}>" ] );
    }
}
