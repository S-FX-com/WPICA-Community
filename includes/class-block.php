<?php
/**
 * Registers the "CMM Address Lookup" Gutenberg block.
 *
 * The block is server-side rendered so it always reflects the current
 * REST endpoint and styles — no stale save() markup issues.
 *
 * Usage in SureForms (or any Gutenberg editor):
 *   Search for "CMM Address Lookup" or "Address Lookup" in the block inserter.
 */
class CMM_Block {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        if ( ! function_exists( 'register_block_type' ) ) return;

        register_block_type(
            CMM_PATH . 'blocks/address-lookup',
            [ 'render_callback' => [ __CLASS__, 'render' ] ]
        );
    }

    /**
     * Server-side render callback.
     * Outputs the typeahead markup and enqueues the supporting JS.
     *
     * @param array $attributes Block attributes from the editor.
     */
    public static function render( array $attributes ): string {
        wp_enqueue_script(
            'cmm-address-typeahead',
            CMM_URL . 'assets/js/cmm-address-typeahead.js',
            [],
            CMM_VERSION,
            true
        );
        wp_enqueue_style(
            'cmm-frontend',
            CMM_URL . 'assets/css/cmm-admin.css',
            [],
            CMM_VERSION
        );

        $label       = esc_html( $attributes['label']       ?? 'Your Property Address' );
        $placeholder = esc_attr( $attributes['placeholder'] ?? 'Start typing your address…' );
        $required    = ! empty( $attributes['required'] );
        $req_attr    = $required ? ' required' : '';
        $req_star    = $required ? '<span style="color:#d63638;margin-left:3px;">*</span>' : '';

        ob_start();
        ?>
        <div class="cmm-address-field" style="position:relative;margin-bottom:16px;">

            <label for="cmm-address-input"
                   style="display:block;margin-bottom:4px;font-weight:600;">
                <?php echo $label . $req_star; ?>
            </label>

            <input type="text"
                   id="cmm-address-input"
                   name="cmm_address_text"
                   placeholder="<?php echo $placeholder; ?>"
                   autocomplete="off"
                   <?php echo $req_attr; ?>
                   style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">

            <input type="hidden" id="cmm-home-id" name="cmm_home_id">

            <!-- Dropdown populated by cmm-address-typeahead.js -->
            <div id="cmm-address-dropdown"
                 style="display:none;position:absolute;top:100%;left:0;right:0;
                        background:#fff;border:1px solid #ccc;
                        border-radius:0 0 4px 4px;
                        max-height:200px;overflow-y:auto;z-index:999;
                        box-shadow:0 4px 8px rgba(0,0,0,.08);">
            </div>

            <!-- Address code badge shown after selection -->
            <div id="cmm-address-code-display"
                 style="display:none;margin-top:6px;padding:6px 10px;
                        background:#f0f7ff;border:1px solid #b0d0f0;
                        border-radius:4px;font-family:monospace;
                        font-size:1.1em;color:#2271b1;">
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
