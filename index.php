<?php
/*
 Plugin Name: WooCommerce Banklink Gateway
 Plugin URI: http://nikolaimuhhin.eu/portfolio/wordpress/banklink
 Description: Extends WooCommerce with an Banklink payment gateway.
 Version: 1.0
 Author: Nikolai Muhhin
 Author URI: http://nikolaimuhhin.eu/

 Copyright: © 2009-2011 WooThemes.
 License: GNU General Public License v3.0
 License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function init_plugin_settings()
    {
        add_filter('plugin_action_links', 'woocommerce_gateway_banklink_plugin_settings_link', 10, 2);

        function woocommerce_gateway_banklink_plugin_settings_link($links, $file)
        {
            if ($file == 'woocommerce-banklink-payment-gateway/index.php') {
                $links['settings'] = sprintf('<a href="%s"> %s </a>', admin_url('admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_Gateway_Banklink'), __('Settings', 'wc-gateway-banklink'));
            }

            return $links;
        }
    }

    function init_banklink_admin_scripts()
    {
        wp_register_script('banklink_tabs_init', plugins_url('/assets/js/admin.js', __FILE__), array('jquery'));
        wp_enqueue_script('banklink_tabs_init');
    }

    function init_banklink_frontend_scripts()
    {
        wp_register_script('banklink_init', plugins_url('/assets/js/frontend.js', __FILE__), array('jquery'));
        wp_enqueue_script('banklink_init');
    }

    function woocommerce_cpg_fallback_notice()
    {
        echo '<div class="error"><p>' . sprintf(__('WooCommerce Custom Payment Gateways depends on the last version of %s to work!', 'wc-gateway-banklink'), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a>') . '</p></div>';
    }

    function woocommerce_gateway_banklink_locate_template($template, $template_name, $template_path)
    {
        global $woocommerce;

        $_template = $template;

        if (!$template_path) {
            $template_path = $woocommerce->template_url;
        };

        $plugin_path = untrailingslashit(plugin_dir_path(__FILE__)) . '/woocommerce/';

        $template = locate_template(array($template_path . $template_name, $template_name));

        if (!$template && file_exists($plugin_path . $template_name)) {
            $template = $plugin_path . $template_name;
        }

        if (!$template) {
            $template = $_template;
        }

        return $template;
    }

    add_filter('woocommerce_locate_template', 'woocommerce_gateway_banklink_locate_template', 10, 3);

    add_action('admin_enqueue_scripts', 'init_banklink_admin_scripts');
    add_action('wp_enqueue_scripts', 'init_banklink_frontend_scripts');

    // initialize banklink payment gateway plugin on woocommerce start
    add_action('plugins_loaded', 'woocommerce_gateway_banklink_init', 0);

    function woocommerce_gateway_banklink_init()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', 'woocommerce_cpg_fallback_notice');
            return;
        }

        require(__DIR__ . '/vendor/autoload.php');

        load_plugin_textdomain('wc-gateway-banklink', false, dirname(plugin_basename(__FILE__)) . '/languages');

        init_plugin_settings();

        class WC_Gateway_Banklink extends WC_Payment_Gateway
        {
            var $banks = array(
                "danske" => array('title' => 'Sampo', 'url' => 'https://www2.danskebank.ee/ibank/pizza/pizza', 'charset_parameter' => '', 'charset' => 'iso-8859-1'),
                "lhv" => array("title" => 'LHV Pank', "url" => 'https://www.seb.ee/cgi-bin/unet3.sh/un3min.r', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8'),
                "nordea" => array("title" => 'Nordea', "url" => 'https://netbank.nordea.com/pnbepay/epayn.jsp', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8'),
                "seb" => array("title" => 'SEB', "url" => 'https://www.seb.ee/cgi-bin/unet3.sh/un3min.r', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8'),
                "swedbank" => array('title' => 'Swedbank', 'url' => 'https://www.swedbank.ee/banklink', 'charset_parameter' => 'VK_ENCODING', 'charset' => 'utf-8'),
                "estcard" => array("title" => 'Pankade Kaardikeskus', "url" => 'https://pos.estcard.ee/ecom/iPayServlet', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8'),
                "credit" => array("title" => 'Krediidipank', "url" => 'https://www.seb.ee/cgi-bin/unet3.sh/un3min.r', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8'),
                "emt" => array("title" => 'EMT', "url" => 'https://www.seb.ee/cgi-bin/unet3.sh/un3min.r', "charset_parameter" => 'VK_CHARSET', "charset" => 'utf-8')
            );

            public function __construct()
            {
                global $woocommerce;

                $this->id = 'banklink';
                $this->medthod_title = 'Banklink';
                $this->icon = apply_filters('woocommerce_banklink_icon', $woocommerce->plugin_url() . '/assets/images/icons/banklink.png');
                $this->has_fields = true;

                $this->init_form_fields();
                $this->init_settings();

                $this->debug = $this->get_option('debug');
                $this->testmode = $this->get_option('testmode');
                $this->form_submission_method = $this->get_option('form_submission_method') == 'yes' ? true : false;
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->redirect_page_id = $this->get_option('redirect_page_id');

                // Logs
                if ('yes' == $this->debug)
                    $this->log = $woocommerce->logger();

                // Actions
                add_action('valid-banklink-standard-ipn-request', array($this, 'successful_request'));
                add_action('woocommerce_receipt_banklink', array($this, 'receipt_page'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                add_action('woocommerce_checkout_process', array($this, 'my_custom_checkout_field_process'));
                add_action('woocommerce_checkout_update_order_meta', array( &$this, 'combine_street_number_suffix' ) );



                // Payment listener/API hook
                add_action('woocommerce_api_wc_gateway_banklink', array($this, 'check_ipn_response'));

                if (!$this->is_valid_for_use()) $this->enabled = false;
            }

            /**
             * Check if this gateway is enabled and available in the user's country
             *
             * @access public
             * @return bool
             */
            function is_valid_for_use()
            {
                if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_paypal_supported_currencies', array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB')))) return false;

                return true;
            }

            public function combine_street_number_suffix ( $order_id ) {
                // check for suffix
                echo $_POST['banklink_sel_bank'];


                return;
            }

            function my_custom_checkout_field_process() {
                global $woocommerce;

                echo 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

                // Check if set, if its not set add an error.
                if (!$_POST['banklink_sel_bank']) {
                    $woocommerce->add_error( __('Please enter something into this new shiny field.') );
                }
                else {
                    echo $_POST['banklink_sel_bank'];
                }
            }

            function init_form_fields()
            {
                $this->main_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'wc-gateway-banklink'),
                        'type' => 'checkbox',
                        'label' => __('Enable Banklink Payment Module.', 'wc-gateway-banklink'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => __('Title', 'wc-gateway-banklink'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'wc-gateway-banklink'),
                        'desc_tip' => true,
                        'default' => __('Custom Payment Gateways 1', 'wc-gateway-banklink')
                    ),
                    'description' => array(
                        'title' => __('Description', 'wc-gateway-banklink'),
                        'type' => 'textarea',
                        'description' => __('This controls the description which the user sees during checkout.', 'wc-gateway-banklink'),
                        'default' => __('Desctiptions for Custom Payment Gateways 1.', 'wc-gateway-banklink')
                    ),
                    'show_selection' => array(
                        'title' => __('Show/Hide radio buttons', 'wc-gateway-banklink'),
                        'type' => 'checkbox',
                        'label' => __('Show selection radio buttons.', 'wc-gateway-banklink'),
                        'default' => 'no'
                    ),
                    'submit_on_click' => array(
                        'title' => __('Confirm order on payment selection', 'wc-gateway-banklink'),
                        'type' => 'checkbox',
                        'label' => __('Submit form on select.', 'wc-gateway-banklink'),
                        'default' => 'no'
                    ),
                    'testing' => array(
                        'title' => __('Gateway Testing', 'wc-gateway-banklink'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'testmode' => array(
                        'title' => __('Banklink sandbox', 'wc-gateway-banklink'),
                        'type' => 'checkbox',
                        'label' => __('Enable Banklink sandbox', 'wc-gateway-banklink'),
                        'default' => 'yes',
                        'description' => sprintf(__('Banklink sandbox can be used to test payments. Sign up for a developer account <a href="%s">here</a>.', 'wc-gateway-banklink'), 'https://developer.paypal.com/'),
                    ),
                    'debug' => array(
                        'title' => __('Debug Log', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable logging', 'woocommerce'),
                        'default' => 'no',
                        'description' => sprintf(__('Log Banklink events, such as IPN requests, inside <code>woocommerce/logs/banklink-%s.txt</code>', 'wc-gateway-banklink'), sanitize_file_name(wp_hash('banklink'))),
                    ));

                $this->option_fields = array();

                foreach ($this->banks as $id => $options) {
                    $options = array(
                        $id . '_enabled' => array(
                            'title' => __('Enable/Disable', 'wc-gateway-banklink'),
                            'type' => 'checkbox',
                            'label' => __('Enable ' . $options["title"] . ' Payment Module.', 'wc-gateway-banklink'),
                            'default' => 'no'
                        ),
                        $id . '_title' => array(
                            'title' => __('Title:', 'wc-gateway-banklink'),
                            'type' => 'text',
                            'description' => __('This controls the title which the user sees during checkout.', 'wc-gateway-banklink'),
                            'default' => __($options["title"], 'wc-gateway-banklink')
                        ),
                        $id . '_description' => array(
                            'title' => __('Description:', 'wc-gateway-banklink'),
                            'type' => 'textarea',
                            'description' => __('This controls the description which the user sees during checkout.', 'wc-gateway-banklink'),
                            'default' => __('Pay securely by Credit or Debit card or internet banking through Swedbank Secure Servers.', 'wc-gateway-banklink')
                        ),
                        $id . '_merchant_id' => array(
                            'title' => __('Merchant ID', 'wc-gateway-banklink'),
                            'type' => 'text',
                            'description' => __('This id seller ID')
                        ),
                        $id . '_merchant_name' => array(
                            'title' => __('Merchant name', 'wc-gateway-banklink'),
                            'type' => 'text',
                            'description' => __('This id seller name')
                        ),
                        $id . '_merchant_account' => array(
                            'title' => __('Merchant account', 'wc-gateway-banklink'),
                            'type' => 'text',
                            'description' => __('This id seller account')
                        ),
                        $id . '_merchant_private_key' => array(
                            'title' => __('Merchant private key', 'wc-gateway-banklink'),
                            'type' => 'textarea',
                            'description' => __('This id seller private key')
                        ),
                        $id . '_bank_public_key' => array(
                            'title' => __('Bank public key', 'wc-gateway-banklink'),
                            'type' => 'textarea',
                            'description' => __('This is bank public key')
                        )
                    );

                    $this->option_properties[$id] = $options;
                    $this->option_fields = array_merge($this->option_fields, $options);
                }

                $this->form_fields = array_merge($this->main_fields, $this->option_fields);
            }
            
            /**
             *
             */
            public function admin_options()
            {
                echo '<div id="banklink-properties">';
                echo '<h3>' . __('Banklink Payment Gateway', 'wc-gateway-banklink') . '</h3>';
                echo '<p>' . __('Activate and configure most common payment gateways for Baltic countries') . '</p>';

                echo '<table class="form-table">';
                $this->generate_settings_html($this->main_fields);
                echo '</table>';

                echo '<h2 class="nav-tab-wrapper">';
                foreach ($this->banks as $id => $options) {
                    echo '<a href="#tabs-banklink-' . $id . '" class="nav-tab">' . __($options["title"], 'wc-gateway-banklink') . '</a>';
                }
                echo '</h2>';

                foreach ($this->option_properties as $id => $properties) {
                    echo '<div id="tabs-banklink-' . $id . '" style="display: none;">';
                    echo '<table class="form-table">';
                    $this->generate_settings_html($properties);
                    echo '</table>';
                    echo '</div>';
                }
                echo '</div>';
            }

            /**
             * Successful Payment!
             *
             * @access public
             * @param array $posted
             * @return void
             */
            function successful_request($posted)
            {
                global $woocommerce;

                $posted = stripslashes_deep($posted);

                // Custom holds post ID
                if (!empty($posted['invoice']) && !empty($posted['custom'])) {

                    $order = $this->get_paypal_order($posted);

                    if ('yes' == $this->debug)
                        $this->log->add('paypal', 'Found order #' . $order->id);

                    // Lowercase returned variables
                    $posted['payment_status'] = strtolower($posted['payment_status']);
                    $posted['txn_type'] = strtolower($posted['txn_type']);

                    // Sandbox fix
                    if ($posted['test_ipn'] == 1 && $posted['payment_status'] == 'pending')
                        $posted['payment_status'] = 'completed';

                    if ('yes' == $this->debug)
                        $this->log->add('paypal', 'Payment status: ' . $posted['payment_status']);

                    // We are here so lets check status and do actions
                    switch ($posted['payment_status']) {
                        case 'completed' :
                        case 'pending' :

                            // Check order not already completed
                            if ($order->status == 'completed') {
                                if ('yes' == $this->debug)
                                    $this->log->add('paypal', 'Aborting, Order #' . $order->id . ' is already complete.');
                                exit;
                            }

                            // Check valid txn_type
                            $accepted_types = array('cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money');
                            if (!in_array($posted['txn_type'], $accepted_types)) {
                                if ('yes' == $this->debug)
                                    $this->log->add('paypal', 'Aborting, Invalid type:' . $posted['txn_type']);
                                exit;
                            }

                            // Validate Amount
                            if ($order->get_total() != $posted['mc_gross']) {

                                if ('yes' == $this->debug)
                                    $this->log->add('paypal', 'Payment error: Amounts do not match (gross ' . $posted['mc_gross'] . ')');

                                // Put this order on-hold for manual checking
                                $order->update_status('on-hold', sprintf(__('Validation error: PayPal amounts do not match (gross %s).', 'woocommerce'), $posted['mc_gross']));

                                exit;
                            }

                            // Validate Email Address
                            if (strcasecmp(trim($posted['receiver_email']), trim($this->receiver_email)) != 0) {
                                if ('yes' == $this->debug)
                                    $this->log->add('paypal', "IPN Response is for another one: {$posted['receiver_email']} our email is {$this->receiver_email}");

                                // Put this order on-hold for manual checking
                                $order->update_status('on-hold', sprintf(__('Validation error: PayPal IPN response from a different email address (%s).', 'woocommerce'), $posted['receiver_email']));

                                exit;
                            }

                            // Store PP Details
                            if (!empty($posted['payer_email']))
                                update_post_meta($order->id, 'Payer PayPal address', $posted['payer_email']);
                            if (!empty($posted['txn_id']))
                                update_post_meta($order->id, 'Transaction ID', $posted['txn_id']);
                            if (!empty($posted['first_name']))
                                update_post_meta($order->id, 'Payer first name', $posted['first_name']);
                            if (!empty($posted['last_name']))
                                update_post_meta($order->id, 'Payer last name', $posted['last_name']);
                            if (!empty($posted['payment_type']))
                                update_post_meta($order->id, 'Payment type', $posted['payment_type']);

                            if ($posted['payment_status'] == 'completed') {
                                $order->add_order_note(__('IPN payment completed', 'woocommerce'));
                                $order->payment_complete();
                            } else {
                                $order->update_status('on-hold', sprintf(__('Payment pending: %s', 'woocommerce'), $posted['pending_reason']));
                            }

                            if ('yes' == $this->debug)
                                $this->log->add('paypal', 'Payment complete.');

                            break;
                        case 'denied' :
                        case 'expired' :
                        case 'failed' :
                        case 'voided' :
                            // Order failed
                            $order->update_status('failed', sprintf(__('Payment %s via IPN.', 'woocommerce'), strtolower($posted['payment_status'])));
                            break;
                        case "refunded" :

                            // Only handle full refunds, not partial
                            if ($order->get_total() == ($posted['mc_gross'] * -1)) {

                                // Mark order as refunded
                                $order->update_status('refunded', sprintf(__('Payment %s via IPN.', 'woocommerce'), strtolower($posted['payment_status'])));

                                $mailer = $woocommerce->mailer();

                                $message = $mailer->wrap_message(
                                    __('Order refunded/reversed', 'woocommerce'),
                                    sprintf(__('Order %s has been marked as refunded - PayPal reason code: %s', 'woocommerce'), $order->get_order_number(), $posted['reason_code'])
                                );

                                $mailer->send(get_option('admin_email'), sprintf(__('Payment for order %s refunded/reversed', 'woocommerce'), $order->get_order_number()), $message);

                            }

                            break;
                        case "reversed" :

                            // Mark order as refunded
                            $order->update_status('on-hold', sprintf(__('Payment %s via IPN.', 'woocommerce'), strtolower($posted['payment_status'])));

                            $mailer = $woocommerce->mailer();

                            $message = $mailer->wrap_message(
                                __('Order reversed', 'woocommerce'),
                                sprintf(__('Order %s has been marked on-hold due to a reversal - PayPal reason code: %s', 'woocommerce'), $order->get_order_number(), $posted['reason_code'])
                            );

                            $mailer->send(get_option('admin_email'), sprintf(__('Payment for order %s reversed', 'woocommerce'), $order->get_order_number()), $message);

                            break;
                        case "canceled_reversal" :

                            $mailer = $woocommerce->mailer();

                            $message = $mailer->wrap_message(
                                __('Reversal Cancelled', 'woocommerce'),
                                sprintf(__('Order %s has had a reversal cancelled. Please check the status of payment and update the order status accordingly.', 'woocommerce'), $order->get_order_number())
                            );

                            $mailer->send(get_option('admin_email'), sprintf(__('Reversal cancelled for order %s', 'woocommerce'), $order->get_order_number()), $message);

                            break;
                        default :
                            // No action
                            break;
                    }

                    exit;
                }

            }

            /**
             * Check for PayPal IPN Response
             *
             * @access public
             * @return void
             */
            function check_ipn_response()
            {
                @ob_clean();

                if (!empty($_POST) && $this->check_ipn_request_is_valid()) {

                    header('HTTP/1.1 200 OK');

                    do_action("valid-banklink-standard-ipn-request", $_POST);

                } else {

                    wp_die("PayPal IPN Request Failure");

                }

            }

            /**
             *
             */
            function payment_fields()
            {
                echo '<table class="crt_3" id="bank_links"> <tbody><tr> <td class="p10" align="center"> <nobr> ';
                foreach ($this->banks as $id => $bank) {
                    if ($this->settings[$id . '_enabled'] == 'yes') {
                        echo '<input type="radio" name="' . $this->id . '_sel_bank" value="' . $id . '" checked=""> <img src="' . plugins_url('/assets/images/bank_' . $id . '.png', __FILE__) . '" height="31" align="absmiddle" id="img_' . $id . '" class="bank_icon"> ';
                    }
                }
                echo '</nobr> </td> </tr> </tbody></table>';

                echo '<div class="payment_box payment_method_' . $this->id . '" ' . ($this->chosen ? '' : 'style="display:none;"') . '>';
                if ($this->description) {
                    echo wpautop(wptexturize($this->description));
                }
                echo '</div>';
            }

            /**
             * Output for the order received page.
             *
             * @access public
             * @param $order
             * @return void
             */
            function receipt_page($order)
            {
                global $woocommerce;

                echo '<p>' . __('Thank you for your order, please click the button below to pay with PayPal.', 'woocommerce') . '</p>';

                print_r($woocommerce->checkout());


                echo $this->generate_banklink_form($order);
            }

            /**
             * Generate payu button link
             **/
            function generate_banklink_form($order_id)
            {
                global $woocommerce;

                $order = new WC_Order($order_id);
                $testmode = false;

                if ($this->testmode == 'yes') {
                    $testmode = true;
                }

                $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);

                $product_info = "Order $order_id";

                /*                $woocommerce->add_inline_js('
                                    jQuery("body").block({
                                        message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to PayPal to make payment.', 'woocommerce' ) ) . '",
                                        baseZ: 99999,
                                        overlayCSS: {
                                            background: "#fff",
                                            opacity: 0.6
                                        },
                                        css: {
                                            padding:        "20px",
                                            zindex:         "9999999",
                                            textAlign:      "center",
                                            color:          "#555",
                                            border:         "3px solid #aaa",
                                            backgroundColor:"#fff",
                                            cursor:         "wait",
                                            lineHeight:		"24px",
                                        }
                                    });
                                    jQuery("#submit_banklink_payment_form").click();
                                ');*/

                $protocol = new \Banklink\Protocol\iPizza('uid401777', 'TeadOstad OU', '991234567897',
                    __DIR__ . '/data/iPizza/private_key.pem', // private
                    __DIR__ . '/data/iPizza/public_key.pem', // public
                    $redirect_url, true);

                $swedbank = new \Banklink\Swedbank($protocol, $testmode);

                $swedbankRequest = $swedbank->preparePaymentRequest($order_id, $order->order_total, $product_info);

                return '<form action="' . esc_url($swedbankRequest->getRequestUrl()) . '" method="post">' .
                $swedbankRequest->buildRequestHtml() .
                '<input type="submit" class="button alt" id="submit_banklink_payment_form" value="' . __('Pay via Swedbank', 'wc-gateway-banklink') . '" />
                            <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'wc-gateway-banklink') . '</a>
                        </form>';
            }

            /**
             * Process the payment and return the result
             *
             * @access public
             * @param int $order_id
             * @return array
             */
            function process_payment($order_id)
            {
                $order = new WC_Order($order_id);

                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
            }

            /**
             * Check for valid payu server callback
             **/
            function check_payu_response()
            {
                global $woocommerce;
                if (isset($_REQUEST['txnid']) && isset($_REQUEST['mihpayid'])) {
                    $order_id_time = $_REQUEST['txnid'];
                    $order_id = explode('_', $_REQUEST['txnid']);
                    $order_id = (int)$order_id[0];
                    if ($order_id != '') {
                        try {
                            $order = new WC_Order($order_id);
                            $merchant_id = $_REQUEST['key'];
                            $amount = $_REQUEST['Amount'];
                            $hash = $_REQUEST['hash'];

                            $status = $_REQUEST['status'];
                            $productinfo = "Order $order_id";
                            echo $hash;
                            echo "{$this->salt}|$status|||||||||||{$order->billing_email}|{$order->billing_first_name}|$productinfo|{$order->order_total}|$order_id_time|{$this->merchant_id}";
                            $checkhash = hash('sha512', "{$this->salt}|$status|||||||||||{$order->billing_email}|{$order->billing_first_name}|$productinfo|{$order->order_total}|$order_id_time|{$this->merchant_id}");
                            $transauthorised = false;
                            if ($order->status !== 'completed') {
                                if ($hash == $checkhash) {

                                    $status = strtolower($status);

                                    if ($status == "success") {
                                        $transauthorised = true;
                                        $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                                        $this->msg['class'] = 'woocommerce_message';
                                        if ($order->status == 'processing') {

                                        } else {
                                            $order->payment_complete();
                                            $order->add_order_note('PayU payment successful<br/>Unnique Id from PayU: ' . $_REQUEST['mihpayid']);
                                            $order->add_order_note($this->msg['message']);
                                            $woocommerce->cart->empty_cart();
                                        }
                                    } else if ($status == "pending") {
                                        $this->msg['message'] = "Thank you for shopping with us. Right now your payment staus is pending, We will keep you posted regarding the status of your order through e-mail";
                                        $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                                        $order->add_order_note('PayU payment status is pending<br/>Unnique Id from PayU: ' . $_REQUEST['mihpayid']);
                                        $order->add_order_note($this->msg['message']);
                                        $order->update_status('on-hold');
                                        $woocommerce->cart->empty_cart();
                                    } else {
                                        $this->msg['class'] = 'woocommerce_error';
                                        $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                                        $order->add_order_note('Transaction Declined: ' . $_REQUEST['Error']);
                                        //Here you need to put in the routines for a failed
                                        //transaction such as sending an email to customer
                                        //setting database status etc etc
                                    }
                                } else {
                                    $this->msg['class'] = 'error';
                                    $this->msg['message'] = "Security Error. Illegal access detected";

                                    //Here you need to simply ignore this and dont need
                                    //to perform any operation in this condition
                                }
                                if ($transauthorised == false) {
                                    $order->update_status('failed');
                                    $order->add_order_note('Failed');
                                    $order->add_order_note($this->msg['message']);
                                }
                                add_action('the_content', array(&$this, 'showMessage'));
                            }
                        } catch (Exception $e) {
                            // $errorOccurred = true;
                            $msg = "Error";
                        }
                    }
                }
            }

            function showMessage($content)
            {
                return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
            }

            // get all pages
            function get_pages($title = false, $indent = true)
            {
                $wp_pages = get_pages('sort_column=menu_order');
                $page_list = array();
                if ($title) $page_list[] = $title;
                foreach ($wp_pages as $page) {
                    $prefix = '';
                    // show indented child pages?
                    if ($indent) {
                        $has_parent = $page->post_parent;
                        while ($has_parent) {
                            $prefix .= ' - ';
                            $next_page = get_page($has_parent);
                            $has_parent = $next_page->post_parent;
                        }
                    }
                    // add to page list array array
                    $page_list[$page->ID] = $prefix . $page->post_title;
                }
                return $page_list;
            }
        }

        function woocommerce_add_gateway_banklink_gateway($methods)
        {
            $methods[] = 'WC_Gateway_Banklink';
            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_banklink_gateway');
    }
}