<?php

/**
 |
 | BUTO PHP API WRAPPER
 | ================================================================================ 
 | Version: 0.7
 | Last modified: 23/03/2011
 | Last modified by: Greg
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
 | $buto						= new Buto($key);
 |
 | Once initiated, simply pass the params needed to each function to set and
 | retrieve data from your Buto account.
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
 | CFPGROUP UPDATE: added some error detection for when a 404 is encountered.
*/
class Buto
{
	public $api_key		= NULL;
	public $site_url	= 'http://buto.tv';
	public $api_url		= 'http://api.buto.tv';
	public $errors		= array();
	
	/**
	 | Constructor
	 | ------------------------------------------------------------------
	 | Sets API key to local var for all queries
	 | Can be overriden later by using 
	 | 
	 | $buto->api_key			= 'NEWAPIKEY';
	**/
	
	public function __construct($key)
	{
		$this->api_key = $key;
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
		
		return new ButoResponse($api_response);
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
		
		return new ButoVideo($api_response);
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
		
		$returned_videos	= array();
		
		if (!is_array($api_response['video']))
		// a single video was returned
		{
			array_push($returned_videos, new ButoVideo(get_object_vars($api_response['video'])));
		}
		
		else
		// multiple videos were returned
		{
			foreach ($api_response['video'] as $api_response_video)
			{
				array_push($returned_videos, new ButoVideo(get_object_vars($api_response_video)));
			}
		}
		
		return $returned_videos;
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
		
		$returned_videos		= array();
	
		if (!is_array($api_response['video']))
		// a single video was returned
		{
			array_push($returned_videos, new ButoVideo(get_object_vars($api_response['video'])));
		}
		
		else
		// multiple videos were returned
		{
			foreach ($api_response['video'] as $api_response_video)
			{
				array_push($returned_videos, new ButoVideo(get_object_vars($api_response_video)));
			}
		}
		
		return $returned_videos;
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
		
		return new ButoVideo($api_response);
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
		
		return new ButoResponse($api_response);
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
		
		return new ButoResponse($api_response);
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
		
		$returned_comments = array();
					
		if (!is_array($api_response['comment']))
		// a single comment was returned
		{
			array_push($returned_comments, new ButoComment(get_object_vars($api_response['comment'])));
		}
		
		else
		// multiple comments were returned
		{
			foreach ($api_response['comment'] as $api_response_comment)
			{
				array_push($returned_comments, new ButoComment(get_object_vars($api_response_comment)));
			}
		}
		
		return $returned_comments;
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
		
		return new ButoComment($api_response);
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
		
		return new ButoComment($api_response);
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
		
		$returned_settings = array();
					
		if (!is_array($api_response['settings']))
		// a single settings 'file' was returned
		{
			array_push($returned_settings, new ButoSettings(get_object_vars($api_response['settings'])));
		}
		
		else
		// multiple settings 'files' were returned
		{
			foreach ($api_response['settings'] as $api_response_settings)
			{
				array_push($returned_settings, new ButoSettings(get_object_vars($api_response_settings)));
			}
		}
		
		return $returned_settings;
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
		
		$returned_themes = array();
					
		if (!is_array($api_response['theme']))
		// a single theme was returned
		{
			array_push($returned_themes, new ButoTheme(get_object_vars($api_response['theme'])));
		}
		
		else
		// multiple themes were returned
		{
			foreach ($api_response['theme'] as $api_response_theme)
			{
				array_push($returned_themes, new ButoTheme(get_object_vars($api_response_theme)));
			}
		}
		
		return $returned_themes;
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
		
		return new ButoPlaylist($api_response);			
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
		
		$returned_playlists = array();
					
		if (!is_array($api_response['playlist']))
		// a single playlist was returned
		{
			array_push($returned_playlists, new ButoPlaylist(get_object_vars($api_response['playlist'])));
		}
		
		else
		// multiple playlists were returned
		{
			foreach ($api_response['playlist'] as $api_response_playlist)
			{
				array_push($returned_playlists, new ButoPlaylist(get_object_vars($api_response_playlist)));
			}
		}
		
		return $returned_playlists;
	}
	
	/**
	 | Request
	 | ------------------------------------------------------------------
	 | Creates a CURL request to the Buto API server and handles responses
	 | A valid response is returned as the SimpleXML response converted into an array
	 | 
	 | Errors are handled by returning FALSE and setting the $errors var
	 | with the output of the error message for debugging
	**/
	
	private function request($url, $xml = FALSE)
	{
		$ch						= curl_init($url);
		
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
		$info 							= curl_getinfo($ch); // also grab request info so we can get the response status.
    	curl_close($ch);
		
		if ($result)
    	{
			if (strpos($result, 'Error:') !== false)
			// API response contained errors
			{
				// return the error message after the colon
			
				$this->errors[$url]		= substr($result, strpos($result, 'Error:') + 6);
				
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
						
						return get_object_vars($api_response_simple_xml);
					}
					
					catch (Exception $e) {
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
?>
