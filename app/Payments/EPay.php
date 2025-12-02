<?php

namespace App\Payments;

class EPay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];

        // 检查是否有 HTTP_REFERER（浏览器前端有，客户端App没有）
        if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            // 浏览器前端：回调到发起支付的前端域名（支持多域名）
            $params['return_url'] = $_SERVER['HTTP_REFERER'] . '#/payment?trade_no=' . $order['trade_no'];
        } else {
            // 客户端App：使用后台配置的站点地址作为 return_url
            // 客户端会通过轮询 /user/order/check?trade_no 来检查支付状态
            $params['return_url'] = config('v2board.app_url') . '/#/payment?trade_no=' . $order['trade_no'];
        }

        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        if ($sign !== md5($str)) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }
}
