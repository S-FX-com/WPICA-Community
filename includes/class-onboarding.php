<?php
/**
 * Onboarding wizard that runs after first activation.
 * After completion it becomes the Community Dashboard — the settings home base.
 */
class CMM_Onboarding {

    public static function init() {
        add_action( 'admin_post_cmm_save_settings', [ __CLASS__, 'save_settings' ] );
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
            <h1>&#127968; Welcome to Blt Community</h1>
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
            <h1>&#127968; <?php echo esc_html( $name ?: 'Community' ); ?> — Dashboard</h1>

            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Settings saved!</p></div>
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

            <p class="submit">
                <input type="submit" class="button button-primary"
                       value="<?php echo $is_wizard ? 'Save &amp; Get Started' : 'Save Settings'; ?>">
            </p>
        </form>
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
        update_option( 'cmm_dues_reset_month',  sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_month'] ?? 1 ) ) );
        update_option( 'cmm_dues_reset_day',    sprintf( '%02d', (int) ( $_POST['cmm_dues_reset_day'] ?? 1 ) ) );

        if ( ! empty( $_POST['cmm_wizard'] ) ) {
            update_option( 'cmm_onboarding_complete', true );
        }

        wp_redirect( admin_url( 'admin.php?page=community-membership&saved=1' ) );
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
