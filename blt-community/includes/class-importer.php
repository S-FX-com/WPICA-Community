<?php
/**
 * Bulk address paste importer.
 * One address per line → each becomes a Home post with status: inactive.
 */
class CMM_Importer {

    public static function init() {
        add_action( 'admin_menu',                    [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_cmm_import_addresses', [ __CLASS__, 'handle_import' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'community-membership',
            'Address Importer',
            'Address Importer',
            'manage_options',
            'cmm-importer',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        $result = isset( $_GET['imported'] ) ? self::decode_result( $_GET['imported'] ) : null;
        ?>
        <div class="wrap">
            <h1>Address Importer</h1>
            <p>Paste one address per line. Each address becomes a Home record with status <strong>Inactive</strong>
               and an auto-generated address code. Duplicates (matching an existing post title) are skipped.</p>

            <?php if ( $result ): ?>
            <div class="notice notice-success inline" style="margin:12px 0;">
                <p>
                    Import complete.<br>
                    <strong><?php echo absint( $result['created'] ); ?></strong> home(s) created.&nbsp;
                    <strong><?php echo absint( $result['skipped'] ); ?></strong> skipped (already exist).
                    <?php if ( $result['collisions'] > 0 ): ?>
                    <br>&#9888; <strong><?php echo absint( $result['collisions'] ); ?></strong>
                    address code collision(s) detected.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmm-address-codes' ) ); ?>">
                        Resolve now &rarr;
                    </a>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cmm_import_addresses' ); ?>
                <input type="hidden" name="action" value="cmm_import_addresses">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cmm_addresses">Addresses</label></th>
                        <td>
                            <textarea id="cmm_addresses" name="cmm_addresses"
                                      rows="20" style="width:100%;max-width:600px;font-family:monospace;"
                                      placeholder="4 West Shore Rd&#10;2 Bullard Dr&#10;15 Oak Avenue"></textarea>
                            <p class="description">One address per line. Leading/trailing whitespace is trimmed.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Import Addresses">
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_import() {
        check_admin_referer( 'cmm_import_addresses' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $raw       = sanitize_textarea_field( wp_unslash( $_POST['cmm_addresses'] ?? '' ) );
        $lines     = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $created   = 0;
        $skipped   = 0;

        // Build a lookup of existing home titles for duplicate detection.
        $existing = self::get_existing_titles();

        foreach ( $lines as $address ) {
            $address = sanitize_text_field( $address );
            if ( ! $address ) continue;

            $key = strtolower( $address );
            if ( isset( $existing[ $key ] ) ) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'  => $address,
                'post_type'   => 'cmm_home',
                'post_status' => 'publish',
            ] );

            if ( is_wp_error( $post_id ) ) continue;

            update_field( 'membership_status', 'inactive', $post_id );

            $code = CMM_Address_Codes::generate( $address );
            update_field( 'address_code', $code, $post_id );

            $existing[ $key ] = $post_id;
            $created++;
        }

        $collisions = CMM_Address_Codes::check_collisions();

        $result = base64_encode( json_encode( [
            'created'    => $created,
            'skipped'    => $skipped,
            'collisions' => count( $collisions ),
        ] ) );

        wp_redirect( admin_url( 'admin.php?page=cmm-importer&imported=' . $result ) );
        exit;
    }

    private static function get_existing_titles(): array {
        $homes = get_posts( [
            'post_type'      => 'cmm_home',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ] );
        $map = [];
        foreach ( $homes as $home ) {
            $map[ strtolower( $home->post_title ) ] = $home->ID;
        }
        return $map;
    }

    private static function decode_result( string $encoded ): ?array {
        $json = base64_decode( $encoded, true );
        if ( ! $json ) return null;
        $data = json_decode( $json, true );
        return is_array( $data ) ? $data : null;
    }
}
