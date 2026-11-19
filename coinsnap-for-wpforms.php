<?php
/**
 * Plugin Name:     Bitcoin payment for WPForms
 * Plugin URI:      https://coinsnap.io/wp-add-ons/wpforms/
 * Description:     Sell products, downloads, bookings for Bitcoin or get Bitcoin-donations in any form you created with WPForms! Easy setup, fast & simple transactions.
 * Version:         1.5.3
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-wpforms
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * Tested up to:    7.0
 * Requires at least: 5.5
 * WPForms tested up to: 1.9.9.4
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

if(!defined('COINSNAP_WPFORMS_VERSION')){ define( 'COINSNAP_WPFORMS_VERSION', '1.5.3' ); }
if(!defined('COINSNAP_WPFORMS_REFERRAL_CODE')){ define( 'COINSNAP_WPFORMS_REFERRAL_CODE', 'D19824' ); }
if(!defined('COINSNAP_PLUGIN_ID')){ define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-wpforms' ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' ); }
if(!defined('COINSNAP_WPFORMS_PATH')){ define( 'COINSNAP_WPFORMS_PATH', plugin_dir_path( WPFORMS_COINSNAP_FILE ) ); }
if(!defined('COINSNAP_WPFORMS_URL')){ define( 'COINSNAP_WPFORMS_URL', plugin_dir_url( WPFORMS_COINSNAP_FILE ) ); }
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}

require_once COINSNAP_WPFORMS_PATH . 'src/Security.php';

// Invoice records table: created on activation, and on the first load after an update.
register_activation_hook(__FILE__, ['WPFormsCoinsnap\\Security', 'install']);

// WPForms fires wpforms_loaded from its plugins_loaded callback (priority 10), so the check at 20 sees it loaded.
add_action('wpforms_loaded', 'wpforms_coinsnap' );
add_action('plugins_loaded', 'wpforms_check_dependency', 20);

/**
 * The gateway extends WPForms_Payment, which only WPForms Pro ships. WPForms Lite has wpforms() but not
 * the payment API, so loading the gateway there is a fatal error on every request.
 */
function coinsnap_wpforms_pro_active(): bool {
    return function_exists('wpforms') && class_exists('WPForms_Payment');
}

// Without WPForms Pro only the admin notice is registered; the plugin stays active and loads once Pro is active.
function wpforms_check_dependency(){
    if (!coinsnap_wpforms_pro_active()) {
        add_action('admin_notices', 'wpforms_dependency_notice');
    }
}

function wpforms_dependency_notice(){?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Bitcoin payment for WPForms requires WPForms Pro to be installed and activated. WPForms Lite does not include the payments API this plugin needs.','coinsnap-for-wpforms'); ?></p>
    </div>
    <?php
}

function wpforms_coinsnap() {
    if (!coinsnap_wpforms_pro_active()) {
        return null;
    }

    WPFormsCoinsnap\Security::install();

    add_action('init', function() {
        // Setting up and handling custom endpoint for api key redirect from BTCPay Server.
        add_rewrite_endpoint('coinsnap-for-wpforms-btcpay-settings-callback', EP_ROOT);
    });

    // To be able to use the endpoint without appended url segments we need to do this.
    add_filter('request', function($vars) {
        if (isset($vars['coinsnap-for-wpforms-btcpay-settings-callback'])) {
            // The proof is the one-time state created when the admin clicked "Generate API key",
            // never a nonce created in this request.
            $vars['coinsnap-for-wpforms-btcpay-settings-callback'] = true;
        }
        return $vars;
    });

    require_once COINSNAP_WPFORMS_PATH . '/library/loader.php';	
    require_once COINSNAP_WPFORMS_PATH . '/src/Metadata.php';
    require_once COINSNAP_WPFORMS_PATH . '/src/Plugin.php';	
    return Plugin::get_instance();
}
