<?php

namespace Hulucat\WechatCorp;

use Illuminate\Support\ServiceProvider;

class CorpServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //publish config
        $this->publishes([
        		__DIR__.'/config/wechat_corp.php' => config_path('wechat_corp.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
        include __DIR__.'/routes.php';
        $this->app->make('Hulucat\WechatCorp\CorpController');
        $this->app->singleton('CorpApi', function($app){
        	return new Hulucat\WechatCorp\CorpApi();
        });
    }
}
