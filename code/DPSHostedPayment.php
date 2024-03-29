<?php
/**
 * Step-by-Step:
 * 1. Send XML transaction request (GenerateRequest) to PaymentExpress
 *    => DPSHostedPaymentForm->doPay() => DPSHostedPayment->prepareRequest() => DPSHostedPayment->processPayment()
 * 2. Receive XML response (Request) with the URI element (encrypted URL), which you use to redirect the user to PaymentExpress so they can enter their card details
 * 3. Cardholder enters their details and transaction is sent to your bank for authorisation. The response is given and they are redirected back to your site with the response
 * 4. You take the "Request" parameter (encrypted URL response) in the URL string and use this in the "Response" element, to send the response request (ProcessResponse) to PaymentExpress to decrypt and receive the XML response back.
 * 5. Receive XML response (Response) with the authorised result of the transaction.
 *    => DPSHostedPayment_Controller->processResponse()
 * 
 * @see http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html
 * 
 * @package payment_dpshosted
 */
class DPSHostedPayment extends Payment{
	
	static $payment_form_class = 'DPSHostedPaymentForm';
	
	static $pxAccess_Url = "https://www.paymentexpress.com/pxpay/pxpay.aspx";
	
	private static $pxAccess_Userid;
	
	private static $pxAccess_Key;
	
	private static $mac_Key;
	
	static $pxPay_Url  = "https://www.paymentexpress.com/pxpay/pxaccess.aspx";
		
	private static $pxPay_Userid;
		
	private static $pxPay_Key;
	
	public static $px_currency = 'NZD';
	
	// Relevant DPS URLs
	protected static $logo = 'http://www.paymentexpress.com/images/dps_paymentexpress.png'; # dps|paymentexpress logo
	protected static $privacy = 'http://www.paymentexpress.com/privacypolicy.htm'; # link to dps' privacy policy
	
	/**
	 * @var string $px_merchantreference Reference field to appear on transaction reports
	 */
	public static $px_merchantreference = null;
	
	static $db = array(
		'Status' => "Enum('Incomplete,Success,Failure,Pending','Incomplete')",
		'Amount' => 'Currency',
		'Currency' => 'Varchar(3)',
		'TxnRef' => 'Varchar', // only written on success
		'Message' => 'Varchar',
		'IP' => 'Varchar',
		'ProxyIP' => 'Varchar',
		'AuthorizationCode' => 'Varchar', // only written on success
		'TxnID' => 'Varchar' // random number
	);
	
	static $has_one = array(
	);
	
	static function set_px_access_userid($id){
		self::$pxAccess_Userid = $id;
	}
	
	static function get_px_access_userid(){
		return self::$pxAccess_Userid;
	}
	
	static function set_px_access_key($key){
		self::$pxAccess_Key = $key;
	}
	
	static function get_px_access_key(){
		return self::$pxAccess_Key;
	}
	
	static function set_mac_key($key){
		self::$mac_Key = $key;
	}
	
	static function get_mac_key(){
		return self::$mac_Key;
	}
	
	static function set_px_pay_userid($id){
		self::$pxPay_Userid = $id;
	}
	
	static function get_px_pay_userid(){
		return self::$pxPay_Userid;
	}
	
	static function set_px_pay_key($key){
		self::$pxPay_Key = $key;
	}
	
	static function get_px_pay_key(){
		return self::$pxPay_Key;
	}
	
	static function generate_txn_id() {
		do {
			$rand = rand();
			$idExists = (bool)DB::query("SELECT COUNT(*) FROM `DPSHostedPayment` WHERE `TxnID` = '{$rand}'")->value();
		} while($idExists);
		return $rand;
	}
	
	/**
	 * Executed in form submission *before* anything
	 * goes out to DPS.
	 */
	public function processPayment($data, $form){
		// generate a unique transaction ID
		$this->TxnID = DPSHostedPayment::generate_txn_id();
		$this->write();
		
		// generate request from thirdparty pxpayment classes
		$request = $this->prepareRequest($data);
		
		// decorate request (if necessary)
		$this->extend('prepareRequest', $request);
		
		// set currency
		$this->Currency = $request->getInputCurrency();
	
		// submit payment request to get the URL for redirection
		$pxpay = new PxPay(self::$pxPay_Url, self::$pxPay_Userid, self::$pxPay_Key);
		$request_string = $pxpay->makeRequest($request);

		$response = new MifMessage($request_string);
		$valid = $response->get_attribute("valid");
		
		// set status to pending
		if($valid) {
			$this->Status = 'Pending';
			$this->write();

			// MifMessage was clobbering ampersands on some environments; SimpleXMLElement is more robust
			$xml = new SimpleXMLElement($request_string);
			$urls = $xml->xpath('//URI');     
			$url = $urls[0].'';
	
			Director::redirect($url);
			
			return new Payment_Processing();
		} else {
			return new Payment_Failure();
		}
	}
	
	/**
	 * Generate a {@link PxPayRequest} object and populate it with the submitted
	 * data from a {@link DPSHostedPaymentForm} instance. You'll likely need to subclass
	 * this method to add custom data.
	 * 
	 * @see http://www.paymentexpress.com/technical_resources/ecommerce_hosted/pxpay.html#GenerateRequest
	 * 
	 * @param array $data
	 * @return PxPayRequest
	 */
	protected function prepareRequest($data){
		$request = new PxPayRequest();
		
		// Set in payment_dpshosted/_config.php
		$postProcess_url = Director::absoluteBaseURL() ."DPSHostedPayment/processResponse";
		$request->setUrlFail($postProcess_url);
		$request->setUrlSuccess($postProcess_url);
		
		// set amount
		$amount = (float) ltrim($data['Amount'], '$');
		$request->setAmountInput($amount);
		
		// mandatory free text data
		if(isset($data['FirstName']) && isset($data['SurName'])) {
			$request->setTxnData1($data['FirstName']." ".$data['SurName']);
			//$request->setTxnData2();
			//$request->setTxnData3();
		}
		
		// Auth, Complete, Purchase, Refund (DPS recomend completeing refunds through other API's)
		$request->setTxnType('Purchase'); // mandatory
		
		// randomly generated number from {@link processPayment()}
		$request->setTxnId($this->TxnID);
		
		// defaults to NZD
		$request->setInputCurrency(self::$px_currency); // mandatory
		
		// use website URL as a reference if none is given
		$ref = (self::$px_merchantreference) ? self::$px_merchantreference : Director::absoluteBaseURL();
    $ref .= " #{$this->ID}";
		$request->setMerchantReference($ref); // mandatory

		if(isset($data['Email'])) {
			$request->setEmailAddress($data['Email']); // optional
		}
		
		return $request;
	}
	
	/**
	 * Set the IP address and Proxy IP (if available) from the site visitor.
	 * Does an ok job of proxy detection. Probably can't be too much better because anonymous proxies
	 * will make themselves invisible.
	 */	
	function setClientIP() {
		if(isset($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
		else if(isset($_SERVER['REMOTE_ADDR'])) $ip = $_SERVER['REMOTE_ADDR'];
		else $ip = null;
		
		if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$proxy = $ip;
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		
		// If the IP and/or Proxy IP have already been set, we want to be sure we don't set it again.
		if(!$this->IP) $this->IP = $ip;
		if(!$this->ProxyIP && isset($proxy)) $this->ProxyIP = $proxy;
	}
	
	function getPaymentFormFields() {
		return new FieldSet(
			new LiteralField("RedirectNotice", "<p>You will be redirected to DPS' secure website to enter your payment details.</p><br />"),
			new LiteralField("DPSInfo", '<a href="' . self::$privacy . '" title="Read DPS\' privacy policy"><img src="' . self::$logo . '" alt="Payments powered by DPS" /></a>')
		);	
	}
	
	function getPaymentFormRequirements() {
		return null;
	}
}

class DPSHostedPayment_Controller extends Controller {
	
	/**
	 * React to DSP response triggered by {@link processPayment()}.
	 */
	public function processResponse() {
		if(preg_match('/^PXHOST/i', $_SERVER['HTTP_USER_AGENT'])){
			$dpsDirectlyConnecting = 1;
		}

		$pxpay = new PxPay(
			DPSHostedPayment::$pxPay_Url, 
			DPSHostedPayment::get_px_pay_userid(), 
			DPSHostedPayment::get_px_pay_key()
		);

		$enc_hex = $_REQUEST["result"];

		$rsp = $pxpay->getResponse($enc_hex);

		if(isset($dpsDirectlyConnecting) && $dpsDirectlyConnecting) {
			// DPS Service connecting directly
			$paymentID = $rsp->getTxnId();
			$SQL_paymentID = (int)$paymentID;
			$payment = DataObject::get_one('DPSHostedPayment', "`TxnID` = '$SQL_paymentID'");
			if(!$payment) {
				echo "failure";
				return;
			}

			$success = $rsp->getSuccess();   # =1 when request succeeds
			if($success =='1'){
				$payment->TxnRef=$rsp->getDpsTxnRef();
				$payment->Status="Success";
				$payment->AuthorizationCode=$rsp->getAuthCode();
				echo "success";
			} else {
				$payment->Message=$rsp->getResponseText();
				$payment->Status="Failure";
				echo "failure";
			}
			$payment->write();
			$payment->redirectToOrder();
		} else {
			// Human visitor
			$paymentID = $rsp->getTxnId();
			$SQL_paymentID = (int)$paymentID;

			$payment = DataObject::get_one('DPSHostedPayment', "`TxnID` = '$SQL_paymentID'");
			if(!$payment) {
				$payment->redirectToOrder();
				return;
			}

			$success = $rsp->getSuccess();
			if($success =='1'){
				// @todo Use AmountSettlement for amount setting?
				$payment->TxnRef=$rsp->getDpsTxnRef();
				$payment->Status="Success";
				$payment->AuthorizationCode=$rsp->getAuthCode();
			} else {
				$payment->Message=$rsp->getResponseText();
				$payment->Status="Failure";
			}
			$payment->write();
			$payment->redirectToOrder();
		}
	}

}
