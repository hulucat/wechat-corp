<?php
namespace Hulucat\WechatCorp;

use GuzzleHttp\Client as HttpClient;

class CorpApi{
	protected $http;
	
	public function __construct(HttpClient $hc){
		$this->http = $hc;
	}
	
	public function GetAccessToken(){
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
