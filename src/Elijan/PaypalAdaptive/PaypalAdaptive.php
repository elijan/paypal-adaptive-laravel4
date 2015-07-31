<?php namespace Elijan\PaypalAdaptive;


use Illuminate\Config\Repository;
use AdaptivePaymentsService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class PaypalAdaptive
{

    private $config;
    private $log;
    private $receivers = [];
    private $redirect_url;
    private $invoiceItems = [];

    private $complete_order = false;

    private $reference_id;
    private $method;

    private $payRequest;
    private $serviceRequest;
    private $response;

    private $error = false;

    public function __construct(Repository $config){

        $this->config  = $config->get("paypal-adaptive::config");

        $this->log =  new Logger('paypal-adaptive');

        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/paypal-adaptive.log', Logger::WARNING));
        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/paypal-adaptive.log', Logger::ERROR));
        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/paypal-adaptive.log', Logger::INFO));
        //set primary reicver here

        $this->log->addWarning("===============Payapal Adaptive is in ".$this->config['mode']);

        $this->complete_order = false;
    }


    public function createOrder($ref_id, $method="CREATE"){

        $this->reference_id = $ref_id;

        $this->method = strtoupper($method);

    }

    public function completeOrder(){


        $this->complete_order = true;
        //create order firsr

        if($this->makeRequest()) {

            $setPaymentOptionsRequest = new \SetPaymentOptionsRequest(new \RequestEnvelope("en_US"));
            $setPaymentOptionsRequest->payKey = $this->getPayKey();;


            $receiverOptions = new \ReceiverOptions();


            $receiverId = new \ReceiverIdentifier();
            $receiverId->email = 'elijans+p@gmail.com';
            $receiverOptions->receiver = $receiverId;

            $setPaymentOptionsRequest->receiverOptions[] = $receiverOptions;

            $receiverOptions->invoiceData = new \InvoiceData();
            $receiverOptions->invoiceData->item = $this->invoiceItems;


            $response = $this->serviceRequest->SetPaymentOptions($setPaymentOptionsRequest);

            return $this->handleResponse($response) ? true : false;
        }

        return false;
    }
    /**
     * @param array $item_data
     *
     * Add item for the inovice in order to appear in shop
     */
    public function addItem(array $item_data){

        //check if payment option has been set
        $item = new \InvoiceItem();

        $item->name = $item_data['name'];
        /*
         * (Optional) External reference to item or item ID.
         */
        if(isset($item_data['identifier'])) {
            $item->identifier = $item_data['identifier'];
        }
        if(@$item_data['price'] != "") {
            $item->price = $item_data['price'];
        }
        if(@$item_data['itemPrice'] != "") {
            $item->itemPrice = $item_data['itemPrice'];
        }
        if(@$item_data['itemCount'] != "") {
            $item->itemCount = $item_data['itemCount'];
        }

        $this->invoiceItems[] = $item;

    }


    /**
     * @param $email
     * @param $amount
     * @param bool $primary
     *
     * Add Pyapal Reciver (required)
     */

    public function addReceiver($email, $amount, $primary = false ){

        $receiver = new \Receiver();
        $receiver->email = $email;
        $receiver->amount = $amount;
        $receiver->primary = $primary;

        $this->log->addWarning("Adding receiver....");
        $this->log->addWarning(print_r($receiver, true));

        array_push($this->receivers, $receiver);


    }

    /**
     *
     * Get List of Reciovers
     *
     * @return \ReceiverList
     */
    public function getReceivers(){

        if(empty($this->receivers)){

            $this->log->addError("No Receivers");

            throw new \Exception('No receivers are set for this payment');

        }
         return new \ReceiverList($this->receivers);
    }

    public function getRedirectUrl(){

        return $this->redirect_url;

    }

    public function Execute($payKey=null)
    {
        if($payKey == null && $this->getPayKey()){

            throwException("PayKey Missing");

            $this->log->addError("Execute Payment Error No Pay Key");

        }

        $executePaymentRequest = new \ExecutePaymentRequest(new \RequestEnvelope("en_US"), $payKey);
        $executePaymentRequest->actionType = "PAY";

        $this->serviceRequest = new AdaptivePaymentsService($this->config);


        $response = $this->serviceRequest->ExecutePayment($executePaymentRequest);

        $this->log->addInfo("Execute Payment Response", array($response));

        $this->handleResponse($response);

    }


    private function handleResponse($response){

        $this->response = $response;

        try {
            /* wrap API method calls on the service object with a try catch */

            $this->log->addInfo("response, received", array($response));

            switch(strtoupper($response->responseEnvelope->ack)) {

                case "SUCCESS":
                    // Success
                     $this->response = $response;

                    return true;

                    break;

                default:
                    //log the error
                    $this->logErrors($response);
                    return false;

                    break;

            }

        } catch(Exception $ex) {
            require_once '../Common/Error.php';
            exit;
        }


    }


    /**
     * Make a Paypal Request
     *
     */
    public function makeRequest(){

        $this->log->addWarning("Create Service");

        $this->serviceRequest = new AdaptivePaymentsService($this->config);

        $this->log->addInfo("Create Pay Request", (array) $this->serviceRequest);


        $this->payRequest = new \PayRequest( new \RequestEnvelope("en_US"), $this->method,
                                    $this->config['cancelUrl'].'&ref_id='. $this->reference_id,
                                    $this->config['currencyCode'],
                                    $this->getReceivers(),
                                    $this->config['returnUrl'].'&ref_id='. $this->reference_id
                                    );

        $this->log->addInfo("Pay Request:", (array)  $this->payRequest);


        $response = $this->serviceRequest->Pay( $this->payRequest);

        if(strtoupper($this->config['mode'])=='SANDBOX'){
            $this->redirect_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_ap-payment&paykey=' . $response->payKey;
        }
        else {
            $this->redirect_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_ap-payment&paykey=' . $response->payKey;
        }

        $this->payKey = $response->payKey;


        return $this->handleResponse($response)?true:false;


    }

    public function getPayKey(){

        return $this->payKey;

    }

    public function getPrimaryEmail(){

        return $this->config['primary_email'];

    }

    private function logErrors($response)
    {

        $this->log->addError("Error:");

        $this->log->addError(print_r($response, true));

        $this->error = $response->error;
    }


    public function getError()
    {
        return $this->error[0]->message;

    }

}

