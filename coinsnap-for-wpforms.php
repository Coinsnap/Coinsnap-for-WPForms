<?php
/**
 * Plugin Name:     Bitcoin payment for WPForms
 * Plugin URI:      https://coinsnap.io/coinsnap-for-wpforms-plugin/
 * Description:     Sell products, downloads, bookings for Bitcoin or get Bitcoin-donations in any form you created with WPForms! Easy setup, fast & simple transactions.
 * Version:         1.2.0
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-wpforms
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    6.8
 * Requires at least: 5.2
 * WPForms tested up to: 1.9.5.2
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

if(!defined('COINSNAP_WPFORMS_VERSION')){ define( 'COINSNAP_WPFORMS_VERSION', '1.2.0' ); }
if(!defined('COINSNAP_WPFORMS_REFERRAL_CODE')){ define( 'COINSNAP_WPFORMS_REFERRAL_CODE', 'D19824' ); }
if(!defined('COINSNAP_PLUGIN_ID')){ define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-wpforms' ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' ); }
if(!defined('COINSNAP_WPFORMS_PATH')){ define( 'COINSNAP_WPFORMS_PATH', plugin_dir_path( WPFORMS_COINSNAP_FILE ) ); }
if(!defined('COINSNAP_WPFORMS_URL')){ define( 'COINSNAP_WPFORMS_URL', plugin_dir_url( WPFORMS_COINSNAP_FILE ) ); }
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}

add_action('wpforms_loaded', 'wpforms_coinsnap' );
add_action('admin_init', 'check_wpforms_dependency');

function check_wpforms_dependency(){
    if (!is_plugin_active('wpforms/wpforms.php')  || !(wpforms()->is_pro())) {
        add_action('admin_notices', 'wpforms_dependency_notice');
        deactivate_plugins(plugin_basename(__FILE__));
    }
}

function wpforms_dependency_notice(){?>
    <div class="notice notice-error">
        <p><?php echo esc_html_e('Bitcoin payment for WPForms plugin requires WP Forms to be installed and activated.','coinsnap-for-wpforms'); ?></p>
    </div>
    <?php
}

add_action('init', function() {
    
//  Session launcher
    if ( ! session_id() ) {
        session_start();
    }
    
// Setting up and handling custom endpoint for api key redirect from BTCPay Server.
    add_rewrite_endpoint('btcpay-settings-callback', EP_ROOT);
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
    if (isset($vars['btcpay-settings-callback'])) {
        $vars['btcpay-settings-callback'] = true;
    }
    return $vars;
});


function wpforms_coinsnap() {
    require_once COINSNAP_WPFORMS_PATH . '/library/loader.php';	
    require_once COINSNAP_WPFORMS_PATH . '/src/Plugin.php';	
    return Plugin::get_instance();
}
