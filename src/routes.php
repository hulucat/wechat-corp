<?php
Route::group(['middleware' => ['web']], function () {

	//消息回调
	Route::get('corp/msg', 'Hulucat\WechatCorp\CorpController@msg');
	Route::get('corp/oauth2', 'Hulucat\WechatCorp\CorpController@oauth2');
});