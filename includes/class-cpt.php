<?php
/**
 * Registers the Home custom post type.
 * The post title IS the home address — no separate address field needed.
 */
class CMM_CPT {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_top_menu' ], 5 );
        add_filter( 'manage_cmm_home_posts_columns',       [ __CLASS__, 'list_columns' ] );
        add_action( 'manage_cmm_home_posts_custom_column', [ __CLASS__, 'list_column_content' ], 10, 2 );
        add_action( 'restrict_manage_posts',               [ __CLASS__, 'export_button' ] );
        add_action( 'admin_post_cmm_export_homes',         [ __CLASS__, 'export_csv' ] );
    }

    public static function register_top_menu() {
        add_menu_page(
            'Community Membership',
            'Community',
            'manage_options',
            'community-membership',
            [ __CLASS__, 'dashboard_redirect' ],
            'dashicons-groups',
            30
        );

        // First submenu shares the parent slug to avoid a duplicate top-level link.
        add_submenu_page(
            'community-membership',
            'Community Dashboard',
            'Dashboard',
            'manage_options',
            'community-membership',
            [ 'CMM_Onboarding', 'render_page' ]
        );
    }

    /** Redirect is never called — Onboarding::render_page handles the parent slug. */
    public static function dashboard_redirect() {}

    public static function register() {
        register_post_type( 'cmm_home', [
            'label'           => 'Homes',
            'labels'          => [
                'name'               => 'Homes',
                'singular_name'      => 'Home',
                'add_new_item'       => 'Add New Home',
                'edit_item'          => 'Edit Home',
                'search_items'       => 'Search Homes',
                'not_found'          => 'No homes found.',
                'not_found_in_trash' => 'No homes found in trash.',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'community-membership',
            'supports'        => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'show_in_rest'    => true,
            'rest_base'       => 'cmm-homes',
            'menu_icon'       => 'dashicons-admin-home',
        ] );
    }

    public static function list_columns( array $columns ): array {
        return [
            'cb'               => $columns['cb'],
            'title'            => 'Address',
            'address_code'     => 'Code',
            'membership_status'=> 'Status',
            'primary_contact'  => 'Primary Contact',
            'dues_paid_date'   => 'Dues Paid',
        ];
    }

    public static function export_button( string $post_type ): void {
        if ( $post_type !== 'cmm_home' ) return;
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
            <?php wp_nonce_field( 'cmm_export_homes' ); ?>
            <input type="hidden" name="action" value="cmm_export_homes">
            <button type="submit" class="button">&#8595; Export CSV</button>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // CSV export — Address, Code, Status, Primary Contact, Dues Paid, Linked Users
    // -------------------------------------------------------------------------

    public static function export_csv(): void {
        check_admin_referer( 'cmm_export_homes' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $homes = get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $status_labels = [
            'active'                   => 'Active',
            'inactive'                 => 'Inactive',
            'expired'                  => 'Expired',
            'approved_pending_payment' => 'Awaiting Payment',
            'pending_review'           => 'Pending Review',
            'rejected'                 => 'Rejected',
        ];

        $filename = 'cmm-homes-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $fh = fopen( 'php://output', 'w' );
        fputcsv( $fh, [ 'Address', 'Code', 'Status', 'Primary Contact', 'Primary Email', 'Dues Paid Date', 'Dues Amount Paid', 'Linked Users' ] );

        foreach ( $homes as $home ) {
            $code   = get_field( 'address_code',     $home->ID ) ?: '';
            $status = get_field( 'membership_status', $home->ID ) ?: '';

            $primary_uid = (int) get_field( 'primary_contact', $home->ID );
            $primary     = $primary_uid ? get_userdata( $primary_uid ) : null;

            $dues_date   = get_field( 'dues_paid_date',   $home->ID ) ?: '';
            $dues_amount = get_field( 'dues_amount_paid', $home->ID );

            // Linked users excluding the primary contact to avoid duplication.
            $linked       = get_field( 'linked_users', $home->ID ) ?: [];
            $linked_names = [];
            foreach ( $linked as $entry ) {
                $uid = is_object( $entry ) ? $entry->ID : (int) $entry;
                if ( $uid === $primary_uid ) continue;
                $lu = get_userdata( $uid );
                if ( $lu ) {
                    $linked_names[] = $lu->display_name;
                }
            }

            fputcsv( $fh, [
                $home->post_title,
                $code,
                $status_labels[ $status ] ?? $status,
                $primary ? $primary->display_name : '',
                $primary ? $primary->user_email    : '',
                $dues_date,
                $dues_amount ? number_format( (float) $dues_amount, 2 ) : '',
                implode( ', ', $linked_names ),
            ] );
        }

        fclose( $fh );
        exit;
    }

    public static function list_column_content( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'address_code':
                echo '<code>' . esc_html( get_field( 'address_code', $post_id ) ) . '</code>';
                break;
            case 'membership_status':
                $status = get_field( 'membership_status', $post_id );
                $labels = [
                    'active'                   => '<span style="color:#00a32a;">&#9679; Active</span>',
                    'inactive'                 => '<span style="color:#646970;">&#9679; Inactive</span>',
                    'expired'                  => '<span style="color:#d63638;">&#9679; Expired</span>',
                    'approved_pending_payment' => '<span style="color:#dba617;">&#9679; Awaiting Payment</span>',
                    'pending_review'           => '<span style="color:#2271b1;">&#9679; Pending Review</span>',
                    'rejected'                 => '<span style="color:#d63638;">&#215; Rejected</span>',
                ];
                echo $labels[ $status ] ?? esc_html( $status );
                break;
            case 'primary_contact':
                $uid = get_field( 'primary_contact', $post_id );
                if ( $uid ) {
                    $u = get_userdata( $uid );
                    echo $u ? esc_html( $u->display_name ) : '—';
                } else {
                    echo '—';
                }
                break;
            case 'dues_paid_date':
                $date = get_field( 'dues_paid_date', $post_id );
                echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—';
                break;
        }
    }
}
