<?php
// Removes only this plugin's own data. The API keys are part of each form's payment
// settings (WPForms data) and are left to WPForms.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('coinsnap_wpforms_webhook_secrets');
delete_option('coinsnap_wpforms_db_version');
delete_option('coinsnap_wpforms_security_upgraded_at');
delete_transient('coinsnap_wpforms_btcpay_state');

// Old per-form webhook records (provider-generated secrets) from older versions.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$coinsnap_wpforms_legacy = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('wpforms_settings_coinsnap_webhook_') . '%'
));
foreach ((array) $coinsnap_wpforms_legacy as $coinsnap_wpforms_option) {
    if (preg_match('/^wpforms_settings_coinsnap_webhook_\d+$/', (string) $coinsnap_wpforms_option)) {
        delete_option($coinsnap_wpforms_option);
    }
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'coinsnap_wpforms_invoices');
