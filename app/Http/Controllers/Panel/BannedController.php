<?php namespace App\Http\Controllers\Panel;

use App\Ban;
use App\BanAppeal;
use App\Board;
use App\Http\Controllers\Panel\PanelController;

use Input;
use Request;
use Validator;

class BannedController extends PanelController {
	
	/*
	|--------------------------------------------------------------------------
	| Banned Controller
	|--------------------------------------------------------------------------
	|
	| This controller will list active bans applied to your IP addresses and
	| allow you to appeal them and review the status of your appeal.
	|
	*/
	
	const VIEW_BANNED = "panel.banned";
	const VIEW_BAN    = "panel.ban";
	
	/**
	 * View path for the secondary (sidebar) navigation.
	 *
	 * @var string
	 */
	public static $navSecondary = "nav.panel.home";
	
	
	public function getIndex(Board $board)
	{
		$bans = Ban::getBans(Request::ip(), false);
		
		return $this->view(static::VIEW_BANNED, [
			'bans' => $bans,
		]);
	}
	
	public function getBan(Board $board, Ban $ban)
	{
		if (!$ban->canView($this->user))
		{
			return abort(403);
		}
		
		$seeing = false;
		
		if (!$ban->seen)
		{
			$ban->seen = true;
			$ban->save();
			$seeing = true;
		}
		
		$ban->setRelation('board', $board);
		
		return $this->view(static::VIEW_BAN, [
			'board'  => $board,
			'ban'    => $ban,
			'seeing' => $seeing,
		]);
	}
	
	public function putAppeal(Board $board, Ban $ban)
	{
		if (!$ban->canAppeal() || !$ban->isBanForIP())
		{
			return abort(403);
		}
		
		$input     = Input::all();
		$validator = Validator::make($input, [
			'appeal_text' => [
				"string",
				"between:0,2048",
			],
		]);
		
		if (!$validator->passes())
		{
			return redirect()
				->back()
				->withErrors($validator->errors());
		}
		
		$appeal = $ban->appeals()->create([
			'appeal_ip'   => inet_pton(Request::ip()),
			'appeal_text' => $input['appeal_text'],
		]);
		$ban->setRelation('appeals', $ban->appeals->push($appeal));
		
		return $this->getBan($board, $ban);
	}
	
}