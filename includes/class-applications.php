<?php
/**
 * Membership Activity admin page + REST address typeahead endpoint.
 *
 * The admin page is a read-only audit log grouped by status. Webhooks
 * activate homes directly — there is no approval/reject UI here. The Reset
 * action remains so admins can clear a home back to inactive when needed.
 *
 * Legacy statuses (pending_review, approved_pending_payment, rejected) still
 * render so any historical records remain visible. They self-heal to active
 * on the next webhook call, or admins can Reset them.
 */
class CMM_Applications {

    public static function init() {
        add_action( 'admin_menu',         [ __CLASS__, 'register_menu' ] );
        add_action( 'rest_api_init',      [ __CLASS__, 'register_endpoints' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_typeahead' ] );

        add_action( 'admin_post_cmm_reset_home', [ __CLASS__, 'reset_home' ] );
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Membership Activity',
            'Membership Activity',
            'manage_options',
            'cmm-applications',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        $action_done = $_GET['cmm_action'] ?? '';
        ?>
        <div class="wrap">
            <h1>Membership Activity</h1>

            <?php if ( $action_done === 'reset' ): ?>
            <div class="notice notice-warning inline"><p>Home reset to inactive.</p></div>
            <?php endif; ?>

            <?php
            self::render_section( 'Active Members',                      'active'                   );
            self::render_section( 'Expired',                             'expired'                  );
            self::render_section( 'Inactive',                            'inactive'                 );
            self::render_section( 'Legacy — Pending Review',             'pending_review'           );
            self::render_section( 'Legacy — Approved, Awaiting Payment', 'approved_pending_payment' );
            self::render_section( 'Legacy — Rejected',                   'rejected'                 );
            ?>
        </div>
        <?php
    }

    private static function render_section( string $heading, string $status ) {
        $homes = get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'meta_query'     => [ [
                'key'   => 'membership_status',
                'value' => $status,
            ] ],
        ] );

        echo '<h2>' . esc_html( $heading ) . ' (' . count( $homes ) . ')</h2>';

        if ( ! $homes ) {
            echo '<p style="color:#646970;">None.</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>Code</th>
                    <th>Dues Paid</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $homes as $home ):
                $primary = (int) get_field( 'primary_contact', $home->ID );
                $user    = $primary ? get_userdata( $primary ) : null;
                $code    = get_field( 'address_code', $home->ID );
                $amount  = (float) get_field( 'dues_amount_paid', $home->ID );
                $paid_on = get_field( 'dues_paid_date', $home->ID );
                $updated = date( 'M j', strtotime( $home->post_modified ) );
            ?>
            <tr>
                <td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
                <td><?php echo $user ? esc_html( $user->user_email ) : '—'; ?></td>
                <td><?php echo esc_html( $home->post_title ); ?></td>
                <td><code><?php echo esc_html( $code ); ?></code></td>
                <td>
                    <?php if ( $amount > 0 || $paid_on ): ?>
                        $<?php echo esc_html( number_format( $amount, 2 ) ); ?>
                        <?php if ( $paid_on ): ?>
                            <br><small style="color:#646970;"><?php echo esc_html( $paid_on ); ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $updated ); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                          onsubmit="return confirm('Reset this home to inactive? This clears all membership and payment data.')">
                        <?php wp_nonce_field( 'cmm_reset_' . $home->ID ); ?>
                        <input type="hidden" name="action"  value="cmm_reset_home">
                        <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                        <button type="submit" class="button button-small" style="color:#b32d2e;">Reset</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Reset home to inactive (clears membership and payment data)
    // -------------------------------------------------------------------------

    public static function reset_home() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_reset_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Stash any legacy conflict applicant so we can clean their roles/meta
        // — they aren't in linked_users yet on legacy conflict records.
        $conflict_uid = (int) get_post_meta( $home_id, 'cmm_pending_conflict_user_id', true );

        update_field( 'membership_status', 'inactive', $home_id );
        update_field( 'primary_contact',   '',         $home_id );
        update_field( 'linked_users',      [],         $home_id );
        update_field( 'dues_amount_paid',  '',         $home_id );
        update_field( 'dues_paid_date',    '',         $home_id );

        // sync_roles_on_save finds previously linked users via cmm_home_id meta
        // and strips their plugin-managed roles + clears their meta cleanly.
        CMM_Roles::sync_roles_on_save( $home_id );

        if ( $conflict_uid ) {
            $conflict_user = get_userdata( $conflict_uid );
            if ( $conflict_user ) {
                $conflict_user->remove_role( 'pending_applicant' );
                CMM_Roles::clear_home_meta( $conflict_uid );
                delete_user_meta( $conflict_uid, 'cmm_assigned_role' );
            }
        }

        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=reset' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // REST endpoint — address typeahead
    //
    // Returns inactive, expired, and active homes so renewals can locate
    // their existing home. Legacy statuses (pending_review,
    // approved_pending_payment, rejected) are excluded so the dropdown
    // doesn't surface stuck records.
    // -------------------------------------------------------------------------

    public static function register_endpoints() {
        register_rest_route( 'cmm/v1', '/addresses', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'address_search' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public static function address_search( WP_REST_Request $request ): array {
        $search = $request->get_param( 'q' );
        if ( strlen( $search ) < 2 ) return [];

        $homes = get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => 10,
            's'              => $search,
            'meta_query'     => [ [
                'key'     => 'membership_status',
                'value'   => [ 'inactive', 'expired', 'active' ],
                'compare' => 'IN',
            ] ],
        ] );

        return array_map( fn( $h ) => [
            'id'           => $h->ID,
            'address'      => $h->post_title,
            'address_code' => get_field( 'address_code', $h->ID ),
        ], $homes );
    }

    // -------------------------------------------------------------------------
    // Enqueue typeahead JS on frontend
    // -------------------------------------------------------------------------

    public static function enqueue_typeahead() {
        if ( is_admin() ) return;
        wp_enqueue_script(
            'cmm-address-typeahead',
            CMM_URL . 'assets/js/cmm-address-typeahead.js',
            [],
            CMM_VERSION,
            true
        );
    }
}
