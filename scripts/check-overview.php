<?php
/** Check the production PHP renderer and optionally generate a browser fixture. */
require __DIR__ . '/check-reliability.php';

function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_html__($text, $domain) { return esc_html(__($text, $domain)); }
function esc_attr__($text, $domain) { return esc_attr(__($text, $domain)); }
function esc_url($url) { return esc_attr($url); }
function esc_url_raw($url, ...$args) { return $url; }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($value) { return stripslashes($value); }
function checked($value, $expected = true, $echo = true) { return $value === $expected ? 'checked="checked"' : ''; }
function wp_nonce_field(...$args) {}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return '/'; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script($handle, $name, $data) { $GLOBALS['test_localized'][$name] = $data; }
function admin_url($path) { return '/dist/overview-response.php'; }
function wp_create_nonce($action) { return 'fixture-only'; }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 7; }
function add_menu_page(...$args) { $GLOBALS['test_parent_menus'][] = $args; }
function add_submenu_page(...$args) { $GLOBALS['test_submenus'][] = $args; }

$monitor = reset_test();
$monitor->add_menu_page();
check(count($GLOBALS['test_parent_menus']) === 1 && count($GLOBALS['test_submenus']) === 2, 'Unexpected menu structure');
check($GLOBALS['test_submenus'][0][2] === 'Overview', 'First submenu still duplicates the parent label');
check($GLOBALS['test_submenus'][0][4] === $GLOBALS['test_parent_menus'][0][3], 'Overview no longer targets the existing monitor page');
check($GLOBALS['test_submenus'][1][2] === 'Settings', 'Settings submenu was lost');
$now = gmdate('Y-m-d H:i:s');
$urls = invoke($monitor, 'normalize_urls', [
    ['id' => 'slow', 'url' => 'https://example.com/', 'pushover' => true],
    ['id' => 'healthy', 'url' => 'https://example.org/', 'email' => true],
    ['id' => 'heartbeat', 'type' => 'heartbeat', 'name' => 'Heartbeat & "demo"', 'last_seen' => $now, 'last_status' => 'up'],
    ['id' => 'new', 'url' => 'https://example.net/'],
    ['id' => 'paused', 'url' => 'https://example.edu/', 'enabled' => false],
]);
$GLOBALS['options']['qndrs_ahm_urls'] = $urls;
$GLOBALS['options']['qndrs_ahm_history'] = [
    'slow' => [entry(gmdate('Y-m-d H:i:s', time() - 120), 'up', 421) + ['status_code' => 200], entry($now, 'up', 2296) + ['status_code' => 200]],
    'healthy' => [entry($now, 'up', 77) + ['status_code' => 200]],
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$monitor->enqueue_styles('qndrs-availability-heartbeat-monitor');
check($GLOBALS['test_localized']['qndrsAhmAjax']['view_storage_key'] === 'qndrsAhmView:v1:1:7', 'Preferences are not scoped by site and user');
ob_start();
$monitor->render_urls_page();
$english = ob_get_clean();
check(str_contains($english, 'uptime-monitor-overview'), 'Overview wrapper missing');
check(substr_count($english, 'data-view-toggle=') === 7, 'Not all seven fields are configurable');
check(str_contains($english, 'Heartbeat &amp; &quot;demo&quot;'), 'Monitor name was not escaped');
check(str_contains($english, 'data-view-column="response"><span class="uptime-chip">2296 ms'), 'Latest response is missing from the independent response column');
check(str_contains($english, 'uptime-url-incident" data-view-column="incident"'), 'Incident column is not independently configurable');
check(substr_count($english, 'class="toggle-monitoring"') === count($urls), 'Monitoring actions were lost');
check(substr_count($english, 'aria-expanded="false"') >= count($urls), 'Details are not initially collapsed');

if (in_array('--fixture', $argv, true)) {
    $catalog = require dirname(__DIR__) . '/languages/qndrs-availability-heartbeat-monitor-nl_NL.l10n.php';
    $GLOBALS['test_translations'] = $catalog['messages'];
    $monitor->enqueue_styles('qndrs-availability-heartbeat-monitor');
    ob_start();
    $monitor->render_urls_page();
    $html = ob_get_clean();
    $localized = '';
    foreach ($GLOBALS['test_localized'] as $name => $data) {
        $localized .= 'window.' . $name . '=' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . ';';
    }
    $payload = invoke($monitor, 'get_dashboard_ajax_payload', $urls);
    $root = dirname(__DIR__);
    if (!is_dir($root . '/dist')) { mkdir($root . '/dist'); }
    file_put_contents($root . '/dist/overview-response.json', json_encode(['success' => true, 'data' => $payload]));
    file_put_contents($root . '/dist/overview-response.php', '<?php header("Content-Type: application/json"); readfile(__DIR__ . "/overview-response.json");');
    $base = '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Plugin overview verification</title>'
        . '<link rel="stylesheet" href="/dist/wp-common.css"><link rel="stylesheet" href="/dist/wp-forms.css"><link rel="stylesheet" href="/dist/wp-buttons.css">'
        . '<link rel="stylesheet" href="/css/uptime-monitor.css"><style>body{margin:0;background:#f0f0f1;font:13px/1.5 Arial,sans-serif}#wpcontent{box-sizing:border-box;margin-left:0;padding:20px;min-height:100vh}.wrap{margin:0}.button{cursor:pointer}.screen-reader-text{position:absolute;top:0;left:0;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}</style>'
        . '<body class="wp-core-ui"><div id="wpcontent">' . $html . '</div><script src="/dist/jquery.min.js"></script><script>' . $localized . '</script><script src="/js/uptime-monitor.js"></script></body></html>';
    file_put_contents($root . '/dist/overview-check.html', $base);
    $script = '<script src="/js/uptime-monitor.js"></script>';
    file_put_contents($root . '/dist/overview-blocked-storage.html', str_replace($script,
        '<script>Object.defineProperty(window,"localStorage",{get(){throw new Error("Test: browser storage blocked");}});</script>' . $script, $base));
    file_put_contents($root . '/dist/overview-corrupt-storage.html', str_replace($script,
        '<script>localStorage.setItem(qndrsAhmAjax.view_storage_key,"{invalid");</script>' . $script, $base));
    file_put_contents($root . '/dist/overview-widget.html', str_replace($html, $payload['widget_html'], $base));
    $GLOBALS['options']['qndrs_ahm_urls'] = [];
    ob_start();
    $monitor->render_urls_page();
    $empty = ob_get_clean();
    check(str_contains($empty, 'uptime-url-list-scroll is-empty'), 'Empty state still reserves columns');
    file_put_contents($root . '/dist/overview-empty.html', str_replace($html, $empty, $base));
    echo "Generated dist/overview-check.html and AJAX fixture. Supply local WordPress jQuery/common/forms/buttons assets as documented.\n";
}
echo "Overview renderer checks passed.\n";
