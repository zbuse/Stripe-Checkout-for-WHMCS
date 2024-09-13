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
try {
    $event = null;
    if (isset($_POST['check'])) {
        // ajax 查询
        $sessionKey = $gatewayParams['paymentmethod'] . $_POST['check'];
        session_start();
        $paymentId = $_SESSION[$sessionKey];
    } else {
        $event = Webhook::constructEvent( @file_get_contents('php://input') ,  $_SERVER['HTTP_STRIPE_SIGNATURE'] , $gatewayParams['StripeWebhookKey']);
        $paymentId = $event->data->object->id;
        $status = $event->type;
    }
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

$stripe = new Stripe\StripeClient($gatewayParams['StripeSkLive']);
$paymentIntent = $stripe->paymentIntents->retrieve($paymentId,[]);
$status = $paymentIntent->status;

//验证回传信息避免多个站点的webhook混乱，返回状态错误。
if (strpos( $paymentIntent['description'] , $gatewayParams['companyname'] ) !== false) {  die("nothing to do"); }   


checkCbTransID($paymentId);    //检查到账单已入账则终止运行
$invoiceId = checkCbInvoiceID($paymentIntent['metadata']['invoice_id'], $gatewayName);

try ( $status == 'payment_intent.succeeded' || $status == 'succeeded' ) {
    //$event->type == 'payment_intent.succeeded'
    //$paymentIntent->status == 'succeeded'

    //Get Transactions fee
    $charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
    $balanceTransaction = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
    $fee = $balanceTransaction->fee / 100.00;
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    //获取账单信息和用户 id
    $currency = getCurrency( $invoice->userid );
    //获取用户使用货币信息
    if ( strtoupper($currency['code'])  != strtoupper($balanceTransaction->currency )) {
        $feeexchange = exchange(strtoupper($balanceTransaction->currency, $currency['code'] ));
        $fee = floor($balanceTransaction->fee * $feeexchange / 100.00);
    }
    logTransaction($gatewayName, $paymentIntent, $gatewayName.': Callback successful');
    addInvoicePayment($invoiceId, $paymentId,$paymentIntent['metadata']['original_amount'],$fee,$gatewayName);
    return json_encode(['status' => $status ]);
}
catch (Exception $e) {
    logTransaction($gatewayParams['paymentmethod'], $e->getMessage, 'error-callback');
    echo $e->getMessage;
    http_response_code(400);
}
