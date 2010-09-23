<?php

/**
 |
 | BUTO PHP API WRAPPER
 | ================================================================================ 
 | Version: 0.4
 | Last modified: 23/09/2010
 | Last modified by: Greg 
 |
 |
 | LICENSE
 | ================================================================================ 
 | Buto PHP API WrapperCopyright (C) 2010 Big Button Media Limited.
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

class Buto
{
	public $api_key				= NULL;
	public $api_url				= 'http://api.buto.tv';
	public $errors				= array();
	
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
		$this->api_key			= $key;
	}
	
	/**
	 | Account - Get account info
	 | ------------------------------------------------------------------
	 | Returns all account information for the user. No params
	**/
	
	public function account()
	{
		$this->dest				= $this->api_url.'/account/';
		$response				= $this->request($this->dest);
		
		if ($response)
		{
			return new ButoResponse($response);
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Videos - Get single video
	 | ------------------------------------------------------------------
	 | Returns a single video object based on it's ID
	 | If video doesn't exits returns FALSE and sets $errors with output
	**/
	
	public function get_video($video_id)
	{
		$this->dest				= $this->api_url.'/videos/'.$video_id;
		$this->response			= $this->request($this->dest);
		
		if ($this->response)
		{
			return new ButoVideo($this->response);
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Videos - get all videos (paginated)
	 | ------------------------------------------------------------------
	 | Returns all videos within the offset / limit range
	 | all limits greater than 25 are capped.
	**/
	
	public function get_videos($limit = 25, $offset = 0)
	{
		$limit					= (int)$limit;
		$offset					= (int)$offset;
		
		$this->dest				= $this->api_url.'/videos/?limit='.$limit.'&offset='.$offset;
		$response				= $this->request($this->dest);
		
		if ($response)
		{
			$videos				= array();
			
			foreach($response['video'] as $params)
			{
				array_push($videos, new ButoVideo(get_object_vars($params)));
			}
			
			return $videos;
		}
		else
		{
			return FALSE;
		}
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
		$this->dest						= $this->api_url.'/videos/search/';
	
		/** Check the vars and set to locals **/
	
		if ($start && $end)
		{
			$this->start_date			= date('c', strtotime($start));
			$this->end_date				= date('c', strtotime($end));
		}
		else
		{
			$this->start_date			= FALSE;
			$this->end_date				= FALSE;
		}
		
		if ($string)
		{
			$this->search_string		= htmlentities($string);
		}
		else
		{
			$this->search_string		= FALSE;
		}
		
		if ($tag_array && !empty($tag_array))
		{
			$this->tags					= $tag_array;
		}
		else
		{
			$this->tags					= array();
		}
		
		if (empty($this->tags) && !$this->search_string && !$this->start_date && !$this->end_date)
		{
			/** No vars were passed, cannot search **/
			
			$this->errors[]				= 'No Search Params passed';
			return FALSE;
		}
		
		/** Build the XML structure for the POST **/
		
		$xml							= new SimpleXMLElement('<search></search>');
		$xml->addChild('start_date', $this->start_date);
		$xml->addChild('end_date', $this->end_date);
		$xml->addChild('text', $this->search_string);
		$tags_node						= $xml->addChild('tags');
		
		foreach($this->tags as $tag)
		{
			$tags_node->addChild('tag', $tag);
		}
		
		/** Make the API call **/    
		
		$response						= $this->request($this->dest, $xml->asXML());
		
		if ($response)
		{
		
			//print_r($response); exit;
			
			$video_responses			= array();
			
			foreach($response as $params)
			{			
				array_push($video_responses, new ButoVideo(get_object_vars($params)));
			}
			
			return $video_responses;
		}
		else
		{	
			//An error was encountered, bail out
			//User can access any errors using $buto->errors
			
			return FALSE;
		}		
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
			return FALSE;
		}
		
		//Video ID required for re-publish
		
		if (!$params['video_id'])
		{
			return FALSE;
		}
		
		//Rest of params are optional
		
		$video_id			= $params['video_id'];
		$title				= $params['title']			=== null	? ''	: substr($params['title'], 0, 40);
		$desc				= $params['description'] 	=== null	? ''	: substr($params['description'], 0, 300);
		$width				= $params['embed_width'] 	=== null	? 500	: (int)$params['embed_width'];
		$height				= $params['embed_height'] 	=== null	? 280	: (int)$params['embed_height'];
		$settings			= $params['settings_id'] 	=== null	? ''	: $params['settings_id'];
		$theme				= $params['theme_id'] 		=== null	? ''	: $params['theme_id'];
		
		//Generate the request XML
		
		$req				= new SimpleXMLElement('<publish></publish>');
		$req->addChild('video_id', $video_id);
		$req->addChild('title', $title);
		$req->addChild('description', $desc);
		$req->addChild('embed_width', $width);
		$req->addChild('embed_height', $height);
		$req->addChild('settings_id', $settings);
		$req->addChild('theme_id', $theme);
		
		//Make the request
		
		$dest				= $this->api_url.'/videos/republish/'.$video_id;
		$response			= $this->request($dest, $req->asXML());
		
		return new ButoVideo($response);
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
		
		//XML for required information
		
		$req				= new SimpleXMLElement('<upload></upload>');
		$req->addChild('name', $name);
		$req->addChild('file_path', $url);
		$req->addChild('encode_width', $width);
		$req->addChild('encode_height', $height);
		
		//Add the optional information
		
		if (isset($params['settings_id']))
		{
			$req->addChild('settings_id', $params['settings_id']);
		}
		
		if (isset($params['theme_id']))
		{
			$req->addChild('theme_id', $params['theme_id']);
		}
		
		//Make the post and get the response
		
		$this->dest			= $this->api_url.'/videos/upload';
		$response			= $this->request($this->dest, $req->asXML());
		
		//Return the newly created video ID
		
		if ($response)
		{
			$created_video	= new ButoVideo($response);
			
			return $created_video->id;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Add tags
	 | ------------------------------------------------------------------
	 | Adds an array of tags to a video, based on video ID
	 | If tag already exists duplicate is not added
	**/
	
	public function add_tags($video_id = FALSE, $tags = array())
	{
		if (!$video_id || $video_id == '' || !$tags || empty($tags))
		{
			return FALSE;
		}
		
		//Add the tags array as XML
		
		$req						= new SimpleXMLElement('<tags></tags>');
		
		foreach ($tags as $tag)
		{
			$req->addChild('tag', $tag);
		}
		
		//Make the request
		
		$this->dest					= $this->api_url.'/videos/'.$video_id.'/tags/add';
		$response					= $this->request($this->dest, $req->asXML());
		
		if ($response)
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Remove tags
	 | ------------------------------------------------------------------
	 | Removes an array of tags from a video. If the tag does not exist no
	 | errors are thrown, but nothing is removed
	**/
	
	public function remove_tags($video_id = FALSE, $tags = array())
	{
		if (!$video_id || $video_id == '' || !$tags || empty($tags))
		{
			return FALSE;
		}
		
		//Add the tags array as XML
		
		$req						= new SimpleXMLElement('<tags></tags>');
		
		foreach ($tags as $tag)
		{
			$req->addChild('tag', $tag);
		}
		
		//Make the request
		
		$this->dest					= $this->api_url.'/videos/'.$video_id.'/tags/remove';
		$response					= $this->request($this->dest, $req->asXML());
		
		if ($response)
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Get comments
	 | ------------------------------------------------------------------
	 | Returns all comments across all videos based on status.
	 | can be optionally limited to a video with the second param.
	**/
	
	public function get_comments($status = FALSE, $video_id = FALSE)
	{
		if ($status) //Check the correct status param was passed
		{
			if ($status != 'approved' && $status != 'denied' && $status != 'pending')
			{
				return FALSE;
			}
		}
		
		if ($video_id)
		{
			$this->dest					= $this->api_url.'/comments/video/'.$video_id.'/'.$status;	
		}
		else
		{
			$this->dest					= $this->api_url.'/comments/'.$status;
		}
		
		$response					= $this->request($this->dest);
		
		if ($response)
		{
			$response_data			= array();
						
			foreach ($response['comment'] as $cmnt)
			{
				array_push($response_data, new ButoComment(get_object_vars($cmnt)));
			}
			
			return $response_data;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Create comment
	 | ------------------------------------------------------------------
	 | Posts a comment to the account, using the video ID supplied.
	 | returns BOOL
	**/
	
	public function post_comment($params)
	{
		//Check for missing video ID or params
	
		if (!$params['video_id'] || !$params['name'] || !$params['body'])
		{
			return FALSE;
		}
		
		$this->dest						= $this->api_url.'/comments/create/';
		
		//Make the XML for the request
		
		$req							= new SimpleXMLElement('<comment></comment>');
		$req->addChild('video_id', $params['video_id']);
		$req->addChild('name', $params['name']);
		$req->addChild('body', $params['body']);
		
		//Make the request
		
		$response						= $this->request($this->dest, $req->asXML());
		
		if ($response)
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Approve comment
	 | ------------------------------------------------------------------
	 | Sets status of comment to approved
	 | returns Bool
	**/
	
	public function approve_comment($comment = FALSE)
	{
		return $this->toggle_comment($comment, 'approve');
	}
	
	/**
	 | Deny comment
	 | ------------------------------------------------------------------
	 | Sets status of comment to denied
	 | returns Bool
	**/
	
	public function deny_comment($comment = FALSE)
	{
		return $this->toggle_comment($comment, 'deny');
	}
	
	/**
	 | Delete comment
	 | ------------------------------------------------------------------
	 | Removes comment from the system. There is no undo for this
	 | call.
	 | returns Bool
	**/
	
	public function delete_comment($comment = FALSE)
	{
		return $this->toggle_comment($comment, 'delete');
	}
	
	/**
	 | Toggle Comment
	 | ------------------------------------------------------------------
	 | Peforms action for above functions
	 | private - should not be called directly
	**/
	
	private function toggle_comment($comment, $status)
	{
		if (!$comment)
		{
			return FALSE;
		}
		
		$this->dest					= $this->api_url.'/comments/'.$status.'/'.$comment;
		$response					= $this->request($this->dest);
		
		if ($response)
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}	
	}
	
	/**
	 | Get Settings
	 | ------------------------------------------------------------------
	 | Returns all settings 'files' for an organisation.
	 | returns FALSE on error and sets $error var
	**/
	
	public function get_settings()
	{	
		$this->dest					= $this->api_url.'/settings/';
		$response					= $this->request($this->dest);
		
		if ($response)
		{
			$return_vars			= array();
			
			foreach ($response['settings'] as $settings)
			{
				array_push($return_vars, new ButoSettings(get_object_vars($settings)));
			}
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Get Themes
	 | ------------------------------------------------------------------
	 | Returns all Themes in the system for the authenticated organisation.
	 | returns FALSE on error and sets $errors var with output.
	**/
	
	public function get_themes()
	{
		$this->dest					= $this->api_url.'/themes/';
		$response					= $this->request($this->dest);
		
		if ($response)
		{
			$return_vars			= array();
			
			foreach ($response['themes'] as $theme)
			{
				array_push($return_vars, new ButoTheme(get_object_vars($theme)));
			}
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Get All playlists
	 | ------------------------------------------------------------------
	 | Returns all playlists in short XML format (no videos returned)
	 | Use individual playlist call for videos in playlist
	**/
	
	public function get_playlists()
	{
		$this->dest				= $this->api_url.'/playlists/';
		$response				= $this->request($this->dest);
		
		if ($response)
		{
			$return_vars			= array();
			
			foreach ($response['playlist'] as $playlist)
			{
				array_push($return_vars, new ButoPlaylist(get_object_vars($playlist)));
			}
		}
		else
		{
			return FALSE;
		}
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
	
		$this->dest				= $this->api_url.'/playlists/'.$playlist_id;
		$response				= $this->request($this->dest);
		
		if ($response)
		{
			return new ButoPlaylist($response);			
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 | Request
	 | ------------------------------------------------------------------
	 | Creates a CURL request to the Buto API server and handles responses
	 | valid responses are returned in their object form (not XML)
	 | 
	 | Errors are handled by returning FALSE and setting the $errors var
	 | with the output of the error message for debugging
	**/
	
	private function request($url, $xml = FALSE)
	{
		$ch						= curl_init($url);
		
		//Set cURL Options
		
		curl_setopt_array($ch, array(
      		CURLOPT_RETURNTRANSFER 		=> 1,
      		CURLOPT_HEADER 				=> 0,
      		CURLOPT_HTTPAUTH			=> CURLAUTH_BASIC,
      		CURLOPT_USERPWD				=> $this->api_key.':x',
      		CURLOPT_HTTPHEADER 			=> array("Accept: text/xml", "Content-type: application/xml"),
      		CURLOPT_POST 				=> 0,
      		CURLOPT_CONNECTTIMEOUT 		=> 0
		));
		
		//If XML was passed, post the XML.
		
		if ($xml)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		}
		
		//Set SSL verify
		
   		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    	
    	$this->result					= curl_exec($ch);
    	curl_close($ch);
    	
    	if ($this->result)
    	{
    		//Get any errors from the Result
    		
    		if (strpos($this->result, 'Error:') !== false)
    		{
    			//Return the error message after the colon
    		
    			$this->errors[$url]		= substr($this->result, strpos($this->result, 'Error:') + 6);
    			return FALSE;
    		}
    		else
    		{
    			//return an XML object
    			
    			if ($this->result == '') //If the response was blank
    			{
    				return TRUE;
    			}
    			else
    			{
    				return get_object_vars(new SimpleXMLElement($this->result));
    			}
    		}
    	}
    	else
    	{
    		//something went SERIOUSLY wrong here (probably a cURL error)...
    		
    		return FALSE;
    	}
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
			foreach($params as $key => $value)
			{
				 if (strpos($key, "-")) $key = str_replace("-", "_", $key);
				 $this->$key		= $value;
			}
		}
	}	
}

/** Item Objects - used for responses - will be extended in future **/

class ButoVideo extends ButoResponse { }
class ButoComment extends ButoResponse { }
class ButoSettings extends ButoResponse { }
class ButoPlaylist extends ButoResponse { }
class ButoTheme extends ButoResponse { }
?>