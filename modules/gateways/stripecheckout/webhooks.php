<?php
use WHMCS\Database\Capsule;
use Stripe\Webhook;
use Stripe\StripeClient;
use Stripe\Exception\SignatureVerificationException;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayParams = getGatewayVariables('stripecheckout');
$gatewayName = $gatewayParams['name'];

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$result = notify($gatewayParams);
switch ($result['status']) {
    case 'succeeded':
        echo json_encode(['status' => $result['status']]);
    	checkCbTransID($result['TransId']); // 检查到账单已入账则终止运行
        $invoiceid = checkCbInvoiceID($result['invoiceid'], $gatewayName);
        addInvoicePayment($invoiceid, $result['TransId'], $result['original_amount'], $result['fee'], $gatewayParams['paymentmethod']);
        logTransaction($gatewayName, $result, $gatewayName . ': Callback successful');
        break;
    case 'requires_action':
        echo json_encode(['status' => $result['status']]);
    break;
    case 'nothing':
        echo json_encode(['status' => 'nothing to do']);
        break;
    default:
    echo json_encode(['status' => $result['status']]);
}

function exchange($from, $to) {
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';
        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
    } catch (Exception $e) {
        return "Exchange error: " . $e->getMessage();
    }
}

function calculateFee($invoiceId, $transfee, $currency) {
    // 获取用户使用货币信息
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    $userCurrency = getCurrency($invoice->userid)['code'];
    // 比较用户货币和交易货币
    if (strtoupper($userCurrency) !== strtoupper($currency)) {
        $rate = exchange($currency, $userCurrency);
        return floor($transfee * $rate / 100.00);
    }
    return $transfee / 100.00;
}

function notify($Params) {
     global $_LANG;
    $stripe = new StripeClient($Params['StripeSkLive']);

    if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
        try {
        $event = null;
        $event = Webhook::constructEvent(
                @file_get_contents('php://input'),
                $_SERVER['HTTP_STRIPE_SIGNATURE'],
                $Params['StripeWebhookKey']
            );
        } catch (\UnexpectedValueException $e) {
            logTransaction($Params['name'], $e, $Params['name'] . ': Invalid payload error step 1');
            http_response_code(400);
            return ['status' => 'Invalid payload'];
        } catch (SignatureVerificationException $e) {
            logTransaction($Params['name'], $e, $Params['name'] . ': Invalid signature error step 2');
            http_response_code(400);
            return ['status' => 'Invalid signature error step 2'];
        }
    }
    if ($event) {
        switch ($event->type) {
            case 'checkout.session.completed':
		    $checkout = $event->data->object;

	$filed =  ['description' => $checkout->metadata->description . $_LANG['invoicenumber'] . $checkout->metadata->invoice_id,
                'metadata' => [
                    'invoice_id' => $checkout->metadata->invoice_id,
                    'original_amount' => $checkout->metadata->original_amount,
		    'description' => $checkout->metadata->description,
		]];
	$paymentIntent = $stripe->paymentIntents->update($checkout->payment_intent, $filed);
	break;
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                break;
        }
    }
    elseif (isset($_POST['check'])) {
        try {
           $paymentIntent = $stripe->paymentIntents->retrieve($_POST['check'], []);
    } catch (Exception $e) {
            logTransaction($Params['name'], $e->getMessage()  , $Params['name']. ' paymentIntent error error step3');
            http_response_code(400);
            return ['status' => $e->getMessage() . 'error step 3'];
        }
    }

    try {
	$paymentId = $paymentIntent->id;
        $invoice_id = $paymentIntent['metadata']['invoice_id'];
        $description = $paymentIntent['metadata']['description'];
        $original_amount = $paymentIntent['metadata']['original_amount'];
        $status = $paymentIntent->status;
    if ($status != 'succeeded' ) { return  ['status' =>$status];}
    if ($description != $Params['companyname']) { return  ['status' => 'nothing'];  }

        $charge = $stripe->charges->retrieve($paymentIntent->latest_charge, []);
            $trans = $stripe->balanceTransactions->retrieve($charge->balance_transaction, []);
            $currency = $trans->currency;
	    $transfee = $trans->fee;
	    $invoiceid =  $invoice_id;
            $fee = calculateFee($invoice_id, $transfee, $currency);
            return [
                'status' => $paymentIntent->status,
		'TransId' => $paymentId ,
		'invoiceid' =>  $invoice_id,
                'currency' => $trans->currency,
                'transfee' => $trans->fee,
                'fee' => $fee,
                'original_amount' => $original_amount
        ];
    }
        catch (Exception $e) {
           logTransaction($Params['name'], $e, $Params['name'] . ' error-callback error step 4');
            http_response_code(400);
            return ['status' =>  $e->getMessage() . ' error step 4'];
        }

    return ['status' => 'fail error step end'];
}

?>
