<?php namespace App;

use Illuminate\Database\Eloquent\Model;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use File;
use Input;
use Settings;
use Sleuth;
use Storage;

class FileStorage extends Model {
	
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'files';
	
	/**
	 * The database primary key.
	 *
	 * @var string
	 */
	protected $primaryKey = 'file_id';
	
	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['hash', 'banned', 'filesize', 'mime', 'meta', 'first_uploaded_at', 'last_uploaded_at', 'upload_count', 'has_thumbnail'];
	
	/**
	 * Determines if Laravel should set created_at and updated_at timestamps.
	 *
	 * @var array
	 */
	public $timestamps = false;
	
	
	/**
	 * The \App\FileAttachment relationship.
	 * Represents a post -> storage relationship.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function attachments()
	{
		return $this->hasMany('\App\FileAttachment', 'file_id');
	}
	
	/**
	 * The \App\BoardAsset relationship.
	 * Used for multiple custom facets of a board.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function assets()
	{
		return $this->hasMany('\App\BoardAsset', 'file_id');
	}
	
	/**
	 * The \App\Posts relationship.
	 * Uses the attachments() relationship to find posts where this file is attached..
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function posts()
	{
		return $this->belongsToMany("\App\Post", 'file_attachments', 'file_id', 'post_id')->withPivot('filename', 'position');
	}
	
	
	/**
	 * Will trigger a file deletion if the storage item is not used anywhere.
	 *
	 * @return boolean
	 */
	public function challengeExistence()
	{
		$count = $this->assets->count() + $this->attachments->count();
		
		if ($count === 0)
		{
			$this->deleteFile();
			return false;
		}
		
		return true;
	}
	
	/**
	 * Creates a new FileAttachment for a post using a direct upload.
	 *
	 * @param  UploadedFile  $file
	 * @param  Post  $post
	 * @return FileAttachment
	 */
	public static function createAttachmentFromUpload(UploadedFile $file, Post $post, $autosave = true)
	{
		$storage     = static::storeUpload($file);
		
		$uploadName  = urlencode($file->getClientOriginalName());
		$uploadExt   = pathinfo($uploadName, PATHINFO_EXTENSION);
		
		$fileName    = basename($uploadName, "." . $uploadExt);
		$fileExt     = $storage->guessExtension();
		
		$attachment  = new FileAttachment();
		$attachment->post_id    = $post->post_id;
		$attachment->file_id    = $storage->file_id;
		$attachment->filename   = urlencode("{$fileName}.{$fileExt}");
		$attachment->is_spoiler = !!Input::get('spoilers');
		
		if ($autosave)
		{
			$attachment->save();
			
			$storage->upload_count++;
			$storage->save();
		}
		
		return $attachment;
	}
	
	/**
	 * Creates a new FileAttachment for a post using a hash.
	 *
	 * @param  Post  $post
	 * @param  string  $filename
	 * @param  boolean  $spoiler
	 * @return FileAttachment
	 */
	public function createAttachmentWithThis(Post $post, $filename, $spoiler = false, $autosave = true)
	{
		$fileName    = pathinfo($filename, PATHINFO_FILENAME);
		$fileExt     = $this->guessExtension();
		
		$attachment  = new FileAttachment();
		$attachment->post_id    = $post->post_id;
		$attachment->file_id    = $this->file_id;
		$attachment->filename   = urlencode("{$fileName}.{$fileExt}");
		$attachment->is_spoiler = !!$spoiler;
		
		if ($autosave)
		{
			$attachment->save();
			
			$this->upload_count++;
			$this->save();
		}
		
		return $attachment;
	}
	
	/**
	 * Removes the associated file for this storage.
	 *
	 * @return boolean  Success. Will return FALSE if the file was already gone.
	 */
	public function deleteFile()
	{
		return unlink($this->getFullPath()) && unlink($this->getFullPathThumb());
	}
	
	/**
	 * Returns the storage's file as a filesystem.
	 *
	 * @return \Illuminate\Filesystem\Filesystem
	 */
	public function getAsFile()
	{
		return new File($this->getFullPath());
	}
	
	/**
	 * Returns the storage's thumbnail as a filesystem.
	 *
	 * @return \Illuminate\Filesystem\Filesystem
	 */
	public function getAsFileThumb()
	{
		return new File($this->getFullPathThumb());
	}
	
	/**
	 * Returns the storage directory, minus the file name.
	 *
	 * @return string
	 */
	public function getDirectory()
	{
		$prefix = $this->getHashPrefix($this->hash);
		
		return "attachments/full/{$prefix}";
	}
	
	/**
	 * Returns the thumbnail's storage directory, minus the file name.
	 *
	 * @return string
	 */
	public function getDirectoryThumb()
	{
		$prefix = $this->getHashPrefix($this->hash);
		
		return "attachments/thumb/{$prefix}";
	}
	
	/**
	 * Supplies a clean URL for downloading an attachment on a board.
	 *
	 * @param  App\Board  $board
	 * @return string
	 */
	public function getDownloadURL(Board $board)
	{
		return url("/{$board->board_uri}/file/{$this->hash}/{$this->getFileName()}.{$this->guessExtension()}");
	}
	
	/**
	 * Determines and returns the "xxx" of "/url/xxx.ext" for URLs.
	 *
	 * @param  string|null  $format  Optional. The token syntax for the filename. Defaults to site setting.
	 * @return string
	 */
	public function getFileName($nameFormat = null)
	{
		if (is_null($nameFormat))
		{
			// Build a thumbnail using the admin settings.
			$nameFormat = Settings::get('attachmentName');
		}
		
		$first_uploade_at = new \Carbon\Carbon($this->first_uploaded_at);
		
		$bits['t'] = $first_uploade_at->timestamp;
		$bits['i'] = 0;
		$bits['n'] = $bits['t'];
		
		if (isset($this->pivot))
		{
			if (isset($this->pivot->filename))
			{
				$bits['n'] = $this->pivot->filename;
			}
			
			if (isset($this->pivot->position))
			{
				$bits['i'] = $this->pivot->position;
			}
		}
		
		$attachmentName = $nameFormat;
		
		foreach ($bits as $bitKey => $bitVal)
		{
			$attachmentName = str_replace("%{$bitKey}", $bitVal, $attachmentName);
		}
		
		return $attachmentName;
	}
	
	/**
	 * Returns the full internal file path.
	 *
	 * @return string
	 */
	public function getFullPath()
	{
		return storage_path() . "/app/" . $this->getPath();
	}
	
	/**
	 * Returns the full internal file path for the thumbnail.
	 *
	 * @return string
	 */
	public function getFullPathThumb()
	{
		return storage_path() . "/app/" . $this->getPathThumb();
	}
	
	/**
	 * Fetch an instance of static using the checksum.
	 *
	 * @param  $hash  Checksum
	 * @return static|null
	 */
	public static function getHash($hash)
	{
		return static::hash($hash)->get()->first();
	}
	
	/**
	 * Returns the skip file directoy prefix
	 *
	 * @param  $hash  Checksum
	 * @return static  Like "a/a/a/a"
	 */
	public static function getHashPrefix($hash)
	{
		return implode(str_split(substr($hash, 0, 4)), "/");
	}
	
	/**
	 * Converts the byte size to a human-readable filesize.
	 *
	 * @author Jeffrey Sambells
	 * @param  int  $decimals
	 * @return string
	 */
	public function getHumanFilesize($decimals = 2)
	{
		$bytes  = $this->filesize;
		$size   = array('B', 'kiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB');
		$factor = floor((strlen($bytes) - 1) / 3);
		
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}
	
	/**
	 * Returns the relative internal file path.
	 *
	 * @return string
	 */
	public function getPath()
	{
		return $this->getDirectory() . "/" . $this->hash;
	}
	
	/**
	 * Returns the full internal file path for the thumbnail.
	 *
	 * @return string
	 */
	public function getPathThumb()
	{
		return $this->getDirectoryThumb() . "/" . $this->hash;
	}
	
	/**
	 * Returns an XML valid attachment HTML string that handles missing thumbnail URLs.
	 *
	 * @return string as HTML
	 */
	public function getThumbnailHTML(Board $board)
	{
		$ext   = $this->guessExtension();
		$mime  = $this->mime;
		$url   = asset("static/img/filetypes/{$ext}.svg");
		$type  = "other";
		$html  = "";
		$stock = true;
		$spoil = $this->isSpoiler();
		
		if ($spoil)
		{
			$url = $board->getAssetUrl('file_spoiler');
		}
		else if ($this->isVideo())
		{
			if ($this->hasThumb())
			{
				$stock = false;
				$url   = $this->getThumbnailURL($board);
				$type  = "video";
			}
		}
		else if ($this->isAudio())
		{
			$stock = false;
			$type  = "audio";
			$url   = $this->getThumbnailURL($board);
		}
		else if ($this->isImage())
		{
			if ($this->hasThumb())
			{
				$stock = false;
				$url   = $this->getThumbnailURL($board);
				$type  = "img";
			}
		}
		else if ($this->isImageVector())
		{
			$stock = false;
			$url   = $this->getDownloadURL($board);
			$type  = "img";
		}
		
		$classes = [];
		$classes['type']  = "attachment-type-{$type}";
		$classes['ext']   = "attachent-ext-{$ext}";
		$classes['stock'] = $stock ? "thumbnail-stock" : "thumbnail-content";
		$classes['spoil'] = $spoil ? "thumbnail-spoiler" : "thumbnail-not-spoiler";
		$classHTML = implode(" ", $classes);
		
		return "<div class=\"attachment-wrapper {$classHTML}\"><img class=\"attachment-img {$classHTML}\" src=\"{$url}\" data-mime=\"{$mime}\" /></div>";
	}
	
	/**
	 * Supplies a clean thumbnail URL for embedding an attachment on a board.
	 *
	 * @param  App\Board  $board
	 * @return string
	 */
	public function getThumbnailURL(Board $board)
	{
		$baseURL = "/{$board->board_uri}/file/thumb/{$this->hash}/";
		$ext     = $this->guessExtension();
		
		if ($this->isSpoiler())
		{
			return $board->getSpoilerUrl();
		}
		
		if ($this->isImage())
		{
			$ext = Settings::get('attachmentThumbnailJpeg') ? "jpg" : "png";
		}
		else if ($this->isVideo())
		{
			$ext = "jpg";
		}
		else if ($this->isAudio())
		{
			if (!$this->hasThumb())
			{
				return $board->getAudioArtURL();
			}
			
			$ext = "png";
		}
		else if ($this->isImageVector())
		{
			// With the SVG filetype, we do not generate a thumbnail, so just serve the actual SVG.
			$baseURL ="/{$board->board_uri}/file/{$this->hash}/";
		}
		
		return url("{$baseURL}{$this->getFileName()}.{$ext}");
	}
	
	/**
	 * A dumb way to guess the file type based on the mime
	 * 
	 * @return string
	 */
	
	public function guessExtension()
	{
		$mimes = explode("/", $this->mime);
		
		switch ($this->mime)
		{
			##
			# IMAGES
			##
			case "image/svg+xml" :
				return "svg";
			
			case "image/jpeg" :
			case "image/jpg" :
				return "jpg";
			
			case "image/gif" :
				return "gif";
			
			case "image/png" :
				return "png";
			
			##
			# DOCUMENTS
			##
			case "text/plain" :
				return "txt";
			
			case "application/epub+zip" :
				return "epub";
			
			case "application/pdf" :
				return "pdf";
			
			##
			# AUDIO
			##
			case "audio/mpeg" :
			case "audio/mp3" :
				return "mp3";
			
			case "audio/aac" :
				return "aac";
			
			case "audio/mp4" :
				return "mp3";
			
			case "audio/ogg" :
				return "ogg";
			
			case "audio/wave" :
				return "wav";
			
			case "audio/webm" :
				return "wav";
			
			##
			# VIDEO
			##
			case "video/3gp" :
				return "3gp";
			
			case "video/webm" :
				return "webm";
			
			case "video/mp4" :
				return "mp4";
			
			case "video/ogg" :
				return "ogg";
			
			case "video/x-flv" :
				return "flv";
		}
		
		return $mimes[1];
	}
	
	/**
	 * Returns if the file is present on the disk.
	 *
	 * @return boolean
	 */
	public function hasFile()
	{
		return Storage::exists($this->getPath());
	}
	
	/**
	 * Returns if a thumbnail is present on the disk.
	 *
	 * @return boolean
	 */
	public function hasThumb()
	{
		return Storage::exists($this->getPathThumb());
	}
	
	/**
	 * Is this attachment audio?
	 *
	 * @return boolean
	 */
	public function isAudio()
	{
		switch ($this->mime)
		{
			case "audio/mpeg" :
			case "audio/mp3" :
			case "audio/aac" :
			case "audio/mp4" :
			case "audio/ogg" :
			case "audio/wave" :
			case "audio/webm" :
				return true;
		}
		
		return false;
	}
	
	/**
	 * Is this attachment an image?
	 *
	 * @return boolean
	 */
	public function isImage()
	{
		switch ($this->mime)
		{
			case "image/jpeg" :
			case "image/jpg" :
			case "image/gif" :
			case "image/png" :
				return true;
		}
		
		return false;
	}
	
	/**
	 * Is this attachment an image vector (SVG)?
	 *
	 * @reutrn boolean
	 */
	public function isImageVector()
	{
		return $this->mime === "image/svg+xml";
	}
	
	/**
	 * Returns if our pivot is a spoiler.
	 *
	 * @return boolean
	 */
	public function isSpoiler()
	{
		return isset($this->pivot) && isset($this->pivot->is_spoiler) && !!$this->pivot->is_spoiler;
	}
	
	/**
	 * Is this attachment a video?
	 * Primarily used to split files on HTTP range requests.
	 *
	 * @return boolean
	 */
	public function isVideo()
	{
		switch ($this->mime)
		{
			case "video/3gp" :
			case "video/webm" :
			case "video/mp4" :
			case "video/ogg" :
			case "video/x-flv" :
				return true;
		}
		
		return false;
	}
	
	/**
	 * Work to be done upon creating an attachment using this storage.
	 *
	 * @param  FileAttachment  $attachment  Defaults to null.
	 * @return FileStorage
	 */
	public function processAttachment(FileAttachment $attachment = null)
	{
		$this->last_uploaded_at = $this->freshTimestamp();
		// Not counting uploads unless it ends up on a post.
		// $this->upload_count    += 1;
		
		
		$this->processThumb();
		$this->save();
		
		return $this;
	}
	
	/**
	 * Turns an image into a thumbnail if possible, overwriting previous versions.
	 *
	 * @return void
	 */
	public function processThumb()
	{
		if (!Storage::exists($this->getPathThumb()))
		{
			if ($this->isAudio())
			{
				$ID3  = new \getID3();
				$meta = $ID3->analyze($this->getFullPath());
				
				if (isset($meta['comments']['picture']) && count($meta['comments']['picture']))
				{
					foreach ($meta['comments']['picture'] as $albumArt)
					{
						try
						{
							$imageManager = new ImageManager;
							$imageManager
								->make($albumArt['data'])
								->resize(
									Settings::get('attachmentThumbnailSize'),
									Settings::get('attachmentThumbnailSize'),
									function($constraint) {
										$constraint->aspectRatio();
										$constraint->upsize();
									}
								)
								->encode(Settings::get('attachmentThumbnailJpeg') ? "jpg" : "png", Settings::get('attachmentThumbnailQuality'))
								->save($this->getFullPathThumb());
							
							$this->has_thumbnail = true;
							return true;
						}
						catch (\Exception $error)
						{
							// Nothing.
						}
						
						break;
					}
				}
			}
			else if ($this->isVideo())
			{
				Storage::makeDirectory($this->getDirectoryThumb());
				
				$video    = $this->getFullPath();
				$image    = $this->getFullPathThumb();
				$interval = 0;
				$frames   = 1;
				
				$cmd = "ffmpeg -i {$video} -deinterlace -an -ss {$interval} -f mjpeg -t 1 -r 1 -y {$image} 2>&1";
				
				exec($cmd, $output, $returnvalue);
				
				// Constrain thumbnail to proper dimensions.
				if (Storage::exists($this->getPathThumb()))
				{	
					$imageManager = new ImageManager;
					$imageManager
					->make($this->getFullPathThumb())
						->resize(
							Settings::get('attachmentThumbnailSize'),
							Settings::get('attachmentThumbnailSize'),
							function($constraint) {
								$constraint->aspectRatio();
								$constraint->upsize();
							}
						)
						->encode("jpg", Settings::get('attachmentThumbnailQuality'))
						->save($this->getFullPathThumb());
					
					$this->has_thumbnail = true;
					return true;
				}
				else
				{
					dd($output);
				}
			}
			else if ($this->isImage())
			{
				Storage::makeDirectory($this->getDirectoryThumb());
				
				$imageManager = new ImageManager;
				$imageManager
					->make($this->getFullPath())
					->resize(
						Settings::get('attachmentThumbnailSize'),
						Settings::get('attachmentThumbnailSize'),
						function($constraint) {
							$constraint->aspectRatio();
							$constraint->upsize();
						}
					)
					->encode(Settings::get('attachmentThumbnailJpeg') ? "jpg" : "png", Settings::get('attachmentThumbnailQuality'))
					->save($this->getFullPathThumb());
				
				$this->has_thumbnail = true;
				return true;
			}
		}
		else
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * Refines a query to an exact hash match.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query  Supplied by the builder.
	 * @param  string  $hash  The checksum hash.
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function scopeHash($query, $hash)
	{
		return $query->where('hash', $hash);
	}
	
	/**
	 * Refines a query to only storage items which are orphaned (not used anywhere).
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query  Supplied by the builder.
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function scopeWhereOrphan($query)
	{
		return $query->whereDoesntHave('attachments')
			->whereDoesntHave('assets');
	}
	
	/**
	 * Handles an UploadedFile from form input. Stores, creates a model, and generates a thumbnail.
	 *
	 * @param  UploadedFile  $upload
	 * @return FileStorage
	 */
	public static function storeUpload(UploadedFile $upload)
	{
		$fileContent  = File::get($upload);
		$fileMD5      = md5((string) File::get($upload));
		$storage      = static::getHash($fileMD5);
		
		if (!($storage instanceof static))
		{
			$storage           = new static();
			$fileTime          = $storage->freshTimestamp();
			
			$storage->hash     = $fileMD5;
			$storage->banned   = false;
			$storage->filesize = $upload->getSize();
			$storage->mime     = $upload->getClientMimeType();
			$storage->first_uploaded_at = $fileTime;
			$storage->upload_count = 0;
			
			if (!isset($upload->case))
			{
				$ext = $upload->guessExtension();
				
				$upload->case = Sleuth::check($upload->getRealPath(), $ext);
				
				if (!$upload->case)
				{
					$upload->case = Sleuth::check($upload->getRealPath());
				}
			}
			
			if (is_object($upload->case))
			{
				$storage->mime = $upload->case->getMimeType();
				
				if ($upload->case->getMetaData())
				{
					$storage->meta = json_encode($upload->case->getMetaData());
				}
			}
		}
		else
		{
			$fileTime = $storage->freshTimestamp();
		}
		
		if (!Storage::exists($storage->getPath()))
		{
			Storage::put($storage->getPath(), $fileContent);
			Storage::makeDirectory($storage->getDirectoryThumb());
		}
		
		$storage->processAttachment();
		
		return $storage;
	}
	
}
