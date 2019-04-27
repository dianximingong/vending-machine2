<?php/** * Created by PhpStorm. * User: Administrator * Date: 2018/5/9 0009 * Time: 15:31 */namespace app\index\controller;use cache\GetCache;use EasyWeChat\Factory;use think\Controller;use think\Db;use \GatewayClient\Gateway;use think\Log;class Payment extends Controller{    /**     * 回调     */    public function wepay_notify(){//        $message = [//            'out_trade_no' => 'OG201903191621220E582',//            'transaction_id' => '666666',//            'return_code' => 'SUCCESS',//            'result_code' => 'SUCCESsS',//            'total_fee' => 20,//        ];//        p($message,false);        $wechat_option = GetCache::getCache('wechat');        $config = [            // 必要配置            'app_id'             => $wechat_option['app_id'],            'mch_id'             => $wechat_option['mch_id'],            'key'                => $wechat_option['key'],   // API 密钥            // 如需使用敏感接口（如退款、发送红包等）需要配置 API 证书路径(登录商户平台下载 API 证书)            'cert_path'          => 'path/to/your/cert.pem', // XXX: 绝对路径！！！！            'key_path'           => 'path/to/your/key',      // XXX: 绝对路径！！！！            'notify_url'         => url('payment/wepay_notify','','',true),     // 你也可以在下单时单独设置来想覆盖它        ];        $app = Factory::payment($config);        $response = $app->handlePaidNotify(function($message, $fail){            Log::write(json_encode($message),'error');            // 使用通知里的 "微信支付订单号" 或者 "商户订单号" 去自己的数据库找到订单            $orders_info = Db::name('saleorders')->where(['so_trade_no'=>$message['out_trade_no'],'so_pay_status'=>3])->find();            if(!$orders_info) {                // 没有找到订单                return true;            }            if($orders_info['so_money']*100 != $message['total_fee']){                // 金额和订单不对应                return true;            }            if ($message['return_code'] === 'SUCCESS') { // return_code 表示通信状态，不代表支付状态                // 用户是否支付成功                if ($message['result_code'] === 'SUCCESS') {                    $machine_channel = Db::name('machine_channel')->where(['channel_id'=>$orders_info['so_channel_id']])->find();                    if($machine_channel['channel_stock'] < 1){                        Db::name('saleorders')->where(['so_id'=>$orders_info['so_id']])->update([                            'so_error_remark' => '机器没有库存',                        ]);                    }else{                        Db::name('saleorders')->where(['so_id'=>$orders_info['so_id']])->update([                            'so_pay_status' => 1,                            'so_out_status' => 3,                            'so_pay_notify_time' => date('Y-m-d H:i:s'),                            'so_mch_no' => $message['transaction_id'],                        ]);                        $Check_cmd = new Check_cmd();                        Log::write($orders_info['so_machine']." ".$message['out_trade_no']." ".$orders_info['so_channel'],'error');                        $cmd = $Check_cmd->send_out_goods($message['out_trade_no'],$orders_info['so_channel'],'01');                        Db::name('machine_channel')->where(['channel_id'=>$orders_info['so_channel_id']])->dec('channel_stock')->update();                        // 用户支付成功//                        Gateway::$registerAddress = "127.0.0.1:1238";//                        Gateway::sendToUid($orders_info['so_machine'],$cmd["hex"]);                    }                } elseif ($message['result_code'] === 'FAIL') {                    Db::name('saleorders')->where(['so_id'=>$orders_info['so_id']])->update([                        'so_pay_status' => 2,                        'so_out_status' => 3,                        'so_pay_notify_time' => date('Y-m-d H:i:s'),                        'so_mch_no' => $message['transaction_id'],                    ]);                }            } else {                return $fail('通信失败，请稍后再通知我');            }            return true; // 返回处理完成        });        $response->send();    }}