<?php
/**
 * Onboarding wizard that runs after first activation.
 * After completion it becomes the Community Dashboard — the settings home base.
 */
class CMM_Onboarding {

    public static function init() {
        add_action( 'admin_post_cmm_save_settings',   [ __CLASS__, 'save_settings' ] );
        add_action( 'admin_post_cmm_send_test_email', [ __CLASS__, 'send_test_email' ] );
        add_action( 'admin_notices', [ __CLASS__, 'onboarding_notice' ] );
    }

    /** Rendered as the top-level Community menu page. */
    public static function render_page() {
        $complete = get_option( 'cmm_onboarding_complete' );
        if ( ! $complete ) {
            self::render_wizard();
        } else {
            self::render_dashboard();
        }
    }

    // -------------------------------------------------------------------------
    // Wizard (first run)
    // -------------------------------------------------------------------------

    private static function render_wizard() {
        $saved = isset( $_GET['saved'] );
        ?>
        <div class="wrap cmm-onboarding">
            <h1 class="wp-heading-inline">&#127968; Welcome to Blt Community</h1>
            <hr class="wp-header-end">
            <p>Complete this one-time setup to configure your community.</p>

            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Settings saved!</p></div>
            <?php endif; ?>

            <?php self::settings_form( true ); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Dashboard (post-onboarding)
    // -------------------------------------------------------------------------

    private static function render_dashboard() {
        $saved = isset( $_GET['saved'] );
        $name  = get_option( 'cmm_community_name', '' );
        $next  = self::get_next_expiration();

        // Stats for the overview cards
        $total   = wp_count_posts( 'cmm_home' )->publish ?? 0;
        $active  = self::count_by_status( 'active' );
        $expired = self::count_by_status( 'expired' );
        $pending = self::count_by_status( 'pending_review' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">&#127968; <?php echo esc_html( $name ?: 'Community' ); ?> — Dashboard</h1>
            <hr class="wp-header-end">

            <?php
            $cmm_action = sanitize_key( $_GET['cmm_action'] ?? '' );
            $test_to    = sanitize_email( $_GET['cmm_test_to'] ?? '' );
            ?>
            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Settings saved!</p></div>
            <?php elseif ( $cmm_action === 'test_email_sent' ): ?>
            <div class="notice notice-success inline"><p>Test email sent to <strong><?php echo esc_html( $test_to ); ?></strong>.</p></div>
            <?php elseif ( $cmm_action === 'test_email_failed' ): ?>
            <div class="notice notice-error inline"><p>Test email could not be sent. Check your WordPress mail configuration.</p></div>
            <?php endif; ?>

            <div class="cmm-card-row" style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
                <?php self::stat_card( 'Total Homes',    $total,   '#2271b1' ); ?>
                <?php self::stat_card( 'Active Members', $active,  '#00a32a' ); ?>
                <?php self::stat_card( 'Expired',        $expired, '#d63638' ); ?>
                <?php self::stat_card( 'Pending Review', $pending, '#dba617' ); ?>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 20px;margin-bottom:24px;max-width:420px;">
                <strong>Next Dues Expiration:</strong>
                <span style="font-size:1.1em;margin-left:8px;"><?php echo esc_html( $next ); ?></span>
            </div>

            <h2>Settings</h2>
            <?php self::settings_form( false ); ?>

            <hr style="margin:32px 0;">
            <?php self::render_webhook_section(); ?>
        </div>
        <?php
    }

    private static function stat_card( string $label, int $value, string $color ): void {
        printf(
            '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 24px;min-width:140px;text-align:center;">
                <div style="font-size:2em;font-weight:700;color:%s;">%d</div>
                <div style="color:#646970;margin-top:4px;">%s</div>
            </div>',
            esc_attr( $color ),
            $value,
            esc_html( $label )
        );
    }

    private static function count_by_status( string $status ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'cmm_home'
               AND p.post_status = 'publish'
               AND pm.meta_key = 'membership_status'
               AND pm.meta_value = %s",
            $status
        ) );
    }

    // -------------------------------------------------------------------------
    // Shared settings form
    // -------------------------------------------------------------------------

    private static function settings_form( bool $is_wizard ): void {
        $name        = get_option( 'cmm_community_name',    '' );
        $slug        = get_option( 'cmm_community_slug',    '' );
        $dues        = get_option( 'cmm_dues_amount',       0 );
        $email       = get_option( 'cmm_admin_email',       get_option( 'admin_email' ) );
        $payment_url = get_option( 'cmm_payment_url',       home_url( '/membership-payment/' ) );
        $reset_month = get_option( 'cmm_dues_reset_month',  '01' );
        $reset_day   = get_option( 'cmm_dues_reset_day',    '01' );
        $next        = self::get_next_expiration();

        $months = [
            '01' => 'January',  '02' => 'February', '03' => 'March',
            '04' => 'April',    '05' => 'May',       '06' => 'June',
            '07' => 'July',     '08' => 'August',    '09' => 'September',
            '10' => 'October',  '11' => 'November',  '12' => 'December',
        ];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_save_settings' ); ?>
            <input type="hidden" name="action" value="cmm_save_settings">
            <?php if ( $is_wizard ): ?>
            <input type="hidden" name="cmm_wizard" value="1">
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cmm_community_name">Community Name</label></th>
                    <td>
                        <input type="text" id="cmm_community_name" name="cmm_community_name"
                               value="<?php echo esc_attr( $name ); ?>"
                               class="regular-text" required
                               placeholder="West Point Island Civic Association">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_community_slug">Short Slug</label></th>
                    <td>
                        <input type="text" id="cmm_community_slug" name="cmm_community_slug"
                               value="<?php echo esc_attr( $slug ); ?>"
                               class="regular-text" required
                               placeholder="wpica"
                               pattern="[a-z0-9\-]+"
                               title="Lowercase letters, numbers, and hyphens only">
                        <p class="description">Lowercase, no spaces. Used in URLs.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_dues_amount">Default Annual Dues</label></th>
                    <td>
                        <span style="font-size:1.1em;vertical-align:middle;">$</span>
                        <input type="number" id="cmm_dues_amount" name="cmm_dues_amount"
                               value="<?php echo esc_attr( $dues ); ?>"
                               min="0" step="0.01" style="width:120px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_admin_email">Admin Email</label></th>
                    <td>
                        <input type="email" id="cmm_admin_email" name="cmm_admin_email"
                               value="<?php echo esc_attr( $email ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_payment_url">Payment Page URL</label></th>
                    <td>
                        <input type="url" id="cmm_payment_url" name="cmm_payment_url"
                               value="<?php echo esc_attr( $payment_url ); ?>"
                               class="large-text">
                        <p class="description">Full URL of the dues payment page. Used in approval emails as <code>{payment_url}</code>.</p>
                    </td>
                </tr>
            </table>

            <hr>
            <h3>Dues Reset</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cmm_dues_reset_month">Reset Month</label></th>
                    <td>
                        <select id="cmm_dues_reset_month" name="cmm_dues_reset_month">
                            <?php foreach ( $months as $val => $label ): ?>
                            <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $reset_month, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_dues_reset_day">Reset Day</label></th>
                    <td>
                        <select id="cmm_dues_reset_day" name="cmm_dues_reset_day">
                            <?php for ( $d = 1; $d <= 31; $d++ ): ?>
                            <option value="<?php printf( '%02d', $d ); ?>"
                                <?php selected( $reset_day, sprintf( '%02d', $d ) ); ?>>
                                <?php echo $d; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                        <p class="description" style="margin-top:8px;">
                            Next Expiration Date:
                            <strong><?php echo esc_html( $next ); ?></strong>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>
            <h3>Approval Email Template</h3>
            <p style="color:#646970;max-width:640px;">
                This email is sent to applicants when their membership application is approved.
                Use the following placeholders: <code>{first_name}</code>, <code>{last_name}</code>,
                <code>{address}</code>, <code>{dues_amount}</code>, <code>{payment_url}</code>,
                <code>{community_name}</code>, <code>{admin_email}</code>.
            </p>
            <?php
            $default_subject = 'Your {community_name} membership application is approved!';
            $default_body    = "Hi {first_name},\n\n"
                . "Great news! Your application for {address} has been approved.\n\n"
                . "To activate your membership, please complete your dues payment of \${dues_amount}:\n"
                . "{payment_url}\n\n"
                . "Once payment is confirmed, your account will be fully activated.\n\n"
                . "Questions? Reply to this email or contact {admin_email}.\n\n"
                . "Thank you,\n{community_name}";
            $email_subject = get_option( 'cmm_approval_email_subject', $default_subject );
            $email_body    = get_option( 'cmm_approval_email_body',    $default_body );
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cmm_approval_email_subject">Subject</label></th>
                    <td>
                        <input type="text" id="cmm_approval_email_subject" name="cmm_approval_email_subject"
                               value="<?php echo esc_attr( $email_subject ); ?>"
                               class="large-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_approval_email_body">Body</label></th>
                    <td>
                        <textarea id="cmm_approval_email_body" name="cmm_approval_email_body"
                                  rows="10" class="large-text"><?php echo esc_textarea( $email_body ); ?></textarea>
                        <p class="description">Plain text. Line breaks are preserved.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary"
                       value="<?php echo $is_wizard ? 'Save &amp; Get Started' : 'Save Settings'; ?>">
            </p>
        </form>

        <?php if ( ! $is_wizard ): ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;margin-top:8px;max-width:720px;">
            <h3 style="margin-top:0;">Send Test Email</h3>
            <p style="color:#646970;margin-bottom:12px;">
                Preview the approval email template. Placeholders will be filled with sample data
                (<em>John Doe, 123 Sample Street</em>, and your configured values).
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <?php wp_nonce_field( 'cmm_send_test_email' ); ?>
                <input type="hidden" name="action" value="cmm_send_test_email">
                <label for="cmm_test_email_to" style="font-weight:600;white-space:nowrap;">Send to:</label>
                <input type="email" id="cmm_test_email_to" name="cmm_test_email"
                       value="<?php echo esc_attr( get_option( 'cmm_admin_email', get_option( 'admin_email' ) ) ); ?>"
                       style="min-width:260px;" class="regular-text" required>
                <button type="submit" class="button button-secondary">Send Test</button>
            </form>
        </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Webhook configuration section
    // -------------------------------------------------------------------------

    private static function render_webhook_section(): void {
        $regenerated = isset( $_GET['cmm_secrets_regenerated'] );

        $app_secret  = CMM_Webhooks::get_secret( 'application' );
        $pay_secret  = CMM_Webhooks::get_secret( 'payment' );
        $app_url     = rest_url( 'cmm/v1/webhook/application' );
        $pay_url     = rest_url( 'cmm/v1/webhook/payment' );

        $regen_url   = admin_url( 'admin-post.php' );
        ?>
        <h2>SureForms Webhook Configuration</h2>

        <?php if ( $regenerated ): ?>
        <div class="notice notice-success inline"><p>Secret regenerated. Update the Authorization header in SureForms.</p></div>
        <?php endif; ?>

        <p style="color:#646970;max-width:640px;">
            Point SureForms webhooks at these URLs and add the corresponding secret as an
            <code>Authorization: Bearer &lt;secret&gt;</code> header. Use <strong>POST / JSON</strong>.
        </p>

        <?php
        self::webhook_row(
            'Registration Form — Application Webhook',
            $app_url,
            $app_secret,
            'application',
            $regen_url,
            [
                'address'    => 'Address dropdown — the full address text selected by the applicant (e.g. "196 Pershing Blvd")',
                'email'      => 'Email Address field',
                'first_name' => 'First Name field',
                'last_name'  => 'Last Name field',
            ]
        );

        self::webhook_row(
            'Payment Form — Payment Confirmation Webhook',
            $pay_url,
            $pay_secret,
            'payment',
            $regen_url,
            [
                'home_id' => 'Hidden field — pass through from the registration form',
                'amount'  => 'Payment amount (numeric)',
                'date'    => 'Payment date (YYYY-MM-DD) — defaults to today if omitted',
            ]
        );
        ?>
        <?php
    }

    private static function webhook_row(
        string $title,
        string $url,
        string $secret,
        string $type,
        string $regen_url,
        array  $fields
    ): void {
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:20px;max-width:720px;">
            <h3 style="margin-top:0;"><?php echo esc_html( $title ); ?></h3>

            <table class="form-table" role="presentation" style="margin:0;">
                <tr>
                    <th style="width:160px;padding:6px 10px 6px 0;">Request URL</th>
                    <td>
                        <code style="background:#f6f7f7;padding:4px 8px;border-radius:3px;user-select:all;word-break:break-all;">
                            <?php echo esc_html( $url ); ?>
                        </code>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 10px 6px 0;">Method / Format</th>
                    <td><code>POST</code> &nbsp;/&nbsp; <code>JSON</code></td>
                </tr>
                <tr>
                    <th style="padding:6px 10px 6px 0;">Authorization</th>
                    <td>
                        Add header: <code>Authorization</code> →
                        <code style="background:#f6f7f7;padding:4px 8px;border-radius:3px;user-select:all;">
                            Bearer <?php echo esc_html( $secret ); ?>
                        </code>
                        &nbsp;
                        <form method="post" action="<?php echo esc_url( $regen_url ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'cmm_regenerate_webhook_secret' ); ?>
                            <input type="hidden" name="action"      value="cmm_regenerate_webhook_secret">
                            <input type="hidden" name="secret_type" value="<?php echo esc_attr( $type ); ?>">
                            <button type="submit" class="button button-small"
                                    onclick="return confirm('Regenerate this secret? You will need to update SureForms.');">
                                Regenerate
                            </button>
                        </form>
                    </td>
                </tr>
            </table>

            <p style="margin:14px 0 6px;font-weight:600;">Expected JSON fields (map in SureForms → Add Data Filters):</p>
            <table style="border-collapse:collapse;width:100%;">
                <thead>
                    <tr style="background:#f6f7f7;">
                        <th style="padding:6px 12px;text-align:left;border:1px solid #ddd;width:140px;">Field key</th>
                        <th style="padding:6px 12px;text-align:left;border:1px solid #ddd;">Map to</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $fields as $key => $description ): ?>
                    <tr>
                        <td style="padding:6px 12px;border:1px solid #ddd;font-family:monospace;"><?php echo esc_html( $key ); ?></td>
                        <td style="padding:6px 12px;border:1px solid #ddd;color:#646970;"><?php echo esc_html( $description ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save handler
    // -------------------------------------------------------------------------

    public static function save_settings() {
        check_admin_referer( 'cmm_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        update_option( 'cmm_community_name',    sanitize_text_field( $_POST['cmm_community_name'] ?? '' ) );
        update_option( 'cmm_community_slug',    sanitize_key( $_POST['cmm_community_slug'] ?? '' ) );
        update_option( 'cmm_dues_amount',       (float) ( $_POST['cmm_dues_amount'] ?? 0 ) );
        update_option( 'cmm_admin_email',       sanitize_email( $_POST['cmm_admin_email'] ?? '' ) );
        update_option( 'cmm_payment_url',       esc_url_raw( $_POST['cmm_payment_url'] ?? '' ) );
        update_option( 'cmm_dues_reset_month',  sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_month'] ?? 1 ) ) );
        update_option( 'cmm_dues_reset_day',    sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_day'] ?? 1 ) ) );
        update_option( 'cmm_approval_email_subject', sanitize_text_field( $_POST['cmm_approval_email_subject'] ?? '' ) );
        update_option( 'cmm_approval_email_body',    sanitize_textarea_field( $_POST['cmm_approval_email_body'] ?? '' ) );

        if ( ! empty( $_POST['cmm_wizard'] ) ) {
            update_option( 'cmm_onboarding_complete', true );
        }

        wp_redirect( admin_url( 'admin.php?page=community-membership&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Test email handler
    // -------------------------------------------------------------------------

    public static function send_test_email() {
        check_admin_referer( 'cmm_send_test_email' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $to = sanitize_email( $_POST['cmm_test_email'] ?? '' );
        if ( ! $to ) {
            wp_redirect( admin_url( 'admin.php?page=community-membership&cmm_action=test_email_failed' ) );
            exit;
        }

        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $dues        = number_format( (float) get_option( 'cmm_dues_amount', 0 ), 2 );
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
            '{first_name}'     => 'John',
            '{last_name}'      => 'Doe',
            '{address}'        => '123 Sample Street',
            '{dues_amount}'    => $dues,
            '{payment_url}'    => $payment_url,
            '{community_name}' => $community,
            '{admin_email}'    => $admin_email,
        ];

        $subject = '[TEST] ' . str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

        $sent   = wp_mail( $to, $subject, $body, [ "From: {$community} <{$admin_email}>" ] );
        $result = $sent ? 'test_email_sent' : 'test_email_failed';

        wp_redirect( admin_url( 'admin.php?page=community-membership&cmm_action=' . $result . '&cmm_test_to=' . rawurlencode( $to ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Admin notice — nudge if onboarding not done
    // -------------------------------------------------------------------------

    public static function onboarding_notice() {
        if ( get_option( 'cmm_onboarding_complete' ) ) return;
        $setup_url = admin_url( 'admin.php?page=community-membership' );
        echo '<div class="notice notice-info"><p>';
        echo '<strong>Blt Community:</strong> Complete the one-time setup to get started. ';
        echo '<a href="' . esc_url( $setup_url ) . '">Run Setup &rarr;</a>';
        echo '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Next expiration date helper (used by other classes)
    // -------------------------------------------------------------------------

    public static function get_next_expiration(): string {
        $month = (int) get_option( 'cmm_dues_reset_month', 1 );
        $day   = (int) get_option( 'cmm_dues_reset_day',   1 );

        $this_year = mktime( 0, 0, 0, $month, $day, (int) date( 'Y' ) );
        $next_year = mktime( 0, 0, 0, $month, $day, (int) date( 'Y' ) + 1 );

        return date( 'F j, Y', $this_year > time() ? $this_year : $next_year );
    }
}
