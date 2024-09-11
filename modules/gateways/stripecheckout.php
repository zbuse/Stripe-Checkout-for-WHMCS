<?php

use Stripe\StripeClient;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function gatewaymodule_MetaData()
{
    return array(
        'DisplayName' => 'Stripe Checkout',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function stripecheckout_config($params)
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Stripe Checkout',
        ),
        'StripeSkLive' => array(
            'FriendlyName' => 'SK_LIVE ',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
        ),
        'StripeWebhookKey' => array(
            'FriendlyName' => 'Webhook 密钥[必设置]',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的Webhook密钥签名',
	),
        'StripeCurrency' => array(
            'FriendlyName' => '发起交易货币[可留空]',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
        ),
        'RefundFixed' => array(
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
            'Description' => '$/¥'
        ),
        'RefundPercent' => array(
            'FriendlyName' => '退款扣除百分比金额',
            'Type' => 'text',
            'Size' => 30,
            'Default' => '0.00',
	    'Description' => "% <br><br> <div class='alert alert-success' role='alert' style='margin-bottom: 0px;'>Webhook设置 <a href='https://dashboard.stripe.com/webhooks' target='_blank'><span class='glyphicon glyphicon-new-window'></span> Stripe webhooks</a> 侦听的事件:checkout.session.completed和checkout.session.async_payment_succeeded  <br>
	    Stripe webhook " .$params['systemurl']."modules/gateways/stripecheckout/webhooks.php
               </div><style>* {font-family: Microsoft YaHei Light , Microsoft YaHei}</style><select style='display:none'>"
        ),
    );
}

function stripecheckout_link($params)
{
    global $_LANG;
    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);
	$amount = ceil($params['amount'] * 100.00);
	$setcurrency = $params['currency'];
	if ($params['StripeCurrency']) {
	    $exchange = stripecheckout_exchange($params['currency'], strtoupper($params['StripeCurrency']));
	    if (!$exchange) {
	        return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
	    }
	$amount = floor($params['amount'] * $exchange * 100.00);
	$setcurrency = $params['StripeCurrency'];
}

        $checkout = $stripe->checkout->sessions->create([
            'customer_email' => $params['clientdetails']['email'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $setcurrency ,
                        'product_data' => ['name' => $params['companyname'] . $_LANG['invoicenumber'] . $params['invoiceid']],
                        'unit_amount' => $amount ,
                    ],
                    'quantity' => 1
                ],
            ],
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $params['amount']
            ],
        'mode' => 'payment',
            'success_url' => $params['systemurl'] . 'viewinvoice.php?paymentsuccess=true&id=' . $params['invoiceid'],
        ]);
    } catch (Exception $e) {
        return '<div class="alert alert-danger text-center" role="alert">' .  $_LANG['expressCheckoutError']  . $e.'</div>';
    }
    if ($checkout->payment_status == 'unpaid') {
        return '<form action="' . $checkout['url'] . '" method="get"><input type="submit" class="btn btn-primary" value="' . $params['langpaynow'] . '" /></form>';
    }
    return '<div class="alert alert-danger text-center" role="alert">'. $_LANG['expressCheckoutError'] .'</div>';
}

function stripecheckout_refund($params)
{
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
	    $status => in_array($responseData->status, ['succeeded', 'pending']) ? 'success' : 'error',
            'rawdata' => $responseData,
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    }
}
function stripecheckout_exchange($from, $to)
{
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';

        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
    } catch (Exception $e) {
        echo "Exchange error: " . $e;
        return "Exchange error: " . $e;
    }
}

