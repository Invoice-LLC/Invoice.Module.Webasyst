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
        $invoice_order->id = $id ."-". md5($id);
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
        $this->merchant_id = '*';
        $this->order_id = $request['order']['id'];
        
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
            return $this->getResponse('wrong signature');
        }

        if($type == "pay") {

            if($notification["status"] == "successful") {
                $this->pay($data, $notification);
                return $this->getResponse('payment successful');
            }
            if($notification["status"] == "error") {
                return $this->getResponse('payment failed');
            }
        }

        return "null";
    }

    public function pay($params, $notification) {
        $transaction_data = $this->formData($params, $notification);
        $transaction_data = $this->saveTransaction($transaction_data, $params);
        $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
    }

    public function formData($params, $notification)
    {
        $transaction_data = parent::formalizeData($params);
        $transaction_data['amount'] = $notification['order']['amount'];
        $transaction_data['order_id'] = $this->orderId;
        $transaction_data['type'] = self::OPERATION_CAPTURE;
        $transaction_data['state'] = self::STATE_CAPTURED;
        $transaction_data['currency_id'] = 'RUB';
        $transaction_data['native_id'] = $this->orderId;

        return $transaction_data;
    }
    
    protected function getResponse($msg) {
        return array(
                'message' => $msg
            );
    }

    public function getTerminal() {
        $file = 'invoice_tid_'.$this->invoice_login;
        
        if(!file_exists($file)) file_put_contents($file, '');
        $tid = file_get_contents($file);

        if($tid == null or empty($tid)) {
            $request = new CREATE_TERMINAL($this->invoice_default_terminal_name);
            $request->type = "dynamical";
            $request->description = "";
            $request->defaultPrice = 0;
            $response = $this->getRestClient()->CreateTerminal($request);

            if($response == null or isset($response->error)) 
                throw new Exception("Terminal error ".$response->error);

            $tid = $response->id;
            file_put_contents($file, $tid);
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
