<?php
/**
 * Address code generation, collision detection, and override management.
 *
 * Algorithm (example):
 *   "4 West Shore Rd"  → WSH4
 *   "2 Bullard Dr"     → BUL2
 *   "15 Oak Avenue"    → OAK15
 */
class CMM_Address_Codes {

    private static array $directionals = [
        'east'  => 'E', 'west'  => 'W', 'north' => 'N', 'south' => 'S',
        'e'     => 'E', 'w'     => 'W', 'n'     => 'N', 's'     => 'S',
    ];

    private static array $suffixes = [
        'rd', 'dr', 'ave', 'st', 'way', 'ct',
        'ln', 'blvd', 'pl', 'ter', 'trl', 'cir',
    ];

    public static function init() {
        add_action( 'save_post_cmm_home',                  [ __CLASS__, 'generate_on_save' ], 10, 2 );
        add_action( 'admin_notices',                        [ __CLASS__, 'collision_notice' ] );
        add_action( 'admin_menu',                           [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_save_codes',            [ __CLASS__, 'save_overrides' ] );
        add_action( 'admin_post_cmm_export_address_codes',  [ __CLASS__, 'export_csv' ] );
    }

    // -------------------------------------------------------------------------
    // Auto-generate on save
    // -------------------------------------------------------------------------

    public static function generate_on_save( int $post_id, WP_Post $post ) {
        if ( wp_is_post_revision( $post_id ) ) return;

        // Never overwrite a manually set code.
        if ( get_field( 'address_code', $post_id ) ) return;

        $code = self::generate( $post->post_title );
        update_field( 'address_code', $code, $post_id );
        self::check_collisions();
    }

    // -------------------------------------------------------------------------
    // Code generation
    // -------------------------------------------------------------------------

    public static function generate( string $address ): string {
        $tokens = preg_split( '/\s+/', trim( $address ) );
        $house  = is_numeric( $tokens[0] ) ? $tokens[0] : '';
        $words  = is_numeric( $tokens[0] ) ? array_slice( $tokens, 1 ) : $tokens;

        // Remove street suffix
        $words = array_values( array_filter(
            $words,
            fn( $w ) => ! in_array( strtolower( $w ), self::$suffixes, true )
        ) );

        $first = strtolower( $words[0] ?? '' );

        if ( isset( self::$directionals[ $first ] ) && isset( $words[1] ) ) {
            $dir    = self::$directionals[ $first ];
            $next   = strtoupper( substr( $words[1], 0, 2 ) );
            $prefix = $dir . $next;
        } else {
            $prefix = strtoupper( substr( $words[0] ?? 'UNK', 0, 3 ) );
        }

        return $prefix . $house;
    }

    // -------------------------------------------------------------------------
    // Collision detection
    // -------------------------------------------------------------------------

    public static function check_collisions(): array {
        $homes      = get_posts( [ 'post_type' => 'cmm_home', 'posts_per_page' => -1 ] );
        $code_map   = [];
        $collisions = [];

        foreach ( $homes as $home ) {
            $code = get_field( 'address_code', $home->ID );
            if ( ! $code ) continue;

            if ( isset( $code_map[ $code ] ) ) {
                if ( ! isset( $collisions[ $code ] ) ) {
                    $collisions[ $code ] = [ $code_map[ $code ] ];
                }
                $collisions[ $code ][] = $home->ID;
            } else {
                $code_map[ $code ] = $home->ID;
            }
        }

        update_option( 'cmm_code_collisions', $collisions );
        return $collisions;
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    public static function collision_notice() {
        $collisions = get_option( 'cmm_code_collisions', [] );
        if ( empty( $collisions ) ) return;

        $count        = count( $collisions );
        $settings_url = admin_url( 'admin.php?page=cmm-address-codes' );
        $codes        = implode( ', ', array_keys( $collisions ) );

        echo '<div class="notice notice-error"><p>';
        echo '<strong>Blt Community:</strong> ' . $count . ' address code collision(s) detected: ';
        echo '<strong>' . esc_html( $codes ) . '</strong>. ';
        echo '<a href="' . esc_url( $settings_url ) . '">Resolve in Address Code Settings &rarr;</a>';
        echo '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Address Codes',
            'Address Codes',
            'manage_options',
            'cmm-address-codes',
            [ __CLASS__, 'render_settings' ]
        );
    }

    public static function render_settings() {
        $saved      = isset( $_GET['saved'] );
        $homes      = get_posts( [ 'post_type' => 'cmm_home', 'posts_per_page' => -1 ] );
        $collisions = get_option( 'cmm_code_collisions', [] );

        $all_codes = [];
        foreach ( $homes as $h ) {
            $all_codes[ $h->ID ] = get_field( 'address_code', $h->ID );
        }

        // Build a flat set of post IDs involved in any collision for quick lookup.
        $collision_ids = [];
        foreach ( $collisions as $ids ) {
            foreach ( $ids as $id ) {
                $collision_ids[ $id ] = true;
            }
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Address Code Manager</h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'cmm_export_address_codes' ); ?>
                <input type="hidden" name="action" value="cmm_export_address_codes">
                <button type="submit" class="page-title-action">&#8595; Export CSV</button>
            </form>
            <hr class="wp-header-end">

            <?php if ( $saved ): ?>
            <div class="notice notice-success inline"><p>Codes saved successfully.</p></div>
            <?php endif; ?>

            <?php if ( $collisions ): ?>
            <div class="notice notice-error inline">
                <p>
                    <strong><?php echo count( $collisions ); ?> collision(s):</strong>
                    <?php echo esc_html( implode( ', ', array_keys( $collisions ) ) ); ?>.
                    Override the conflicting codes below and save.
                </p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cmm_save_codes' ); ?>
                <input type="hidden" name="action" value="cmm_save_codes">

                <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th>Current Code</th>
                            <th>Override</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $homes as $home ):
                        $code         = $all_codes[ $home->ID ] ?? '';
                        $is_collision = isset( $collision_ids[ $home->ID ] );
                    ?>
                    <tr <?php if ( $is_collision ) echo 'style="background:#fff3f3;"'; ?>>
                        <td><?php echo esc_html( $home->post_title ); ?></td>
                        <td><code><?php echo esc_html( $code ); ?></code></td>
                        <td>
                            <input type="text"
                                   name="codes[<?php echo absint( $home->ID ); ?>]"
                                   value="<?php echo esc_attr( $code ); ?>"
                                   style="width:120px;font-family:monospace;">
                        </td>
                        <td>
                            <?php if ( $is_collision ): ?>
                                <span style="color:#d63638;">&#9888; Collision</span>
                            <?php else: ?>
                                <span style="color:#00a32a;">&#10003; OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save All Codes">
                </p>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save overrides
    // -------------------------------------------------------------------------

    public static function save_overrides() {
        check_admin_referer( 'cmm_save_codes' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $codes = $_POST['codes'] ?? [];
        foreach ( $codes as $post_id => $code ) {
            update_field( 'address_code', sanitize_text_field( $code ), (int) $post_id );
        }
        self::check_collisions();

        wp_redirect( admin_url( 'admin.php?page=cmm-address-codes&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // CSV export — Address, Code, Collision status
    // -------------------------------------------------------------------------

    public static function export_csv() {
        check_admin_referer( 'cmm_export_address_codes' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $homes      = get_posts( [ 'post_type' => 'cmm_home', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $collisions = get_option( 'cmm_code_collisions', [] );

        $collision_ids = [];
        foreach ( $collisions as $ids ) {
            foreach ( $ids as $id ) {
                $collision_ids[ $id ] = true;
            }
        }

        $filename = 'cmm-address-codes-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $fh = fopen( 'php://output', 'w' );
        fputcsv( $fh, [ 'Address', 'Code', 'Code Status' ] );

        foreach ( $homes as $home ) {
            $code   = get_field( 'address_code', $home->ID ) ?: '';
            $status = isset( $collision_ids[ $home->ID ] ) ? 'Collision' : 'OK';
            fputcsv( $fh, [ $home->post_title, $code, $status ] );
        }

        fclose( $fh );
        exit;
    }
}
