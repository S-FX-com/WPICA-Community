<?php
/**
 * Registers custom roles and role-sync hook.
 *
 * Roles:
 *   home_admin       — primary contact of an active home
 *   home_member      — additional resident of an active home
 *   pending_applicant — application submitted, awaiting review
 */
class CMM_Roles {

    public static function init() {
        add_action( 'init',         [ __CLASS__, 'register_roles' ] );
        add_action( 'acf/save_post', [ __CLASS__, 'sync_roles_on_save' ], 20 );
    }

    public static function register_roles() {
        // WordPress only inserts a role if it doesn't already exist.
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

        // Give site admins all CMM capabilities.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( [ 'cmm_manage_home', 'cmm_view_dues', 'cmm_view_reports' ] as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Keep user roles in sync whenever a cmm_home post is saved through ACF.
     * Called at priority 20 so ACF has already written the meta values.
     */
    public static function sync_roles_on_save( int $post_id ) {
        if ( get_post_type( $post_id ) !== 'cmm_home' ) return;

        $status  = get_field( 'membership_status', $post_id );
        $primary = (int) get_field( 'primary_contact', $post_id );
        $linked  = get_field( 'linked_users', $post_id ) ?: [];

        foreach ( $linked as $uid ) {
            $uid  = (int) $uid;
            $user = new WP_User( $uid );
            if ( ! $user->exists() ) continue;

            $user->remove_role( 'home_admin' );
            $user->remove_role( 'home_member' );
            $user->remove_role( 'pending_applicant' );

            switch ( $status ) {
                case 'active':
                    $user->add_role( $uid === $primary ? 'home_admin' : 'home_member' );
                    break;
                case 'approved_pending_payment':
                case 'pending_review':
                    $user->add_role( 'pending_applicant' );
                    break;
                // expired / inactive / rejected → no community role
            }
        }
    }
}
