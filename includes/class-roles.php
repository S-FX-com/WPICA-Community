<?php
/**
 * Registers custom roles and keeps them in sync with home membership status.
 *
 * User meta written/cleared whenever a user is linked or unlinked from a home:
 *   cmm_home_id      — post ID of the linked cmm_home
 *   cmm_address_code — address code of that home (for display without a DB join)
 *
 * These meta keys make the user→home relationship queryable and visible in the
 * WP admin user profile, and allow SureForms to reference them in field mapping.
 */
class CMM_Roles {

    public static function init() {
        add_action( 'init',              [ __CLASS__, 'register_roles' ] );
        add_action( 'acf/save_post',     [ __CLASS__, 'sync_roles_on_save' ], 20 );
        add_action( 'show_user_profile', [ __CLASS__, 'render_home_profile_field' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_home_profile_field' ] );
    }

    public static function register_roles() {
        add_role( 'home_admin', 'Home Admin', [
            'read'            => true,
            'cmm_manage_home' => true,
            'cmm_view_dues'   => true,
        ] );

        add_role( 'home_member', 'Home Member', [
            'read'             => true,
            'cmm_view_content' => true,
        ] );

        add_role( 'pending_applicant', 'Pending Applicant', [
            'read' => true,
        ] );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( [ 'cmm_manage_home', 'cmm_view_dues', 'cmm_view_reports' ] as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Sync roles and user meta whenever a cmm_home post is saved through ACF.
     * Also clears roles/meta for users who were removed from linked_users.
     */
    public static function sync_roles_on_save( $post_id ) {
        if ( ! is_int( $post_id ) || get_post_type( $post_id ) !== 'cmm_home' ) return;

        $status      = get_field( 'membership_status', $post_id );
        $primary     = (int) get_field( 'primary_contact', $post_id );
        $linked      = array_map( 'intval', (array) ( get_field( 'linked_users', $post_id ) ?: [] ) );
        $code        = (string) get_field( 'address_code', $post_id );
        $member_role = get_option( 'cmm_approved_role', 'home_member' );

        // Clear roles and meta for users previously linked but no longer in the list.
        $prev_linked = get_users( [
            'meta_key'   => 'cmm_home_id',
            'meta_value' => $post_id,
            'fields'     => 'ID',
        ] );
        foreach ( $prev_linked as $uid ) {
            $uid = (int) $uid;
            if ( in_array( $uid, $linked, true ) ) continue;
            $u = new WP_User( $uid );
            self::strip_managed_roles( $u );
            self::clear_home_meta( $uid );
        }

        foreach ( $linked as $uid ) {
            $user = new WP_User( $uid );
            if ( ! $user->exists() ) continue;

            self::strip_managed_roles( $user );

            // Always write meta for any user in linked_users — meta represents the
            // administrative link, not access level. Roles (below) represent access.
            self::set_home_meta( $uid, $post_id, $code );

            $assigned = '';
            switch ( $status ) {
                case 'active':
                    $assigned = $uid === $primary ? 'home_admin' : $member_role;
                    break;
                case 'approved_pending_payment':
                case 'pending_review':
                    $assigned = 'pending_applicant';
                    break;
                // expired / inactive / rejected → no community role, but meta stays
            }

            if ( $assigned ) {
                $user->add_role( $assigned );
                update_user_meta( $uid, 'cmm_assigned_role', $assigned );
            }
        }
    }

    /**
     * Remove every role this plugin previously granted the user.
     *
     * Built-in plugin roles (home_admin, home_member, pending_applicant) are
     * always removed. The currently-stored cmm_assigned_role is also removed —
     * this is what allows the configurable Approved Member Role to be revoked
     * cleanly when the admin changes the setting or unlinks the user, without
     * stripping unrelated WordPress roles the user may also hold.
     */
    private static function strip_managed_roles( WP_User $user ): void {
        $user->remove_role( 'home_admin' );
        $user->remove_role( 'home_member' );
        $user->remove_role( 'pending_applicant' );

        $assigned = (string) get_user_meta( $user->ID, 'cmm_assigned_role', true );
        if ( $assigned && ! in_array( $assigned, [ 'home_admin', 'home_member', 'pending_applicant' ], true ) ) {
            $user->remove_role( $assigned );
        }
        delete_user_meta( $user->ID, 'cmm_assigned_role' );
    }

    // -------------------------------------------------------------------------
    // User meta helpers — called from this class and from Webhooks / Frontend
    // -------------------------------------------------------------------------

    public static function set_home_meta( int $user_id, int $home_id, string $code = '' ) {
        update_user_meta( $user_id, 'cmm_home_id',      $home_id );
        update_user_meta( $user_id, 'cmm_address_code', $code );
        update_user_meta( $user_id, 'cmm_home_address', get_the_title( $home_id ) );
    }

    public static function clear_home_meta( int $user_id ) {
        delete_user_meta( $user_id, 'cmm_home_id' );
        delete_user_meta( $user_id, 'cmm_address_code' );
        delete_user_meta( $user_id, 'cmm_home_address' );
    }

    // -------------------------------------------------------------------------
    // Admin profile display
    // -------------------------------------------------------------------------

    public static function render_home_profile_field( WP_User $user ) {
        $home_id = (int) get_user_meta( $user->ID, 'cmm_home_id', true );
        ?>
        <h3>Community Membership</h3>
        <table class="form-table">
            <tr>
                <th><label>Linked Home</label></th>
                <td>
                    <?php if ( $home_id && ( $home = get_post( $home_id ) ) ): ?>
                        <a href="<?php echo esc_url( get_edit_post_link( $home_id ) ); ?>">
                            <?php echo esc_html( $home->post_title ); ?>
                        </a>
                        <?php
                        $code = get_user_meta( $user->ID, 'cmm_address_code', true );
                        if ( $code ) echo '&nbsp;&nbsp;<code>' . esc_html( $code ) . '</code>';
                        ?>
                    <?php else: ?>
                        <span style="color:#646970;">No home linked</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }
}
