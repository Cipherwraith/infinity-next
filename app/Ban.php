<?php namespace App;

use App\Support\IP\CIDR as CIDR;
use App\Contracts\PermissionUser  as PermissionUser;
use Illuminate\Database\Eloquent\Model;
use Request;

class Ban extends Model {
	
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'bans';
	
	/**
	 * The primary key that is used by ::get()
	 *
	 * @var string
	 */
	protected $primaryKey = 'ban_id';
	
	/**
	 * Attributes which are automatically sent through a Carbon instance on load.
	 *
	 * @var array
	 */
	protected $dates = ['created_at', 'updated_at', 'expires_at'];
	
	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['ban_ip', 'board_uri', 'seen', 'created_at', 'updated_at', 'expires_at', 'mod_id', 'post_id', 'ban_reason_id', 'justification'];
	
	
	public function appeals()
	{
		return $this->hasMany('\App\BanAppeal', 'ban_id');
	}
	
	public function board()
	{
		return $this->belongsTo('\App\Board', 'board_uri');
	}
	
	public function mod()
	{
		return $this->belongsTo('\App\User', 'mod_id', 'user_id');
	}
	
	public function post()
	{
		return $this->belongsTo('\App\Post', 'post_id');
	}
	
	/**
	 * Determines if this ban can be appealed.
	 *
	 * @return boolean
	 */
	public function canAppeal()
	{
		return is_null($this->getAppeal());
	}
	
	/**
	 * Determines if a user can view this ban (as moderator or client).
	 *
	 * @param  PermissionUser  $user
	 * @return boolean
	 */
	public function canView(PermissionUser $user)
	{
		return $this->isBanForIP();
	}
	
	/**
	 * 
	 *
	 */
	public function getBanIpAttribute()
	{
		return new CIDR(inet_ntop($this->ban_ip_start), inet_ntop($this->ban_ip_end));
	}
	
	/**
	 * Fetches the last appeal for this IP on this ban.
	 *
	 * @param  string  $ip  Optional. Human-readable IP. Defaults to the request.
	 * @return \App\BanAppeal
	 */
	public function getAppeal($ip = null)
	{
		if (is_null($ip))
		{
			$ip = Request::ip();
		}
		
		$ip = inet_pton($ip);
		
		return $this->appeals->where('appeal_ip', $ip)->last();
	}
	
	/**
	 * Fetches the latest applicable ban.
	 *
	 * @param  string  $ip  Human-readable IP.
	 * @param  string|null|false  (Board|Global Only|Both)
	 * @return \App\Ban
	 */
	public static function getBan($ip, $board_uri = null)
	{
		return Ban::ipString($ip)
			->board($board_uri)
			->whereActive()
			->orderBy('board_uri', 'desc') // Prioritizes local over global bans.
			->take(1)
			->get()
			->last();
	}
	
	/**
	 * Fetches all applicable bans.
	 *
	 * @param  string  $ip  Human-readable IP.
	 * @param  string|null|false  $board_uri  Board|Global Only|Both
	 * @return Ban
	 */
	public static function getBans($ip, $board_uri = null)
	{
		return Ban::ipString($ip)
			->board($board_uri)
			->whereActive()
			->orderBy('board_uri', 'desc') // Prioritizes local over global bans.
			->with('mod')
			->get();
	}
	
	public function getRedirectUrl()
	{
		return "/cp/banned/" . ($this->isGlobal() ? "global" : "board/{$this->board_uri}") . "/{$this->ban_id}";
	}
	
	public function getAppealUrl()
	{
		if (!is_null($this->board_uri))
		{
			return "/cp/banned/board/{$this->board_uri}/{$this->ban_id}";
		}
		
		return "/cp/banned/global/{$this->ban_id}";
	}
	
	public static function isBanned($ip, $board = null)
	{
		$board_uri = null;
		
		if ($board instanceof Board)
		{
			$board_uri = $board->board_uri;
		}
		else if ($board != "")
		{
			$board_uri = $board;
		}
		
		return static::getBan($ip, $board_uri) ? true : false;
	}
	
	/**
	 * Determines if this ban applies to the requesting client.
	 *
	 * @param  string  Optional. IP to be checked. Defaults to request IP.
	 * @return boolean  If the IP is within he range of this ban.
	 */
	public function isBanForIP($ip = null)
	{
		if (is_null($ip))
		{
			$ip = Request::ip();
		}
		
		return CIDR::cidr_intersect($ip, $this->ban_ip);
	}
	
	public function isExpired()
	{
		return !is_null($this->expires_at) && $this->expires_at->isPast();
	}
	
	/**
	 * Returns if this ban applies to all boards.
	 *
	 * @return boolean
	 */
	public function isGlobal()
	{
		return is_null($this->board_uri);
	}
	
	public function scopeBoard($query, $board_uri = null)
	{
		if ($board_uri === false)
		{
			return $query;
		}
		else if (is_null($board_uri))
		{
			return $query->whereNull('board_uri');
		}
		
		return $query
			->where(function($query) use ($board_uri) {
				$query
					->where('board_uri', '=', $board_uri)
					->orWhereNull('board_uri');
			});
	}
	
	public function scopeIpString($query, $ip)
	{
		return $query->ipBinary(inet_pton($ip));
	}
	
	public function scopeIpBinary($query, $ip)
	{
		return $query->where(function($query) use ($ip) {
				$query->where('ban_ip_start', '<=', $ip);
				$query->where('ban_ip_end',   '>=', $ip);
			});
	}
	
	public function scopeWhereActive($query)
	{
		return $query
			->where(function($query) {
				$query->whereCurrent();
				$query->orWhere('seen', false);
			})
			->whereUnappealed();
	}
	
	public function scopeWhereAppealed($query)
	{
		return $query->whereHas('appeals', function($query)
		{
			$query->where('approved', true);
		});
	}
	
	public function scopeWhereCurrent($query)
	{
		return $query->where('expires_at', '>', $this->freshTimestamp());
	}
	
	public function scopeWhereUnappealed($query)
	{
		return $query->whereDoesntHave('appeals', function($query)
		{
			$query->where('approved', true);
		});
	}
	
	public function willExpire()
	{
		return !is_null($this->expires_at);
	}
	
}
