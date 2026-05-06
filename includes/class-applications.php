<?php
/**
 * Gated signup application flow and REST address typeahead endpoint.
 *
 * Flow:
 *   Visitor submits SureForms registration → account created (pending_applicant)
 *   Admin reviews → Approve (approved_pending_payment + payment email)
 *                 → Reject  (rejected + decline email)
 *   Payment confirmed → active + home_admin role
 *
 * Conflict flow (existing member at the address):
 *   Webhook flags home with cmm_has_member_conflict postmeta.
 *   Admin sees both existing member and new applicant, then chooses:
 *     Overwrite  — replace existing primary contact with new applicant
 *     Add        — keep existing primary contact, add new applicant to linked_users
 *     Reject New — discard new application, restore home to its prior status
 */
class CMM_Applications {

    public static function init() {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_typeahead' ] );

        add_action( 'admin_post_cmm_approve_application',        [ __CLASS__, 'approve' ] );
        add_action( 'admin_post_cmm_reject_application',         [ __CLASS__, 'reject' ] );
        add_action( 'admin_post_cmm_resend_payment_email',       [ __CLASS__, 'resend_payment_email' ] );
        add_action( 'admin_post_cmm_reset_home',                 [ __CLASS__, 'reset_home' ] );
        add_action( 'admin_post_cmm_overwrite_conflict',         [ __CLASS__, 'overwrite_conflict' ] );
        add_action( 'admin_post_cmm_add_conflict',               [ __CLASS__, 'add_conflict' ] );
        add_action( 'admin_post_cmm_reject_conflict',            [ __CLASS__, 'reject_conflict' ] );
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
            <?php elseif ( $action_done === 'conflict_overwritten' ): ?>
            <div class="notice notice-success inline"><p>Previous member replaced. Payment email sent to new applicant.</p></div>
            <?php elseif ( $action_done === 'conflict_added' ): ?>
            <div class="notice notice-success inline"><p>New applicant added as co-member. Payment email sent.</p></div>
            <?php elseif ( $action_done === 'conflict_rejected' ): ?>
            <div class="notice notice-warning inline"><p>New application rejected. Address restored to prior status.</p></div>
            <?php elseif ( $action_done === 'needs_conflict_resolution' ): ?>
            <div class="notice notice-warning inline"><p>This application has a member conflict — please use the conflict resolution buttons below.</p></div>
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

                $has_conflict  = $show_actions && (bool) get_post_meta( $home->ID, 'cmm_has_member_conflict', true );
                $conflict_uid  = $has_conflict ? (int) get_post_meta( $home->ID, 'cmm_pending_conflict_user_id', true ) : 0;
                $conflict_user = $conflict_uid ? get_userdata( $conflict_uid ) : null;

                if ( $has_conflict && $conflict_user ):
            ?>
            <tr style="background:#fff8e5;border-left:4px solid #dba617;">
                <td>
                    <strong style="color:#dba617;">&#9888; Member Conflict</strong><br>
                    <small style="color:#646970;">Existing:</small>
                    <?php echo $user ? esc_html( $user->display_name ) : '<em>none</em>'; ?><br>
                    <small style="color:#646970;">New applicant:</small>
                    <?php echo esc_html( $conflict_user->display_name ); ?>
                </td>
                <td>
                    <small style="color:#646970;">Existing:</small>
                    <?php echo $user ? esc_html( $user->user_email ) : '—'; ?><br>
                    <small style="color:#646970;">New applicant:</small>
                    <?php echo esc_html( $conflict_user->user_email ); ?>
                </td>
                <td><?php echo esc_html( $home->post_title ); ?></td>
                <td><code><?php echo esc_html( $code ); ?></code></td>
                <td><?php echo esc_html( $date ); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;vertical-align:top;">
                        <?php wp_nonce_field( 'cmm_overwrite_conflict_' . $home->ID ); ?>
                        <input type="hidden" name="action"  value="cmm_overwrite_conflict">
                        <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                        <div style="margin-bottom:4px;">
                            <label style="font-size:11px;white-space:nowrap;">
                                <input type="checkbox" name="send_email" value="1" checked>
                                Send approval email
                            </label>
                        </div>
                        <button type="submit" class="button button-primary button-small"
                                title="Replace existing member with new applicant">
                            Overwrite Existing
                        </button>
                    </form>
                    &nbsp;
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;vertical-align:top;">
                        <?php wp_nonce_field( 'cmm_add_conflict_' . $home->ID ); ?>
                        <input type="hidden" name="action"  value="cmm_add_conflict">
                        <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                        <div style="margin-bottom:4px;">
                            <label style="font-size:11px;white-space:nowrap;">
                                <input type="checkbox" name="send_email" value="1" checked>
                                Send approval email
                            </label>
                        </div>
                        <button type="submit" class="button button-small"
                                title="Keep existing member and add new applicant as co-member">
                            Add as Co-Member
                        </button>
                    </form>
                    &nbsp;
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'cmm_reject_conflict_' . $home->ID ); ?>
                        <input type="hidden" name="action"  value="cmm_reject_conflict">
                        <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                        <button type="submit" class="button button-small" style="color:#b32d2e;"
                                title="Reject the new application and restore address to its prior status">
                            Reject New
                        </button>
                    </form>
                </td>
            </tr>
            <?php else: ?>
            <tr>
                <td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
                <td><?php echo $user ? esc_html( $user->user_email ) : '—'; ?></td>
                <td><?php echo esc_html( $home->post_title ); ?></td>
                <td><code><?php echo esc_html( $code ); ?></code></td>
                <td><?php echo esc_html( $date ); ?></td>
                <td>
                    <?php if ( $show_actions ): ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;vertical-align:top;">
                            <?php wp_nonce_field( 'cmm_approve_' . $home->ID ); ?>
                            <input type="hidden" name="action"  value="cmm_approve_application">
                            <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">
                            <div style="margin-bottom:4px;">
                                <label style="font-size:11px;white-space:nowrap;">
                                    <input type="checkbox" name="send_email" value="1" checked>
                                    Send approval email
                                </label>
                            </div>
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
            <?php endif; ?>
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

        // Guard: conflict homes must use the dedicated conflict resolution actions.
        if ( get_post_meta( $home_id, 'cmm_has_member_conflict', true ) ) {
            wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=needs_conflict_resolution' ) );
            exit;
        }

        update_field( 'membership_status', 'approved_pending_payment', $home_id );

        $primary = (int) get_field( 'primary_contact', $home_id );
        if ( $primary && ! empty( $_POST['send_email'] ) ) {
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

        // Clear any stale conflict data.
        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

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

        // Clear all linked users including any pending conflict user.
        $conflict_uid = (int) get_post_meta( $home_id, 'cmm_pending_conflict_user_id', true );
        $linked       = get_field( 'linked_users', $home_id ) ?: [];
        $all_users    = array_unique( array_filter( array_merge(
            array_map( fn( $e ) => is_object( $e ) ? $e->ID : (int) $e, $linked ),
            $conflict_uid ? [ $conflict_uid ] : []
        ) ) );

        foreach ( $all_users as $uid ) {
            $user = get_userdata( $uid );
            if ( $user ) {
                $user->remove_role( 'pending_applicant' );
                $user->remove_role( 'home_admin' );
                $user->remove_role( 'home_member' );
                CMM_Roles::clear_home_meta( $uid );
            }
        }

        update_field( 'membership_status', 'inactive', $home_id );
        update_field( 'primary_contact',   '',         $home_id );
        update_field( 'linked_users',      [],         $home_id );
        update_field( 'dues_amount_paid',  '',         $home_id );
        update_field( 'dues_paid_date',    '',         $home_id );

        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=reset' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Conflict resolution — Overwrite existing member with new applicant
    // -------------------------------------------------------------------------

    public static function overwrite_conflict() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_overwrite_conflict_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $conflict_uid = (int) get_post_meta( $home_id, 'cmm_pending_conflict_user_id', true );
        if ( ! $conflict_uid ) {
            wp_redirect( admin_url( 'admin.php?page=cmm-applications' ) );
            exit;
        }

        // Demote and unlink the previous primary contact.
        $old_uid = (int) get_field( 'primary_contact', $home_id );
        if ( $old_uid && $old_uid !== $conflict_uid ) {
            $old_user = get_userdata( $old_uid );
            if ( $old_user ) {
                $old_user->remove_role( 'home_admin' );
                $old_user->remove_role( 'home_member' );
                $old_user->remove_role( 'pending_applicant' );
                CMM_Roles::clear_home_meta( $old_uid );
            }
        }

        // Install the new applicant as sole primary contact and approve.
        update_field( 'primary_contact',   $conflict_uid,              $home_id );
        update_field( 'linked_users',      [ $conflict_uid ],          $home_id );
        update_field( 'membership_status', 'approved_pending_payment', $home_id );

        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        if ( ! empty( $_POST['send_email'] ) ) {
            self::send_payment_email( $conflict_uid, $home_id );
        }

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=conflict_overwritten' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Conflict resolution — Add new applicant as co-member
    // -------------------------------------------------------------------------

    public static function add_conflict() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_add_conflict_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $conflict_uid = (int) get_post_meta( $home_id, 'cmm_pending_conflict_user_id', true );
        if ( ! $conflict_uid ) {
            wp_redirect( admin_url( 'admin.php?page=cmm-applications' ) );
            exit;
        }

        // Add new applicant to linked_users while keeping the existing primary contact.
        $existing_linked = get_field( 'linked_users', $home_id ) ?: [];
        $existing_ids    = array_map( fn( $u ) => is_object( $u ) ? $u->ID : (int) $u, $existing_linked );
        if ( ! in_array( $conflict_uid, $existing_ids, true ) ) {
            $existing_ids[] = $conflict_uid;
        }

        update_field( 'linked_users',      $existing_ids,              $home_id );
        update_field( 'membership_status', 'approved_pending_payment', $home_id );

        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        if ( ! empty( $_POST['send_email'] ) ) {
            self::send_payment_email( $conflict_uid, $home_id );
        }

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=conflict_added' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Conflict resolution — Reject the new application
    // -------------------------------------------------------------------------

    public static function reject_conflict() {
        $home_id = (int) ( $_POST['home_id'] ?? 0 );
        check_admin_referer( 'cmm_reject_conflict_' . $home_id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $conflict_uid = (int) get_post_meta( $home_id, 'cmm_pending_conflict_user_id', true );
        if ( $conflict_uid ) {
            $conflict_user = get_userdata( $conflict_uid );
            if ( $conflict_user ) {
                $conflict_user->remove_role( 'pending_applicant' );
                CMM_Roles::clear_home_meta( $conflict_uid );
                self::send_rejection_email( $conflict_uid, $home_id, '' );
            }
        }

        // Restore the home to whatever status it had before the conflict application.
        $prev_status = get_post_meta( $home_id, 'cmm_conflict_prev_status', true ) ?: 'inactive';
        update_field( 'membership_status', $prev_status, $home_id );

        delete_post_meta( $home_id, 'cmm_has_member_conflict' );
        delete_post_meta( $home_id, 'cmm_pending_conflict_user_id' );
        delete_post_meta( $home_id, 'cmm_conflict_prev_status' );

        wp_redirect( admin_url( 'admin.php?page=cmm-applications&cmm_action=conflict_rejected' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Email helpers
    // -------------------------------------------------------------------------

    private static function send_payment_email( int $user_id, int $home_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $dues        = number_format( (float) get_option( 'cmm_dues_amount', 0 ), 2 );
        $address     = get_the_title( $home_id );
        $payment_url = get_option( 'cmm_payment_url', home_url( '/membership-payment/' ) );

        $default_subject = 'Your {community_name} membership application is approved!';
        $default_body    = "Hi {first_name},\n\n"
            . "Great news! Your application for {address} has been approved.\n\n"
            . "To activate your membership, please complete your dues payment of \${dues_amount}:\n"
            . "{payment_url}\n\n"
            . "Once payment is confirmed, your account will be fully activated.\n\n"
            . "Questions? Reply to this email or contact {admin_email}.\n\n"
            . "Thank you,\n{community_name}";

        $subject_tpl = get_option( 'cmm_approval_email_subject', $default_subject );
        $body_tpl    = get_option( 'cmm_approval_email_body',    $default_body );

        $replacements = [
            '{first_name}'    => $user->first_name,
            '{last_name}'     => $user->last_name,
            '{address}'       => $address,
            '{dues_amount}'   => $dues,
            '{payment_url}'   => $payment_url,
            '{community_name}' => $community,
            '{admin_email}'   => $admin_email,
        ];

        $subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

        wp_mail( $user->user_email, $subject, $body, [ "From: {$community} <{$admin_email}>" ] );
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
