<?php
/**
 * GitHub-based plugin updater.
 *
 * Checks a branch of the GitHub repo for newer versions, then hooks into the
 * WordPress plugin update system so updates appear on the Plugins screen the
 * same way as plugins from WordPress.org.
 */
class CMM_Updater {

    const GITHUB_USER   = 'S-FX-com';
    const GITHUB_REPO   = 'BLT-Community';
    const GITHUB_BRANCH = 'main';

    const TRANSIENT_KEY = 'cmm_remote_version_data';
    const CACHE_HOURS   = 6;

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
        add_filter( 'plugins_api',                            [ __CLASS__, 'plugins_api' ], 10, 3 );
        add_filter( 'upgrader_source_selection',              [ __CLASS__, 'rename_source' ], 10, 4 );
        add_filter( 'http_request_args',                      [ __CLASS__, 'add_auth_header' ], 10, 2 );

        add_action( 'admin_menu',                             [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_save_update_settings',    [ __CLASS__, 'save_settings' ] );
        add_action( 'admin_post_cmm_check_updates',           [ __CLASS__, 'force_check' ] );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Plugin Updates',
            'Updates',
            'manage_options',
            'cmm-updates',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        $token    = get_option( 'cmm_github_token', '' );
        $saved    = isset( $_GET['saved'] );
        $checked  = isset( $_GET['checked'] );
        $remote   = self::get_remote_version();
        $repo_url = sprintf( 'https://github.com/%s/%s/', self::GITHUB_USER, self::GITHUB_REPO );
        $check_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=cmm_check_updates' ),
            'cmm_check_updates'
        );
        ?>
        <div class="wrap">
            <h1>Plugin Updates</h1>

            <?php if ( $saved ): ?>
            <div class="notice notice-success is-dismissible"><p>Update settings saved.</p></div>
            <?php endif; ?>

            <?php if ( $checked ): ?>
            <div class="notice notice-success is-dismissible">
                <p>Update check completed.
                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Open the Plugins screen</a>
                    to see the result.
                </p>
            </div>
            <?php endif; ?>

            <div class="card" style="max-width:760px;padding:14px 24px;">
                <h2 style="margin-top:0;">Automatic Updates</h2>
                <p>This plugin checks GitHub for new versions and shows updates on the WordPress Plugins screen,
                   just like plugins from WordPress.org.</p>

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
                                <?php if ( $remote && version_compare( $remote['version'], CMM_VERSION, '>' ) ): ?>
                                <span style="margin-left:12px;color:#00a32a;font-weight:600;">
                                    New version available: <?php echo esc_html( $remote['version'] ); ?>
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
            </div>
        </div>
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

        // Invalidate caches so the next request uses the new token.
        self::clear_caches();

        wp_redirect( admin_url( 'admin.php?page=cmm-updates&saved=1' ) );
        exit;
    }

    public static function force_check() {
        check_admin_referer( 'cmm_check_updates' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        self::clear_caches();
        wp_update_plugins();

        wp_redirect( admin_url( 'admin.php?page=cmm-updates&checked=1' ) );
        exit;
    }

    private static function clear_caches(): void {
        delete_site_transient( self::TRANSIENT_KEY );
        delete_site_transient( 'update_plugins' );
    }

    // -------------------------------------------------------------------------
    // Remote version lookup
    // -------------------------------------------------------------------------

    /**
     * Fetch and parse the plugin header from the remote branch.
     * Returns array{version: string, download_url: string} or null on failure.
     * Cached for CACHE_HOURS to avoid hitting GitHub on every request.
     */
    private static function get_remote_version(): ?array {
        $cached = get_site_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
            return $cached;
        }
        if ( $cached === 'miss' ) {
            return null;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/blt-community.php',
            self::GITHUB_USER,
            self::GITHUB_REPO,
            self::GITHUB_BRANCH
        );

        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache the miss briefly so a transient failure doesn't hammer the API.
            set_site_transient( self::TRANSIENT_KEY, 'miss', HOUR_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $body, $matches ) ) {
            set_site_transient( self::TRANSIENT_KEY, 'miss', HOUR_IN_SECONDS );
            return null;
        }

        $data = [
            'version'      => trim( $matches[1] ),
            'download_url' => sprintf(
                'https://github.com/%s/%s/archive/refs/heads/%s.zip',
                self::GITHUB_USER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH
            ),
        ];

        set_site_transient( self::TRANSIENT_KEY, $data, self::CACHE_HOURS * HOUR_IN_SECONDS );
        return $data;
    }

    // -------------------------------------------------------------------------
    // WordPress update integration
    // -------------------------------------------------------------------------

    public static function inject_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        $remote = self::get_remote_version();
        if ( ! $remote || version_compare( $remote['version'], CMM_VERSION, '<=' ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( CMM_PATH . 'blt-community.php' );

        $update = (object) [
            'id'           => sprintf( 'github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
            'slug'         => dirname( $plugin_file ),
            'plugin'       => $plugin_file,
            'new_version'  => $remote['version'],
            'url'          => sprintf( 'https://github.com/%s/%s/', self::GITHUB_USER, self::GITHUB_REPO ),
            'package'      => $remote['download_url'],
            'tested'       => get_bloginfo( 'version' ),
            'requires'     => '6.0',
            'requires_php' => '8.0',
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
        ];

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }
        $transient->response[ $plugin_file ] = $update;

        return $transient;
    }

    public static function plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;

        $plugin_file = plugin_basename( CMM_PATH . 'blt-community.php' );
        $slug        = dirname( $plugin_file );

        if ( empty( $args->slug ) || $args->slug !== $slug ) return $result;

        $remote = self::get_remote_version();
        if ( ! $remote ) return $result;

        $repo_url = sprintf( 'https://github.com/%s/%s/', self::GITHUB_USER, self::GITHUB_REPO );

        $info = new stdClass();
        $info->name           = 'Blt Community';
        $info->slug           = $slug;
        $info->version        = $remote['version'];
        $info->author         = '<a href="' . esc_url( $repo_url ) . '">BLT</a>';
        $info->homepage       = $repo_url;
        $info->download_link  = $remote['download_url'];
        $info->trunk          = $remote['download_url'];
        $info->requires       = '6.0';
        $info->requires_php   = '8.0';
        $info->tested         = get_bloginfo( 'version' );
        $info->last_updated   = date( 'Y-m-d' );
        $info->sections       = [
            'description' => 'Home-centric membership management for civic associations and community organizations.',
            'changelog'   => sprintf(
                'See <a href="%s">commit history</a> for changes.',
                esc_url( $repo_url . 'commits/' . self::GITHUB_BRANCH )
            ),
        ];

        return $info;
    }

    /**
     * GitHub source archives unpack as "REPO-BRANCH/", not the plugin slug
     * WordPress expects. Rename the unpacked source directory before WordPress
     * moves it into place.
     */
    public static function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        global $wp_filesystem;

        if ( ! is_object( $upgrader ) ) return $source;
        if ( ! isset( $hook_extra['plugin'] ) ) return $source;
        if ( $hook_extra['plugin'] !== plugin_basename( CMM_PATH . 'blt-community.php' ) ) return $source;

        $expected = trailingslashit( $remote_source ) . 'blt-community/';
        if ( $source === $expected ) return $source;

        if ( $wp_filesystem && $wp_filesystem->move( $source, $expected ) ) {
            return $expected;
        }

        return $source;
    }

    /**
     * Add the GitHub token to outbound requests targeting our repo, so private
     * repos can be cloned/downloaded. No-op when no token is configured.
     */
    public static function add_auth_header( $args, $url ) {
        $token = get_option( 'cmm_github_token', '' );
        if ( ! $token ) return $args;

        $repo_host = 'github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $raw_host  = 'raw.githubusercontent.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $codeload  = 'codeload.github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;

        if ( stripos( $url, $repo_host ) === false
          && stripos( $url, $raw_host )  === false
          && stripos( $url, $codeload )  === false ) {
            return $args;
        }

        if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
            $args['headers'] = [];
        }
        $args['headers']['Authorization'] = 'token ' . $token;

        return $args;
    }
}
