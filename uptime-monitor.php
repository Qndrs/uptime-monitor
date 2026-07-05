<?php

namespace SimpleUptimeMonitor;
/**
 * Plugin Name: Simple Uptime Monitor
 * Plugin URI: https://github.com/qndrs/uptime-monitor
 * Description: Monitor de beschikbaarheid van websites en ontvang meldingen via e-mail of Pushover. Beheer eenvoudig meerdere URL's vanuit het WordPress-beheerpaneel, met logging, JSON-import/export, REST-ondersteuning en intervalinstellingen.
 * Version: 3.0.0
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
    public const MAX_LOG_FILE_SIZE = 5242880;
    public const MAX_LOG_ENTRIES = 1000;
    private const CRON_HOOK = 'monitor_uptime_event';
    private const CRON_SCHEDULE = 'uptime_monitor_interval';

    /**
     * Constructor.
     * Registers hooks for plugin functionality.
     */
    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
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
     * Load the plugin textdomain for translations.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain('uptime-monitor', false, dirname(plugin_basename(__FILE__)) . '/languages/');
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

    private function reschedule_monitoring(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    private function normalize_stored_urls(): array
    {
        $urls = $this->normalize_urls(get_option('uptime_monitor_urls', []));
        update_option('uptime_monitor_urls', $urls);

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
            wp_enqueue_style('uptime-monitor-styles', plugin_dir_url(__FILE__) . 'css/uptime-monitor.css');
            // Enqueue admin scripts
            wp_enqueue_script('uptime-monitor-scripts', plugin_dir_url(__FILE__) . 'js/uptime-monitor.js', ['jquery'], '1.0', true);
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
            ]);
        }
    }

    /**
     * Renders the admin page for managing monitored URLs.
     */
    public function render_urls_page(): void
    {
        $urls = $this->normalize_stored_urls();

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!isset($_POST['uptime_monitor_nonce_field']) || !wp_verify_nonce($_POST['uptime_monitor_nonce_field'], 'uptime_monitor_nonce_action')) {
                wp_die('Security check failed.');
            }


            $new_url = $this->sanitize_monitor_url($_POST['url'] ?? '');
            $email_alert = isset($_POST['email_alert']);
            $pushover_alert = isset($_POST['pushover_alert']);

            if ($new_url === null) {
                echo '<div class="error"><p>' . esc_html__('Invalid URL.', 'uptime-monitor') . '</p></div>';
            } else {
                $response = wp_remote_get($new_url, ['timeout' => 10]);
                if (is_wp_error($response)) {
                    echo '<div class="error"><p>' . esc_html__('Invalid URL:', 'uptime-monitor') . ' ' . esc_html($new_url) . '</p></div>';
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($status_code < 200 || $status_code >= 300) {
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
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_uptime_monitor')) {
                wp_die('Security check failed.');
            }
            $index_to_delete = (int)$_GET['delete'];
            unset($urls[$index_to_delete]);
            update_option('uptime_monitor_urls', array_values($urls));
            add_settings_error('uptime_monitor', 'uptime_notice', __('URL deleted successfully!', 'uptime-monitor'), 'updated');
            settings_errors('uptime_monitor');
        }

        echo '<div class="wrap uptime-monitor-admin">';
        echo '<h1>' . __('Manage URLs', 'uptime-monitor') . '</h1>';
        echo '<form id="uptime-monitor-form" method="post" class="uptime-monitor-form">';
        wp_nonce_field('uptime_monitor_nonce_action', 'uptime_monitor_nonce_field');
        echo '<table class="form-table">';
        echo '<tr><td><label for="url">' . __('URL', 'uptime-monitor') . '</label><input type="text" id="url" name="url" required>';
        echo '<label for="email_alert">' . __('Email Alert', 'uptime-monitor') . '</label><input type="checkbox" id="email_alert" name="email_alert" value="1">';
        echo '<label for="pushover_alert">' . __('Pushover Alert', 'uptime-monitor') . '</label><input type="checkbox" id="pushover_alert" name="pushover_alert" value="1"></td>';
        echo '</table>';
        echo '<p><input type="submit" class="button button-primary" value="' . __('Add URL', 'uptime-monitor') . '"></p>';
        echo '</form>';


        echo '<h2>' . __('Existing URLs', 'uptime-monitor') . '</h2>';
        echo '<table class="widefat fixed uptime-monitor-table">';
        echo '<thead><tr><th>' . __('URL', 'uptime-monitor') . '</th><th>' . __('Email Alerts', 'uptime-monitor') . '</th><th>' . __('Pushover Alerts', 'uptime-monitor') . '</th><th>' . __('Monitoring Enabled', 'uptime-monitor') . '</th><th>' . __('Actions', 'uptime-monitor') . '</th></tr></thead>';
        echo '<tbody>';
        if (empty($urls)) {
            echo '<tr><td colspan="5">' . esc_html__('No URLs available. Add one!', 'uptime-monitor') . '</td></tr>';
        } else {
            foreach ($urls as $index => $url_data) {
                echo '<tr>';
                echo '<td>' . esc_html($url_data['url']) . '</td>';
                echo '<td>' . ($url_data['email'] ? esc_html__('Enabled', 'uptime-monitor') : esc_html__('Disabled', 'uptime-monitor')) . '</td>';
                echo '<td>' . ($url_data['pushover'] ? esc_html__('Enabled', 'uptime-monitor') : esc_html__('Disabled', 'uptime-monitor')) . '</td>';
                echo '<td>';
                echo '<input type="checkbox" class="toggle-monitoring" data-id="' . esc_attr($url_data['id']) . '" ' . checked($url_data['enabled'], true, false) . '>';
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
        // Opslaan van instellingen
	    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		    check_admin_referer('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

		    if (!empty($_POST['import_json'])) {
			    // IMPORT VAN JSON
			    $json_input = stripslashes(trim($_POST['import_json']));
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
			    $monitor_interval = max(60, intval($_POST['monitor_interval'] ?? 120));
			    update_option('uptime_monitor_interval', $monitor_interval);

                $this->reschedule_monitoring();

			    echo '<div class="updated"><p>' . __('Settings saved!', 'uptime-monitor') . '</p></div>';
		    }
	    }


        // Huidige instellingen ophalen
	    $monitor_interval = $this->get_monitor_interval();
	    $urls = $this->normalize_stored_urls();

	    $export_data = [
		    'settings' => [ 'monitor_interval' => $monitor_interval ],
		    'urls'     => $urls,
	    ];

	    $json_export = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


	    echo '<div class="wrap">';
        echo '<h1>' . __('Uptime Monitor Settings', 'uptime-monitor') . '</h1>';
        echo "<p>" . __('Pushover credentials are securely managed via your wp-config.php file. Contact your site administrator to update these values.', 'uptime-monitor') . "</p>";
        echo "<pre>define('PUSHOVER_USER_KEY', 'your-pushover-user-key');define('PUSHOVER_API_TOKEN', 'your-pushover-api-token');</pre>";
        echo '<form method="post">';
        wp_nonce_field('uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="monitor_interval">' . __('Monitor Interval (seconds)', 'uptime-monitor') . '</label></th>';
        echo '<td><input type="number" id="monitor_interval" name="monitor_interval" value="' . esc_attr($monitor_interval) . '" min="60" step="60"></td>';
        echo '</tr>';
        echo '</table>';

	    echo '<h2>' . __('Export Configuration', 'uptime-monitor') . '</h2>';
	    echo '<textarea readonly rows="10" style="width:100%; font-family:monospace;">' . esc_textarea($json_export) . '</textarea>';

	    echo '<h2>' . __('Import Configuration', 'uptime-monitor') . '</h2>';
	    echo '<p>' . __('Paste a previously exported JSON configuration below.', 'uptime-monitor') . '</p>';
	    echo '<textarea name="import_json" rows="10" style="width:100%; font-family:monospace;"></textarea>';


	    echo '<p><input type="submit" class="button button-primary" value="' . __('Save Settings', 'uptime-monitor') . '"></p>';
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

        foreach ($urls as $url_data) {
	        if (isset($url_data['enabled']) && $url_data['enabled'] === false) {
		        $this->log_to_json('info', 'Monitoring disabled for URL.', ['url' => $url_data['url']]);
		        continue;
	        }
	        for ($i = 0; $i < 3; $i++) {
                $response = wp_remote_get($url_data['url'], ['timeout' => 10]);
                if (!is_wp_error($response)) {
                    $this->log_to_json('info', 'Successful retry.', ['url' => $url_data['url'], 'attempt' => $i + 1]);
                    break;
                }
            }
            if (is_wp_error($response)) {
                $this->log_to_json('error', 'Failed to fetch URL.', ['url' => $url_data['url'], 'error' => $response->get_error_message()]);
                continue;
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $this->log_to_json('info', 'HTTP status code received.', ['url' => $url_data['url'], 'status_code' => $status_code]);
            if ($status_code >= 200 and $status_code < 300) {
                $this->log_to_json('info', 'Url is up.', ['url' => $url_data['url']]);

            } else {
                $this->log_to_json('error', 'URL is down.', ['url' => $url_data['url'], 'status_code' => $status_code]);

                if ($url_data['email']) {
                    $this->send_email_alert($url_data['url'], $status_code);
                }
                if ($url_data['pushover']) {
                    $this->send_pushover_alert($url_data['url'], $status_code);
                }
            }
        }
    }

    /**
     * Sends an email alert for a down URL.
     *
     * @param string $url The URL that is down.
     * @param int $status_code The HTTP status code.
     */
    private function send_email_alert($url, $status_code): void
    {
        $admin_email = get_option('admin_email');
        $subject = sprintf(__('Website Down Alert: %s', 'uptime-monitor'), $url);
        $message = sprintf(__('The website %s is down. HTTP Status Code: %d.', 'uptime-monitor'), $url, $status_code);
        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Sends a Pushover alert for a down URL.
     *
     * @param string $url The URL that is down.
     * @param int $status_code The HTTP status code.
     */
    private function send_pushover_alert($url, $status_code): void
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

        $message = sprintf(__('The website %s is down. HTTP Status Code: %d.', 'uptime-monitor'), $url, $status_code);
        $title = __('Website Down Alert', 'uptime-monitor');

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
        $log_file = WP_CONTENT_DIR . '/logs/uptime-monitor.json';

        // Zorg dat de map bestaat
        if (!file_exists(dirname($log_file))) {
            mkdir(dirname($log_file), 0755, true);
        }

        // Bestaande logs ophalen, maar voorkom dat een groot logbestand WordPress laat crashen.
        $logs = [];
        if (file_exists($log_file) && filesize($log_file) <= self::MAX_LOG_FILE_SIZE) {
            $decoded_logs = json_decode(file_get_contents($log_file), true);
            $logs = is_array($decoded_logs) ? $decoded_logs : [];
        }

        // Nieuw logitem
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'data' => $data,
        ];

        // Toevoegen en opslaan
        $logs[] = $log_entry;
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_LOG_ENTRIES);
        }
        file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        $new_url = $this->sanitize_monitor_url(wp_unslash($_POST['url'] ?? ''));
        $email_alert = isset($_POST['email_alert']) && $_POST['email_alert'] == 1;
        $pushover_alert = isset($_POST['pushover_alert']) && $_POST['pushover_alert'] == 1;

        if ($new_url === null) {
            wp_send_json_error(['message' => __('Invalid URL.', 'uptime-monitor')], 400);
        }

        $response = wp_remote_get($new_url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => sprintf(__('Invalid URL: %s', 'uptime-monitor'), $new_url)], 400);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
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
        wp_send_json_success(['urls' => $urls]);
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

        $id_to_delete = sanitize_key($_POST['id'] ?? '');
        $urls = $this->normalize_stored_urls();

        foreach ($urls as $index => $url_data) {
            if ($url_data['id'] === $id_to_delete) {
                unset($urls[$index]);
                $urls = array_values($urls); // Herstel de indexen
                update_option('uptime_monitor_urls', $urls);
                wp_send_json_success(['urls' => $urls]);
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

		$id = sanitize_key($_POST['id'] ?? '');
		$enabled = isset($_POST['enabled']) && $_POST['enabled'] == 1;
		$urls = $this->normalize_stored_urls();

		foreach ($urls as &$url_data) {
			if ($url_data['id'] === $id) {
				$url_data['enabled'] = $enabled;
				update_option('uptime_monitor_urls', $urls);
				wp_send_json_success(['urls' => $urls]);
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
		$log_file = WP_CONTENT_DIR . '/logs/uptime-monitor.json';
		if (!file_exists($log_file)) {
			return new \WP_Error(
				'no_logs_found',
				__('No logs found.', 'uptime-monitor'),
				['status' => 404]
			);
		}
		if (filesize($log_file) > SimpleUptimeMonitor::MAX_LOG_FILE_SIZE) {
			return new \WP_Error(
				'logs_too_large',
				__('The log file is too large to return through the REST API.', 'uptime-monitor'),
				['status' => 413]
			);
		}

		$logs = file_get_contents($log_file);
		$logs_data = json_decode($logs, true);

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
        'display' => sprintf(__('Every %d seconds', 'uptime-monitor'), $monitor_interval)
    ];

    return $schedules;
});
