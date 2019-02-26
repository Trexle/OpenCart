<?php

/*
 * Rev 1
 * @todo fix url
 * @todo fix token
 */

class ControllerExtensionPaymentTrexle extends Controller
{
    public function index()
    {
        $this->load->language('extension/payment/trexle');

        $data['text_credit_card'] = $this->language->get('text_credit_card');
        $data['text_wait'] = $this->language->get('text_wait');
        $data['entry_cc_number'] = $this->language->get('entry_cc_number');
        $data['entry_cc_expire_date'] = $this->language->get('entry_cc_expire_date');
        $data['entry_cc_cvv2'] = $this->language->get('entry_cc_cvv2');
        $data['button_confirm'] = $this->language->get('button_confirm');

        $data['months'] = array();

        for ($i = 1; $i <= 12; $i++) {
            $data['months'][] = array(
                'text' => sprintf('%02d', $i) . ' - ' . strftime('%B', mktime(0, 0, 0, $i, 1, 2000)),
                'value' => sprintf('%02d', $i)
            );
        }

        $today = getdate();

        $data['year_expire'] = array();

        for ($i = $today['year']; $i < $today['year'] + 11; $i++) {
            $data['year_expire'][] = array(
                'text' => strftime('%Y', mktime(0, 0, 0, 1, 1, $i)),
                'value' => strftime('%Y', mktime(0, 0, 0, 1, 1, $i))
            );
        }

        return $this->load->view('extension/payment/trexle', $data);

    }

    public function send()
    {

        $json = array();
        $url = "https://core.trexle.com/api/v1/charges";
        $client_token = $this->config->get('payment_trexle_token');

        if ((int)$this->config->get('payment_trexle_server') == 0) {
            $url = "https://core.trexle.com/api/v1/charges";
        }

        $this->load->model('checkout/order');
        $this->load->language('extension/payment/texle');
        $error = 0;

        if (!isset($_POST['cc_number'])) {
            $json['error']['cc_number'] = true;
            $error++;
        } else if (isset($_POST['cc_number']) && trim($_POST['cc_number']) == "") {
            $json['error']['cc_number'] = true;
            $error++;
        }

        if (!isset($_POST['cc_cvv2'])) {
            $json['error']['cc_cvv2'] = true;
            $error++;
        } else if (isset($_POST['cc_cvv2']) && trim($_POST['cc_cvv2']) == "") {
            $json['error']['cc_cvv2'] = true;
            $error++;
        }


        if ($error == 0) {
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            $data = [
                'amount' => number_format(floatval($order_info['total']), 2, '.', '') * 100,
                'currency' => $order_info['currency_code'],
                'description' => 'Order: #' . sprintf('%08d', trim((int)$order_info['order_id'])),
                'email' => html_entity_decode($order_info['email'], ENT_QUOTES, 'UTF-8'),
                'ip_address' => $order_info['ip'],
                'card[number]' => trim(html_entity_decode(preg_replace('/[^0-9]/', '', $this->request->post['cc_number']), ENT_QUOTES, 'UTF-8')),
                'card[expiry_month]' => html_entity_decode($this->request->post['cc_expire_date_month'], ENT_QUOTES, 'UTF-8'),
                'card[expiry_year]' => html_entity_decode($this->request->post['cc_expire_date_year'], ENT_QUOTES, 'UTF-8'),
                'card[cvc]' => html_entity_decode(preg_replace('/[^0-9]/', '', $this->request->post['cc_cvv2']), ENT_QUOTES, 'UTF-8'),
                'card[name]' => html_entity_decode($order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'], ENT_QUOTES, 'UTF-8'),
                'card[address_line1]' => html_entity_decode($order_info['payment_address_1'], ENT_QUOTES, 'UTF-8'),
                'card[address_line2]' => '-',
                'card[address_city]' => html_entity_decode($order_info['payment_city'], ENT_QUOTES, 'UTF-8'),
                'card[address_postcode]' => html_entity_decode($order_info['payment_postcode'], ENT_QUOTES, 'UTF-8'),
                'card[address_state]' => ($order_info['payment_iso_code_2'] != 'US') ? $order_info['payment_zone'] : html_entity_decode($order_info['payment_zone_code'], ENT_QUOTES, 'UTF-8'),
                'card[address_country]' => html_entity_decode($order_info['payment_country'], ENT_QUOTES, 'UTF-8')
            ];

            $curl = curl_init($url);

            curl_setopt($curl, CURLOPT_USERPWD, $client_token . ':');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json'));

            $response = curl_exec($curl);

            if (curl_error($curl)) {
                $json['error']['message'] = 'CURL ERROR: ' . curl_errno($curl) . '::' . curl_error($curl);

            } elseif ($response) {
                $response_object = json_decode($response, true);

                if (count($response_object)) {

                    if (isset($response_object['response']['success']) && $response_object['response']['success'] == true) {

                        $message = 'Captured : ' . $response_object['response']['captured'] . "\n";
                        $message .= 'Transaction ID : ' . $response_object['response']['token'] . "\n";

                        $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_trexle_order_status_id'), $message, false);

                        $json['success'] = $this->url->link('checkout/success', '', true);
                    } else {

                        $error_message = '';
                        if (isset($response_object['error'])) {
                            $error_message = $response_object['error']."\n";
                            $error_message .= $response_object['detail'];
                        }

                        $json['error']['message'] = $error_message;

                    }
                } else {
                    $json['error']['message'] = 'Empty Gateway Response';
                }
            } else {
                $json['error']['message'] = 'Empty Gateway Response';
            }

            curl_close($curl);

        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
