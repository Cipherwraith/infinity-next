<?php namespace App;

use App\Board;

use Illuminate\Database\Eloquent\Model;

use Request;

class BoardAdventure extends Model {
	
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'board_adventures';
	
	/**
	 * The database primary key.
	 *
	 * @var string
	 */
	protected $primaryKey = 'adventure_id';
	
	/**
	 * Attributes which are automatically sent through a Carbon instance on load.
	 *
	 * @var array
	 */
	protected $dates = ['created_at', 'updated_at', 'expires_at', 'expended_at'];
	
	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['adventurer_ip', 'board_uri', 'expires_at'];
	
	
	public function board()
	{
		return $this->belongsTo('\App\Board', 'board_uri');
	}
	
	
	public function scopeWhereBoard($query, Board $board)
	{
		return $query->where('board_uri', $board->board_uri);
	}
	
	public function scopeWhereBelongsToClient($query)
	{
		return $query->where('adventurer_ip', inet_pton(Request::ip()));
	}
	
	public function scopeWhereFresh($query)
	{
		return $query->where('expires_at', '>=', $this->freshTimestamp())
			->whereNull('expended_at');
	}
	
	public function scopeWhereExpended($query)
	{
		return $query->where('expended_at', '>', 0);
	}
	
	public function scopeWhereExpired($query)
	{
		return $query->where('expires_at', '<', $this->freshTimestamp());
	}
	
	
	public static function getAdventure(Board $board)
	{
		$adventures = static::whereFresh()
			->whereBoard($board)
			->whereBelongsToClient()
			->get();
		
		if (count($adventures))
		{
			return $adventures->first();
		}
		
		return null;
	}
}
