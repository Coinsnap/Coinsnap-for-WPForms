<?php
/**
 * Plugin Name:     WPForms Forms Coinsnap Add-On
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Integrates WPForms with Coinsnap.
 * Version:         1.0.0
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-wpforms
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.4.3
 * Requires at least: 5.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */ 

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPFormsCoinsnap\Plugin;

const WPFORMS_COINSNAP_VERSION = '1.0.0';
const WPFORMS_COINSNAP_FILE = __FILE__;

define( 'SERVER_PHP_VERSION', '7.4' );
define( 'COINSNAP_VERSION', '1.0.0' );
define( 'COINSNAP_REFERRAL_CODE', '' );
define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-wpforms' );
define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );
define( 'WPFORMS_COINSNAP_PATH', plugin_dir_path( WPFORMS_COINSNAP_FILE ) );
define( 'WPFORMS_COINSNAP_URL', plugin_dir_url( WPFORMS_COINSNAP_FILE ) );

add_action( 'wpforms_loaded', 'wpforms_coinsnap' );

function wpforms_coinsnap() {
	require_once WPFORMS_COINSNAP_PATH . '/library/autoload.php';	
	require_once WPFORMS_COINSNAP_PATH . '/src/Plugin.php';	
	return Plugin::get_instance();
}
