<?php
namespace app\weixin\controller;

use cache\GetCache;
use EasyWeChat\Factory;
use EasyWeChat\Kernel\Messages\Image;
use think\Controller;
use think\Db;
use think\Log;

class Index extends Controller{
    protected $app;
    protected $open_id;

    public function __construct()
    {
        parent::__construct(); // TODO: Change the autogenerated stub
        $wechat_option = GetCache::getCache('wechat');
        $this->app = Factory::officialAccount($wechat_option);
    }

    public function index(){
        $this->app->server->push(function ($message) {
            $this->open_id = $message['FromUserName'];
            Log::write('all ---- '.json_encode($message),'error');
            switch ($message['MsgType']) {
                case 'event':
//                    return "您好！欢迎关注我!";
                    Log::write('event ---- '.json_encode($message),'error');
                    return $this->receive_event($message);
                    break;
                case 'text':
                    return $this->receive_text($message['Content']);
                    break;
                case 'image':
                    return '收到图片消息';
                    break;
//                case 'voice':
//                    return '收到语音消息';
//                    break;
//                case 'video':
//                    return '收到视频消息';
//                    break;
//                case 'location':
//                    return '收到坐标消息';
//                    break;
//                case 'link':
//                    return '收到链接消息';
//                    break;
//                case 'file':
//                    return '收到文件消息';
                // ... 其它消息
                default:
                    return '不明白你的意思哦！';
                    break;
            }

            // ...
        });
        $response = $this->app->server->serve();
        // 将响应输出
        $response->send(); // Laravel 里请使用：return $response;
    }

    /**
     * 功能：收到文字
     */
    public function receive_text($keyword = ''){
        $info = Db::name('keyword')->where(['keyword_triggerType' => 2,'keyword_title'=>trim($keyword)])->find();
        Log::write('receive_text ---- '.json_encode($info),'error');
        if($info){
            if($info['keyword_responseType'] == 2 && $info['keyword_pic'] != '') {
                $image = new Image($info['keyword_media_id']);
                $this->app->customer_service->message($image)->to($this->open_id)->send();
            }
            $content = $info['keyword_reply'];
            if($info['keyword_url']) $content = '<a href="'. $info['keyword_url'] .'">'.$content.'</a>';
            return $content;
        }
        return '不明白你的意思哦！';
    }

    /**
     * 处理事件内容
     */
    public function receive_event($message){
        $event = $message['Event'];
        if(!$event){
            return '未知事件';
        }
        if($event == 'subscribe'){//关注事件
            Log::write('subscribe ---- '.json_encode($message),'error');
            return $this->receive_subscribe(); // 处理关注事件
        }
        if($event == 'unsubscribe'){ // 取消关注事件
            Log::write('unsubscribe ---- '.json_encode($message),'error');
            return $this->receive_unsubscribe(); // 处理取消关注事件
        }
        if($event == 'CLICK'){
            Log::write('CLICK ---- '.json_encode($message),'error');
        }
        if($event == 'SCAN'){ // 扫描二维码
            Log::write('SCAN ---- '.json_encode($message),'error');
//            $this->receive_scan($openid,$event); // 处理关注事件
        }
        if($event == 'VIEW'){ // 扫描二维码
            Log::write('VIEW ---- '.json_encode($message),'error');
//            $this->receive_subscribe($openid); // 处理关注事件
        }
    }

    /**
     * 功能：扫码进来的
     * @param string $openid
     * @param array $event
     */
    public function receive_scan($openid='',$event=[]){

    }


    /**
     * 处理关注事件
     * @param string $openid
     */
    public function receive_subscribe(){
        $info = $this->app->user->get($this->open_id);
        $user_info = $this->getUserinfo($this->open_id,$info);
        $wechat_reply = GetCache::getCache('wechat_reply');
        $content = '您好！欢迎关注我！';
        if(isset($wechat_reply) && $wechat_reply['content']) $content = $wechat_reply['content'];
        return $content;
    }


    /**
     * 处理取消关注事件
     * @param string $openid
     */
    public function receive_unsubscribe(){
        $user_map['user_openid'] = $this->open_id;
        $user_info = Db::name("user")->where($user_map)->find();
        if($user_info){
            Db::name('user')->where(['user_id'=>$user_info['user_id']])->inc('user_unsubscribe_num')->update([
                'user_subscribe' => 2, // 用户是否关注公众号 1：关注 0：未关注 2：取消关注
            ]); // 更新用户信息
        }
        return '处理成功！';
    }


    /**
     * 用户信息处理
     * @param $openid
     * @param array $info   用户信息
     * @return array|false|\PDOStatement|string|\think\Model
     */
    private function getUserinfo($openid,$info=[]){
        $user_map['user_openid'] = $openid;
        $user_info = Db::name("user")->where($user_map)->find();
        if(!$user_info){
            $user_info = [
                'user_openid'           => $openid,
                'user_unionid'          => isset($info['unionid']) ? $info['unionid'] : '',
                'user_name'             => $info['nickname'],
                'user_pic'              => $info['headimgurl'],
                'user_gender'           => $info['sex'],
                'user_subscribe'        => 1,
                'user_createTime'       => date('Y-m-d H:i:s'),
            ];
            $user_info['user_id'] = Db::name('user')->insertGetId($user_info);//插入一条代理的粉丝
        }
        else if(trim($user_info['user_name']) != $info['nickname'] || trim($user_info['user_openid']) == ''){
            $user_info['user_unionid'] = isset($info['unionid']) ? $info['unionid'] : '';// unionid
            $user_info['user_openid'] = $openid; //
            $user_info['user_name'] = $info['nickname']; // 昵称
            $user_info['user_pic'] = $info['headimgurl']; // 头像
            $user_info['user_gender'] = $info['sex']; // 性别
            $user_info['user_subscribe'] = 1; // 用户是否关注公众号 1：关注 0：未关注 2：取消关注
            Db::name('user')->update($user_info); // 更新用户信息
        }
        else{
            $user_info['user_subscribe'] = 1; // 用户是否关注公众号 1：关注 0：未关注 2：取消关注
            Db::name('user')->update($user_info); // 更新用户信息
        }
        Log::write('getUserinfo ---- '.json_encode($user_info),'error');
        return $user_info;
    }



}