<?php
/**
 * Annual dues reset cron.
 * On the configured month + day, all active homes flip to expired
 * and their linked users lose community roles.
 */
class CMM_Dues {

    public static function init() {
        add_action( 'cmm_dues_reset_event', [ __CLASS__, 'run_reset' ] );
        add_action( 'admin_notices',        [ __CLASS__, 'reset_notice' ] );
        add_action( 'admin_init',           [ __CLASS__, 'dismiss_notice' ] );

        // Admin: manual trigger for testing
        add_action( 'admin_post_cmm_trigger_dues_reset', [ __CLASS__, 'manual_trigger' ] );
    }

    // -------------------------------------------------------------------------
    // Cron callback
    // -------------------------------------------------------------------------

    public static function run_reset() {
        $month = (int) get_option( 'cmm_dues_reset_month', 1 );
        $day   = (int) get_option( 'cmm_dues_reset_day',   1 );

        if ( (int) date( 'n' ) !== $month || (int) date( 'j' ) !== $day ) return;

        self::do_reset();
    }

    public static function do_reset(): int {
        $active_homes = get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'meta_query'     => [ [
                'key'   => 'membership_status',
                'value' => 'active',
            ] ],
        ] );

        $count = 0;
        foreach ( $active_homes as $home ) {
            update_field( 'membership_status', 'expired', $home->ID );

            $linked = get_field( 'linked_users', $home->ID ) ?: [];
            foreach ( $linked as $uid ) {
                $user = new WP_User( (int) $uid );
                if ( ! $user->exists() ) continue;
                $user->remove_role( 'home_admin' );
                $user->remove_role( 'home_member' );
            }
            $count++;
        }

        update_option( 'cmm_dues_reset_notice', [
            'date'  => date( 'F j, Y' ),
            'count' => $count,
        ] );

        return $count;
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    public static function reset_notice() {
        $notice = get_option( 'cmm_dues_reset_notice' );
        if ( ! $notice ) return;

        $key = 'cmm_reset_dismissed_' . sanitize_key( $notice['date'] );
        if ( get_user_meta( get_current_user_id(), $key, true ) ) return;

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'cmm_dismiss_reset', '1' ),
            'cmm_dismiss_reset'
        );
        $reports_url = admin_url( 'admin.php?page=cmm-reports' );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Blt Community:</strong> Annual dues reset ran on '
            . esc_html( $notice['date'] ) . '. ';
        echo absint( $notice['count'] ) . ' home(s) set to <strong>Expired</strong>. ';
        echo 'Members must renew to regain access. ';
        echo '<a href="' . esc_url( $reports_url ) . '">View Expired Homes &rarr;</a>';
        echo ' &nbsp; <a href="' . esc_url( $dismiss_url ) . '">Dismiss</a>';
        echo '</p></div>';
    }

    public static function dismiss_notice() {
        if ( ! isset( $_GET['cmm_dismiss_reset'] ) ) return;
        check_admin_referer( 'cmm_dismiss_reset' );

        $notice = get_option( 'cmm_dues_reset_notice' );
        if ( ! $notice ) return;

        $key = 'cmm_reset_dismissed_' . sanitize_key( $notice['date'] );
        update_user_meta( get_current_user_id(), $key, true );

        wp_redirect( remove_query_arg( [ 'cmm_dismiss_reset', '_wpnonce' ] ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Manual trigger (admin only — for testing)
    // -------------------------------------------------------------------------

    public static function manual_trigger() {
        check_admin_referer( 'cmm_trigger_dues_reset' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $count = self::do_reset();

        wp_redirect( admin_url(
            'admin.php?page=cmm-reports&cmm_notice=' . urlencode( $count . ' home(s) expired.' )
        ) );
        exit;
    }
}
