<?php
namespace Hulucat\WechatCorp;

use App\Http\Controllers\Controller;
use Hulucat\WechatCorp\CorpApi;
use Illuminate\Http\Request;

class CorpController extends Controller
{
	public function msg()
	{
		echo "1";
	}

	public function oauth2(Request $request, CorpApi $corp){
		$code = $request->input('code');
		$back = $request->input('back');
		$uid = $corp->getUserId($code);
		$request->session()->set('corp_uid', $uid);
		header("Location: $back", true, 302);
	}
}