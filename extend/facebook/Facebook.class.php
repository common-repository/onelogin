<?php
// http://developers.facebook.com/docs/reference/fql/user
include_once(dirname(__FILE__) . '/base_facebook.php');

class Facebook_class extends BaseFacebook
{
	/**
   * Identical to the parent constructor, except that
   * we start a PHP session to store the user ID and
   * access token if during the course of execution
   * we discover them.
   *
   * @param Array $config the application configuration.
   * @see BaseFacebook::__construct in facebook.php
   */
	public function __construct($app_id, $secret, $default_perms = '') {
		if (!session_id()) {
			session_start();
		}
		$this->cookie = $this->get_facebook_cookie($app_id, $secret);
		$this->default_permissions = $default_perms;

		parent::__construct(array('appId' => $app_id, 'secret' => $secret, 'fileUpload' => true));
	}

	protected static $kSupportedKeys = array('state', 'code', 'access_token', 'user_id');

	/**
	* Provides the implementations of the inherited abstract
	* methods.  The implementation uses PHP sessions to maintain
	* a store for authorization codes, user ids, CSRF states, and
	* access tokens.
	*/
	protected function setPersistentData($key, $value) {
		if (!in_array($key, self::$kSupportedKeys)) {
			self::errorLog('Unsupported key passed to setPersistentData.');
			return;
		}

		global $OneLogin;
		if ( $OneLogin && $key == 'access_token' ) {
			$OneLogin->add_umeta(get_current_user_id(), 'fb_token', $value);
		} elseif ( $OneLogin && $key == 'user_id' ) {
			$OneLogin->add_umeta(get_current_user_id(), 'fb_id', $value);
		} else {
			$session_var_name = $this->constructSessionVariableName($key);
			$_SESSION[$session_var_name] = $value;
		}
	}

	protected function getPersistentData($key, $default = false) {
		if (!in_array($key, self::$kSupportedKeys)) {
			self::errorLog('Unsupported key passed to getPersistentData.');
			return $default;
		}

		global $OneLogin;
		if ( $OneLogin && $key == 'access_token' ) {
			$fb_token_expires = $OneLogin->get_umeta('fb_token_expires');
			$fb_token_expires = ( is_null($fb_token_expires) )? 0 : $fb_token_expires;
			if ( $fb_token_expires > time() ) {
				$token = $OneLogin->get_umeta('fb_token');
			} else {
				$token = $this->getUserData();
				if ( $token ) {
					$OneLogin->add_umeta(get_current_user_id(), 'fb_token_expires', $token['token_expires']);
					$token = $token['token'];
				}
			}
			return $token;
		} elseif ( $OneLogin && $key == 'user_id' ) {
			return $OneLogin->get_umeta('fb_id');
		} else {
			$session_var_name = $this->constructSessionVariableName($key);
			return isset($_SESSION[$session_var_name]) ?
			$_SESSION[$session_var_name] : $default;
		}
	}

	protected function clearPersistentData($key) {
		if (!in_array($key, self::$kSupportedKeys)) {
			self::errorLog('Unsupported key passed to clearPersistentData.');
			return;
		}

		global $OneLogin;
		if ( $OneLogin && $key == 'access_token' ) {
			$OneLogin->add_umeta(get_current_user_id(), 'fb_token', $value);
		} else {
			$session_var_name = $this->constructSessionVariableName($key);
			unset($_SESSION[$session_var_name]);
		}
	}

	protected function clearAllPersistentData() {
		foreach (self::$kSupportedKeys as $key) {
			$this->clearPersistentData($key);
		}
	}

	protected function constructSessionVariableName($key) {
		return implode('_', array('fb',
			$this->getAppId(),
			$key)
		);
	}
	
	public function getDataFromUrl($url) {
		$ch = curl_init();
		$timeout = 5;
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}
	
	public function displayLoginButton($permissions = '', $text = 'Facebook connect', $echo = false, $redirect_url = false) {
		$redirect = '';
		if ( $redirect_url && ! empty($redirect_url) ) {
			$redirect = ' data-redirect="' . $redirect_url['redirect_url'] . '" data-url-nonce="' . $redirect_url['nonce'] . '"';
		}
		$button = '<a class="onelogin-facebook-connect facebook-connect"' . $redirect . ' href="#"' . ( $permissions != '' ? ' data-fb-permissions="' . $permissions . '"' : ''  ) . '>' . $text . '</a>';
		if ( $echo ) {
			echo $button;
		}
		return $button;
	}

	public function displayDisconnectButton($text, $leave = '', $echo = false) {
		$button = '<a class="onelogin-facebook-disconnect facebook-disconnect" href="#" data-leave="' . $leave . '">' . $text . '</a>';
		if ( $echo ) {
			echo $button;
		}
		return $button;
	}
	
	public function getUserid() {
		$cookie = $this->getCookie();
		$fb_userid = $cookie['user_id'];
		return $fb_userid;
	}
	
	public function getProfilePicture($uid = false, $type = 'large') {
		$uid = $uid ? $uid : $this->getUserid();
		$url = 'https://graph.facebook.com/'.$uid.'/picture?type=' . $type;
		//$url = 'api.facebook.com/method/fql.query?query=SELECT pic_big FROM user WHERE uid = '.$this->getUserid();
		$url = $this->get_redirect_url($url);
		return $url;
	}
	
	public function getUserData() {
		$fb_cookie = $this->getCookie();
		if($fb_cookie) {
			$url = 'https://graph.facebook.com/me?access_token='.$this->getAccessToken();
			$data = json_decode($this->getDataFromUrl($url));
			$fb['id'] = $data->id;
			$fb['name'] = $data->name;
			$fb['username'] = $data->username;
			$fb['first_name'] = $data->first_name;
			$fb['last_name'] = $data->last_name;
			$fb['link'] = $data->link;
			$fb['birthday'] = $data->birthday;
			$fb['gender'] = $data->gender;
			$fb['email'] = $data->email;
			$fb['timezone'] = $data->timezone;
			$fb['locale'] = $data->locale;
			$fb['updated_time'] = $data->updated_time;
			$fb['picture'] = $this->getProfilePicture();
			$fb['birthday'] = $data->birthday;
			$fb['bio'] = $data->bio;
			//tokens
			$fb['token'] = $fb_cookie['access_token'];
			$fb['token_expires'] = $fb_cookie['expires'];
			return $fb;
		}
	}
	
	public function getCookie() {
		return $this->cookie;
	}
	
	public function getAccessToken() {
		return $this->cookie['access_token'];
	}
	
	public function loadJsSDK($path_to_library='') { ?>
		<div id="fb-root"></div>
		<script type="text/javascript">
			(function($){
				var adminAjax = "<?php echo admin_url('admin-ajax.php'); ?>";
				function fb_onelogin_connect(th) {
					$("*").css("cursor", "progress");
					var permissions = th.attr('data-fb-permissions'),
						redirect_url = th.attr('data-redirect'),
						redirect_nonce = th.attr('data-url-nonce');
					permissions = permissions || '<?php echo $this->default_permissions; ?>';
					
					FB.login(function(response) {
						if (response.authResponse) {
							on_fb_connect(redirect_url, redirect_nonce);
						} else {
							// console.log(response);
						}
					}, {scope: permissions});
				}

				function on_fb_connect(redirect_url, redirect_nonce) {
					redirect_url = redirect_url || false;
					$.post(
						adminAjax,
						{ 
							action : '<?php echo OneLogin::getInstance()->connect_action; ?>',
							provider: 'facebook',
							redirect_url: redirect_url,
							redirect_nonce: redirect_nonce
						},
						function( response ) {
							$("*").css("cursor", "auto");
							if ( response.error ) alert(response.error);
							if ( redirect_url && response.url_nonce_correct ) {
								window.location.href = redirect_url;
							} else {
								window.location.reload();
							};
						}, 'json'
					);
				}

				function on_fb_disconnect(leave) {
					$("*").css("cursor", "progress");
					$.post(
						adminAjax,
						{ action: '<?php echo OneLogin::getInstance()->disconnect_action; ?>', provider: 'facebook', leave: leave },
						function( response ) {
							$("*").css("cursor", "auto");
							window.location.reload();
						}, 'text'
					);
				}

				$('.facebook-connect').live('click', function(event) {
					event.preventDefault();
					fb_onelogin_connect($(this));
				});

				$('.facebook-disconnect').live('click', function(event) {
					event.preventDefault();
					var leave = $(this).attr('data-leave') || '';
					on_fb_disconnect(leave);
				});
			})(jQuery)

			window.fbAsyncInit = function() {
				FB.init({appId: '<?php echo $this->appId; ?>', status: false, cookie: true, xfbml: true, oauth: true, channelUrl: '<?php echo home_url("/onelogin_facebook_channel_file.html"); ?>' });
				FB.getLoginStatus(function(response) {

				}, true);
			};
		</script>
		<?php 
	}
	
	public function parse_signed_request($signed_request, $secret) {
		list($encoded_sig, $payload) = explode('.', $signed_request, 2); 
		
		// decode the data
		$sig = $this->base64_url_decode($encoded_sig);
		$data = json_decode($this->base64_url_decode($payload), true);
		
		if (strtoupper($data['algorithm']) !== 'HMAC-SHA256') {
			error_log('Unknown algorithm. Expected HMAC-SHA256');
			return null;
		}
		
		// check sig
		$expected_sig = hash_hmac('sha256', $payload, $secret, $raw = true);
		if ($sig !== $expected_sig) {
			error_log('Bad Signed JSON signature!');
			return null;
		}
		
		return $data;
	}
	
	public function base64_url_decode($input) {
		return base64_decode(strtr($input, '-_', '+/'));
	}

	public function get_facebook_cookie($app_id, $app_secret) {
		$signed_request = $this->parse_signed_request($_COOKIE['fbsr_' . $app_id], $app_secret);
		//$signed_request[uid] = $signed_request[user_id]; // for compatibility 
		if (!is_null($signed_request)) {
			$url = "https://graph.facebook.com/oauth/access_token?client_id=$app_id&redirect_uri=&client_secret=$app_secret&code=$signed_request[code]";
			$access_token_response = $this->getDataFromUrl($url);
			parse_str($access_token_response);
			$signed_request[access_token] = $access_token;
			if($expires==0) $signed_request[expires] = 0;
			else $signed_request[expires] = time() + $expires;
		}
		return $signed_request;
	}
	
	public function get_redirect_url($url) {
		$redirect_url = null; 
	 
		$url_parts = @parse_url($url);
		if (!$url_parts) return false;
		if (!isset($url_parts['host'])) return false; //can't process relative URLs
		if (!isset($url_parts['path'])) $url_parts['path'] = '/';
	 
		$sock = fsockopen($url_parts['host'], (isset($url_parts['port']) ? (int)$url_parts['port'] : 80), $errno, $errstr, 30);
		if (!$sock) return false;
	 
		$request = "HEAD " . $url_parts['path'] . (isset($url_parts['query']) ? '?'.$url_parts['query'] : '') . " HTTP/1.1\r\n"; 
		$request .= 'Host: ' . $url_parts['host'] . "\r\n"; 
		$request .= "Connection: Close\r\n\r\n"; 
		fwrite($sock, $request);
		$response = '';
		while(!feof($sock)) $response .= fread($sock, 8192);
		fclose($sock);
	 
		if (preg_match('/^Location: (.+?)$/m', $response, $matches)){
			if ( substr($matches[1], 0, 1) == "/" )
				return $url_parts['scheme'] . "://" . $url_parts['host'] . trim($matches[1]);
			else
				return trim($matches[1]);
	 
		} else {
			return false;
		}
	}
	
	public function getFacebookFriends($criteria='') {
		$name = $criteria['name'];
		
		if($name=='') $name = 'me';
		
		$url = 'https://graph.facebook.com/'.$name.'/friends?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);
		$content = json_decode($content,true);
		
		$users = $this->formatFacebookUsers($content);
		
		return $users;
	}
	
	public function formatFacebookUsers($content) {
		for($i=0; $i<count($content['data']); $i++) {
			$id = $content['data'][$i]['id'];
			$name = $content['data'][$i]['name'];
			
			$picture = 'https://graph.facebook.com/'.$id.'/picture?type=square'; //square, small, large
			$url = 'http://www.facebook.com/profile.php?id='.$id;
			
			$users[$i]['id'] = $id;
			$users[$i]['name'] = $name;
			$users[$i]['picture'] = $picture;
			$users[$i]['url'] = $url;
		}
		return $users;
	}
	
	public function getFacebookAccounts() {
		$url = 'https://graph.facebook.com/me/accounts?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);
		$content = json_decode($content,true);
		return $content;
	}
	
	public function displayUsersIcons($criteria) {
		$users = $criteria['users'];
		$nb_display = $criteria['nb_display'];
		$width = $criteria['width'];
		$privacy = $criteria['privacy'];
		
		if($width=='') $width="30";
		
		if($nb_display>count($users) || $nb_display=='') $nb_display=count($users); //display value never bigger than nb users
		
		$display = '';
		for($i=0;$i<$nb_display;$i++) {
			$name = $users[$i]['name'];
			$picture = $users[$i]['picture'];
			$url = $users[$i]['url'];
			
			if($privacy!=1) $display .= '<a href="'.$url.'" target="_blank" title="'.$name.'">';
			$display .= '<img src="'.$picture.'" width="'.$width.'" style="padding:2px;">';
			if($privacy!=1) $display .= '</a>';
		}
		return $display;
	}
	
	public function getFacebookFeeds() {
		
		$url = 'https://graph.facebook.com/me/posts?access_token='.$this->getAccessToken();
		
		$ch = curl_init();
		$timeout = 5;
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
		$data = curl_exec($ch);
		curl_close($ch);
		
		$data = json_decode($data,true);
		$dataList = $this->formatFacebookPosts($data);
		
		return $dataList;
	}
	
	public function formatFacebookPosts($data) {
		$i=0;
		foreach($data['data'] as $value) {
			$id = $value['id'];
			$from_id = $value['from']['id'];
			$from_name = $value['from']['name'];
			
			$type = $value['type']; //video, link, status, picture, swf
			$message = $value['message'];
			$picture = $value['picture'];
			$link = $value['link'];
			$source = $value['source']; //for videos
			$name = $value['name']; //for videos or links
			$caption = $value['caption']; //for videos (domain name url) or links
			$description = $value['description']; //for videos
			$icon = $value['icon'];
			$created = $value['created_time'];
			$likes_nb = $value['likes'];
			
			$comments = $value['comments']['data']; //(message, created_time)
			$comments_nb = $value['comments']['count'];
			$action_comment = $value['actions'][0]['link'];
			
			$picture_url = 'https://graph.facebook.com/'.$from_id.'/picture';
			$profile_url = 'http://www.facebook.com/profile.php?id='.$from_id;
			
			$attribution = $value['attribution'];
			
			if($type=='status') {
				$dataList[$i]['id'] = $id;
				$dataList[$i]['from_id'] = $from_id;
				$dataList[$i]['from_name'] = $from_name;
				$dataList[$i]['type'] = $type;
				$dataList[$i]['message'] = $message;
				$dataList[$i]['picture'] = $picture;
				$dataList[$i]['link'] = $link;
				$dataList[$i]['source'] = $source;
				$dataList[$i]['name'] = $name;
				$dataList[$i]['caption'] = $caption;
				$dataList[$i]['description'] = $description;
				$dataList[$i]['icon'] = $icon;
				$dataList[$i]['created'] = $created;
				$dataList[$i]['attribution'] = $attribution;
				$dataList[$i]['likes_nb'] = $likes_nb;
				$dataList[$i]['comments'] = $comments;
				$dataList[$i]['comments_nb'] = $comments_nb;
				$dataList[$i]['action_comment'] = $action_comment;
				$dataList[$i]['picture_url'] = $picture_url;
				$dataList[$i]['profile_url'] = $profile_url;
				$i++;	
			}
		}
		return $dataList;
	}
	
	public function updateFacebookStatus($criteria, $token='') {
		$fb_id = $criteria['fb_id'];
		$message = $criteria['message'];
		$link = $criteria['link'];
		$picture = $criteria['picture'];
		$name = $criteria['name'];
		$caption = $criteria['caption'];
		$description = $criteria['description'];
		$source = $criteria['source'];
		
		if($fb_id=='') $fb_id = 'me';
		
		$criteriaString = '&message='.$message;
		if($link!='') $criteriaString .= '&link='.$link;
		if($picture!='') $criteriaString .= '&picture='.$picture;
		if($name!='') $criteriaString .= '&name='.$name;
		if($caption!='') $criteriaString .= '&caption='.$caption;
		if($description!='') $criteriaString .= '&description='.$description;
		if($source!='') $criteriaString .= '&source='.$source;
		
		if($token=='') $token = $this->getAccessToken();
		$postParms = "access_token=".$token.$criteriaString;
		
		$ch = curl_init('https://graph.facebook.com/'.$fb_id.'/feed');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postParms);
		$results = curl_exec($ch);
		curl_close($ch);
	}
	/*****************************************/
	/* Formats album data for easy use in	 */
	/* WP themes and ajax.	                 */
	/*****************************************/
	public function formatFacebookAlbums() {
		$albums 	= array();
		$content 	= $this->getAlbums();
		
		for($i=0; $i<count($content); $i++) {
			$name 			= $content[$i]['name'];
			if ($name == "Wall Photos")	continue;
			$id 			= $content[$i]['id'];
			$coverphoto 	= $this->getPhoto($content[$i]['cover_photo']);
			$url 			= $content[$i]['link'];			
			
			$albums[$i]['id'] = $id;
			$albums[$i]['name'] = $name;
			$albums[$i]['coverphoto'] = $coverphoto;
			$albums[$i]['url'] = $url;
		}
		return $albums;
	}
	
	
	/*****************************************/
	/* Gets an array of Albums From FB Graph */
	/*****************************************
	*/
	public function getAlbums ($criteria='') {
		$name = $criteria['name'];
		
		if($name=='') $name = 'me';
		
		$url = 'https://graph.facebook.com/'.$name.'/albums?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);		
				
		$content = json_decode($content,true);
		
		return $content['data'];
		
	}
	/*****************************************/
	/* retrieves the OG photo object from its*/
	/* ID									 */
	/*****************************************
	*/
	public function getPhoto ($pid) {		
		$url = 'https://graph.facebook.com/'.$pid.'?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);
				
		$results = json_decode($content,true);
						
		if (isset($results['id'])) 
			return $results;
		else 
			throw new Exception ("Error getting photo: " . $results['error_msg']);
		
	}
	/*****************************************/
	/* retrieves an open graph object 		 */
	/* representing an album				 */
	/*****************************************/
	
	public function getAlbum($aid) {
		$url = 'https://graph.facebook.com/'.$aid.'?access_token='.$this->getAccessToken();

		$ogdata = $this->getDataFromUrl($url,0,null,null);		
		$results = json_decode($ogdata,true);
						
		if (isset($results['id'])) 
			return $results;
		else 
			throw new Exception ("Error getting album: " . $results['error_msg']);
	}
	
	/*****************************************/
	/* Retrieves an array of OG photo objects*/
	/* from an album via its graph connection*/
	/*****************************************
	*/
	public function getPhotosFromAlbum($aid) {
		$url = 'https://graph.facebook.com/'.$aid.'/photos?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);
		$results = json_decode($content,true);
						
		if (isset( $results['data'])) 
			return $results['data'];	
		else 
			throw new Exception ("Error getting album photos: " . $results['error_msg']);		
	}
	/*********************************************/
	/* Uploads the image specified in $localpath */
	/* if album_id is not specified in $criteria,*/
	/* the photo will be posted to the user's    */
	/* wall.                                     */
	/*********************************************/
	public function putPhoto ($criteria, $localpath, $token='') {
		$fb_id 		= $criteria['fb_id'];
		$album_id 	= $criteria['album_id'];
		$message 	= $criteria['message'];

		if($fb_id=='') 
			$fb_id 			= 'me';
		if($album_id=='') 
			$destgraphobj	= $fb_id;
		else 
			$destgraphobj	= $album_id;

		if($token=='') 
			$token 			= $this->getAccessToken();

		$postData = array (
			'source' 		=> "@" . realpath($localpath),
			'message' 		=> $message,
			'access_token' 	=> $token
			);


		$ch = curl_init('https://graph.facebook.com/'.$album_id.'/photos');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		$results = curl_exec($ch);
		curl_close($ch);
		$results = json_decode($results, true);
		
		if (isset($results['id'])) 
			return $results['id'];
		else 
			throw new Exception("Error posting photo: " . $results['error_msg']);
	}
	
	/*****************************************/
	/* Creates an album on the user's FB Profile.*/
	/* Returns an Open Graph ID for the album. */
	/*****************************************
	*/
	public function putAlbum ($criteria, $token='') {
		$fb_id 		= $criteria['fb_id'];
		$message 	= $criteria['message'];
		$name 		= $criteria['name'];

		if($fb_id=='') 
			$fb_id 				= 'me';

		$criteriaString 		= '&message='	.$message;
		if($link!='') 
			$criteriaString 	.= '&link='		.$link;
		if($picture!='') 
			$criteriaString 	.= '&picture='	.$picture;
		if($name!='') 
			$criteriaString 	.= '&name='		.$name;
		if($caption!='') 
			$criteriaString 	.= '&caption='	.$caption;
		if($description!='') 
			$criteriaString 	.= '&description='.$description;
		if($source!='') 
			$criteriaString 	.= '&source='	.$source;

		if($token=='') $token = $this->getAccessToken();
		$postParms = "access_token=".$token.$criteriaString;

		$ch = curl_init('https://graph.facebook.com/'.$fb_id.'/albums');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postParms);
		$results = curl_exec($ch);
		curl_close($ch);
		$results = json_decode($results, true);

		if (isset($results['id'])) 
			return $results['id'];
		else 
			throw new Exception ("Error creating album: " . $results['error_msg']);
	}
	/*****************************************/
	/* Gets all comments from an object.     */
	/* This can be anything on FB as long as */
	/* you have its FBID.                    */
	/* Returns an array of OG comment objects*/
	/*****************************************/
	public function getCommentsFromObject($aid, $token='') {
		$url = 'https://graph.facebook.com/'.$aid.'/comments?access_token='.$this->getAccessToken();
		$content = $this->getDataFromUrl($url,0,null,null);
		$results = json_decode($content,true);
	
		if (isset( $results['data'])) 
			return $results['data'];	
		else 
			throw new Exception ("Error getting album comments: " . $results['error_msg']);
	}
	/*****************************************/
	/* Puts a comment on an album.           */
	/* Fails if $aid is not specified        */
	/* Returns an OG ID for the new comment  */
	/*****************************************/
	public function putCommentOnAlbum($criteria, $aid, $token='')  {
		if ($aid == '')
			throw new Exception("Blank album ID specified.  Cannot post comment.");
		$message 	= $criteria['message'];

		$criteriaString 		= '&message='	.$message;

		if($token=='') $token = $this->getAccessToken();
		$postParms = "access_token=".$token.$criteriaString;

		$ch = curl_init('https://graph.facebook.com/'.$aid.'/comments');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postParms);
		$results = curl_exec($ch);
		curl_close($ch);
		$results = json_decode($results, true);
		
		if (isset($results['id'])) 
			return $results['id'];
		else 
			throw new Exception ("Error putting comment: " . $results['error_msg']);
	}
	
	/*****************************************/
	/* Runs an arbitrary FQL query.          */
	/*****************************************/

	public function fqlQuery ($query, $token='', $to_array = true) {
		if($token=='') $token = $this->getAccessToken();
		$url = 'https://graph.facebook.com/fql?q=' . urlencode($query) . '&format=json-strings&access_token=' . $token;
		$content = $this->getDataFromUrl($url,0,null,null);
		$results = json_decode($content, $to_array);
		
		if ( ( $to_array && isset($results['data']) ) || ( ! $to_array && isset( $results->data) ) ) 
			return $to_array ? $results['data'] : $results->data;
		else 
			throw new Exception ("Error executing query: " . $results['error_msg']);		
		
	}
}

?>