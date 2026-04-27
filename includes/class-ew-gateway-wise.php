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
 * @version     2.2.0
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

        // Actions.
        add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
        add_action( "woocommerce_thankyou_{$this->id}",                        array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_my_account_my_orders_actions',                array( $this, 'my_account_pay_action' ), 10, 2 );
    }

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

        if ( ! $this->is_plugin() ) {
            unset( $this->form_fields['wipe_data'] );
        }
    }

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

        $amount  = $order->get_total();
        $parsed  = wp_parse_url( $base_url );
        $existing = array();
        if ( ! empty( $parsed['query'] ) ) {
            wp_parse_str( $parsed['query'], $existing );
        }

        $new_params   = array( 'amount' => $amount );
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

    /**
     * Admin Panel Options.
     */
    public function admin_options() {
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
            $('.extended').tipTip();
        });
        </script>
        <table class="form-table">
        <tbody>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="woocommerce_wise_bacs_preview"
                           class="extended"
                           title="<?php echo esc_attr__( 'Quickly preview recent orders that used Wise as a payment method', 'wise-payment-for-woocommerce' ); ?>"
                    ><?php echo esc_html__( 'Preview', 'wise-payment-for-woocommerce' ); ?></label>
                </th>
                <td class="forminp">
                    <fieldset>
                        <select class="select" name="woocommerce_wise_bacs_preview" id="woocommerce_wise_bacs_preview">
                            <option value=""><?php echo esc_html__( '-- choose order to preview --', 'wise-payment-for-woocommerce' ); ?></option>
                            <?php foreach ( $options as $option ) echo $option; ?>
                        </select>
                    </fieldset>
                </td>
            </tr>
        </tbody>
        </table>
        <?php
    }

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

    /**
     * Replace the "Pay" button URL on My Account -> Orders with the Wise payment URL.
     *
     * @param  array    $actions
     * @param  WC_Order $order
     * @return array
     */
    public function my_account_pay_action( $actions, $order ) {
        if ( 'wise_bacs' !== $order->get_payment_method() ) {
            return $actions;
        }
        if ( ! $order->has_status( 'pending' ) ) {
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

    /**
     * Process the payment and return the result.
     *
     * @param  int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            // Mark as pending — awaiting Wise payment.
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
     * Returns recent Wise orders as <option> elements for the admin preview dropdown.
     *
     * @return array
     */
    private function get_wise_orders() {
        $options = array();
        $orders  = wc_get_orders( array(
            'limit'          => $this->limit_orders,
            'payment_method' => $this->id,
            'return'         => 'objects',
        ) );
        foreach ( $orders as $order ) {
            $label     = sprintf(
                /* translators: 1: order number 2: date 3: status */
                __( 'Order #%1$s — %2$s (%3$s)', 'wise-payment-for-woocommerce' ),
                $order->get_order_number(),
                wc_format_datetime( $order->get_date_created() ),
                wc_get_order_status_name( $order->get_status() )
            );
            $wise_url  = $this->build_wise_url( $order );
            $options[] = '<option value="' . esc_attr( $wise_url ) . '">' . esc_html( $label ) . '</option>';
        }
        return $options;
    }

    /**
     * Whether running as a WordPress plugin.
     *
     * @return bool
     */
    private function is_plugin() {
        return defined( 'ABSPATH' );
    }
}

endif;
