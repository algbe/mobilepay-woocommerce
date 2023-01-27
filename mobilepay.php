<?php
/**
 * Plugin Name: MobilePay
 * Plugin URI: https://mobilepay.dk
 * Description: With this plugin you can have MobilePay as your payment gateway
 * Version: 1.0
 * Author: MobilePay
 * Author URI: https://mobilepay.dk
 */

define("MOBILEPAY_NO_OPTION", "__NO_OPTION__");
define('MOBILEPAY_PLUGIN_PATH', plugin_dir_path(__FILE__)); 

require_once MOBILEPAY_PLUGIN_PATH . 'includes/mobilepay-jwt.php';
require_once MOBILEPAY_PLUGIN_PATH . 'includes/mobilepay-http-client.php';


add_filter('woocommerce_payment_gateways', 'mobilepay_add_gateway_class');
function mobilepay_add_gateway_class($gateways) {
    $gateways[] = 'WC_MobilePay_Gateway';

    return $gateways;
}

add_action('plugins_loaded', 'mobilepay_init_gateway_class');
function mobilepay_init_gateway_class() {

    class WC_MobilePay_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'mobilepay';
            $this->icon = 'https://avatars.githubusercontent.com/u/22961759?s=30&v=4';
            $this->method_title = 'MobilePay';
            $this->method_description = 'Pay with MobilePay';
            $this->has_fields = true;
            $this->title = "MobilePay";

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();

            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->vat_number = $this->get_option('vat_number');
            $this->private_key = get_option('private_key');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action( 'woocommerce_api_mobilepay_payment_complete', array( $this, 'webhook' ) );
            
        }

        private function get_new_keys()
        {
            $rsa_config = array(
                'digest_alg' => 'sha512',
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA
            );

            $pair = openssl_pkey_new($rsa_config);
            openssl_pkey_export($pair, $private_key);
            $public_key = openssl_pkey_get_details($pair);

            return array( 
                'private_key' => $private_key,
                'public_key' => $public_key['key']
            );
        }

        private function option_exists($option_name)
        {
            return $this->get_option($option_name, MOBILEPAY_NO_OPTION) !== MOBILEPAY_NO_OPTION;
        }

        private function upsert_option($option_name, $value)
        {
            if($this->option_exists($option_name))
            {
                $this->update_option($option_name, $value);
                return;
            }

            add_option($option_name, $value);
        }

        public function process_admin_options()
        {
            parent::process_admin_options();


            $generate_new_keys = $this->get_option('generate_new_keys');
            if($generate_new_keys === 'yes')
            {
                $keys = $this->get_new_keys();
                $this->update_option('private_key', $keys['private_key']);
                $this->update_option('public_key', $keys['public_key']);

                $this->update_option('generate_new_keys', 'no');

                $encoded_key = base64_encode($keys['public_key']);
                $encoded_redirect_url = base64_encode($this->get_current_url());
                $url = sprintf("https://mobilepayintegrator.azurewebsites.net/Integration/Auth?vatNumber=%s&publicKey=%s&clientRedirectUrl=%s", $this->vat_number, $encoded_key, $encoded_redirect_url);

                header("Location: $url");
                exit;
            }

        }
        
        private function get_current_url() {

            if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')   
                 $url = "https://";   
            else  
                 $url = "http://";   
            // Append the host(domain name, ip) to the URL.   
            $url.= $_SERVER['HTTP_HOST'];   
            
            // Append the requested resource location to the URL   
            $url.= $_SERVER['REQUEST_URI'];    

            return $url;
        }

        public function init_form_fields() {
            
            $this->form_fields = array(
                'private_key' => array(
                    'type' => 'hidden',
                    'default' => ''
                ),
                'public_key' => array(
                    'type' => 'hidden',
                    'default' => ''
                ),
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable MobilePay Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'vat_number' => array(
                    'title'       => 'VAT Number',
                    'type'        => 'text',
                    'description' => 'VAT number of the merchant',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'capture_on_order_complete' => array(
                    'title'       => 'Capture on order completion',
                    'label'       => 'Active',
                    'type'        => 'checkbox',
                    'default'     => 'yes',
                ),
                'generate_new_keys' => array(
                    'title'       => 'Integrate with MobilePay',
                    'description' => 'Checking this checkbox you will be redirected to MobilePay website to give the consent to this plugin to act on behalf of your shop.',
                    'label'       => 'Integrate now?',
                    'type'        => 'checkbox',
                    'default'     => 'no',
                    'desc_tip'    => false
                ),
            );
        }

        public function process_payment( $order_id ) {
            
            global $woocommerce;
            $order = wc_get_order( $order_id );
            
            $jwt = new MobilePay_JWT($this->get_option('private_key'), $this->get_option('vat_number'));
            $httpClient = new MobilePay_HttpClient($jwt->get_token());
            
            $request = array(
                'ConsumerName' => $order->get_billing_first_name(),
                'TotalAmount' => $order->get_total(),
                'TotalVATAmount' => $order->get_total_tax(),
                'ConsumerAddressLines' => array (
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                    $order->get_billing_country()
                ),
                'InvoiceNumber' => $order->get_order_number(),
                'RedirectUrl' => $this->get_return_url( $order ),
            );

            foreach ( $order->get_items() as $item_id => $item ) {

                $request['InvoiceArticles'][] = array(
                    //'ArticleNumber' => (string)$item->get_product_id(),
                    'ArticleDescription' => $item->get_name(),
                    'TotalVATAmount' => $item->get_subtotal_tax(),
                    'TotalPriceIncludingVat' => $item->get_subtotal(),
                    'Unit' => $item->get_product()->get_sku(),
                    'Quantity' => $item->get_quantity(),
                    'PricePerUnit' => $item->get_total()
                );
            }

            error_log(print_r($request, true));

            $mpResponse = $httpClient->create_payment_link( $request );

            error_log(print_r($mpResponse, true));

            
            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __( 'Awaiting cheque payment', 'woocommerce' ));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Return thank you redirect
            return array(
                'result' => 'success',
                'redirect' => $mpResponse['response']
            );
            //$url = sprintf("https://mpeshopintegrator.azurewebsites.net/Integration/Auth?vatNumber=%s&publicKey=%s", $this->vat_number, $encoded_key);

            //header("Location: $url");
            //exit;
        }

        public function webhook() {
            $order = wc_get_order( $_GET['id'] );
            $order->payment_complete();
            $order->reduce_order_stock();

        }
        
        public function payment_fields() {
        }

        public function validate_fields() {
            return true;
        }


    }

}
