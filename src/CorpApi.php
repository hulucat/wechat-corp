<?php
namespace Hulucat\WechatCorp;

use GuzzleHttp\Client as HttpClient;
use Cache;
use Log;

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

class CorpApi{
	protected $http;
	
	public function __construct(HttpClient $hc){
		$this->http = $hc;
	}
	
	public function oauth2($backUrl){
		$redirectUri = array_key_exists('HTTPS', $_SERVER)?'https://':'http://';
		$redirectUri = urlencode($redirectUri.config('wechat_corp.app_host').'/corp/oauth2?back='.$backUrl);
		$url='https://open.weixin.qq.com/connect/oauth2/authorize?appid=';
		$url .= config('wechat_corp.id');
		$url .= '&redirect_uri=';
		$url .= $redirectUri;
		$url .= '&response_type=code&scope=snsapi_base#wechat_redirect';
		header("Location: $url", true, 302);
	}

    /** 根据oauth2的code换取用户id
     * @param $code
     * @return null
     */
    public function getUserId($code){
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
     * @param $userId
     */
    public function getUser($userId, $refresh=false){
        $user = null;
        $cacheKey = "wechat_corp_user_$userId";
        if($refresh){
            $user = $this->realGetUser($userId);
        }else{
            $user = Cache::get($cacheKey);
            if(!$user){
                $user = $this->realGetUser($userId);
            }else{
                Log::debug("Get corp user from cache", [
                    'userId'    => $userId,
                    'user'      => $user,
                ]);
            }
        }
        return $user;
    }

    private function realGetUser($userId){
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
    public function isInDepartment($userId, $department){
        $user = $this->getUser($userId);
        if(!$user){
            return false;
        }
        return in_array($department, $user->department);
    }

    /**取得企业用户的某个扩展属性值
     * @param $userId
     * @param $attrName
     * @return null
     */
    public function getUserExtAttr($userId, $attrName){
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

	public function getAccessToken(){
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

	public function getJsapiTicket(){
		$cacheKey = 'wechat_corp_jsapi_ticket';
		$ticket = \Cache::get($cacheKey);
		$at = $this->getAccessToken();
		if($ticket){
			//检查这个ticket是不是应该过期了
			$oldAccessToken = \Cache::get('wechat_corp_'.md5($ticket));
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
			\Cache::put($cacheKey, $ticket, 110);
		}else{
			$ticket = null;
		}
		return $ticket;
	}

	public function getJsapiConfig($url){
		$rt = new JsapiConfig();
		$rt->ticket = $this->getJsapiTicket();
		$rt->signature = sha1("jsapi_ticket={$rt->ticket}&noncestr={$rt->nonce}&timestamp={$rt->timestamp}&url=$url");
		return $rt;
	}
	
	protected function httpGet($url, Array $query){
		\Log::debug("WechatCorp: ", [
			'Request: ' => $url,
			'Params: ' => $query,
		]);
		$response = $this->http->request('GET', $url, ['query' => $query]);
		\Log::debug('WechatCorp:', [
				'Status' => $response->getStatusCode(),
				'Reason' => $response->getReasonPhrase(),
				'Headers' => $response->getHeaders(),
				'Body' => strval($response->getBody()),
		]);
		return $response->getBody();
	}
}
