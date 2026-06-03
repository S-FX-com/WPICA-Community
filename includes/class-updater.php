<?php
/**
 * GitHub-based plugin updater.
 *
 * Uses the YahnisElsts plugin-update-checker library to integrate with the
 * WordPress plugin update system. Updates appear on the Plugins screen the
 * same way as plugins from WordPress.org.
 *
 * Library: https://github.com/YahnisElsts/plugin-update-checker
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker as PucPluginUpdateChecker;

class CMM_Updater {

    const GITHUB_USER   = 'S-FX-com';
    const GITHUB_REPO   = 'BLT-Community';
    const GITHUB_BRANCH = 'main';

    /** @var PucPluginUpdateChecker|null */
    private static $checker = null;

    public static function init() {
        self::build_checker();

        add_action( 'admin_post_cmm_save_update_settings', [ __CLASS__, 'save_settings' ] );
        add_action( 'admin_post_cmm_check_updates',        [ __CLASS__, 'force_check' ] );
    }

    // -------------------------------------------------------------------------
    // PUC factory
    // -------------------------------------------------------------------------

    private static function build_checker(): void {
        $loader = CMM_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
        if ( ! file_exists( $loader ) ) {
            return;
        }
        require_once $loader;

        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        self::$checker = PucFactory::buildUpdateChecker(
            sprintf( 'https://github.com/%s/%s/', self::GITHUB_USER, self::GITHUB_REPO ),
            CMM_PATH . 'blt-community.php',
            'blt-community'
        );

        self::$checker->setBranch( self::GITHUB_BRANCH );

        $token = get_option( 'cmm_github_token', '' );
        if ( $token !== '' ) {
            self::$checker->setAuthentication( $token );
        }
    }

    // -------------------------------------------------------------------------
    // Settings-tab renderer
    //
    // The Updates tab on the Settings page calls this. It emits the section
    // contents (heading + form) without a wrap container.
    // -------------------------------------------------------------------------

    public static function render_settings_section(): void {
        $token       = get_option( 'cmm_github_token', '' );
        $remote_info = self::get_remote_info();
        $repo_url    = sprintf( 'https://github.com/%s/%s/', self::GITHUB_USER, self::GITHUB_REPO );
        $check_url   = wp_nonce_url(
            admin_url( 'admin-post.php?action=cmm_check_updates' ),
            'cmm_check_updates'
        );
        ?>
        <h3 style="margin-top:0;">Automatic Updates</h3>
        <p>This plugin checks GitHub for new versions and shows updates on the WordPress Plugins screen,
           just like plugins from WordPress.org. Powered by the
           <a href="https://github.com/YahnisElsts/plugin-update-checker" target="_blank" rel="noopener">
               plugin-update-checker
           </a> library.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cmm_save_update_settings' ); ?>
            <input type="hidden" name="action" value="cmm_save_update_settings">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Installed Version</th>
                    <td>
                        <code style="background:#f6f7f7;padding:4px 8px;border-radius:3px;">
                            <?php echo esc_html( CMM_VERSION ); ?>
                        </code>
                        <?php if ( $remote_info && version_compare( $remote_info['version'], CMM_VERSION, '>' ) ): ?>
                        <span style="margin-left:12px;color:#00a32a;font-weight:600;">
                            New version available: <?php echo esc_html( $remote_info['version'] ); ?>
                        </span>
                        <?php elseif ( $remote_info ): ?>
                        <span style="margin-left:12px;color:#646970;">
                            Up to date (remote: <?php echo esc_html( $remote_info['version'] ); ?>)
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Update Source</th>
                    <td>
                        <code style="background:#f6f7f7;padding:4px 8px;border-radius:3px;user-select:all;">
                            <?php echo esc_url( $repo_url ); ?>
                        </code>
                        <p class="description">
                            Tracking the &ldquo;<?php echo esc_html( self::GITHUB_BRANCH ); ?>&rdquo; branch.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cmm_github_token">GitHub Access Token</label></th>
                    <td>
                        <input type="password" id="cmm_github_token" name="cmm_github_token"
                               value="<?php echo esc_attr( $token ); ?>"
                               class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $token ? str_repeat( '•', 20 ) : ''; ?>">
                        <p class="description">
                            Optional. Only required if the GitHub repository is private. Use a
                            fine-grained token with <strong>Contents: Read-only</strong> access to
                            <code><?php echo esc_html( self::GITHUB_USER . '/' . self::GITHUB_REPO ); ?></code>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Check for Updates</th>
                    <td>
                        <a href="<?php echo esc_url( $check_url ); ?>" class="button">
                            Check Now
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button">
                            Open Plugins Screen
                        </a>
                        <p class="description">
                            WordPress checks for updates automatically. To check immediately, use the
                            &ldquo;Check Now&rdquo; button or visit the Plugins screen.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Save Update Settings</button>
            </p>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save handlers
    // -------------------------------------------------------------------------

    public static function save_settings() {
        check_admin_referer( 'cmm_save_update_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Tokens can contain underscores, letters, digits (e.g. ghp_*, github_pat_*).
        // Preserve the exact characters the user pasted; just trim whitespace.
        $token = trim( wp_unslash( $_POST['cmm_github_token'] ?? '' ) );
        update_option( 'cmm_github_token', $token );

        // Apply the new token to the live checker and clear caches.
        if ( self::$checker ) {
            self::$checker->setAuthentication( $token );
            self::$checker->resetUpdateState();
        }
        delete_site_transient( 'update_plugins' );

        wp_redirect( CMM_Settings::tab_url( 'updates' ) . '&saved=1' );
        exit;
    }

    public static function force_check() {
        check_admin_referer( 'cmm_check_updates' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        if ( self::$checker ) {
            self::$checker->resetUpdateState();
            self::$checker->checkForUpdates();
        }
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        wp_redirect( CMM_Settings::tab_url( 'updates' ) . '&checked=1' );
        exit;
    }

    // -------------------------------------------------------------------------
    // Remote info (for display on the admin page)
    // -------------------------------------------------------------------------

    /**
     * Returns array{version: string} describing the remote release, or null
     * if no update info is available yet.
     */
    private static function get_remote_info(): ?array {
        if ( ! self::$checker ) return null;

        $update = self::$checker->getUpdate();
        if ( $update && ! empty( $update->version ) ) {
            return [ 'version' => $update->version ];
        }

        // No update found means we're either up-to-date or PUC hasn't checked yet.
        // Fall back to the cached state's version if present.
        $state = self::$checker->getUpdateState();
        if ( $state ) {
            $cached = $state->getUpdate();
            if ( $cached && ! empty( $cached->version ) ) {
                return [ 'version' => $cached->version ];
            }
        }

        return null;
    }
}
