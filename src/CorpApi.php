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

abstract class Message{
	public $touser;
	public $toparty;
	public $totag;
	public $agentid;
	public $safe;
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

class News{
    public $articles;
    public function __construct($articles){
        $this->articles = $articles;
    }
}

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

class CorpApi{
	protected $http;
	
	public function __construct(HttpClient $hc){
		$this->http = $hc;
	}
	
	public function oauth2($backUrl){
		$redirectUri = array_key_exists('HTTPS', $_SERVER)?'https://':'http://';
		$redirectUri = urlencode($redirectUri.config('wechat_corp.app_host')."/corp/oauth2?back=$backUrl");
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

    /**
     * @param $departmentId
     * @param $fetchChild 1/0：是否递归获取子部门下面的成员
     * @param $status: 0获取全部成员，1获取已关注成员列表，2获取禁用成员列表，4获取未关注成员列表。
     * status可叠加，未填写则默认为4
     * @return mixed
     * 成功时,返回: "userlist": [
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
    public function listSimpleUsers($departmentId, $fetchChild, $status){
        $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/simplelist', [
            'access_token'  => $this->getAccessToken(),
            'department_id' => $departmentId,
            'fetch_child'   => $fetchChild,
            'status'        => $status,
        ]);
        $body = json_decode($body);
        if(property_exists($body, 'userlist')){
            return $body->userlist;
        }else{
            return $body;
        }
    }

    public function listDepartments($parentId=1){
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
     * @param $toUser 成员ID列表（消息接收者，多个接收者用‘|’分隔，最多支持1000个）。
     * 特殊情况：指定为@all，则向关注该企业应用的全部成员发送
     * @param $toParty 部门ID列表，多个接收者用‘|’分隔，最多支持100个。当touser为@all时忽略本参数
     * @param $toTag 标签ID列表，多个接收者用‘|’分隔。当touser为@all时忽略本参数
     * @param $agentId 企业应用的id，整型。可在应用的设置页面查看
     * @param $safe 表示是否是保密消息，0表示否，1表示是，默认0
     * @param $text 消息内容，最长不超过2048个字节，注意：主页型应用推送的文本消息在微信端最多只显示20个字（包含中英文）
     * @return
     */
	public function sendText($toUser, $toParty, $toTag, $agentId, $safe, $text){
        $at = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=$at";
		$msg = new TextMessage($toUser, $toParty, $toTag, $agentId, $safe, $text);
        $this->httpPost($url, json_encode($msg, JSON_UNESCAPED_UNICODE));
	}

    public function sendNews($toUser, $toParty, $toTag, $agentId, $safe, $articles){
        $at = $this->getAccessToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=$at";
        $msg = new NewsMessage($toUser, $toParty, $toTag, $agentId, $safe, $articles);
        $this->httpPost($url, json_encode($msg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

	protected function httpGet($url, Array $query){
		\Log::debug("WechatCorp get: ", [
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

    protected function httpPost($url, $body){
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
    }
}
