<?php
// Standalone test of the create-invoice request sent by Plugin::process_payment(), for both
// providers, with minimal WordPress / WPForms Pro stubs and a fake HTTP API.
// Run: php tests/invoice-metadata-test.php
namespace {
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', '/');
define('COINSNAP_WPFORMS_VERSION', '9.9.9');
define('COINSNAP_WPFORMS_REFERRAL_CODE', 'REF');
define('COINSNAP_WPFORMS_URL', 'https://shop.test/wp-content/plugins/coinsnap-for-wpforms/');
define('COINSNAP_SERVER_URL', 'https://coinsnap.test');
define('COINSNAP_CURRENCIES', ['EUR', 'USD', 'SATS', 'BTC']);
$GLOBALS['t_requests'] = [];
$GLOBALS['t_redirect'] = '';
function add_action(...$a) {}
function add_filter(...$a) {}
function is_admin() { return false; }
function is_ssl() { return true; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_html($s) { return $s; }
function esc_url($s) { return $s; }
function esc_url_raw($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function wp_unslash($s) { return $s; }
function wp_hash($s) { return md5($s); }
function apply_filters($h, $v) { return $v; }
function add_query_arg($a, $u) { return $u; }
function admin_url($p) { return 'https://shop.test/wp-admin/' . $p; }
function wp_get_referer() { return 'https://shop.test/book/'; }
function determine_locale() { return 'de_DE'; }
function current_time($t, $gmt = false) { return '2026-11-19 10:00:00'; }
function wpforms_get_currency() { return 'EUR'; }
function wpforms_log(...$a) {}
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function is_wp_error($v) { return false; }
// The plugin exits after the redirect: stop there (its catch then logs the error).
function wp_redirect($url) { $GLOBALS['t_redirect'] = $url; throw new \RuntimeException('redirect'); }
class T_Headers { public function getAll() { return []; } }
function wp_remote_retrieve_headers($r) { return new T_Headers(); }
function wp_remote_request($url, $args) {
    $GLOBALS['t_requests'][] = ['url' => $url, 'method' => $args['method'], 'body' => $args['body']];
    $json = function ($v) { return ['response' => ['code' => 200], 'body' => json_encode($v)]; };
    if (strpos($url, '/rates?') !== false) { return $json([['currencyPair' => 'BTC_EUR', 'rate' => '80000', 'errors' => []]]); }
    if ($args['method'] === 'POST' && substr($url, -9) === '/invoices') { return $json(['id' => 'inv' . count($GLOBALS['t_requests']), 'checkoutLink' => 'https://pay.test/i']); }
    return ['response' => ['code' => 404], 'body' => '{}'];
}
class T_Wpdb { public $prefix = 'wp_'; public function prepare($q, ...$a) { return $q; } public function query($q) { return 1; } }
$GLOBALS['wpdb'] = new T_Wpdb();
class T_Obj { public $errors = []; public function update(...$a) { return true; } }
class T_WPForms { public function obj($n) { return new T_Obj(); } public function get($n) { return new T_Obj(); } }
function wpforms() { return new T_WPForms(); }
class WPForms_Payment { public $form_data = []; public $slug; public $version; public $name; public $priority; public $icon; }
}

namespace WPForms\Db\Payments { class ValueValidator {} }

namespace {
require dirname(__DIR__) . '/library/loader.php';
require dirname(__DIR__) . '/src/Security.php';
if (file_exists(dirname(__DIR__) . '/src/Metadata.php')) { require dirname(__DIR__) . '/src/Metadata.php'; }
require dirname(__DIR__) . '/src/Plugin.php';

// WPForms' processed fields ($fields) and the raw submission ($entry) for one entry.
$fields = [
    1 => ['id' => 1, 'type' => 'name', 'name' => 'Name', 'value' => 'Ana Buyer', 'first' => 'Ana', 'middle' => '', 'last' => 'Buyer'],
    2 => ['id' => 2, 'type' => 'email', 'name' => 'Email', 'value' => 'ana@example.com'],
    3 => ['id' => 3, 'type' => 'phone', 'name' => 'Phone', 'value' => '+381 11 123'],
    4 => ['id' => 4, 'type' => 'address', 'name' => 'Address', 'value' => "Main St 1\nApt 2\nBelgrade 11000", 'address1' => 'Main St 1', 'address2' => 'Apt 2', 'city' => 'Belgrade', 'state' => 'Central', 'postal' => '11000', 'country' => 'RS'],
    5 => ['id' => 5, 'type' => 'textarea', 'name' => 'Message', 'value' => "Please\nbring coffee"],
    6 => ['id' => 6, 'type' => 'select', 'name' => 'Session', 'value' => 'Morning', 'value_raw' => 'Morning'],
    7 => ['id' => 7, 'type' => 'text', 'name' => 'Card', 'value' => '4111 1111 1111 1111'],
    8 => ['id' => 8, 'type' => 'hidden', 'name' => 'Ref', 'value' => 'abc'],
    9 => ['id' => 9, 'type' => 'file-upload', 'name' => 'CV', 'value' => 'https://shop.test/cv.pdf'],
    10 => ['id' => 10, 'type' => 'password', 'name' => 'Password', 'value' => 'hunter2'],
    11 => ['id' => 11, 'type' => 'payment-single', 'name' => 'Ticket', 'value' => '€ 20.00', 'amount' => '20.00', 'amount_raw' => '20.00', 'quantity' => 2],
    12 => ['id' => 12, 'type' => 'payment-select', 'name' => 'Lunch', 'value' => 'Vegan - € 5.00', 'value_choice' => 'Vegan', 'value_raw' => '2', 'amount' => '5.00', 'amount_raw' => '5.00'],
    13 => ['id' => 13, 'type' => 'payment-coupon', 'name' => 'Coupon', 'value' => 'SAVE5'],
    14 => ['id' => 14, 'type' => 'payment-total', 'name' => 'Total', 'value' => '€ 45.00', 'amount' => '45.00'],
    15 => ['id' => 15, 'type' => 'text', 'name' => 'Empty', 'value' => ''],
];
$entry = ['id' => 3, 'post_id' => 12, 'fields' => [1 => 'Ana Buyer', 2 => 'ana@example.com', 3 => '+381 11 123']];

$fail = 0;
$check = function ($ok, $label) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } };
$set = function ($obj, $prop, $value) { $r = new \ReflectionProperty($obj, $prop); $r->setAccessible(true); $r->setValue($obj, $value); };

foreach (['coinsnap', 'btcpay'] as $provider) {
    $GLOBALS['t_requests'] = [];
    $GLOBALS['t_redirect'] = '';
    $form = ['id' => 3, 'settings' => ['form_title' => 'Workshop booking'],
        'fields' => [1 => ['id' => 1, 'type' => 'name', 'format' => 'simple'], 2 => ['id' => 2, 'type' => 'email'], 3 => ['id' => 3, 'type' => 'phone']],
        'payments' => ['coinsnap' => ['coinsnap_provider' => $provider, 'store_id' => 'store1', 'api_key' => 'key', 'btcpay_store_id' => 'store1',
            'btcpay_api_key' => 'key', 'btcpay_server_url' => 'https://btcpay.test', 'coinsnap_returnurl' => 'https://shop.test/thanks/', 'autoredirect' => '1']]];
    $plugin = new \WPFormsCoinsnap\Plugin();
    $plugin->slug = 'coinsnap';
    $plugin->form_data = $form;
    $set($plugin, 'allowed_to_process', true);
    $set($plugin, 'amount', '45.00');
    $set($plugin, 'rate', 1 / 80000);
    $set($plugin, 'payment_id', 77);
    $plugin->process_payment($fields, $entry, $form, 42);
    $check($GLOBALS['t_redirect'] === 'https://pay.test/i', "$provider: invoice recorded, buyer sent to the checkout");
    $create = array_values(array_filter($GLOBALS['t_requests'], function ($r) { return $r['method'] === 'POST' && substr($r['url'], -9) === '/invoices'; }));
    $check(count($create) === 1, "$provider: one create-invoice request");
    if (!$create) { continue; }
    $body = json_decode($create[0]['body'], true);
    $m = $body['metadata'] ?? [];
    $check(($body['orderId'] ?? null) === '42', "$provider: top-level orderId is the entry ID");
    $check(($body['buyerEmail'] ?? null) === 'ana@example.com', "$provider: top-level buyerEmail");
    $expected = [
        'orderId' => '42', 'orderNumber' => '42', 'entryId' => '42', 'paymentId' => '77',
        'orderUrl' => 'https://shop.test/wp-admin/admin.php?page=wpforms-payments&view=payment&payment_id=77',
        'formId' => '3', 'formTitle' => 'Workshop booking', 'itemDesc' => 'Workshop booking', 'platform' => 'wpforms',
        'pluginVersion' => '9.9.9', 'pageUrl' => 'https://shop.test/book/', 'postId' => '12', 'language' => 'de_DE', 'couponCodes' => 'SAVE5',
        'customerName' => 'Ana Buyer', 'buyerName' => 'Ana Buyer', 'buyerEmail' => 'ana@example.com', 'buyerPhone' => '+381 11 123',
        'buyerAddress1' => 'Main St 1', 'buyerAddress2' => 'Apt 2', 'buyerCity' => 'Belgrade', 'buyerState' => 'Central',
        'buyerZip' => '11000', 'buyerCountry' => 'RS', 'buyerMessage' => "Please\nbring coffee",
    ];
    foreach ($expected as $k => $v) {
        $check(($m[$k] ?? null) === $v, "$provider: metadata.$k");
    }
    $check(($m['lineItems'] ?? null) === [
        ['name' => 'Ticket', 'sku' => '11', 'quantity' => 2, 'price' => '20.00', 'total' => '40.00'],
        ['name' => 'Lunch: Vegan', 'sku' => '12', 'quantity' => 1, 'price' => '5.00', 'total' => '5.00'],
    ], "$provider: lineItems");
    $check(($m['formFields'] ?? null) === ['Session' => 'Morning'], "$provider: formFields has only the other filled-in fields");
    $flat = json_encode($m);
    $leaked = array_filter(['4111 1111 1111 1111', 'abc', 'cv.pdf', 'hunter2'], function ($v) use ($flat) { return strpos($flat, $v) !== false; });
    $check(!$leaked, "$provider: no card number, hidden, file or password values");
}
exit($fail ? 1 : 0);
}
