<?php
/*
Plugin Name: OneLogin Single Sign-on
Description: Provide one click single sign-on login and automatic registration support for your user's Facebook account. OneLogin is a decentralized framework that comes with support for Facebook. Any oAuth service provider could be used with minimal effort.
Version: 1.0
Author: Matt Taylor, Nikola Nikolov
Author URI: http://onephotoapp.com
License: GPL2
*/

	if( !defined('ONELOGIN_DEBUG') )
		@define('ONELOGIN_DEBUG', false);

	require_once('api.php');
