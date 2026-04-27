<?php
/**
 * Class EW_Gateway_Wise file.
 *
 * @package WooCommerce\Gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Payment Gateway for Wise
 *
 * @class       EW_Gateway_Wise
 * @extends     WC_Payment_Gateway
 * @version     2.3.0
 * @package     WooCommerce\Classes\Payment
 */

if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'EW_Gateway_Wise' ) ) :

class EW_Gateway_Wise extends WC_Payment_Gateway {

    /** @var array */
    public $locale;

    /** @var int */
    public $limit_orders;

    /** @var string */
    public $instructions;

    /** @var string */
    public $wise_payment_url;

    /** @var string */
    public $add_description_to_url;

    public function __construct() {
        $this->id                 = 'wise_bacs';
        $this->has_fields         = false;
        $this->method_title       = __( 'Wise bank transfer', 'wise-payment-for-woocommerce' );
        $this->method_description = __( 'Customers pay by banking domestically with minimal fees, toward a local (to them) Wise account linked to your bank account (usually in another country).', 'wise-payment-for-woocommerce' );

        $this->limit_orders = apply_filters( "{$this->id}_limit_orders", 25 );

        $this->init_form_fields();
        $this->init_settings();

        $this->title                  = $this->get_option( 'title' );
        $this->description            = $this->get_option( 'description' );
        $this->instructions           = $this->get_option( 'instructions' );
        $this->wise_payment_url       = $this->get_option( 'wise_payment_url' );
        $this->add_description_to_url = $this->get_option( 'add_description_to_url' );

        // Core actions.
        add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
        add_action( "woocommerce_thankyou_{$this->id}",                        array( $this, 'thankyou_page' ) );

        // My Account "Pay" button.
        add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_account_pay_action' ), 10, 2 );

        // Schedule on-hold promotion before WooCommerce auto-cancels the order (if Hold stock is set).
        add_action( 'woocommerce_new_order', array( $this, 'schedule_onhold_promotion' ) );
        add_action( 'wise_bacs_promote_to_onhold', array( $this, 'scheduled_promote_to_onhold' ) );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'                => array(
                'title'   => __( 'Enable/Disable', 'wise-payment-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Wise payments', 'wise-payment-for-woocommerce' ),
                'default' => 'no',
            ),
            'title'                  => array(
                'title'       => __( 'Title', 'wise-payment-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method title that the customer sees in the checkout page', 'wise-payment-for-woocommerce' ),
                'default'     => __( 'Pay by Bank', 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'            => array(
                'title'       => __( 'Description', 'wise-payment-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer sees in the checkout page', 'wise-payment-for-woocommerce' ),
                'default'     => __( "International transfers with domestic fees. Powered by <a href='https://wise.com' target='_blank'>Wise&reg;</a>", 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'           => array(
                'title'       => __( 'Instructions', 'wise-payment-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment instructions shown on the thank-you page', 'wise-payment-for-woocommerce' ),
                'default'     => __( "- Please use the payment button below to transfer the funds via Wise.\n- Use your Order number as payment reference.\n- Orders won't be shipped until funds have cleared.", 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'wise_payment_url'       => array(
                'title'       => __( 'Wise Payment URL', 'wise-payment-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Wise payment link, e.g. https://wise.com/pay/business/yourbusiness?utm_source=quick_pay', 'wise-payment-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'https://wise.com/pay/business/yourbusiness?utm_source=quick_pay',
            ),
            'add_description_to_url' => array(
                'title'       => __( 'Add order description', 'wise-payment-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Add order number as description to the Wise payment URL', 'wise-payment-for-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'When enabled, the order number will be appended to the Wise URL as a description parameter.', 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'wipe_data'              => array(
                'title'   => __( 'Wipe configuration', 'wise-payment-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Wipe all plugin data when uninstalling this plugin', 'wise-payment-for-woocommerce' ),
                'default' => 'no',
            ),
        );

        if ( ! defined( 'WPINC' ) ) {
            unset( $this->form_fields['wipe_data'] );
        }
    }

    /**
     * Sanitize and validate the wise_payment_url option before saving.
     * Ensures only https://wise.com URLs are accepted.
     *
     * @param  string $key
     * @param  string $value
     * @return string
     */
    public function validate_wise_payment_url_field( $key, $value ) {
        $url  = esc_url_raw( trim( $value ) );
        $host = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $host || ! in_array( $host, array( 'wise.com', 'www.wise.com' ), true ) ) {
            WC_Admin_Settings::add_error(
                __( 'Wise Payment URL must be a valid wise.com URL (e.g. https://wise.com/pay/business/yourbusiness).', 'wise-payment-for-woocommerce' )
            );
            // Keep existing value on error.
            return $this->get_option( $key );
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // URL builder
    // -------------------------------------------------------------------------

    /**
     * Build the Wise payment URL with amount (and optional description) injected.
     * utm_* params are always kept at the end.
     *
     * @param  WC_Order $order
     * @return string
     */
    protected function build_wise_url( $order ) {
        $base_url = trim( $this->wise_payment_url );
        if ( empty( $base_url ) ) {
            return '';
        }

        $parsed = wp_parse_url( $base_url );

        // Guard: must have scheme and host.
        if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return '';
        }

        $existing = array();
        if ( ! empty( $parsed['query'] ) ) {
            wp_parse_str( $parsed['query'], $existing );
        }

        // Round amount to 2 decimal places to avoid float formatting issues.
        $new_params   = array( 'amount' => number_format( (float) $order->get_total(), 2, '.', '' ) );
        $utm_params   = array();
        $other_params = array();

        if ( 'yes' === $this->add_description_to_url ) {
            /* translators: %s: order number */
            $new_params['description'] = sprintf( __( 'Order #%s', 'wise-payment-for-woocommerce' ), $order->get_order_number() );
        }

        foreach ( $existing as $key => $val ) {
            if ( strpos( $key, 'utm_' ) === 0 ) {
                $utm_params[ $key ] = $val;
            } else {
                $other_params[ $key ] = $val;
            }
        }

        $merged = array_merge( $other_params, $new_params, $utm_params );

        $base = $parsed['scheme'] . '://' . $parsed['host'];
        if ( ! empty( $parsed['path'] ) )     $base .= $parsed['path'];
        if ( ! empty( $parsed['fragment'] ) ) $base .= '#' . $parsed['fragment'];

        return $base . '?' . http_build_query( $merged, '', '&' );
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    /**
     * Admin Panel Options — adds order preview dropdown below standard settings.
     */
    public function admin_options() {
        // Show admin notice if Wise URL is not configured yet.
        if ( 'yes' === $this->get_option( 'enabled' ) && empty( $this->wise_payment_url ) ) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__( 'Wise Payment for WooCommerce: please set the Wise Payment URL below to start accepting payments.', 'wise-payment-for-woocommerce' )
                . '</p></div>';
        }

        parent::admin_options();

        $options = $this->get_wise_orders();
        ?>
        <script type="text/javascript">
        jQuery( function($) {
            'use strict';
            $('#woocommerce_wise_bacs_preview').on('change', function() {
                try {
                    var url = new URL( $(this).val() );
                    window.open(url, '_blank');
                } catch (_) {}
                return false;
            });
        });
        </script>
        <table class="form-table">
        <tbody>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="woocommerce_wise_bacs_preview">
                        <?php echo esc_html__( 'Preview orders', 'wise-payment-for-woocommerce' ); ?>
                    </label>
                </th>
                <td class="forminp">
                    <fieldset>
                        <select class="select" name="woocommerce_wise_bacs_preview" id="woocommerce_wise_bacs_preview">
                            <option value=""><?php echo esc_html__( '-- choose order to preview --', 'wise-payment-for-woocommerce' ); ?></option>
                            <?php foreach ( $options as $option ) echo $option; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </select>
                        <p class="description"><?php echo esc_html__( 'Opens the Wise payment link for the selected order in a new tab.', 'wise-payment-for-woocommerce' ); ?></p>
                    </fieldset>
                </td>
            </tr>
        </tbody>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Checkout / Payment flow
    // -------------------------------------------------------------------------

    /**
     * Process the payment and return the result.
     *
     * @param  int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            // Start as pending — a cron event will promote to on-hold before auto-cancel (if Hold stock is set).
            // so the customer first sees the payment button before status changes.
            $order->update_status(
                apply_filters( "woocommerce_{$this->id}_process_payment_order_status", 'pending', $order ),
                __( 'Awaiting Wise payment.', 'wise-payment-for-woocommerce' )
            );
        } else {
            $order->payment_complete();
        }

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Validate that the Wise URL is configured before the payment can be used.
     *
     * @return bool
     */
    public function validate_fields() {
        if ( empty( $this->wise_payment_url ) ) {
            wc_add_notice(
                __( 'Payment error: Wise payment is not configured correctly. Please contact the store owner.', 'wise-payment-for-woocommerce' ),
                'error'
            );
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Thank-you page
    // -------------------------------------------------------------------------

    /**
     * Output for the order received (thank-you) page.
     *
     * @param int $order_id
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! is_object( $order ) ) {
            return;
        }

        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }

        $wise_url = $this->build_wise_url( $order );
        if ( ! empty( $wise_url ) ) {
            echo '<p>'
                . '<a href="' . esc_url( $wise_url ) . '" target="_blank" rel="noopener noreferrer" class="button pay-with-wise">'
                . esc_html__( 'Pay with Wise', 'wise-payment-for-woocommerce' )
                . '</a>'
                . '</p>' . PHP_EOL;
        }
    }

    // -------------------------------------------------------------------------
    // My Account "Pay" button
    // -------------------------------------------------------------------------

    /**
     * Replace the default "Pay" button URL on My Account → Orders with the Wise payment URL.
     *
     * @param  array    $actions
     * @param  WC_Order $order
     * @return array
     */
    public function my_account_pay_action( $actions, $order ) {
        if ( 'wise_bacs' !== $order->get_payment_method() ) {
            return $actions;
        }
        if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            return $actions;
        }

        $wise_url = $this->build_wise_url( $order );
        if ( empty( $wise_url ) ) {
            return $actions;
        }

        if ( isset( $actions['pay'] ) ) {
            $actions['pay']['url'] = $wise_url;
        } else {
            $actions['pay'] = array(
                'url'  => $wise_url,
                'name' => __( 'Pay', 'wise-payment-for-woocommerce' ),
            );
        }

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Auto-cancel prevention + reminder email
    // -------------------------------------------------------------------------

    /**
     * When a new order is placed via Wise, schedule a cron event to promote it
     * to on-hold (N-1) minutes before WooCommerce would auto-cancel it.
     *
     * If "Hold stock (minutes)" is empty/0 in WooCommerce settings, no event is scheduled
     * because there is no auto-cancellation risk.
     *
     * @param int $order_id
     */
    public function schedule_onhold_promotion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! is_object( $order ) ) {
            return;
        }
        if ( 'wise_bacs' !== $order->get_payment_method() ) {
            return;
        }

        $hold_stock_minutes = (int) get_option( 'woocommerce_hold_stock_minutes', 0 );
        if ( $hold_stock_minutes <= 0 ) {
            // Auto-cancellation disabled — nothing to do.
            return;
        }

        // Fire 1 minute before WooCommerce would cancel.
        $delay = max( 1, $hold_stock_minutes - 1 ) * MINUTE_IN_SECONDS;

        wp_schedule_single_event(
            time() + $delay,
            'wise_bacs_promote_to_onhold',
            array( $order_id )
        );
    }

    /**
     * Cron callback: promote a Wise pending order to on-hold and send a payment reminder.
     * Only runs if the order is still pending (i.e. customer hasn't paid yet).
     *
     * @param int $order_id
     */
    public function scheduled_promote_to_onhold( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! is_object( $order ) ) {
            return;
        }
        if ( 'wise_bacs' !== $order->get_payment_method() ) {
            return;
        }
        // If already paid/processing/completed — do nothing.
        if ( ! $order->has_status( 'pending' ) ) {
            return;
        }

        $order->update_status(
            'on-hold',
            __( 'Order promoted to on-hold before WooCommerce auto-cancel timeout — awaiting Wise payment. Reminder sent to customer.', 'wise-payment-for-woocommerce' )
        );

        $this->send_payment_reminder( $order );
    }

    /**
     * Send a custom payment reminder email to the customer.
     *
     * @param WC_Order $order
     */
    protected function send_payment_reminder( $order ) {
        $to       = $order->get_billing_email();
        $wise_url = $this->build_wise_url( $order );

        if ( empty( $to ) ) {
            return;
        }

        $site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $order_num   = $order->get_order_number();
        $order_total = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );

        /* translators: 1: site name, 2: order number */
        $subject = sprintf( __( '[%1$s] Payment reminder for order #%2$s', 'wise-payment-for-woocommerce' ), $site_name, $order_num );

        $first_name = $order->get_billing_first_name();

        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => __( 'Payment reminder', 'wise-payment-for-woocommerce' ) ) );
        ?>

        <p><?php
            /* translators: %s: customer first name */
            echo esc_html( sprintf( __( 'Hello %s,', 'wise-payment-for-woocommerce' ), $first_name ) );
        ?></p>

        <p><?php
            /* translators: 1: order number, 2: order total */
            echo esc_html( sprintf(
                __( 'We noticed that your order #%1$s (%2$s) is still awaiting payment.', 'wise-payment-for-woocommerce' ),
                $order_num,
                $order_total
            ) );
        ?></p>

        <p><?php echo esc_html__( 'If you have already completed the transfer via Wise — please ignore this message. There is nothing to worry about: the administrator will verify the payment and process your order shortly.', 'wise-payment-for-woocommerce' ); ?></p>

        <p><?php echo esc_html__( 'If you have not yet paid, please use the button below to complete your payment via Wise:', 'wise-payment-for-woocommerce' ); ?></p>

        <?php if ( ! empty( $wise_url ) ) : ?>
        <p style="text-align:center;margin:24px 0;">
            <a href="<?php echo esc_url( $wise_url ); ?>"
               style="background-color:#163300;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;font-weight:bold;">
                <?php echo esc_html__( 'Pay with Wise', 'wise-payment-for-woocommerce' ); ?>
            </a>
        </p>
        <p style="font-size:12px;color:#666;">
            <?php
            /* translators: %s: Wise payment URL */
            echo esc_html( sprintf( __( 'Or copy this link: %s', 'wise-payment-for-woocommerce' ), $wise_url ) );
            ?>
        </p>
        <?php endif; ?>

        <?php
        wc_get_template( 'emails/email-footer.php' );
        $message = ob_get_clean();

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        // Use WooCommerce mailer for proper delivery.
        $mailer = WC()->mailer();
        $mailer->send( $to, $subject, $message, $headers );

        // Log a note on the order.
        $order->add_order_note(
            /* translators: %s: customer email */
            sprintf( __( 'Wise payment reminder sent to %s.', 'wise-payment-for-woocommerce' ), $to )
        );
    }

    // -------------------------------------------------------------------------
    // Admin preview helpers
    // -------------------------------------------------------------------------

    /**
     * Returns recent Wise orders as <option> elements for the admin preview dropdown.
     *
     * @return array
     */
    private function get_wise_orders() {
        $options = array();

        $orders = wc_get_orders( array(
            'limit'          => $this->limit_orders,
            'payment_method' => $this->id,
            'return'         => 'objects',
            'no_found_rows'  => true,
        ) );

        if ( empty( $orders ) ) {
            return $options;
        }

        foreach ( $orders as $order ) {
            $wise_url = $this->build_wise_url( $order );
            $label    = sprintf(
                /* translators: 1: order number 2: date 3: status */
                __( 'Order #%1$s — %2$s (%3$s)', 'wise-payment-for-woocommerce' ),
                $order->get_order_number(),
                wc_format_datetime( $order->get_date_created() ),
                wc_get_order_status_name( $order->get_status() )
            );

            if ( empty( $wise_url ) ) {
                $options[] = '<option value="" disabled>' . esc_html( $label ) . ' — ' . esc_html__( 'URL not configured', 'wise-payment-for-woocommerce' ) . '</option>';
            } else {
                $options[] = '<option value="' . esc_attr( $wise_url ) . '">' . esc_html( $label ) . '</option>';
            }
        }

        return $options;
    }
}

endif;
