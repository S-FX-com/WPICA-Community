<?php
/**
 * Tabbed Settings page — single home for all plugin configuration.
 *
 * Tabs:
 *   general          — community identity, dues, admin email, payment URL, role
 *   emails           — approval / receipt email template + test send
 *   membership-form  — payment mode + confirmation message (post-submit page)
 *   webhooks         — SureForms webhook URLs and bearer secrets
 *   updates          — GitHub update settings (delegates to CMM_Updater)
 *   shortcodes       — read-only directory of frontend shortcodes
 *
 * Field renderers (render_general_fields() etc.) are reused by the
 * Onboarding wizard so the first-run flow and the live Settings page stay in
 * sync without duplication.
 */
class CMM_Settings {

    const PAGE_SLUG = 'cmm-settings';

    const TABS = [
        'general'         => 'General',
        'emails'          => 'Emails',
        'membership-form' => 'Membership Form',
        'webhooks'        => 'Webhooks',
        'updates'         => 'Updates',
        'shortcodes'      => 'Shortcodes',
    ];

    public static function init() {
        add_action( 'admin_menu',                     [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_save_settings',   [ __CLASS__, 'save_settings' ] );
        add_action( 'admin_post_cmm_send_test_email', [ __CLASS__, 'send_test_email' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Settings',
            'Settings',
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Page shell + tab routing
    // -------------------------------------------------------------------------

    public static function render_page() {
        $current = sanitize_key( $_GET['tab'] ?? 'general' );
        if ( ! array_key_exists( $current, self::TABS ) ) {
            $current = 'general';
        }

        $saved   = isset( $_GET['saved'] );
        $checked = isset( $_GET['checked'] );
        $regen   = isset( $_GET['cmm_secrets_regenerated'] );
        $cmm_act = sanitize_key( $_GET['cmm_action'] ?? '' );
        $test_to = sanitize_email( $_GET['cmm_test_to'] ?? '' );
        ?>
        <div class="wrap">
            <h1>Settings</h1>

            <?php if ( $saved ): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <?php if ( $checked ): ?>
            <div class="notice notice-success is-dismissible">
                <p>Update check completed.
                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Open the Plugins screen</a>
                    to see the result.
                </p>
            </div>
            <?php endif; ?>

            <?php if ( $regen ): ?>
            <div class="notice notice-success is-dismissible">
                <p>Secret regenerated. Update the Authorization header in SureForms.</p>
            </div>
            <?php endif; ?>

            <?php if ( $cmm_act === 'test_email_sent' ): ?>
            <div class="notice notice-success is-dismissible">
                <p>Test email sent to <strong><?php echo esc_html( $test_to ); ?></strong>.</p>
            </div>
            <?php elseif ( $cmm_act === 'test_email_failed' ): ?>
            <div class="notice notice-error is-dismissible">
                <p>Test email could not be sent. Check your WordPress mail configuration.</p>
            </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ( self::TABS as $key => $label ): ?>
                <a href="<?php echo esc_url( self::tab_url( $key ) ); ?>"
                   class="nav-tab <?php echo $current === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </h2>

            <div class="cmm-settings-tab" style="background:#fff;padding:20px 24px;border:1px solid #c3c4c7;border-top:none;">
                <?php
                $method = 'render_tab_' . str_replace( '-', '_', $current );
                if ( method_exists( __CLASS__, $method ) ) {
                    self::$method();
                }
                ?>
            </div>
        </div>
        <?php
    }

    public static function tab_url( string $tab ): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
    }

    // -------------------------------------------------------------------------
    // Tab: General
    // -------------------------------------------------------------------------

    private static function render_tab_general(): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_save_settings' ); ?>
            <input type="hidden" name="action"      value="cmm_save_settings">
            <input type="hidden" name="cmm_section" value="general">
            <?php self::render_general_fields(); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
            </p>
        </form>
        <?php
    }

    public static function render_general_fields(): void {
        $name        = get_option( 'cmm_community_name',    '' );
        $slug        = get_option( 'cmm_community_slug',    '' );
        $dues        = get_option( 'cmm_dues_amount',       0 );
        $email       = get_option( 'cmm_admin_email',       get_option( 'admin_email' ) );
        $payment_url = get_option( 'cmm_payment_url',       home_url( '/membership-payment/' ) );
        $reset_month = get_option( 'cmm_dues_reset_month',  '01' );
        $reset_day   = get_option( 'cmm_dues_reset_day',    '01' );
        $member_role = get_option( 'cmm_approved_role',     'home_member' );
        $next        = CMM_Onboarding::get_next_expiration();
        $all_roles   = wp_roles()->get_names();

        $months = [
            '01' => 'January',  '02' => 'February', '03' => 'March',
            '04' => 'April',    '05' => 'May',       '06' => 'June',
            '07' => 'July',     '08' => 'August',    '09' => 'September',
            '10' => 'October',  '11' => 'November',  '12' => 'December',
        ];
        ?>
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
                    <p class="description">Full URL of the dues payment page. Used in the welcome / receipt email as <code>{payment_url}</code>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cmm_approved_role">Approved Member Role</label></th>
                <td>
                    <select id="cmm_approved_role" name="cmm_approved_role">
                        <?php foreach ( $all_roles as $role_key => $role_label ): ?>
                        <option value="<?php echo esc_attr( $role_key ); ?>"
                            <?php selected( $member_role, $role_key ); ?>>
                            <?php echo esc_html( translate_user_role( $role_label ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        WordPress role assigned to linked users when their home becomes <strong>Active</strong>.
                        The primary contact additionally receives the built-in <code>home_admin</code> role
                        (which grants the ability to invite or remove household members).
                    </p>
                </td>
            </tr>
        </table>

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
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Emails
    // -------------------------------------------------------------------------

    private static function render_tab_emails(): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_save_settings' ); ?>
            <input type="hidden" name="action"      value="cmm_save_settings">
            <input type="hidden" name="cmm_section" value="emails">
            <?php self::render_emails_fields(); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
            </p>
        </form>

        <hr style="margin:32px 0;">
        <?php self::render_test_email_widget(); ?>
        <?php
    }

    public static function render_emails_fields(): void {
        $default_subject = 'Welcome to {community_name} — your membership is active';
        $default_body    = "Hi {first_name},\n\n"
            . "Thank you for your membership at {address}.\n\n"
            . "Payment received: \${amount_paid} on {paid_date}.\n\n"
            . "Your membership is now active. Log in any time to manage your home and household members:\n"
            . "{login_url}\n\n"
            . "Need to set or reset your password? Use this one-time link (valid for 24 hours):\n"
            . "{password_setup_url}\n\n"
            . "Questions? Reply to this email or contact {admin_email}.\n\n"
            . "Thank you,\n{community_name}";
        $email_subject = get_option( 'cmm_approval_email_subject', $default_subject );
        $email_body    = get_option( 'cmm_approval_email_body',    $default_body );
        ?>
        <h3 style="margin-top:0;">Welcome / Receipt Email Template</h3>
        <p style="color:#646970;max-width:640px;">
            This email is sent automatically to members whenever activation or renewal
            completes. Available placeholders:
            <code>{first_name}</code>, <code>{last_name}</code>, <code>{address}</code>,
            <code>{amount_paid}</code>, <code>{paid_date}</code>, <code>{dues_amount}</code>,
            <code>{payment_url}</code>, <code>{login_url}</code>,
            <code>{password_setup_url}</code>, <code>{community_name}</code>,
            <code>{admin_email}</code>.
        </p>
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
        <?php
    }

    private static function render_test_email_widget(): void {
        ?>
        <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:16px 24px;max-width:720px;">
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
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Membership Form
    // -------------------------------------------------------------------------

    private static function render_tab_membership_form(): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_save_settings' ); ?>
            <input type="hidden" name="action"      value="cmm_save_settings">
            <input type="hidden" name="cmm_section" value="membership-form">
            <?php self::render_membership_form_fields(); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
            </p>
        </form>
        <?php
    }

    public static function render_membership_form_fields(): void {
        $payment_mode = get_option( 'cmm_payment_mode', 'confirmation' );
        $default_confirmation = "<h2>Thank you, {first_name}!</h2>\n"
            . "<p>Your membership at <strong>{address}</strong> is now active.</p>\n"
            . "<p>To complete your dues payment of <strong>\${amount}</strong>, please use the PayPal button below:</p>\n"
            . "<!-- Paste your PayPal button HTML here -->\n"
            . "<p style=\"margin-top:24px;color:#646970;\">A receipt has been emailed to {email}. Questions? Contact us at <a href=\"mailto:{admin_email}\">{admin_email}</a>.</p>";
        $confirmation_message = get_option( 'cmm_confirmation_message', $default_confirmation );

        $default_notice_existing = "We found your account (<strong>{display_name}</strong>). We'll add this membership to it. The welcome email includes a password-reset link if needed.";
        $default_notice_new      = "No account yet &mdash; we'll create one when you submit.";
        $notice_existing = get_option( 'cmm_form_notice_existing_account', $default_notice_existing );
        $notice_new      = get_option( 'cmm_form_notice_new_account',      $default_notice_new );

        $custom_css = (string) get_option( 'cmm_form_custom_css', '' );
        ?>
        <h3 style="margin-top:0;">Membership Form &amp; Payment Confirmation</h3>
        <p style="color:#646970;max-width:640px;">
            The <code>[cmm_membership_form]</code> shortcode renders a native multi-step
            membership form. After a member submits, they see the confirmation message
            below (which is where you place your PayPal button or external payment link).
            Available placeholders:
            <code>{first_name}</code>, <code>{last_name}</code>, <code>{address}</code>,
            <code>{amount}</code>, <code>{email}</code>, <code>{community_name}</code>,
            <code>{admin_email}</code>.
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Payment Mode</th>
                <td>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="radio" name="cmm_payment_mode" value="confirmation"
                               <?php checked( $payment_mode, 'confirmation' ); ?>>
                        <strong>Confirmation Message</strong> — show a custom message after
                        submit (paste your PayPal button HTML below).
                    </label>
                    <label style="display:block;color:#646970;">
                        <input type="radio" name="cmm_payment_mode" value="stripe" disabled>
                        <strong>Stripe Checkout</strong>
                        <em style="margin-left:6px;background:#f0f0f1;padding:2px 8px;border-radius:10px;font-size:11px;">
                            Available in Phase 2
                        </em>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cmm_confirmation_message">Confirmation Message</label></th>
                <td>
                    <?php
                    wp_editor( $confirmation_message, 'cmm_confirmation_message', [
                        'textarea_name' => 'cmm_confirmation_message',
                        'textarea_rows' => 12,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'tinymce'       => [
                            'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
                        ],
                        'quicktags'     => true,
                    ] );
                    ?>
                    <p class="description">
                        Switch to the <strong>Text</strong> tab to paste raw HTML
                        (PayPal "Buy Now" button code, iframes, etc.).
                    </p>
                </td>
            </tr>
        </table>

        <h3>Inline Notices on the Account Step</h3>
        <p style="color:#646970;max-width:640px;">
            Shown on Step&nbsp;2 of the signup form once the applicant types an email address.
            Basic inline HTML is allowed (<code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>,
            <code>&lt;a&gt;</code>).
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cmm_form_notice_existing_account">Existing Account</label></th>
                <td>
                    <textarea id="cmm_form_notice_existing_account" name="cmm_form_notice_existing_account"
                              rows="3" class="large-text"><?php echo esc_textarea( $notice_existing ); ?></textarea>
                    <p class="description">
                        Shown when the email matches an existing WordPress user. Placeholder:
                        <code>{display_name}</code>.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cmm_form_notice_new_account">New Account</label></th>
                <td>
                    <textarea id="cmm_form_notice_new_account" name="cmm_form_notice_new_account"
                              rows="3" class="large-text"><?php echo esc_textarea( $notice_new ); ?></textarea>
                    <p class="description">
                        Shown when no existing user is found. The username + password fields
                        appear below this notice.
                    </p>
                </td>
            </tr>
        </table>

        <h3>Custom CSS</h3>
        <p style="color:#646970;max-width:640px;">
            Override the form's appearance without forking the plugin. The form owns its
            own layout; this CSS is loaded <em>after</em> the plugin stylesheet so it wins.
            The cleanest way to re-skin is to override the custom properties exposed on
            <code>.cmm-mf</code>:
        </p>
        <p style="color:#646970;max-width:640px;">
            <code>--cmm-mf-accent</code>,
            <code>--cmm-mf-accent-hover</code>,
            <code>--cmm-mf-text</code>,
            <code>--cmm-mf-label</code>,
            <code>--cmm-mf-muted</code>,
            <code>--cmm-mf-border</code>,
            <code>--cmm-mf-bg</code>,
            <code>--cmm-mf-bg-soft</code>,
            <code>--cmm-mf-radius</code>,
            <code>--cmm-mf-radius-input</code>,
            <code>--cmm-mf-gap</code>,
            <code>--cmm-mf-font</code>.
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cmm_form_custom_css">CSS</label></th>
                <td>
                    <textarea id="cmm_form_custom_css" name="cmm_form_custom_css"
                              rows="10" class="large-text code"
                              spellcheck="false"
                              placeholder=".cmm-mf {&#10;    --cmm-mf-accent: #c00;&#10;    --cmm-mf-radius: 4px;&#10;}"><?php
                        echo esc_textarea( $custom_css );
                    ?></textarea>
                    <p class="description">
                        Plain CSS only. HTML tags are stripped on save.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Webhooks
    // -------------------------------------------------------------------------

    private static function render_tab_webhooks(): void {
        $app_secret = CMM_Webhooks::get_secret( 'application' );
        $pay_secret = CMM_Webhooks::get_secret( 'payment' );
        $app_url    = rest_url( 'cmm/v1/webhook/application' );
        $pay_url    = rest_url( 'cmm/v1/webhook/payment' );
        $regen_url  = admin_url( 'admin-post.php' );

        $unified_fields = [
            'address'    => 'Address dropdown — the full address text (e.g. "196 Pershing Blvd"). Matches the Home post title.',
            'email'      => 'Email Address field. Required for new members; optional for renewals where the existing primary contact pays.',
            'first_name' => 'First Name field (optional).',
            'last_name'  => 'Last Name field (optional).',
            'amount'     => 'Payment amount, numeric (optional but recommended on the payment webhook).',
            'date'       => 'Payment date YYYY-MM-DD (optional — defaults to today).',
        ];
        ?>
        <h3 style="margin-top:0;">SureForms Webhook Configuration</h3>

        <p style="color:#646970;max-width:640px;">
            The two URLs below are <strong>equivalent</strong> — each accepts the same merged
            payload (applicant + payment fields, all individually optional) and activates the
            home immediately. There is no admin approval step. Two endpoints exist only because
            SureForms ties one webhook per form; point your unified membership form at either
            (or both, with the same fields). Use <strong>POST / JSON</strong> with an
            <code>Authorization: Bearer &lt;secret&gt;</code> header.
        </p>

        <?php
        self::render_webhook_row( 'Application URL', $app_url, $app_secret, 'application', $regen_url, $unified_fields );
        self::render_webhook_row( 'Payment URL',     $pay_url, $pay_secret, 'payment',     $regen_url, $unified_fields );
    }

    private static function render_webhook_row(
        string $title,
        string $url,
        string $secret,
        string $type,
        string $regen_url,
        array  $fields
    ): void {
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:20px;max-width:780px;">
            <h4 style="margin-top:0;">Membership Webhook — <?php echo esc_html( $title ); ?></h4>

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
                        Add header: <code>Authorization</code> &rarr;
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

            <p style="margin:14px 0 6px;font-weight:600;">Expected JSON fields (map in SureForms &rarr; Add Data Filters):</p>
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
    // Tab: Updates  (delegates to CMM_Updater)
    // -------------------------------------------------------------------------

    private static function render_tab_updates(): void {
        if ( method_exists( 'CMM_Updater', 'render_settings_section' ) ) {
            CMM_Updater::render_settings_section();
        } else {
            echo '<p>Update settings are not available.</p>';
        }
    }

    // -------------------------------------------------------------------------
    // Tab: Shortcodes  (read-only directory)
    // -------------------------------------------------------------------------

    private static function render_tab_shortcodes(): void {
        $shortcodes = [
            [
                'tag'         => 'cmm_membership_form',
                'title'       => 'Membership Signup Form',
                'description' => 'Renders a native multi-step membership form (Address &rarr; Account &rarr; Details &rarr; Submit). On submit, activates the home and shows the Confirmation Message configured in the <em>Membership Form</em> tab — that is where the PayPal "Buy Now" button HTML lives until Phase 2 ships Stripe Checkout.',
                'usage'       => '[cmm_membership_form]',
                'notes'       => [
                    'Place on a public page accessible to anyone (no login required).',
                    'The form picks the dues amount from the General tab.',
                    'After submit, the same page re-renders with the Confirmation Message.',
                ],
                'related'     => 'Configure the post-submit page in <strong>Settings &rarr; Membership Form</strong>.',
            ],
            [
                'tag'         => 'cmm_my_home',
                'title'       => 'My Home Member Dashboard',
                'description' => 'Frontend dashboard for logged-in members. Shows the linked home, membership status, household members, and (for the primary contact) controls to invite or remove household members.',
                'usage'       => '[cmm_my_home]',
                'notes'       => [
                    'Visitors who are not logged in see a "Log in" link.',
                    'Users with no linked home see an "Apply for membership" link pointing to <code>/register/</code>.',
                    'The primary contact (home_admin role) sees the invite controls.',
                ],
                'related'     => 'Best paired with a page titled "My Home" and a menu link visible only to logged-in users.',
            ],
        ];
        ?>
        <h3 style="margin-top:0;">Frontend Shortcodes</h3>
        <p style="color:#646970;max-width:720px;">
            Add these shortcodes to any page or post to expose membership features on the
            public site. Click the code block to copy it.
        </p>

        <?php foreach ( $shortcodes as $sc ): ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:18px;max-width:820px;">
            <h4 style="margin:0 0 6px 0;font-size:16px;">
                <code style="background:#f6f7f7;padding:3px 8px;border-radius:3px;font-size:14px;">
                    [<?php echo esc_html( $sc['tag'] ); ?>]
                </code>
                <span style="margin-left:10px;color:#1d2327;font-weight:600;">
                    <?php echo esc_html( $sc['title'] ); ?>
                </span>
            </h4>

            <p style="color:#3c434a;margin:8px 0 12px 0;">
                <?php echo wp_kses_post( $sc['description'] ); ?>
            </p>

            <p style="margin:0 0 6px 0;font-weight:600;">Usage</p>
            <p style="margin:0 0 14px 0;">
                <code style="background:#f6f7f7;padding:6px 10px;border-radius:3px;user-select:all;display:inline-block;">
                    <?php echo esc_html( $sc['usage'] ); ?>
                </code>
            </p>

            <?php if ( ! empty( $sc['notes'] ) ): ?>
            <p style="margin:0 0 6px 0;font-weight:600;">Notes</p>
            <ul style="margin:0 0 12px 18px;color:#3c434a;">
                <?php foreach ( $sc['notes'] as $note ): ?>
                <li><?php echo wp_kses_post( $note ); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if ( ! empty( $sc['related'] ) ): ?>
            <p style="margin:0;color:#646970;">
                <?php echo wp_kses_post( $sc['related'] ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save handler — routes by section, updates only that section's keys
    // -------------------------------------------------------------------------

    public static function save_settings() {
        check_admin_referer( 'cmm_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $section = sanitize_key( $_POST['cmm_section'] ?? 'all' );
        $is_wizard = ! empty( $_POST['cmm_wizard'] );

        if ( $section === 'general' || $section === 'all' ) {
            self::save_general();
        }
        if ( $section === 'emails' || $section === 'all' ) {
            self::save_emails();
        }
        if ( $section === 'membership-form' || $section === 'all' ) {
            self::save_membership_form();
        }

        if ( $is_wizard ) {
            update_option( 'cmm_onboarding_complete', true );
            wp_redirect( admin_url( 'admin.php?page=community-membership&saved=1' ) );
            exit;
        }

        $tab = $section === 'all' ? 'general' : $section;
        wp_redirect( self::tab_url( $tab ) . '&saved=1' );
        exit;
    }

    private static function save_general(): void {
        update_option( 'cmm_community_name',   sanitize_text_field( $_POST['cmm_community_name'] ?? '' ) );
        update_option( 'cmm_community_slug',   sanitize_key( $_POST['cmm_community_slug'] ?? '' ) );
        update_option( 'cmm_dues_amount',      (float) ( $_POST['cmm_dues_amount'] ?? 0 ) );
        update_option( 'cmm_admin_email',      sanitize_email( $_POST['cmm_admin_email'] ?? '' ) );
        update_option( 'cmm_payment_url',      esc_url_raw( $_POST['cmm_payment_url'] ?? '' ) );
        update_option( 'cmm_dues_reset_month', sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_month'] ?? 1 ) ) );
        update_option( 'cmm_dues_reset_day',   sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_day'] ?? 1 ) ) );

        $submitted_role = sanitize_key( $_POST['cmm_approved_role'] ?? 'home_member' );
        if ( ! array_key_exists( $submitted_role, wp_roles()->get_names() ) ) {
            $submitted_role = 'home_member';
        }
        update_option( 'cmm_approved_role', $submitted_role );
    }

    private static function save_emails(): void {
        update_option( 'cmm_approval_email_subject', sanitize_text_field( $_POST['cmm_approval_email_subject'] ?? '' ) );
        update_option( 'cmm_approval_email_body',    sanitize_textarea_field( $_POST['cmm_approval_email_body'] ?? '' ) );
    }

    private static function save_membership_form(): void {
        $submitted_mode = sanitize_key( $_POST['cmm_payment_mode'] ?? 'confirmation' );
        update_option( 'cmm_payment_mode', $submitted_mode === 'stripe' ? 'stripe' : 'confirmation' );

        // Raw HTML allowed — admin-only field; rendering uses kses_confirmation().
        update_option( 'cmm_confirmation_message', wp_unslash( $_POST['cmm_confirmation_message'] ?? '' ) );

        // Inline form notices — limited HTML so admins can bold/link text.
        $notice_allowed = [
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'br'     => [],
            'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
        ];
        update_option(
            'cmm_form_notice_existing_account',
            wp_kses( wp_unslash( $_POST['cmm_form_notice_existing_account'] ?? '' ), $notice_allowed )
        );
        update_option(
            'cmm_form_notice_new_account',
            wp_kses( wp_unslash( $_POST['cmm_form_notice_new_account'] ?? '' ), $notice_allowed )
        );

        // Plain CSS only. Strip any HTML/PHP an admin might paste by accident
        // (a stray <script> here would otherwise be served to every visitor).
        update_option(
            'cmm_form_custom_css',
            wp_strip_all_tags( wp_unslash( $_POST['cmm_form_custom_css'] ?? '' ) )
        );
    }

    // -------------------------------------------------------------------------
    // Test email handler
    // -------------------------------------------------------------------------

    public static function send_test_email() {
        check_admin_referer( 'cmm_send_test_email' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $to = sanitize_email( $_POST['cmm_test_email'] ?? '' );
        if ( ! $to ) {
            wp_redirect( self::tab_url( 'emails' ) . '&cmm_action=test_email_failed' );
            exit;
        }

        $community   = get_option( 'cmm_community_name', 'Community' );
        $admin_email = get_option( 'cmm_admin_email', get_option( 'admin_email' ) );
        $dues        = number_format( (float) get_option( 'cmm_dues_amount', 0 ), 2 );
        $payment_url = get_option( 'cmm_payment_url', home_url( '/membership-payment/' ) );

        $default_subject = 'Welcome to {community_name} — your membership is active';
        $default_body    = "Hi {first_name},\n\n"
            . "Thank you for your membership at {address}.\n\n"
            . "Payment received: \${amount_paid} on {paid_date}.\n\n"
            . "Your membership is now active. Log in any time to manage your home and household members:\n"
            . "{login_url}\n\n"
            . "Need to set or reset your password? Use this one-time link (valid for 24 hours):\n"
            . "{password_setup_url}\n\n"
            . "Questions? Reply to this email or contact {admin_email}.\n\n"
            . "Thank you,\n{community_name}";

        $subject_tpl = get_option( 'cmm_approval_email_subject', $default_subject );
        $body_tpl    = get_option( 'cmm_approval_email_body',    $default_body );

        $replacements = [
            '{first_name}'         => 'John',
            '{last_name}'          => 'Doe',
            '{address}'            => '123 Sample Street',
            '{amount_paid}'        => $dues,
            '{paid_date}'          => date( 'Y-m-d' ),
            '{dues_amount}'        => $dues,
            '{payment_url}'        => $payment_url,
            '{login_url}'          => wp_login_url(),
            '{password_setup_url}' => wp_lostpassword_url(),
            '{community_name}'     => $community,
            '{admin_email}'        => $admin_email,
        ];

        $subject = '[TEST] ' . str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

        $sent   = wp_mail( $to, $subject, $body, [ "From: {$community} <{$admin_email}>" ] );
        $result = $sent ? 'test_email_sent' : 'test_email_failed';

        wp_redirect( self::tab_url( 'emails' ) . '&cmm_action=' . $result . '&cmm_test_to=' . rawurlencode( $to ) );
        exit;
    }
}
