<?php
require "InvoiceSDK/RestClient.php";
require "InvoiceSDK/common/SETTINGS.php";
require "InvoiceSDK/common/ORDER.php";
require "InvoiceSDK/CREATE_TERMINAL.php";
require "InvoiceSDK/CREATE_PAYMENT.php";

class invoicePayment extends waPayment implements waIPayment
{
    protected $orderId;

    public function allowedCurrency()
    {
        $default = array(
            'RUB',
        );
        return $default;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $result = null;
        $order = waOrder::factory($order_data);

        $amount = $order->total;
        $id = $order->id;

        $invoice_order = new INVOICE_ORDER($amount);
        $invoice_order->id = $id;
        $settings = new SETTINGS($this->getTerminal());
        $settings->success_url = ( ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);

        $request = new CREATE_PAYMENT($invoice_order, $settings, []);
        $response = $this->getRestClient()->CreatePayment($request);

        if($response == null or isset($response->error)) throw new Exception("Payment error");

        $view = wa()->getView();
        $view->assign('payment_url', $response->payment_url);

        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request)
    {
        return parent::callbackInit($request);
    }

    protected function callbackHandler($data)
    {
        $notification = $this->getNotification();

        $type = $notification["notification_type"];
        $id = $notification["order"]["id"];
        $this->orderId = $id;


        $signature = $notification["signature"];

        if($signature != $this->getSignature($notification["id"], $notification["status"], $this->invoice_api_key)) {
            return "Wrong signature";
        }

        if($type == "pay") {

            if($notification["status"] == "successful") {
                $this->pay($data);
                return "payment successful";
            }
            if($notification["status"] == "error") {
                return "payment failed";
            }
        }

        return "null";
    }

    public function pay($params) {
        $transaction_data = $this->formalizeData($params);
        $transaction_data = $this->saveTransaction($transaction_data, $params);
        $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
    }

    public function formalizeData($params)
    {
        $transaction_data = parent::formalizeData($params);
        $transaction_data['order_id'] = $this->orderId;
        $transaction_data['type'] = self::OPERATION_CAPTURE;
        $transaction_data['state'] = self::STATE_CAPTURED;
        $transaction_data['currency_id'] = $params['orderCurrency'];

        return $transaction_data;
    }

    public function getTerminal() {
        if(!file_exists('invoice_tid')) file_put_contents('invoice_tid', '');
        $tid = file_get_contents('invoice_tid');

        if($tid == null or empty($tid)) {
            $request = new CREATE_TERMINAL($this->invoice_default_terminal_name);
            $response = $this->getRestClient()->CreateTerminal($request);

            if($response == null or isset($response->error)) throw new Exception("Terminal error");

            $tid = $response->id;
            file_put_contents('invoice_tid', $tid);
        }

        return $tid;
    }

    public function getRestClient() {
        return new RestClient($this->invoice_login, $this->invoice_api_key);
    }

    public function getSignature($id, $status, $key) {
        return md5($id.$status.$key);
    }

    public function getNotification() {
        $postData = file_get_contents('php://input');
        return json_decode($postData, true);
    }
}