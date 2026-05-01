<?php
/**
 * Frontend My Home dashboard shortcode [cmm_my_home].
 * Also handles invite token generation, acceptance, and AJAX user removal.
 */
class CMM_Frontend {

    public static function init() {
        add_shortcode( 'cmm_my_home',            [ __CLASS__, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts',         [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_cmm_send_invite', [ __CLASS__, 'send_invite' ] );
        add_action( 'admin_post_nopriv_cmm_accept_invite', [ __CLASS__, 'accept_invite' ] );
        add_action( 'admin_post_cmm_accept_invite',        [ __CLASS__, 'accept_invite' ] );
        add_action( 'wp_ajax_cmm_remove_user',             [ __CLASS__, 'ajax_remove_user' ] );
    }

    public static function enqueue_assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! has_shortcode( $post->post_content ?? '', 'cmm_my_home' ) ) return;

        wp_enqueue_style(
            'cmm-frontend',
            CMM_URL . 'assets/css/cmm-admin.css',
            [],
            CMM_VERSION
        );
        wp_enqueue_script(
            'cmm-admin',
            CMM_URL . 'assets/js/cmm-admin.js',
            [ 'jquery' ],
            CMM_VERSION,
            true
        );
        wp_localize_script( 'cmm-admin', 'cmmData', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cmm_remove_user' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public static function shortcode(): string {
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<p><a href="' . esc_url( $login_url ) . '">Log in</a> to view your home dashboard.</p>';
        }

        $user_id = get_current_user_id();
        $home    = self::get_home_for_user( $user_id );

        if ( ! $home ) {
            $register_url = home_url( '/register/' );
            return '<div class="cmm-dashboard"><p>No home linked to your account. '
                . '<a href="' . esc_url( $register_url ) . '">Apply for membership</a>.</p></div>';
        }

        $status = get_field( 'membership_status', $home->ID );

        ob_start();
        include CMM_PATH . 'templates/dashboard-my-home.php';
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Invite — send
    // -------------------------------------------------------------------------

    public static function send_invite() {
        check_admin_referer( 'cmm_send_invite' );
        if ( ! is_user_logged_in() ) wp_die( 'Unauthorized' );

        $home_id   = (int) ( $_POST['home_id'] ?? 0 );
        $home      = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) wp_die( 'Invalid home.' );

        // Only the home_admin (primary contact) may invite.
        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( get_current_user_id() !== $primary ) wp_die( 'Unauthorized' );

        $invite_name  = sanitize_text_field( $_POST['invite_name'] ?? '' );
        $invite_email = sanitize_email( $_POST['invite_email'] ?? '' );
        if ( ! $invite_email ) {
            wp_redirect( add_query_arg( 'cmm_error', 'invalid_email', get_permalink() ) );
            exit;
        }

        $token = wp_generate_password( 32, false );
        set_transient( 'cmm_invite_' . $token, [
            'home_id' => $home_id,
            'email'   => $invite_email,
            'name'    => $invite_name,
        ], 7 * DAY_IN_SECONDS );

        $accept_url = add_query_arg( [
            'action'       => 'cmm_accept_invite',
            'cmm_token'    => $token,
        ], admin_url( 'admin-post.php' ) );

        $community = get_option( 'cmm_community_name', 'Community' );
        $address   = $home->post_title;
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );

        wp_mail(
            $invite_email,
            "You've been invited to join {$community}",
            "Hi {$invite_name},\n\n"
            . "You've been invited to join the {$community} membership at {$address}.\n\n"
            . "Accept your invitation here (link valid 7 days):\n{$accept_url}\n\n"
            . "Thank you,\n{$community}",
            [ "From: {$community} <{$admin_email}>" ]
        );

        wp_redirect( add_query_arg( 'cmm_invited', '1', get_permalink() ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Invite — accept
    // -------------------------------------------------------------------------

    public static function accept_invite() {
        $token = sanitize_text_field( $_GET['cmm_token'] ?? '' );
        if ( ! $token ) wp_die( 'Invalid invite link.' );

        $data = get_transient( 'cmm_invite_' . $token );
        if ( ! $data ) wp_die( 'This invite link has expired or is invalid.' );

        $home_id = (int) $data['home_id'];
        $email   = $data['email'];

        // Create or find the user account.
        $user_id = email_exists( $email );
        if ( ! $user_id ) {
            $user_id = wp_create_user( $email, wp_generate_password(), $email );
            if ( is_wp_error( $user_id ) ) wp_die( $user_id->get_error_message() );
            wp_update_user( [ 'ID' => $user_id, 'display_name' => $data['name'] ] );
        }

        // Link user to home.
        $linked = get_field( 'linked_users', $home_id ) ?: [];
        if ( ! in_array( $user_id, $linked, true ) ) {
            $linked[] = $user_id;
            update_field( 'linked_users', $linked, $home_id );
        }

        $user = new WP_User( $user_id );
        $user->add_role( 'home_member' );
        CMM_Roles::set_home_meta( $user_id, $home_id, (string) get_field( 'address_code', $home_id ) );

        delete_transient( 'cmm_invite_' . $token );

        // Log the user in.
        wp_set_auth_cookie( $user_id );
        wp_redirect( home_url( '/my-home/?cmm_joined=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX — remove user from home
    // -------------------------------------------------------------------------

    public static function ajax_remove_user() {
        check_ajax_referer( 'cmm_remove_user' );
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Unauthorized' );

        $home_id    = (int) ( $_POST['home_id'] ?? 0 );
        $target_uid = (int) ( $_POST['user_id'] ?? 0 );

        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( get_current_user_id() !== $primary ) {
            wp_send_json_error( 'Only the home admin can remove members.' );
        }
        if ( $target_uid === $primary ) {
            wp_send_json_error( 'Cannot remove the primary contact.' );
        }

        $linked = get_field( 'linked_users', $home_id ) ?: [];
        $linked = array_values( array_filter( $linked, fn( $id ) => (int) $id !== $target_uid ) );
        update_field( 'linked_users', $linked, $home_id );

        $user = new WP_User( $target_uid );
        $user->remove_role( 'home_member' );
        $user->remove_role( 'home_admin' );
        CMM_Roles::clear_home_meta( $target_uid );

        wp_send_json_success( 'User removed.' );
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    public static function get_home_for_user( int $user_id ): ?WP_Post {
        $home_id = (int) get_user_meta( $user_id, 'cmm_home_id', true );
        if ( ! $home_id ) return null;
        $home = get_post( $home_id );
        return ( $home && $home->post_type === 'cmm_home' ) ? $home : null;
    }
}
