<?php
namespace Hulucat\WechatCorp;

use GuzzleHttp\Client as HttpClient;
use Cache;
use Log;

class Article{
    public $title;
    public $description;
    public $url;
    public $picurl;

    public function __construct($title, $description, $url, $picurl){
        $this->title = $title;
        $this->description = $description;
        $this->url = $url;
        $this->picurl = $picurl;
    }
}

class JsapiConfig{
	public $nonce;
	public $ticket;
	public $timestamp;	
	public $signature;
	public function __construct(){
		$this->nonce = str_random(16);
		$this->timestamp = time();
	}
}

abstract class Message{
	public $touser;
	public $toparty;
	public $totag;
	public $agentid;
	public $safe;
}

class News{
    public $articles;
    public function __construct($articles){
        $this->articles = $articles;
    }
}

class NewsMessage extends Message{
    public $news = '';
    public $msgtype = 'news';

    public function __construct($toUser, $toParty, $toTag, $agentId, $safe, $articles){
        $this->touser = $toUser;
        $this->toparty = $toParty;
        $this->totag = $toTag;
        $this->agentid = $agentId;
        $this->safe = $safe;
        $this->news = new News($articles);
    }   
}

class Text{
    public $content;

    public function __construct($content){
        $this->content = $content;
    }
}

class TextMessage extends Message{
	public $text = '';
    public $msgtype = 'text';

	public function __construct($toUser, $toParty, $toTag, $agentId, $safe, $textContent){
		$this->touser = $toUser;
		$this->toparty = $toParty;
		$this->totag = $toTag;
		$this->agentid = $agentId;
		$this->safe = $safe;
		$this->text = new Text($textContent);
	}
}

class CorpApi
{
	protected $http;
	
	public function __construct(HttpClient $hc)
    {
		$this->http = $hc;
	}

    public function getAccessToken()
    {
        $cacheKey = 'wechat_corp_access_token';
        $at = \Cache::get($cacheKey);
        if(!$at){
            $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
                    'corpid'=>config('wechat_corp.id'),
                    'corpsecret'=>config('wechat_corp.secret')
            ]);
            $rt = json_decode($body);
            if(property_exists($rt, 'access_token')){
                $at = $rt->access_token;
                \Cache::put($cacheKey, $at, 110);
            }else{
                $at = null;
            }   
        }
        return $at;
    }

    /**
    * 将企业号的userid转成openid
    * @param $userId 企业号内的成员id，必填
    * @param $agentId 整型，需要发送红包的应用ID，若只是使用微信支付和企业转账，则无需该参数
    * @return array['openid': 'abc', 'appid':'123']，如未传agentId，则无appid，错误时返回null
    */
    public function convertToOpenid($userId, $agentId=null)
    {
        $accessToken = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/user/convert_to_openid?access_token=$accessToken";
        $content = null;
        if($agentId==null){
            $content = "{\"userid\": \"$userId\"}";
        }else{
            $content = "{\"userid\": \"$userId\", \"agentid\":\"$agentId\"}";
        }
        $body = $this->httpPost($url, $content);
        $data = json_decode($body);
        $rt = [];
        if(property_exists($data, 'openid') && $data->openid){
            $rt['openid']   = $data->openid;
        }else{
            return null;
        }
        if(property_exists($data, 'appid')){
            $rt['appid']    = $data->appid;
        }
        return $rt;
    }

    /**
    * 该接口主要应用于使用微信支付、微信红包和企业转账之后的结果查询，开发者需要知道某个结果事件的openid对应企业号内成员的信息时，可以通过调用该接口进行转换查询。
    * @param $openId 在使用微信支付、微信红包和企业转账之后，返回结果的openid
    * @return $userId 该openid在企业号中对应的成员userid
    */
    public function convertoToUserId($openId)
    {
        $accessToken = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/user/convert_to_userid?access_token=$accessToken";
        $body = $this->httpPost($url, "{\"openid\": \"$openId\"}");
        $data = json_decode($body);
        if(property_exists($data, 'userid') && $data->userid){
            return $data->userid;
        }else{
            return null;
        }
    }

    public function getJsapiConfig($url)
    {
        $rt = new JsapiConfig();
        $rt->ticket = $this->getJsapiTicket();
        $rt->signature = sha1(
            "jsapi_ticket={$rt->ticket}&noncestr={$rt->nonce}&timestamp={$rt->timestamp}&url=$url"
        );
        return $rt;
    }

    public function getJsapiTicket()
    {
        $cacheKey = 'wechat_corp_jsapi_ticket';
        $ticket = Cache::get($cacheKey);
        $at = $this->getAccessToken();
        if($ticket){
            //检查这个ticket是不是应该过期了
            $oldAccessToken = Cache::get('wechat_corp_'.md5($ticket));
            if($oldAccessToken==$at){//如果access token没有更新，那么缓存中的ticket就可以用
                return $ticket;
            }
        }
        $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/get_jsapi_ticket', [
            'access_token' => $at
        ]);
        $rt = json_decode($body);
        if(property_exists($rt, 'ticket')){
            $ticket = $rt->ticket;
            Cache::put($cacheKey, $ticket, 110);
            Cache::put('wechat_corp_'.md5($ticket), $at, 110);
        }else{
            $ticket = null;
        }
        return $ticket;
    }    

    /** 根据oauth2的code换取用户id
     * @param $code
     * @return null
     */
    public function getUserId($code)
    {
		$body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/getuserinfo', [
			'access_token' => $this->getAccessToken(),
			'code' => $code,
		]);
		$rt = json_decode($body);
		if(property_exists($rt, 'UserId')){
			return $rt->UserId;
		}else{
			return null;
		}
	}

    /**根据userId获取用户信息
    {
        "errcode":0,
        "errmsg":"ok",
        "userid":"xxx",
        "name":"xxx",
        "department":[2],
        "mobile":"186xxxxxxxx",
        "gender":"1",
        "avatar":"http://xxx",
        "status":1,
        "extattr":{"attrs":[]}
     * @param $userId
     */
    public function getUser($userId, $refresh=false)
    {
        $user = null;
        $cacheKey = "wechat_corp_user_$userId";
        if($refresh){
            $user = $this->realGetUser($userId);
        }else{
            $user = Cache::get($cacheKey);
            if(!$user){
                $user = $this->realGetUser($userId);
            }
        }
        return $user;
    }

    /**取得企业用户的某个扩展属性值
     * @param $userId
     * @param $attrName
     * @return null
     */
    public function getUserExtAttr($userId, $attrName)
    {
        $user = $this->getUser($userId);
        if(property_exists($user, 'extattr') && property_exists($user->extattr, 'attrs')){
            foreach ($user->extattr->attrs as $i=>$attr){
                if($attr->name==$attrName){
                    return $attr->value;
                }
            }
        }
        return null;
    }

    private function realGetUser($userId)
    {
        $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/get', [
            'access_token' => $this->getAccessToken(),
            'userid' => $userId,
        ]);
        $user = json_decode($body);
        if($user->errcode==0){
            $cacheKey = "wechat_corp_user_$userId";
            Cache::put($cacheKey, $user, 60);
        }
        Log::debug("Real get user: ", [
            'userId'=>$userId,
            "user"=>$user,
        ]);
        return $user;
    }

    /**判断某个企业用户是否在某个部门中
     * @param $userId
     * @param $department department id, 1, 2
     * @return boolean
     */
    public function isInDepartment($userId, $department)
    {
        $user = $this->getUser($userId);
        if(!$user){
            return false;
        }
        return in_array($department, $user->department);
    }

    public function listDepartments($parentId=1)
    {
        $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/department/list', [
            'access_token'  => $this->getAccessToken(),
            'id' => $parentId,
        ]);
        $body = json_decode($body);
        if(property_exists($body, 'department')){
            return $body->department;
        }else{
            return $body;
        }
    }

    /**
     * @param $departmentId
     * @param $fetchChild 1/0：是否递归获取子部门下面的成员
     * @param $status: 0获取全部成员，1获取已关注成员列表，2获取禁用成员列表，4获取未关注成员列表。
     * status可叠加
     * @return mixed
     * 成功时,返回数组: [
    {
    "userid": "zhangsan",
    "name": "李四",
    "department": [1, 2]
    }
    ]
     * 失败时,返回: {
    "errcode": 404,
    "errmsg": "description",
     }
     */
    public function listSimpleUsers($departmentId, $fetchChild, $status=0)
    {
        $cacheKey = "SIMPLE_USER_LIST_{$departmentId}_{$fetchChild}_{$status}";
        $str = Cache::get($cacheKey);
        if($str){
            Log::debug("Load simple users from cache");
            return json_decode($str);
        }else{
            $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/simplelist', [
                'access_token'  => $this->getAccessToken(),
                'department_id' => $departmentId,
                'fetch_child'   => $fetchChild,
                'status'        => $status,
            ]);
            $body = json_decode($body);
            if(property_exists($body, 'userlist')){
                Cache::put($cacheKey, json_encode($body->userlist), 60);
                return $body->userlist;
            }else{
                return $body;
            }            
        }
    }

    /**
    * 列出同在一个部门，或者在下一级部门的用户
    * 如果当前用户属于部门2，则认为是管理员，会列出所有用户
    */
    public function listUsersInSameDepartment($userId)
    {
        $currentUser = $this->getUser($userId);
        $users = [];
        foreach ($currentUser->department as $depart) {
            if($depart==2){
                return $this->listSimpleUsers(1, true, 0);
            }
        }
        foreach ($currentUser->department as $depart) {
            $departUsers = $this->listSimpleUsers($depart, true, 0);
            $users = array_merge($users, $departUsers);
        }
        $dict = [];
        foreach ($users as $i => $user) {
            $dict[$user->userid] = $user;
        }
        return array_values($dict);
    }

    public function oauth2($backUrl)
    {
        $redirectUri = array_key_exists('HTTPS', $_SERVER)?'https://':'http://';
        $redirectUri = urlencode($redirectUri.config('wechat_corp.app_host')."/corp/oauth2?back=$backUrl");
        $url='https://open.weixin.qq.com/connect/oauth2/authorize?appid=';
        $url .= config('wechat_corp.id');
        $url .= '&redirect_uri=';
        $url .= $redirectUri;
        $url .= '&response_type=code&scope=snsapi_base#wechat_redirect';
        header("Location: $url", true, 302);
    }

    /**
    * 发送图文消息
    */
    public function sendNews($toUser, $toParty, $toTag, $agentId, $safe, $articles)
    {
        $at = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=$at";
        $msg = new NewsMessage($toUser, $toParty, $toTag, $agentId, $safe, $articles);
        $this->httpPost($url, json_encode($msg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param $toUser 成员ID列表（消息接收者，多个接收者用‘|’分隔，最多支持1000个）。
     * 特殊情况：指定为@all，则向关注该企业应用的全部成员发送
     * @param $toParty 部门ID列表，多个接收者用‘|’分隔，最多支持100个。当touser为@all时忽略本参数
     * @param $toTag 标签ID列表，多个接收者用‘|’分隔。当touser为@all时忽略本参数
     * @param $agentId 企业应用的id，整型。可在应用的设置页面查看
     * @param $safe 表示是否是保密消息，0表示否，1表示是，默认0
     * @param $text 消息内容，最长不超过2048个字节，注意：主页型应用推送的文本消息在微信端最多只显示20个字（包含中英文）
     * @return
     */
	public function sendText($toUser, $toParty, $toTag, $agentId, $safe, $text)
    {
        $at = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=$at";
		$msg = new TextMessage($toUser, $toParty, $toTag, $agentId, $safe, $text);
        $this->httpPost($url, json_encode($msg, JSON_UNESCAPED_UNICODE));
	}

    /**
    * 企业付款
    * @param $params, array，包含以下字段：
    *   device_info 微信支付分配的终端设备号，非必填
    *   partner_trade_no 商户订单号
    *   openid 商户appid下，某用户的openid
    *   check_name 
    *       NO_CHECK：不校验真实姓名 
            FORCE_CHECK：强校验真实姓名（未实名认证的用户会校验失败，无法转账） 
            OPTION_CHECK：针对已实名认证的用户才校验真实姓名（未实名认证用户不校验，可以转账成功）
    *   re_user_name 收款用户真实姓名。如果check_name设置为FORCE_CHECK或OPTION_CHECK，则必填用户真实姓名
    *   amount 企业付款金额，单位为分
    *   desc 企业付款操作说明信息。必填。
    * @return 付款结果，参考微信文档付款结果 https://pay.weixin.qq.com/wiki/doc/api/tools/mch_pay.php?chapter=14_2
    */
    public function transfer($params)
    {
        $url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers";
        //sign
        $params['mch_appid'] = config('wechat_corp.mch_app_id');
        $params['mchid'] = config('wechat_corp.mch_id');
        $params['nonce_str'] = $this->getNonceStr();
        $params['spbill_create_ip'] = $SERVER['SERVER_ADDR'];
        $params['sign'] = $this->sign($params);
        $xml = $this->toXml($params);
        $result = $this->fromXml($this->postXml($xml, $url, true));
        Log::debug("WechatCorp transfer result: ".json_encode($result));
        return $result;
    }

	protected function httpGet($url, Array $query)
    {
		Log::debug("WechatCorp get: ", [
			'Request: ' => $url,
			'Params: ' => $query,
		]);
		$response = $this->http->request('GET', $url, ['query' => $query]);
		Log::debug('WechatCorp:', [
				'Status' => $response->getStatusCode(),
				'Reason' => $response->getReasonPhrase(),
				'Headers' => $response->getHeaders(),
				'Body' => strval($response->getBody()),
		]);
		return $response->getBody();
	}

    protected function httpPost($url, $body)
    {
        Log::debug("WechatCorp post: ", [
            'Request: ' => $url,
            'body: ' => $body,
        ]);
        $response = $this->http->request('POST', $url, [
            'body'  => $body
        ]);
        Log::debug('WechatCorp:', [
            'Status' => $response->getStatusCode(),
            'Reason' => $response->getReasonPhrase(),
            'Headers' => $response->getHeaders(),
            'Body' => strval($response->getBody()),
        ]);
        return $response->getBody();
    }

    private function postXml($xml, $url, $useCert=false, $timeout=30)
    {
        Log::debug("WechatCorp post xml to $url: \n".$xml);
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,TRUE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);//严格校验
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if($useCert == true){
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch,CURLOPT_SSLCERTTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLCERT, config('wechat_corp.mch_sslcert'));
            curl_setopt($ch,CURLOPT_SSLKEYTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLKEY, config('wechat_corp.mch_sslkey'));
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if($data){
            curl_close($ch);
            Log::debug("WechatCorp post xml result: \n".$data);
            return $data;
        } else {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            Log::error("Error post xml", [
                'url'   => $url,
                'xml'   => $xml,
                'errno' => $errno,
                'error' => $error
            ]);
            return null;
        }
    }    

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    private function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str ="";
        for ( $i = 0; $i < $length; $i++ )  {
            $str .= substr($chars, mt_rand(0, strlen($chars)-1), 1);
        }
        return $str;
    }

    /** 产生签名
     * @param $params
     * @param $key
     * @return mixed
     */
    private function sign($params){
        $dict = array();
        foreach ($params as $key=>$value){
            if($value!=null && $value!=''){
                $dict[$key] = $value;
            }
        }
        ksort($dict);
        $dict['key'] = config('wechat_corp.mch_payment_key');
        $str = urldecode(http_build_query($dict));
        return strtoupper(md5($str));
    }

    private function fromXml($xml){
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    private function toXml($dict)
    {
        $xml = '<xml>';
        foreach ($dict as $key => $val) {
            if (is_numeric($val)){
                $xml .= "<{$key}>{$val}</{$key}>";
            }else{
                $xml .= "<{$key}><![CDATA[{$val}]]></{$key}>";
            }
        }
        $xml .= '</xml>';
        return $xml;
    }    
}
