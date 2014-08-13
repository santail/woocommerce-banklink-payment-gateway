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
                "danske" => array('class' => 'DanskeBank', 'protocol' => 'iPizza', 'title' => 'Danske Bank'),
                "credit" => array('class' => 'Krediidipank', 'protocol' => 'iPizza', 'title' => 'Krediidipank'),
                "lhv" => array('class' => 'LHV', 'protocol' => 'iPizza', "title" => 'LHV Pank'),
                "nordea" => array('class' => 'Nordea', 'protocol' => 'Solo', "title" => 'Nordea'),
                "seb" => array('class' => 'SEB', 'protocol' => 'iPizza', "title" => 'SEB'),
                "swedbank" => array('class' => 'Swedbank', 'protocol' => 'iPizza', 'title' => 'Swedbank')
            );

            public function __construct()
            {
                global $woocommerce;

                $this->id = 'banklink';
                $this->medthod_title = 'Banklink';
                $this->icon = apply_filters('woocommerce_banklink_icon', plugins_url('/assets/images/icons/banklink.png', __FILE__));
                $this->has_fields = true;

                $this->init_form_fields();
                $this->init_settings();

                $this->debug = $this->get_option('debug');
                $this->testmode = $this->get_option('testmode');
                $this->form_submission_method = $this->get_option('form_submission_method') == 'yes' ? true : false;
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');

                // Logs
                if ('yes' == $this->debug)
                    $this->log = $woocommerce->logger();

                // Actions
                add_action('woocommerce_receipt_banklink', array($this, 'receipt_page'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                // Validate checkout form for payment method and selected method type if Banklink is chosen
                add_action('woocommerce_checkout_process', array($this, 'checkout_process'));

                // Add chosen payment method to order meta
                add_action('woocommerce_checkout_update_order_meta', array($this, 'update_order_meta_with_custom_values'));

                if (!$this->is_valid_for_use()) $this->enabled = false;
            }

            function is_valid_for_use()
            {
                if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_paypal_supported_currencies', array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB')))) return false;

                return true;
            }

            function checkout_process($posted)
            {
                if ($posted['payment_method'] == $this->id) {
                    global $woocommerce;

                    // Check if set, if its not set add an error.
                    if (!$_POST['banklink_sel_bank']) {
                        $woocommerce->add_error(__('Please mark payment method'));
                    }
                }
            }

            function update_order_meta_with_custom_values($order_id)
            {
                if ($_POST['banklink_sel_bank']) update_post_meta($order_id, 'banklink_sel_bank', esc_attr($_POST['banklink_sel_bank']));
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

            function payment_fields()
            {
                $container = "";
                $is_payments_activated = false;

                $container .= '<table class="crt_3" id="bank_links"> <tbody><tr> <td class="p10" align="center"> <nobr> ';
                foreach ($this->banks as $id => $bank) {
                    if ($this->settings[$id . '_enabled'] == 'yes') {
                        $is_payments_activated |= true;
                        $container .= '<input type="radio" name="' . $this->id . '_sel_bank" value="' . $id . '" checked=""> <img src="' . plugins_url('/assets/images/bank_' . $id . '.png', __FILE__) . '" height="31" align="absmiddle" id="img_' . $id . '" class="bank_icon"> ';
                    }
                }
                $container .= '</nobr> </td> </tr> </tbody></table>';

                $container .= '<div class="payment_box payment_method_' . $this->id . '" ' . ($this->chosen ? '' : 'style="display:none;"') . '>';
                if ($this->description) {
                    $container .= wpautop(wptexturize($this->description));
                }
                $container .= '</div>';

                if ($is_payments_activated) {
                    echo $container;
                }
            }

            function receipt_page($order_id)
            {
                global $woocommerce;

                echo '<p>' . __('Thank you for your order, please click the button below to pay with PayPal.', 'woocommerce') . '</p>';

                echo $this->generate_banklink_form($order_id);
            }

            function generate_banklink_form($order_id)
            {
                global $woocommerce;

                $order = new WC_Order($order_id);
                $testmode = false;

                if ($this->testmode == 'yes') {
                    $testmode = true;
                }

                $product_info = "Order $order_id";

                if ($this->settings) {
                    $woocommerce->add_inline_js('
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
                    ');
                }

                $payment_method = $order->order_custom_fields['banklink_sel_bank'][0];

                $bank = $this->banks[$payment_method];

                switch ($bank['protocol']) {
                    case 'iPizza':
                        $tempPrivateKeyFile = createTemporalFileforKey('merchant_private_key', $$this->settings[$payment_method . '_merchant_private_key']);
                        $tempPublicKeyFile = createTemporalFileforKey('merchant_public_key', $$this->settings[$payment_method . '_merchant_public_key']);

                        $protocol = new  \Banklink\Protocol\iPizza($this->settings[$payment_method . '_merchant_id'],
                            $this->settings[$payment_method . '_merchant_name'],
                            $this->settings[$payment_method . '_merchant_account'],
                            $tempPrivateKeyFile, // private
                            $tempPublicKeyFile, // public
                            add_query_arg( 'utm_nooverride', '1', $this->get_return_url($order) ), 
                            true);
                        break;
                    case 'Solo':
                        $protocol = new  \Banklink\Protocol\Solo($this->settings[$payment_method . '_merchant_id'],
                            $this->settings[$payment_method . '_merchant_private_key'],
                            add_query_arg( 'utm_nooverride', '1', $this->get_return_url($order) ), 
                            $this->settings[$payment_method . '_merchant_name'],
                            $this->settings[$payment_method . '_merchant_account']);
                        break;
                    default:
                        break;
                }

                $bank_name = '\Banklink\\' . $bank['class'];

                $banklink = new $bank_name($protocol, $testmode);

                $paymentRequest = $banklink->preparePaymentRequest($order_id, $order->order_total, $product_info);

                return '<form action="' . esc_url($paymentRequest->getRequestUrl()) . '" method="post">' .
                    $paymentRequest->buildRequestHtml() .
                    '<input type="submit" class="button alt" id="submit_banklink_payment_form" value="' . __('Pay via Swedbank', 'wc-gateway-banklink') . '" />
                    <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'wc-gateway-banklink') . '</a>
                </form>';
            }
            
            function createTemporalFileforKey($prefix, $content) 
            {
                $tempFile = tempnam(sys_get_temp_dir(), $prefix);
                
                $handle = fopen($tempFile, "w");
                fwrite($handle, $content);
                fclose($handle);
                
                return $tempFile;
            }

            function process_payment($order_id)
            {
                $order = new WC_Order($order_id);

                return array(
                    'result' 	=> 'success',
                    'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
            }

            function showMessage($content)
            {
                return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
            }

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