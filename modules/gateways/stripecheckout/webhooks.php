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

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
    );
} catch(\UnexpectedValueException $e) {
    logTransaction($paymentmethod, $e, 'stripecheckout: Invalid payload');
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    logTransaction($paymentmethod, $e, 'stripecheckout: Invalid signature');
    http_response_code(400);
    exit();
}

try {
    $fee = 0;
    if ($event->type == 'checkout.session.completed') {
        $stripe = new StripeClient($gatewayParams['StripeSkLive']);
        $checkoutId = $event->data->object->id;
        $checkout = $stripe->checkout->sessions->retrieve($checkoutId,[]);
		
        if ( $checkout->payment_status == 'paid' || $checkout->status == 'complete') {
            $invoiceId = checkCbInvoiceID($checkout['metadata']['invoice_id'], $paymentmethod);
            $paymentId = $checkout->payment_intent;
		checkCbTransID($paymentId);

        //Get Transactions fee
        $paymentIntent = $stripe->paymentIntents->retrieve($checkout->payment_intent, []);
        $charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
        $balanceTransaction = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
        $fee = $balanceTransaction->fee / 100.00;
		$invoice = Capsule::table('tblinvoices')->where('id', $invoiceid)->first();  //获取账单信息和用户 id
		$currency = getCurrency( $invoice->userid ); //获取用户使用货币信息
		
if ( strtoupper($currency['code'])  != strtoupper($balanceTransaction->currency )) {
        $feeexchange = stripecheckout_exchange_exchange(strtoupper($balanceTransaction->currency),$currency['code']);
        $fee = floor($balanceTransaction->fee * $feeexchange / 100.00);
}
            logTransaction($paymentmethod, $checkout , 'stripecheckout: Callback successful');
            addInvoicePayment( $invoiceId,$paymentId, $checkout['metadata']['original_amount'] , $fee, $paymentmethod);
            echo json_encode( ['status'=>$checkout->payment_status] );
            http_response_code(200);
        } else {
            echo json_encode( ['status'=>$checkout->payment_status] );
			http_response_code(400);
		}
    }
        
} catch (Exception $e) {
    logTransaction($paymentmethod, $e, 'error-callback');
    http_response_code(400);
    echo $e;
}
