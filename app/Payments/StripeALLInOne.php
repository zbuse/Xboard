<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

class StripeALLInOne {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '请使用符合ISO 4217标准的三位字母，例如GBP',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => 'whsec_....',
                'type' => 'input',
            ],
            'description' => [
                'label' => '自定义商品介绍',
                'description' => '',
                'type' => 'input',
            ],
            'payment_method' => [
                'label' => '支付方式',
                'description' => '请输入alipay或者wechat_pay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $currency = $this->config['currency'];
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) {
            throw new ApiException(__('Currency conversion has timed out, please try again later'));
        }
        $stripe = new \Stripe\StripeClient($this->config['stripe_sk_live']);


        $stripePaymentMethod = $stripe->paymentMethods->create([
            'type' => $this->config['payment_method'],
        ]);
        // 准备支付意图的基础参数
        $params = [
            'amount' => floor($order['total_amount'] * $exchange),
            'currency' => $currency,
            'confirm' => true,
            'payment_method' => $stripePaymentMethod->id,
            'automatic_payment_methods' => ['enabled' => true],
            'statement_descriptor' => 'sub-' . $order['user_id'] . '-' . substr($order['trade_no'], -8),
            'description' => $this->config['description'],
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'identifier' => ''
            ],
            'return_url' => $order['return_url']
        ];

        // 如果支付方式为 wechat_pay，添加相应的支付方式选项
        if ($this->config['payment_method'] === 'wechat_pay') {
            $params['payment_method_options'] = [
                'wechat_pay' => [
                    'client' => 'web'
                ],
            ];
        }
        //更新支持最新的paymentIntents方法，Sources API将在今年被彻底替
        $stripeIntents = $stripe->paymentIntents->create($params);

        $nextAction = null;
        //jump url
        $jumpUrl = null;
        $actionType = 0;
        if (!$stripeIntents['next_action']) {
            throw new ApiException(__('Payment gateway request failed'));
        }else {
            $nextAction = $stripeIntents['next_action'];
        }

        switch ($this->config['payment_method']){
            case "alipay":
                if (isset($nextAction['alipay_handle_redirect'])){
                    $jumpUrl = $nextAction['alipay_handle_redirect']['url'];
                    $actionType = 1;
                }else {
                    throw new ApiException('unable get alipay redirect url', 500);
                }
                break;
            case "wechat_pay":
                if (isset($nextAction['wechat_pay_display_qr_code'])){
                    $jumpUrl = $nextAction['wechat_pay_display_qr_code']['data'];
                    Log::info($jumpUrl);
                }else {
                    throw new ApiException('unable get alipay redirect url', 500);
                }
        }
        return [
            'type' => $actionType,
            'data' => $jumpUrl
        ];
    }

    public function notify($params)
    {
        \Stripe\Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $event = \Stripe\Webhook::constructEvent(
                get_request_content(),
                $_SERVER['HTTP_STRIPE_SIGNATURE'],
                $this->config['stripe_webhook_key']
            );
        } catch (\Stripe\Error\SignatureVerification $e) {
            abort(400);
        }
        switch ($event->type) {
            case 'source.chargeable':
                $object = $event->data->object;
                \Stripe\Charge::create([
                    'amount' => $object->amount,
                    'currency' => $object->currency,
                    'source' => $object->id,
                    'metadata' => json_decode($object->metadata, true)
                ]);
                break;
            case 'charge.succeeded':
                $object = $event->data->object;
                if ($object->status === 'succeeded') {
                    if (!isset($object->metadata->out_trade_no) && !isset($object->source->metadata)) {
                        return('order error');
                    }
                    $metaData = isset($object->metadata->out_trade_no) ? $object->metadata : $object->source->metadata;
                    $tradeNo = $metaData->out_trade_no;
                    return [
                        'trade_no' => $tradeNo,
                        'callback_no' => $object->id
                    ];
                }
                break;
            default:
                throw new ApiException('event is not support');
        }
        return('success');
    }

    private function exchange($from, $to)
    {
        $from = strtolower($from);
        $to = strtolower($to);
        $result = file_get_contents("https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/" . $from . ".min.json");
        $result = json_decode($result, true);
        return $result[$from][$to];
    }
}
