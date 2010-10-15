<?php
define("CCTYPE_VISA", "visa");
define("CCTYPE_MASTERCARD", "mc");
define("CCTYPE_DISCOVER", "disc");
define("CCTYPE_AMEX", "amex");
define("CCTYPE_JCB", "jcb");

class PaymentechProcessor
{
	const AUTH_APPROVED = 1;
	const AUTH_DECLINED = 2;
	const AUTH_ERROR = 3;
    
	protected $mode = "test"; //in test mode we can try things out without making any real transactions
	protected $gateway_url = "https://orbitalvar1.paymentech.net/authorize";
	
	//member vars that should be exposed via setters
	public $industry_type;
	public $message_type;
	public $bin;
	public $merchant_id;
	public $terminal_id;
	public $cc_num;
	public $cc_expiry_mon;
	public $cc_expiry_yr;
	public $currency_code;
	public $curency_exp;
	public $cvv;
	public $postal_code;
	public $address1;
	public $address2;
	public $city;
	public $state;
	public $phone;
	public $card_owner;
	public $owner_id;
	public $amount;
	public $comments;
	public $card_type;
	public $order_num;
	public $tx_ref_num;
    
    //provided for debugging purposes
	public $request_xml;
	public $response_xml;
	
	public $transactionMsg;
	public $responseArray;
	
	//not used yet
    //many carts do this validation already
    //not sure if its worth it
	function ValidateCard()
	{
		$visa_length = 16;
		$mc_length = 16;
		$amex_length = 15;
		$disc_length = 16;
		
		$visa_start = array(4);
		$mc_start = array(51,52,53,54,55);
		$amex_start = array(34,37);
		$disc_start = array(60110, 60112, 60113, 60114, 60119);
		
		switch($this->card_type)
		{
			case CCTYPE_VISA:
				$startchars = substr($this->cc_num,0,1);
				if(!in_array($startchars,$visa_start))
				{
					$this->$transactionMsg = "Invalid Visa Card Number";
					return 10001;
				}
				if(strlen($this->cc_num) < $visa_length)
				{
					$this->transactionMsg = "Invalid Visa Card Number";
					return 10001;
				}
				break;
			case CCTYPE_MASTERCARD:
				$startchars = substr($this->cc_num,0,2);
				if(!in_array($startchars,$mc_start))
				{
					$this->transactionMsg = "Invalid MasterCard Number";
					return 10001;
				}
				break;
			case CCTYPE_AMEX:
				$startchars = substr($this->cc_num,0,2);
				if(!in_array($startchars,$visa_start))
				{
					$this->tranactionMsg = "Invalid American Express Card Number";
					return 10001;
				}
				break;
			case CCTYPE_DISCOVER:
				$startchars = substr($this->cc_num,0,5);
				if(!in_array($startchars,$visa_start))
				{
					$this->transactionMsg = "Invalid Discover Card Number";
					return 10001;
				}
				break;
			default:
				return "Unrecognized card type";
		}
		if(!$this->luhnCheck($this->cc_num))
		{
			$this->transactionMsg = "Invalid Credit Card Number";
			return 10001;
		}
		
		return 0;
	}
	
	/* Luhn algorithm number checker - (c) 2005-2008 shaman - www.planzero.org *
	 * This code has been released into the public domain, however please      *
	 * give credit to the original author where possible.                      */
	
	function luhnCheck($number) 
	{
		// Strip any non-digits (useful for credit card numbers with spaces and hyphens)
		$number=preg_replace('/\D/', '', $number);
	
		// Set the string length and parity
		$number_length=strlen($number);
		$parity=$number_length % 2;
	
		// Loop through each digit and do the maths
		$total=0;
		for ($i=0; $i<$number_length; $i++) 
		{
			$digit=$number[$i];
			// Multiply alternate digits by two
			if ($i % 2 == $parity) 
			{
	      		$digit*=2;
	      		// If the sum is two digits, add them together (in effect)
	      		if ($digit > 9) 
	      		{
	        		$digit-=9;
	      		}
	    	}
	    	// Total up the digits
			$total+=$digit;
		}
	
		// If the total mod 10 equals 0, the number is valid
		return ($total % 10 == 0) ? TRUE : FALSE;
	}
	
	//Process auth and capture payment
	public function processACPayment()
	{
		// $validcard=$this->ValidateCard();
		// if($validcard !=0){
			// return $validcard;
		// }
		
		$xml = $this->buildXML();
		$header= "POST /AUTHORIZE HTTP/1.0\r\n";
		$header.= "MIME-Version: 1.0\r\n";
		$header.= "Content-type: application/PTI40\r\n";
		$header.= "Content-length: " .strlen($xml) . "\r\n";
		$header.= "Content-transfer-encoding: text\r\n";
		$header.= "Request-number: 1\r\n";
		$header.= "Document-type: Request\r\n";
		$header.= "Interface-Version: Test 1.4\r\n";
		$header.= "Connection: close \r\n\r\n";
		$header.= $xml;
		$response = $this->SubmitTransaction($header);
		
		return $response;
	}

	private function CheckTrans(){
        $this->tx_ref_num = $this->responsArray['TxRefNum'];
		if($this->responseArray['ProcStatus'] != 0){
			$this->transactionMsg = $this->responseArray['StatusMsg'];
			return $this->responseArray['ProcStatus'];
		}
		
		if($this->responseArray['RespCode'] != 0){
			$this->transactionMsg = $this->responseArray['RespMsg'];
			return $this->responseArray['RespCode'];
		}
		
		if($this->responseArray['AVSRespCode'] != 'H'){
			$this->transactionMsg = "There is a problem with the address information you entered. Please check and re-submit.";
			return $this->responseArray['AVSRespCode'];
		}
		
		if($this->responseArray['CVV2RespCode'] != 'M'){
		  $this->transactionMsg = "There is a problem with the CVV code entered. Please check and re-submit.";
		  return $this->responseArray['CVV2RespCode'];
		}
		
		//this has to be the last check. if all others pass and this is 0
		//we are good. this may be 0 but and AVS or CVV code could be
		//thrown and the transaction is not approved because the merchant
		//enabled the cvv/avs requirement in the vt.
		if ($this->responseArray['ApprovalStatus'] == 0){
			$this->transactionMsg = "Approved";
			return 0;
		}
		
		$this->transactionMsg="Unknown response code";
		return 5757;
	}
	
	private function SubmitTransaction($xml)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$this->gateway_url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $xml);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$this->responsexml = curl_exec($ch);
		$this->responseArray = $this->ParseXmlResponse($this->responsexml);
		if (curl_errno($ch))
		{
			$this->transactionMsg = "Error in processing xml for curl: " . curl_error($ch)."<br>$xml";
			return curl_errno;
		} 
		else 
		{
			curl_close($ch);
		}
		$rcode = $this->CheckTrans();
		return $rcode;
	}
	
	public function processRecurringPayment()
	{
		return "Not implemented";
	}
	
	private function CreateProfile()
	{
		return "Not implemented";
	}
	
	private function buildXML()
	{
		$xml  = "<Request>";
		$xml .= "<NewOrder>";
		$xml .= "<IndustryType>".$this->industry_type."</IndustryType>";
		$xml .= "<MessageType>". $this->message_type."</MessageType>";
		$xml .= "<BIN>".$this->bin."</BIN>";
		$xml .= "<MerchantID>".$this->merchant_id."</MerchantID>";
		$xml .= "<TerminalID>".$this->terminal_id."</TerminalID>";
		$xml .= "<CardBrand>".$this->card_type."</CardBrand>";
		$xml .= "<AccountNum>".$this->cc_num."</AccountNum>";
		//get two digit year from 4 digit year and pad for 2 digit month
        if (strlen($this->cc_expiry_yr)==4){
            $two_digit_year = substr($this->cc_expiry_yr,-2,2);
        }
		$two_digit_mon = str_pad($this->cc_expiry_mon,2,'0',STR_PAD_LEFT);
		$xml .= "<Exp>".$two_digit_mon.$two_digit_year."</Exp>";
		$xml .= "<CurrencyCode>840</CurrencyCode>";
		$xml .= "<CurrencyExponent>2</CurrencyExponent>";
		//this param only necessary for visa and discover
		//supported values
		//		1 - Value is Present
		//		2 - Value on card but illegible
		//		9 - Cardholder states data not available 
		//always 1 becuase CVV required for ecommerce transactions for security reasons
		if(($this->card_type == CCTYPE_VISA) || ($this->card_type == CCTYPE_DISCOVER)) 
		{
			$xml .= "<CardSecValInd>1</CardSecValInd>";
		}
		$xml .= "<CardSecVal>".$this->cvv."</CardSecVal>";
		$xml .= "<AVSzip>".$this->postal_code."</AVSzip>";
		$xml .= "<AVSaddress1>".$this->address1."</AVSaddress1>";
		$xml .= "<AVScity>".$this->city."</AVScity>";
		$xml .= "<AVSstate>".$this->state."</AVSstate>";
		//$xml .= "<AVSname>".$this->card_owner."</AVSname>";
		$xml .= "<AVSphoneNum></AVSphoneNum>";
		$xml .= "<OrderID>".$this->order_num."</OrderID>";
		$xml .= "<Amount>".$this->amount."</Amount>";
		$xml .= "<Comments>AC</Comments>";
		$xml .= "<ShippingRef></ShippingRef>";
		$xml .= "</NewOrder>";
		$xml .= "</Request>";
		$this->request_xml=$xml;
		return $xml;
	}
   
	private function ParseXmlResponse($xmlstring)
	{
		$xml_parser = xml_parser_create();
		xml_parser_set_option($xml_parser,XML_OPTION_CASE_FOLDING,0);
		xml_parser_set_option($xml_parser,XML_OPTION_SKIP_WHITE,1);
		xml_parse_into_struct($xml_parser, $xmlstring, $vals, $index);
		xml_parser_free($xml_parser);
		
		foreach($vals as $val)
		{
			$tagval=$val['tag'];
			if(($val['tag']!='QuickResp') && ($val['tag']!='NewOrderResp'))	{	
				if(isset($val['value'])){
					$newResArr[$tagval]=$val['value'];
					}
					else{
					$newResArr[$tagval]='';
					}
				}
		}
        return $newResArr;
	}
}
?>