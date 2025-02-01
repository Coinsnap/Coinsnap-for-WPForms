<?php
/**
 * Plugin Name:     Bitcoin payment for WPForms
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Sell products, downloads, bookings for Bitcoin or get Bitcoin-donations in any form you created with WPForms! Easy setup, fast & simple transactions.
 * Version:         1.0.2
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-wpforms
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.7
 * Requires at least: 5.2
 * WPForms tested up to: 1.9.3.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */ 

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPFormsCoinsnap\Plugin;

const WPFORMS_COINSNAP_FILE = __FILE__;

if(!defined('COINSNAP_WPFORMS_VERSION')){ define( 'COINSNAP_WPFORMS_VERSION', '1.0.2' ); }
if(!defined('COINSNAP_WPFORMS_REFERRAL_CODE')){ define( 'COINSNAP_WPFORMS_REFERRAL_CODE', 'D19824' ); }
if(!defined('COINSNAP_PLUGIN_ID')){ define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-wpforms' ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' ); }
if(!defined('COINSNAP_WPFORMS_PATH')){ define( 'COINSNAP_WPFORMS_PATH', plugin_dir_path( WPFORMS_COINSNAP_FILE ) ); }
if(!defined('COINSNAP_WPFORMS_URL')){ define( 'COINSNAP_WPFORMS_URL', plugin_dir_url( WPFORMS_COINSNAP_FILE ) ); }

add_action( 'wpforms_loaded', 'wpforms_coinsnap' );

function wpforms_coinsnap() {
    require_once COINSNAP_WPFORMS_PATH . '/library/loader.php';	
    require_once COINSNAP_WPFORMS_PATH . '/src/Plugin.php';	
    return Plugin::get_instance();
}
