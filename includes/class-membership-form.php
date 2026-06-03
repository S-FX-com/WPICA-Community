<?php
/**
 * Native multi-step membership form.
 *
 * Shortcode: [cmm_membership_form]
 *
 * Renders a 4-step wizard (Address → Account → Details → Submit) that
 * delegates activation to CMM_Webhooks::process_membership_activation() and
 * then renders an admin-configured Confirmation Message page (which is where
 * the PayPal "Buy Now" button HTML lives until Phase 2 ships Stripe Checkout).
 *
 * REST endpoints (public, rate-limited):
 *   GET /wp-json/cmm/v1/home-status?home_id=N
 *   GET /wp-json/cmm/v1/account-check?email=E
 *   GET /wp-json/cmm/v1/username-check?username=U
 */
class CMM_Membership_Form {

    public static function init() {
        add_shortcode( 'cmm_membership_form', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'rest_api_init',          [ __CLASS__, 'register_endpoints' ] );
        add_action( 'wp_enqueue_scripts',     [ __CLASS__, 'maybe_enqueue_assets' ] );
        add_action( 'admin_post_nopriv_cmm_membership_submit', [ __CLASS__, 'handle_submission' ] );
        add_action( 'admin_post_cmm_membership_submit',        [ __CLASS__, 'handle_submission' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode entry point
    // -------------------------------------------------------------------------

    public static function render_shortcode( $atts ): string {
        if ( isset( $_GET['cmm_activated'], $_GET['home_id'] ) ) {
            return self::render_confirmation( (int) $_GET['home_id'] );
        }
        return self::render_form();
    }

    private static function render_form(): string {
        $dues_amount = (float) get_option( 'cmm_dues_amount', 0 );
        $submit_url  = admin_url( 'admin-post.php' );
        $nonce       = wp_create_nonce( 'cmm_membership_submit' );
        $error       = isset( $_GET['cmm_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cmm_error'] ) ) : '';

        ob_start();
        include CMM_PATH . 'templates/membership-form.php';
        return ob_get_clean();
    }

    private static function render_confirmation( int $home_id ): string {
        $home = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) {
            return '<div class="cmm-mf-notice cmm-mf-error">Activation reference not found.</div>';
        }

        $message = (string) get_option( 'cmm_confirmation_message', '' );
        if ( $message === '' ) {
            $message = "<h2>Thank you, {first_name}!</h2>\n"
                . "<p>Your membership at <strong>{address}</strong> is now active.</p>";
        }

        $message = self::substitute_placeholders( $message, $home_id );

        ob_start();
        ?>
        <div class="cmm-mf-confirmation">
            <?php echo self::kses_confirmation( $message ); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Form submission handler
    // -------------------------------------------------------------------------

    public static function handle_submission(): void {
        $return_url = wp_get_referer() ?: home_url( '/' );

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cmm_membership_submit' ) ) {
            self::redirect_with_error( $return_url, 'Security check failed. Please try again.' );
        }

        $home_id    = (int) ( $_POST['home_id'] ?? 0 );
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $username   = sanitize_user( (string) ( $_POST['username'] ?? '' ), true );
        $password   = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';

        if ( ! $home_id ) {
            self::redirect_with_error( $return_url, 'Please choose your address from the dropdown.' );
        }
        if ( ! $email || ! is_email( $email ) ) {
            self::redirect_with_error( $return_url, 'A valid email address is required.' );
        }
        if ( ! $first_name || ! $last_name ) {
            self::redirect_with_error( $return_url, 'First and last name are required.' );
        }

        // Existing-account branch: username/password not collected from form.
        $existing_user = get_user_by( 'email', $email );

        if ( ! $existing_user ) {
            // New account: enforce username + password locally before activation.
            if ( ! $username || strlen( $username ) < 3 ) {
                self::redirect_with_error( $return_url, 'Username must be at least 3 characters.' );
            }
            if ( username_exists( $username ) ) {
                self::redirect_with_error( $return_url, "Username '{$username}' is already taken." );
            }
            if ( strlen( $password ) < 8 ) {
                self::redirect_with_error( $return_url, 'Password must be at least 8 characters.' );
            }
        }

        $member_data = [
            'mobile'           => sanitize_text_field( $_POST['mobile']            ?? '' ),
            'spouse_first'     => sanitize_text_field( $_POST['spouse_first_name'] ?? '' ),
            'spouse_last'      => sanitize_text_field( $_POST['spouse_last_name']  ?? '' ),
            'children'         => sanitize_textarea_field( $_POST['children']      ?? '' ),
            'directory_listed' => ! empty( $_POST['directory_listed'] ),
            'primary_address'  => [
                'street' => sanitize_text_field( $_POST['primary_street'] ?? '' ),
                'city'   => sanitize_text_field( $_POST['primary_city']   ?? '' ),
                'state'  => sanitize_text_field( $_POST['primary_state']  ?? '' ),
                'zip'    => sanitize_text_field( $_POST['primary_zip']    ?? '' ),
            ],
        ];

        // For new accounts, hand the credentials to the activation helper so
        // wp_create_user() uses the chosen username instead of falling back to
        // email-as-username.
        if ( ! $existing_user ) {
            $member_data['username'] = $username;
            $member_data['password'] = $password;
        }

        $dues_amount = (float) get_option( 'cmm_dues_amount', 0 );

        $result = CMM_Webhooks::process_membership_activation(
            $home_id,
            $email,
            $first_name,
            $last_name,
            $dues_amount,
            date( 'Y-m-d' ),
            $member_data
        );

        if ( isset( $result['error'] ) ) {
            self::redirect_with_error( $return_url, $result['error'] );
        }

        $confirm_url = add_query_arg( [
            'cmm_activated' => '1',
            'home_id'       => $result['home_id'],
        ], $return_url );

        wp_safe_redirect( $confirm_url );
        exit;
    }

    private static function redirect_with_error( string $url, string $message ): void {
        wp_safe_redirect( add_query_arg( [
            'cmm_error' => rawurlencode( $message ),
        ], $url ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // REST endpoints
    // -------------------------------------------------------------------------

    public static function register_endpoints() {
        register_rest_route( 'cmm/v1', '/home-status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'home_status' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'home_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'cmm/v1', '/account-check', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'account_check' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [ 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
            ],
        ] );

        register_rest_route( 'cmm/v1', '/username-check', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'username_check' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [
                    'required'          => true,
                    'sanitize_callback' => fn( $v ) => sanitize_user( (string) $v, true ),
                ],
            ],
        ] );
    }

    public static function home_status( WP_REST_Request $request ) {
        if ( ! self::rate_limit_check( 'home_status' ) ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        $home_id = (int) $request->get_param( 'home_id' );
        $home    = get_post( $home_id );
        if ( ! $home || $home->post_type !== 'cmm_home' ) {
            return new WP_REST_Response( [ 'error' => 'Home not found' ], 404 );
        }

        $status     = (string) get_field( 'membership_status', $home_id );
        $paid_date  = (string) get_field( 'dues_paid_date',    $home_id );
        $paid_amt   = (float)  get_field( 'dues_amount_paid',  $home_id );
        $primary    = get_field( 'primary_contact', $home_id );
        $has_primary = ! empty( $primary );

        $labels = [
            'active'                   => 'Active',
            'inactive'                 => 'Available',
            'expired'                  => 'Expired',
            'pending_review'           => 'Pending Review',
            'approved_pending_payment' => 'Approved — Awaiting Payment',
            'rejected'                 => 'Rejected',
        ];

        return new WP_REST_Response( [
            'home_id'          => $home_id,
            'address'          => $home->post_title,
            'address_code'     => (string) get_field( 'address_code', $home_id ),
            'status'           => $status,
            'status_label'     => $labels[ $status ] ?? ucfirst( $status ),
            'dues_paid_date'   => $paid_date ?: null,
            'dues_amount_paid' => $paid_amt,
            'has_primary'      => $has_primary,
        ], 200 );
    }

    public static function account_check( WP_REST_Request $request ) {
        if ( ! self::rate_limit_check( 'account_check' ) ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        $email = (string) $request->get_param( 'email' );
        if ( ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'exists' => false ], 200 );
        }
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return new WP_REST_Response( [ 'exists' => false ], 200 );
        }
        return new WP_REST_Response( [
            'exists'       => true,
            'display_name' => $user->display_name,
            'has_home'     => (bool) get_user_meta( $user->ID, 'cmm_home_id', true ),
        ], 200 );
    }

    public static function username_check( WP_REST_Request $request ) {
        if ( ! self::rate_limit_check( 'username_check' ) ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        $username = (string) $request->get_param( 'username' );
        if ( strlen( $username ) < 3 ) {
            return new WP_REST_Response( [
                'available' => false,
                'reason'    => 'too_short',
            ], 200 );
        }
        $available = ! username_exists( $username );
        $suggestion = null;
        if ( ! $available ) {
            for ( $i = 2; $i < 12; $i++ ) {
                $candidate = $username . $i;
                if ( ! username_exists( $candidate ) ) {
                    $suggestion = $candidate;
                    break;
                }
            }
        }
        return new WP_REST_Response( [
            'available'  => $available,
            'suggestion' => $suggestion,
        ], 200 );
    }

    private static function rate_limit_check( string $endpoint, int $max = 30 ): bool {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'cmm_rl_' . $endpoint . '_' . md5( (string) $ip );
        $count = (int) get_transient( $key );
        if ( $count >= $max ) return false;
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing — only on pages with the shortcode
    // -------------------------------------------------------------------------

    public static function maybe_enqueue_assets(): void {
        if ( is_admin() ) return;
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'cmm_membership_form' ) ) return;

        wp_enqueue_style(
            'cmm-membership-form',
            CMM_URL . 'assets/css/cmm-membership-form.css',
            [],
            CMM_VERSION
        );
        wp_enqueue_script(
            'cmm-membership-form',
            CMM_URL . 'assets/js/cmm-membership-form.js',
            [],
            CMM_VERSION,
            true
        );
        wp_localize_script( 'cmm-membership-form', 'cmmForm', [
            'restRoot'   => esc_url_raw( rest_url( 'cmm/v1/' ) ),
            'duesAmount' => (float) get_option( 'cmm_dues_amount', 0 ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Confirmation message — placeholder substitution + safe HTML rendering
    // -------------------------------------------------------------------------

    private static function substitute_placeholders( string $message, int $home_id ): string {
        $home    = get_post( $home_id );
        $primary = (int) get_field( 'primary_contact', $home_id );
        $user    = $primary ? get_userdata( $primary ) : null;
        $amount  = (float) get_field( 'dues_amount_paid', $home_id );

        $replacements = [
            '{first_name}'     => $user ? $user->first_name : '',
            '{last_name}'      => $user ? $user->last_name  : '',
            '{email}'          => $user ? $user->user_email : '',
            '{address}'        => $home ? $home->post_title : '',
            '{amount}'         => number_format( $amount, 2 ),
            '{community_name}' => get_option( 'cmm_community_name', 'Community' ),
            '{admin_email}'    => get_option( 'cmm_admin_email', get_option( 'admin_email' ) ),
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $message );
    }

    /**
     * Extended kses allow-list so admins can paste PayPal "Buy Now" buttons
     * (which are <form> POSTs with <input type="hidden"> fields) and basic
     * <iframe>-style external payment widgets into the Confirmation Message.
     *
     * Only manage_options users can edit the option, so the trust boundary is
     * still the WP admin role; this just keeps wp_kses_post from stripping
     * the payment markup.
     */
    private static function kses_confirmation( string $html ): string {
        $allowed = wp_kses_allowed_html( 'post' );

        $allowed['form'] = [
            'action'  => true,
            'method'  => true,
            'target'  => true,
            'class'   => true,
            'style'   => true,
            'id'      => true,
            'name'    => true,
            'enctype' => true,
        ];
        $allowed['input'] = [
            'type'  => true,
            'name'  => true,
            'value' => true,
            'src'   => true,
            'alt'   => true,
            'class' => true,
            'style' => true,
            'id'    => true,
            'border'=> true,
        ];
        $allowed['button'] = [
            'type'  => true,
            'name'  => true,
            'value' => true,
            'class' => true,
            'style' => true,
            'id'    => true,
        ];
        $allowed['iframe'] = [
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'loading'         => true,
            'class'           => true,
            'style'           => true,
            'name'            => true,
            'title'           => true,
        ];

        return wp_kses( $html, $allowed );
    }
}
