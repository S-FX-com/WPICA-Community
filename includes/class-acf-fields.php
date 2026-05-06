<?php
/**
 * Registers ACF field group for the Home CPT entirely in code.
 * No JSON import needed — travels with the plugin across all installs.
 */
class CMM_ACF_Fields {

    public static function init() {
        add_action( 'acf/init',      [ __CLASS__, 'register_fields' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metaboxes' ] );
        add_action( 'admin_post_cmm_clear_primary_contact', [ __CLASS__, 'handle_clear_primary' ] );
    }

    public static function register_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        acf_add_local_field_group( [
            'key'      => 'group_cmm_home',
            'title'    => 'Home Membership Details',
            'location' => [ [ [
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'cmm_home',
            ] ] ],
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
                    'allow_null'    => 1,
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
        ] );
    }

    // -------------------------------------------------------------------------
    // Sidebar metabox — Remove Primary Contact button
    // -------------------------------------------------------------------------

    public static function register_metaboxes(): void {
        add_meta_box(
            'cmm_primary_contact_actions',
            'Primary Contact',
            [ __CLASS__, 'render_primary_metabox' ],
            'cmm_home',
            'side',
            'default'
        );
    }

    public static function render_primary_metabox( WP_Post $post ): void {
        $raw     = get_field( 'primary_contact', $post->ID );
        $uid     = $raw ? (int) ( is_object( $raw ) ? $raw->ID : $raw ) : 0;
        $user    = $uid ? get_userdata( $uid ) : null;
        $cleared = isset( $_GET['cmm_primary_cleared'] );
        ?>
        <?php if ( $cleared ): ?>
        <div class="notice notice-success inline" style="margin:4px 0 10px;">
            <p>Primary contact removed.</p>
        </div>
        <?php endif; ?>

        <?php if ( $user ): ?>
        <p style="margin:0 0 8px;">
            <strong><?php echo esc_html( $user->display_name ); ?></strong><br>
            <span style="color:#646970;font-size:12px;"><?php echo esc_html( $user->user_email ); ?></span>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_clear_primary_' . $post->ID ); ?>
            <input type="hidden" name="action"  value="cmm_clear_primary_contact">
            <input type="hidden" name="home_id" value="<?php echo absint( $post->ID ); ?>">
            <button type="submit" class="button button-small"
                    style="color:#d63638;border-color:#d63638;"
                    onclick="return confirm('Remove <?php echo esc_js( $user->display_name ); ?> as primary contact?')">
                &times; Remove Primary Contact
            </button>
        </form>
        <?php else: ?>
        <p style="color:#646970;margin:0;font-size:13px;">No primary contact assigned.</p>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Handler — clear primary contact and redirect back to the edit screen
    // -------------------------------------------------------------------------

    public static function handle_clear_primary(): void {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_clear_primary_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $old_uid = (int) get_field( 'primary_contact', $home_id );
        if ( $old_uid ) {
            // Remove the user from linked_users if they are only there as primary contact.
            $linked = array_map(
                fn( $u ) => (int) ( is_object( $u ) ? $u->ID : $u ),
                (array) ( get_field( 'linked_users', $home_id ) ?: [] )
            );
            $remaining = array_values( array_filter( $linked, fn( $id ) => $id !== $old_uid ) );
            update_field( 'linked_users', $remaining, $home_id );
        }

        update_field( 'primary_contact', '', $home_id );
        CMM_Roles::sync_roles_on_save( $home_id );

        wp_redirect( add_query_arg(
            [ 'cmm_primary_cleared' => '1' ],
            get_edit_post_link( $home_id, 'url' )
        ) );
        exit;
    }
}
