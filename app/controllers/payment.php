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

    public function pvit(){
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
       
        $data_received=file_get_contents("php://input");
        
        if( $data_received){
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
        }else{
            $statut_received=$_POST['statut'];
        }

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));
        
        if ($statut_received == 200) {
            $this->model('admin')->addTransaction(array(
                'amount' =>  $price,
                'type' => $type,
                'type_id' => $typeId,
                'sale_id' => $token_received,
                'name' => $this->model('user')->authUser['full_name'],
                'email' => $this->model('user')->authUser['email'],
                'country' => $this->model('user')->authUser['country'],
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

    public function ebilling(){
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
        $reference = reference(6);

        $user = $this->model('user')->authUser;

        // =============================================================
		// ===================== Setup Attributes ======================
		// =============================================================
		// E-Billing server URL

		$SERVER_URL = "https://lab.billing-easy.net/api/v1/merchant/e_bills";

		// Username
		$USER_NAME = 'aristide';

		// SharedKey
		$SHARED_KEY = 'a4e80739-61ea-430e-8ddc-db9eb7bf0783';

		// POST URL
		$POST_URL = 'https://test.billing-easy.net';


        // Fetch all data (including those not optional) from session
		$eb_amount = $price;
		$eb_shortdescription = 'Paiement sur Mood d\'une valeur de '.$price.' FCFA.';
		$eb_reference = $reference;
		$eb_email = $user['email'];
		$eb_msisdn = $_POST['tel_marchand'];
		$eb_name = $user['full_name'];
		$eb_address = $user['city'];
		$eb_city = $user['country'];
		$eb_detaileddescription = 'Paiement sur Mood d\'une valeur de '.$price.' FCFA.';
		$eb_additionalinfo = '';
		$eb_callbackurl = url('payment/eb_call', array('reference' => $reference, 'type' => $type, 'typeid' => $typeId, 'price' => $price));

		// =============================================================
		// ============== E-Billing server invocation ==================
		// =============================================================
		$global_array =
        [
            'payer_email' => $eb_email,
            'payer_msisdn' => $eb_msisdn,
            'amount' => $eb_amount,
            'reference' => $eb_reference,
            'short_description' => $eb_shortdescription,
            'description' => $eb_detaileddescription,
            'due_date' => date('d/m/Y', time() + 86400),
            'external_reference' => $eb_reference,
            'payer_name' => $eb_name,
            'payer_address' => $eb_address,
            'payer_city' => $eb_city,
            'additional_info' => $eb_additionalinfo
        ];

        $this->model('admin')->addEbilling($global_array, true);

		$content = json_encode($global_array);
		$curl = curl_init($SERVER_URL);
		curl_setopt($curl, CURLOPT_USERPWD, $USER_NAME . ":" . $SHARED_KEY);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
		$json_response = curl_exec($curl);

		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if ( $status != 201 ) {
			echo "Error: call to URL  failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl);
			die;
		}

		curl_close($curl);

		$response = json_decode($json_response, true);

        echo "<form action='" . $POST_URL . "' method='post' name='frm'>";
        echo "<input type='hidden' name='invoice_number' value='".$response['e_bill']['bill_id']."'>";
        echo "<input type='hidden' name='eb_callbackurl' value='".$eb_callbackurl."'>";
        echo "</form>";
        echo "<script language='JavaScript'>";
        echo "document.frm.submit();";
        echo "</script>";
        exit();
    }

    public function eb_call(){
        $type = $this->request->input('type');
        $typeId = $this->request->input('typeid');
        $price = $this->request->input('price');
        $reference = $this->request->input('reference');

        $billing = $this->model('admin')->getEbilling($reference);

        $url = ($type == 'pro' or $type == 'pro-users') ? url('settings/pro') : url();
        $url = Hook::getInstance()->fire('payment.success.url', $url, array($type, $typeId));

        if ($billing) {
            $this->model('admin')->addTransaction(array(
                'amount' =>  $price,
                'type' => $type,
                'type_id' => $typeId,
                'sale_id' => $reference,
                'name' => $this->model('user')->authUser['full_name'],
                'email' => $this->model('user')->authUser['email'],
                'country' => $this->model('user')->authUser['country'],
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

    //notification de paiement ebilling en arrière-plan
    public function notificaation_eb(){
        if($this->request->is('post')){
			if($this->model('admin')->editEbilling($_POST, true)){
				http_response_code(200);
				echo http_response_code();
				exit();
			}else{
				http_response_code(401);
				echo http_response_code();
				exit();
			}
		}else{
			http_response_code(402);
			echo http_response_code();
			exit();
		}
    }
}