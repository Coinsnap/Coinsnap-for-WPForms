<?php
// Run: php tests/missing-wpforms-pro-test.php
// With WPForms Lite (no WPForms_Payment class) or without WPForms at all, loading this plugin must not be
// fatal; it only shows the "requires WPForms Pro" admin notice. With WPForms Pro the gateway loads as before.
// Each scenario runs in its own PHP process, because the plugin file and its classes can be loaded only once.
if (PHP_SAPI !== 'cli') {
    exit;
}

$scenario = $argv[1] ?? '';

if ($scenario === '') {
    $fail = 0;
    foreach (['lite', 'none', 'pro'] as $s) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $s . ' 2>&1', $out, $code);
        echo implode("\n", $out) . "\n";
        if ($code !== 0) {
            $fail++;
        }
    }
    echo $fail ? "\n$fail SCENARIO(S) FAILED\n" : "\nALL PASS\n";
    exit($fail ? 1 : 0);
}

define('ABSPATH', __DIR__ . '/');
$GLOBALS['hooks'] = [];
function add_action($hook, $cb, $prio = 10, ...$a) { $GLOBALS['hooks'][] = [$hook, $cb, $prio]; return true; }
function add_filter($hook, $cb, $prio = 10, ...$a) { $GLOBALS['hooks'][] = [$hook, $cb, $prio]; return true; }
function register_activation_hook($file, $cb) {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://shop.test/wp-content/plugins/coinsnap-for-wpforms/'; }
function plugin_basename($f) { return 'coinsnap-for-wpforms/coinsnap-for-wpforms.php'; }
function is_admin() { return true; }
function admin_url($p = '') { return 'https://shop.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://shop.test' . $p; }
function is_plugin_active($p) { return $GLOBALS['scenario'] !== 'none'; }
function deactivate_plugins(...$a) { $GLOBALS['deactivated'] = true; }
// The invoice table is already installed, so Security::install() returns early.
function get_option($k, $d = false) { return $k === \WPFormsCoinsnap\Security::DB_VERSION_KEY ? \WPFormsCoinsnap\Security::DB_VERSION : $d; }
function update_option(...$a) { return true; }
class T_Wpdb { public $prefix = 'wp_'; public function get_charset_collate() { return ''; } }
$GLOBALS['wpdb'] = new T_Wpdb();
$GLOBALS['scenario'] = $scenario;
$GLOBALS['deactivated'] = false;

if ($scenario !== 'none') {
    class T_WPForms { public function is_pro() { return $GLOBALS['scenario'] === 'pro'; } }
    function wpforms() { return new T_WPForms(); }
}
if ($scenario === 'pro') {
    // WPForms Pro's payment API, as far as the gateway's construction needs it.
    class WPForms_Payment {
        public $form_data = []; public $slug; public $version; public $name; public $priority; public $icon;
        public function __construct() { $this->init(); }
    }
}

$fail = 0;
function ok($c, $m) { global $fail, $scenario; echo ($c ? 'PASS ' : 'FAIL ') . "[$scenario] $m\n"; if (!$c) { $fail++; } }

// Runs what WordPress runs on one request: plugins_loaded (WPForms fires wpforms_loaded from inside it, if
// WPForms is active), then admin_init.
function run_hook($name) {
    $hooks = array_values(array_filter($GLOBALS['hooks'], function ($h) use ($name) { return $h[0] === $name; }));
    usort($hooks, function ($a, $b) { return $a[2] <=> $b[2]; });
    foreach ($hooks as $h) {
        call_user_func($h[1], '');
    }
}

try {
    require dirname(__DIR__) . '/coinsnap-for-wpforms.php';
    run_hook('plugins_loaded');
    if ($scenario !== 'none') {
        run_hook('wpforms_loaded');
    }
    run_hook('admin_init');
    $error = '';
} catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
ok($error === '', 'one request loads without a fatal error' . ($error !== '' ? " [$error]" : ''));

$plugin = class_exists('WPFormsCoinsnap\\Plugin', false);
$noticeHooks = array_values(array_filter($GLOBALS['hooks'], function ($h) { return $h[0] === 'admin_notices'; }));
$gatewayHooks = array_filter($GLOBALS['hooks'], function ($h) {
    return in_array($h[0], ['wpforms_process', 'wpforms_process_complete', 'init', 'request', 'template_redirect'], true);
});

if ($scenario === 'pro') {
    ok($plugin && count($gatewayHooks) >= 4, 'gateway and webhook hooks are registered with WPForms Pro');
    ok(count(array_filter($noticeHooks, function ($h) { return !is_array($h[1]); })) === 0, 'no "requires WPForms Pro" notice with WPForms Pro');
} else {
    ok(!$plugin, 'the gateway class is not loaded without WPForms Pro');
    ok(count($gatewayHooks) === 0, 'no gateway, webhook or rewrite hooks without WPForms Pro');
    ok(count($noticeHooks) === 1, 'exactly one admin notice is registered');
    $text = '';
    if (count($noticeHooks) === 1) {
        if (!function_exists('esc_html_e')) { function esc_html_e($s, $d = null) { echo $s; } }
        if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return $s; } }
        ob_start();
        call_user_func($noticeHooks[0][1]);
        $text = ob_get_clean();
    }
    ok(strpos($text, 'WPForms Pro') !== false, 'the notice says WPForms Pro is required');
    ok(!$GLOBALS['deactivated'], 'the plugin stays active, so it works once WPForms Pro is activated');
}

exit($fail ? 1 : 0);
