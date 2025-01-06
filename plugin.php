<?php
/**
 * Plugin Name: WooCommerce Custom Payment Gateway
 * Plugin URI: https://yourwebsite.com
 * Description: Custom payment gateway integration for WooCommerce
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * 
 * Requires WooCommerce: 3.0+
 * WC requires at least: 3.0
 * WC tested up to: 8.5
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Add the gateway to WooCommerce
 * This filter adds our custom gateway to the list of available payment methods
 */
add_filter('woocommerce_payment_gateways', 'add_custom_gateway_class');
function add_custom_gateway_class($gateways) {
    $gateways[] = 'WC_Custom_Payment_Gateway';
    return $gateways;
}

/**
 * Custom Payment Gateway Class
 * This class handles all the functionality of our custom payment gateway
 */
class WC_Custom_Payment_Gateway extends WC_Payment_Gateway {
    // Class variables for API configuration
    private $api_key;
    private $api_secret;
    private $auth_token;
    private $test_mode;

    /**
     * Constructor for the gateway
     * Initialize all the basic gateway settings
     */
    public function __construct() {
        // Basic gateway settings
        $this->id = 'custom_gateway'; // Unique ID for your gateway
        $this->icon = ''; // URL of the icon that will be displayed on checkout
        $this->has_fields = false; // True if you want to show custom fields on checkout
        $this->method_title = 'Custom Payment Gateway';
        $this->method_description = 'Redirects customers to payment gateway for payment';

        // URL for gateway API endpoints
        $this->auth_endpoint = 'https://api.payment-gateway.com/auth';
        $this->payment_endpoint = 'https://api.payment-gateway.com/checkout';

        // Load gateway settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key = $this->get_option('api_key');
        $this->api_secret = $this->get_option('api_secret');
        $this->test_mode = 'yes' === $this->get_option('test_mode');

        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     * Define all the fields that will appear in WooCommerce settings
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Custom Payment Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Custom Payment',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay securely via our payment gateway.',
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Enter your API Key',
            ),
            'api_secret' => array(
                'title'       => 'API Secret',
                'type'        => 'password',
                'description' => 'Enter your API Secret',
            ),
            'test_mode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => 'Place the payment gateway in test mode.',
                'default'     => 'yes',
                'desc_tip'    => true,
            )
        );
    }

    /**
     * Authenticate with the payment gateway
     * This method handles the API authentication and token retrieval
     * 
     * @return bool
     */
    private function authenticate() {
        // Set the API endpoint based on test mode
        $auth_endpoint = $this->test_mode ? 
            'https://test-api.payment-gateway.com/auth' : 
            'https://api.payment-gateway.com/auth';

        // Make authentication request
        $response = wp_remote_post($auth_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret)
            ),
            'body' => json_encode(array(
                'grant_type' => 'client_credentials'
            ))
        ));

        if (is_wp_error($response)) {
            WC_Admin_Notices::add_custom_error('Payment gateway authentication failed');
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            $this->auth_token = $body['access_token'];
            return true;
        }

        return false;
    }

    /**
     * Process Payment
     * This method is called when the user clicks on the checkout button
     * 
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        // Get the order
        $order = wc_get_order($order_id);

        // Authenticate with the payment gateway
        if (!$this->authenticate()) {
            wc_add_notice('Payment gateway authentication failed.', 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Prepare the payment data
        $payment_data = array(
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'order_id' => $order_id,
            'return_url' => $this->get_return_url($order),
            'cancel_url' => $order->get_cancel_order_url(),
            // Add any additional fields required by your payment gateway
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        );

        // Set the payment endpoint based on test mode
        $payment_endpoint = $this->test_mode ? 
            'https://test-api.payment-gateway.com/checkout' : 
            'https://api.payment-gateway.com/checkout';

        // Initialize payment session with gateway
        $response = wp_remote_post($payment_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->auth_token
            ),
            'body' => json_encode($payment_data)
        ));

        if (is_wp_error($response)) {
            wc_add_notice('Payment connection error.', 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        // Check if we got the checkout URL
        if (isset($result['checkout_url'])) {
            // Update order status
            $order->update_status('pending', __('Awaiting payment confirmation.', 'woocommerce'));

            // Return success and redirect to payment gateway
            return array(
                'result' => 'success',
                'redirect' => $result['checkout_url']
            );
        }

        // If we get here, something went wrong
        wc_add_notice('Payment gateway error.', 'error');
        return array(
            'result' => 'fail',
            'redirect' => ''
        );
    }

    /**
     * Webhook handler (optional)
     * This method handles payment confirmation from the gateway
     * You'll need to setup the webhook URL in your gateway's dashboard
     */
    public function webhook_handler() {
        // Get the webhook data
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        // Verify the webhook signature (implementation depends on your gateway)
        if (!$this->verify_webhook_signature($payload)) {
            status_header(400);
            exit('Invalid signature');
        }

        // Get the order
        $order = wc_get_order($data['order_id']);
        if (!$order) {
            status_header(404);
            exit('Order not found');
        }

        // Update the order status based on payment status
        if ($data['status'] === 'completed') {
            $order->payment_complete();
        } elseif ($data['status'] === 'failed') {
            $order->update_status('failed', __('Payment failed.', 'woocommerce'));
        }

        status_header(200);
        exit('Webhook processed');
    }

    /**
     * Verify webhook signature
     * Implementation depends on your payment gateway
     * 
     * @param string $payload
     * @return bool
     */
    private function verify_webhook_signature($payload) {
        // Implement signature verification based on your gateway's requirements
        return true;
    }
}
