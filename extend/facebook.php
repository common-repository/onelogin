<?php 

/**
* 
*/
class OneLoginProvider_Facebook {
	private $facebook;
	private $options;
	public $admin_options;
	public $allowed_persmisions = array();

	public function __construct() {
		// echo 'fsfs';
		include_once(dirname(__FILE__) . '/facebook/Facebook.class.php');
		$this->options = OneLogin::getInstance()->get_provider_options('facebook');

		$default_perms = ( isset($this->options['default_perms']) )? $this->options['default_perms'] : '';

		$this->facebook = ( $this->options['app_id'] && $this->options['app_id'] != '' && $this->options['app_secret'] && $this->options['app_secret'] != '' )? new Facebook_class($this->options['app_id'], $this->options['app_secret'], $default_perms) : false;
		// Notify the administrator, that the plugin won't work properly, but don't break back-end functionallity
		if ( ! $this->facebook && current_user_can('administrator') && ! is_admin() ) {
			echo '<strong style="color: red;">Please enter your Facebook APP ID/Secret in order to use the OneLogin Facebook Provider.</strong>';
		}

		if ( is_admin() ) {
			$this->admin_options = array(
				'app_id' => array( // the key is the way this option will be accessed in the array returned from "OneLogin->get_provider_options()"
					'help' => 'Enter your Facebook APP ID in this field. If you don\'t have a Facebook APP, go and <a href="https://developers.facebook.com/apps" target="_blank">create one</a> - it\'s easy!',
					'type' => 'text', //this is the default, other types: "textarea", "wysiwyg", "select"(requires "options" array) and "media"
					'label' => 'APP ID', //this defaults to {ucwords(str_replace(array('-', '_'), ' ', $key))}
				),
				'app_secret' => array(), // you can also set empty array and the default values will be used
				'default_perms' => array(
					'help' => 'What should be the default permissions, that your application requires? Check with the <a href="https://developers.facebook.com/docs/reference/api/permissions/" target="_blank">Permissions article</a> at Facebook Developers documentation, to learn more about the available permissions and what each of them allows. This can be changed on a per-shortcode basis, using the <code>additional_data</code> parameter.',
					'label' => 'Default Permissions(scope)',
				),
				'connect_img' => array(
					'type' => 'media',
					'help' => 'Upload or select an image for the <code>[onelogin_connect provider="facebook"]</code> shortcode. If you select an image here, only it will be displayed and the text option will be used for the <code>alt</code> and <code>title</code> attributes.',
					'label' => 'Connect Button Image'
				)
			);
		} else {
			if ( $this->facebook ) {
				add_action('wp_footer', array($this->facebook, 'loadJsSDK'));
			}
		}

		add_action( 'onelogin_on_facebook_connect', array($this,'on_fb_connect') );
		add_action( 'onelogin_on_facebook_disconnect', array($this,'on_fb_disconnect') );

		$this->allowed_persmisions = apply_filters('onelogin_facebook_allowed_permissions', array('user_about_me', 'friends_about_me', 'user_activities', 'friends_activities', 'user_birthday', 'friends_birthday', 'user_checkins', 'friends_checkins', 'user_education_history', 'friends_education_history', 'user_events', 'friends_events', 'user_groups', 'friends_groups', 'user_hometown', 'friends_hometown', 'user_interests', 'friends_interests', 'user_likes', 'friends_likes', 'user_location', 'friends_location', 'user_notes', 'friends_notes', 'user_photo_video_tags', 'friends_photo_video_tags', 'user_photos', 'friends_photos', 'user_questions', 'friends_questions', 'user_relationships', 'friends_relationships', 'user_relationship_details', 'friends_relationship_details', 'user_religion_politics', 'friends_religion_politics', 'user_status', 'friends_status', 'user_videos', 'friends_videos', 'user_website', 'friends_website', 'user_work_history', 'friends_work_history', 'email', 'read_friendlists', 'read_insights', 'read_mailbox', 'read_requests', 'read_stream', 'xmpp_login', 'ads_management', 'create_event', 'manage_friendlists', 'manage_notifications', 'offline_access', 'user_online_presence', 'friends_online_presence', 'publish_checkins', 'publish_stream', 'rsvp_event', 'publish_actions', 'manage_pages'));
	}

	public function auth_url($add_permissions = '', $text = false, $redirect_url = false) {
		$text = ( $text )? $text : 'Facebook connect';
		$text = ( $this->options['connect_img'] )? '<img src="' . $this->options['connect_img'] . '" alt="' . $text . '" title="' . $text . '" />' : $text;

		$add_permissions = $this->sanitize_permissions($add_permissions);
		if ( ! $this->facebook ) {
			return ( current_user_can('administrator') )? 'Please enter your Facebook APP ID and APP Secret in the admin panel.' : '';
		}
		if ( onelogin_is_user_from('facebook') && $this->is_connected() ) {
			return apply_filters('onelogin_user_connected_facebook_auth_url', '');
		}
		return apply_filters('onelogin_facebook_auth_url', $this->facebook->displayLoginButton($add_permissions, $text, false, $redirect_url));
	}

	public function disconnect_url($text = false, $leave = '') {
		$text = ( $text )? $text : 'Facebook disconnect';
		if ( ! $this->facebook ) {
			return ( current_user_can('administrator') )? 'Please enter your Facebook APP ID and APP Secret in the admin panel.' : '';
		}
		if ( ! onelogin_is_user_from('facebook') ) {
			return apply_filters('onelogin_unc_facebook_disconnect_url', '');
		}
		return apply_filters('onelogin_facebook_disconnect_url', $this->facebook->displayDisconnectButton($text, $leave));
	}

	public function is_connected() {
		return (bool) $this->facebook && $this->facebook->getCookie();
	}

	public function print_js() {
		
	}

	public function sanitize_permissions($permissions) {
		if ( $permissions != '' ) {
			$permissions = array_map('trim', explode(',', $permissions));
			foreach ($permissions as $i => $perm) {
				if ( ! in_array($perm, $this->allowed_persmisions) ) {
					unset($permissions[$i]);
				}
			}

			$permissions = implode(',', $permissions);
		}
		return $permissions;
	}

	public function on_fb_logout() {
		require_once(ABSPATH . WPINC . '/pluggable.php');
		wp_logout();
	}

	public function on_fb_disconnect() {
		$leave = ( $_REQUEST['leave'] )? $_REQUEST['leave'] : '';
		$fb_id = OneLogin::getInstance()->get_umeta('fb_id');
		if ( ! $fb_id ) {
			return;
		}
		do_action('onelogin_fb_disconnect', $fb_id);
		if ( $leave == 'nothing' ) {
			do_action('onelogin_fb_disconnect_complete', $fb_id);
			OneLogin::getInstance()->delete_umeta(get_current_user_id(), array('fb_id', 'fb_token', 'fb_token_expires'));
			OneLogin::getInstance()->delete_umeta(get_current_user_id(), 'provider', 'facebook');
			$this->facebook->destroySession();
		}
	}

	public function on_fb_connect($nonce_verified = false) {
		global $OneLogin;

		$fb_cookie = $this->facebook->getCookie();

		//user is connected with WordPress
		if ( is_user_logged_in() ) {
			if ( $fb_cookie!='' ) {
				$user_data = $this->facebook->getUserData();
				$OneLogin->connect_user('facebook', array('fb_id' => $user_data['id'], 'fb_token' => $user_data['token'], 'fb_token_expires' => $user_data['token_expires']));
			}
		} else { //the user is not connected with WP
			//if Facebook session detected
			if($fb_cookie!='') {

				$user_data = $this->facebook->getUserData();

				$fb_id = $user_data['id'];
				$fb_token = $user_data['token'];
				$fb_token_expires = $user_data['token_expires'];
				
				$wp_id = $OneLogin->get_uid_by_meta('fb_id', $fb_id);

				if ( ! get_userdata($wp_id) /* the user could have been deleted */ ) {
					// Delete all the meta, and set the id to null
					$OneLogin->delete_umeta($wp_id); 
					$wp_id = null;
				}
				
				//FB user found => update some of his data and connect him
				if(! is_null($wp_id) ) {
					$OneLogin->add_umeta($wp_id, 'fb_token', $fb_token);
					$OneLogin->add_umeta($wp_id, 'fb_token_expires', $fb_token_expires);

					$OneLogin->connect_the_user($wp_id);
				} else { //create the user
					$wp_user_data = array(
						'user_login' => $user_data['username'],
						'user_pass' => wp_generate_password(),
						'user_email' => $user_data['email'],
						'first_name' => $user_data['first_name'],
						'last_name' => $user_data['last_name'],
						'user_url' => '',
						'role' => get_option('default_role'),
					);
					
					$OneLogin->create_wp_user($wp_user_data, 'facebook', array('fb_id' => $user_data['id'], 'fb_token' => $user_data['token'], 'fb_token_expires' => $user_data['token_expires']));
				}
			} else {
				echo json_encode(array('error' => 'An error happened'));
				exit;
			}
		}
		echo json_encode(array('url_nonce_correct' => $nonce_verified));
		
		exit;
	}

	public function get_fb_api() {
		return $this->facebook;
	}

	public function get_fb_id($uid = null) {
		$user = OneLogin::getInstance()->get_userinfo($uid);
		if ( $user ) {
			return OneLogin::getInstance()->get_umeta('fb_id', $user->ID);
		}
		return false;
	}

	public function get_connected_users($criteria=array()) {
		global $wpdb;
		
		$fb_token_expires = $criteria['fb_token_expires'];
		
		$fb_token_expires = ( $fb_token_expires )? $fb_token_expires : 0;
		
		$now = time();
		$sql = "SELECT * FROM $wpdb->onelogin WHERE m_key = 'fb_token_expires' AND (value=%s OR CAST(value AS SIGNED)>%d) ";

		$results = $wpdb->get_results($wpdb->prepare($sql, $fb_token_expires, $now));
		
		for($i=0;$i<count($results);$i++) {
			$data[$i]['fb_token'] = $results[$i]->fb_token;
			$data[$i]['fb_token_expires'] = $results[$i]->fb_token_expires;
		}
		return $data;
	}

	public function get_all_connected_users() {
		global $wpdb;

		$results = OneLogin::getInstance()->get_users_from('facebook', 'fb_id');
		$data = array();
		
		for($i=0;$i<count($results);$i++) {
			$data[$i]['fb_id'] = $results[$i]->value;
			// $data[$i]['name'] = $results[$i]->fb_name;
			// $data[$i]['email'] = $results[$i]->fb_email;
			$data[$i]['picture'] = 'https://graph.facebook.com/'.$results[$i]->value.'/picture';
			$data[$i]['url'] = 'https://www.facebook.com/profile.php?id='.$results[$i]->value;
			$data[$i]['fb_token'] = $results[$i]->fb_token;
			$data[$i]['fb_token_expires'] = $results[$i]->fb_token_expires;
			// $data[$i]['created'] = $results[$i]->created;
		}
		return $data;
	}
}


 ?>