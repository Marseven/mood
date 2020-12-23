<?php
class PaymentController extends Controller {

    public function before()
    {
        parent::before(); // TODO: Change the autogenerated stub
        require_once path('app/vendor/autoload.php');
    }

    public function load() {
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = formatMoneyTotal($this->request->input('price'));
        $reference = reference(6);

        session_put("about-pay-type", $type);
        session_put('about-pay-typeid', $typeId);
        session_put("about-pay-price", $price);
        session_put("about-pay-reference", $reference);
        return $this->view('payment/methods', array(
            'type' => $type,
            'typeId' => $typeId,
            'price' => $price,
            'reference' => $reference,
            'detail' => $this->model('admin')->getPaymentDetails($type, $typeId)
        ));
    }



    public function stripe() {
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
        $token = $this->request->input('token');

        $stripe = \Omnipay\Omnipay::create('Stripe');
        $stripe->initialize(array(
            'apiKey' => config('stripe-secret-key', '')
        ));

        if (session_get('about-pay-price') != $price) return json_encode(array(
            'status' => 0,
            'message' => l('transaction-failed')
        ));

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));
        $currency = model('user')->getUserCurrency();
        if (!userCurrencySupportStripe()) {
            $currency = config('currency-converter-source', 'USD');;
        }
        try{
           if (($type == 'pro' or $type == 'pro-users') and config('enable-stripe-recurring', false)) {
               $user = $this->model('user')->getUser();
                $response = $stripe->createCustomer(array(
                    'token' => $token,
                    'description' => 'Customer for ' .$user['email'],
                    'email' => $user['email'],
                    'name' => $user['full_name'],
                    'address' => $user['country'],
                ))->send();
                if ($response->isSuccessful()) {
                    $customerId = $response->getCustomerReference();
                    $response = $stripe->createSubscription(array(
                        'customerReference' => $customerId,
                        'plan' => ($typeId == 'monthly') ? config('stripe-product-monthly-plan') : config('stripe-product-yearly-plan'),
                        'items' => array(
                            array(
                                'plan' => ($typeId == 'monthly') ? config('stripe-product-monthly-plan') : config('stripe-product-yearly-plan')
                            )
                        ),
                    ))->send();
                    if ($response->isSuccessful()) {
                        $subscriptionId = $response->getSubscriptionReference();
                        $response = $stripe->purchase([
                            'amount' => $price,
                            'currency' => $currency,
                            'customerReference' => $customerId,

                        ])->send();
                        if ($response->isSuccessful()) {
                            Database::getInstance()->query("UPDATE users SET stripe_customer_id=?,stripe_subscription_id=? WHERE id=?", $customerId,$subscriptionId, $user['id']);
                            $this->model('admin')->addTransaction(array(
                                'amount' => (userCurrencySupportStripe()) ? convertBackToBase($price) : $price,
                                'type' => $type,
                                'type_id' => $typeId,
                                'sale_id' => $customerId,
                                'name' => $this->model('user')->authUser['full_name'],
                                'country' => $this->model('user')->authUser['country'],
                                'email' => $this->model('user')->authUser['email'],
                                'userid' => $this->model('user')->authId
                            ));
                            Hook::getInstance()->fire('payment.success', null, array($type, $typeId));
                            if (session_get('mobile-pay') == 1) return json_encode(array(
                                'status' => 1,
                                'url' => url('api/pay/success'),
                                'message' => l('transaction-successful')
                            ));
                            return json_encode(array(
                                'status' => 1,
                                'url' => $url,
                                'message' => l('transaction-successful')
                            ));
                        } else {
                            if (session_get('mobile-pay') == 1) return json_encode(array(
                                'status' => 1,
                                'url' => url('api/pay/failed'),
                                'message' => l('transaction-failed')
                            ));
                            return json_encode(array(
                                'status' => 0,
                                'message' => l('transaction-failed').$response->getMessage()
                            ));
                        }

                    } else {
                        if (session_get('mobile-pay') == 1) return json_encode(array(
                            'status' => 1,
                            'url' => url('api/pay/failed'),
                            'message' => l('transaction-failed')
                        ));
                        return json_encode(array(
                            'status' => 0,
                            'message' => l('transaction-failed').$response->getMessage()
                        ));
                    }

                } else {
                    if (session_get('mobile-pay') == 1) return json_encode(array(
                        'status' => 1,
                        'url' => url('api/pay/failed'),
                        'message' => l('transaction-failed')
                    ));
                    return json_encode(array(
                        'status' => 0,
                        'message' => l('transaction-failed').$response->getMessage()
                    ));
                }

           } else {
               $response = $stripe->purchase([
                   'amount' => number_format($price, 2),
                   'currency' => $currency,
                   'token' => $token,

               ])->send();

               if ($response->isSuccessful()) {
                   $saleId = $response->getTransactionReference();

                   $this->model('admin')->addTransaction(array(
                       'amount' => (userCurrencySupportStripe()) ? convertBackToBase($price) : $price,
                       'type' => $type,
                       'type_id' => $typeId,
                       'sale_id' => $saleId,
                       'name' => $this->model('user')->authUser['full_name'],
                       'country' => $this->model('user')->authUser['country'],
                       'email' => $this->model('user')->authUser['email'],
                       'userid' => $this->model('user')->authId
                   ));
                   Hook::getInstance()->fire('payment.success', null, array($type, $typeId));
                   if (session_get('mobile-pay') == 1) return json_encode(array(
                       'status' => 1,
                       'url' => url('api/pay/success'),
                       'message' => l('transaction-successful')
                   ));
                   return json_encode(array(
                       'status' => 1,
                       'url' => $url,
                       'message' => l('transaction-successful')
                   ));
               } else {
                   if (session_get('mobile-pay') == 1) return json_encode(array(
                       'status' => 1,
                       'url' => url('api/pay/failed'),
                       'message' => l('transaction-failed')
                   ));
                   return json_encode(array(
                       'status' => 0,
                       'message' => l('transaction-failed')
                   ));
               }
           }
        } catch(Exception $exception) {
            if (session_get('mobile-pay') == 1) return json_encode(array(
                'status' => 1,
                'url' => url('api/pay/failed'),
                'message' => l('transaction-failed')
            ));
            return json_encode(array(
                'status' => 0,
                'message' => l('transaction-failed').$exception->getMessage()
            ));
        }
    }

    public function stripeHook() {
        $input = @file_get_contents('php://input');
        $event_json = json_decode($input);

        $dataobj = $event_json['data']['object'];
        $event_type = $event_json['type'];
        if($event_type == "invoice.payment_succeeded") {
            $customerId = $dataobj['customer'];
            $user = Database::getInstance()->query("SELECT * FROM users WHERE stripe_customer_id=?", $customerId);
            $user = $user->fetch(PDO::FETCH_ASSOC);
            if ($user) {
               $type = ($user['user_type'] == 2) ? 'pro' : 'pro-users';
               $lastTransaction = $this->model('admin')->getLastTransaction($user['id'], $type);
               $validTime = ($lastTransaction['type_id'] == 'monthly') ? time() + (2629746 * 1):time() + (2629746 * 12) ;
               Database::getInstance()->query("UPDATE transactions SET valid_time=? WHERE id=?", $validTime, $lastTransaction['id']);
            }
        }
        http_response_code(200);
    }

    public function stripeCancel() {
        $stripe = \Omnipay\Omnipay::create('Stripe');
        $stripe->initialize(array(
            'apiKey' => config('stripe-secret-key', '')
        ));
        $user = $this->model('user')->getUser();
        try {
            $response = $stripe->cancelSubscription(array(
                'subscriptionReference' => $user['stripe_subscription_id'],
                'customerReference' => $user['stripe_customer_id']
            ))->send();
            if ($response->isSuccessful()) {
                Database::getInstance()->query("UPDATE users SET stripe_customer_id=?,stripe_subscription_id=? WHERE id=?", '', '', $user['id']);
                return json_encode(array(
                    'type' => 'url',
                    'message' => l('subscription-canceled'),
                    'url' => url('settings/pro')
                ));
            } else {
                return json_encode(array(
                    'type' => 'error',
                    'message' => $response->getMessage()
                ));
            }
        } catch (Exception $e) {
            return json_encode(array(
                'type' => 'error',
                'message' => $e->getMessage()
            ));
        }
    }

    public function initPaypal() {
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');

        if (session_get('about-pay-price') != $price) $price = session_get('about-pay-price');
        try{
            $gateway = \Omnipay\Omnipay::create('PayPal_Express');

        } catch (Exception $e){
            exit($e->getMessage());
        }
        $gateway->setUsername(config('paypal-username'));
        $gateway->setPassword(config('paypal-password'));
        $gateway->setSignature(config('paypal-signature'));

        $gateway->setTestMode(config('paypal-sandbox', false));

        $gateway->setlogoImageUrl(assetUrl(config('site_logo', 'assets/images/logo.png')));

        $gateway->setbrandName(config('site-title'));

        $currency  = model('user')->getUserCurrency();
        if (!userCurrencySupportPaypal()) {
            $price = convertBackToBase($price);
            $currency = config('currency-converter-source', 'USD');
        }


        $request_data = [
            'amount'      => number_format($price, 2, '.', ''),
            'returnUrl'   => url('payment/paypal/complete', array('type' => $type, 'typeid'=> $typeId, 'price' => $price)),
            'cancelUrl'   => url(''),
            'currency'    => $currency,
            'description' => '',
        ];

        try {
            $response = $gateway->purchase($request_data)->send();
            if ($response->isRedirect()) {
                $response->redirect();
            } else {
                exit($response->getMessage());
            }
        } catch (\Exception $e) {
            echo $e->getMessage() . '<br />';
            exit('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function completePaypal() {
        $gateway = \Omnipay\Omnipay::create('PayPal_Express');
        $gateway->setUsername(config('paypal-username'));
        $gateway->setPassword(config('paypal-password'));
        $gateway->setSignature(config('paypal-signature'));

        $gateway->setTestMode(config('paypal-sandbox', false));

        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));

        $currency  = model('user')->getUserCurrency();
        if (!userCurrencySupportPaypal()) {
            $currency = config('currency-converter-source', 'USD');
        }

        $response = $gateway->completePurchase([
            'transactionReference' => $this->request->input('token'),
            'payerId'              => $this->request->input('PayerID'),
            'amount'               => $price,
            'currency'             => $currency,
        ])->send();

        $paypalResponse = $response->getData();

        if (isset($paypalResponse['L_ERRORCODE0'])) {
            if (session_get('mobile-pay') == 0) $this->request->redirect(url('api/pay/failed'));
            $this->request->redirect(($type == 'pro') ? url('pro') : url());//the payment failed
        } elseif (isset($paypalResponse['PAYMENTINFO_0_ACK']) && $paypalResponse['PAYMENTINFO_0_ACK'] === 'Success') {
            $saleId = $response->getTransactionReference();

            $this->model('admin')->addTransaction(array(
                'amount' => (!userCurrencySupportPaypal()) ? $price : convertBackToBase($price),
                'type' => $type,
                'type_id' => $typeId,
                'sale_id' => $saleId,
                'name' => $this->model('user')->authUser['full_name'],
                'country' => $this->model('user')->authUser['country'],
                'email' => $this->model('user')->authUser['email'],
                'userid' => $this->model('user')->authId
            ));
            Hook::getInstance()->fire('payment.success', null, array($type, $typeId));
            if (session_get('mobile-pay') == 1) $this->request->redirect(url('api/pay/success'));
            $this->request->redirect($url);
        }
    }

    public function initMollie() {
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');

        if (session_get('about-pay-price') != $price) $price = session_get('about-pay-price');
        $gateway = \Omnipay\Omnipay::create('Mollie');

        $gateway->setApiKey(config('mollie-api-key'));

        $details =  $this->model('admin')->getPaymentDetails($type, $typeId);
        $description = $details['desc'];
        $returnUrl = url('payment/mollie/verify', array('type' => $type, 'typeid' => $typeId, 'price' => $price));
        $webhookUrl = url('payment/mollie/hook');
        $currency  = model('user')->getUserCurrency();
        if (!userCurrencySupportMollie()) {
            $price = convertBackToBase($price);
            $currency = config('currency-converter-source', 'USD');
        }
        $oResponse = $gateway->purchase([
            'amount'      => number_format($price, 2, '.', ''),
            'description' => $description,
            'returnUrl'   => $returnUrl,
            'notifyUrl'   => $webhookUrl,
            'currency'    => $currency,
        ])->send();

        // Add the token to database
        $token = $oResponse->getTransactionReference();
        session_put('mollie-transaction-id', $token);

        if ($oResponse->isRedirect()) {
            $oResponse->redirect();
            exit;
        } elseif ($oResponse->isPending()) {
            echo 'Pending, Reference: ' . $oResponse->getTransactionReference();
        } else {
            echo '<p class="text-danger">Error ' . $oResponse->getCode() . ': ' . $oResponse->getMessage() . '</p>';
        }
    }

    public function verifyMollie() {
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
        $token = session_get('mollie-transaction-id');

        if (!$token) exit('Invalid');

        if (session_get('about-pay-price') != $price) $price = session_get('about-pay-price');
        $gateway = \Omnipay\Omnipay::create('Mollie');

        $gateway->setApiKey(config('mollie-api-key'));

        $oResponse = $gateway->fetchTransaction([
            'transactionReference' => $token,
        ])->send();

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));

        if ($oResponse->isSuccessful()) {
            $data = $oResponse->getData();
            if ($data['status'] == 'paid') {
                $this->model('admin')->addTransaction(array(
                    'amount' =>  (!userCurrencySupportMollie()) ? $price : convertBackToBase($price),
                    'type' => $type,
                    'type_id' => $typeId,
                    'sale_id' => $token,
                    'name' => $this->model('user')->authUser['full_name'],
                    'country' => $this->model('user')->authUser['country'],
                    'email' => $this->model('user')->authUser['email'],
                    'userid' => $this->model('user')->authId
                ));
                Hook::getInstance()->fire('payment.success', null, array($type, $typeId));
                if (session_get('mobile-pay') == 1) $this->request->request(url('api/pay/success'));
                $this->request->redirect($url);
            } else {
                if (session_get('mobile-pay') == 1) $this->request->request(url('api/pay/failed'));
                $this->request->redirect(url(''));
            }
        } else {
            if (session_get('mobile-pay') == 1) $this->request->request(url('api/pay/failed'));
            $this->request->redirect(($type == 'pro') ? url('pro') : url());
        }
    }

    public function hookMollie() {

    }

    public function bankTransfer() {
        if ($val = $this->request->input('val')) {
            if ($file = $this->request->inputFile('file')) {

                $uploader = new Uploader($file, 'image');
                $uploader->setPath('bank/transfer/'.$this->model('user')->authId.'/');
                if ($uploader->passed()) {
                    $file = $uploader->uploadFile()->result();
                    $val['file'] = $file;
                } else {
                    return json_encode(array('type' => 'error', 'message' => $uploader->getError()));
                }
            } else {
                return json_encode(array('type' => 'error', 'message' => l('kindly-upload-receipt-file')));
            }

            return $this->model('admin')->saveBankTransfer($val);

        }
    }

    public function completePvit(){
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
       
        $data_received=file_get_contents("php://input"); 
        $data_received_xml=new SimpleXMLElement($data_received); 
        $ligne_response=$data_received_xml[0]; 
        $interface_received=$ligne_response->INTERFACEID; 
        $reference_received=$ligne_response->REF; 
        $type_received=$ligne_response->TYPE; 
        $statut_received=$ligne_response->STATUT; 
        $operateur_received=$ligne_response->OPERATEUR; 
        $client_received=$ligne_response->TEL_CLIENT; 
        $message_received=$ligne_response->MESSAGE; 
        $token_received=$ligne_response->TOKEN; 
        $agent_received=$ligne_response->AGENT;

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));
        var_dump($this->model('user')->authUser['email']);die;
        if ($statut_received == 200) {
            $this->model('admin')->addTransaction(array(
                'amount' =>  $price,
                'type' => $type,
                'type_id' => $typeId,
                'sale_id' => $token_received,
                'name' => $this->model('user')->authUser['full_name'],
                'email' => $this->model('user')->authUser['email'],
                'country' => $this->model('user')->authUser['country'],
                'telephone' => $client_received,
                'userid' => $this->model('user')->authId
            ));
            Hook::getInstance()->fire('payment.success', null, array($type, $typeId));
            if (session_get('mobile-pay') == 1) $this->request->request(url('api/pay/success'));
            $this->request->redirect($url);
        } else {
            if (session_get('mobile-pay') == 1) $this->request->request(url('api/pay/failed'));
            $this->request->redirect(($type == 'pro') ? url('pro') : url());
        }
    }
}