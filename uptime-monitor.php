<?php

namespace SimpleUptimeMonitor;
/**
 * Plugin Name: Simple Uptime Monitor
 * Plugin URI: https://github.com/qndrs/uptime-monitor
 * Description: Monitor de beschikbaarheid van websites en ontvang meldingen via e-mail of Pushover. Beheer eenvoudig meerdere URL's vanuit het WordPress-beheerpaneel, met logging, JSON-import/export, REST-ondersteuning en intervalinstellingen.
 * Version: 3.1.0
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
    public const VERSION = '3.1.0';
    public const MAX_LOG_ENTRIES = 1000;
    public const MAX_STATUS_HISTORY_PER_URL = 43200;
    public const MAX_STATUS_HISTORY_DAYS = 30;
    public const MAX_INCIDENT_ALERTS = 3;
    public const DEFAULT_RETRY_ATTEMPTS = 3;
    public const DEFAULT_REQUEST_TIMEOUT = 10;
    public const DEFAULT_DOWN_STATUS_CODES = '100-199,300-599';
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action(self::CRON_HOOK, [$this, 'monitor_uptime']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        // AJAX hooks
        add_action('wp_ajax_add_uptime_url', [$this, 'ajax_add_url']);
        add_action('wp_ajax_delete_uptime_url', [$this, 'ajax_delete_url']);
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
        if (!is_array($url_data) || empty($url_data['url'])) {
            return null;
        }

        $url = $this->sanitize_monitor_url($url_data['url']);
        if ($url === null) {
            return null;
        }

        return [
            'id' => !empty($url_data['id']) ? sanitize_key($url_data['id']) : wp_generate_uuid4(),
            'url' => $url,
            'email' => !empty($url_data['email']),
            'pushover' => !empty($url_data['pushover']),
            'enabled' => array_key_exists('enabled', $url_data) ? (bool)$url_data['enabled'] : true,
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

    /**
     * Enqueues styles and scripts for the admin interface.
     *
     * @param string $hook_suffix The current admin page hook.
     */
    public function enqueue_styles($hook_suffix): void
    {
        if ($hook_suffix === 'toplevel_page_uptime-monitor') {
            // Enqueue admin styles
            wp_enqueue_style('uptime-monitor-styles', plugin_dir_url(__FILE__) . 'css/uptime-monitor.css', [], self::VERSION);
            // Enqueue admin scripts
            wp_enqueue_script('uptime-monitor-scripts', plugin_dir_url(__FILE__) . 'js/uptime-monitor.js', ['jquery'], self::VERSION, true);
            // Localize AJAX script
            wp_localize_script('uptime-monitor-scripts', 'uptimeMonitorAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('uptime_monitor_nonce'),
            ]);
            // Localize script for translations
            wp_localize_script('uptime-monitor-scripts', 'uptimeMonitorL10n', [
                'add_success' => __('URL added successfully!', 'uptime-monitor'),
                'delete_success' => __('URL deleted successfully!', 'uptime-monitor'),
                'error' => __('An error occurred: ', 'uptime-monitor'),
                'error_generic' => __('A general error occurred. Please try again.', 'uptime-monitor'),
                'no_urls' => __('No URLs available. Add one!', 'uptime-monitor'),
                'delete' => __('Delete', 'uptime-monitor'),
                'enabled' => __('Enabled', 'uptime-monitor'),
                'disabled' => __('Disabled', 'uptime-monitor'),
                'history' => __('Status History', 'uptime-monitor'),
                'no_history' => __('No checks yet.', 'uptime-monitor'),
                'status_up' => __('Up', 'uptime-monitor'),
                'status_down' => __('Down', 'uptime-monitor'),
                'status_error' => __('Error', 'uptime-monitor'),
                /* translators: %d: HTTP status code. */
                'http_status' => __('HTTP %d', 'uptime-monitor'),
                /* translators: %d: Response time in milliseconds. */
                'response_time_ms' => __('%d ms', 'uptime-monitor'),
                /* translators: %d: Average response time in milliseconds. */
                'average_response_time_ms' => __('Avg %d ms', 'uptime-monitor'),
                'trend_faster' => __('Faster', 'uptime-monitor'),
                'trend_slower' => __('Slower', 'uptime-monitor'),
                'trend_stable' => __('Stable', 'uptime-monitor'),
                'uptime' => __('Uptime', 'uptime-monitor'),
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
        foreach ($urls as &$url_data) {
            $history = $this->get_status_history_for_url($url_data['id']);
            $url_data['history'] = $history;
            $url_data['uptime'] = $this->get_uptime_percentages($history);
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

        if (!empty($url_data['email'])) {
            $this->send_email_recovery_alert($url_data['url'], $status_code);
        }
        if (!empty($url_data['pushover'])) {
            $this->send_pushover_recovery_alert($url_data['url'], $status_code);
        }

        unset($incidents[$url_id]);
        update_option('uptime_monitor_incidents', $incidents, false);
        $this->log_to_json('info', 'Recovery alert sent and incident reset.', [
            'url' => $url_data['url'],
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

        if ($should_send_alert) {
            if (!empty($url_data['email'])) {
                $this->send_email_alert($url_data['url'], $status_code, $error_message, $is_final_alert);
            }
            if (!empty($url_data['pushover'])) {
                $this->send_pushover_alert($url_data['url'], $status_code, $error_message, $is_final_alert);
            }

            $alert_count++;
            $incident['last_alert_at'] = gmdate('Y-m-d H:i:s');
            $this->log_to_json('info', 'Down alert processed.', [
                'url' => $url_data['url'],
                'alert_count' => $alert_count,
                'final_alert_until_recovery' => $is_final_alert,
            ]);
        } else {
            $this->log_to_json('info', 'Down alert suppressed until recovery.', [
                'url' => $url_data['url'],
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
        $formatted = get_date_from_gmt($timestamp, get_option('date_format') . ' ' . get_option('time_format'));

        return $formatted ?: $timestamp . ' UTC';
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
                    echo '<div class="error"><p>' . esc_html__('Invalid URL:', 'uptime-monitor') . ' ' . esc_html($new_url) . '</p></div>';
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

        echo '<div class="wrap uptime-monitor-admin">';
        echo '<h1>' . esc_html__('Manage URLs', 'uptime-monitor') . '</h1>';
        echo '<form id="uptime-monitor-form" method="post" class="uptime-monitor-form">';
        wp_nonce_field('uptime_monitor_nonce_action', 'uptime_monitor_nonce_field');
        echo '<table class="form-table">';
        echo '<tr><td><label for="url">' . esc_html__('URL', 'uptime-monitor') . '</label><input type="text" id="url" name="url" required>';
        echo '<label for="email_alert">' . esc_html__('Email Alert', 'uptime-monitor') . '</label><input type="checkbox" id="email_alert" name="email_alert" value="1">';
        echo '<label for="pushover_alert">' . esc_html__('Pushover Alert', 'uptime-monitor') . '</label><input type="checkbox" id="pushover_alert" name="pushover_alert" value="1"></td>';
        echo '</table>';
        echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__('Add URL', 'uptime-monitor') . '"></p>';
        echo '</form>';


        echo '<h2>' . esc_html__('Existing URLs', 'uptime-monitor') . '</h2>';
        echo '<table class="widefat fixed uptime-monitor-table">';
        echo '<thead><tr><th>' . esc_html__('URL', 'uptime-monitor') . '</th><th>' . esc_html__('Email Alerts', 'uptime-monitor') . '</th><th>' . esc_html__('Pushover Alerts', 'uptime-monitor') . '</th><th>' . esc_html__('Monitoring Enabled', 'uptime-monitor') . '</th><th>' . esc_html__('Status History', 'uptime-monitor') . '</th><th>' . esc_html__('Actions', 'uptime-monitor') . '</th></tr></thead>';
        echo '<tbody>';
        if (empty($urls)) {
            echo '<tr><td colspan="6">' . esc_html__('No URLs available. Add one!', 'uptime-monitor') . '</td></tr>';
        } else {
            foreach ($urls as $index => $url_data) {
                echo '<tr>';
                echo '<td>' . esc_html($url_data['url']) . '</td>';
                echo '<td>' . ($url_data['email'] ? esc_html__('Enabled', 'uptime-monitor') : esc_html__('Disabled', 'uptime-monitor')) . '</td>';
                echo '<td>' . ($url_data['pushover'] ? esc_html__('Enabled', 'uptime-monitor') : esc_html__('Disabled', 'uptime-monitor')) . '</td>';
                echo '<td>';
                echo '<input type="checkbox" class="toggle-monitoring" data-id="' . esc_attr($url_data['id']) . '" ' . checked($url_data['enabled'], true, false) . '>';
                echo '</td>';
                echo '<td>';
                $this->render_status_history_cell($this->get_status_history_for_url($url_data['id']));
                echo '</td>';
                echo '<td><button class="button delete-url" data-id="' . esc_attr($url_data['id']) . '">' . esc_html__('Delete', 'uptime-monitor') . '</button></td>';
                echo '</tr>';
            }
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Render the settings page for Uptime Monitor.
     */
    public function render_settings_page(): void
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        // Opslaan van instellingen
	    if ($request_method === 'POST') {
		    check_admin_referer('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

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
                        echo '<div class="updated"><p>' . esc_html__('Configuration imported successfully!', 'uptime-monitor') . '</p></div>';
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

                $this->reschedule_monitoring();

			    echo '<div class="updated"><p>' . esc_html__('Settings saved!', 'uptime-monitor') . '</p></div>';
		    }
	    }


        // Huidige instellingen ophalen
	    $monitor_interval = $this->get_monitor_interval();
        $retry_attempts = $this->get_retry_attempts();
        $request_timeout = $this->get_request_timeout();
        $down_status_codes = $this->get_down_status_codes();
	    $urls = $this->normalize_stored_urls();

	    $export_data = [
		    'settings' => [
                'monitor_interval' => $monitor_interval,
                'retry_attempts' => $retry_attempts,
                'request_timeout' => $request_timeout,
                'down_status_codes' => $down_status_codes,
            ],
		    'urls'     => $urls,
	    ];

	    $json_export = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


	    echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Uptime Monitor Settings', 'uptime-monitor') . '</h1>';
        echo '<p>' . esc_html__('Pushover credentials are securely managed via your wp-config.php file. Contact your site administrator to update these values.', 'uptime-monitor') . '</p>';
        echo "<pre>define('PUSHOVER_USER_KEY', 'your-pushover-user-key');define('PUSHOVER_API_TOKEN', 'your-pushover-api-token');</pre>";
        echo '<form method="post">';
        wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="monitor_interval">' . esc_html__('Monitor Interval (seconds)', 'uptime-monitor') . '</label></th>';
        echo '<td><input type="number" id="monitor_interval" name="monitor_interval" value="' . esc_attr($monitor_interval) . '" min="60" step="60"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="retry_attempts">' . esc_html__('Retry Attempts', 'uptime-monitor') . '</label></th>';
        echo '<td><input type="number" id="retry_attempts" name="retry_attempts" value="' . esc_attr($retry_attempts) . '" min="1" max="10" step="1"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="request_timeout">' . esc_html__('Request Timeout (seconds)', 'uptime-monitor') . '</label></th>';
        echo '<td><input type="number" id="request_timeout" name="request_timeout" value="' . esc_attr($request_timeout) . '" min="1" max="60" step="1"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="down_status_codes">' . esc_html__('Down Status Codes', 'uptime-monitor') . '</label></th>';
        echo '<td><input type="text" id="down_status_codes" name="down_status_codes" value="' . esc_attr($down_status_codes) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Use comma-separated HTTP status codes or ranges. Default: 100-199,300-599.', 'uptime-monitor') . '</p></td>';
        echo '</tr>';
        echo '</table>';

	    echo '<h2>' . esc_html__('Export Configuration', 'uptime-monitor') . '</h2>';
	    echo '<textarea readonly rows="10" style="width:100%; font-family:monospace;">' . esc_textarea($json_export) . '</textarea>';

	    echo '<h2>' . esc_html__('Import Configuration', 'uptime-monitor') . '</h2>';
	    echo '<p>' . esc_html__('Paste a previously exported JSON configuration below.', 'uptime-monitor') . '</p>';
	    echo '<textarea name="import_json" rows="10" style="width:100%; font-family:monospace;"></textarea>';


	    echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__('Save Settings', 'uptime-monitor') . '"></p>';
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
        $this->log_to_json('info', 'URLs to monitor.', ['urls' => $urls]);
        $retry_attempts = $this->get_retry_attempts();
        $request_timeout = $this->get_request_timeout();

        foreach ($urls as $url_data) {
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

    private function send_pushover_message($url, string $title, string $message): void
    {
        $user_key = defined('PUSHOVER_USER_KEY') ? PUSHOVER_USER_KEY : '';
        $api_token = defined('PUSHOVER_API_TOKEN') ? PUSHOVER_API_TOKEN : '';

        if (!$user_key || !$api_token) {
            $this->log_to_json('error', 'Pushover credentials are missing.', [
                'url' => $url,
                'error' => __('Cannot send alert due to missing credentials.', 'uptime-monitor'),
            ]);
            return;
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
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code != 200) {
                $this->log_to_json('error', 'Pushover API responded with unexpected code.', [
                    'url' => $url,
                    'status_code' => $response_code,
                ]);
            } else {
                $this->log_to_json('info', 'Pushover notification sent successfully.', [
                    'url' => $url,
                    'status_code' => $response_code,
                ]);
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
            wp_send_json_error(['message' => sprintf(__('Invalid URL: %s', 'uptime-monitor'), $new_url)], 400);
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
        wp_send_json_success(['urls' => $this->add_status_history_to_urls($urls)]);
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
                wp_send_json_success(['urls' => $this->add_status_history_to_urls($urls)]);
            }
        }
        wp_send_json_error(['message' => __('URL not found.', 'uptime-monitor')], 404);
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
				wp_send_json_success(['urls' => $this->add_status_history_to_urls($urls)]);
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
		]);
	}
	/**
	 * Checks if the current user has permission to access the REST API endpoint.
	 *
	 * @return bool True if the user has 'manage_options' capability, false otherwise.
	 */
	public function check_permissions(): bool {
		return current_user_can('manage_options'); // Allow only admins
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

		return new \WP_REST_Response($logs_data, 200);
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
