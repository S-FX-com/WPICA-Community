<?php
/**
 * Stripe Checkout integration for the native membership form.
 *
 * Implemented as direct REST calls against api.stripe.com so the plugin
 * stays SDK-free. Two flows:
 *
 * 1. Form submission (called from CMM_Membership_Form when payment mode
 *    is 'stripe'): cache the submission as a transient, create a Stripe
 *    Checkout Session with `metadata.cmm_token=<uuid>`, return the
 *    session URL for redirect.
 *
 * 2. Webhook (POST /wp-json/cmm/v1/webhook/stripe): verify the
 *    Stripe-Signature header (HMAC SHA-256), look up the cached
 *    submission by `metadata.cmm_token`, activate the home via
 *    CMM_Webhooks::process_membership_activation(), record the event
 *    ID for idempotency, and stash an activation marker the return URL
 *    can read.
 */
class CMM_Stripe {

    public const API_BASE   = 'https://api.stripe.com/v1';
    public const PENDING_TTL = HOUR_IN_SECONDS;        // form submit → checkout return
    public const RESULT_TTL  = 6 * HOUR_IN_SECONDS;    // activated marker for the return URL

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ] );
    }

    public static function register_endpoints() {
        register_rest_route( 'cmm/v1', '/webhook/stripe', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public static function is_configured(): bool {
        return (bool) get_option( 'cmm_stripe_secret_key' )
            && (bool) get_option( 'cmm_stripe_webhook_secret' );
    }

    public static function get_webhook_url(): string {
        return rest_url( 'cmm/v1/webhook/stripe' );
    }

    public static function is_test_mode(): bool {
        $key = (string) get_option( 'cmm_stripe_secret_key', '' );
        return str_starts_with( $key, 'sk_test_' );
    }

    // -------------------------------------------------------------------------
    // Pending submission cache (form submit → webhook activation → return URL)
    // -------------------------------------------------------------------------

    public static function store_pending( array $data ): string {
        $token = wp_generate_uuid4();
        set_transient( 'cmm_stripe_pending_' . $token, $data, self::PENDING_TTL );
        return $token;
    }

    public static function fetch_pending( string $token ): ?array {
        if ( ! $token ) return null;
        $data = get_transient( 'cmm_stripe_pending_' . $token );
        return is_array( $data ) ? $data : null;
    }

    public static function clear_pending( string $token ): void {
        delete_transient( 'cmm_stripe_pending_' . $token );
    }

    public static function mark_activated( string $token, int $home_id ): void {
        set_transient( 'cmm_stripe_activated_' . $token, $home_id, self::RESULT_TTL );
    }

    public static function fetch_activated( string $token ): ?int {
        if ( ! $token ) return null;
        $home_id = get_transient( 'cmm_stripe_activated_' . $token );
        return $home_id ? (int) $home_id : null;
    }

    // -------------------------------------------------------------------------
    // Create a Checkout Session
    //
    // Expected $data keys: token, home_id, email, amount, address, first_name,
    // last_name. Returns either [ 'url' => '...' ] or [ 'error' => '...' ].
    // -------------------------------------------------------------------------

    public static function create_checkout_session( array $data ): array {
        $secret_key = (string) get_option( 'cmm_stripe_secret_key', '' );
        if ( ! $secret_key ) {
            return [ 'error' => 'Stripe secret key is not configured' ];
        }

        $amount_cents = (int) round( ( (float) $data['amount'] ) * 100 );
        if ( $amount_cents <= 0 ) {
            return [ 'error' => 'Configured dues amount must be greater than zero before Stripe Checkout will accept a payment' ];
        }

        $community = (string) get_option( 'cmm_community_name', 'Community' );
        $product   = sprintf( '%s Membership — %s', $community, (string) ( $data['address'] ?? '' ) );

        // Strip any prior cmm_* return-flow params so they don't leak into the
        // success/cancel URLs we're about to construct.
        $origin = (string) ( $data['origin_url'] ?? home_url( '/' ) );
        $origin = remove_query_arg(
            [ 'cmm_stripe_cancel', 'cmm_stripe_return', 'cmm_activated', 'cmm_error', 'token', 'home_id' ],
            $origin
        );

        $return_arg = add_query_arg( [
            'cmm_stripe_return' => '1',
            'token'             => (string) $data['token'],
        ], $origin );
        $cancel_arg = add_query_arg( [
            'cmm_stripe_cancel' => '1',
        ], $origin );

        $params = [
            'mode'                                       => 'payment',
            'customer_email'                             => (string) $data['email'],
            'success_url'                                => $return_arg,
            'cancel_url'                                 => $cancel_arg,
            'line_items[0][quantity]'                    => 1,
            'line_items[0][price_data][currency]'        => 'usd',
            'line_items[0][price_data][unit_amount]'     => $amount_cents,
            'line_items[0][price_data][product_data][name]' => $product,
            'metadata[cmm_token]'                        => (string) $data['token'],
            'metadata[cmm_home_id]'                      => (string) (int) $data['home_id'],
            'payment_intent_data[metadata][cmm_token]'   => (string) $data['token'],
            'payment_intent_data[metadata][cmm_home_id]' => (string) (int) $data['home_id'],
        ];

        $response = wp_remote_post( self::API_BASE . '/checkout/sessions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['url'] ) ) {
            $msg = is_array( $body ) && isset( $body['error']['message'] )
                ? (string) $body['error']['message']
                : "Stripe API returned HTTP {$code}";
            return [ 'error' => $msg ];
        }

        return [ 'url' => (string) $body['url'], 'session_id' => (string) ( $body['id'] ?? '' ) ];
    }

    // -------------------------------------------------------------------------
    // Webhook receiver
    // -------------------------------------------------------------------------

    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $secret  = (string) get_option( 'cmm_stripe_webhook_secret', '' );
        $payload = $request->get_body();
        $sig     = (string) $request->get_header( 'stripe-signature' );

        if ( ! $secret ) {
            return new WP_REST_Response( [ 'error' => 'Webhook secret not configured' ], 500 );
        }
        if ( ! self::verify_signature( $payload, $sig, $secret ) ) {
            return new WP_REST_Response( [ 'error' => 'Signature verification failed' ], 400 );
        }

        $event = json_decode( $payload, true );
        if ( ! is_array( $event ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid JSON' ], 400 );
        }

        $type = (string) ( $event['type'] ?? '' );
        if ( $type !== 'checkout.session.completed' ) {
            // Acknowledge other event types without acting; keeps Stripe from retrying.
            return new WP_REST_Response( [ 'status' => 'ignored', 'type' => $type ], 200 );
        }

        $session    = (array) ( $event['data']['object'] ?? [] );
        $event_id   = (string) ( $event['id'] ?? '' );
        $metadata   = (array) ( $session['metadata'] ?? [] );
        $token      = (string) ( $metadata['cmm_token']   ?? '' );
        $home_id    = (int)    ( $metadata['cmm_home_id'] ?? 0 );
        $amount_cts = (int)    ( $session['amount_total'] ?? 0 );

        if ( ! $token || ! $home_id ) {
            return new WP_REST_Response( [ 'error' => 'Missing cmm_token or cmm_home_id metadata' ], 400 );
        }

        // Idempotency — guard against Stripe's at-least-once delivery.
        $idem_key = 'cmm_stripe_event_' . $event_id;
        if ( $event_id && get_post_meta( $home_id, $idem_key, true ) ) {
            return new WP_REST_Response( [ 'status' => 'already_handled' ], 200 );
        }

        $pending = self::fetch_pending( $token );
        if ( ! $pending ) {
            // Token expired or already consumed. Activate from minimal metadata
            // so payment isn't lost — uses session.customer_email + amount.
            $pending = [
                'home_id'     => $home_id,
                'email'       => (string) ( $session['customer_email'] ?? $session['customer_details']['email'] ?? '' ),
                'first_name'  => '',
                'last_name'   => '',
                'amount'      => $amount_cts > 0 ? $amount_cts / 100 : 0,
                'date'        => date( 'Y-m-d' ),
                'member_data' => [],
            ];
        }

        $result = CMM_Webhooks::process_membership_activation(
            (int)    $pending['home_id'],
            (string) $pending['email'],
            (string) ( $pending['first_name'] ?? '' ),
            (string) ( $pending['last_name']  ?? '' ),
            $amount_cts > 0 ? (float) $amount_cts / 100 : (float) ( $pending['amount'] ?? 0 ),
            (string) ( $pending['date'] ?? date( 'Y-m-d' ) ),
            (array)  ( $pending['member_data'] ?? [] )
        );

        if ( isset( $result['error'] ) ) {
            return new WP_REST_Response( $result, 500 );
        }

        if ( $event_id ) {
            update_post_meta( $home_id, $idem_key, time() );
        }
        self::mark_activated( $token, $home_id );
        self::clear_pending( $token );

        return new WP_REST_Response( [
            'status'  => 'activated',
            'home_id' => $home_id,
            'event'   => $event_id,
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // Stripe-Signature verification (HMAC SHA-256)
    //
    // Header format: "t=<unix_ts>,v1=<hex>,v1=<hex>,..."
    // Signed payload: "<unix_ts>.<raw_body>"
    // -------------------------------------------------------------------------

    private static function verify_signature( string $payload, string $sig_header, string $secret, int $tolerance = 300 ): bool {
        if ( ! $sig_header || ! $secret ) return false;

        $timestamp = 0;
        $signatures = [];
        foreach ( explode( ',', $sig_header ) as $part ) {
            $kv = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
            if ( $kv[0] === 't' )  $timestamp    = (int) $kv[1];
            if ( $kv[0] === 'v1' ) $signatures[] = $kv[1];
        }

        if ( ! $timestamp || ! $signatures ) return false;
        if ( abs( time() - $timestamp ) > $tolerance ) return false;

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        foreach ( $signatures as $sig ) {
            if ( hash_equals( $expected, $sig ) ) return true;
        }
        return false;
    }
}
