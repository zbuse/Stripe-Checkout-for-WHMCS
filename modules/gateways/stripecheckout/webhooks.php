<?php
use Stripe\StripeClient;
use Stripe\Webhook;
use WHMCS\Database\Capsule;
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
$gatewayParams = getGatewayVariables('stripealipay');
$gatewayName = $gatewayParams['name'];
if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
	die("Module Not Activated");
}
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
	die("错误请求");
}
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;
try {
	$event = Webhook::constructEvent(
	        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
	    );
}
catch(\UnexpectedValueException $e) {
	logTransaction($gatewayName, $e, $gatewayName.': Invalid payload');
	http_response_code(400);
	exit();
}
catch(Stripe\Exception\SignatureVerificationException $e) {
	logTransaction($gatewayName, $e, $gatewayName.': Invalid signature');
	http_response_code(400);
	exit();
}
try {
	if ($event->type == 'payment_intent.succeeded') {
		$stripe = new Stripe\StripeClient($gatewayParams['StripeSkLive']);
		$paymentId = $event->data->object->id;
		$paymentIntent = $stripe->paymentIntents->retrieve($paymentId,[]);
		if ($paymentIntent->status == 'succeeded') {
			$invoiceId = checkCbInvoiceID($paymentIntent['metadata']['invoice_id'], $gatewayName);
			checkCbTransID($paymentId);
			//Get Transactions fee
			$charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
			$balanceTransaction = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
			$fee = $balanceTransaction->fee / 100.00;
			$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
			//获取账单信息和用户 id
			$currency = getCurrency( $invoice->userid );
			//获取用户使用货币信息
			if ( strtoupper($currency['code'])  != strtoupper($balanceTransaction->currency )) {
				$feeexchange = stripewechatpay_exchange(strtoupper($balanceTransaction->currency, $currency['code'] ));
				$fee = floor($balanceTransaction->fee * $feeexchange / 100.00);
			}
			logTransaction($gatewayName, $paymentIntent, $gatewayName.': Callback successful');
			addInvoicePayment($invoiceId, $paymentId,$paymentIntent['metadata']['original_amount'],$fee,$gatewayName);
		}
		echo json_encode(['status' => $paymentIntent->status ]);
	}
}
catch (Exception $e) {
	logTransaction($gatewayName, $e, 'error-callback');
	http_response_code(400);
	echo $e;
}
