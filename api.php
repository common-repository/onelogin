<?php

/**
* 
*/
class OneLogin {
	private $providers;
	public $current_user;
	public $options;
	public $connect_action = 'onelogin_connect';
	public $disconnect_action = 'onelogin_disconnect';
	private static $initialized = false;
	private $default_options = array(
		'providers' => array(),
		'onelogin' => array('new_user_role' => '')
	);
	private $option_name = 'onelogin_data';

	public static function getInstance() {
		global $OneLogin;

		if ( ! self::$initialized )  {
			$OneLogin = new self();
		}

		return $OneLogin;
	}

	function __construct() {
		global $wpdb;

		self::$initialized = true;
		$wpdb->onelogin = $wpdb->prefix . 'onelogin';

		$this->default_options['onelogin']['new_user_role'] = get_option('default_role');

		$this->options = wp_parse_args(get_option($this->option_name, $this->default_options), $this->default_options);

		add_action('init', array($this, 'init'), 1);

		// $wpdb->show_errors();
		// $wpdb->query("DELETE FROM $wpdb->onelogin WHERE 1=1");

		// $this->onelogin_activation();
	}

	public function init() {
		$base = dirname(__FILE__) . '/extend/';

		$this->providers = apply_filters('onelogin_providers', array(
			'facebook' => $base . 'facebook.php',
			'google' => $base . 'google.php',
		));

		$this->initialize_providers();

		foreach ($this->providers as $provider => $val) {
			$this->options['providers'][$provider] = ( isset($this->options['providers'][$provider]) )? $this->options['providers'][$provider] : array();
		}

		if ( strpos('onelogin_facebook_channel_file.html', $_SERVER['REQUEST_URI']) !== false ) {
			$cache_expire = 60*60*24*365;
 			header("Pragma: public");
			header("Cache-Control: max-age=".$cache_expire);
			header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$cache_expire) . ' GMT');
			
			echo '<script src="//connect.facebook.net/en_US/all.js"></script>';
			exit;
		}

		if ( isset($_GET['onelogin_print_scripts']) ) {
			header('Content-type: application/javascript');

			foreach ($this->providers as $provider => $file) {
				if ( isset($this->$provider) ) {
					$method = apply_filters('onelogin_print_js_' . $provider . '_method', 'print_js');
					if ( method_exists($this->$provider, $method) ) {
						$this->$provider->$method();
					}
				}
			}
			exit;
		}

		add_action('admin_menu', array($this, 'admin_menu'));

		add_shortcode('onelogin_connect', array($this, 'onelogin_connect_shortcode'));
		add_shortcode('onelogin_disconnect', array($this, 'onelogin_disconnect_shortcode'));

		if ( is_user_logged_in() ) {
			$this->current_user = wp_get_current_user();
			$this->current_user = $this->add_user_data($this->current_user);
		} else {
			$this->current_user = false;
		}

		// Action to remove user meta when a user is deleted
		add_action( 'delete_user', array( $this, 'delete_user_action' ), 11 );

		add_action( 'wp_ajax_nopriv_' . $this->connect_action, array($this,'on_connect') );
		add_action( 'wp_ajax_' . $this->connect_action, array($this,'on_connect') );
		
		add_action( 'wp_ajax_' . $this->disconnect_action, array($this,'on_disconnect') );

		// Registers the admin-side JS, required for OneLogin
		wp_register_script('onelogin-admin-js', plugins_url('js/onelogin-admin.js', __FILE__), array('jquery','media-upload','thickbox'));

		// Registers the admin-side JS, required for OneLogin
		wp_register_style('onelogin-style', plugins_url('css/onelogin-style.css', __FILE__));

		add_action('admin_print_scripts-settings_page_onelogin-config', array('OneLogin', 'enqueue_scripts'));
		add_action('admin_print_styles-settings_page_onelogin-config', array('OneLogin', 'enqueue_styles'));
	}

	public function on_connect() {
		$provider = ( $_REQUEST['provider'] )? $_REQUEST['provider'] : false;
		if ( $provider ) {
			if ( $_REQUEST['redirect_url'] ) {
				$nonce_verified = $this->check_url_nonce($_REQUEST['redirect_url'], $_REQUEST['redirect_nonce']);
			}
			do_action('onelogin_on_' . $provider . '_connect', $nonce_verified);
		}
	}

	public function on_disconnect() {
		$provider = ( $_REQUEST['provider'] )? $_REQUEST['provider'] : false;
		if ( $provider ) {
			do_action('onelogin_on_' . $provider . '_disconnect');
		}
	}

	// Performs a DB query to retrieve all users from the specified provider. 
	// You can optionally specify a secondary key that would determine which row to be returned
	// Otherwise the row that has the provider will be returned(not quite useful)
	public function get_users_from($provider, $secondary_key = false) {
		global $wpdb;

		if ( ! $secondary_key ) {
			return $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->onelogin WHERE 1=1 AND m_key='provider' AND value=%s GROUP BY uid", $provider));
		}
		$sql = "SELECT s1.uid, s1.m_key, s1.value
				FROM $wpdb->onelogin s1
				LEFT JOIN $wpdb->onelogin s2 ON s1.uid WHERE s2.m_key = 'provider' AND s2.value = %s AND s1.uid = s2.uid AND s1.m_key = %s GROUP BY s1.uid";

		return $wpdb->get_results($wpdb->prepare($sql, $provider, $secondary_key));
	}

	public function add_umeta($uid, $key, $value = '', $append = false) {
		global $wpdb;

		$result = false;

		$m_id = ( $append === false )? $this->umeta_exists($uid, $key) : null;
		if ( ! is_null($m_id) ) {
			$result = $wpdb->update($wpdb->onelogin, array('value' => $value), array('m_id' => $m_id), array('%s'), array('%d'));
		} else {
			$result = $wpdb->query($wpdb->prepare("INSERT INTO $wpdb->onelogin (uid, m_key, value) VALUES (%d, %s, %s)", $uid, $key, $value));
		}
		if ( $result && $uid == $this->current_user->ID ) {
			$this->umeta_updated($key, $value);
		}

		return $result;
	}

	/**
	* Checks if a OneLogin user meta exists
	*
	* @access public
	*
	* @param mixed $uid WordPress User ID for which to search for meta, false to check if meta key exists
	* @param string $key Meta key for which to check
	* @param string $value Optional - will match against the value
	*
	* @return null/integer Null if meta doesn't exist, meta ID otherwise
	*/
	public function umeta_exists($uid, $key, $value = null) {
		global $wpdb;

		if ( $uid === false ) {
			return $wpdb->get_var($wpdb->prepare("SELECT m_id FROM $wpdb->onelogin WHERE m_key=%s AND value=%s", $key, $value));
		} else {
			$sql = "SELECT m_id FROM $wpdb->onelogin WHERE uid=%d AND m_key=%s";
			$sql .= ( ! is_null($value) && $value != '' )? " AND value=%s" : '';
			$sql = $wpdb->prepare($sql, $uid, $key, $value);
			return $wpdb->get_var($sql);
		}
	}

	/**
	* Gets WordPress User ID by meta key
	*
	* @access public
	*
	* @param string $key Meta key for which to check
	* @param string $value Optional - will match against the value
	* 
	* @return null/integer Null if meta doesn't exist, User ID otherwise
	*/
	public function get_uid_by_meta($key, $value = null) {
		global $wpdb;

		$wpdb->show_errors();

		$sql = "SELECT uid FROM $wpdb->onelogin WHERE m_key=%s";
		$sql .= ( ! is_null($value) && $value != '' )? " AND value=%s" : '';

		return $wpdb->get_var($wpdb->prepare($sql, $key, $value));
	}

	/**
	* Gets OneLogin User meta for specified user/user and key
	*
	* @access public
	*
	* @param string $key Optional - Meta key for which to check
	* @param string $uid Optional - User ID, defaults to current user
	* 
	* @return boolean/array False if no user, Array of meta records otherwise
	*/
	public function get_umeta($key = false, $uid = false) {
		$uid = ( $uid !== false )? $uid : ( $this->current_user ? $this->current_user->ID : false );

		if ( ! $uid ) {
			return false;
		}
		global $wpdb;
		$sql = "SELECT * FROM $wpdb->onelogin WHERE uid=%d";
		if ( $key ) {
			return $wpdb->get_results($wpdb->prepare($sql . " AND m_key=%s", $uid, $key));
		} else {
			return $wpdb->get_results($wpdb->prepare($sql, $uid));
		}
	}

	/**
	* Deletes OneLogin User meta record
	*
	* @access public
	*
	* @param integer $uid WordPress User ID
	* @param string|array $key Meta key for which to check
	* @param string $value Optional - will match against the value
	* 
	* @return boolean True on success, false otherwise(including when meta doesn't exist)
	*/
	public function delete_umeta($uid, $key = null, $value = null) {
		global $wpdb;

		$result = false;

		if ( ! is_null($key) ) {
			if ( is_array($key) ) {
				foreach ($key as $_key) {
					$this->delete_umeta($uid, $_key, $value);
				}
			} else {
				$sql = "SELECT m_id FROM $wpdb->onelogin WHERE uid=%d AND m_key=%s";
				$sql .= ( ! is_null($value) )? " AND value=%s" : '';

				$m_id = $wpdb->get_var($wpdb->prepare($sql, $uid, $key, $value));
				if ( ! is_null($m_id) ) {
					$result = $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->onelogin WHERE m_id=%d", $m_id));
				}
			}
		} else {
			// Delete all umeta associated with this user
			$result = $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->onelogin WHERE uid=%d", $uid));
		}

		if ( $result && $this->current_user->ID == $uid ) {
			$this->umeta_updated($key, '', true);
		}
		return $result;
	}

	/**
	* Deletes 
	* 
	*/
	public function delete_user_action($delete_id) {
		$this->delete_umeta($delete_id);
	}

	/**
	* Registers OneLogin's options page
	*
	* @access public
	*/
	public function admin_menu() {
		add_submenu_page('options-general.php', 'OneLogin', 'OneLogin', 'manage_options', 'onelogin-config', array($this, 'admin_page'));
	}

	/**
	* Renders OneLogin's options page
	* 
	* @access public
	* 
	* @uses do_action() Calls 'onelogin_before_providers_admin_opts' hook before rendering any options.
	* @uses do_action() Calls 'onelogin_after_provider_{$provider}_admin_opts' hook after rendering {$provider} options.
	* @uses do_action() Calls 'onelogin_after_providers_admin_opts' hook after rendering all options, before submit button.
	*/
	public function admin_page() { ?>
		<div class="wrap">
			<div class="icon32" id="icon-options-general"><br></div><h2>OneLogin Settings</h2>
			<?php 
			if ( $_SERVER['REQUEST_METHOD'] == 'POST' && wp_verify_nonce($_POST['_onelogin_nonce'], 'onelogin_' . __FILE__) ) {
				$new_opts = $_POST['onelogin_data'];
				foreach ($this->providers as $provider => $path) {
					$new_opts['providers'][$provider] = apply_filters('onelogin_save_' . $provider . '_provider_data', $new_opts['providers'][$provider]);
				}
				foreach ($this->options['onelogin'] as $key => $value) {
					$this->options['onelogin'][$key] = $new_opts[$key];
				}

				// Let's allow the change of the general provider's options and not all of the plugin's options :)
				$new_opts['providers'] = apply_filters('onelogin_save_providers_data', $new_opts['providers']);
				$this->options = $new_opts;
				update_option('onelogin_data', $new_opts);
			} ?>
			<form action="" method="post">
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th colspan="2"><h3>General Settings</h3></th>
						</tr>
						<tr valign="top">
							<th><label for="new_user_role">New User Default Role</label></th>
							<td>
								<select name="onelogin_data[onelogin][new_user_role]" id="new_user_role"><?php wp_dropdown_roles($this->options['onelogin']['new_user_role']); ?></select>
								<p class="help">This will be the default role for new users registered through one of the services.</p>
							</td>
						</tr>
						<?php do_action('onelogin_before_providers_admin_opts'); ?>
						<?php if ( ! empty($this->providers) ) : ?>
							<?php foreach ($this->providers as $provider => $file) :
								if ( ! isset($this->$provider) || ! isset($this->$provider->admin_options) || empty($this->$provider->admin_options) ) {
								 	continue;
								}
								$options = $this->$provider->admin_options; ?>
								<tr valign="top">
									<th colspan="2"><h3><?php echo ucwords($provider); ?> settings</h3></th>
								</tr>
								<?php foreach ($options as $key => $settings) :
									$name = "onelogin_data[providers][$provider][$key]";

									if ( isset($settings['type']) && $settings['type'] == 'callback' && is_callable($settings['callback']) ) :
										call_user_func_array($settings['callback'], array($name, $key));
									else :
										$type = ( isset($settings['type']) )? $settings['type'] : 'text';
										$label = ( isset($settings['label']) )? $settings['label'] : ucwords(str_replace(array('-', '_'), ' ', $key));
										$help = ( isset($settings['help']) )? '<p class="help">' . $settings['help'] . '</p>' : '';
										$value = ( isset($this->options['providers'][$provider][$key]) )? $this->options['providers'][$provider][$key] : '';
										$id = 'provider_' . $provider . '_setting_' . $key; ?>
										<tr valign="top">
											<th><label for="<?php echo $id; ?>"><?php echo $label; ?></label></th>
											<td>
												<?php switch ($type) {
													case 'textarea':
														$rows = ( isset($settings['rows']) )? $settings['rows'] : 5;
														echo '<textarea class="large-text" id="' . $id . '" rows="' . $rows . '" name="' . $name . '">' . esc_textarea($value) . '</textarea>';
														break;
													
													case 'wysiwyg':
														if ( function_exists('wp_editor') ) {
															wp_editor($value, $name, array('media_buttons' => true));
														} else {
															the_editor($value, $name, 'title', true);
														}
														break;

													case 'select': 
														$opts = ( isset($settings['options']) )? $settings['options'] : array();
														echo '<select class="field" name="' . $name . '" id="' . $id . '">';

														foreach ($opts as $key => $value) {
															echo '<option value="' . $key . '">' . $value . '</option>';
														}

														echo '</select>';
														break;

													case 'media':
													default:
														$text = ( isset($settings['text']) )? $settings['text'] : _('Upload/Select a File');
														echo '<input type="text" class="widefat onelogin-media-field" value="' . esc_attr($value) . '" id="' . $id . '" name="' . $name . '"><button type="button" class="onelogin-media button-primary">' . $text . '</button>';
														break;

													case 'text':
													default:
														echo '<input type="text" class="widefat" value="' . esc_attr($value) . '" id="' . $id . '" name="' . $name . '">';
														break;
												}
												echo ( isset($settings['help']) ) ? '<p class="help">' . nl2br($settings['help']) . '</p>' : ''; ?>
											</td>
										</tr>
									<?php 
									endif;
								endforeach; ?>
								<?php do_action('onelogin_after_provider_' . $provider . '_admin_opts'); ?>
							<?php endforeach ?>
						<?php endif; ?>
						<?php do_action('onelogin_after_providers_admin_opts'); ?>
					</tbody>
				</table>
				<?php wp_nonce_field('onelogin_' . __FILE__, '_onelogin_nonce'); ?>
				<input type="hidden" name="onelogin_action" value="save_options" />
				<p class="submit"><input type="submit" value="Save Changes" class="button-primary" id="submit" name="submit" /></p>
			</form>
		
		</div>
		<?php
	}

	/**
	* Enqueues the required JavaScript files
	*
	* @access public
	* 
	* @uses wp_enqueue_script() Enqueues the "onelogin-admin-js" script
	*/
	public static function enqueue_scripts() {
		wp_enqueue_script('onelogin-admin-js');
	}

	/**
	* Enqueues the required CSS files
	*
	* @access public
	* 
	* @uses wp_enqueue_script() Enqueues the "onelogin-style" css
	*/
	public static function enqueue_styles() {
		wp_enqueue_style('onelogin-style');
		wp_enqueue_style('thickbox');
	}

	/**
	* Returns options for specified provider
	*
	* @access public
	*
	* @param string $provider Provider for which to retrieve options
	* 
	* @return array Array of provider's options, possibly empty if provider has no options
	*/
	public function get_provider_options($provider) {
		return ( isset($this->options['providers'][$provider]) )? $this->options['providers'][$provider] : array();
	}

	// $option must be a key/value array
	// $add is only whether to add that option/s, or to delete all others first
	// If a key in the $option already exists, it will be overwritten, 
	// unless the value is an array and $add is true - merging will occur then
	/**
	* Updates options for specified provider
	*
	* @access public
	*
	* @param string $provider Provider for which to update options
	* @param array $new_option Key/Value array
	* @param boolean $add Whether to append $new_option or delete all options. Defaults to true - new keys are appended, if a key is an array, they are merged
	* Otherwise the new value is used
	* 
	* @return array Updated options
	*/
	public function update_provider_options($provider, $new_option, $add = true) {
		$options = ( $add )? $this->get_provider_options($provider) : array();

		foreach ($new_option as $key => $value) {
			$options[$key] = ( isset($options[$key]) && is_array($value) && $add )? array_merge((array) $options[$key], $value) : $value;
		}

		$this->options['providers'][$provider] = $options;
		update_option('onelogin_data', $this->options);

		return $options;
	}

	/**
	* Creates an authentication link for the specified provider
	*
	* @access public
	*
	* @param string $provider Provider for which to create Authentication link
	* @param string $additional_data Optional - any additional data, that should be passed to the provider's method
	* @param string $text Optional - text for the authenticate link
	* @param string $redirect_url Optional - where to redirect after a successfull authentication
	* 
	* @uses apply_filters() Calls 'onelogin_auth_url_{$proider}_method' filter for modifying the provider's auth_url method.
	* 
	* @return string Authentication <a> tag, or empty string on failure
	*/
	public function create_auth_url($provider, $additional_data = '', $text = null, $redirect_url = false) {
		if ( $this->$provider ) {
			$method = apply_filters('onelogin_auth_url_' . $proider . '_method', 'auth_url');
			if ( $redirect_url ) {
				$redirect_url = array('redirect_url' => $redirect_url, 'nonce' => $this->create_url_nonce($redirect_url));
			}
			return ( method_exists($this->$provider, $method) )? $this->$provider->$method($additional_data, $text, $redirect_url) : '';
		}
		return '';
	}

	/**
	* Creates a disconnect link for the specified provider
	*
	* @access public
	*
	* @param string $provider Provider for which to create Authentication URL
	* @param string $text Optional - text for the disconnect link
	* 
	* @uses apply_filters() Calls 'onelogin_disconnect_url_{$proider}_method' filter for modifying the provider's disconnect_url method.
	* 
	* @return string Disconnect <a> tag, or empty string on failure
	*/
	public function create_disconnect_url($provider, $text = '', $additional_data = '') {
		if ( $this->$provider ) {
			$method = apply_filters('onelogin_disconnect_url_' . $proider . '_method', 'disconnect_url');
			return ( method_exists($this->$provider, $method) )? $this->$provider->$method($text, $additional_data) : '';
		}
		return '';
	}

	/**
	* Initializes all available providers
	*
	* @access private
	*
	* @uses do_action() Calls 'onelogin_providers_initalized' hook after all providers have been initialized.
	* @uses OneLogin::initialize_provider()
	*/
	private function initialize_providers() {
		foreach ($this->providers as $provider => $path) {
			$this->initialize_provider($provider, $path);
		}
		do_action('onelogin_providers_initalized');
	}

	/**
	* Initializes the specified provider
	*
	* @access private
	*
	* @param string $provider Provider key
	* @param string $path Optional path to the provider's initializing file, will look in OneLogin::$providers[$provider] if not provided
	* 
	* @uses apply_filters() Calls 'onelogin_provider_class_{$provider}' filter for changing the provider's class name. Defaults to 'OneLoginProvider_{ucwords($provider)}'.
	*/
	private function initialize_provider($provider, $path = null) {
		// Already initialized
		if ( isset($this->$provider) ) {
			return;
		}
		$path = ( ! is_null($path) )? $path : $this->providers[$provider];
		if ( file_exists($path) ) {
			require_once($path);
			// Allow plugins to define their classess in another fashion
			$class = apply_filters('onelogin_provider_class_' . $provider, 'OneLoginProvider_' . ucwords($provider), $provider);
			if ( class_exists($class) ) {
				$this->$provider = ( isset($this->$provider) )? $this->$provider : new $class();
			}
		}
	}

	/**
	* Checks if current user has connected their account with the specified provider/s
	*
	* @access public
	*
	* @param string/array $providers One or multiple providers for which to check
	* 
	* @return boolean False if user is not logged in, or has not connected to any of the services, true otherwise
	*/
	public function is_user_from($providers) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$providers = ( is_array($providers) )? $providers : array($providers);
		// If the user has connected the account to ANY of the providers in question
		foreach ($providers as $provider) {
			if ( array_search($provider, $this->current_user->providers) !== false ) {
				return true;
			}
		}

		return false;
	}

	public function is_connected($provider) {
		$this->initialize_provider($provider);
		$method = apply_filters('onelogin_is_connected_' . $provider . '_method', 'is_connected');
		return ( method_exists($this->$provider, $method) )? (boolean) $this->is_user_from($provider) && $this->$provider->$method() : -1;
	}

	/**
	* Connects the current user with a specific service
	*
	* @access public
	* @param String $provider Provider key
	* @param Array (optional) $metadata Additional metadata to bind to the user
	*
	* @uses do_action() Calls "onelogin_user_connected_{$provider}" action to notify, that a user has connected their account with the specific provider
	*/
	public function connect_user($provider, $metadata = array(), $uid = false) {
		$uid = $uid ? $uid : $this->current_user->ID;

		if ( is_null($this->umeta_exists($uid, 'provider', $provider)) ) {
			$this->add_umeta($uid, 'provider', $provider, true);
		}
		foreach ($metadata as $key => $value) {
			$this->add_umeta($uid, $key, $value);
		}

		do_action('onelogin_user_connected_' . $provider, $uid, $metadata);

		if ( ! is_user_logged_in() ) {
			$this->connect_the_user($uid);
		}
	}

	/**
	* Connects the current user with a specific service
	*
	* @access public
	* @param String $provider Provider key
	* @param Array (optional) $metadata Additional metadata to bind to the user
	*
	* @uses do_action() Calls "onelogin_user_connected_{$provider}" action to notify, that a user has connected their account with the specific provider
	*/
	public function create_wp_user($user_data, $provider, $metadata = array()) {
		if ( ! email_exists($user_data['user_email']) ) {
			$this->check_user_data($user_data, $provider);
			$user_data['user_pass'] = $user_data['user_pass'] ? $user_data['user_pass'] : wp_generate_password();
			$user_data['role'] = $user_data['role'] ? $user_data['role'] : get_option('default_role');

			$wp_id = wp_insert_user($user_data);
		} else {
			$user = get_user_by('email', $user_data['user_email']);
			$wp_id = $user->ID;
		}

		if ( $wp_id && ! is_wp_error($wp_id) ) {
			$this->connect_user($provider, $metadata, $wp_id);
			return true;
		}
		return $wp_id;
	}

	public function connect_the_user($uid) {
		wp_set_auth_cookie($uid, true, false);
	}

	public function get_user_providers($uid = false) {
		if ( $uid ) {
			
		} else {
			return $this->current_user ? $this->current_user->providers : array();
		}
	}

	public function get_userinfo($user_id = null) {
		if ( is_null($user_id) ) {
			return $this->current_user;
		} else {
			return $this->add_user_data(get_userdata($user_id));
		}
	}

	public function check_user_data(& $data, $provider) {
		require_once(ABSPATH . WPINC . '/registration.php');
		// $valid_username_email = $this->get_valid_username_email(array('username'=>$username, 'name'=>$fb_name, 'email'=>$fb_email, 'user_id'=>$fb_id));
		
		// $username = $valid_username_email['username'];
		// $fb_email = $valid_username_email['email'];

		$username = $data['user_login'];
		$email = $data['user_email'];

		$name = $data['first_name'] . ' ' . $data['last_name'];
		// $user_id = $criteria['user_id'];
				
		
		$username = ( $username != '' )? str_replace('.','_',$username) : $username;
		
		if ( $username == '' || username_exists($username) ) {
			$username = $this->format_username($name);
		}
		
		if( $username != '' && username_exists($username) ) {
			for($i=1; $i<10; $i++) {
				$tmp_username = $username . '_' . $i;
				if ( ! username_exists($tmp_username) ) {
					$username = $tmp_username;
					break;
				}
			}
		} elseif ( $username == '' ) {
			for( $i=1; $i<10; $i++ ) {
				$tmp_username = $provider . uniqid() . '_' . $i;
				if( ! username_exists($tmp_username) ) {
					$username = $tmp_username;
					break;
				}
			}
		}
		
		//email treatments
		if(email_exists($email) || $email=='') {
			$email = $username . '@' . $_SERVER['SERVER_NAME'] . '.fake';
			$email = str_replace('_','',$email);
		}
		
		$data['user_login'] = $username;
		$data['user_email'] = $email;
	}

	function format_username($username) {
		//format the user nicename (until finding a better way...)
		$encoding = mb_detect_encoding($username, 'auto');
		if($encoding=='UTF-8') $username = utf8_decode($username);
	    $a = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿ';
	    $b = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyyby';
	    $username = strtr($username, $a, $b);
	    $username = strtolower($username);
	    $username = eregi_replace("[^a-z0-9]",' ',$username);
		$username = trim($username);
		$username = preg_replace("/ -+/","_",$username);
		$username = preg_replace("/- +/","_",$username);
		$username = preg_replace("/ +/","_",$username);
		$username = str_replace('.','_',$username);
		return $username;
	}
	
	// Add the metadata for this user - connected providers, emails, etc
	function add_user_data($user) {
		$prov_meta = $this->get_umeta('provider');
		$prov_meta = ( $prov_meta )? $prov_meta : array();

		$providers = array();
		foreach ($prov_meta as $data) {
			$providers[] = $data->value;
		}
		$user->providers = $providers;

		return apply_filters('onelogin_add_user_data', $user);
	}

	function umeta_updated($key, $value, $delete = false) {
		if ( $delete == false ) {
			if ( isset($this->current_user->$key) && is_array($this->current_user->$key) ) {
				// var_dump($this->current_user->$key, $key);
				// Not a good way, but we would have to make another query to check the old value
				array_push($this->current_user->$key, $value);
			} else {
				$this->current_user->$key = $value;
			}
		} else {
			if ( isset($this->current_user->$key) && ! is_array($this->current_user->$key) ) {
				unset($this->current_user->$key);
			}
		}
	}

	public function onelogin_activation() {
		global $wpdb;
		$wpdb->onelogin = $wpdb->prefix . 'onelogin';

		$charset_collate = '';
		if ( ! empty($wpdb->charset) )
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty($wpdb->collate) )
			$charset_collate .= " COLLATE $wpdb->collate";

		// Has the same structure(different column names though) as the WordPress usermeta table
		$sql = "CREATE TABLE $wpdb->onelogin (
			m_id bigint(20) unsigned NOT NULL auto_increment,
			uid bigint(20) unsigned NOT NULL default '0',
			m_key varchar(255) default NULL,
			value longtext,
			PRIMARY KEY (m_id),
			KEY uid (uid),
			KEY m_key (m_key)
		) $charset_collate;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	public function onelogin_connect_shortcode($atts, $content = null) {
		extract(shortcode_atts(array(
			'provider' => false,
			'connected' => 'You are already connected.',
			'additional_data' => '',
			'text' => null,
			'redirect_url' => null,
		), $atts));
		if ( ! $provider || $provider == '' ) {
			return '';
		}
		$is_connected = $this->is_connected($provider);

		return ( $is_connected && $is_connected !== -1 )? apply_filters('the_content', $connected) : $this->create_auth_url($provider, $additional_data, $text, $redirect_url);
	}

	public function onelogin_disconnect_shortcode($atts, $content = null) {
		extract(shortcode_atts(array(
			'provider' => false,
			'text' => null,
			'additional_data' => '',
		), $atts));
		if ( ! $provider || $provider == '' ) {
			return '';
		}

		return $this->create_disconnect_url($provider, $text, $additional_data);
	}

	/**
	* Creates a nonce for a specific URL
	*
	* @access public
	* @param String $url - the URL for which to create nonce
	*
	* @return String - the generated nonce
	**/
	public function create_url_nonce($url) {
		return wp_create_nonce('OneLogin_url_nonce_' . $url);
	}

	/**
	* Checks if a nonce created from OneLogin::create_url_nonce() is correct
	*
	* @access public
	* @param String $url - the URL for which the nonce was created
	* @param String $url - nonce to check against
	*
	* @return Boolean - whether the nonce passed the test
	**/
	public function check_url_nonce($url, $nonce) {
		return wp_verify_nonce($nonce, 'OneLogin_url_nonce_' . $url);
	}
}




function onelogin_init() {
	global $OneLogin;
	$OneLogin = new OneLogin();
	//if it's in the admin section
	if(is_admin()) {
		//create table on plugin activation (if table doesn't exist)
		register_activation_hook(__FILE__, array($OneLogin, 'onelogin_activation'));
	}

}
add_action('init', 'onelogin_init', 0);

function onelogin_auth_url($provider, $additional_data = '', $text = '', $redirect_url = null) {
	return OneLogin::getInstance()->create_auth_url($provider, $additional_data, $text, $redirect_url);
}

function onelogin_userinfo($user_id = null) {
	return OneLogin::getInstance()->get_userinfo($user_id);
}

function onelogin_is_user_from($providers) {
	return OneLogin::getInstance()->is_user_from($providers);
}

function onelogin_is_connected($provider) {
	return OneLogin::getInstance()->is_connected($provider);
}

function onelogin_get_providers() {
	return OneLogin::getInstance()->get_user_providers();
}


?>