<?php
/**
 * Reports dashboard with summary cards, filterable table, and CSV exports.
 */
class CMM_Reports {

    public static function init() {
        add_action( 'admin_menu',              [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_export',   [ __CLASS__, 'handle_export' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Reports',
            'Reports',
            'manage_options',
            'cmm-reports',
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Reports page
    // -------------------------------------------------------------------------

    public static function render_page() {
        $notice = isset( $_GET['cmm_notice'] ) ? sanitize_text_field( $_GET['cmm_notice'] ) : '';

        // Filters
        $filter_status = sanitize_text_field( $_GET['status'] ?? '' );
        $filter_year   = (int) ( $_GET['year'] ?? 0 );
        $search        = sanitize_text_field( $_GET['s'] ?? '' );

        $homes = self::get_homes( $filter_status, $filter_year, $search );

        // Aggregate totals
        $totals = self::get_totals();
        ?>
        <div class="wrap">
            <h1>Reports</h1>

            <?php if ( $notice ): ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <!-- Summary cards -->
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
                <?php
                self::card( 'Total Homes',     $totals['total'],    '#2271b1' );
                self::card( 'Active Members',  $totals['active'],   '#00a32a' );
                self::card( 'Inactive',        $totals['inactive'], '#646970' );
                self::card( 'Expired',         $totals['expired'],  '#d63638' );
                self::card( 'Total Dues YTD',  '$' . number_format( $totals['dues_ytd'], 2 ), '#2271b1' );
                ?>
            </div>

            <!-- Export buttons -->
            <div style="margin-bottom:20px;">
                <?php foreach ( [ 'active' => 'Active Members', 'all' => 'All Homes', 'dues' => 'Dues Report' ] as $type => $label ): ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field( 'cmm_export' ); ?>
                    <input type="hidden" name="action"      value="cmm_export">
                    <input type="hidden" name="export_type" value="<?php echo esc_attr( $type ); ?>">
                    <button type="submit" class="button">&#8595; Export <?php echo esc_html( $label ); ?> CSV</button>
                </form>
                <?php endforeach; ?>

                <!-- Manual dues reset trigger -->
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;float:right;"
                      onsubmit="return confirm('This will immediately expire all active homes. Continue?');">
                    <?php wp_nonce_field( 'cmm_trigger_dues_reset' ); ?>
                    <input type="hidden" name="action" value="cmm_trigger_dues_reset">
                    <button type="submit" class="button button-secondary">&#9888; Run Dues Reset Now</button>
                </form>
            </div>

            <!-- Filters -->
            <form method="get" style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">
                <input type="hidden" name="page" value="cmm-reports">
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ( self::statuses() as $val => $label ): ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_status, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="year">
                    <option value="0">All Years</option>
                    <?php for ( $y = (int) date('Y'); $y >= (int) date('Y') - 5; $y-- ): ?>
                    <option value="<?php echo $y; ?>" <?php selected( $filter_year, $y ); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Address or code…" style="width:200px;">
                <button type="submit" class="button">Filter</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmm-reports' ) ); ?>" class="button">Reset</a>
            </form>

            <!-- Home table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th>Primary Contact</th>
                        <th>Email</th>
                        <th>Dues Paid Date</th>
                        <th>Amount Paid</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( ! $homes ): ?>
                    <tr><td colspan="7" style="color:#646970;text-align:center;">No records found.</td></tr>
                <?php else: foreach ( $homes as $home ):
                    $primary = (int) get_field( 'primary_contact', $home->ID );
                    $user    = $primary ? get_userdata( $primary ) : null;
                    $status  = get_field( 'membership_status', $home->ID );
                    $code    = get_field( 'address_code', $home->ID );
                    $date    = get_field( 'dues_paid_date', $home->ID );
                    $amount  = get_field( 'dues_amount_paid', $home->ID );
                ?>
                    <tr>
                        <td><?php echo esc_html( $home->post_title ); ?></td>
                        <td><code><?php echo esc_html( $code ); ?></code></td>
                        <td><?php echo esc_html( self::statuses()[ $status ] ?? $status ); ?></td>
                        <td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
                        <td><?php echo $user ? esc_html( $user->user_email ) : '—'; ?></td>
                        <td><?php echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—'; ?></td>
                        <td><?php echo $amount ? '$' . number_format( (float) $amount, 2 ) : '—'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    public static function handle_export() {
        check_admin_referer( 'cmm_export' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $type = sanitize_key( $_POST['export_type'] ?? 'all' );

        $homes = match ( $type ) {
            'active' => self::get_homes( 'active' ),
            'dues'   => self::get_homes_with_dues(),
            default  => self::get_homes(),
        };

        $filename = 'cmm-' . $type . '-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $fh = fopen( 'php://output', 'w' );

        if ( $type === 'dues' ) {
            fputcsv( $fh, [ 'Address', 'Code', 'Amount Paid', 'Date Paid', 'Year' ] );
            foreach ( $homes as $home ) {
                $date   = get_field( 'dues_paid_date', $home->ID );
                $amount = get_field( 'dues_amount_paid', $home->ID );
                fputcsv( $fh, [
                    $home->post_title,
                    get_field( 'address_code', $home->ID ),
                    $amount ? number_format( (float) $amount, 2 ) : '',
                    $date ?: '',
                    $date ? date( 'Y', strtotime( $date ) ) : '',
                ] );
            }
        } else {
            fputcsv( $fh, [ 'Address', 'Code', 'Status', 'Primary Contact', 'Email', 'Dues Date', 'Amount' ] );
            foreach ( $homes as $home ) {
                $primary = (int) get_field( 'primary_contact', $home->ID );
                $user    = $primary ? get_userdata( $primary ) : null;
                $date    = get_field( 'dues_paid_date', $home->ID );
                $amount  = get_field( 'dues_amount_paid', $home->ID );
                fputcsv( $fh, [
                    $home->post_title,
                    get_field( 'address_code', $home->ID ),
                    get_field( 'membership_status', $home->ID ),
                    $user ? $user->display_name : '',
                    $user ? $user->user_email : '',
                    $date ?: '',
                    $amount ? number_format( (float) $amount, 2 ) : '',
                ] );
            }
        }

        fclose( $fh );
        exit;
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private static function get_homes( string $status = '', int $year = 0, string $search = '' ): array {
        $args = [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'meta_query'     => [],
        ];

        if ( $status ) {
            $args['meta_query'][] = [
                'key'   => 'membership_status',
                'value' => $status,
            ];
        }

        if ( $year ) {
            $args['meta_query'][] = [
                'key'     => 'dues_paid_date',
                'value'   => [ $year . '-01-01', $year . '-12-31' ],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ];
        }

        if ( $search ) {
            // Search by address (post title) or address code
            add_filter( 'posts_where', [ __CLASS__, 'filter_search_where' ], 10, 2 );
            $args['_cmm_search'] = $search;
        }

        $homes = get_posts( $args );
        remove_filter( 'posts_where', [ __CLASS__, 'filter_search_where' ] );
        return $homes;
    }

    public static function filter_search_where( string $where, WP_Query $query ): string {
        $search = $query->get( '_cmm_search' );
        if ( ! $search ) return $where;

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where .= $wpdb->prepare(
            " AND ( {$wpdb->posts}.post_title LIKE %s
               OR EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm2
                   WHERE pm2.post_id = {$wpdb->posts}.ID
                     AND pm2.meta_key = 'address_code'
                     AND pm2.meta_value LIKE %s
               ) )",
            $like,
            $like
        );
        return $where;
    }

    private static function get_homes_with_dues(): array {
        return get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'meta_query'     => [ [
                'key'     => 'dues_paid_date',
                'compare' => 'EXISTS',
            ] ],
        ] );
    }

    private static function get_totals(): array {
        global $wpdb;

        $statuses = $wpdb->get_results(
            "SELECT pm.meta_value AS status, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'membership_status'
             WHERE p.post_type = 'cmm_home' AND p.post_status = 'publish'
             GROUP BY pm.meta_value",
            OBJECT_K
        );

        $dues_ytd = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(pm.meta_value)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'dues_amount_paid'
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'dues_paid_date'
             WHERE p.post_type = 'cmm_home'
               AND p.post_status = 'publish'
               AND pm2.meta_value LIKE %s",
            date( 'Y' ) . '%'
        ) );

        return [
            'total'    => (int) wp_count_posts( 'cmm_home' )->publish,
            'active'   => (int) ( $statuses['active']->cnt ?? 0 ),
            'inactive' => (int) ( $statuses['inactive']->cnt ?? 0 ),
            'expired'  => (int) ( $statuses['expired']->cnt ?? 0 ),
            'dues_ytd' => $dues_ytd,
        ];
    }

    private static function card( string $label, $value, string $color ): void {
        printf(
            '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 24px;min-width:140px;text-align:center;">
                <div style="font-size:1.8em;font-weight:700;color:%s;">%s</div>
                <div style="color:#646970;margin-top:4px;">%s</div>
            </div>',
            esc_attr( $color ),
            esc_html( (string) $value ),
            esc_html( $label )
        );
    }

    private static function statuses(): array {
        return [
            'active'                   => 'Active',
            'inactive'                 => 'Inactive',
            'expired'                  => 'Expired',
            'approved_pending_payment' => 'Awaiting Payment',
            'pending_review'           => 'Pending Review',
            'rejected'                 => 'Rejected',
        ];
    }
}
