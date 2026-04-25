<?php
/**
 * Registers the CMM Address Lookup block with SureForms via the
 * srfm_register_additional_blocks filter.
 *
 * SureForms' Register class reads each entry's 'dir' glob, loads the PHP
 * files it finds, resolves the class name from the directory segment
 * (hyphens → underscores → PascalCase), appends \Block, and calls register().
 *
 * Directory: blocks/address-lookup/  →  class: BltCommunity\Blocks\Address_Lookup\Block
 */
class CMM_Block {

    public static function init() {
        add_filter( 'srfm_register_additional_blocks', [ __CLASS__, 'register_blocks' ] );
    }

    public static function register_blocks( array $blocks ): array {
        $blocks[] = [
            'dir'       => CMM_PATH . 'blocks/**/*.php',
            'namespace' => 'BltCommunity\\Blocks',
        ];
        return $blocks;
    }
}
