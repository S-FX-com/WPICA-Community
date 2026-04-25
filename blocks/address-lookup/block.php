<?php
/**
 * CMM Address Lookup block — SureForms-compatible implementation.
 *
 * Namespace segment must match the directory name with hyphens → underscores.
 * Directory: blocks/address-lookup/ → segment: Address_Lookup
 * Full class:  BltCommunity\Blocks\Address_Lookup\Block
 *
 * SureForms discovers this via the srfm_register_additional_blocks filter,
 * instantiates the class, and calls register() → register_block_type_from_metadata()
 * using get_dir() (the directory of THIS file), then calls render() as the callback.
 */

namespace BltCommunity\Blocks\Address_Lookup;

use SRFM\Inc\Blocks\Base;

class Block extends Base {

    /**
     * Render callback — called by WordPress when the block appears on the frontend.
     *
     * @param array $attributes Block attributes from the editor.
     * @return string           HTML output.
     */
    public function render( $attributes ) {
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

            <div id="cmm-address-dropdown"
                 style="display:none;position:absolute;top:100%;left:0;right:0;
                        background:#fff;border:1px solid #ccc;
                        border-radius:0 0 4px 4px;
                        max-height:200px;overflow-y:auto;z-index:999;
                        box-shadow:0 4px 8px rgba(0,0,0,.08);">
            </div>

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
