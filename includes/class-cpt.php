<?php
/**
 * Registers the Home custom post type.
 * The post title IS the home address — no separate address field needed.
 */
class CMM_CPT {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_top_menu' ], 5 );
        add_filter( 'manage_cmm_home_posts_columns',         [ __CLASS__, 'list_columns' ] );
        add_filter( 'manage_edit-cmm_home_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'manage_cmm_home_posts_custom_column',   [ __CLASS__, 'list_column_content' ], 10, 2 );
        add_action( 'pre_get_posts',                         [ __CLASS__, 'apply_admin_query' ] );
        add_action( 'restrict_manage_posts',                 [ __CLASS__, 'render_admin_filters' ], 9 );
        add_action( 'restrict_manage_posts',                 [ __CLASS__, 'export_button' ] );
        add_action( 'admin_post_cmm_export_homes',           [ __CLASS__, 'export_csv' ] );
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

    public static function sortable_columns( array $columns ): array {
        $columns['address_code']      = 'address_code';
        $columns['membership_status'] = 'membership_status';
        $columns['primary_contact']   = 'primary_contact';
        $columns['dues_paid_date']    = 'dues_paid_date';
        return $columns;
    }

    /**
     * Drives sortable column ORDER BY and filter-dropdown WHERE clauses on the
     * Homes list table. primary_contact sorts by the linked user's display name
     * via a posts_clauses JOIN, so an empty value sorts last regardless of order.
     */
    public static function apply_admin_query( WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( $query->get( 'post_type' ) !== 'cmm_home' ) return;

        $orderby = (string) $query->get( 'orderby' );
        switch ( $orderby ) {
            case 'address_code':
            case 'membership_status':
            case 'dues_paid_date':
                $query->set( 'meta_key', $orderby );
                $query->set( 'orderby',  'meta_value' );
                break;
            case 'primary_contact':
                add_filter( 'posts_clauses', [ __CLASS__, 'orderby_primary_contact_clauses' ], 10, 2 );
                break;
        }

        $meta_query = $query->get( 'meta_query' ) ?: [];

        $status = isset( $_GET['cmm_status'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_status'] ) ) : '';
        if ( $status ) {
            $meta_query[] = [
                'key'   => 'membership_status',
                'value' => $status,
            ];
        }

        $year = isset( $_GET['cmm_dues_year'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_year'] ) ) : '';
        if ( $year === 'none' ) {
            $meta_query[] = [
                'relation' => 'OR',
                [ 'key' => 'dues_paid_date', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'dues_paid_date', 'value' => '', 'compare' => '=' ],
            ];
        } elseif ( preg_match( '/^\d{4}$/', $year ) ) {
            $meta_query[] = [
                'key'     => 'dues_paid_date',
                'value'   => [ "{$year}-01-01", "{$year}-12-31" ],
                'compare' => 'BETWEEN',
            ];
        }

        $from = isset( $_GET['cmm_dues_from'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_from'] ) ) : '';
        $to   = isset( $_GET['cmm_dues_to']   ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_to']   ) ) : '';
        if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $meta_query[] = [ 'key' => 'dues_paid_date', 'value' => $from, 'compare' => '>=' ];
        }
        if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $meta_query[] = [ 'key' => 'dues_paid_date', 'value' => $to, 'compare' => '<=' ];
        }

        if ( $meta_query ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * LEFT-JOIN users so the Primary Contact column sorts by display name. Empty
     * primary contacts come out at the end via COALESCE-to-tilde.
     */
    public static function orderby_primary_contact_clauses( array $clauses, WP_Query $q ): array {
        if ( ! is_admin() || ! $q->is_main_query() ) return $clauses;
        if ( $q->get( 'post_type' ) !== 'cmm_home' ) return $clauses;

        global $wpdb;
        $order = strtoupper( (string) $q->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS cmm_pc_meta
                                  ON cmm_pc_meta.post_id = {$wpdb->posts}.ID
                                 AND cmm_pc_meta.meta_key = 'primary_contact'
                                LEFT JOIN {$wpdb->users} AS cmm_pc_user
                                  ON cmm_pc_user.ID = cmm_pc_meta.meta_value ";
        $clauses['orderby'] = "COALESCE(NULLIF(cmm_pc_user.display_name, ''), '~') {$order}";

        // Single-use filter — remove so subsequent queries on the same page are unaffected.
        remove_filter( 'posts_clauses', [ __CLASS__, 'orderby_primary_contact_clauses' ], 10 );
        return $clauses;
    }

    /**
     * Renders Status + Dues Paid filter controls in the list table's filter bar.
     */
    public static function render_admin_filters( string $post_type ): void {
        if ( $post_type !== 'cmm_home' ) return;

        $current_status = isset( $_GET['cmm_status']    ) ? sanitize_text_field( wp_unslash( $_GET['cmm_status']    ) ) : '';
        $current_year   = isset( $_GET['cmm_dues_year'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_year'] ) ) : '';
        $current_from   = isset( $_GET['cmm_dues_from'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_from'] ) ) : '';
        $current_to     = isset( $_GET['cmm_dues_to']   ) ? sanitize_text_field( wp_unslash( $_GET['cmm_dues_to']   ) ) : '';

        $statuses = [
            'active'                   => 'Active',
            'inactive'                 => 'Inactive',
            'expired'                  => 'Expired',
            'approved_pending_payment' => 'Awaiting Payment',
            'pending_review'           => 'Pending Review',
            'rejected'                 => 'Rejected',
        ];

        global $wpdb;
        $years = $wpdb->get_col(
            "SELECT DISTINCT SUBSTRING(meta_value, 1, 4) AS y
               FROM {$wpdb->postmeta}
              WHERE meta_key = 'dues_paid_date' AND meta_value REGEXP '^[0-9]{4}-'
              ORDER BY y DESC"
        );
        ?>
        <select name="cmm_status">
            <option value="">All statuses</option>
            <?php foreach ( $statuses as $key => $label ): ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_status, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="cmm_dues_year">
            <option value="">All dues</option>
            <option value="none" <?php selected( $current_year, 'none' ); ?>>Never paid</option>
            <?php foreach ( $years as $year ): ?>
                <option value="<?php echo esc_attr( $year ); ?>" <?php selected( $current_year, $year ); ?>>
                    Paid in <?php echo esc_html( $year ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="cmm_dues_from" value="<?php echo esc_attr( $current_from ); ?>"
               placeholder="Dues from" title="Dues paid from" style="width:140px;">
        <input type="date" name="cmm_dues_to" value="<?php echo esc_attr( $current_to ); ?>"
               placeholder="Dues to" title="Dues paid to" style="width:140px;">
        <?php
    }

    /**
     * Renders the Export CSV button as a plain link, not a form. The
     * restrict_manage_posts hook fires inside the list table's posts-filter
     * <form method="get"> — any nested <form> here would close the outer form
     * early, breaking the search submission. admin-post.php dispatches
     * admin_post_{action} for GET requests too, so a nonce'd link works.
     */
    public static function export_button( string $post_type ): void {
        if ( $post_type !== 'cmm_home' ) return;
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=cmm_export_homes' ),
            'cmm_export_homes'
        );
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="button">&#8595; Export CSV</a>
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
