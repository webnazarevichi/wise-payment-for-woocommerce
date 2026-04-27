<?php
/**
 * Class EW_Gateway_Wise file.
 *
 * @package WooCommerce\Gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
 
/**
 * Payment Gateway for Wise
 *
 * Exposes a simple Payment Gateway to Wise by providing customers with the seller's bank details w/o API
 * (based on WooCommerce BACS gateway)
 *
 * @class       EW_Gateway_Wise
 * @extends     WC_Payment_Gateway
 * @version     2.1.0.2
 * @package     WooCommerce\Classes\Payment
 */

if ( class_exists( 'WC_Payment_Gateway' ) && !class_exists( 'EW_Gateway_Wise' ) ) :  
 
class EW_Gateway_Wise extends WC_Payment_Gateway {

    /**
     * Array of locales
     *
     * @var array
     */
    public  $locale;

    /**
     * limit number of fetched orders for preview
     * @var int
     */
    public $limit_orders;

    /**
     * Payment instructions
     * @var string
     */
    public $instructions;

    /**
     * Full legal name of the Wise registrant
     * @var string
     */
    public $account_holder;

    /**
     * Wise account details
     * @var array
     */
    public $account_details;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'ew_wise';
        $this->has_fields         = false;
        $this->method_title       = __( 'Wise bank transfer', 'wise-payment-for-woocommerce' );
        $this->method_description = __( 'Customers pay by banking domestically with minimal fees, '
                                       .'toward a local (to them) Wise account linked to your bank account '
                                       .'(usually in another country).', 'wise-payment-for-woocommerce' );
        
        // default orders for preview fetched 
        $this->limit_orders = apply_filters("{$this->id}_limit_orders", 25); 

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables.
        $this->title          = $this->get_option( 'title' );
        $this->description    = $this->get_option( 'description' );
        $this->instructions   = $this->get_option( 'instructions' );
        $this->account_holder = $this->get_option( 'account_holder' );

        // Wise account fields shown on the thanks page and in emails.
        $this->account_details = get_option(
            "woocommerce_{$this->id}_accounts",
            array(
                array(
                    'account_scope'    => $this->get_option( 'account_scope' ),
                    'account_currency' => $this->get_option( 'account_currency' ),
                    'account_number'   => $this->get_option( 'account_number' ),
                    'routing_number'   => $this->get_option( 'routing_number' ),
                    'iban'             => $this->get_option( 'iban' ),
                    'bic'              => $this->get_option( 'bic' ),
                    'branch'           => $this->get_option( 'branch' ),
                    'remarks'          => $this->get_option( 'remarks' )
                )
            )
        );

        // Actions.
        add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
        add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'save_account_details' ) );
        add_action( "woocommerce_thankyou_{$this->id}",                        array( $this, 'thankyou_page' ) );
        
        // Customer Emails.
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }
    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {

        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __( 'Enable/Disable', 'wise-payment-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Wise payments', 'wise-payment-for-woocommerce' ),
                'default'     => 'no'
            ),
            'title'           => array(
                'title'       => __( 'Title', 'wise-payment-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method title that the customer sees in the checkout page', 'wise-payment-for-woocommerce' ),
                'default'     => __( 'Pay by Bank', 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'     => array(
                'title'       => __( 'Description', 'wise-payment-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer sees in the checkout page', 'wise-payment-for-woocommerce' ),
                'default'     => __( "International transfers with domestic fees. Powered by <a href='https://wise.com' target='_blank'>Wise&reg;</a>", 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'instructions'    => array(
                'title'       => __( 'Instructions', 'wise-payment-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment instructions that will be included to the thank-you page and e-mails', 'wise-payment-for-woocommerce' ),
                'default'     => __( "- Please choose a receiving account that best fits your situation, and wire the funds using your banking facility<br>\n"
                                    ."- The receiving account type is: <i>Checking</i><br>\n" 
                                    ."- As payment reference use your <i>Order number</i><br>\n"
                                    ."- Please mind orders won't be shipped until funds have cleared our end.\n"
                                    , 'wise-payment-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'account_holder'  => array(
                'title'       => __( 'Account holder', 'wise-payment-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( "Full legal name of the Wise registrant. Will be included to the thank-you page and e-mails as the beneficiary of all accounts", 'wise-payment-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true
            ),
            'account_details' => array(
                       'type' => 'account_details'
            ),
            'wipe_data'       => array(
                'title'       => __( 'Wipe configuration', 'wise-payment-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Wipe all accounts data when uninstalling this plugin', 'wise-payment-for-woocommerce' ),
                'default'     => 'no'
            )
        );

        // delete of configuration data applies to plugin mode only.
        if( !$this->is_plugin() ) unset( $this->form_fields['wipe_data'] );
    }

    /**
     * Admin Panel Options.
     * Add additional functionality that concerns the management of the plugin
     */
    public function admin_options() {
        // Render all the settings of this plugin first 
        parent::admin_options();
        
        // get orders that have Wise as chosen gateway
        $options = $this->get_wise_orders();
        
        // Insert a custom HTML block with extended settings
        ?>
        <script type="text/javascript">
        jQuery( function($){
            'use strict';
            $('#woocommerce_ew_wise_preview').on('change', function() {
                try {
                    // https://stackoverflow.com/questions/5717093
                    let url = new URL( $(this).val() );
                    window.open(url, '_blank');
                  } catch (_) {}
                return false; 
            });
            // re-apply tiptip for custom block
            $('.extended').tipTip();
        });
        </script>
        <table class="form-table">
        <tbody>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="woocommerce_ew_wise_preview"><?php echo __( 'Preview', 'wise-payment-for-woocommerce' ) ?>
                    <span class="woocommerce-help-tip extended" 
                    title="<?php printf( /* translators: %s: default limit of fetched orders */
                                     __( 'Preview the \'Payment details\' page from the last %s Orders placed with Wise '
                                        .'as the chosen method of payment (opens in new window)', 'wise-payment-for-woocommerce' ), 
                                         $this->limit_orders ) ?>">
                    </span>
                    </label>
                </th>
                <td class="forminp">
                    <fieldset>
                    <legend class="screen-reader-text"><span>Preview</span></legend>
                    <form>
                    <select class="select " name="woocommerce_ew_wise_preview" id="woocommerce_ew_wise_preview" style="">
                    <?php foreach( $options as $k=>$v ): ?>
                    <option value="<?php echo $k ?>"><?php echo $v ?></option>
                    <?php endforeach; ?>
                    </select>
                    </form>
                    </fieldset>
                </td>
            </tr>
        </tbody>
        </table>
        <?php
    }

    /**
     * Generate account details html.
     *
     * @return string
     */
    public function generate_account_details_html() {

        ob_start();

        $country = WC()->countries->get_base_country();
        $locale  = $this->get_country_locale();
        // Get routing number label in the $locale array and use appropriate one.
        $routing_number = isset( $locale[ $country ]['routing_number']['label'] ) ? $locale[ $country ]['routing_number']['label'] : __( 'Routing number', 'wise-payment-for-woocommerce' );
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php esc_html_e( 'Wise Accounts', 'wise-payment-for-woocommerce' ); ?>
                <div style='float:right; margin-right:-23px'>
                <?php echo wc_help_tip( __( "Details of the receiving accounts linked to the balances. "
                                           ."Only some may appear in the thank-you page and e-mails depending on varied criteria", 'wise-payment-for-woocommerce' ) ); ?>
               </div>
            </th>
            <td class="forminp" id="ew_wise_accounts">
                <div class="wc_input_table_wrapper">
                    <table class="widefat wc_input_table sortable" cellspacing="0">
                        <thead>
                            <tr>
                                <th class="sort">&nbsp;</th>
                                <th><?php esc_html_e( 'Scope', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Comma-separated list of WC 2-letter country or continent codes. Determines when an account becomes visible in the thank-you page and emails. "
                                                               ."An empty field means always visible, but only when no other more specific accounts (by country, continent, or currency) are already visible", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'Currency', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Free text that should also include the WC 3-letter currency code for the given balance", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'Account number', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "'Account number' + 'Routing number' usually refer to local transfers. 'Account number' + 'BIC' to international", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( $routing_number ); ?>
                                    <?php echo wc_help_tip( __( "Same as ACH, Sort Code, ABA, BSB, etc. Proper term renders in frontend as per billing country conventions", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'IBAN', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Supersedes 'Account number' in Europe", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'BIC', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Same as SWIFT outside the US. Proper term renders in frontend as per billing country conventions", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'Branch address', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Name and address of the Wise branch for the given balance. Sometimes, better write the partner's bank, especially within the US", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                                <th><?php esc_html_e( 'Remarks', 'wise-payment-for-woocommerce' ); ?>
                                    <?php echo wc_help_tip( __( "Inform customers of any fine-print for usage of a given Wise account", 'wise-payment-for-woocommerce' ) ); ?>
                               </th>
                            </tr>
                        </thead>
                        <tbody class="accounts">
                        <?php
                        $i = -1;
                        if ( $this->account_details ) {
                            foreach ( $this->account_details as $account ) {
                                $i++;
                                echo 
                                '<tr class="account">
                                    <td class="sort"></td>
                                    <td><input type="text" value="' . esc_attr( wp_unslash( $account['account_scope'] ) )    . '" name="ew_wise_account_scope['    . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( wp_unslash( $account['account_currency'] ) ) . '" name="ew_wise_account_currency[' . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( $account['account_number'] )                 . '" name="ew_wise_account_number['   . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( $account['routing_number'] )                 . '" name="ew_wise_routing_number['   . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( $account['iban'] )                           . '" name="ew_wise_iban['             . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( $account['bic'] )                            . '" name="ew_wise_bic['              . esc_attr( $i ) . ']" /></td>
                                    <td><textarea name="ew_wise_branch['  . esc_attr( $i ) . ']" style="background:transparent">' . esc_attr( wp_unslash( $account['branch'] ) )  . '</textarea></td>
                                    <td><textarea name="ew_wise_remarks[' . esc_attr( $i ) . ']" style="background:transparent">' . esc_attr( wp_unslash( $account['remarks'] ) ) . '</textarea></td>
                                </tr>';
                            }
                        }
                        ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4">
                                <!-- https://iconmonstr.com/info-10-svg/ -->
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path d="M13 16h-2v-6h2v6zm-1-10.25c.69 0 1.25.56 1.25 1.25s-.56 1.25-1.25 1.25-1.25-.56-1.25-1.25.56-1.25 1.25-1.25zm0-2.75c5.514 0 10 3.592 10 8.007 0 
                                4.917-5.145 7.961-9.91 7.961-1.937 0-3.383-.397-4.394-.644-1 .613-1.595 1.037-4.272 1.82.535-1.373.723-2.748.602-4.265-.838-1-2.025-2.4-2.025-4.872-.001-4.415 
                                4.485-8.007 9.999-8.007zm0-2c-6.338 0-12 4.226-12 10.007 0 2.05.738 4.063 2.047 5.625.055 1.83-1.023 4.456-1.993 6.368 2.602-.47 6.301-1.508 7.978-2.536 1.418.345 
                                2.775.503 4.059.503 7.084 0 11.91-4.837 11.91-9.961-.001-5.811-5.702-10.006-12.001-10.006z"/></svg>
                                <a href="https://github.com/woocommerce/woocommerce/blob/release/9.1.0.10/plugins/woocommerce/i18n/countries.php#L17" target="_blank"><?php esc_html_e( 'Countries', 'wise-payment-for-woocommerce' ); ?></a> |
                                <a href="https://github.com/woocommerce/woocommerce/blob/release/9.1.0.10/plugins/woocommerce/i18n/continents.php#L16" target="_blank"><?php esc_html_e( 'Continents', 'wise-payment-for-woocommerce' ); ?></a> |
                                <a href="https://github.com/woocommerce/woocommerce/blob/release/9.1.0.10/plugins/woocommerce/includes/wc-core-functions.php#L665" target="_blank"><?php esc_html_e( 'Currencies', 'wise-payment-for-woocommerce' ); ?></a>
                               </th>
                                <th colspan="3">&nbsp;</th>
                                <th colspan="2"><a href="#" class="add button"><?php esc_html_e( '+ Add account', 'wise-payment-for-woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php esc_html_e( 'Remove selected account(s)', 'wise-payment-for-woocommerce' ); ?></a></th>
                           </tr>
                        </tfoot>
                    </table>
                </div>
                <script type="text/javascript">
                    jQuery(function() {
                        jQuery('#ew_wise_accounts').on( 'click', 'a.add', function(){
                            var size = jQuery('#ew_wise_accounts').find('tbody .account').length;
                            jQuery('<tr class="account">\
                                    <td class="sort"></td>\
                                    <td><input type="text" name="ew_wise_account_scope['    + size + ']" /></td>\
                                    <td><input type="text" name="ew_wise_account_currency[' + size + ']" /></td>\
                                    <td><input type="text" name="ew_wise_account_number['   + size + ']" /></td>\
                                    <td><input type="text" name="ew_wise_routing_number['   + size + ']" /></td>\
                                    <td><input type="text" name="ew_wise_iban['             + size + ']" /></td>\
                                    <td><input type="text" name="ew_wise_bic['              + size + ']" /></td>\
                                    <td><textarea name="ew_wise_branch['  + size + ']" style="background:transparent"></textarea></td>\
                                    <td><textarea name="ew_wise_remarks[' + size + ']" style="background:transparent"></textarea></td>\
                                   </tr>').appendTo('#ew_wise_accounts table tbody');
                            return false;
                        });
                    });
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Save account details table.
     */
    public function save_account_details() {

        $accounts = array();

        // phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification -- Nonce verification already handled in WC_Admin_Settings::save()
        if ( isset( $_POST['ew_wise_account_scope'] ) 
          && isset( $_POST['ew_wise_account_currency'] ) 
          && isset( $_POST['ew_wise_account_number'] ) 
          && isset( $_POST['ew_wise_routing_number'] ) 
          && isset( $_POST['ew_wise_iban'] )
          && isset( $_POST['ew_wise_bic'] )  
          && isset( $_POST['ew_wise_branch'] )
          && isset( $_POST['ew_wise_remarks'] ) 
           ) {
            $account_scopes     = wc_clean( wp_unslash( $_POST['ew_wise_account_scope'] ) );
            $account_currencies = wc_clean( wp_unslash( $_POST['ew_wise_account_currency'] ) );
            $account_numbers    = wc_clean( wp_unslash( $_POST['ew_wise_account_number'] ) );
            $routing_numbers    = wc_clean( wp_unslash( $_POST['ew_wise_routing_number'] ) );
            $ibans              = wc_clean( wp_unslash( $_POST['ew_wise_iban'] ) );
            $bics               = wc_clean( wp_unslash( $_POST['ew_wise_bic'] ) );
            $branches           = wc_clean( wp_unslash( $_POST['ew_wise_branch'] ) );
            $remarks            = wc_clean( wp_unslash( $_POST['ew_wise_remarks'] ) );
            
            foreach ( $branches as $i => $branch ) {
                if ( !isset( $branch[ $i ] ) ) {
                    continue;
                }

                $accounts[] = array(
                    'account_scope'    => $account_scopes[ $i ],
                    'account_currency' => $account_currencies[ $i ],
                    'account_number'   => $account_numbers[ $i ],
                    'routing_number'   => $routing_numbers[ $i ],
                    'iban'             => $ibans[ $i ],
                    'bic'              => $bics[ $i ],
                    'branch'           => $branches[ $i ],
                    'remarks'          => $remarks[ $i ]
                );
            }
        }
        // phpcs:enable

        update_option( "woocommerce_{$this->id}_accounts", $accounts );
    }

    /**
     * Output for the order received page.
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_page( $order_id ) {
        $this->bank_details( $order_id );
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( ! $sent_to_admin && 'ew_wise' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
            $this->bank_details( $order->get_id() );
        }
    }

    /**
     * Get bank details and place into a list format.
     *
     * @param int $order_id Order ID.
     */
    private function bank_details( $order_id = '' ) {

        if ( empty( $this->account_details ) ) return;
        
        if ( !is_object( $order = wc_get_order( $order_id ) ) ) return;
        
        // 2-letter codes at: woocommerce/includes/class-wc-geo-ip.php
        $country  = $order->get_billing_country();
        // 3-letter codes at: woocommerce/includes/wc-core-functions.php
        $currency = $order->get_currency();
        // stores different wordings of the same notion depending on local conventions.
        $locale   = $this->get_country_locale();
        
        // for debug of what accounts get displayed depending on billing country
        #$country = 'US'; 
        
        // Get continent the order country belongs to.
        // 2-letter codes at: woocommerce/i18n/continents.php
        $continent = WC()->countries->get_continent_code_for_country( $country );

        #error_log( var_export( $currency, true ) );

        // Get appropriate 'routing number' and 'BIC' wording depending on billing country conventions.
        $routing_label = isset( $locale[ $country ]['routing_number']['label'] ) ? 
                                $locale[ $country ]['routing_number']['label']   : __( 'Routing number', 'wise-payment-for-woocommerce' );
        $bic_label     = isset( $locale[ $country ]['bic']['label'] ) ? 
                                $locale[ $country ]['bic']['label']   : __( 'SWIFT', 'wise-payment-for-woocommerce' );

        $wise_accounts = apply_filters( "woocommerce_{$this->id}_accounts", $this->account_details );

        if ( !empty( $wise_accounts ) ) {
            $account_local_html  = '';
            $account_global_html = '';
            $has_details = false;
           
            foreach ( $wise_accounts as $wise_account ) {
                $wise_account = (object) $wise_account;
                // sanitize and normalise backend inputs.
                $account_scope    = strtoupper( preg_replace( '/\s+/', '', $wise_account->account_scope ) );
                $account_currency = strtoupper( preg_replace( '/\s+/', '', $wise_account->account_currency ) );
                // Wise account and Order in the same currency 
                $same_currency    = false;

                // always display Wise accounts that are in the same currency as an order, irrespective of customer's billing country currency.
                      if ( $account_currency 
                && strpos( $account_currency, $currency ) !== false
                         ) { $same_currency = true; }
                // only display Wise accounts relevant to a customer's billing country.
                  elseif ( $account_scope 
                && strpos( $account_scope, $country )   === false 
                && strpos( $account_scope, $continent ) === false 
                         ) { $same_currency = false; continue; }
                
                // Wise account fields shown on the thank-you page and e-mails.
                $account_fields = apply_filters(
                    "woocommerce_{$this->id}_account_fields", array(
                        'account_currency' => array(
                            'label' => __( 'Currency', 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->account_currency
                        ),
                        'account_number' => array(
                            'label' => __( 'Account number', 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->account_number
                        ),
                        'routing_number' => array(
                            'label' => $routing_label,
                            'value' => $wise_account->routing_number
                        ),
                        'iban' => array(
                            'label' => __( 'IBAN', 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->iban
                        ),
                        'bic' => array(
                            'label' => __( $bic_label, 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->bic
                        ),
                        'branch' => array(
                            'label' => __( 'Bank', 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->branch
                        ),
                        'remarks' => array(
                            'label' => __( 'Remarks', 'wise-payment-for-woocommerce' ),
                            'value' => $wise_account->remarks
                        )
                    ), $order_id
                );

                // buffer the local and global accounts separately.
                if ( $account_scope || $same_currency ) {

                    $account_local_html .= '<ul class="wc-ew_wise-bank-details order_details ew_wise_details">' . PHP_EOL;

                    foreach ( $account_fields as $field_key => $field ) {
                        if ( !empty( $field['value'] ) ) {
                            $account_local_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
                            $has_details   = true;
                        }
                    }
                    $account_local_html .= '<hr></ul>';

                } else {
                    
                    $account_global_html .= '<ul class="wc-ew_wise-bank-details order_details ew_wise_details">' . PHP_EOL;

                    foreach ( $account_fields as $field_key => $field ) {
                        if ( !empty( $field['value'] ) ) {
                            $account_global_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
                            $has_details   = true;
                        }
                    }
                    $account_global_html .= '<hr></ul>';
                }
            }
            if ( $has_details ) {
                
                // Display only local accounts, and if no such available, then display global accounts.
                $account_html = $account_local_html ? $account_local_html : $account_global_html;
                echo '<section class="woocommerce-ew_wise-bank-details">';
                echo $this->get_icon();
                echo '<h2 class="wc-ew_wise-bank-details-heading">' . esc_html__( 'Payment details', 'wise-payment-for-woocommerce' ) . '</h2>' . PHP_EOL;
                if ( $this->instructions ) {
                    echo wp_kses_post( wpautop( wptexturize( wp_kses_post( $this->instructions ) ) ) ) . PHP_EOL;
                }
                if ( $this->account_holder ) {
                    echo __( 'Beneficiary', 'wise-payment-for-woocommerce' ) . ': <strong>' . wp_kses_post( wpautop( wptexturize( wp_kses_post( $this->account_holder ) ) ) ) . '</strong>';
                }
                echo wp_kses_post( PHP_EOL . $account_html ) 
                    .'</section>'. PHP_EOL;
            }
        }
    }
    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {

        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            // Mark as on-hold (we're awaiting the payment).
            $order->update_status( apply_filters( "woocommerce_{$this->id}_process_payment_order_status", 'on-hold', $order ), __( 'Awaiting Wise payment.', 'wise-payment-for-woocommerce' ) );
        } else {
            $order->payment_complete();
        }

        // Remove cart.
        WC()->cart->empty_cart();

        // Return thank-you redirect.
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }

    /**
     * Storage for country-specific jargon of the same notions, different than defaults.
     *
     * @return array
     */
    public function get_country_locale() {

        if ( empty( $this->locale ) ) {

            // Isn't 'Routing Number', nor 'SWIFT'
            $this->locale = apply_filters(
                "woocommerce_get_{$this->id}_locale", array(
                    'AU' => array(
                        'routing_number' => array(
                           'label' => __( 'BSB', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'CA' => array(
                        'routing_number' => array(
                           'label' => __( 'Transit number', 'wise-payment-for-woocommerce' )
                        ),
                        'bic' => array(
                           'label' => __( 'Institution number', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'GB' => array(
                        'routing_number' => array(
                           'label' => __( 'Sort code', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'IN' => array(
                        'routing_number' => array(
                           'label' => __( 'IFSC', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'IT' => array(
                        'routing_number' => array(
                           'label' => __( 'Branch sort', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'NZ' => array(
                        'routing_number' => array(
                           'label' => __( 'Bank code', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'SE' => array(
                        'routing_number' => array(
                           'label' => __( 'Bank code', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'US' => array(
                        'bic' => array(
                           'label' => __( 'BIC', 'wise-payment-for-woocommerce' )
                        )
                    ),
                    'ZA' => array(
                        'routing_number' => array(
                           'label' => __( 'Branch code', 'wise-payment-for-woocommerce' )
                        )
                    )
                )
            );
        }
        return $this->locale;
    }

    /**
     * returns the gateway icon html, and the filter inserts it at the checkout page in the method checkbox.
     *
     * @return string
     */
    public function get_icon() {
        // view-source:https://wise.com/public-resources/assets/logos/wise/brand_logo.svg
        $icon_src = '<svg width="95" height="24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:block;">
        <path d="M93.668 10.69c0-5.382-3.75-9.167-9.006-9.167-6.692 0-11.177 4.862-11.177 11.805 
        0 5.418 3.786 9.167 9.132 9.167 4.862 0 8.486-2.35 9.975-6.1h-5.4c-.718 1.167-2.243 1.902-4.109 
        1.902-2.87 0-4.664-1.902-4.826-4.593h15.106c.198-1.022.305-1.901.305-3.014zm-14.998-.628c.484-2.475 
        2.888-4.449 5.795-4.449 2.511 0 4.413 1.83 4.413 4.45H78.67zM58.971 22.01l.969-5.166c2.96.592 
        3.409-1.077 4.18-5.095l.377-1.992c1.076-5.615 3.247-8.414 9.455-7.66l-.97 5.167c-2.96-.592-3.48 
        1.65-4.143 5.13l-.377 1.992c-1.077 5.687-3.32 8.378-9.49 7.625zM51.723 22.029l3.66-20.022h4.826l-3.642 
        20.022h-4.844zM24.076 2.007h4.683l1.525 14.478L36.42 2.007h4.646L42.7 16.629l5.92-14.622h4.665l-8.486 
        20.022h-5.31L37.764 8.25 31.9 22.03h-5.132l-2.69-20.022z" fill="#2E4369"/><path d="M6.584 7.317l-5.561 
        5.31h9.454l.987-2.314h-4.79L9.67 7.317 7.93 4.321h8.127l-7.5 17.708h2.817L19.86 2.007H3.463l3.121 5.31z" 
        fill="#00B9FF"/></svg>';
        
        // better compatibility when as file
        $icon_url  = plugin_dir_url( dirname(__FILE__) ) . 'assets/images/brand_logo.svg';
        $icon_html = "<img src='{$icon_url}' alt='Wise logo' title='Wise logo' style='max-width:100px' />"; 

        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
    }

    /**
     * Check if in plugin mode or child-theme mode.
     *
     * @return true|false
     */
    private function is_plugin() {
        
        //[2.1.3]: disabled, because the check appears to produce undesirable entries in the logs
        return true; 
        
        $plugin_dir = plugin_dir_url( dirname(__FILE__) );
        $headers = @get_headers( $plugin_dir )[0];
        // https://stackoverflow.com/questions/2280394
        return preg_match( '(200|403)', $headers ) === false ? false : true;
    }

    /**
     * Load orders placed with this gateway for preview purposes.
     *
     * @return array
     */
    private function get_wise_orders() {

        // Get orders payed by this plugin
        $args = array(
            'limit' => $this->limit_orders,
            'payment_method' => 'ew_wise',
        );
            $orders = wc_get_orders( $args );

            $options   = array();
            $options[] = __( 'Choose Wise Order -->', 'wise-payment-for-woocommerce' );
        if( !$orders ) {
            $options[] = __( 'No Wise Orders found', 'wise-payment-for-woocommerce' );
        }
        foreach( $orders as $order ) {
            $options[$order->get_checkout_order_received_url()] = "[{$order->get_id()}][{$order->get_currency()}] {$order->get_billing_first_name()} {$order->get_billing_last_name()} - {$order->get_billing_email()}";
        };
        
        //error_log( var_export( $options, true ) );
        return $options;
    }

}

endif;
