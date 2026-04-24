<?php
/**
 * Template: Member-facing My Home dashboard.
 * Variables available: $home (WP_Post), $user_id (int), $status (string)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$invited    = isset( $_GET['cmm_invited'] ) ? true : false;
$joined     = isset( $_GET['cmm_joined'] )  ? true : false;
$cmm_error  = sanitize_key( $_GET['cmm_error'] ?? '' );

$code     = get_field( 'address_code',    $home->ID );
$due_date = get_field( 'dues_paid_date',  $home->ID );
$amount   = get_field( 'dues_amount_paid', $home->ID );
$primary  = (int) get_field( 'primary_contact', $home->ID );
$linked   = get_field( 'linked_users', $home->ID ) ?: [];
$is_admin = ( $user_id === $primary );

$status_labels = [
    'active'                   => [ 'label' => 'Active',            'color' => '#00a32a' ],
    'inactive'                 => [ 'label' => 'Inactive',          'color' => '#646970' ],
    'expired'                  => [ 'label' => 'Expired',           'color' => '#d63638' ],
    'approved_pending_payment' => [ 'label' => 'Awaiting Payment',  'color' => '#dba617' ],
    'pending_review'           => [ 'label' => 'Pending Review',    'color' => '#2271b1' ],
    'rejected'                 => [ 'label' => 'Rejected',          'color' => '#d63638' ],
];
$status_info = $status_labels[ $status ] ?? [ 'label' => ucfirst( $status ), 'color' => '#646970' ];
?>

<div class="cmm-dashboard">

    <?php if ( $invited ): ?>
    <div class="cmm-notice cmm-notice-success">Invite sent successfully!</div>
    <?php elseif ( $joined ): ?>
    <div class="cmm-notice cmm-notice-success">You have joined the home. Welcome!</div>
    <?php elseif ( $cmm_error === 'invalid_email' ): ?>
    <div class="cmm-notice cmm-notice-error">Please enter a valid email address for the invite.</div>
    <?php endif; ?>

    <!-- Home Header -->
    <div class="cmm-home-header">
        <div>
            <h2 class="cmm-address"><?php echo esc_html( $home->post_title ); ?></h2>
            <?php if ( $code ): ?>
            <span class="cmm-code">Code: <?php echo esc_html( $code ); ?></span>
            <?php endif; ?>
        </div>
        <span class="cmm-status-badge" style="background:<?php echo esc_attr( $status_info['color'] ); ?>;">
            <?php echo esc_html( $status_info['label'] ); ?>
        </span>
    </div>

    <?php if ( $status === 'pending_review' ): ?>
    <!-- ------------------------------------------------------------------ -->
    <!-- Pending applicant view                                              -->
    <!-- ------------------------------------------------------------------ -->
    <div class="cmm-card">
        <p>Your application is under review. You will receive an email once a decision has been made.</p>
        <p>Questions? Contact us at
            <a href="mailto:<?php echo esc_attr( get_option( 'cmm_admin_email' ) ); ?>">
                <?php echo esc_html( get_option( 'cmm_admin_email' ) ); ?>
            </a>.
        </p>
    </div>

    <?php elseif ( in_array( $status, [ 'active', 'expired', 'approved_pending_payment' ], true ) ): ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Active / Expired / Awaiting Payment                                  -->
    <!-- ------------------------------------------------------------------ -->
    <div class="cmm-card">
        <h3>Membership Details</h3>
        <table class="cmm-info-table">
            <?php if ( $due_date ): ?>
            <tr>
                <th>Dues Paid Date</th>
                <td><?php echo esc_html( date( 'F j, Y', strtotime( $due_date ) ) ); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ( $amount ): ?>
            <tr>
                <th>Amount Paid</th>
                <td>$<?php echo esc_html( number_format( (float) $amount, 2 ) ); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ( $status === 'expired' ): ?>
        <p style="color:#d63638;">Your membership has expired. Please renew to restore access.</p>
        <a href="<?php echo esc_url( home_url( '/membership-payment/' ) ); ?>"
           class="cmm-btn cmm-btn-primary">Renew Membership</a>
        <?php elseif ( $status === 'approved_pending_payment' ): ?>
        <p style="color:#dba617;">Your application is approved! Complete payment to activate membership.</p>
        <a href="<?php echo esc_url( home_url( '/membership-payment/' ) ); ?>"
           class="cmm-btn cmm-btn-primary">Complete Payment</a>
        <?php endif; ?>
    </div>

    <!-- Members list -->
    <div class="cmm-card">
        <h3>Household Members</h3>
        <?php if ( $linked ): ?>
        <ul class="cmm-member-list">
            <?php foreach ( $linked as $uid ):
                $uid  = (int) $uid;
                $u    = get_userdata( $uid );
                if ( ! $u ) continue;
                $role = ( $uid === $primary ) ? 'Home Admin' : 'Member';
            ?>
            <li class="cmm-member-item">
                <span class="cmm-member-name">
                    <?php echo esc_html( $u->display_name ); ?>
                    <small style="color:#646970;">(<?php echo esc_html( $role ); ?>)</small>
                </span>
                <?php if ( $is_admin && $uid !== $primary ): ?>
                <button class="cmm-btn cmm-btn-small cmm-remove-user"
                        data-home-id="<?php echo absint( $home->ID ); ?>"
                        data-user-id="<?php echo absint( $uid ); ?>">
                    Remove
                </button>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p style="color:#646970;">No members linked yet.</p>
        <?php endif; ?>
    </div>

    <!-- Invite form (home admin only) -->
    <?php if ( $is_admin && $status === 'active' ): ?>
    <div class="cmm-card">
        <h3>Invite a Household Member</h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              class="cmm-invite-form">
            <?php wp_nonce_field( 'cmm_send_invite' ); ?>
            <input type="hidden" name="action"  value="cmm_send_invite">
            <input type="hidden" name="home_id" value="<?php echo absint( $home->ID ); ?>">

            <div class="cmm-field-group">
                <label for="invite_name">Name</label>
                <input type="text" id="invite_name" name="invite_name"
                       placeholder="Jane Smith" required>
            </div>
            <div class="cmm-field-group">
                <label for="invite_email">Email</label>
                <input type="email" id="invite_email" name="invite_email"
                       placeholder="jane@example.com" required>
            </div>
            <button type="submit" class="cmm-btn cmm-btn-primary">Send Invite</button>
            <p class="cmm-hint">Invite link is valid for 7 days.</p>
        </form>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Inactive / rejected / unknown -->
    <div class="cmm-card">
        <p>Your membership is not currently active.
           <a href="<?php echo esc_url( home_url( '/register/' ) ); ?>">Apply for membership</a>
           or contact
           <a href="mailto:<?php echo esc_attr( get_option( 'cmm_admin_email' ) ); ?>">
               <?php echo esc_html( get_option( 'cmm_admin_email' ) ); ?>
           </a> for assistance.
        </p>
    </div>
    <?php endif; ?>

</div><!-- .cmm-dashboard -->
