<?php
/** Standalone regression checks with WordPress/database doubles; no runtime integration. */
define('ABSPATH', sys_get_temp_dir() . '/qndrs-ahm-tests-' . getmypid() . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', '<?php');
register_shutdown_function(function () {
    unlink(ABSPATH . 'wp-admin/includes/upgrade.php');
    rmdir(ABSPATH . 'wp-admin/includes');
    rmdir(ABSPATH . 'wp-admin');
    rmdir(ABSPATH);
});

class WP_REST_Controller {}
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data) {}
}
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function dbDelta($sql) {}
function __($text, $domain) { return $GLOBALS['test_translations'][$text] ?? $text; }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($text)); }
function sanitize_text_field($text) { return strip_tags($text); }
function absint($value) { return abs((int)$value); }
function wp_generate_uuid4() { static $id = 0; return 'event-' . ++$id; }
function wp_salt($scheme) { return 'test-salt'; }
function wp_json_encode($value) { return json_encode($value); }
function maybe_serialize($value) { return serialize($value); }
function maybe_unserialize($value) { return $value === null ? null : unserialize($value); }
function wp_cache_delete($key, $group) { $GLOBALS['invalidated'][] = $key; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_next_scheduled($hook) { return false; }
function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
function wp_date($format, $timestamp, $timezone = null) { return gmdate($format, $timestamp); }
function date_i18n($format, $timestamp, $gmt = false) { return gmdate($format, $timestamp); }
function wp_timezone() { return new DateTimeZone('UTC'); }

class TestDatabase {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public array $rows = [];
    public $before_update = null;
    public bool $fail_write = false;
    public bool $fail_insert = false;
    public int $attempts = 0;
    public function get_charset_collate() { return ''; }
    public function prepare($sql, ...$args) {
        return json_encode([$sql, count($args) === 1 && is_array($args[0]) ? $args[0] : $args]);
    }
    public function get_var($query) {
        [$sql, $args] = str_starts_with($query, '[') ? json_decode($query, true) : [$query, []];
        if (str_starts_with($sql, 'SHOW TABLES')) { return $this->prefix . 'qndrs_ahm_history'; }
        if (str_starts_with($sql, 'SELECT COUNT(*) FROM wp_qndrs')) { return count($this->rows); }
        if (str_starts_with($sql, 'SELECT option_value')) { return maybe_serialize(get_option($args[0], [])); }
        throw new RuntimeException('Unexpected read: ' . $sql);
    }
    public function insert($table, $row, $formats) { $this->rows[$row['event_id']] = $row; return 1; }
    public function query($query) {
        [$sql, $args] = str_starts_with($query, '[') ? json_decode($query, true) : [$query, []];
        if (str_starts_with($sql, 'UPDATE wp_options')) {
            $this->attempts++;
            if ($this->before_update) { ($this->before_update)(); }
            if ($this->fail_write) { return false; }
            if (maybe_serialize(get_option($args[1])) !== $args[2]) { return 0; }
            update_option($args[1], maybe_unserialize($args[0]));
            return 1;
        }
        if ($sql === 'DELETE FROM wp_qndrs_ahm_history') {
            if ($this->fail_write) { return false; }
            $this->rows = [];
            return 1;
        }
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            if ($this->fail_write || $this->fail_insert) { return false; }
            preg_match_all('/\((%s, %s, %s, %s, (?:NULL|%d), (?:NULL|%d), %s)\)/', $sql, $tuples);
            $inserted = 0;
            foreach ($tuples[1] as $tuple) {
                $values = [];
                foreach (explode(', ', $tuple) as $placeholder) {
                    $values[] = $placeholder === 'NULL' ? null : array_shift($args);
                }
                if (!isset($this->rows[$values[0]])) { $inserted++; }
                $this->rows[$values[0]] = $values;
            }
            return $inserted;
        }
        throw new RuntimeException('Unexpected write: ' . $sql);
    }
}

require dirname(__DIR__) . '/qndrs-availability-heartbeat-monitor.php';
use Qndrs\AvailabilityHeartbeatMonitor\AvailabilityHeartbeatMonitor as Monitor;
function invoke($monitor, $method, ...$args) {
    return (new ReflectionMethod(Monitor::class, $method))->invoke($monitor, ...$args);
}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function reset_test($mode = 'legacy') {
    $GLOBALS['options'] = ['qndrs_ahm_history_storage' => $mode];
    $GLOBALS['wpdb'] = new TestDatabase();
    $GLOBALS['invalidated'] = [];
    return new Monitor();
}
function entry($timestamp, $status = 'up', $response = null) {
    return ['timestamp' => $timestamp, 'status' => $status, 'response_time_ms' => $response];
}

// Shadow verification must survive both time retention and the per-monitor cap.
$monitor = reset_test('table_shadow');
$old = entry(gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS));
$GLOBALS['options']['qndrs_ahm_history'] = ['a' => [$old]];
invoke($monitor, 'record_status_history', ['id' => 'a'], 'up', 200, null, '');
check(count(get_option('qndrs_ahm_history')['a']) === 2, 'Shadow history lost an expired row');
$monitor = reset_test('table_shadow');
$GLOBALS['options']['qndrs_ahm_history'] = ['a' => array_fill(0, Monitor::MAX_STATUS_HISTORY_PER_URL, entry(gmdate('Y-m-d H:i:s')))];
invoke($monitor, 'record_status_history', ['id' => 'a'], 'up', 200, null, '');
check(count(get_option('qndrs_ahm_history')['a']) === Monitor::MAX_STATUS_HISTORY_PER_URL + 1, 'Shadow history hit the legacy cap');
$monitor = reset_test();
$GLOBALS['options']['qndrs_ahm_history'] = ['a' => [$old]];
invoke($monitor, 'record_status_history', ['id' => 'a'], 'up', 200, null, '');
check(count(get_option('qndrs_ahm_history')['a']) === 1, 'Legacy retention changed');

// Recover stale/duplicate table rows and repeat safely, including legacy records without IDs.
$monitor = reset_test('table_shadow');
$GLOBALS['options']['qndrs_ahm_history'] = ['a' => [entry(gmdate('Y-m-d H:i:s'))]];
$wpdb->rows = ['extra' => []];
check($monitor->migrate_history_to_table()['success'], 'Could not reconcile extra shadow rows');
check($monitor->migrate_history_to_table()['success'], 'Migration retry failed');
check(count($wpdb->rows) === 1, 'Migration duplicated history');
check($monitor->finalize_history_table()['success'], 'Finalization failed after reconciliation');
$before = $wpdb->rows;
check(!$monitor->migrate_history_to_table()['success'] && $wpdb->rows === $before, 'Finalized table was rebuilt');
$monitor = reset_test('table_shadow');
$wpdb->fail_write = true;
check(!$monitor->migrate_history_to_table()['success'], 'Failed reset reported success');
check(get_option('qndrs_ahm_history_storage') === 'legacy', 'Failed reset left table reads active');
$monitor = reset_test('table_shadow');
$GLOBALS['options']['qndrs_ahm_history'] = ['a' => [$old]];
$wpdb->fail_insert = true;
check(!$monitor->migrate_history_to_table()['success'], 'Failed import reported success');
check(get_option('qndrs_ahm_history_storage') === 'legacy', 'Failed import left table reads active');
$wpdb->fail_insert = false;
check($monitor->migrate_history_to_table()['success'], 'Failed import could not be retried');
invoke($monitor, 'record_status_history', ['id' => 'a'], 'up', 200, null, '');
check($monitor->finalize_history_table()['success'], 'Retention divergence blocked shadow finalization');

// Aggregate status precedence: incident > degraded > unknown > operational.
$a = ['id' => 'a', 'enabled' => true];
$b = ['id' => 'b', 'enabled' => true];
foreach ([
    [[], [], 'unknown'],
    [[$a], [], 'unknown'],
    [[array_merge($a, ['enabled' => false])], [], 'unknown'],
    [[$a], ['a' => [entry(gmdate('Y-m-d H:i:s'))]], 'operational'],
    [[$a, $b], ['a' => [entry(gmdate('Y-m-d H:i:s'))]], 'unknown'],
    [[$a, $b], ['a' => [entry(gmdate('Y-m-d H:i:s'), 'up', 1500)]], 'degraded'],
    [[$a, $b], ['a' => [entry(gmdate('Y-m-d H:i:s'), 'down')]], 'incident'],
] as [$urls, $history, $expected]) {
    $monitor = reset_test();
    $GLOBALS['options']['qndrs_ahm_history'] = $history;
    $summary = invoke($monitor, 'get_dashboard_summary', $urls);
    check($summary['overall_status'] === $expected, 'Unexpected aggregate status: ' . $expected);
    if ($expected === 'unknown') { check($summary['overall_label'] === 'Unknown', 'Unknown label missing'); }
}

// Force another monitor's ping to commit between this request's read and write.
$monitor = reset_test();
$a = invoke($monitor, 'normalize_heartbeat_record', ['id' => 'a', 'name' => 'A', 'token_hash' => hash_hmac('sha256', 'token-a', wp_salt('auth'))]);
$b = invoke($monitor, 'normalize_heartbeat_record', ['id' => 'b', 'name' => 'B', 'token_hash' => hash_hmac('sha256', 'token-b', wp_salt('auth'))]);
$GLOBALS['options']['qndrs_ahm_urls'] = [$a, $b];
$wpdb->before_update = function () use ($monitor, $wpdb) {
    $wpdb->before_update = null;
    check(!is_wp_error(invoke($monitor, 'persist_heartbeat', 'token-b', 'up', 'B ping', '2026-10-23 10:00:01')), 'Concurrent B ping failed');
};
check(!is_wp_error(invoke($monitor, 'persist_heartbeat', 'token-a', 'up', 'A ping', '2026-10-23 10:00:00')), 'A ping retry failed');
$urls = get_option('qndrs_ahm_urls');
check($urls[0]['last_message'] === 'A ping' && $urls[1]['last_message'] === 'B ping', 'Concurrent ping was lost');
check($wpdb->attempts === 3, 'Conflict was not retried');
check(in_array('alloptions', $GLOBALS['invalidated'], true), 'Autoload cache was not invalidated');

// Revalidate token and enabled state after a conflict; fail closed on DB errors/contention.
foreach (['rotate', 'disable', 'delete', 'database', 'contention'] as $scenario) {
    $monitor = reset_test();
    $GLOBALS['options']['qndrs_ahm_urls'] = [$a, $b];
    $wpdb->fail_write = $scenario === 'database';
    $wpdb->before_update = function () use ($scenario, $wpdb) {
        $urls = get_option('qndrs_ahm_urls');
        if ($scenario === 'rotate') { $urls[0]['token_hash'] = 'rotated'; }
        if ($scenario === 'disable') { $urls[0]['enabled'] = false; }
        if ($scenario === 'delete') { array_shift($urls); }
        if ($scenario === 'contention') { $urls[1]['last_message'] .= 'x'; }
        update_option('qndrs_ahm_urls', $urls);
        if ($scenario !== 'contention') { $wpdb->before_update = null; }
    };
    $result = invoke($monitor, 'persist_heartbeat', 'token-a', 'up', '', '2026-10-23 10:00:00');
    check(is_wp_error($result), 'Failed heartbeat was accepted: ' . $scenario);
    check($result->data['status'] === (in_array($scenario, ['database', 'contention'], true) ? 503 : 403), 'Wrong error status: ' . $scenario);
}
echo "Reliability regression checks passed.\n";
