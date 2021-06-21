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
            $this->icon = 'https://avatars.githubusercontent.com/u/22961759?s=200&v=4';
            $this->method_title = 'MobilePay';
            $this->method_description = 'Pay with MobilePay';
            $this->has_fields = true;

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();

            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->enabled = $this->get_option('enabled');
            $this->vat_number = $this->get_option('vat_number');
            $this->private_key = $this->get_option('private_key');

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

            $this->add_option($option_name, $value);
        }

        public function process_admin_options()
        {
            parent::process_admin_options();


            $generate_new_keys = $this->get_option('generate_new_keys');
            if($generate_new_keys === 'yes')
            {
                $keys = $this->get_new_keys();
                $this->upsert_option('private_key', $keys['private_key']);
                $this->upsert_option('public_key', $keys['public_key']);

                $this->update_option('generate_new_keys', 'no');

                $encoded_key = base64_encode($keys['public_key']);
                $url = sprintf("https://wp7586.danskenet.net/integrator/Integration?vatNumber=%s&publicKey=%s", $this->vat_number, $encoded_key);

                header("Location: $url");
                exit;
            }

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

        public function payment_fields() {

            $jwt = new MobilePay_JWT($this->private_key, $this->vat_number);
            $http_client = new MobilePay_HttpClient($jwt->get_token());


            $payment_response = $http_client->create_payment(array("create" => "payment"));

            echo sprintf("<pre>%s</pre>", json_encode($payment_response));

           // echo $this->get_option("public_key").PHP_EOL.$jwt->get_token();
        }

        public function validate_fields() {
            return true;

        }

        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
            );

        }

        public function webhook() {
            $order = wc_get_order( $_GET['id'] );
            $order->payment_complete();
            $order->reduce_order_stock();

        }

    }

}
