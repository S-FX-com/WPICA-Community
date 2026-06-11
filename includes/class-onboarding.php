<?php
/**
 * Onboarding wizard (first run) + Community Dashboard (post-onboarding).
 *
 * The dashboard is a stats overview; all configuration lives on the
 * Settings page (see CMM_Settings). The wizard reuses the General /
 * Emails / Membership Form field renderers so both flows stay in sync.
 */
class CMM_Onboarding {

    public static function init() {
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
            <h1>&#127968; Welcome to WPICA Community</h1>
            <p>Complete this one-time setup to configure your community.
               You can fine-tune everything later from <strong>Community &rarr; Settings</strong>.</p>

            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Settings saved!</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cmm_save_settings' ); ?>
                <input type="hidden" name="action"      value="cmm_save_settings">
                <input type="hidden" name="cmm_section" value="all">
                <input type="hidden" name="cmm_wizard"  value="1">

                <?php CMM_Settings::render_general_fields(); ?>

                <hr>
                <?php CMM_Settings::render_emails_fields(); ?>

                <hr>
                <?php CMM_Settings::render_membership_form_fields(); ?>

                <p class="submit">
                    <input type="submit" class="button button-primary"
                           value="Save &amp; Get Started">
                </p>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Dashboard (post-onboarding) — stats overview only
    // -------------------------------------------------------------------------

    private static function render_dashboard() {
        $saved = isset( $_GET['saved'] );
        $name  = get_option( 'cmm_community_name', '' );
        $next  = self::get_next_expiration();

        $total    = wp_count_posts( 'cmm_home' )->publish ?? 0;
        $active   = self::count_by_status( 'active' );
        $expired  = self::count_by_status( 'expired' );
        $inactive = self::count_by_status( 'inactive' );

        $settings_url = admin_url( 'admin.php?page=' . CMM_Settings::PAGE_SLUG );
        ?>
        <div class="wrap">
            <h1>&#127968; <?php echo esc_html( $name ?: 'Community' ); ?> — Dashboard</h1>

            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Settings saved!</p></div>
            <?php endif; ?>

            <div class="cmm-card-row" style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
                <?php self::stat_card( 'Total Homes',    $total,    '#2271b1' ); ?>
                <?php self::stat_card( 'Active Members', $active,   '#00a32a' ); ?>
                <?php self::stat_card( 'Expired',        $expired,  '#d63638' ); ?>
                <?php self::stat_card( 'Inactive',       $inactive, '#dba617' ); ?>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 20px;margin-bottom:24px;max-width:420px;">
                <strong>Next Dues Expiration:</strong>
                <span style="font-size:1.1em;margin-left:8px;"><?php echo esc_html( $next ); ?></span>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 24px;max-width:720px;">
                <h2 style="margin-top:0;">Quick Links</h2>
                <p style="margin:0;">
                    <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">Settings</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmm-applications' ) ); ?>" class="button">Applications</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmm-reports' ) ); ?>" class="button">Reports</a>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=cmm_home' ) ); ?>" class="button">Homes</a>
                </p>
                <p class="description" style="margin-top:10px;">
                    All plugin configuration — community info, dues, emails, the membership form
                    post-submit page, SureForms webhooks, updates, and the frontend shortcode
                    directory — now lives under
                    <a href="<?php echo esc_url( $settings_url ); ?>"><strong>Community &rarr; Settings</strong></a>.
                </p>
            </div>
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
    // Admin notice — nudge if onboarding not done
    // -------------------------------------------------------------------------

    public static function onboarding_notice() {
        if ( get_option( 'cmm_onboarding_complete' ) ) return;
        $setup_url = admin_url( 'admin.php?page=community-membership' );
        echo '<div class="notice notice-info"><p>';
        echo '<strong>WPICA Community:</strong> Complete the one-time setup to get started. ';
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
