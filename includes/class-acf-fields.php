<?php
/**
 * Registers ACF field group for the Home CPT entirely in code.
 * No JSON import needed — travels with the plugin across all installs.
 */
class CMM_ACF_Fields {

    public static function init() {
        add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
    }

    public static function register_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        acf_add_local_field_group( [
            'key'      => 'group_cmm_home',
            'title'    => 'Home Membership Details',
            'location' => [ [ [
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'cmm_home',
            ] ] ],
            'fields' => [

                [
                    'key'          => 'field_cmm_address_code',
                    'label'        => 'Address Code',
                    'name'         => 'address_code',
                    'type'         => 'text',
                    'instructions' => 'Auto-generated. Edit only to resolve a collision.',
                ],

                [
                    'key'           => 'field_cmm_membership_status',
                    'label'         => 'Membership Status',
                    'name'          => 'membership_status',
                    'type'          => 'radio',
                    'choices'       => [
                        'active'                   => 'Active',
                        'inactive'                 => 'Inactive',
                        'expired'                  => 'Expired',
                        'approved_pending_payment' => 'Approved — Pending Payment',
                        'pending_review'           => 'Pending Admin Review',
                        'rejected'                 => 'Rejected',
                    ],
                    'default_value' => 'inactive',
                    'layout'        => 'horizontal',
                ],

                [
                    'key'            => 'field_cmm_dues_paid_date',
                    'label'          => 'Dues Paid Date',
                    'name'           => 'dues_paid_date',
                    'type'           => 'date_picker',
                    'display_format' => 'F j, Y',
                    'return_format'  => 'Y-m-d',
                ],

                [
                    'key'     => 'field_cmm_dues_amount_paid',
                    'label'   => 'Dues Amount Paid',
                    'name'    => 'dues_amount_paid',
                    'type'    => 'number',
                    'prepend' => '$',
                ],

                [
                    'key'           => 'field_cmm_primary_contact',
                    'label'         => 'Primary Contact',
                    'name'          => 'primary_contact',
                    'type'          => 'user',
                    'multiple'      => 0,
                    'return_format' => 'id',
                ],

                [
                    'key'           => 'field_cmm_linked_users',
                    'label'         => 'Linked Users',
                    'name'          => 'linked_users',
                    'type'          => 'user',
                    'multiple'      => 1,
                    'return_format' => 'id',
                ],

            ],
        ] );
    }
}
