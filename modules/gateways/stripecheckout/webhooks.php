<?php
use Stripe\StripeClient;
use Stripe\Webhook;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayParams = getGatewayVariables("stripecheckout");
$paymentmethod = $gatewayParams['name'];
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    die($_LANG['errors']['badRequest']);
}

function exchange($from, $to) {
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';
        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
    }
    catch (Exception $e) {
        echo "Exchange error: " . $e->getMessage;
        return "Exchange error: " . $e->getMessage;
    }
}


try {
    $event = null;
        $event = Webhook::constructEvent( @file_get_contents('php://input') ,  $_SERVER['HTTP_STRIPE_SIGNATURE'] , $gatewayParams['StripeWebhookKey']);
        $checkoutId = $event->data->object->id;
        $status = $event->type;
}
catch(\UnexpectedValueException $e) {
    logTransaction($gatewayName, $e, $gatewayName.': Invalid payload');
    echo "Invalid payload";
    http_response_code(400);
    exit();
}
catch(Stripe\Exception\SignatureVerificationException $e) {
    logTransaction($gatewayName, $e, $gatewayName.': Invalid signature');
    echo "Invalid signature";
    http_response_code(400);
    exit();
}

try {
      $fee = 0;
      if ( $event->type == 'checkout.session.completed') {
        $stripe = new StripeClient($gatewayParams['StripeSkLive']);
        $checkout = $stripe->checkout->sessions->retrieve($checkoutId,[]);
        $invoiceId = checkCbInvoiceID($checkout['metadata']['invoice_id'], $paymentmethod);
        $paymentId = $checkout->payment_intent;
	$paymentIntent = $stripe->paymentIntents->retrieve($paymentId, []);
	//验证回传信息避免多个站点的webhook混乱，返回状态错误。
	if ( $paymentIntent['metadata']['description'] != $gatewayParams['companyname']  ) {  die("nothing to do"); }
	checkCbTransID($paymentId);

        //Get Transactions fee
        $charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
        $balanceTransaction = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
        $fee = $balanceTransaction->fee / 100.00;
		$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();  //获取账单信息和用户 id
		$currency = getCurrency( $invoice->userid ); //获取用户使用货币信息
		
if ( strtoupper($currency['code'])  != strtoupper($balanceTransaction->currency )) {
        $feeexchange = exchange(strtoupper($balanceTransaction->currency),$currency['code']);
        $fee = floor($balanceTransaction->fee * $feeexchange / 100.00);
}
            logTransaction($paymentmethod, $checkout , 'stripecheckout: Callback successful');
            addInvoicePayment( $invoiceId,$paymentId, $checkout['metadata']['original_amount'] , $fee, $paymentmethod);
            echo json_encode( ['status'=>$checkout->payment_status] );
            http_response_code(200);
        } else {
            echo json_encode( ['status'=>'null'] );
	    http_response_code(400);
	}
 
        
} catch (Exception $e) {
    logTransaction($paymentmethod, $e, 'error-callback');
    http_response_code(400);
    echo $e;
}
