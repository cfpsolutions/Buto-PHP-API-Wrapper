<?php

/**
 |
 | BUTO PHP API WRAPPER
 | ================================================================================ 
 | Version: 0.9
 | Last modified: 01/05/2013
 | Last modified by: HdotNET
 |
 |
 | LICENSE
 | ================================================================================ 
 | Buto PHP API Wrapper Copyright (C) 2011 Big Button Media Limited.
 |
 | This library is free software; you can redistribute it and/or
 | modify it under the terms of the GNU Lesser General Public
 | License as published by the Free Software Foundation; either
 | version 2.1 of the License, or (at your option) any later version.
 |
 | This library is distributed in the hope that it will be useful,
 | but WITHOUT ANY WARRANTY; without even the implied warranty of
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 | Lesser General Public License for more details.
 |
 | You should have received a copy of the GNU Lesser General Public
 | License along with this library; if not, write to the Free Software
 | Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 |
 |
 | USAGE
 | ================================================================================
 | This class file must be included in any script that will interact with the Buto
 | system, the main Buto class should then be initiated with your API key.
 |
 | $key							= 'YOURAPIKEY';
 | $buto						= new Buto(array('api_key'=>$key));
 |
 | OR
 |
 | $key							= 'YOURAPIKEY';
 | $buto						= new Buto();
 | $buto->initialize(array('api_key'=>$key));
 | // do some stuff using the normal object response
 | // then re-initialize to change the response format
 | $buto->initialize(array('response_format'=>'xml'));
 | $video_xml = $buto->get_video($id); 
 |
 |
 | Once initiated, simply pass the params needed to each function to set and
 | retrieve data from your Buto account. So when using the object response format:
 |
 | $id							= 'XXXXX';
 | $video						= $buto->get_video($id); 
 |
 | echo 'Title of my video = '.$video->name;
 |
 |
 | RESTRICTIONS
 | ================================================================================
 | API is rate limited to 5 requests a second - so ensure no endless loops are
 | used or a ban period will incurr.
 |
**/

/**
 | CFPSOLUTIONS UPDATE 2011-04-21: added some error detection for when a 404 is encountered.
 | CFPSOLUTIONS UPDATE 2013-05-01: added checks for correct php modules installation (simplexml and curl)
 | CFPSOLUTIONS UPDATE 2013-05-01: added new response_format config parameter to allow the return response to be made up of XML/ XML chunks
 | CFPSOLUTIONS UPDATE 2013-05-01: added update_video method
 | CFPSOLUTIONS UPDATE 2013-05-01: set api url to https
*/
class Buto
{
	public $api_key				= NULL;
	public $site_url			= 'http://buto.tv';
	private $api_url			= 'https://api.buto.tv';
	public $response_format 	= 'object'; // object/xml
	public $errors				= array();
	
	/**
	 | Constructor
	 | ------------------------------------------------------------------
	 | Sets API key to local var for all queries
	 | Can be overriden later by using 
	 | 
	 | $buto->api_key			= 'NEWAPIKEY';
	**/
	
	public function __construct($params = array())
	{
		try {
			$this->check_setup();
		}
		catch (Exception $e) 
		{
		   $this->errors[] = $e->getMessage();
		   die(__METHOD__ . ' Fatal error : ' .$e->getMessage());
		}
		
		if (count($params) > 0)
		{
			$this->initialize($params);
		}
	}
	/**
	 | Initialize api preferences
	 | ------------------------------------------------------------------
	 | array('api_key'=>'xxxx','response_format'=>'xxxx')
	**/	 
	public function initialize($params = array())
	{
		if (count($params) > 0)
		{
			foreach ($params as $key => $val)
			{
				$this->$key = $val;
			}
		}
	}
	private function check_setup()
	{
		if (!extension_loaded('curl')) 
		{
			throw new Exception('curl module is not installed');
			return false;
		}
		if (!extension_loaded('simplexml')) 
		{
			throw new Exception('SimpleXML module is not installed');
			return false;
		}
		return true;	
	}
	private function return_response($response,$object)
	{
		switch($this->response_format)
		{
			case 'xml':
				return $response->asXML();
			break;	
			case 'array':
				return get_object_vars($response);
			break;	
			default:
				$response = get_object_vars($response);
				return new $object($response);	
			break;
		}
	}
	private function process_response($response,$key,$object)
	{
		$returned = array();
		$response = get_object_vars($response);
		if (!is_array($response[$key]))
		{
			// a single item was returned
			switch($this->response_format)
			{
				case 'xml':
					$v = $response[$key]->asXML();
				break;	
				case 'array':
					$v = get_object_vars($response[$key]);
				break;	
				default:
					$v = new $object(get_object_vars($response[$key]));	
				break;
			}

			array_push($returned, $v);
		}
		else
		{
			// multiple item were returned
			foreach ($response[$key] as $r)
			{
				switch($this->response_format)
				{
					case 'xml':
						$v = $r->asXML();
					break;		
					case 'array':
						$v = get_object_vars($r);
					break;
					default:
						$v = new $object(get_object_vars($r));	
					break;
				}
				array_push($returned, $v);
			}
		}

		return $returned;	
	}
	
	
	/**
	 | Account - Get account info
	 | ------------------------------------------------------------------
	 | Returns all account information for the user. No params
	**/
	
	public function account()
	{
		$dest			= $this->api_url.'/account/';
		$api_response	= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoResponse');
	}
	
	/**
	 | Videos - Get single video
	 | ------------------------------------------------------------------
	 | Returns a single video object based on it's ID
	 | If video doesn't exits returns FALSE and sets $errors with output
	**/
	
	public function get_video($video_id)
	{
		$dest			= $this->api_url.'/videos/'.$video_id;
		$api_response	= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoVideo');
	}
	
	/**
	 | Videos - get all videos (paginated)
	 | ------------------------------------------------------------------
	 | Returns all videos within the offset / limit range
	 | all limits greater than 25 are capped.
	**/
	
	public function get_videos($limit = 25, $offset = 0)
	{
		$limit				= (int)$limit;
		$offset				= (int)$offset;
		
		$dest				= $this->api_url.'/videos/?limit='.$limit.'&offset='.$offset;
		$api_response		= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->process_response($api_response,'video','ButoVideo');
	}
	
	/**
	 | Videos - Search
	 | ------------------------------------------------------------------
	 | Searches videos in the account based on params passed.
	 | all parameters are optional
	 |
	 | Returns an array of ButoVideo objects, which may be empty if search
	 | returns no results
	**/
	
	public function search_videos($start = FALSE, $end = FALSE, $string = FALSE, $tag_array = NULL)
	{
		$dest				= $this->api_url.'/videos/search/';
	
		// check the vars ...
	
		if ($start && $end)
		{
			$start_date		= date('c', strtotime($start));
			$end_date		= date('c', strtotime($end));
		}
		else
		{
			$start_date		= FALSE;
			$end_date		= FALSE;
		}
		
		if ($string)
		{
			$search_string	= htmlentities($string);
		}
		else
		{
			$search_string	= FALSE;
		}
		
		if ($tag_array && !empty($tag_array))
		{
			$tags			= $tag_array;
		}
		else
		{
			$tags			= array();
		}
		
		if (empty($tags) && !$search_string && !$start_date && !$end_date)
		{
			// no vars were passed - cannot search
			
			$this->errors[]	= 'No search params were passed';
			
			return FALSE;
		}
		
		// else build the XML structure for the POST
		
		$xml = new SimpleXMLElement('<search></search>');
		$xml->addChild('start_date', $start_date);
		$xml->addChild('end_date', $end_date);
		$xml->addChild('text', $search_string);
		$tags_node = $xml->addChild('tags');
		
		foreach($tags as $tag)
		{
			$tags_node->addChild('tag', $tag);
		}
		
		// make the request 
		
		$api_response = $this->request($dest, $xml->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		
		return $this->process_response($api_response,'video','ButoVideo');
	}
	
	/**
	 | Create new publish
	 | ------------------------------------------------------------------
	 | Republishes a video (based on its MEDIA ID)
	 | creates a 'copy' of the video which can have different settings, 
	 | theme, name and size.
	**/
	
	public function republish_video ($params = array())
	{
		if (!$params || empty($params))
		{
			$this->errors[]		= 'No params were passed';
			
			return FALSE;
		}
		
		// all parameters are required
		
		if (!isset($params['video_id']) ||
		    !isset($params['title']) ||
			!isset($params['description']) ||
			!isset($params['embed_width']) ||
			!isset($params['embed_height']) ||
			!isset($params['settings_id']) ||
			!isset($params['theme_id'])   )
		{
			$this->errors[]		= 'Not all required params were passed';
			
			return FALSE;
		}
		
		// the rest of params are optional
		
		$video_id			= $params['video_id'];
		$title				= $params['title']			=== null	? ''	: substr($params['title'], 0, 40);
		$description		= $params['description'] 	=== null	? ''	: substr($params['description'], 0, 300);
		$embed_width		= $params['embed_width'] 	=== null	? 500	: (int)$params['embed_width'];
		$embed_height		= $params['embed_height'] 	=== null	? 280	: (int)$params['embed_height'];
		$settings_id		= $params['settings_id'] 	=== null	? ''	: $params['settings_id'];
		$theme_id			= $params['theme_id'] 		=== null	? ''	: $params['theme_id'];
		
		// generate the request XML
		
		$req = new SimpleXMLElement('<publish></publish>');
		$req->addChild('video_id', $video_id);
		$req->addChild('title', $title);
		$req->addChild('description', $description);
		$req->addChild('embed_width', $embed_width);
		$req->addChild('embed_height', $embed_height);
		$req->addChild('settings_id', $settings_id);
		$req->addChild('theme_id', $theme_id);
		
		// make the request
		
		$dest				= $this->api_url.'/videos/republish/'.$video_id;
		$api_response		= $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoVideo');
	}
	
	/**
	 | Embed Video
	 | ----------------------------------------------------------
	 | Generates a standard embed code for a buto video based on ID
	 | iPhone compatibility can be disabled with the final parameter
	 | returns void, but echos output
	**/
	
	public function embed_video($id = FALSE, $width = 500, $height = 280, $iphone = TRUE)
	{
		if (!$id || $id == '')
		{
			return FALSE;
		}
		
		if (!is_numeric($width) || !is_numeric($height))
		{
			return FALSE;
		}
		
		$video			= $this->get_video($id);
	
		// check video was returned
		
		if (!$video)
		{
			return FALSE;
		}
		
		// build the embed code
		
		$code			= '';
		
		$code			= '<object type="application/x-shockwave-flash" data="'.$this->site_url.'/player/swf/'.$video->id.'"';
		$code			.= ' width="'.$width.'" height="'.$height.'">';
		$code			.= '<param name="movie" value="'.$this->site_url.'/player/swf/'.$video->id.'" />';
		$code			.= '<param name="flashvars" value="video_id='.$video->id.'" />';
		$code			.= '<param name="allowfullscreen" value="true" />';
		$code			.= '<param name="allowscriptaccess" value="always" />';
		$code			.= '<param name="wmode" value="transparent" />';
		
		if ($iphone)
		{		
			$code		.= '<video width="'.$width.'" height="'.$height.'" src="'.$this->site_url.'/videos/source_file/m4v/256/'.$video->id.'"';
			$code		.= ' poster="'.$this->site_url.'/videos/source_file/jpg/poster/'.$video->id.'" controls>';
		}
		
		$code			.= '<img src="'.$this->site_url.'/videos/source_file/jpg/poster/'.$video->id.'" width="'.$width.'" height="'.$height.'" alt="You must install Adobe Flash Player 9 or higher to view this video" title="You must install Adobe Flash Player 9 or higher to view this video" />';
		$code			.= '</object>';
		
		if ($iphone)
		{
			$code		.= '<script type="text/javascript" src="http://ping.buto.tv/track/'.$video->id.'"></script>';
		}
		
		echo $code;
	}
	
	/**
	 | Upload Video
	 | ----------------------------------------------------------
	 | Upload a video via tha API
	 | @params - array containing name, file_path, encode_width,
	 | encode_height, optional - (settings_id, theme_id)
	 |
	 | returns the ID of the created video, or FALSE
	**/
	
	public function upload_video($params = array())
	{	
		if (!$params || empty($params))
		{
			return FALSE;
		}
		
		$name				= htmlentities($params['name']);
		$url				= $params['file_path'];
		$width				= (int)$params['encode_width'];
		$height				= (int)$params['encode_height'];
		
		// build XML
		
		$req = new SimpleXMLElement('<upload></upload>');
		$req->addChild('name', $name);
		$req->addChild('file_path', $url);
		$req->addChild('encode_width', $width);
		$req->addChild('encode_height', $height);
		
		if (isset($params['settings_id']))
		{
			$req->addChild('settings_id', $params['settings_id']);
		}
		
		if (isset($params['theme_id']))
		{
			$req->addChild('theme_id', $params['theme_id']);
		}
		
		// make the request
		
		$dest					= $this->api_url.'/videos/upload';
		$api_response			= $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		// if the request was successful, the response is the new video ID
		
		$created_video	= new ButoVideo($api_response);
			
		return $created_video->id;
	}
	
	/**
	 | Add tags
	 | ------------------------------------------------------------------
	 | Adds an array of tags to a video, based on video ID
	 | If tag already exists duplicate is not added
	 |
	 | Upon success, returns the updated tags for the video
	**/
	
	public function add_tags($video_id = FALSE, $tags = array())
	{
		if (!$video_id || $video_id == '' || !$tags || empty($tags))
		{
			return FALSE;
		}
		
		// add the tags array as XML
		
		$req = new SimpleXMLElement('<tags></tags>');
		
		foreach ($tags as $tag)
		{
			$req->addChild('tag', $tag);
		}
		
		// make the request
		
		$dest					= $this->api_url.'/videos/'.$video_id.'/tags/add';
		$api_response			= $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoResponse');
	}
	
	/**
	 | Remove tags
	 | ------------------------------------------------------------------
	 | Removes an array of tags from a video. If the tag does not exist no
	 | errors are thrown, but nothing is removed
	 |
	 | Upon success, returns the updated tags for the video
	**/
	
	public function remove_tags($video_id = FALSE, $tags = array())
	{
		if (!$video_id || $video_id == '' || !$tags || empty($tags))
		{
			return FALSE;
		}
		
		// add the tags array as XML
		
		$req = new SimpleXMLElement('<tags></tags>');
		
		foreach ($tags as $tag)
		{
			$req->addChild('tag', $tag);
		}
		
		// make the request
		
		$dest						= $this->api_url.'/videos/'.$video_id.'/tags/remove';
		$api_response				= $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoResponse');
	}
	
	/**
	 | Get comments
	 | ------------------------------------------------------------------
	 | Returns all comments across all videos based on status.
	 | can be optionally limited to a video with the second param.
	**/
	
	public function get_comments($status = FALSE, $video_id = FALSE)
	{
		if ($status)
		// validate value passed
		{
			if ($status != 'approved' && $status != 'denied' && $status != 'pending')
			{
				return FALSE;
			}
		}
		
		// build the request
		
		if ($video_id)
		{
			$dest = $this->api_url.'/comments/video/'.$video_id.'/'.$status;	
		}
		else
		{
			$dest = $this->api_url.'/comments/'.$status;
		}
		
		// make the request
		
		$api_response = $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		
		return $this->process_response($api_response,'comment','ButoComment');

	}
	
	/**
	 | Create comment
	 | ------------------------------------------------------------------
	 | Posts a comment on a video
	 |
	 | Upon success, returns the details of the new comment
	**/
	
	public function post_comment($params)
	{
		if (!$params['video_id'] || !$params['name'] || !$params['body'])
		{
			return FALSE;
		}
		
		// build the request
		
		$dest = $this->api_url.'/comments/create/';
		
		$req = new SimpleXMLElement('<comment></comment>');
		$req->addChild('video_id', $params['video_id']);
		$req->addChild('name', $params['name']);
		$req->addChild('body', $params['body']);
		
		// make the request
		
		$api_response = $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoComment');
	}
	
	/**
	 | Approve comment
	 | ------------------------------------------------------------------
	 | Sets status of comment to approved
	 |
	 | Upon success, returns the details of the updated comment
	**/
	
	public function approve_comment($comment_id = FALSE)
	{
		return $this->toggle_comment($comment_id, 'approve');
	}
	
	/**
	 | Deny comment
	 | ------------------------------------------------------------------
	 | Sets status of comment to denied
	 |
	 | Upon success, returns the details of the updated comment
	**/
	
	public function deny_comment($comment_id = FALSE)
	{
		return $this->toggle_comment($comment_id, 'deny');
	}
	
	/**
	 | Toggle Comment
	 | ------------------------------------------------------------------
	 | Peforms action for above functions
	 | private - should not be called directly
	**/
	
	private function toggle_comment($comment_id, $status)
	{
		if (!$comment_id)
		{
			return FALSE;
		}
		
		$dest					= $this->api_url.'/comments/'.$status.'/'.$comment_id;
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}	
		
		return $this->return_response($api_response,'ButoComment');
	}
	
	/**
	 | Get single setting
	 | ------------------------------------------------------------------
	 | Returns details about a single settings 'files' from its ID.
	**/
	
	public function get_setting($setting_id = FALSE)
	{
		if (!$setting_id || $setting_id == '')
		{
			return FALSE;
		}
	
		$dest						= $this->api_url.'/settings/setting/'.$setting_id;
		$api_response				= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoSettings');		
	}
	
	/**
	 | Get Settings
	 | ------------------------------------------------------------------
	 | Returns all settings 'files' for an organisation.
	 | returns FALSE on error and sets $error var
	**/
	
	public function get_settings()
	{	
		
		$dest					= $this->api_url.'/settings/';
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->process_response($api_response,'settings','ButoSettings');

	}
	
	/**
	 | Get single theme
	 | ------------------------------------------------------------------
	 | Returns details about a single theme from its ID.
	**/
	
	public function get_theme($theme_id = FALSE)
	{
		if (!$theme_id || $theme_id == '')
		{
			return FALSE;
		}
	
		$dest						= $this->api_url.'/themes/theme/'.$theme_id;
		$api_response				= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoTheme');				
	}
	
	/**
	 | Get Themes
	 | ------------------------------------------------------------------
	 | Returns all Themes in the system for the authenticated organisation.
	 | returns FALSE on error and sets $errors var with output.
	**/
	
	public function get_themes()
	{
		$dest					= $this->api_url.'/themes/';
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}

		return $this->process_response($api_response,'theme','ButoTheme');

	}
	
	/**
	 | Get single playlist
	 | ------------------------------------------------------------------
	 | Returns details about a single playlist, as well as each video inside the playlist
	 | Videos returned in their correct order as ButoVideo objects
	**/
	
	public function get_playlist($playlist_id = FALSE)
	{
		if (!$playlist_id || $playlist_id == '')
		{
			return FALSE;
		}
	
		$dest						= $this->api_url.'/playlists/'.$playlist_id;
		$api_response				= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoPlaylist');			
	}
	
	/**
	 | Get All playlists
	 | ------------------------------------------------------------------
	 | Returns all playlists in short XML format (no videos returned)
	 | Use individual playlist call for videos in playlist
	**/
	
	public function get_playlists()
	{
		$dest					= $this->api_url.'/playlists/';
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		
		
		return $this->process_response($api_response,'playlist','ButoPlaylist');
		

	}
	
	/**
	 | Create playlists
	 | ------------------------------------------------------------------
	 | Adds an array of videos to a new playlist.
	 |
	 | Upon success, returns the playlist details including video objects
	**/
	
	public function create_playlist($params = FALSE)
	{
		if (!$params || empty($params))
		{
			return FALSE;
		}
		
		// add the tags array as XML
		
		$req = new SimpleXMLElement('<playlist></playlist>');
		
		$req->addChild('name', $params['name']);
		$req->addChild('type', $params['type']);
		$req->addChild('videos');
		
		foreach ($params['videos'] as $video)
		{
			$req->videos->addChild('video', $video);
		}
		
		// make the request
		
		$dest					= $this->api_url.'/playlists/create/';
		$api_response			= $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoPlaylist');	
	}
	
	/**
	 | Get single user
	 | ------------------------------------------------------------------
	 | Returns details about a single user from an ID.
	**/
	
	public function get_user($user_id = FALSE)
	{
		if (!$user_id || $user_id == '')
		{
			return FALSE;
		}
	
		$dest						= $this->api_url.'/users/user/'.$user_id;
		$api_response				= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
				
		return $this->return_response($api_response,'ButoUser');
	}
	
	/**
	 | Get users
	 | ------------------------------------------------------------------
	 | Returns all users in the system for the authenticated organisation.
	 | returns FALSE on error and sets $errors var with output.
	**/
	
	public function get_users()
	{
		$dest					= $this->api_url.'/users/';
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->process_response($api_response,'user','ButoUser');
	}	
	
	/**
	 | Get transcript
	 | ------------------------------------------------------------------
	 | Returns transcript for a single caption from an ID.
	**/
	
	public function get_transcript($caption_id = FALSE)
	{
		if (!$caption_id || $caption_id == '')
		{
			return FALSE;
		}
	
		$dest						= $this->api_url.'/captions/'.$caption_id;
		$api_response				= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoCaptions');		
	}
	
	/**
	 | Get captions
	 | ------------------------------------------------------------------
	 | Returns all captions with transcripts for a media file from a video ID.
	 | returns FALSE on error and sets $errors var with output.
	**/
	
	public function get_captions($video_id = FALSE)
	{
		$dest					= $this->api_url.'/captions/video/'.$video_id;
		$api_response			= $this->request($dest);
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->process_response($api_response,'caption','ButoCaptions');

	}	
	/**
	 | Update video - update meta data only.
	 | eg title, description
	 | ------------------------------------------------------------------
	 | Returns the updated video
	 | Returns FALSE on error
	**/
	public function update_video($video_id,$params)
	{
		if (!is_array($params))
		{
			return FALSE;
		}
		
		// build the request
		
		$dest = $this->api_url.'/videos/update/'.$video_id;
		
		$req = new SimpleXMLElement('<request></request>');
		$req->addChild('id', $video_id);
		foreach($params as $key=>$value)
		{
			$req->addChild($key,$value);
		}
		// make the request
		
		$api_response = $this->request($dest, $req->asXML());
		
		if (!$api_response)
		{
			return FALSE;
		}
		
		return $this->return_response($api_response,'ButoVideo');
	}
	
	/**
	 | Request
	 | ------------------------------------------------------------------
	 | Creates a CURL request to the Buto API server and handles responses
	 | A valid response is returned as the SimpleXML object
	 | 
	 | Errors are handled by returning FALSE and setting the $errors var
	 | with the output of the error message for debugging
	**/
	
	private function request($url, $xml = FALSE)
	{
		$ch = curl_init($url);
		
		curl_setopt_array($ch, array(
      		CURLOPT_RETURNTRANSFER 		=> 1,
      		CURLOPT_HEADER 				=> 0,
      		CURLOPT_HTTPAUTH			=> CURLAUTH_BASIC,
      		CURLOPT_USERPWD				=> $this->api_key.':x',
      		CURLOPT_HTTPHEADER 			=> array("Accept: text/xml", "Content-type: application/xml"),
      		CURLOPT_POST 				=> 0,
      		CURLOPT_CONNECTTIMEOUT 		=> 0,
			CURLOPT_FOLLOWLOCATION 		=> 1
		));
		
		if ($xml)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		}
		
		// set SSL verify
		
   		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    	
    	$result							= curl_exec($ch);
		$info 							= curl_getinfo($ch);
		// also grab request info so we can get the response status.
    	curl_close($ch);
		
		if ($result)
    	{
			if (strpos($result, 'Error:') !== false) // API response contained errors
			{
				// return the error message after the colon
				$this->errors[$url] = substr($result, strpos($result, 'Error:') + 6);
				return FALSE;
			}
			
			else
			{
				if(isset($info['http_code']) && $info['http_code'] == '404')
				{
					// we should just bail here.
					$this->errors[$url] = 'Error 404';
					return false;	
				}
				
				elseif ($result != "")
				{
					try {
						libxml_use_internal_errors(true); // this should suppress any XML errors.
						$api_response_simple_xml = new SimpleXMLElement($result);
						return $api_response_simple_xml;
					}
					catch (Exception $e) 
					{
					   return FALSE;
					}
			
				}
				
				return TRUE;
			}
		}
		
		else
			return FALSE;
    	
	}
	
	
}

/**
 | Main response class
 | Handles creating vars for each param
 |
**/

class ButoResponse
{
	public function __construct($params)
	{
		$this->set_attributes($params);
	}

	public function set_attributes($params = '')
	{
		if (is_array($params))
		{
			foreach ($params as $key => $value)
			{
				if (strpos($key, "-"))
				// PHP does not like hyphens in variable names
				{
				 	$key = str_replace("-", "_", $key);
				}
				
				$this->$key = $value;
			}
		}
	}	
}

// item Objects - used for responses - will be extended in future

class ButoVideo extends ButoResponse { }
class ButoComment extends ButoResponse { }
class ButoSettings extends ButoResponse { }
class ButoPlaylist extends ButoResponse { }
class ButoTheme extends ButoResponse { }
class ButoUser extends ButoResponse { }
class ButoCaptions extends ButoResponse { }
?>
