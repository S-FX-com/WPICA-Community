<?php
/**
 * Gated signup application flow and REST address typeahead endpoint.
 *
 * Flow:
 *   Visitor submits SureForms registration → account created (pending_applicant)
 *   Admin reviews → Approve (approved_pending_payment + payment email)
 *                 → Reject  (rejected + decline email)
 *   Payment confirmed → active + home_admin role
 */
class CMM_Applications {

    public static function init() {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_typeahead' ] );

        add_action( 'admin_post_cmm_approve_application',  [ __CLASS__, 'approve' ] );
        add_action( 'admin_post_cmm_reject_application',   [ __CLASS__, 'reject' ] );
        add_action( 'admin_post_cmm_resend_payment_email', [ __CLASS__, 'resend_payment_email' ] );
        add_action( 'admin_post_cmm_reset_home',           [ __CLASS__, 'reset_home' ] );
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Applications',
            'Applications',
            'manage_options',
            'cmm-applications',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        $action_done = $_GET['cmm_action'] ?? '';
        ?>
        <div class="wrap">
            <h1>Member Applications</h1>

            <?php if ( $action_done === 'approved' ): ?>
            <div class="notice notice-success inline"><p>Application approved. Payment email sent.</p></div>
            <?php elseif ( $action_done === 'rejected' ): ?>
            <div class="notice notice-warning inline"><p>Application rejected. Decline email sent.</p></div>
            <?php elseif ( $action_done === 'resent' ): ?>
            <div class="notice notice-info inline"><p>Payment email resent.</p></div>
            <?php elseif ( $action_done === 'reset' ): ?>
            <div class="notice notice-warning inline"><p>Home reset to inactive.</p></div>
            <?php endif; ?>

            <?php
            self::render_section( 'Pending Applications',        'pending_review',           true  );
            self::render_section( 'Approved — Awaiting Payment', 'approved_pending_payment', false );
            self::render_section( 'Rejected',                    'rejected',                 false );
            self::render_section( 'Active Members',              'active',                   false );
            ?>
        </div>
        <?php
    }

    private static function render_section( string $heading, string $status, bool $show_actions ) {
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
                    <th>Submitted / Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $homes as $home ):
                $primary  = (int) get_field( 'primary_contact', $home->ID );
                $user     = $primary ? get_userdata( $primary ) : null;
                $code     = get_field( 'address_code', $home->ID );
                $date     = date( 'M j', strtotime( $home->post_modified ) );
            ?>
            <tr>
                <td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
                <td><?php echo $user ? esc_html( $user->user_email ) : '—'; ?></td>
                <td><?php echo esc_html( $home->post_title ); ?></td>
                <td><code><?php echo esc_html( $code ); ?></code></td>
                <td><?php echo esc_html( $date ); ?></td>
                <td>
                    <?php if ( $show_actions ): ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'cmm_approve_' . $home->ID ); ?>
                            <input type="hidden" name="action"  value="cmm_approve_application">
                            <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                            <button type="submit" class="button button-primary button-small">Approve</button>
                        </form>
                        &nbsp;
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'cmm_reject_' . $home->ID ); ?>
                            <input type="hidden" name="action"  value="cmm_reject_application">
                            <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                            <input type="text"   name="reason"  placeholder="Rejection reason (optional)"
                                   style="width:180px;">
                            <button type="submit" class="button button-small">Reject</button>
                        </form>
                        &nbsp;
                    <?php elseif ( $status === 'approved_pending_payment' ): ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'cmm_resend_' . $home->ID ); ?>
                            <input type="hidden" name="action"  value="cmm_resend_payment_email">
                            <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                            <button type="submit" class="button button-small">Resend Payment Email</button>
                        </form>
                        &nbsp;
                    <?php else: ?>
                    <?php endif; ?>
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
    // Approve
    // -------------------------------------------------------------------------

    public static function approve() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_approve_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        update_field( 'membership_status', 'approved_pending_payment', $home_id );

        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( $primary ) {
            self::send_payment_email( $primary, $home_id );
        }

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=approved' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    public static function reject() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_reject_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        update_field( 'membership_status', 'rejected', $home_id );

        $reason  = sanitize_textarea_field( $_POST['reason'] ?? '' );
        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( $primary ) {
            self::send_rejection_email( $primary, $home_id, $reason );
        }

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=rejected' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Resend payment email
    // -------------------------------------------------------------------------

    public static function resend_payment_email() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_resend_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( $primary ) {
            self::send_payment_email( $primary, $home_id );
        }

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=resent' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Reset home to inactive (clears membership and payment data)
    // -------------------------------------------------------------------------

    public static function reset_home() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_reset_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $linked = get_field( 'linked_users', $home_id ) ?: [];
        foreach ( $linked as $entry ) {
            $uid  = is_object( $entry ) ? $entry->ID : (int) $entry;
            $user = get_userdata( $uid );
            if ( $user ) {
                $user->remove_role( 'pending_applicant' );
                $user->remove_role( 'home_admin' );
                delete_user_meta( $uid, 'cmm_home_id' );
                delete_user_meta( $uid, 'cmm_address_code' );
            }
        }

        update_field( 'membership_status', 'inactive', $home_id );
        update_field( 'primary_contact',   '',         $home_id );
        update_field( 'linked_users',      [],         $home_id );
        update_field( 'dues_amount_paid',  '',         $home_id );
        update_field( 'dues_paid_date',    '',         $home_id );

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=reset' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Email helpers
    // -------------------------------------------------------------------------

    private static function send_payment_email( int $user_id, int $home_id ) {
        $user         = get_userdata( $user_id );
        if ( ! $user ) return;

        $community    = get_option( 'cmm_community_name', 'Community' );
        $dues         = number_format( (float) get_option( 'cmm_dues_amount', 0 ), 2 );
        $address      = get_the_title( $home_id );
        $payment_url  = home_url( '/membership-payment/' );
        $admin_email  = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );

        $subject = "Your {$community} membership application is approved!";
        $message = "Hi {$user->first_name},\n\n"
            . "Great news! Your application for {$address} has been approved.\n\n"
            . "To activate your membership, please complete your dues payment of \${$dues}:\n"
            . "{$payment_url}\n\n"
            . "Once payment is confirmed, your account will be fully activated.\n\n"
            . "Questions? Reply to this email or contact {$admin_email}.\n\n"
            . "Thank you,\n{$community}";

        wp_mail( $user->user_email, $subject, $message, [ "From: {$community} <{$admin_email}>" ] );
    }

    private static function send_rejection_email( int $user_id, int $home_id, string $reason ) {
        $user        = get_userdata( $user_id );
        if ( ! $user ) return;

        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $address     = get_the_title( $home_id );

        $subject = "Update on your {$community} membership application";
        $message = "Hi {$user->first_name},\n\n"
            . "Thank you for applying for membership at {$address}.\n\n"
            . "After review, we are unable to approve your application at this time.\n"
            . ( $reason ? "\nReason: {$reason}\n" : '' )
            . "\nIf you believe this is in error, please contact us at {$admin_email}.\n\n"
            . "Thank you,\n{$community}";

        wp_mail( $user->user_email, $subject, $message, [ "From: {$community} <{$admin_email}>" ] );
    }

    // -------------------------------------------------------------------------
    // REST endpoint — address typeahead
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
                'value'   => [ 'inactive', 'expired' ],
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
