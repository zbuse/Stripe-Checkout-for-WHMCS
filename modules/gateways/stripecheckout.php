<?php
use Stripe\StripeClient;
if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}
function stripecheckout_MetaData() {
	return array(
		        'DisplayName' => 'Stripe Checkout',
		        'APIVersion' => '1.1', // Use API Version 1.1
	'DisableLocalCreditCardInput' => true,
		        'TokenisedStorage' => false,
		    );
}
function stripecheckout_config($params) {
	return array(
		        'FriendlyName' => array(
		            'Type' => 'System',
		            'Value' => 'Stripe Alipay',
			),
		        'StripeSkLive' => array(
		            'FriendlyName' => 'SK_LIVE 密钥',
		            'Type' => 'text',
		            'Size' => 30,
		            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
		        ),
		        'StripePkLive' => array(
		            'FriendlyName' => 'PK_LIVE 密钥',
		            'Type' => 'text',
		            'Size' => 30,
		            'Description' => '填写从Stripe获取到的密钥（PK_LIVE）',
		        ),
		        'StripeWebhookKey' => array(
		            'FriendlyName' => 'Webhook 密钥',
		            'Type' => 'text',
		            'Size' => 30,
		      'Description' => "<br> <div class='alert alert-success' role='alert' style='margin-bottom: 0px;'>Webhook设置 <a href='https://dashboard.stripe.com/webhooks' target='_blank'><span class='glyphicon glyphicon-new-window'></span> Stripe webhooks</a> 侦听的事件:payment_intent.succeeded <br>
      Stripe webhook " .$params['systemurl']."modules/gateways/stripecheckout/webhooks.php
               </div><style>* {font-family: Microsoft YaHei Light , Microsoft YaHei}</style>"
			),
		        'StripeCurrency' => array(
		            'FriendlyName' => '发起交易货币[默认CNY]',
		            'Type' => 'text',
			    "Default" => "CNY",
			    'Size' => 30,
		            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
		        ),
		        'RefundFixed' => array(
		            'FriendlyName' => '退款扣除固定金额',
		            'Type' => 'text',
		            'Size' => 30,
		      'Default' => '0.00',
		      'Description' => '$'
		        ),
		        'RefundPercent' => array(
		            'FriendlyName' => '退款扣除百分比金额',
		            'Type' => 'text',
		            'Size' => 30,
			    'Description' => "%",
			),
		    );
}
function stripecheckout_link($params) {
	global $_LANG;
	session_start();
	$originalAmount = isset($params['basecurrencyamount']) ? $params['basecurrencyamount'] : $params['amount'];
	//解决Convert To For Processing后出现入账金额不对问题
	$StripeCurrency = empty($params['StripeCurrency']) ? "CNY" : $params['StripeCurrency'];
	$amount = ceil($params['amount'] * 100.00);
	$setcurrency = $params['currency'];
	$StripeMethodType = explode(',', $params['Stripemethod']);
	$stripe = new Stripe\StripeClient($params['StripeSkLive']);
	$return_url = $params['systemurl'] . 'viewinvoice.php?paymentsuccess=true&id=' . $params['invoiceid'];
	$paymentmethod = $params['paymentmethod'];
	$sessionKey = $paymentmethod . $params['invoiceid'] . round($originalAmount);
	// 将金额一并写入防止变动不能请求新的支付
	if ($StripeCurrency !=  $setcurrency ) {
		$exchange = stripecheckout_exchange( strtoupper($setcurrency) , strtoupper($StripeCurrency) );
		if (!$exchange) {
			return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
		}
		$setcurrency = $StripeCurrency;
		$amount = floor($params['amount'] * $exchange * 100.00);
	}
	try {
		$paymentIntent = null;
		$paymentIntentParams = [
				        'amount' => $amount,
				        'currency' => $setcurrency ,
				        'payment_method_types' => ['card','alipay','wechat_pay',],
				        'payment_method_types' =>$StripeMethodType ,
					'payment_method_options' => ['card' => ['request_three_d_secure' => 'automatic',],'wechat_pay' => ['client' => 'web',]],
				        'description' => $params['companyname'] . $_LANG['invoicenumber'] . $params['invoiceid'],
			        	'confirm' => true,
				        'metadata' => [
				                    'invoice_id' => $params['invoiceid'],
				                    'original_amount' => $originalAmount
						    'description' => $params['companyname'],
				                ],
				            ];
		//将paymentIntentId存入 session 避免多次创建交易请求
		if (isset($_SESSION[$sessionKey])) {
			$paymentIntentId = $_SESSION[$sessionKey];
			$paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
		} else {
			$paymentIntent = $stripe->paymentIntents->create($paymentIntentParams);
			$_SESSION[$sessionKey] = $paymentIntent->id;
		}
		if ($paymentIntent->status == 'requires_confirmation') {
			$paymentIntent = $stripe->paymentIntents->confirm($paymentIntent->id);
		}
		$client_secret = $paymentIntent->client_secret;
	//跳转回来直接判断入账
	if ($paymentIntent->status == 'succeeded') {
		checkCbTransID($paymentIntent->id);
		//Get Transactions fee
		$charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
		$balanceTransaction = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
		$fee = $balanceTransaction->fee / 100.00;
		$userCurrency = getCurrency($params['clientdetails']['userid'])['code'];
	if ( strtoupper($userCurrency) != strtoupper($balanceTransaction->currency )) {
			$feeexchange = stripealipay_exchange(strtoupper($balanceTransaction->currency) ,  isset($params['basecurrency']) ? $params['basecurrency'] : $userCurrency );
			$fee = floor($balanceTransaction->fee * $feeexchange / 100.00);
		}
		logTransaction($paymentmethod, $paymentIntent, $params['name'] .': return successful');
		addInvoicePayment($params['invoiceid'], $paymentIntent->id ,$paymentIntent['metadata']['original_amount'],$fee,$params['paymentmethod']);
		header("Refresh: 0; url=$return_url");
		return $paymentIntent->status;
	}
		
		if ($paymentIntent->status != 'succeeded') {
			return '
     <script src="https://js.stripe.com/v3/"></script>
    <form id="payment-form">
      <div id="payment-element" class="mb-3">
<!--Stripe.js injects the Payment Element-->
      </div>
      <button id="submit"  class="btn btn-success">
        <div class="spinner hidden" id="spinner"></div>
        <span id="button-text">'.$params["langpaynow"].'</span>
      </button>
      <div id="payment-message" class="hidden"></div>
    </form>
<script>
const stripe = Stripe("'.$params['StripePkLive'].'");
const clientSecret = "'.$client_secret.'";
const return_url= "'.$return_url.'";
let elements;
initialize();
document
  .querySelector("#payment-form")
  .addEventListener("submit", handleSubmit);
function initialize() {
  elements = stripe.elements({ clientSecret });
     const paymentElementOptions = {
       floating: {
         alignment: "center",
       },  floating: true,
 defaultCollapsed: false,
size : "mobile",
       layout: "tabs",
//      layout: "accordion",
};
  const paymentElement = elements.create("payment", paymentElementOptions);
    paymentElement.mount("#payment-element");
}
async function handleSubmit(e) {
  e.preventDefault();
  setLoading(true);
  const { error } = await stripe.confirmPayment({
    elements,
    confirmParams: {
      return_url: return_url,
    },
  });
  // redirected to the `return_url`.
  if (error.type === "card_error" || error.type === "validation_error") {
    showMessage(error.message);
  } else {
    showMessage("An unexpected error occurred.");
  }
  setLoading(false);
}
// ------- UI helpers -------
function showMessage(messageText) {
  const messageContainer = document.querySelector("#payment-message");
  messageContainer.classList.remove("hidden");
  messageContainer.textContent = messageText;
  setTimeout(function () {
    messageContainer.classList.add("hidden");
    messageContainer.textContent = "";
  }, 4000);
}
// Show a spinner on payment submission
function setLoading(isLoading) {
  if (isLoading) {
    // Disable the button and show a spinner
    document.querySelector("#submit").disabled = true;
    document.querySelector("#spinner").classList.remove("hidden");
    document.querySelector("#button-text").classList.add("hidden");
  } else {
    document.querySelector("#submit").disabled = false;
    document.querySelector("#spinner").classList.add("hidden");
    document.querySelector("#button-text").classList.remove("hidden");
  }
}
    </script>
 ';
		}
	}
	catch (Exception $e) {
		return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理'. $e->getMessage() .'</div>';
	}
	
	return '<div class="alert alert-danger text-center" role="alert">'. $_LANG['expressCheckoutError'] .'</div>';
}
function stripecheckout_refund($params) {
	$stripe = new Stripe\StripeClient($params['StripeSkLive']);
	$amount = ($params['amount'] - $params['RefundFixed']) / ($params['RefundPercent'] / 100 + 1);
	try {
		$responseData = $stripe->refunds->create([
				            'payment_intent' => $params['transid'],
				            'amount' => $amount * 100.00,
				            'metadata' => [
				                'invoice_id' => $params['invoiceid'],
				                'original_amount' => $params['amount'],
				            ]
				        ]);
		return array(
				            'status' => ($responseData->status === 'succeeded' || $responseData->status === 'pending') ? 'success' : 'error',
				            'rawdata' => $responseData,
				            'transid' => $params['transid'],
				            'fees' => $params['amount'],
				        );
	}
	catch (Exception $e) {
		return array(
				            'status' => 'error',
				            'rawdata' => $e->getMessage(),
				            'transid' => $params['transid'],
				            'fees' => $params['amount'],
				        );
	}
}
function stripecheckout_exchange($from, $to) {
	try {
		$url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';
		$result = file_get_contents($url, false);
		$result = json_decode($result, true);
		return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
	}
	catch (Exception $e) {
		echo "Exchange error: " . $e;
		return "Exchange error: " . $e;
	}
}
