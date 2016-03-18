<?php
Route::group(['middleware' => ['web']], function () {
	//
	Route::get('corp/msg', 'hulucat\wechat-corp\CorpController@msg');
	Route::get('corp/oauth2', 'hulucat\wechat-corp\CorpController@oauth2');
});