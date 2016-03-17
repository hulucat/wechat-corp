<?php
namespace Hulucat\WechatCorp;

use GuzzleHttp\Client as HttpClient;

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
	
	public function getAccessToken(){
		$cacheKey = 'wechat_corp_access_token';
		$at = \Cache::get($cacheKey);
		if(!$at){
			$body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
					'corpid'=>config('wechat_corp.wechat_corp_id'),
					'corpsecret'=>config('wechat_corp.wechat_corp_secret')
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
