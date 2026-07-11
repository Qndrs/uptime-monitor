<?php

namespace SimpleUptimeMonitor;
/**
 * Plugin Name: Simple Uptime Monitor
 * Plugin URI: https://github.com/qndrs/uptime-monitor
 * Description: Monitor de beschikbaarheid van websites en ontvang meldingen via e-mail of Pushover. Beheer eenvoudig meerdere URL's vanuit het WordPress-beheerpaneel, met logging, JSON-import/export, REST-ondersteuning en intervalinstellingen.
 * Version: 3.5.0
 * Author: Robert E. Kuunders, GPT
 * Author URI: https://qndrs.nl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: uptime-monitor
 * Domain Path: /languages
 *
 * Features:
 * - E-mail en Pushover notificaties bij downtime
 * - Cron-gebaseerde monitoring (instelbaar interval)
 * - Beheerbare URL-lijst met aan/uit schakelaar
 * - JSON-configuratie export en import
 * - REST endpoint voor logbestanden (alleen voor admins)
 * - Meertalige ondersteuning (Loco Translate compatibel)
 * - Custom client plugin mogelijk voor firewall-omzeiling
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SimpleUptimeMonitor
 * Main plugin class for handling uptime monitoring.
 */
class SimpleUptimeMonitor
{
    public const VERSION = '3.5.0';
    public const MAX_LOG_ENTRIES = 1000;
    public const MAX_STATUS_HISTORY_PER_URL = 43200;
    public const MAX_STATUS_HISTORY_DAYS = 30;
    public const MAX_INCIDENT_ALERTS = 3;
    public const DEFAULT_RETRY_ATTEMPTS = 3;
    public const DEFAULT_REQUEST_TIMEOUT = 10;
    public const DEFAULT_DOWN_STATUS_CODES = '100-199,300-599';
    public const DEFAULT_HEARTBEAT_INTERVAL = 300;
    private const CRON_HOOK = 'monitor_uptime_event';
    private const CRON_SCHEDULE = 'uptime_monitor_interval';

    /**
     * Constructor.
     * Registers hooks for plugin functionality.
     */
    public function __construct()
    {
        add_action('admin_init', [$this, 'maybe_upgrade_options']);
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action(self::CRON_HOOK, [$this, 'monitor_uptime']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        // AJAX hooks
        add_action('wp_ajax_add_uptime_url', [$this, 'ajax_add_url']);
        add_action('wp_ajax_delete_uptime_url', [$this, 'ajax_delete_url']);
        add_action('wp_ajax_refresh_uptime_dashboard', [$this, 'ajax_refresh_dashboard']);
        add_action('wp_ajax_toggle_uptime_monitoring', [$this, 'ajax_toggle_monitoring']);
    }
    /**
     * Activation hook.
     * Sets up initial settings and schedules monitoring events.
     */
    public function activate(): void
    {
        $this->normalize_stored_urls();
        $this->reschedule_monitoring();
    }

    /**
     * Deactivation hook.
     * Clears scheduled events on plugin deactivation.
     */
    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Normalizes existing options after file-based updates where activation does not run.
     */
    public function maybe_upgrade_options(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->normalize_stored_urls();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    private function get_monitor_interval(): int
    {
        return max(60, (int)get_option('uptime_monitor_interval', 120));
    }

    private function get_retry_attempts(): int
    {
        return min(10, max(1, (int)get_option('uptime_monitor_retry_attempts', self::DEFAULT_RETRY_ATTEMPTS)));
    }

    private function get_request_timeout(): int
    {
        return min(60, max(1, (int)get_option('uptime_monitor_request_timeout', self::DEFAULT_REQUEST_TIMEOUT)));
    }

    private function get_down_status_codes(): string
    {
        return $this->sanitize_status_code_ranges(get_option('uptime_monitor_down_status_codes', self::DEFAULT_DOWN_STATUS_CODES));
    }

    private function get_pushover_user_key(): string
    {
        if (defined('PUSHOVER_USER_KEY') && (string)PUSHOVER_USER_KEY !== '') {
            return sanitize_text_field((string)PUSHOVER_USER_KEY);
        }

        return sanitize_text_field((string)get_option('uptime_monitor_pushover_user_key', ''));
    }

    private function get_pushover_api_token(): string
    {
        if (defined('PUSHOVER_API_TOKEN') && (string)PUSHOVER_API_TOKEN !== '') {
            return sanitize_text_field((string)PUSHOVER_API_TOKEN);
        }

        return sanitize_text_field((string)get_option('uptime_monitor_pushover_api_token', ''));
    }

    private function has_pushover_credentials(): bool
    {
        return $this->get_pushover_user_key() !== '' && $this->get_pushover_api_token() !== '';
    }

    private function pushover_uses_constants(): bool
    {
        return (defined('PUSHOVER_USER_KEY') && (string)PUSHOVER_USER_KEY !== '')
            || (defined('PUSHOVER_API_TOKEN') && (string)PUSHOVER_API_TOKEN !== '');
    }

    private static function get_read_api_token_hash(): string
    {
        return (string)get_option('uptime_monitor_read_api_token_hash', '');
    }

    private function has_read_api_token(): bool
    {
        return self::get_read_api_token_hash() !== '';
    }

    private function create_read_api_token(): string
    {
        $token = 'sum_' . wp_generate_password(40, false, false);
        update_option('uptime_monitor_read_api_token_hash', self::hash_read_api_token($token), false);
        update_option('uptime_monitor_read_api_token_last4', substr($token, -4), false);
        update_option('uptime_monitor_read_api_token_created_at', gmdate('Y-m-d H:i:s'), false);

        return $token;
    }

    private function clear_read_api_token(): void
    {
        delete_option('uptime_monitor_read_api_token_hash');
        delete_option('uptime_monitor_read_api_token_last4');
        delete_option('uptime_monitor_read_api_token_created_at');
    }

    private static function hash_read_api_token(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private static function verify_read_api_token(string $token): bool
    {
        $token_hash = self::get_read_api_token_hash();
        if ($token === '' || $token_hash === '') {
            return false;
        }

        return hash_equals($token_hash, self::hash_read_api_token($token));
    }

    private static function get_request_token(\WP_REST_Request $request): string
    {
        $token = trim((string)$request->get_header('x_uptime_monitor_token'));

        if ($token === '') {
            $authorization = trim((string)$request->get_header('authorization'));
            if (stripos($authorization, 'Bearer ') === 0) {
                $token = trim(substr($authorization, 7));
            }
        }

        if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorization = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION'])));
            if (stripos($authorization, 'Bearer ') === 0) {
                $token = trim(substr($authorization, 7));
            }
        }

        if ($token === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = trim(sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])));
            if (stripos($authorization, 'Bearer ') === 0) {
                $token = trim(substr($authorization, 7));
            }
        }

        return $token;
    }

    private function create_heartbeat_token(): string
    {
        return 'sumhb_' . wp_generate_password(40, false, false);
    }

    private function hash_heartbeat_token(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private function find_heartbeat_monitor_index_by_token(array $urls, string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $token_hash = $this->hash_heartbeat_token($token);
        foreach ($urls as $index => $url_data) {
            if (($url_data['type'] ?? 'http_check') !== 'heartbeat') {
                continue;
            }
            if (!empty($url_data['token_hash']) && hash_equals((string)$url_data['token_hash'], $token_hash)) {
                return $index;
            }
        }

        return null;
    }

    private function save_pushover_settings_from_post(): void
    {
        $nonce = isset($_POST['uptime_monitor_settings_nonce']) ? sanitize_text_field(wp_unslash($_POST['uptime_monitor_settings_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'uptime_monitor_settings_nonce_action')) {
            return;
        }

        if (isset($_POST['clear_pushover_user_key'])) {
            delete_option('uptime_monitor_pushover_user_key');
        } else {
            $posted_user_key = isset($_POST['pushover_user_key']) ? sanitize_text_field(wp_unslash($_POST['pushover_user_key'])) : '';
            if ($posted_user_key !== '') {
                update_option('uptime_monitor_pushover_user_key', $posted_user_key, false);
            }
        }

        if (isset($_POST['clear_pushover_api_token'])) {
            delete_option('uptime_monitor_pushover_api_token');
        } else {
            $posted_api_token = isset($_POST['pushover_api_token']) ? sanitize_text_field(wp_unslash($_POST['pushover_api_token'])) : '';
            if ($posted_api_token !== '') {
                update_option('uptime_monitor_pushover_api_token', $posted_api_token, false);
            }
        }
    }

    private function add_heartbeat_monitor_from_post(): array
    {
        $name = isset($_POST['heartbeat_name']) ? sanitize_text_field(wp_unslash($_POST['heartbeat_name'])) : '';
        if ($name === '') {
            return ['token' => '', 'message' => __('Naam voor heartbeat monitor is verplicht.', 'uptime-monitor'), 'type' => 'error'];
        }

        $expected_interval = isset($_POST['heartbeat_expected_interval']) ? absint(wp_unslash($_POST['heartbeat_expected_interval'])) : self::DEFAULT_HEARTBEAT_INTERVAL;
        $expected_interval = min(86400, max(60, $expected_interval));
        $token = $this->create_heartbeat_token();
        $urls = $this->normalize_stored_urls();
        $urls[] = [
            'id' => wp_generate_uuid4(),
            'type' => 'heartbeat',
            'name' => $name,
            'url' => '',
            'email' => !empty($_POST['heartbeat_email_alert']),
            'pushover' => !empty($_POST['heartbeat_pushover_alert']),
            'enabled' => true,
            'expected_interval' => $expected_interval,
            'token_hash' => $this->hash_heartbeat_token($token),
            'token_last4' => substr($token, -4),
            'token_created_at' => gmdate('Y-m-d H:i:s'),
            'last_seen' => '',
            'last_status' => 'unknown',
            'last_message' => '',
        ];

        update_option('uptime_monitor_urls', $this->normalize_urls($urls));

        return ['token' => $token, 'message' => __('Heartbeat monitor aangemaakt. Kopieer het token nu; het wordt niet opnieuw getoond.', 'uptime-monitor'), 'type' => 'updated'];
    }

    private function update_heartbeat_monitor_from_post(string $action): array
    {
        $id = isset($_POST['heartbeat_id']) ? sanitize_key(wp_unslash($_POST['heartbeat_id'])) : '';
        if ($id === '') {
            return ['token' => '', 'message' => __('Heartbeat monitor niet gevonden.', 'uptime-monitor'), 'type' => 'error'];
        }

        $urls = $this->normalize_stored_urls();
        foreach ($urls as $index => $url_data) {
            if (($url_data['type'] ?? 'http_check') !== 'heartbeat' || $url_data['id'] !== $id) {
                continue;
            }

            if ($action === 'delete') {
                unset($urls[$index]);
                update_option('uptime_monitor_urls', array_values($urls));
                $this->delete_status_history_for_url($id);
                $this->delete_incident_for_url($id);

                return ['token' => '', 'message' => __('Heartbeat monitor verwijderd.', 'uptime-monitor'), 'type' => 'updated'];
            }

            if ($action === 'toggle') {
                $urls[$index]['enabled'] = empty($url_data['enabled']);
                update_option('uptime_monitor_urls', $this->normalize_urls($urls));

                return ['token' => '', 'message' => __('Heartbeat monitor bijgewerkt.', 'uptime-monitor'), 'type' => 'updated'];
            }

            if ($action === 'rotate') {
                $token = $this->create_heartbeat_token();
                $urls[$index]['token_hash'] = $this->hash_heartbeat_token($token);
                $urls[$index]['token_last4'] = substr($token, -4);
                $urls[$index]['token_created_at'] = gmdate('Y-m-d H:i:s');
                update_option('uptime_monitor_urls', $this->normalize_urls($urls));

                return ['token' => $token, 'message' => __('Heartbeat-token vernieuwd. Kopieer het token nu; het wordt niet opnieuw getoond.', 'uptime-monitor'), 'type' => 'updated'];
            }
        }

        return ['token' => '', 'message' => __('Heartbeat monitor niet gevonden.', 'uptime-monitor'), 'type' => 'error'];
    }

    private function sanitize_status_code_ranges($value): string
    {
        $tokens = preg_split('/[\s,]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $ranges = [];

        foreach ($tokens as $token) {
            if (preg_match('/^(\d{3})(?:-(\d{3}))?$/', $token, $matches) !== 1) {
                continue;
            }

            $start = (int)$matches[1];
            $end = isset($matches[2]) ? (int)$matches[2] : $start;
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            if ($start < 100 || $end > 599) {
                continue;
            }

            $ranges[] = $start === $end ? (string)$start : $start . '-' . $end;
        }

        $ranges = array_values(array_unique($ranges));

        return !empty($ranges) ? implode(',', $ranges) : self::DEFAULT_DOWN_STATUS_CODES;
    }

    private function is_down_status_code(int $status_code): bool
    {
        if ($status_code < 100 || $status_code > 599) {
            return true;
        }

        foreach (explode(',', $this->get_down_status_codes()) as $range) {
            $parts = array_map('absint', explode('-', $range));
            $start = $parts[0] ?? 0;
            $end = $parts[1] ?? $start;

            if ($status_code >= $start && $status_code <= $end) {
                return true;
            }
        }

        return false;
    }

    private function reschedule_monitoring(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    private function normalize_stored_urls(): array
    {
        $urls = $this->normalize_urls(get_option('uptime_monitor_urls', []));
        update_option('uptime_monitor_urls', $urls);
        $this->prune_monitor_state_to_urls($urls);

        return $urls;
    }

    private function normalize_urls($urls): array
    {
        if (!is_array($urls)) {
            return [];
        }

        $normalized = [];
        foreach ($urls as $url_data) {
            $record = $this->normalize_url_record($url_data);
            if ($record !== null) {
                $normalized[] = $record;
            }
        }

        return $normalized;
    }

    private function normalize_url_record($url_data): ?array
    {
        if (!is_array($url_data)) {
            return null;
        }

        $type = isset($url_data['type']) ? sanitize_key($url_data['type']) : 'http_check';
        if ($type === 'heartbeat') {
            return $this->normalize_heartbeat_record($url_data);
        }

        if (empty($url_data['url'])) {
            return null;
        }

        $url = $this->sanitize_monitor_url($url_data['url']);
        if ($url === null) {
            return null;
        }

        return [
            'id' => !empty($url_data['id']) ? sanitize_key($url_data['id']) : wp_generate_uuid4(),
            'type' => 'http_check',
            'url' => $url,
            'email' => !empty($url_data['email']),
            'pushover' => !empty($url_data['pushover']),
            'enabled' => array_key_exists('enabled', $url_data) ? (bool)$url_data['enabled'] : true,
        ];
    }

    private function normalize_heartbeat_record(array $url_data): ?array
    {
        $name = isset($url_data['name']) ? sanitize_text_field($url_data['name']) : '';
        if ($name === '' && !empty($url_data['url'])) {
            $name = sanitize_text_field((string)$url_data['url']);
        }
        if ($name === '') {
            return null;
        }

        $expected_interval = isset($url_data['expected_interval']) ? absint($url_data['expected_interval']) : self::DEFAULT_HEARTBEAT_INTERVAL;
        $expected_interval = min(86400, max(60, $expected_interval));
        $last_seen = isset($url_data['last_seen']) ? sanitize_text_field((string)$url_data['last_seen']) : '';
        $last_status = isset($url_data['last_status']) ? sanitize_key((string)$url_data['last_status']) : 'unknown';
        if (!in_array($last_status, ['up', 'down', 'error', 'unknown'], true)) {
            $last_status = 'unknown';
        }

        return [
            'id' => !empty($url_data['id']) ? sanitize_key($url_data['id']) : wp_generate_uuid4(),
            'type' => 'heartbeat',
            'name' => $name,
            'url' => '',
            'email' => !empty($url_data['email']),
            'pushover' => !empty($url_data['pushover']),
            'enabled' => array_key_exists('enabled', $url_data) ? (bool)$url_data['enabled'] : true,
            'expected_interval' => $expected_interval,
            'token_hash' => isset($url_data['token_hash']) ? sanitize_text_field((string)$url_data['token_hash']) : '',
            'token_last4' => isset($url_data['token_last4']) ? sanitize_text_field((string)$url_data['token_last4']) : '',
            'token_created_at' => isset($url_data['token_created_at']) ? sanitize_text_field((string)$url_data['token_created_at']) : '',
            'last_seen' => $last_seen,
            'last_status' => $last_status,
            'last_message' => isset($url_data['last_message']) ? sanitize_text_field((string)$url_data['last_message']) : '',
        ];
    }

    private function sanitize_monitor_url($url): ?string
    {
        $url = esc_url_raw(trim((string)$url), ['http', 'https']);
        if (!$url || !wp_http_validate_url($url)) {
            return null;
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function get_monitor_display_name(array $url_data): string
    {
        if (($url_data['type'] ?? 'http_check') === 'heartbeat') {
            return isset($url_data['name']) ? (string)$url_data['name'] : __('Heartbeat monitor', 'uptime-monitor');
        }

        return isset($url_data['url']) ? (string)$url_data['url'] : '';
    }

    private function get_heartbeat_last_seen_epoch(array $url_data): ?int
    {
        $last_seen = isset($url_data['last_seen']) ? (string)$url_data['last_seen'] : '';

        return $this->history_timestamp_to_epoch($last_seen);
    }

    private function is_heartbeat_stale(array $url_data): bool
    {
        if (($url_data['type'] ?? 'http_check') !== 'heartbeat') {
            return false;
        }

        if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
            return false;
        }

        $last_seen_epoch = $this->get_heartbeat_last_seen_epoch($url_data);
        if ($last_seen_epoch === null) {
            return true;
        }

        $expected_interval = isset($url_data['expected_interval']) ? absint($url_data['expected_interval']) : self::DEFAULT_HEARTBEAT_INTERVAL;
        $stale_after = max(120, $expected_interval * 2);

        return (time() - $last_seen_epoch) > $stale_after;
    }

    private function get_log_safe_monitor_records(array $urls): array
    {
        return array_map(function (array $url_data): array {
            unset($url_data['token_hash']);

            return $url_data;
        }, $urls);
    }

    /**
     * Adds a menu page for the plugin in the WordPress admin.
     */
    public function add_menu_page(): void
    {
        add_menu_page(
            __('Uptime Monitor', 'uptime-monitor'),
            __('Uptime Monitor', 'uptime-monitor'),
            'manage_options',
            'uptime-monitor',
            [$this, 'render_urls_page'],
            'dashicons-admin-site-alt3'
        );
        add_submenu_page(
            'uptime-monitor',
            __('Settings', 'uptime-monitor'),
            __('Settings', 'uptime-monitor'),
            'manage_options',
            'uptime-monitor-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_dashboard_widget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'uptime_monitor_dashboard_widget',
            __('Uptime Monitor', 'uptime-monitor'),
            [$this, 'render_dashboard_widget']
        );
    }

    /**
     * Enqueues styles and scripts for the admin interface.
     *
     * @param string $hook_suffix The current admin page hook.
     */
    public function enqueue_styles($hook_suffix): void
    {
        $plugin_pages = [
            'toplevel_page_uptime-monitor',
            'uptime-monitor_page_uptime-monitor-settings',
        ];

        if ($hook_suffix === 'index.php' || in_array($hook_suffix, $plugin_pages, true)) {
            $style_file = plugin_dir_path(__FILE__) . 'css/uptime-monitor.css';
            $style_version = file_exists($style_file) ? (string)filemtime($style_file) : self::VERSION;
            wp_enqueue_style('uptime-monitor-styles', plugin_dir_url(__FILE__) . 'css/uptime-monitor.css', [], $style_version);
        }

        if ($hook_suffix === 'index.php' || in_array($hook_suffix, $plugin_pages, true)) {
            $script_file = plugin_dir_path(__FILE__) . 'js/uptime-monitor.js';
            $script_version = file_exists($script_file) ? (string)filemtime($script_file) : self::VERSION;
            wp_enqueue_script('uptime-monitor-scripts', plugin_dir_url(__FILE__) . 'js/uptime-monitor.js', ['jquery'], $script_version, true);
            // Localize AJAX script
            wp_localize_script('uptime-monitor-scripts', 'uptimeMonitorAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('uptime_monitor_nonce'),
                'refresh_interval_ms' => 30000,
            ]);
            // Localize script for translations
            wp_localize_script('uptime-monitor-scripts', 'uptimeMonitorL10n', [
                'add_success' => __('URL succesvol toegevoegd.', 'uptime-monitor'),
                'delete_success' => __('URL succesvol verwijderd.', 'uptime-monitor'),
                'error' => __('Er is een fout opgetreden: ', 'uptime-monitor'),
                'error_generic' => __('Er is een algemene fout opgetreden. Probeer het opnieuw.', 'uptime-monitor'),
                'no_urls' => __('Geen URLs beschikbaar. Voeg er een toe.', 'uptime-monitor'),
                'delete' => __('Verwijderen', 'uptime-monitor'),
                'enabled' => __('Ingeschakeld', 'uptime-monitor'),
                'disabled' => __('Uitgeschakeld', 'uptime-monitor'),
                'history' => __('Statusgeschiedenis', 'uptime-monitor'),
                'no_history' => __('Nog geen controles.', 'uptime-monitor'),
                'status_up' => __('Online', 'uptime-monitor'),
                'status_down' => __('Down', 'uptime-monitor'),
                'status_error' => __('Fout', 'uptime-monitor'),
                /* translators: %d: HTTP status code. */
                'http_status' => __('HTTP %d', 'uptime-monitor'),
                /* translators: %d: Response time in milliseconds. */
                'response_time_ms' => __('%d ms', 'uptime-monitor'),
                /* translators: %d: Average response time in milliseconds. */
                'average_response_time_ms' => __('Gem. %d ms', 'uptime-monitor'),
                'trend_faster' => __('Sneller', 'uptime-monitor'),
                'trend_slower' => __('Langzamer', 'uptime-monitor'),
                'trend_stable' => __('Stabiel', 'uptime-monitor'),
                'uptime' => __('Uptime', 'uptime-monitor'),
                'status_paused' => __('Gepauzeerd', 'uptime-monitor'),
                'status_unknown' => __('Onbekend', 'uptime-monitor'),
                'status_degraded' => __('Verminderd', 'uptime-monitor'),
                'operational' => __('Operationeel', 'uptime-monitor'),
                'incident_active' => __('Incident actief', 'uptime-monitor'),
                'no_incident' => __('Geen incident', 'uptime-monitor'),
                'incident_open' => __('Incident open', 'uptime-monitor'),
                'no_alerts' => __('Geen alarmen', 'uptime-monitor'),
                'details' => __('Details', 'uptime-monitor'),
                'hide_details' => __('Details verbergen', 'uptime-monitor'),
                'monitoring' => __('Monitoring', 'uptime-monitor'),
                'dismiss' => __('Deze melding sluiten.', 'uptime-monitor'),
                'copy_success' => __('Gekopieerd naar het klembord.', 'uptime-monitor'),
                'copy_failed' => __('Kopieren naar het klembord is mislukt.', 'uptime-monitor'),
                'refresh_now' => __('Nu verversen', 'uptime-monitor'),
                'refreshing' => __('Verversen...', 'uptime-monitor'),
                'refreshed' => __('Net bijgewerkt.', 'uptime-monitor'),
                'auto_refresh' => __('Auto-refresh', 'uptime-monitor'),
                'heartbeat' => __('Heartbeat', 'uptime-monitor'),
                'http_check' => __('HTTP check', 'uptime-monitor'),
                /* translators: %d: Expected heartbeat interval in seconds. */
                'expected_every_seconds' => __('Elke %d seconden verwacht', 'uptime-monitor'),
            ]);
        }
    }

    private function get_status_history(): array
    {
        $history = get_option('uptime_monitor_history', []);

        return is_array($history) ? $history : [];
    }

    private function get_status_history_for_url(string $url_id): array
    {
        $history = $this->get_status_history();
        $url_history = isset($history[$url_id]) && is_array($history[$url_id]) ? $history[$url_id] : [];

        return array_map(
            [$this, 'normalize_status_history_entry'],
            array_values(array_slice($url_history, -self::MAX_STATUS_HISTORY_PER_URL))
        );
    }

    private function normalize_status_history_entry($entry): array
    {
        $entry = is_array($entry) ? $entry : [];
        $timestamp = isset($entry['timestamp']) ? sanitize_text_field($entry['timestamp']) : '';

        return [
            'timestamp' => $timestamp,
            'display_timestamp' => $timestamp !== '' ? $this->format_history_timestamp($timestamp) : '',
            'status' => isset($entry['status']) ? sanitize_key($entry['status']) : 'error',
            'status_code' => isset($entry['status_code']) ? absint($entry['status_code']) : null,
            'response_time_ms' => isset($entry['response_time_ms']) ? absint($entry['response_time_ms']) : null,
            'message' => isset($entry['message']) ? sanitize_text_field($entry['message']) : '',
        ];
    }

    private function add_status_history_to_urls(array $urls): array
    {
        $incidents = $this->get_incidents();
        foreach ($urls as &$url_data) {
            $history = $this->get_status_history_for_url($url_data['id']);
            $url_data['history'] = $history;
            $url_data['uptime'] = $this->get_uptime_percentages($history);
            $url_data['dashboard'] = $this->get_url_dashboard_data($url_data, $history, $incidents);
        }
        unset($url_data);

        return $urls;
    }

    private function record_status_history(array $url_data, string $status, ?int $status_code, ?int $response_time_ms, string $message): void
    {
        $url_id = isset($url_data['id']) ? sanitize_key($url_data['id']) : '';
        if ($url_id === '') {
            return;
        }

        $allowed_statuses = ['up', 'down', 'error'];
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'error';
        }

        $history = $this->get_status_history();
        $url_history = isset($history[$url_id]) && is_array($history[$url_id]) ? $history[$url_id] : [];
        $url_history[] = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'status' => $status,
            'status_code' => $status_code,
            'response_time_ms' => $response_time_ms !== null ? max(0, $response_time_ms) : null,
            'message' => sanitize_text_field($message),
        ];

        $history[$url_id] = $this->prune_status_history($url_history);
        update_option('uptime_monitor_history', $history, false);
    }

    private function prune_status_history(array $history): array
    {
        $cutoff = time() - (self::MAX_STATUS_HISTORY_DAYS * 24 * 60 * 60);
        $filtered = [];

        foreach ($history as $entry) {
            $entry = $this->normalize_status_history_entry($entry);
            $timestamp = $this->history_timestamp_to_epoch($entry['timestamp']);
            if ($timestamp === null || $timestamp >= $cutoff) {
                $filtered[] = $entry;
            }
        }

        return array_values(array_slice($filtered, -self::MAX_STATUS_HISTORY_PER_URL));
    }

    private function history_timestamp_to_epoch(string $timestamp): ?int
    {
        if ($timestamp === '') {
            return null;
        }

        $epoch = strtotime($timestamp . ' UTC');

        return $epoch !== false ? $epoch : null;
    }

    private function delete_status_history_for_url(string $url_id): void
    {
        $history = $this->get_status_history();
        if (isset($history[$url_id])) {
            unset($history[$url_id]);
            update_option('uptime_monitor_history', $history, false);
        }
    }

    private function get_incidents(): array
    {
        $incidents = get_option('uptime_monitor_incidents', []);

        return is_array($incidents) ? $incidents : [];
    }

    private function delete_incident_for_url(string $url_id): void
    {
        $incidents = $this->get_incidents();
        if (isset($incidents[$url_id])) {
            unset($incidents[$url_id]);
            update_option('uptime_monitor_incidents', $incidents, false);
        }
    }

    private function prune_monitor_state_to_urls(array $urls): void
    {
        $valid_ids = [];
        foreach ($urls as $url_data) {
            if (!empty($url_data['id'])) {
                $valid_ids[sanitize_key($url_data['id'])] = true;
            }
        }

        $history = $this->get_status_history();
        $history_changed = false;
        foreach (array_keys($history) as $url_id) {
            if (!isset($valid_ids[$url_id])) {
                unset($history[$url_id]);
                $history_changed = true;
            }
        }
        if ($history_changed) {
            update_option('uptime_monitor_history', $history, false);
        }

        $incidents = $this->get_incidents();
        $incidents_changed = false;
        foreach (array_keys($incidents) as $url_id) {
            if (!isset($valid_ids[$url_id])) {
                unset($incidents[$url_id]);
                $incidents_changed = true;
            }
        }
        if ($incidents_changed) {
            update_option('uptime_monitor_incidents', $incidents, false);
        }
    }

    private function handle_up_incident_state(array $url_data, ?int $status_code): void
    {
        $url_id = isset($url_data['id']) ? sanitize_key($url_data['id']) : '';
        if ($url_id === '') {
            return;
        }

        $incidents = $this->get_incidents();
        if (!isset($incidents[$url_id]) || !is_array($incidents[$url_id])) {
            return;
        }

        $monitor_name = $this->get_monitor_display_name($url_data);
        if (!empty($url_data['email'])) {
            $this->send_email_recovery_alert($monitor_name, $status_code);
        }
        if (!empty($url_data['pushover'])) {
            $this->send_pushover_recovery_alert($monitor_name, $status_code);
        }

        unset($incidents[$url_id]);
        update_option('uptime_monitor_incidents', $incidents, false);
        $this->log_to_json('info', 'Recovery alert sent and incident reset.', [
            'monitor' => $monitor_name,
            'status_code' => $status_code,
        ]);
    }

    private function handle_down_incident_state(array $url_data, ?int $status_code, string $error_message = ''): void
    {
        $url_id = isset($url_data['id']) ? sanitize_key($url_data['id']) : '';
        if ($url_id === '') {
            return;
        }

        $incidents = $this->get_incidents();
        $incident = isset($incidents[$url_id]) && is_array($incidents[$url_id]) ? $incidents[$url_id] : [
            'status' => 'down',
            'down_since' => gmdate('Y-m-d H:i:s'),
            'alert_count' => 0,
        ];

        $alert_count = isset($incident['alert_count']) ? absint($incident['alert_count']) : 0;
        $should_send_alert = $alert_count < self::MAX_INCIDENT_ALERTS;
        $is_final_alert = ($alert_count + 1) >= self::MAX_INCIDENT_ALERTS;
        $monitor_name = $this->get_monitor_display_name($url_data);

        if ($should_send_alert) {
            if (!empty($url_data['email'])) {
                $this->send_email_alert($monitor_name, $status_code, $error_message, $is_final_alert);
            }
            if (!empty($url_data['pushover'])) {
                $this->send_pushover_alert($monitor_name, $status_code, $error_message, $is_final_alert);
            }

            $alert_count++;
            $incident['last_alert_at'] = gmdate('Y-m-d H:i:s');
            $this->log_to_json('info', 'Down alert processed.', [
                'monitor' => $monitor_name,
                'alert_count' => $alert_count,
                'final_alert_until_recovery' => $is_final_alert,
            ]);
        } else {
            $this->log_to_json('info', 'Down alert suppressed until recovery.', [
                'monitor' => $monitor_name,
                'alert_count' => $alert_count,
            ]);
        }

        $incident['status'] = 'down';
        $incident['alert_count'] = $alert_count;
        $incident['last_status_code'] = $status_code;
        $incident['last_error'] = sanitize_text_field($error_message);
        $incident['last_seen_at'] = gmdate('Y-m-d H:i:s');
        $incidents[$url_id] = $incident;
        update_option('uptime_monitor_incidents', $incidents, false);
    }

    private function format_history_timestamp(string $timestamp): string
    {
        $epoch = $this->history_timestamp_to_epoch($timestamp);
        if ($epoch === null) {
            return $timestamp . ' UTC';
        }

        $formatted = $this->format_epoch_for_display($epoch);

        return $formatted ?: $timestamp . ' UTC';
    }

    private function format_epoch_for_display(int $epoch): string
    {
        $format = 'j F Y H:i';
        $offset = $this->get_display_timezone_offset($epoch);

        return date_i18n($format, $epoch + $offset, true);
    }

    private function get_display_timezone_offset(int $epoch): int
    {
        $timezone_name = get_option('timezone_string');
        if (!is_string($timezone_name) || $timezone_name === '' || $timezone_name === 'UTC') {
            $timezone_name = 'Europe/Amsterdam';
        }

        $timezone = timezone_open($timezone_name);
        $datetime = date_create('@' . $epoch);
        if ($timezone === false || $datetime === false) {
            return 2 * HOUR_IN_SECONDS;
        }

        return timezone_offset_get($timezone, $datetime);
    }

    private function get_status_label(string $status): string
    {
        switch ($status) {
            case 'up':
                return __('Up', 'uptime-monitor');
            case 'down':
                return __('Down', 'uptime-monitor');
            default:
                return __('Error', 'uptime-monitor');
        }
    }

    private function get_dashboard_status_label(string $status): string
    {
        switch ($status) {
            case 'up':
                return __('Up', 'uptime-monitor');
            case 'down':
                return __('Down', 'uptime-monitor');
            case 'paused':
                return __('Paused', 'uptime-monitor');
            case 'degraded':
                return __('Degraded', 'uptime-monitor');
            default:
                return __('Unknown', 'uptime-monitor');
        }
    }

    private function get_latest_history_entry(array $history): ?array
    {
        if (empty($history)) {
            return null;
        }

        return $this->normalize_status_history_entry($history[count($history) - 1]);
    }

    private function get_url_dashboard_data(array $url_data, array $history, array $incidents): array
    {
        $url_id = isset($url_data['id']) ? sanitize_key($url_data['id']) : '';
        $type = isset($url_data['type']) ? sanitize_key($url_data['type']) : 'http_check';
        $latest = $this->get_latest_history_entry($history);
        $incident_open = $url_id !== '' && isset($incidents[$url_id]);
        $incident = $incident_open && is_array($incidents[$url_id]) ? $incidents[$url_id] : [];
        $status = 'unknown';
        $incident_label = $incident_open ? __('Incident open', 'uptime-monitor') : __('No incident', 'uptime-monitor');
        $incident_duration_display = $this->get_incident_duration_display($incident);

        if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
            $status = 'paused';
        } elseif ($type === 'heartbeat') {
            $heartbeat_stale = $this->is_heartbeat_stale($url_data);
            $last_status = isset($url_data['last_status']) ? sanitize_key((string)$url_data['last_status']) : 'unknown';

            if ($latest === null && !empty($url_data['last_seen'])) {
                $last_seen = (string)$url_data['last_seen'];
                $latest = [
                    'timestamp' => $last_seen,
                    'display_timestamp' => $this->format_history_timestamp($last_seen),
                    'status' => in_array($last_status, ['up', 'down', 'error'], true) ? $last_status : 'up',
                    'status_code' => null,
                    'response_time_ms' => null,
                    'message' => isset($url_data['last_message']) ? (string)$url_data['last_message'] : '',
                ];
            }

            if ($heartbeat_stale) {
                $status = 'down';
                $incident_open = true;
                $incident_label = __('Heartbeat stale', 'uptime-monitor');
                $last_seen_epoch = $this->get_heartbeat_last_seen_epoch($url_data);
                $incident_duration_display = $last_seen_epoch !== null
                    ? sprintf(__('Missing for %s', 'uptime-monitor'), human_time_diff($last_seen_epoch, time()))
                    : __('No ping received', 'uptime-monitor');
            } elseif (in_array($last_status, ['down', 'error'], true)) {
                $status = 'down';
            } elseif ($last_status === 'up' || $latest !== null) {
                $status = 'up';
            }
        } elseif ($incident_open) {
            $status = 'down';
        } elseif ($latest !== null) {
            $status = $latest['status'] === 'up' ? 'up' : 'down';
            if ($status === 'up' && $latest['response_time_ms'] !== null && $latest['response_time_ms'] >= 1000) {
                $status = 'degraded';
            }
        }

        $average_response_time_ms = $this->get_average_response_time_ms($history);
        $average_response_display = '';
        if ($average_response_time_ms !== null) {
            /* translators: %d: Average response time in milliseconds. */
            $average_response_display = sprintf(__('Avg %d ms', 'uptime-monitor'), $average_response_time_ms);
        }

        return [
            'status' => $status,
            'status_label' => $this->get_dashboard_status_label($status),
            'latest' => $latest,
            'average_response_time_ms' => $average_response_time_ms,
            'average_response_time_display' => $average_response_display,
            'incident_open' => $incident_open,
            'incident_label' => $incident_label,
            'incident_duration_display' => $incident_duration_display,
            'notifications_label' => $this->get_notifications_label($url_data),
        ];
    }

    private function get_incident_duration_display(array $incident): string
    {
        $down_since = isset($incident['down_since']) ? sanitize_text_field($incident['down_since']) : '';
        if ($down_since === '') {
            return '';
        }

        $down_since_epoch = $this->history_timestamp_to_epoch($down_since);
        if ($down_since_epoch === null) {
            return '';
        }

        /* translators: %s: Human-readable incident duration. */
        return sprintf(__('Down for %s', 'uptime-monitor'), human_time_diff($down_since_epoch, time()));
    }

    private function get_notifications_label(array $url_data): string
    {
        $channels = [];
        if (!empty($url_data['email'])) {
            $channels[] = __('Email', 'uptime-monitor');
        }
        if (!empty($url_data['pushover'])) {
            $channels[] = __('Pushover', 'uptime-monitor');
        }

        return !empty($channels) ? implode(', ', $channels) : __('No alerts', 'uptime-monitor');
    }

    private function get_dashboard_summary(array $urls): array
    {
        $urls = $this->add_status_history_to_urls($urls);
        $urls = $this->sort_dashboard_urls($urls);
        $total = count($urls);
        $up = 0;
        $down = 0;
        $paused = 0;
        $degraded = 0;
        $incident_count = 0;
        $email_count = 0;
        $pushover_count = 0;
        $response_times = [];
        $uptime_24h = [];
        $latest_timestamp = null;

        foreach ($urls as $url_data) {
            $dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
            $status = isset($dashboard['status']) ? $dashboard['status'] : 'unknown';

            if ($status === 'up') {
                $up++;
            } elseif ($status === 'down') {
                $down++;
            } elseif ($status === 'paused') {
                $paused++;
            } elseif ($status === 'degraded') {
                $degraded++;
            }

            if (!empty($dashboard['incident_open'])) {
                $incident_count++;
            }
            if (!empty($url_data['email'])) {
                $email_count++;
            }
            if (!empty($url_data['pushover'])) {
                $pushover_count++;
            }
            if (isset($dashboard['average_response_time_ms']) && $dashboard['average_response_time_ms'] !== null) {
                $response_times[] = $dashboard['average_response_time_ms'];
            }
            if (isset($url_data['uptime']['24h']['percentage']) && $url_data['uptime']['24h']['percentage'] !== null) {
                $uptime_24h[] = $url_data['uptime']['24h']['percentage'];
            }
            if (isset($dashboard['latest']['timestamp'])) {
                $timestamp = $this->history_timestamp_to_epoch((string)$dashboard['latest']['timestamp']);
                if ($timestamp !== null && ($latest_timestamp === null || $timestamp > $latest_timestamp)) {
                    $latest_timestamp = $timestamp;
                }
            }
        }

        $active = max(0, $total - $paused);
        $health_percentage = $active > 0 ? round((($up + $degraded) / $active) * 100, 1) : null;
        $average_response = !empty($response_times) ? (int)round(array_sum($response_times) / count($response_times)) : null;
        $average_uptime_24h = !empty($uptime_24h) ? round(array_sum($uptime_24h) / count($uptime_24h), 1) : null;
        $overall_status = $down > 0 ? 'incident' : ($degraded > 0 ? 'degraded' : 'operational');
        $next_check = wp_next_scheduled(self::CRON_HOOK);
        $average_response_display = __('No data', 'uptime-monitor');
        if ($average_response !== null) {
            /* translators: %d: Average response time in milliseconds. */
            $average_response_display = sprintf(__('Avg %d ms', 'uptime-monitor'), $average_response);
        }

        return [
            'urls' => $urls,
            'total' => $total,
            'active' => $active,
            'up' => $up,
            'down' => $down,
            'paused' => $paused,
            'degraded' => $degraded,
            'incidents' => $incident_count,
            'email_count' => $email_count,
            'pushover_count' => $pushover_count,
            'health_percentage' => $health_percentage,
            'health_display' => $health_percentage !== null ? number_format_i18n($health_percentage, 1) . '%' : __('No data', 'uptime-monitor'),
            'average_response_ms' => $average_response,
            'average_response_display' => $average_response_display,
            'uptime_24h' => $average_uptime_24h,
            'uptime_24h_display' => $average_uptime_24h !== null ? number_format_i18n($average_uptime_24h, 1) . '%' : __('No data', 'uptime-monitor'),
            'latest_check_display' => $latest_timestamp !== null ? $this->format_epoch_for_display($latest_timestamp) : __('No checks yet.', 'uptime-monitor'),
            'next_check_display' => $next_check ? $this->format_epoch_for_display($next_check) : __('Not scheduled', 'uptime-monitor'),
            'overall_status' => $overall_status,
            'overall_label' => $overall_status === 'incident'
                ? __('Incident active', 'uptime-monitor')
                : ($overall_status === 'degraded' ? __('Degraded', 'uptime-monitor') : __('Operational', 'uptime-monitor')),
        ];
    }

    private function sort_dashboard_urls(array $urls): array
    {
        foreach ($urls as $index => &$url_data) {
            $url_data['_dashboard_sort_index'] = $index;
        }
        unset($url_data);

        usort($urls, function (array $a, array $b): int {
            $priority_difference = $this->get_dashboard_url_priority($a) <=> $this->get_dashboard_url_priority($b);
            if ($priority_difference !== 0) {
                return $priority_difference;
            }

            return ((int)($a['_dashboard_sort_index'] ?? 0)) <=> ((int)($b['_dashboard_sort_index'] ?? 0));
        });

        foreach ($urls as &$url_data) {
            unset($url_data['_dashboard_sort_index']);
        }
        unset($url_data);

        return $urls;
    }

    private function get_dashboard_url_priority(array $url_data): int
    {
        $dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
        if (!empty($dashboard['incident_open'])) {
            return 0;
        }

        $status = isset($dashboard['status']) ? sanitize_key($dashboard['status']) : 'unknown';
        $priorities = [
            'down' => 1,
            'degraded' => 2,
            'unknown' => 3,
            'up' => 4,
            'paused' => 5,
        ];

        return $priorities[$status] ?? 6;
    }

    private function get_dashboard_ajax_payload(array $urls): array
    {
        $dashboard = $this->get_dashboard_summary($urls);

        ob_start();
        $this->render_dashboard_overview($dashboard);
        $dashboard_html = ob_get_clean();

        ob_start();
        $this->render_dashboard_widget_content($dashboard);
        $widget_html = ob_get_clean();

        return [
            'urls' => $dashboard['urls'],
            'dashboard_html' => $dashboard_html,
            'widget_html' => $widget_html,
        ];
    }

    public function register_rest_routes(): void
    {
        register_rest_route('uptime-monitor/v1', '/status', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_get_status'],
            'permission_callback' => [$this, 'rest_check_permissions'],
        ]);

        register_rest_route('uptime-monitor/v1', '/status/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_get_url_status'],
            'permission_callback' => [$this, 'rest_check_permissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route('uptime-monitor/v1', '/heartbeat', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_receive_heartbeat'],
            'permission_callback' => [$this, 'rest_check_heartbeat_permissions'],
        ]);
    }

    public function rest_check_permissions(\WP_REST_Request $request = null): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return $request instanceof \WP_REST_Request && self::request_has_valid_read_api_token($request);
    }

    public static function request_has_valid_read_api_token(\WP_REST_Request $request): bool
    {
        return self::verify_read_api_token(self::get_request_token($request));
    }

    public function rest_check_heartbeat_permissions(\WP_REST_Request $request)
    {
        $token = self::get_request_token($request);
        if ($token === '') {
            return new \WP_Error('uptime_monitor_heartbeat_token_missing', __('Heartbeat token is missing.', 'uptime-monitor'), ['status' => 401]);
        }

        if ($this->find_heartbeat_monitor_index_by_token($this->normalize_stored_urls(), $token) === null) {
            return new \WP_Error('uptime_monitor_heartbeat_token_invalid', __('Heartbeat token is invalid.', 'uptime-monitor'), ['status' => 403]);
        }

        return true;
    }

    public function rest_get_status(\WP_REST_Request $request): \WP_REST_Response
    {
        $dashboard = $this->get_dashboard_summary($this->normalize_stored_urls());

        return new \WP_REST_Response($this->get_rest_status_payload($dashboard), 200);
    }

    public function rest_get_url_status(\WP_REST_Request $request)
    {
        $id = sanitize_key((string)$request->get_param('id'));
        $dashboard = $this->get_dashboard_summary($this->normalize_stored_urls());

        foreach ($dashboard['urls'] as $url_data) {
            if (isset($url_data['id']) && $url_data['id'] === $id) {
                return new \WP_REST_Response($this->get_rest_url_payload($url_data), 200);
            }
        }

        return new \WP_Error('uptime_monitor_url_not_found', __('URL not found.', 'uptime-monitor'), ['status' => 404]);
    }

    public function rest_receive_heartbeat(\WP_REST_Request $request)
    {
        $token = self::get_request_token($request);
        if ($token === '') {
            return new \WP_Error('uptime_monitor_heartbeat_token_missing', __('Heartbeat token is missing.', 'uptime-monitor'), ['status' => 401]);
        }

        $urls = $this->normalize_stored_urls();
        $matched_index = $this->find_heartbeat_monitor_index_by_token($urls, $token);

        if ($matched_index === null) {
            return new \WP_Error('uptime_monitor_heartbeat_token_invalid', __('Heartbeat token is invalid.', 'uptime-monitor'), ['status' => 403]);
        }

        $url_data = $urls[$matched_index];
        if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
            return new \WP_Error('uptime_monitor_heartbeat_disabled', __('Heartbeat monitor is disabled.', 'uptime-monitor'), ['status' => 403]);
        }

        $status = sanitize_key((string)$request->get_param('status'));
        if (!in_array($status, ['up', 'down', 'error'], true)) {
            $status = 'up';
        }

        $message = sanitize_text_field((string)$request->get_param('message'));
        $timestamp = gmdate('Y-m-d H:i:s');
        $url_data['last_seen'] = $timestamp;
        $url_data['last_status'] = $status;
        $url_data['last_message'] = $message;
        $urls[$matched_index] = $url_data;
        update_option('uptime_monitor_urls', $urls);

        $history_message = $message !== '' ? $message : __('Heartbeat received.', 'uptime-monitor');
        $this->record_status_history($url_data, $status, null, null, $history_message);

        if ($status === 'up') {
            $this->handle_up_incident_state($url_data, null);
        } else {
            $this->handle_down_incident_state($url_data, null, $history_message);
        }

        $this->log_to_json('info', 'Heartbeat received.', [
            'id' => $url_data['id'],
            'name' => $this->get_monitor_display_name($url_data),
            'status' => $status,
        ]);

        return new \WP_REST_Response([
            'success' => true,
            'id' => $url_data['id'],
            'name' => $this->get_monitor_display_name($url_data),
            'status' => $status,
            'last_seen' => $timestamp,
        ], 200);
    }

    private function get_rest_status_payload(array $dashboard): array
    {
        return [
            'generated_at' => gmdate('c'),
            'overall' => [
                'status' => $dashboard['overall_status'],
                'label' => $dashboard['overall_label'],
                'latest_check' => $dashboard['latest_check_display'],
                'next_check' => $dashboard['next_check_display'],
            ],
            'counts' => [
                'total' => $dashboard['total'],
                'active' => $dashboard['active'],
                'up' => $dashboard['up'],
                'down' => $dashboard['down'],
                'paused' => $dashboard['paused'],
                'degraded' => $dashboard['degraded'],
                'incidents' => $dashboard['incidents'],
            ],
            'metrics' => [
                'health_percentage' => $dashboard['health_percentage'],
                'health_display' => $dashboard['health_display'],
                'average_response_ms' => $dashboard['average_response_ms'],
                'average_response_display' => $dashboard['average_response_display'],
                'uptime_24h' => $dashboard['uptime_24h'],
                'uptime_24h_display' => $dashboard['uptime_24h_display'],
            ],
            'settings' => [
                'monitor_interval' => $this->get_monitor_interval(),
                'retry_attempts' => $this->get_retry_attempts(),
                'request_timeout' => $this->get_request_timeout(),
                'down_status_codes' => $this->get_down_status_codes(),
            ],
            'urls' => array_map([$this, 'get_rest_url_payload'], $dashboard['urls']),
        ];
    }

    private function get_rest_url_payload(array $url_data): array
    {
        $dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
        $latest = isset($dashboard['latest']) && is_array($dashboard['latest']) ? $dashboard['latest'] : null;

        return [
            'id' => isset($url_data['id']) ? (string)$url_data['id'] : '',
            'type' => isset($url_data['type']) ? (string)$url_data['type'] : 'http_check',
            'name' => isset($url_data['name']) ? (string)$url_data['name'] : '',
            'url' => isset($url_data['url']) ? (string)$url_data['url'] : '',
            'enabled' => !isset($url_data['enabled']) || (bool)$url_data['enabled'],
            'heartbeat' => ($url_data['type'] ?? 'http_check') === 'heartbeat' ? [
                'expected_interval' => isset($url_data['expected_interval']) ? absint($url_data['expected_interval']) : self::DEFAULT_HEARTBEAT_INTERVAL,
                'last_seen' => isset($url_data['last_seen']) ? (string)$url_data['last_seen'] : '',
                'last_status' => isset($url_data['last_status']) ? (string)$url_data['last_status'] : 'unknown',
                'last_message' => isset($url_data['last_message']) ? (string)$url_data['last_message'] : '',
            ] : null,
            'alerts' => [
                'email' => !empty($url_data['email']),
                'pushover' => !empty($url_data['pushover']),
                'label' => isset($dashboard['notifications_label']) ? $dashboard['notifications_label'] : $this->get_notifications_label($url_data),
            ],
            'status' => [
                'value' => isset($dashboard['status']) ? $dashboard['status'] : 'unknown',
                'label' => isset($dashboard['status_label']) ? $dashboard['status_label'] : __('Unknown', 'uptime-monitor'),
            ],
            'latest_check' => $latest,
            'uptime' => isset($url_data['uptime']) && is_array($url_data['uptime']) ? $url_data['uptime'] : [],
            'average_response_ms' => isset($dashboard['average_response_time_ms']) ? $dashboard['average_response_time_ms'] : null,
            'average_response_display' => isset($dashboard['average_response_time_display']) ? $dashboard['average_response_time_display'] : '',
            'incident' => [
                'open' => !empty($dashboard['incident_open']),
                'label' => isset($dashboard['incident_label']) ? $dashboard['incident_label'] : __('No incident', 'uptime-monitor'),
                'duration' => isset($dashboard['incident_duration_display']) ? $dashboard['incident_duration_display'] : '',
            ],
        ];
    }

    private function get_average_response_time_ms(array $history): ?int
    {
        $response_times = [];
        foreach ($history as $entry) {
            $entry = $this->normalize_status_history_entry($entry);
            if ($entry['response_time_ms'] !== null) {
                $response_times[] = $entry['response_time_ms'];
            }
        }

        if (empty($response_times)) {
            return null;
        }

        return (int)round(array_sum($response_times) / count($response_times));
    }

    private function get_response_time_trend(array $history): ?string
    {
        $response_times = [];
        foreach ($history as $entry) {
            $entry = $this->normalize_status_history_entry($entry);
            if ($entry['response_time_ms'] !== null) {
                $response_times[] = $entry['response_time_ms'];
            }
        }

        if (count($response_times) < 2) {
            return null;
        }

        $latest = array_pop($response_times);
        $previous_average = array_sum($response_times) / count($response_times);
        $threshold = max(50, $previous_average * 0.1);

        if ($latest > $previous_average + $threshold) {
            return 'slower';
        }
        if ($latest < $previous_average - $threshold) {
            return 'faster';
        }

        return 'stable';
    }

    private function get_response_time_trend_label(string $trend): string
    {
        switch ($trend) {
            case 'faster':
                return __('Faster', 'uptime-monitor');
            case 'slower':
                return __('Slower', 'uptime-monitor');
            default:
                return __('Stable', 'uptime-monitor');
        }
    }

    private function get_uptime_percentages(array $history): array
    {
        $periods = [
            '24h' => [
                'label' => __('24h', 'uptime-monitor'),
                'seconds' => 24 * 60 * 60,
            ],
            '7d' => [
                'label' => __('7d', 'uptime-monitor'),
                'seconds' => 7 * 24 * 60 * 60,
            ],
            '30d' => [
                'label' => __('30d', 'uptime-monitor'),
                'seconds' => 30 * 24 * 60 * 60,
            ],
        ];
        $now = time();
        $uptime = [];

        foreach ($periods as $period_key => $period) {
            $period_start = $now - $period['seconds'];
            $total_checks = 0;
            $up_checks = 0;

            foreach ($history as $entry) {
                $entry = $this->normalize_status_history_entry($entry);
                $timestamp = $this->history_timestamp_to_epoch($entry['timestamp']);
                if ($timestamp === null || $timestamp < $period_start) {
                    continue;
                }

                $total_checks++;
                if ($entry['status'] === 'up') {
                    $up_checks++;
                }
            }

            $percentage = $total_checks > 0 ? round(($up_checks / $total_checks) * 100, 1) : null;
            $uptime[$period_key] = [
                'label' => $period['label'],
                'percentage' => $percentage,
                'percentage_display' => $percentage !== null ? number_format_i18n($percentage, 1) : '',
                'total_checks' => $total_checks,
            ];
        }

        return $uptime;
    }

    private function render_uptime_summary(array $history): void
    {
        $uptime = $this->get_uptime_percentages($history);
        $has_uptime_data = false;

        foreach ($uptime as $period) {
            if ($period['percentage'] !== null) {
                $has_uptime_data = true;
                break;
            }
        }

        if (!$has_uptime_data) {
            return;
        }

        echo '<div class="uptime-percentage-summary" aria-label="' . esc_attr__('Uptime', 'uptime-monitor') . '">';
        foreach ($uptime as $period_key => $period) {
            if ($period['percentage'] === null) {
                continue;
            }

            echo '<span class="uptime-percentage uptime-percentage-' . esc_attr($period_key) . '">';
            echo '<span class="uptime-percentage-label">' . esc_html($period['label']) . '</span>';
            echo '<span class="uptime-percentage-value">' . esc_html($period['percentage_display']) . '%</span>';
            echo '</span>';
        }
        echo '</div>';
    }

    private function render_status_history_cell(array $history): void
    {
        if (empty($history)) {
            echo '<span class="uptime-history-empty">' . esc_html__('No checks yet.', 'uptime-monitor') . '</span>';
            return;
        }

        $this->render_uptime_summary($history);

        $average_response_time_ms = $this->get_average_response_time_ms($history);
        $response_time_trend = $this->get_response_time_trend($history);
        if ($average_response_time_ms !== null) {
            echo '<div class="uptime-response-summary">';
            /* translators: %d: Average response time in milliseconds. */
            echo '<span class="uptime-response-average">' . esc_html(sprintf(__('Avg %d ms', 'uptime-monitor'), $average_response_time_ms)) . '</span>';
            if ($response_time_trend !== null) {
                echo '<span class="uptime-response-trend uptime-response-trend-' . esc_attr($response_time_trend) . '">' . esc_html($this->get_response_time_trend_label($response_time_trend)) . '</span>';
            }
            echo '</div>';
        }

        echo '<ol class="uptime-history-list">';
        foreach (array_reverse(array_slice($history, -5)) as $entry) {
            $entry = $this->normalize_status_history_entry($entry);
            $status = $entry['status'];
            $status_code = $entry['status_code'] !== null ? absint($entry['status_code']) : 0;
            $response_time_ms = $entry['response_time_ms'] !== null ? absint($entry['response_time_ms']) : null;
            $timestamp = $entry['timestamp'];
            $display_timestamp = $entry['display_timestamp'];
            $message = $entry['message'];

            echo '<li class="uptime-history-item">';
            echo '<span class="uptime-status-badge uptime-status-' . esc_attr($status) . '">' . esc_html($this->get_status_label($status)) . '</span>';
            if ($status_code > 0) {
                /* translators: %d: HTTP status code. */
                echo '<span class="uptime-history-code">' . esc_html(sprintf(__('HTTP %d', 'uptime-monitor'), $status_code)) . '</span>';
            }
            if ($response_time_ms !== null) {
                /* translators: %d: Response time in milliseconds. */
                echo '<span class="uptime-history-response-time">' . esc_html(sprintf(__('%d ms', 'uptime-monitor'), $response_time_ms)) . '</span>';
            }
            if ($timestamp !== '') {
                echo '<time class="uptime-history-time" datetime="' . esc_attr($timestamp) . '">' . esc_html($display_timestamp) . '</time>';
            }
            if ($message !== '') {
                echo '<span class="uptime-history-message">' . esc_html($message) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol>';
    }

    private function render_dashboard_overview(array $dashboard): void
    {
        $overall_status = isset($dashboard['overall_status']) ? sanitize_key($dashboard['overall_status']) : 'operational';
        $status_class = $overall_status === 'incident' ? 'down' : ($overall_status === 'degraded' ? 'degraded' : 'up');

        echo '<section class="uptime-statusbar is-' . esc_attr($status_class) . '" aria-label="' . esc_attr__('Monitoring summary', 'uptime-monitor') . '">';
        echo '<div class="uptime-statusbar__state">';
        echo '<span class="uptime-status-light is-' . esc_attr($status_class) . '" aria-hidden="true"></span>';
        echo '<strong>' . esc_html($dashboard['overall_label']) . '</strong>';
        echo '</div>';
        echo '<span><strong>' . esc_html((string)$dashboard['total']) . '</strong> ' . esc_html__('monitored', 'uptime-monitor') . '</span>';
        echo '<span><strong>' . esc_html((string)$dashboard['up']) . '</strong> ' . esc_html__('up', 'uptime-monitor') . '</span>';
        echo '<span><strong>' . esc_html((string)$dashboard['down']) . '</strong> ' . esc_html__('down', 'uptime-monitor') . '</span>';
        echo '<span><strong>' . esc_html((string)$dashboard['paused']) . '</strong> ' . esc_html__('paused', 'uptime-monitor') . '</span>';
        echo '<span>' . esc_html__('Last check:', 'uptime-monitor') . ' <strong>' . esc_html($dashboard['latest_check_display']) . '</strong></span>';
        echo '</section>';

        echo '<section class="uptime-metric-grid" aria-label="' . esc_attr__('Monitoring metrics', 'uptime-monitor') . '">';
        /* translators: 1: Active incidents, 2: Active URLs. */
        $health_meta = sprintf(__('Active incidents: %1$d; active URLs: %2$d', 'uptime-monitor'), (int)$dashboard['incidents'], (int)$dashboard['active']);
        $this->render_dashboard_metric(__('Current health', 'uptime-monitor'), $dashboard['health_display'], $health_meta);
        $this->render_dashboard_metric(__('Average response', 'uptime-monitor'), $dashboard['average_response_display'], __('Across URLs with history', 'uptime-monitor'));
        $this->render_dashboard_metric(__('24h uptime', 'uptime-monitor'), $dashboard['uptime_24h_display'], __('Average over recent checks', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Notifications', 'uptime-monitor'), (string)$dashboard['email_count'] . ' / ' . (string)$dashboard['pushover_count'], __('Email / Pushover enabled', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Next check', 'uptime-monitor'), $dashboard['next_check_display'], __('Scheduled WordPress cron', 'uptime-monitor'));
        echo '</section>';
    }

    private function render_dashboard_metric(string $label, string $value, string $meta): void
    {
        echo '<article class="uptime-metric-card">';
        echo '<span class="uptime-metric-card__label">' . esc_html($label) . '</span>';
        echo '<strong class="uptime-metric-card__value">' . esc_html($value) . '</strong>';
        echo '<span class="uptime-metric-card__meta">' . esc_html($meta) . '</span>';
        echo '</article>';
    }

    public function render_dashboard_widget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $dashboard = $this->get_dashboard_summary($this->normalize_stored_urls());
        $this->render_dashboard_widget_content($dashboard);
    }

    private function render_dashboard_widget_content(array $dashboard): void
    {
        $overall_status = isset($dashboard['overall_status']) ? sanitize_key($dashboard['overall_status']) : 'operational';
        $status_class = $overall_status === 'incident' ? 'down' : ($overall_status === 'degraded' ? 'degraded' : 'up');
        $attention_urls = array_values(array_filter($dashboard['urls'], function (array $url_data): bool {
            $url_dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
            $status = isset($url_dashboard['status']) ? sanitize_key($url_dashboard['status']) : 'unknown';

            return !empty($url_dashboard['incident_open']) || in_array($status, ['down', 'degraded', 'unknown'], true);
        }));

        echo '<div class="uptime-monitor-dashboard uptime-dashboard-widget is-' . esc_attr($status_class) . '">';
        echo '<div class="uptime-widget-refresh-meter" aria-hidden="true"><span></span></div>';
        echo '<div class="uptime-widget-status">';
        if ($overall_status === 'incident') {
            echo '<span class="uptime-widget-beacon" aria-hidden="true"></span>';
        } else {
            echo '<span class="uptime-status-light is-' . esc_attr($status_class) . '" aria-hidden="true"></span>';
        }
        echo '<div>';
        echo '<strong>' . esc_html($dashboard['overall_label']) . '</strong>';
        echo '<span>' . esc_html__('Last check:', 'uptime-monitor') . ' ' . esc_html($dashboard['latest_check_display']) . '</span>';
        echo '</div>';
        echo '</div>';

        if (empty($attention_urls)) {
            echo '<p class="uptime-widget-ok">' . esc_html__('All monitored URLs are reachable.', 'uptime-monitor') . '</p>';
        } else {
            echo '<ul class="uptime-widget-list">';
            foreach (array_slice($attention_urls, 0, 5) as $url_data) {
                $this->render_dashboard_widget_item($url_data);
            }
            echo '</ul>';
            if (count($attention_urls) > 5) {
                echo '<p class="uptime-widget-more">' . esc_html(sprintf(
                    /* translators: %d: Number of additional attention URLs not shown in the dashboard widget. */
                    __('%d more URLs need attention.', 'uptime-monitor'),
                    count($attention_urls) - 5
                )) . '</p>';
            }
        }

        echo '<p class="uptime-widget-actions"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=uptime-monitor')) . '">' . esc_html__('Open Uptime Monitor', 'uptime-monitor') . '</a></p>';
        echo '</div>';
    }

    private function render_dashboard_widget_item(array $url_data): void
    {
        $dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
        $status = isset($dashboard['status']) ? sanitize_key($dashboard['status']) : 'unknown';
        $status_label = isset($dashboard['status_label']) ? (string)$dashboard['status_label'] : $this->get_dashboard_status_label($status);
        $type = isset($url_data['type']) ? sanitize_key($url_data['type']) : 'http_check';
        $url = isset($url_data['url']) ? esc_url_raw($url_data['url']) : '';
        $host = $url !== '' ? wp_parse_url($url, PHP_URL_HOST) : '';
        $title = $type === 'heartbeat'
            ? $this->get_monitor_display_name($url_data)
            : (is_string($host) && $host !== '' ? $host : $url);
        $latest = isset($dashboard['latest']) && is_array($dashboard['latest']) ? $dashboard['latest'] : null;
        $detail = '';

        if ($latest !== null) {
            $status_code = isset($latest['status_code']) && $latest['status_code'] !== null ? absint($latest['status_code']) : 0;
            $message = isset($latest['message']) ? (string)$latest['message'] : '';
            if ($status_code > 0) {
                /* translators: %d: HTTP status code. */
                $detail = sprintf(__('HTTP %d', 'uptime-monitor'), $status_code);
            } elseif ($message !== '') {
                $detail = $message;
            }
        }

        $incident_duration = isset($dashboard['incident_duration_display']) ? (string)$dashboard['incident_duration_display'] : '';

        echo '<li class="uptime-widget-item is-' . esc_attr($status) . '">';
        echo '<span class="uptime-status-light is-' . esc_attr($status) . '" aria-hidden="true"></span>';
        echo '<div>';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<span><b>' . esc_html($status_label) . '</b>';
        if ($detail !== '') {
            echo ' Â· ' . esc_html($detail);
        }
        if ($incident_duration !== '') {
            echo ' Â· ' . esc_html($incident_duration);
        }
        echo '</span>';
        echo '</div>';
        echo '</li>';
    }

    private function render_rest_endpoint_row(string $label, string $description, string $endpoint, string $meta = ''): void
    {
        echo '<article class="uptime-rest-endpoint">';
        echo '<div class="uptime-rest-endpoint__main">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '<span>' . esc_html($description) . '</span>';
        echo '<code>' . esc_html($endpoint) . '</code>';
        if ($meta !== '') {
            echo '<span class="uptime-rest-endpoint__meta">' . wp_kses($meta, [
                'code' => [],
                'strong' => [],
                'span' => [
                    'class' => [],
                ],
            ]) . '</span>';
        }
        echo '</div>';
        echo '<div class="uptime-rest-endpoint__actions">';
        echo '<button type="button" class="button uptime-copy-endpoint" data-copy-value="' . esc_attr($endpoint) . '"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' . esc_html__('Copy', 'uptime-monitor') . '</button>';
        echo '</div>';
        echo '</article>';
    }

    private function render_url_dashboard_rows(array $urls): void
    {
        echo '<section class="uptime-url-list" aria-label="' . esc_attr__('Monitored URLs', 'uptime-monitor') . '">';
        if (empty($urls)) {
            echo '<div class="uptime-empty-state">' . esc_html__('No URLs available. Add one!', 'uptime-monitor') . '</div>';
        } else {
            foreach ($urls as $url_data) {
                $this->render_url_dashboard_row($url_data);
            }
        }
        echo '</section>';
    }

    private function render_url_dashboard_row(array $url_data): void
    {
        $history = isset($url_data['history']) && is_array($url_data['history']) ? $url_data['history'] : [];
        $dashboard = isset($url_data['dashboard']) && is_array($url_data['dashboard']) ? $url_data['dashboard'] : [];
        $type = isset($url_data['type']) ? sanitize_key($url_data['type']) : 'http_check';
        $status = isset($dashboard['status']) ? sanitize_key($dashboard['status']) : 'unknown';
        $status_label = isset($dashboard['status_label']) ? $dashboard['status_label'] : $this->get_dashboard_status_label($status);
        $latest = isset($dashboard['latest']) && is_array($dashboard['latest']) ? $dashboard['latest'] : null;
        $url = isset($url_data['url']) ? esc_url_raw($url_data['url']) : '';
        $host = $url !== '' ? wp_parse_url($url, PHP_URL_HOST) : '';
        $title = $type === 'heartbeat'
            ? $this->get_monitor_display_name($url_data)
            : (is_string($host) && $host !== '' ? $host : $url);
        $enabled = !isset($url_data['enabled']) || (bool)$url_data['enabled'];
        $incident_open = !empty($dashboard['incident_open']);
        $notifications_label = isset($dashboard['notifications_label']) ? $dashboard['notifications_label'] : $this->get_notifications_label($url_data);
        $incident_label = isset($dashboard['incident_label']) ? $dashboard['incident_label'] : __('No incident', 'uptime-monitor');
        $incident_duration_display = isset($dashboard['incident_duration_display']) ? (string)$dashboard['incident_duration_display'] : '';

        echo '<article class="uptime-url-row is-' . esc_attr($status) . '" data-id="' . esc_attr($url_data['id']) . '">';
        echo '<div class="uptime-url-status">';
        echo '<span class="uptime-status-light is-' . esc_attr($status) . '" aria-hidden="true"></span>';
        echo '<strong>' . esc_html($status_label) . '</strong>';
        echo '</div>';

        echo '<div class="uptime-url-identity">';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<span class="uptime-chip uptime-chip-muted">' . esc_html($type === 'heartbeat' ? __('Heartbeat', 'uptime-monitor') : __('HTTP check', 'uptime-monitor')) . '</span>';
        if ($type !== 'heartbeat' && $url !== '') {
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
        } elseif ($type === 'heartbeat' && !empty($url_data['expected_interval'])) {
            echo '<span>' . esc_html(sprintf(__('Elke %d seconden verwacht', 'uptime-monitor'), absint($url_data['expected_interval']))) . '</span>';
        }
        echo '</div>';

        echo '<div class="uptime-url-current">';
        if ($latest !== null) {
            $status_code = isset($latest['status_code']) && $latest['status_code'] !== null ? absint($latest['status_code']) : 0;
            $response_time_ms = isset($latest['response_time_ms']) && $latest['response_time_ms'] !== null ? absint($latest['response_time_ms']) : null;
            $timestamp = isset($latest['timestamp']) ? (string)$latest['timestamp'] : '';
            $display_timestamp = isset($latest['display_timestamp']) ? (string)$latest['display_timestamp'] : $timestamp;

            if ($status_code > 0) {
                /* translators: %d: HTTP status code. */
                echo '<span class="uptime-chip">' . esc_html(sprintf(__('HTTP %d', 'uptime-monitor'), $status_code)) . '</span>';
            }
            if ($response_time_ms !== null) {
                /* translators: %d: Response time in milliseconds. */
                echo '<span class="uptime-chip">' . esc_html(sprintf(__('%d ms', 'uptime-monitor'), $response_time_ms)) . '</span>';
            }
            if ($timestamp !== '') {
                echo '<time class="uptime-chip uptime-chip-muted" datetime="' . esc_attr($timestamp) . '">' . esc_html($display_timestamp) . '</time>';
            }
        } else {
            echo '<span class="uptime-chip uptime-chip-muted">' . esc_html__('No checks yet.', 'uptime-monitor') . '</span>';
        }
        echo '</div>';

        echo '<div class="uptime-url-metrics">';
        $this->render_uptime_summary($history);
        if (!empty($dashboard['average_response_time_display'])) {
            echo '<span class="uptime-response-average">' . esc_html($dashboard['average_response_time_display']) . '</span>';
        }
        echo '</div>';

        echo '<div class="uptime-url-alerts">';
        echo '<span class="uptime-chip' . ($notifications_label === __('No alerts', 'uptime-monitor') ? ' is-warning' : '') . '">' . esc_html($notifications_label) . '</span>';
        echo '<span class="uptime-chip' . ($incident_open ? ' is-danger' : ' uptime-chip-muted') . '">' . esc_html($incident_label) . '</span>';
        if ($incident_duration_display !== '') {
            echo '<span class="uptime-chip is-danger">' . esc_html($incident_duration_display) . '</span>';
        }
        echo '</div>';

        echo '<div class="uptime-url-actions">';
        echo '<label class="uptime-toggle"><input type="checkbox" class="toggle-monitoring" data-id="' . esc_attr($url_data['id']) . '" ' . checked($enabled, true, false) . '> <span>' . esc_html__('Monitoring', 'uptime-monitor') . '</span></label>';
        echo '<button type="button" class="button toggle-history" aria-expanded="false"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="uptime-action-label">' . esc_html__('Details', 'uptime-monitor') . '</span></button>';
        echo '<button type="button" class="button delete-url uptime-delete-button" data-id="' . esc_attr($url_data['id']) . '">' . esc_html__('Delete', 'uptime-monitor') . '</button>';
        echo '</div>';

        echo '<div class="uptime-url-history" hidden>';
        $this->render_status_history_cell($history);
        echo '</div>';
        echo '</article>';
    }

    /**
     * Renders the admin page for managing monitored URLs.
     */
    public function render_urls_page(): void
    {
        $urls = $this->normalize_stored_urls();
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        if ($request_method === 'POST') {
            $nonce = isset($_POST['uptime_monitor_nonce_field']) ? sanitize_text_field(wp_unslash($_POST['uptime_monitor_nonce_field'])) : '';
            if (!wp_verify_nonce($nonce, 'uptime_monitor_nonce_action')) {
                wp_die('Security check failed.');
            }


            $posted_url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
            $new_url = $this->sanitize_monitor_url($posted_url);
            $email_alert = isset($_POST['email_alert']);
            $pushover_alert = isset($_POST['pushover_alert']);

            if ($new_url === null) {
                echo '<div class="error"><p>' . esc_html__('Invalid URL.', 'uptime-monitor') . '</p></div>';
            } else {
                $response = wp_remote_get($new_url, ['timeout' => $this->get_request_timeout()]);
                if (is_wp_error($response)) {
                    echo '<div class="error"><p>' . esc_html__('Invalid URL:', 'uptime-monitor') . ' ' . esc_html($new_url . ' - ' . $response->get_error_message()) . '</p></div>';
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($this->is_down_status_code($status_code)) {
                        echo '<div class="error"><p>' . esc_html__('URL is not reachable (status code:', 'uptime-monitor') . ' ' . esc_html($status_code) . ').</p></div>';
                    } else {
                        $urls[] = [
                            'id' => wp_generate_uuid4(),
                            'url' => $new_url,
                            'email' => $email_alert,
                            'pushover' => $pushover_alert,
                            'enabled' => true,
                        ];
                        $urls = $this->normalize_urls($urls);
                        update_option('uptime_monitor_urls', $urls);
                        add_settings_error('uptime_monitor', 'uptime_notice', __('URL added successfully!', 'uptime-monitor'), 'updated');
                        settings_errors('uptime_monitor');

                    }
                }
            }

        }

        if (isset($_GET['delete'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'delete_uptime_monitor')) {
                wp_die('Security check failed.');
            }
            $index_to_delete = isset($_GET['delete']) ? absint(wp_unslash($_GET['delete'])) : 0;
            $deleted_url_id = isset($urls[$index_to_delete]['id']) ? sanitize_key($urls[$index_to_delete]['id']) : '';
            unset($urls[$index_to_delete]);
            update_option('uptime_monitor_urls', array_values($urls));
            if ($deleted_url_id !== '') {
                $this->delete_status_history_for_url($deleted_url_id);
                $this->delete_incident_for_url($deleted_url_id);
            }
            add_settings_error('uptime_monitor', 'uptime_notice', __('URL deleted successfully!', 'uptime-monitor'), 'updated');
            settings_errors('uptime_monitor');
        }

        $dashboard = $this->get_dashboard_summary($urls);
        $urls = $dashboard['urls'];

        echo '<div class="wrap uptime-monitor-admin uptime-monitor-dashboard">';
        echo '<div class="uptime-dashboard-header">';
        echo '<h1>' . esc_html__('Uptime Monitor', 'uptime-monitor') . '</h1>';
        echo '<div class="uptime-dashboard-actions">';
        echo '<button type="button" class="button uptime-refresh-dashboard"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>' . esc_html__('Refresh now', 'uptime-monitor') . '</span></button>';
        echo '<label class="uptime-auto-refresh-toggle"><input type="checkbox" class="uptime-auto-refresh-input"> <span>' . esc_html__('Auto-refresh', 'uptime-monitor') . '</span></label>';
        echo '<span class="uptime-refresh-state" aria-live="polite"></span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="uptime-monitor-notices" aria-live="polite" aria-atomic="true"></div>';

        $this->render_dashboard_overview($dashboard);

        echo '<form id="uptime-monitor-form" method="post" class="uptime-monitor-form uptime-add-url-panel">';
        wp_nonce_field('uptime_monitor_nonce_action', 'uptime_monitor_nonce_field');
        echo '<label class="screen-reader-text" for="url">' . esc_html__('URL', 'uptime-monitor') . '</label>';
        echo '<input type="url" id="url" name="url" placeholder="https://example.com" required>';
        echo '<label><input type="checkbox" id="email_alert" name="email_alert" value="1"> ' . esc_html__('Email Alert', 'uptime-monitor') . '</label>';
        echo '<label><input type="checkbox" id="pushover_alert" name="pushover_alert" value="1"> ' . esc_html__('Pushover Alert', 'uptime-monitor') . '</label>';
        echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Add URL', 'uptime-monitor') . '">';
        echo '</form>';

        echo '<h2>' . esc_html__('Monitored URLs', 'uptime-monitor') . '</h2>';
        $this->render_url_dashboard_rows($urls);
        echo '</div>';
    }

    /**
     * Render the settings page for Uptime Monitor.
     */
    public function render_settings_page(): void
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $generated_read_api_token = '';
        $generated_heartbeat_token = '';
        // Opslaan van instellingen
	    if ($request_method === 'POST') {
		    check_admin_referer('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

            if (isset($_POST['generate_read_api_token'])) {
                $generated_read_api_token = $this->create_read_api_token();
                echo '<div class="updated"><p>' . esc_html__('Alleen-lezen API-token gegenereerd. Kopieer het nu; het wordt niet opnieuw getoond.', 'uptime-monitor') . '</p></div>';
            } elseif (isset($_POST['clear_read_api_token'])) {
                $this->clear_read_api_token();
                echo '<div class="updated"><p>' . esc_html__('Alleen-lezen API-token ingetrokken.', 'uptime-monitor') . '</p></div>';
            } elseif (isset($_POST['add_heartbeat_monitor'])) {
                $result = $this->add_heartbeat_monitor_from_post();
                $generated_heartbeat_token = $result['token'];
                echo '<div class="' . esc_attr($result['type']) . '"><p>' . esc_html($result['message']) . '</p></div>';
            } elseif (isset($_POST['rotate_heartbeat_token'])) {
                $result = $this->update_heartbeat_monitor_from_post('rotate');
                $generated_heartbeat_token = $result['token'];
                echo '<div class="' . esc_attr($result['type']) . '"><p>' . esc_html($result['message']) . '</p></div>';
            } elseif (isset($_POST['toggle_heartbeat_monitor'])) {
                $result = $this->update_heartbeat_monitor_from_post('toggle');
                echo '<div class="' . esc_attr($result['type']) . '"><p>' . esc_html($result['message']) . '</p></div>';
            } elseif (isset($_POST['delete_heartbeat_monitor'])) {
                $result = $this->update_heartbeat_monitor_from_post('delete');
                echo '<div class="' . esc_attr($result['type']) . '"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                $import_json = isset($_POST['import_json']) ? sanitize_textarea_field(wp_unslash($_POST['import_json'])) : '';
		    if ($import_json !== '') {
			    // IMPORT VAN JSON
			    $json_input = trim($import_json);
			    $data = json_decode($json_input, true);

			    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || (!isset($data['settings']) && !isset($data['urls']))) {
				    echo '<div class="error"><p>' . esc_html__('Invalid JSON format.', 'uptime-monitor') . '</p></div>';
			    } else {
                    if (isset($data['urls']) && !is_array($data['urls'])) {
                        echo '<div class="error"><p>' . esc_html__('Invalid JSON format.', 'uptime-monitor') . '</p></div>';
                    } else {
                        if (isset($data['settings']['monitor_interval'])) {
                            update_option('uptime_monitor_interval', max(60, intval($data['settings']['monitor_interval'])));
                        }
                        if (isset($data['settings']['retry_attempts'])) {
                            update_option('uptime_monitor_retry_attempts', min(10, max(1, intval($data['settings']['retry_attempts']))));
                        }
                        if (isset($data['settings']['request_timeout'])) {
                            update_option('uptime_monitor_request_timeout', min(60, max(1, intval($data['settings']['request_timeout']))));
                        }
                        if (isset($data['settings']['down_status_codes'])) {
                            update_option('uptime_monitor_down_status_codes', $this->sanitize_status_code_ranges($data['settings']['down_status_codes']));
                        }
                        if (isset($data['urls'])) {
                            $imported_urls = $this->normalize_urls($data['urls']);
                            update_option('uptime_monitor_urls', $imported_urls);
                        }
                        // Herplan cronjob
                        $this->reschedule_monitoring();
                        echo '<div class="updated"><p>' . esc_html__('Configuratie succesvol geimporteerd.', 'uptime-monitor') . '</p></div>';
                    }
			    }
		    } else {
			    // STANDAARD INSTELLING OPSLAAN
                $posted_interval = isset($_POST['monitor_interval']) ? absint(wp_unslash($_POST['monitor_interval'])) : 120;
			    $monitor_interval = max(60, $posted_interval);
			    update_option('uptime_monitor_interval', $monitor_interval);
                $retry_attempts = isset($_POST['retry_attempts']) ? min(10, max(1, absint(wp_unslash($_POST['retry_attempts'])))) : self::DEFAULT_RETRY_ATTEMPTS;
                update_option('uptime_monitor_retry_attempts', $retry_attempts);
                $request_timeout = isset($_POST['request_timeout']) ? min(60, max(1, absint(wp_unslash($_POST['request_timeout'])))) : self::DEFAULT_REQUEST_TIMEOUT;
                update_option('uptime_monitor_request_timeout', $request_timeout);
                $down_status_codes = isset($_POST['down_status_codes']) ? sanitize_text_field(wp_unslash($_POST['down_status_codes'])) : self::DEFAULT_DOWN_STATUS_CODES;
                update_option('uptime_monitor_down_status_codes', $this->sanitize_status_code_ranges($down_status_codes));
                $this->save_pushover_settings_from_post();

                $this->reschedule_monitoring();

                if (isset($_POST['test_pushover'])) {
                    $test_sent = $this->has_pushover_credentials()
                        && $this->send_pushover_message(
                            home_url('/'),
                            __('Uptime Monitor Test', 'uptime-monitor'),
                            __('This is a test notification from Simple Uptime Monitor.', 'uptime-monitor')
                        );

                    if ($test_sent) {
                        echo '<div class="updated"><p>' . esc_html__('Instellingen opgeslagen en Pushover-testmelding verzonden.', 'uptime-monitor') . '</p></div>';
                    } else {
                        echo '<div class="error"><p>' . esc_html__('Instellingen opgeslagen, maar de Pushover-testmelding is mislukt. Controleer credentials en logs.', 'uptime-monitor') . '</p></div>';
                    }
                } else {
			        echo '<div class="updated"><p>' . esc_html__('Instellingen opgeslagen.', 'uptime-monitor') . '</p></div>';
                }
		    }
            }
	    }


        // Huidige instellingen ophalen
	    $monitor_interval = $this->get_monitor_interval();
        $retry_attempts = $this->get_retry_attempts();
        $request_timeout = $this->get_request_timeout();
        $down_status_codes = $this->get_down_status_codes();
        $stored_pushover_user_key = (string)get_option('uptime_monitor_pushover_user_key', '');
        $stored_pushover_api_token = (string)get_option('uptime_monitor_pushover_api_token', '');
        $pushover_user_key_placeholder = $stored_pushover_user_key !== '' ? __('Configured', 'uptime-monitor') : '';
        $pushover_api_token_placeholder = $stored_pushover_api_token !== '' ? __('Configured', 'uptime-monitor') : '';
	    $urls = $this->normalize_stored_urls();
        $export_urls = array_map(function (array $url_data): array {
            if (($url_data['type'] ?? 'http_check') === 'heartbeat') {
                unset($url_data['token_hash'], $url_data['token_last4'], $url_data['token_created_at']);
            }

            return $url_data;
        }, $urls);

	    $export_data = [
		    'settings' => [
                'monitor_interval' => $monitor_interval,
                'retry_attempts' => $retry_attempts,
                'request_timeout' => $request_timeout,
                'down_status_codes' => $down_status_codes,
            ],
		    'urls'     => $export_urls,
	    ];

	    $json_export = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


        $pushover_status = $this->pushover_uses_constants()
            ? __('wp-config.php-constanten', 'uptime-monitor')
            : ($this->has_pushover_credentials() ? __('In database ingesteld', 'uptime-monitor') : __('Niet ingesteld', 'uptime-monitor'));
        $read_api_token_last4 = (string)get_option('uptime_monitor_read_api_token_last4', '');
        $read_api_token_created_at = (string)get_option('uptime_monitor_read_api_token_created_at', '');
        $read_api_token_configured = $this->has_read_api_token();
        $read_api_token_status = $read_api_token_configured ? __('Token ingesteld', 'uptime-monitor') : __('Geen token ingesteld', 'uptime-monitor');
        $read_api_token_created_display = $read_api_token_created_at !== '' ? $this->format_history_timestamp($read_api_token_created_at) : '';

	    echo '<div class="wrap uptime-monitor-admin uptime-monitor-dashboard uptime-monitor-settings-page">';
        echo '<div class="uptime-dashboard-header">';
        echo '<h1>' . esc_html__('Uptime Monitor-instellingen', 'uptime-monitor') . '</h1>';
        echo '</div>';

        echo '<section class="uptime-statusbar uptime-settings-statusbar is-up" aria-label="' . esc_attr__('Instellingenoverzicht', 'uptime-monitor') . '">';
        echo '<div class="uptime-statusbar__state">';
        echo '<span class="uptime-status-light is-up" aria-hidden="true"></span>';
        echo '<strong>' . esc_html__('Configuratiepaneel', 'uptime-monitor') . '</strong>';
        echo '</div>';
        echo '<span><strong>' . esc_html((string)count($urls)) . '</strong> ' . esc_html__('monitors', 'uptime-monitor') . '</span>';
        echo '<span>' . esc_html__('Interval:', 'uptime-monitor') . ' <strong>' . esc_html((string)$monitor_interval) . 's</strong></span>';
        echo '<span>' . esc_html__('Pogingen:', 'uptime-monitor') . ' <strong>' . esc_html((string)$retry_attempts) . '</strong></span>';
        echo '<span>' . esc_html__('Timeout:', 'uptime-monitor') . ' <strong>' . esc_html((string)$request_timeout) . 's</strong></span>';
        echo '<span>' . esc_html__('Pushover:', 'uptime-monitor') . ' <strong>' . esc_html($pushover_status) . '</strong></span>';
        echo '</section>';

        echo '<section class="uptime-metric-grid uptime-settings-metrics" aria-label="' . esc_attr__('Huidige instellingen', 'uptime-monitor') . '">';
        $this->render_dashboard_metric(__('Monitorinterval', 'uptime-monitor'), (string)$monitor_interval . 's', __('Minimaal 60 seconden', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Herhaalpogingen', 'uptime-monitor'), (string)$retry_attempts, __('Voordat de status definitief is', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Verzoek-timeout', 'uptime-monitor'), (string)$request_timeout . 's', __('Per HTTP-poging', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Down-statuscodes', 'uptime-monitor'), $down_status_codes, __('HTTP-statusbereiken', 'uptime-monitor'));
        $this->render_dashboard_metric(__('Pushover', 'uptime-monitor'), $pushover_status, __('Bron van toegangsgegevens', 'uptime-monitor'));
        echo '</section>';

        $status_endpoint = rest_url('uptime-monitor/v1/status');
        $status_detail_endpoint = rest_url('uptime-monitor/v1/status/{id}');
        $logs_endpoint = rest_url('uptime-monitor/v1/logs');
        echo '<section class="uptime-settings-panel uptime-rest-viewer" aria-label="' . esc_attr__('REST API viewer', 'uptime-monitor') . '">';
        echo '<div class="uptime-settings-panel__header">';
        echo '<h2>' . esc_html__('REST API', 'uptime-monitor') . '</h2>';
        echo '<span class="uptime-chip uptime-chip-muted">' . esc_html__('Admin-authenticatie vereist', 'uptime-monitor') . '</span>';
        echo '</div>';
        echo '<div class="uptime-rest-grid">';
        $this->render_rest_endpoint_row(__('Algemene status', 'uptime-monitor'), __('Volledige monitorstatusresponse.', 'uptime-monitor'), $status_endpoint);
        $this->render_rest_endpoint_row(__('URL-status', 'uptime-monitor'), __('Status van een monitor op basis van ID.', 'uptime-monitor'), $status_detail_endpoint);
        $this->render_rest_endpoint_row(
            __('Logboek', 'uptime-monitor'),
            __('Bestaand logendpoint, alleen voor admins.', 'uptime-monitor'),
            $logs_endpoint,
            '<strong>Parameters:</strong> <code>type</code>, <code>url</code>, <code>date_from</code>, <code>date_to</code>, <code>page</code>, <code>per_page</code>, <code>limit</code>, <code>order</code><br><span class="uptime-rest-endpoint__example"><code>?type=error&amp;url=example.com&amp;limit=10&amp;order=desc</code></span>'
        );
        echo '</div>';
        echo '<div class="uptime-rest-token">';
        echo '<div class="uptime-rest-token__main">';
        echo '<strong>' . esc_html__('Alleen-lezen API-token', 'uptime-monitor') . '</strong>';
        echo '<span class="uptime-chip' . ($read_api_token_configured ? '' : ' is-warning') . '">' . esc_html($read_api_token_status) . '</span>';
        echo '<p>' . esc_html__('Gebruik dit token voor externe dashboards die alleen de status-endpoints nodig hebben.', 'uptime-monitor') . '</p>';
        echo '<code>' . esc_html__('Authorization: Bearer jouw-token', 'uptime-monitor') . '</code>';
        if ($read_api_token_configured && $read_api_token_last4 !== '') {
            /* translators: %s: Last four characters of the read-only API token. */
            echo '<p class="uptime-rest-token__meta">' . esc_html(sprintf(__('Huidig token eindigt op %s.', 'uptime-monitor'), $read_api_token_last4)) . '</p>';
        }
        if ($read_api_token_created_display !== '') {
            /* translators: %s: Token creation date. */
            echo '<p class="uptime-rest-token__meta">' . esc_html(sprintf(__('Aangemaakt: %s', 'uptime-monitor'), $read_api_token_created_display)) . '</p>';
        }
        if ($generated_read_api_token !== '') {
            echo '<div class="uptime-rest-token__generated">';
            echo '<strong>' . esc_html__('Kopieer dit token nu. Het wordt niet opnieuw getoond.', 'uptime-monitor') . '</strong>';
            echo '<code>' . esc_html($generated_read_api_token) . '</code>';
            echo '<button type="button" class="button uptime-copy-endpoint" data-copy-value="' . esc_attr($generated_read_api_token) . '"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' . esc_html__('Copy', 'uptime-monitor') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<form method="post" class="uptime-rest-token__actions">';
        wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');
        echo '<button type="submit" class="button button-primary" name="generate_read_api_token" value="1"><span class="dashicons dashicons-update" aria-hidden="true"></span>' . esc_html($read_api_token_configured ? __('Token vernieuwen', 'uptime-monitor') : __('Token genereren', 'uptime-monitor')) . '</button>';
        if ($read_api_token_configured) {
            echo '<button type="submit" class="button uptime-delete-button" name="clear_read_api_token" value="1"><span class="dashicons dashicons-trash" aria-hidden="true"></span>' . esc_html__('Token intrekken', 'uptime-monitor') . '</button>';
        }
        echo '</form>';
        echo '</div>';
        echo '</section>';

        $heartbeat_endpoint = rest_url('uptime-monitor/v1/heartbeat');
        $heartbeat_monitors = array_values(array_filter($urls, function (array $url_data): bool {
            return ($url_data['type'] ?? 'http_check') === 'heartbeat';
        }));
        echo '<section class="uptime-settings-panel uptime-settings-panel-wide uptime-heartbeat-panel" aria-label="' . esc_attr__('Heartbeat monitors', 'uptime-monitor') . '">';
        echo '<div class="uptime-settings-panel__header">';
        echo '<h2>' . esc_html__('Heartbeat monitors', 'uptime-monitor') . '</h2>';
        echo '<span class="uptime-chip uptime-chip-muted">' . esc_html__('Uitgaande clientpings', 'uptime-monitor') . '</span>';
        echo '</div>';
        echo '<p class="uptime-settings-note">' . esc_html__('Gebruik heartbeat monitors voor computers, jobs, Home Assistant, NAS-apparaten of interne apps die deze monitor via uitgaande HTTPS kunnen bereiken, maar zelf niet van buitenaf bereikbaar zijn.', 'uptime-monitor') . '</p>';
        echo '<div class="uptime-rest-endpoint uptime-heartbeat-endpoint">';
        echo '<div class="uptime-rest-endpoint__main">';
        echo '<strong>' . esc_html__('Heartbeat-endpoint', 'uptime-monitor') . '</strong>';
        echo '<span>' . esc_html__('Clients sturen een POST naar dit endpoint met hun eigen heartbeat-token.', 'uptime-monitor') . '</span>';
        echo '<code>' . esc_html($heartbeat_endpoint) . '</code>';
        echo '</div>';
        echo '<div class="uptime-rest-endpoint__actions">';
        echo '<button type="button" class="button uptime-copy-endpoint" data-copy-value="' . esc_attr($heartbeat_endpoint) . '"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' . esc_html__('Kopieren', 'uptime-monitor') . '</button>';
        echo '</div>';
        echo '</div>';
        if ($generated_heartbeat_token !== '') {
            $curl_example = "curl -X POST " . $heartbeat_endpoint . " -H \"Authorization: Bearer " . $generated_heartbeat_token . "\" -H \"Content-Type: application/json\" -d \"{\\\"status\\\":\\\"up\\\",\\\"message\\\":\\\"heartbeat ok\\\"}\"";
            echo '<div class="uptime-rest-token__generated uptime-heartbeat-token">';
            echo '<strong>' . esc_html__('Kopieer dit heartbeat-token nu. Het wordt niet opnieuw getoond.', 'uptime-monitor') . '</strong>';
            echo '<code>' . esc_html($generated_heartbeat_token) . '</code>';
            echo '<button type="button" class="button uptime-copy-endpoint" data-copy-value="' . esc_attr($generated_heartbeat_token) . '"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' . esc_html__('Token kopieren', 'uptime-monitor') . '</button>';
            echo '<pre class="uptime-settings-code">' . esc_html($curl_example) . '</pre>';
            echo '<button type="button" class="button uptime-copy-endpoint" data-copy-value="' . esc_attr($curl_example) . '"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' . esc_html__('curl-voorbeeld kopieren', 'uptime-monitor') . '</button>';
            echo '</div>';
        }
        echo '<div class="uptime-heartbeat-grid">';
        echo '<form method="post" class="uptime-heartbeat-create">';
        wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');
        echo '<h3>' . esc_html__('Heartbeat monitor toevoegen', 'uptime-monitor') . '</h3>';
        echo '<div class="uptime-heartbeat-create-fields">';
        echo '<div class="uptime-settings-field">';
        echo '<label for="heartbeat_name">' . esc_html__('Naam', 'uptime-monitor') . '</label>';
        echo '<input type="text" id="heartbeat_name" name="heartbeat_name" class="regular-text" placeholder="' . esc_attr__('Home Assistant', 'uptime-monitor') . '" required>';
        echo '</div>';
        echo '<div class="uptime-settings-field">';
        echo '<label for="heartbeat_expected_interval">' . esc_html__('Verwacht interval (seconden)', 'uptime-monitor') . '</label>';
        echo '<input type="number" id="heartbeat_expected_interval" name="heartbeat_expected_interval" value="' . esc_attr((string)self::DEFAULT_HEARTBEAT_INTERVAL) . '" min="60" max="86400" step="60">';
        echo '<p class="description">' . esc_html__('De status wordt stale/down na ongeveer twee keer dit interval zonder ping.', 'uptime-monitor') . '</p>';
        echo '</div>';
        echo '<div class="uptime-heartbeat-alert-options">';
        echo '<label class="uptime-settings-checkbox"><input type="checkbox" name="heartbeat_email_alert" value="1"> ' . esc_html__('E-mailalarm', 'uptime-monitor') . '</label>';
        echo '<label class="uptime-settings-checkbox"><input type="checkbox" name="heartbeat_pushover_alert" value="1"> ' . esc_html__('Pushover-alarm', 'uptime-monitor') . '</label>';
        echo '</div>';
        echo '</div>';
        echo '<div class="uptime-settings-actions"><button type="submit" class="button button-primary" name="add_heartbeat_monitor" value="1"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Heartbeat monitor toevoegen', 'uptime-monitor') . '</button></div>';
        echo '</form>';
        echo '<div class="uptime-heartbeat-list">';
        echo '<h3>' . esc_html__('Ingestelde heartbeat monitors', 'uptime-monitor') . '</h3>';
        if (empty($heartbeat_monitors)) {
            echo '<p class="uptime-settings-note">' . esc_html__('Er zijn nog geen heartbeat monitors ingesteld.', 'uptime-monitor') . '</p>';
        } else {
            foreach ($heartbeat_monitors as $heartbeat_monitor) {
                $dashboard = isset($heartbeat_monitor['dashboard']) && is_array($heartbeat_monitor['dashboard']) ? $heartbeat_monitor['dashboard'] : $this->get_url_dashboard_data($heartbeat_monitor, $this->get_status_history_for_url($heartbeat_monitor['id']), $this->get_incidents());
                $status = isset($dashboard['status']) ? sanitize_key($dashboard['status']) : 'unknown';
                $last_seen = !empty($heartbeat_monitor['last_seen']) ? $this->format_history_timestamp((string)$heartbeat_monitor['last_seen']) : __('Geen ping ontvangen', 'uptime-monitor');
                $token_last4 = !empty($heartbeat_monitor['token_last4']) ? (string)$heartbeat_monitor['token_last4'] : __('Niet ingesteld', 'uptime-monitor');
                echo '<article class="uptime-heartbeat-card is-' . esc_attr($status) . '">';
                echo '<div>';
                echo '<strong>' . esc_html($heartbeat_monitor['name']) . '</strong>';
                echo '<span class="uptime-chip uptime-status-' . esc_attr($status) . '">' . esc_html($this->get_dashboard_status_label($status)) . '</span>';
                echo '<p>' . esc_html__('Laatste ping:', 'uptime-monitor') . ' <strong>' . esc_html($last_seen) . '</strong></p>';
                echo '<p>' . esc_html__('Verwacht interval:', 'uptime-monitor') . ' <strong>' . esc_html((string)$heartbeat_monitor['expected_interval']) . 's</strong> - ' . esc_html__('Token:', 'uptime-monitor') . ' <strong>' . esc_html($token_last4) . '</strong></p>';
                echo '</div>';
                echo '<div class="uptime-heartbeat-actions">';
                foreach ([
                    'toggle_heartbeat_monitor' => !empty($heartbeat_monitor['enabled']) ? __('Pauzeren', 'uptime-monitor') : __('Hervatten', 'uptime-monitor'),
                    'rotate_heartbeat_token' => __('Token vernieuwen', 'uptime-monitor'),
                    'delete_heartbeat_monitor' => __('Verwijderen', 'uptime-monitor'),
                ] as $action_name => $button_label) {
                    echo '<form method="post">';
                    wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');
                    echo '<input type="hidden" name="heartbeat_id" value="' . esc_attr($heartbeat_monitor['id']) . '">';
                    $button_class = $action_name === 'delete_heartbeat_monitor' ? 'button uptime-delete-button' : 'button';
                    echo '<button type="submit" class="' . esc_attr($button_class) . '" name="' . esc_attr($action_name) . '" value="1">' . esc_html($button_label) . '</button>';
                    echo '</form>';
                }
                echo '</div>';
                echo '</article>';
            }
        }
        echo '</div>';
        echo '</div>';
        echo '</section>';

        echo '<form method="post" class="uptime-settings-form">';
        wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

        echo '<div class="uptime-settings-grid">';
        echo '<section class="uptime-settings-panel">';
        echo '<div class="uptime-settings-panel__header">';
        echo '<h2>' . esc_html__('Monitoringinstellingen', 'uptime-monitor') . '</h2>';
        echo '<span class="uptime-chip uptime-chip-muted">' . esc_html__('Cron en HTTP-checks', 'uptime-monitor') . '</span>';
        echo '</div>';
        echo '<div class="uptime-settings-fields">';
        echo '<div class="uptime-settings-field">';
        echo '<label for="monitor_interval">' . esc_html__('Monitorinterval (seconden)', 'uptime-monitor') . '</label>';
        echo '<input type="number" id="monitor_interval" name="monitor_interval" value="' . esc_attr($monitor_interval) . '" min="60" step="60">';
        echo '<p class="description">' . esc_html__('Hoe vaak WordPress cron monitoringchecks moet plannen.', 'uptime-monitor') . '</p>';
        echo '</div>';
        echo '<div class="uptime-settings-field">';
        echo '<label for="retry_attempts">' . esc_html__('Herhaalpogingen', 'uptime-monitor') . '</label>';
        echo '<input type="number" id="retry_attempts" name="retry_attempts" value="' . esc_attr($retry_attempts) . '" min="1" max="10" step="1">';
        echo '<p class="description">' . esc_html__('Extra pogingen voordat een URL als down wordt gezien.', 'uptime-monitor') . '</p>';
        echo '</div>';
        echo '<div class="uptime-settings-field">';
        echo '<label for="request_timeout">' . esc_html__('Verzoek-timeout (seconden)', 'uptime-monitor') . '</label>';
        echo '<input type="number" id="request_timeout" name="request_timeout" value="' . esc_attr($request_timeout) . '" min="1" max="60" step="1">';
        echo '<p class="description">' . esc_html__('Maximale wachttijd per HTTP-poging.', 'uptime-monitor') . '</p>';
        echo '</div>';
        echo '<div class="uptime-settings-field is-wide">';
        echo '<label for="down_status_codes">' . esc_html__('Down-statuscodes', 'uptime-monitor') . '</label>';
        echo '<input type="text" id="down_status_codes" name="down_status_codes" value="' . esc_attr($down_status_codes) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Gebruik komma-gescheiden HTTP-statuscodes of bereiken. Standaard: 100-199,300-599.', 'uptime-monitor') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        echo '<section class="uptime-settings-panel">';
        echo '<div class="uptime-settings-panel__header">';
        echo '<h2>' . esc_html__('Pushover-configuratie', 'uptime-monitor') . '</h2>';
        echo '<span class="uptime-chip' . ($this->has_pushover_credentials() ? '' : ' is-warning') . '">' . esc_html($pushover_status) . '</span>';
        echo '</div>';
        if ($this->pushover_uses_constants()) {
            echo '<p class="uptime-settings-note">' . esc_html__('Pushover-constanten zijn ingesteld in wp-config.php en krijgen voorrang op opgeslagen instellingen.', 'uptime-monitor') . '</p>';
        } else {
            echo '<p class="uptime-settings-note">' . esc_html__('Sla Pushover-toegangsgegevens hier op wanneer bestandstoegang tot wp-config.php niet beschikbaar is.', 'uptime-monitor') . '</p>';
        }
        echo '<pre class="uptime-settings-code">' . esc_html("define('PUSHOVER_USER_KEY', 'your-pushover-user-key');\ndefine('PUSHOVER_API_TOKEN', 'your-pushover-api-token');") . '</pre>';
        echo '<div class="uptime-settings-fields">';
        echo '<div class="uptime-settings-field is-wide">';
        echo '<label for="pushover_user_key">' . esc_html__('Pushover-gebruikerssleutel', 'uptime-monitor') . '</label>';
        echo '<input type="password" id="pushover_user_key" name="pushover_user_key" value="" placeholder="' . esc_attr($pushover_user_key_placeholder) . '" class="regular-text" autocomplete="new-password">';
        if ($stored_pushover_user_key !== '') {
            echo '<label class="uptime-settings-checkbox"><input type="checkbox" name="clear_pushover_user_key" value="1"> ' . esc_html__('Opgeslagen user key wissen', 'uptime-monitor') . '</label>';
        }
        echo '</div>';
        echo '<div class="uptime-settings-field is-wide">';
        echo '<label for="pushover_api_token">' . esc_html__('Pushover API-token', 'uptime-monitor') . '</label>';
        echo '<input type="password" id="pushover_api_token" name="pushover_api_token" value="" placeholder="' . esc_attr($pushover_api_token_placeholder) . '" class="regular-text" autocomplete="new-password">';
        if ($stored_pushover_api_token !== '') {
            echo '<label class="uptime-settings-checkbox"><input type="checkbox" name="clear_pushover_api_token" value="1"> ' . esc_html__('Opgeslagen API-token wissen', 'uptime-monitor') . '</label>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div class="uptime-settings-actions"><button type="submit" class="button" name="test_pushover" value="1"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span>' . esc_html__('Pushover-test verzenden', 'uptime-monitor') . '</button></div>';
        echo '</section>';
        echo '</div>';

        echo '<section class="uptime-settings-panel uptime-settings-panel-wide">';
        echo '<div class="uptime-settings-panel__header">';
        echo '<h2>' . esc_html__('Configuratie importeren / exporteren', 'uptime-monitor') . '</h2>';
        echo '<span class="uptime-chip uptime-chip-muted">' . esc_html__('Geheimen uitgesloten', 'uptime-monitor') . '</span>';
        echo '</div>';
        echo '<div class="uptime-settings-io-grid">';
        echo '<div class="uptime-settings-field is-wide">';
	    echo '<label for="uptime_export_json">' . esc_html__('Configuratie exporteren', 'uptime-monitor') . '</label>';
	    echo '<textarea id="uptime_export_json" readonly rows="10" class="uptime-settings-textarea">' . esc_textarea($json_export) . '</textarea>';
        echo '</div>';
        echo '<div class="uptime-settings-field is-wide">';
	    echo '<label for="import_json">' . esc_html__('Configuratie importeren', 'uptime-monitor') . '</label>';
	    echo '<textarea id="import_json" name="import_json" rows="10" class="uptime-settings-textarea" placeholder="' . esc_attr__('Plak hier een eerder geexporteerde JSON-configuratie.', 'uptime-monitor') . '"></textarea>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

	    echo '<div class="uptime-settings-savebar">';
        echo '<button type="submit" class="button button-primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span>' . esc_html__('Instellingen opslaan', 'uptime-monitor') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Handles the monitoring process for registered URLs.
     */
    public function monitor_uptime(): void
    {
        $this->log_to_json('info', 'Cron job started.', ['task' => 'monitor_uptime_event']);
        $urls = $this->normalize_stored_urls();
        $this->log_to_json('info', 'URLs to monitor.', ['urls' => $this->get_log_safe_monitor_records($urls)]);
        $retry_attempts = $this->get_retry_attempts();
        $request_timeout = $this->get_request_timeout();

        foreach ($urls as $url_data) {
            if (($url_data['type'] ?? 'http_check') === 'heartbeat') {
                if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
                    $this->log_to_json('info', 'Heartbeat monitoring disabled.', ['name' => $this->get_monitor_display_name($url_data)]);
                    continue;
                }

                if ($this->is_heartbeat_stale($url_data)) {
                    $this->log_to_json('error', 'Heartbeat monitor is stale.', ['name' => $this->get_monitor_display_name($url_data)]);
                    $this->handle_down_incident_state($url_data, null, __('Heartbeat is stale.', 'uptime-monitor'));
                }
                continue;
            }

	        if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
		        $this->log_to_json('info', 'Monitoring disabled for URL.', ['url' => $url_data['url']]);
		        continue;
	        }
            $response_time_ms = null;
            $response = null;
	        for ($i = 0; $i < $retry_attempts; $i++) {
                $request_start = microtime(true);
                $response = wp_remote_get($url_data['url'], ['timeout' => $request_timeout]);
                $response_time_ms = (int)round((microtime(true) - $request_start) * 1000);

                if (is_wp_error($response)) {
                    $this->log_to_json('error', 'HTTP check attempt failed.', [
                        'url' => $url_data['url'],
                        'attempt' => $i + 1,
                        'retry_attempts' => $retry_attempts,
                        'error' => $response->get_error_message(),
                        'response_time_ms' => $response_time_ms,
                    ]);
                    continue;
                }

                $attempt_status_code = wp_remote_retrieve_response_code($response);
                $is_down_status = $this->is_down_status_code($attempt_status_code);
                $this->log_to_json('info', 'HTTP check attempt completed.', [
                    'url' => $url_data['url'],
                    'attempt' => $i + 1,
                    'retry_attempts' => $retry_attempts,
                    'status_code' => $attempt_status_code,
                    'is_down_status' => $is_down_status,
                    'response_time_ms' => $response_time_ms,
                ]);

                if (!$is_down_status) {
                    break;
                }
            }
            if (is_wp_error($response)) {
                $this->log_to_json('error', 'Failed to fetch URL.', ['url' => $url_data['url'], 'error' => $response->get_error_message(), 'response_time_ms' => $response_time_ms]);
                $this->record_status_history($url_data, 'error', null, $response_time_ms, $response->get_error_message());
                $this->handle_down_incident_state($url_data, null, $response->get_error_message());
                continue;
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $this->log_to_json('info', 'HTTP status code received.', ['url' => $url_data['url'], 'status_code' => $status_code, 'response_time_ms' => $response_time_ms]);
            if (!$this->is_down_status_code($status_code)) {
                $this->log_to_json('info', 'Url is up.', ['url' => $url_data['url']]);
                $this->record_status_history($url_data, 'up', $status_code, $response_time_ms, __('URL is up.', 'uptime-monitor'));
                $this->handle_up_incident_state($url_data, $status_code);

            } else {
                $this->log_to_json('error', 'URL is down.', ['url' => $url_data['url'], 'status_code' => $status_code]);
                $this->record_status_history($url_data, 'down', $status_code, $response_time_ms, __('URL is down.', 'uptime-monitor'));
                $this->handle_down_incident_state($url_data, $status_code);
            }
        }
    }

    /**
     * Sends an email alert for a down URL.
     *
     * @param string $url The URL that is down.
     * @param int|null $status_code The HTTP status code.
     * @param string $error_message Optional network error message.
     * @param bool $is_final_alert Whether this is the last alert until recovery.
     */
    private function send_email_alert($url, ?int $status_code, string $error_message = '', bool $is_final_alert = false): void
    {
        $admin_email = get_option('admin_email');
        /* translators: %s: Monitored URL. */
        $subject = sprintf(__('Website Down Alert: %s', 'uptime-monitor'), $url);
        if ($status_code !== null) {
            /* translators: 1: Monitored URL, 2: HTTP status code. */
            $message = sprintf(__('The website %1$s is down. HTTP Status Code: %2$d.', 'uptime-monitor'), $url, $status_code);
        } else {
            /* translators: 1: Monitored URL, 2: Network error message. */
            $message = sprintf(__('The website %1$s is down. Error: %2$s.', 'uptime-monitor'), $url, $error_message);
        }
        if ($is_final_alert) {
            $message .= "\n\n" . __('No further alerts will be sent for this incident until the website is reachable again.', 'uptime-monitor');
        }
        wp_mail($admin_email, $subject, $message);
    }

    private function send_email_recovery_alert($url, ?int $status_code): void
    {
        $admin_email = get_option('admin_email');
        /* translators: %s: Monitored URL. */
        $subject = sprintf(__('Website Recovered: %s', 'uptime-monitor'), $url);
        if ($status_code !== null) {
            /* translators: 1: Monitored URL, 2: HTTP status code. */
            $message = sprintf(__('The website %1$s is reachable again. HTTP Status Code: %2$d.', 'uptime-monitor'), $url, $status_code);
        } else {
            /* translators: %s: Monitored URL. */
            $message = sprintf(__('The website %s is reachable again.', 'uptime-monitor'), $url);
        }

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Sends a Pushover alert for a down URL.
     *
     * @param string $url The URL that is down.
     * @param int|null $status_code The HTTP status code.
     * @param string $error_message Optional network error message.
     * @param bool $is_final_alert Whether this is the last alert until recovery.
     */
    private function send_pushover_alert($url, ?int $status_code, string $error_message = '', bool $is_final_alert = false): void
    {
        if ($status_code !== null) {
            /* translators: 1: Monitored URL, 2: HTTP status code. */
            $message = sprintf(__('The website %1$s is down. HTTP Status Code: %2$d.', 'uptime-monitor'), $url, $status_code);
        } else {
            /* translators: 1: Monitored URL, 2: Network error message. */
            $message = sprintf(__('The website %1$s is down. Error: %2$s.', 'uptime-monitor'), $url, $error_message);
        }
        if ($is_final_alert) {
            $message .= "\n\n" . __('No further alerts will be sent for this incident until the website is reachable again.', 'uptime-monitor');
        }

        $this->send_pushover_message($url, __('Website Down Alert', 'uptime-monitor'), $message);
    }

    private function send_pushover_recovery_alert($url, ?int $status_code): void
    {
        if ($status_code !== null) {
            /* translators: 1: Monitored URL, 2: HTTP status code. */
            $message = sprintf(__('The website %1$s is reachable again. HTTP Status Code: %2$d.', 'uptime-monitor'), $url, $status_code);
        } else {
            /* translators: %s: Monitored URL. */
            $message = sprintf(__('The website %s is reachable again.', 'uptime-monitor'), $url);
        }

        $this->send_pushover_message($url, __('Website Recovered', 'uptime-monitor'), $message);
    }

    private function send_pushover_message($url, string $title, string $message): bool
    {
        $user_key = $this->get_pushover_user_key();
        $api_token = $this->get_pushover_api_token();

        if (!$user_key || !$api_token) {
            $this->log_to_json('error', 'Pushover credentials are missing.', [
                'url' => $url,
                'error' => __('Cannot send alert due to missing credentials.', 'uptime-monitor'),
            ]);
            return false;
        }

        $post_data = [
            'token' => $api_token,
            'user' => $user_key,
            'message' => $message,
            'title' => $title,
        ];

        $response = wp_remote_post('https://api.pushover.net/1/messages.json', [
            'body' => $post_data,
        ]);

        if (is_wp_error($response)) {
            $this->log_to_json('error', 'Pushover notification failed.', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return false;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code != 200) {
                $this->log_to_json('error', 'Pushover API responded with unexpected code.', [
                    'url' => $url,
                    'status_code' => $response_code,
                ]);
                return false;
            } else {
                $this->log_to_json('info', 'Pushover notification sent successfully.', [
                    'url' => $url,
                    'status_code' => $response_code,
                ]);
                return true;
            }
        }
    }

    /**
     * Logs a message with a specific type and related data into a JSON file.
     *
     * @param string $type The type or category of the log (e.g., 'error', 'info').
     * @param string $message The message to be logged.
     * @param array $data Optional. Additional data to include in the log entry.
     *
     * @return void
     */
    public static function log_to_json($type, $message, $data = []): void
    {
        $logs = get_option('uptime_monitor_logs', []);
        $logs = is_array($logs) ? $logs : [];

        // Nieuw logitem
        $log_entry = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'data' => $data,
        ];

        // Toevoegen en opslaan
        $logs[] = $log_entry;
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_LOG_ENTRIES);
        }
        update_option('uptime_monitor_logs', $logs, false);
    }

    /**
     * Handles adding a new URL via AJAX.
     *
     * @return void Outputs JSON response with success or error details.
     */
    public function ajax_add_url(): void
    {
        check_ajax_referer('uptime_monitor_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to manage uptime URLs.', 'uptime-monitor')], 403);
        }

        $urls = $this->normalize_stored_urls();
        $posted_url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
        $new_url = $this->sanitize_monitor_url($posted_url);
        $email_alert = isset($_POST['email_alert']) && absint(wp_unslash($_POST['email_alert'])) === 1;
        $pushover_alert = isset($_POST['pushover_alert']) && absint(wp_unslash($_POST['pushover_alert'])) === 1;

        if ($new_url === null) {
            wp_send_json_error(['message' => __('Invalid URL.', 'uptime-monitor')], 400);
        }

        $response = wp_remote_get($new_url, ['timeout' => $this->get_request_timeout()]);
        if (is_wp_error($response)) {
            /* translators: %s: Invalid monitored URL. */
            wp_send_json_error(['message' => sprintf(__('Invalid URL: %s', 'uptime-monitor'), $new_url . ' - ' . $response->get_error_message())], 400);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($this->is_down_status_code($status_code)) {
            /* translators: %d: HTTP status code. */
            wp_send_json_error(['message' => sprintf(__('URL is not reachable. Status code: %d', 'uptime-monitor'), $status_code)], 400);
        }
        $urls[] = [
            'id' => wp_generate_uuid4(),
            'url' => $new_url,
            'email' => $email_alert,
            'pushover' => $pushover_alert,
	        'enabled' => true,
        ];
        $urls = $this->normalize_urls($urls);
        update_option('uptime_monitor_urls', $urls);
        wp_send_json_success($this->get_dashboard_ajax_payload($urls));
    }

    /**
     * Handles the deletion of a URL via AJAX.
     *
     * This function validates the AJAX request, retrieves the list of monitored URLs,
     * removes the specified URL by its unique ID, and returns an updated list of URLs.
     * If the ID is not found, an error message is returned.
     *
     * @return void Outputs a JSON response with success or error details.
     */
    public function ajax_delete_url(): void
    {
        check_ajax_referer('uptime_monitor_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to manage uptime URLs.', 'uptime-monitor')], 403);
        }

        $id_to_delete = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        $urls = $this->normalize_stored_urls();

        foreach ($urls as $index => $url_data) {
            if ($url_data['id'] === $id_to_delete) {
                unset($urls[$index]);
                $urls = array_values($urls); // Herstel de indexen
                update_option('uptime_monitor_urls', $urls);
                $this->delete_status_history_for_url($id_to_delete);
                $this->delete_incident_for_url($id_to_delete);
                wp_send_json_success($this->get_dashboard_ajax_payload($urls));
            }
        }
        wp_send_json_error(['message' => __('URL not found.', 'uptime-monitor')], 404);
    }

    public function ajax_refresh_dashboard(): void
    {
        check_ajax_referer('uptime_monitor_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to view uptime data.', 'uptime-monitor')], 403);
        }

        wp_send_json_success($this->get_dashboard_ajax_payload($this->normalize_stored_urls()));
    }

	/**
	 * Handles AJAX request to toggle monitoring for a specific URL.
	 *
	 * This function updates the 'active' flag of a monitored URL identified by its ID.
	 *
	 * @return void Outputs a JSON response with updated URLs or an error message.
	 */
	public function ajax_toggle_monitoring(): void {
		check_ajax_referer('uptime_monitor_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to manage uptime URLs.', 'uptime-monitor')], 403);
        }

		$id = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
		$enabled = isset($_POST['enabled']) && absint(wp_unslash($_POST['enabled'])) === 1;
		$urls = $this->normalize_stored_urls();

		foreach ($urls as &$url_data) {
			if ($url_data['id'] === $id) {
				$url_data['enabled'] = $enabled;
				update_option('uptime_monitor_urls', $urls);
				wp_send_json_success($this->get_dashboard_ajax_payload($urls));
			}
		}
		wp_send_json_error(['message' => __('URL not found.', 'uptime-monitor')], 404);
	}
}

new SimpleUptimeMonitor();
class UptimeMonitorLogsController extends \WP_REST_Controller {
	/**
	 * Registers REST API routes for the Uptime Monitor.
	 *
	 * This method defines the /uptime-monitor/v1/logs endpoint for retrieving log entries.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route('uptime-monitor/v1', '/logs', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [$this, 'get_logs'],
			'permission_callback' => [$this, 'check_permissions'], // '__return_true' when checking route
			'args'                => [
				'type'      => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				],
				'url'       => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_from' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to'   => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'page'      => [
					'required'          => false,
					'default'           => 1,
					'sanitize_callback' => 'absint',
				],
				'per_page'  => [
					'required'          => false,
					'default'           => 100,
					'sanitize_callback' => 'absint',
				],
				'limit'     => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
				'order'     => [
					'required'          => false,
					'default'           => 'desc',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		]);
	}
	/**
	 * Checks if the current user has permission to access the REST API endpoint.
	 *
	 * @return bool True if the user has 'manage_options' capability, false otherwise.
	 */
	public function check_permissions(\WP_REST_Request $request = null): bool {
		if (current_user_can('manage_options')) {
			return true;
		}

		return $request instanceof \WP_REST_Request && SimpleUptimeMonitor::request_has_valid_read_api_token($request);
	}
	/**
	 * Handles REST API request to fetch the uptime log data.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response|\WP_Error The log data as a REST response or an error if the log file is missing.
	 */
	public function get_logs( \WP_REST_Request $request ) {
		$logs_data = get_option('uptime_monitor_logs', []);
		if (empty($logs_data) || !is_array($logs_data)) {
			return new \WP_Error(
				'no_logs_found',
				__('No logs found.', 'uptime-monitor'),
				['status' => 404]
			);
		}

		$logs_data = array_values(array_filter($logs_data, 'is_array'));
		$filtered_logs = $this->filter_logs($logs_data, $request);
		if (is_wp_error($filtered_logs)) {
			return $filtered_logs;
		}

		$order = sanitize_key((string)$request->get_param('order'));
		if ($order !== 'asc') {
			$filtered_logs = array_reverse($filtered_logs);
		}

		$total = count($filtered_logs);
		$page = max(1, absint($request->get_param('page')));
		$limit = absint($request->get_param('limit'));
		if ($limit > 0) {
			$page = 1;
		}
		$per_page = $limit > 0 ? min(1000, $limit) : min(100, max(1, absint($request->get_param('per_page'))));
		$total_pages = $total > 0 ? (int)ceil($total / $per_page) : 0;
		$offset = ($page - 1) * $per_page;
		$paged_logs = array_slice($filtered_logs, $offset, $per_page);

		$response = new \WP_REST_Response(array_values($paged_logs), 200);
		$response->header('X-WP-Total', (string)$total);
		$response->header('X-WP-TotalPages', (string)$total_pages);
		$response->header('X-Uptime-Monitor-Page', (string)$page);
		$response->header('X-Uptime-Monitor-Per-Page', (string)$per_page);

		return $response;
	}

	private function filter_logs(array $logs_data, \WP_REST_Request $request) {
		$type = sanitize_key((string)$request->get_param('type'));
		$url = trim((string)$request->get_param('url'));
		$date_from = $this->parse_log_date_param($request->get_param('date_from'), false, 'date_from');
		if (is_wp_error($date_from)) {
			return $date_from;
		}
		$date_to = $this->parse_log_date_param($request->get_param('date_to'), true, 'date_to');
		if (is_wp_error($date_to)) {
			return $date_to;
		}

		return array_values(array_filter($logs_data, function (array $entry) use ($type, $url, $date_from, $date_to): bool {
			if ($type !== '' && sanitize_key((string)($entry['type'] ?? '')) !== $type) {
				return false;
			}

			if ($url !== '') {
				$entry_data = isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : [];
				$entry_url = isset($entry_data['url']) ? (string)$entry_data['url'] : '';
				if ($entry_url === '' || stripos($entry_url, $url) === false) {
					return false;
				}
			}

			$timestamp = isset($entry['timestamp']) ? strtotime((string)$entry['timestamp'] . ' UTC') : false;
			if ($timestamp === false) {
				return false;
			}

			if ($date_from !== null && $timestamp < $date_from) {
				return false;
			}

			return $date_to === null || $timestamp <= $date_to;
		}));
	}

	private function parse_log_date_param($value, bool $end_of_day, string $param_name) {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$value .= $end_of_day ? ' 23:59:59 UTC' : ' 00:00:00 UTC';
		} else {
			$value .= ' UTC';
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return new \WP_Error(
				'uptime_monitor_invalid_log_date',
				sprintf(
					/* translators: %s: Invalid log date query parameter name. */
					__('Invalid log date parameter: %s.', 'uptime-monitor'),
					$param_name
				),
				['status' => 400]
			);
		}

		return $timestamp;
	}
}
add_action('rest_api_init', function() {
	$controller = new UptimeMonitorLogsController();
	$controller->register_routes();
});
/**
 * Adds a custom cron schedule interval.
 *
 * This filter allows the plugin to schedule events at custom intervals. In this case,
 * it adds a "twominute" interval with a duration of 120 seconds.
 *
 * @param array $schedules The existing array of cron schedules.
 * @return array The updated array of cron schedules, including the custom interval.
 */
add_filter('cron_schedules', function ($schedules) {
    $monitor_interval = max(60, (int)get_option('uptime_monitor_interval', 120)); // Standaard naar 120 seconden
    $schedules['uptime_monitor_interval'] = [
        'interval' => $monitor_interval,
        /* translators: %d: Monitoring interval in seconds. */
        'display' => sprintf(__('Every %d seconds', 'uptime-monitor'), $monitor_interval)
    ];

    return $schedules;
});

