<?php 

if( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
    exit('Chetin\', huh? You got nothing to do here.');

global $wpdb;

$tablename = ( isset($wpdb->onelogin) )? $wpdb->onelogin : $wpdb->prefix . 'onelogin';

$wpdb->query("DROP TABLE IF EXISTS $tablename");
delete_option('onelogin_data');
