<?php

require_once(DIR_SYSTEM . 'library/xendit.php');

class ControllerExtensionPaymentXendit extends Controller {
    const EXT_ID_PREFIX = 'opencart-xendit-';
    const MINIMUM_AMOUNT = 10000;
    const MINIMUM_AMOUNT_CC = 5000;
    const MAXIMUM_AMOUNT_CC = 200000000;

    public function index() {
        $this->load->language('extension/payment/xendit');

        $data['environment'] = $this->config->get('payment_xendit_environment');
        $data['text_instructions'] = $this->language->get('text_instructions');
        $data['text_test_instructions'] = $this->language->get('text_test_instructions');

        return $this->load->view('extension/payment/xendit', $data);
    }

    /**
     * @param $order
     * @return array
     */
    protected function extract_customer_address_object($order): array
    {
        $customer_address = [
            'street_line1' => $order['payment_address_1'] ?? '',
            'street_line2' => $order['payment_address_2'] ?? '',
            'state' => $order['payment_zone'] ?? '',
            'postal_code' => $order['payment_postcode'] ?? '',
            'city' => $order['payment_city'] ?? '',
            'country' => $order['payment_iso_code_2'] ?? ''
        ];

        return array_filter($customer_address);
    }

    /**
     * @param $order
     * @return array
     */
    protected function extract_customer_object($order): array
    {
        $customer = [
            'email' => $order['email'] ?? '',
            'given_names' => $order['payment_firstname'] ?? '',
            'surname' => $order['payment_lastname'] ?? '',
            'mobile_number' => $order['telephone'] ?? '',
        ];
        $address = $this->extract_customer_address_object($order);
        if(!empty($address)){
            $customer['addresses'] = [$address];
        }
        return array_filter($customer);
    }

    public function process_payment() {
        $this->load->model('extension/payment/xendit');
        $this->load->model('checkout/order');
        $this->load->model('extension/total/shipping');
        $this->load->language('extension/payment/xendit');

        $order_id = $this->session->data['order_id'];
        $order = $this->model_checkout_order->getOrder(
            $order_id
        );

        $api_key = $this->get_api_key();

        Xendit::set_secret_key($api_key['secret_key']);
        Xendit::set_public_key($api_key['public_key']);

        $store_name = $this->config->get('config_name');
        $amount = (int)$order['total'];
        $invoice_hash = strtolower($_POST['invoice_hash']);

        if ($invoice_hash != 'credit_card' && $amount < self::MINIMUM_AMOUNT) {
            $json['error'] = 'The minimum amount for using this payment is IDR ' . number_format(self::MINIMUM_AMOUNT) . '. Please put more item(s) to reach the minimum amount. Code: 100001';
        } else if ($invoice_hash == 'credit_card' && $amount < self::MINIMUM_AMOUNT_CC) {
            $json['error'] = 'The minimum amount for using this payment is IDR ' . number_format(self::MINIMUM_AMOUNT_CC) . '. Please put more item(s) to reach the minimum amount. Code: 100001';
        } else if ($invoice_hash == 'credit_card' && $amount > self::MAXIMUM_AMOUNT_CC) {
            $json['error'] = 'The maximum amount for using this payment is IDR ' . number_format(self::MAXIMUM_AMOUNT_CC) . '. Maximum amount exceeded. Code: 100001';
        }

        if (isset($json['error'])) {
            $this->response->addHeader('Content-Type: application/json');
            return $this->response->setOutput(json_encode($json));
        }

        $request_payload = array(
            'external_id' => self::EXT_ID_PREFIX . $order_id,
            'amount' => $amount,
            'payer_email' => $order['email'],
            'description' => 'Payment for order #' . $order_id . ' at ' . $store_name,
            'client_type' => 'INTEGRATION',
            'success_redirect_url' => $this->url->link('checkout/success'),
            'failure_redirect_url' => $this->url->link('checkout/cart'),
            'platform_callback_url' => $this->url->link('extension/payment/xendit/process_notification')
        );

        $customer_object = $this->extract_customer_object($order);
        if(!empty($customer_object)){
            $request_payload['customer'] = $customer_object;
        }

        $request_url = '/payment/xendit/invoice';
        $request_options = array(
            'store_name' => $store_name
        );

        try {
            $response = Xendit::request($request_url, Xendit::METHOD_POST, $request_payload, $request_options);

            if (isset($response['error_code'])) {
                $message = $response['message'];

                if (isset($response['code'])) {
                    $message .= " Code: " . $response['code'];
                }
                $json['error'] = $message;
            }
            else {
                $this->model_extension_payment_xendit->addOrder($order, $response, $this->config->get('payment_xendit_environment'), 'invoice');
                $message = 'Invoice ID: ' . $response['id'] . '. Redirecting..';
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    1,
                    $message,
                    false
                );

                $json['redirect'] = $response['invoice_url'];
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function process_notification() {
        if (($this->request->server['REQUEST_METHOD'] == 'POST')) {
            $this->load->model('extension/payment/xendit');
            $this->load->model('checkout/order');

            try {
                $original_response = json_decode(file_get_contents('php://input'), true);
                $invoice_id = $original_response['id'];

                $api_key = $this->get_api_key();
                Xendit::set_secret_key($api_key['secret_key']);
                $store_name = $this->config->get('config_name');
                $request_url = '/payment/xendit/invoice/' . $invoice_id;
                $request_options = array(
                    'store_name' => $store_name
                );
                $response = Xendit::request($request_url, Xendit::METHOD_GET, array(), $request_options);

                if ( isset($response['error_code']) ) {
                    $message = 'Could not get xendit invoice. Invoice id: ' . $invoice_id . '. Cancelling order.';
                    $this->response->addHeader('HTTP/1.1 400 Bad Request');
                    $this->response->setOutput($message);
                    return;
                }

                $external_id = $response['external_id'];
                $order_id = preg_replace('/[^0-9]/', '', $external_id);
                $order_info = $this->model_checkout_order->getOrder($order_id);

                if (empty($order_info)) {
                    $message = 'Order not found. Order id: ' . $order_id . '.';
                    $this->response->addHeader('HTTP/1.1 404 Not Found');
                    $this->response->setOutput($message);
                    return;
                }

                $order_status_id = $order_info['order_status_id'];

                // if status is not pending
                if ($order_status_id != 1) {
                    $message = 'Order status is not pending. Order id: ' . $order_id . '.';
                    $this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
                    $this->response->setOutput($message);
                    return;
                }

                return $this->process_order($response, $original_response, $order_id);
            } catch (Exception $e) {
                echo 'something';
            }
        } else {
            echo 'Unexpected request method';
        }
    }

    private function get_api_key() {
        if ($this->config->get('payment_xendit_environment') === 'live') {
            return array(
                'secret_key' => $this->config->get('payment_xendit_live_secret_key'),
                'public_key' => $this->config->get('payment_xendit_live_public_key')
            );
        } else {
            return array(
                'secret_key' => $this->config->get('payment_xendit_test_secret_key'),
                'public_key' => $this->config->get('payment_xendit_test_public_key')
            );
        }
    }

    private function process_order($response, $original_response, $order_id) {
        if ($response['status'] === 'PAID' || $response['status'] === 'SETTLED') {
            $this->cart->clear();
            $message = 'Payment successful. Invoice ID: ' . $response['id'];

            $this->model_extension_payment_xendit->paidOrder($order_id, $response['paid_at'], array(
                'xendit_invoice_fee' => $original_response['fees_paid_amount']
            ));

            $this->model_checkout_order->addOrderHistory(
                $order_id,
                2,
                $message,
                false
            );
            $this->response->setOutput($message);
        } else {
            $message = 'Invoice not paid or settled. Cancelling order. Invoice ID: ' . $response['id'];
            return $this->cancel_order($order_id, $message);
        }
    }

    private function cancel_order($order_id, $message) {
        $this->cart->clear();
        $this->model_extension_payment_xendit->cancelOrder($order_id);
        $this->model_checkout_order->addOrderHistory(
            $order_id,
            7,
            $message,
            false
        );

        $message = 'Successfully cancelled order ' . $order_id;
        $this->response->setOutput($message);
    }
}
