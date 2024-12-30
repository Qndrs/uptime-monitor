<?php
namespace UptimeMonitor;
/*
Plugin Name: Uptime Monitor Extension for Get URL Cron
Description: Extends Get URL Cron plugin to monitor website uptime and send alerts.
Version: 2.4.0
Author: Robert E. Kuunders, GPT
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UptimeMonitor
 * Main plugin class for handling uptime monitoring.
 */
class UptimeMonitorExtension {
	/**
	 * Constructor.
	 * Registers hooks for plugin functionality.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'monitor_uptime_event', [ $this, 'monitor_uptime' ] );
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		// AJAX hooks
		add_action( 'wp_ajax_add_uptime_url', [ $this, 'ajax_add_url' ] );
		add_action( 'wp_ajax_delete_uptime_url', [ $this, 'ajax_delete_url' ] );
	}
	/**
	 * Load the plugin textdomain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'uptime-monitor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	/**
	 * Activation hook.
	 * Sets up initial settings and schedules monitoring events.
	 */
	public function activate():void {
		if ( ! wp_next_scheduled( 'monitor_uptime_event' ) ) {
			wp_schedule_event( time(), 'monitor_uptime_event', 'monitor_uptime_event' );
		}
	}
	/**
	 * Deactivation hook.
	 * Clears scheduled events on plugin deactivation.
	 */
	public function deactivate():void  {
		$timestamp = wp_next_scheduled( 'monitor_uptime_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'monitor_uptime_event' );
		}
	}
	/**
	 * Adds a menu page for the plugin in the WordPress admin.
	 */
	public function add_menu_page():void  {
		add_menu_page(
			__( 'Uptime Monitor', 'uptime-monitor' ),
			__( 'Uptime Monitor', 'uptime-monitor' ),
			'manage_options',
			'uptime-monitor',
			[ $this, 'render_urls_page' ],
			'dashicons-admin-site-alt3'
		);
		add_submenu_page(
			'uptime-monitor',
			__( 'Settings', 'uptime-monitor' ),
			__( 'Settings', 'uptime-monitor' ),
			'manage_options',
			'uptime-monitor-settings',
			[ $this, 'render_settings_page' ]
		);
	}
	/**
	 * Enqueues styles and scripts for the admin interface.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_styles( $hook_suffix ):void  {
		if ( $hook_suffix === 'toplevel_page_uptime-monitor' ) {
			// Enqueue admin styles
			wp_enqueue_style( 'uptime-monitor-styles', plugin_dir_url( __FILE__ ) . 'css/uptime-monitor.css' );
			// Enqueue admin scripts
			wp_enqueue_script( 'uptime-monitor-scripts', plugin_dir_url( __FILE__ ) . 'js/uptime-monitor.js', [ 'jquery' ], '1.0', true );
			// Localize AJAX script
			wp_localize_script( 'uptime-monitor-scripts', 'uptimeMonitorAjax', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'uptime_monitor_nonce' ),
			]);
			// Localize script for translations
			wp_localize_script( 'uptime-monitor-scripts', 'uptimeMonitorL10n', [
				'add_success'    => __( 'URL added successfully!', 'uptime-monitor' ),
				'delete_success' => __( 'URL deleted successfully!', 'uptime-monitor' ),
				'error'          => __( 'An error occurred: ', 'uptime-monitor' ),
				'error_generic'  => __( 'A general error occurred. Please try again.', 'uptime-monitor' ),
				'no_urls'        => __( 'No URLs available. Add one!', 'uptime-monitor' ),
				'delete'         => __( 'Delete', 'uptime-monitor' ),
				'enabled'        => __( 'Enabled', 'uptime-monitor' ),
				'disabled'       => __( 'Disabled', 'uptime-monitor' ),
			]);
		}
	}
	/**
	 * Renders the admin page for managing monitored URLs.
	 */
	public function render_urls_page():void  {
		$urls               = get_option( 'uptime_monitor_urls', [] );

		if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			if ( ! isset( $_POST['uptime_monitor_nonce_field'] ) || ! wp_verify_nonce( $_POST['uptime_monitor_nonce_field'], 'uptime_monitor_nonce_action' ) ) {
				wp_die( 'Security check failed.' );
			}


			$new_url        = sanitize_text_field( $_POST['url'] );
			$email_alert    = isset( $_POST['email_alert'] );
			$pushover_alert = isset( $_POST['pushover_alert'] );

			$response = wp_remote_get( $new_url, [ 'timeout' => 10 ] );
			if ( is_wp_error( $response ) ) {
				echo '<div class="error"><p>' . esc_html__( 'Invalid URL:', 'uptime-monitor' ) . ' ' . esc_html( $new_url ) . '</p></div>';
			} else {
				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code < 200 || $status_code >= 300 ) {
					echo '<div class="error"><p>' . esc_html__( 'URL is not reachable (status code:', 'uptime-monitor' ) . ' ' . esc_html( $status_code ) . ').</p></div>';
				} else {
					$urls[] = [
						'url'      => $new_url,
						'email'    => $email_alert,
						'pushover' => $pushover_alert
					];
					update_option( 'uptime_monitor_urls', $urls );
					add_settings_error( 'uptime_monitor', 'uptime_notice', __( 'URL added successfully!', 'uptime-monitor' ), 'updated' );
					settings_errors('uptime_monitor');

				}
			}

		}

		if ( isset( $_GET['delete'] ) ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_uptime_monitor' ) ) {
				wp_die( 'Security check failed.' );
			}
			$index_to_delete = (int) $_GET['delete'];
			unset( $urls[ $index_to_delete ] );
			update_option( 'uptime_monitor_urls', array_values( $urls ) );
			add_settings_error( 'uptime_monitor', 'uptime_notice', __( 'URL deleted successfully!', 'uptime-monitor' ), 'updated' );
			settings_errors('uptime_monitor');
		}

		echo '<div class="wrap uptime-monitor-admin">';
		echo '<h1>' . __( 'Manage URLs', 'uptime-monitor' ) . '</h1>';
		echo '<form id="uptime-monitor-form" method="post" class="uptime-monitor-form">';
		wp_nonce_field( 'uptime_monitor_nonce_action', 'uptime_monitor_nonce_field' );
		echo '<table class="form-table">';
		echo '<tr><td><label for="url">' . __( 'URL', 'uptime-monitor' ) . '</label><input type="text" id="url" name="url" required>';
		echo '<label for="email_alert">' . __( 'Email Alert', 'uptime-monitor' ) . '</label><input type="checkbox" id="email_alert" name="email_alert" value="1">';
		echo '<label for="pushover_alert">' . __( 'Pushover Alert', 'uptime-monitor' ) . '</label><input type="checkbox" id="pushover_alert" name="pushover_alert" value="1"></td>';
		echo '</table>';
		echo '<p><input type="submit" class="button button-primary" value="' . __( 'Add URL', 'uptime-monitor' ) . '"></p>';
		echo '</form>';


		echo '<h2>' . __( 'Existing URLs', 'uptime-monitor' ) . '</h2>';
		echo '<table class="widefat fixed uptime-monitor-table">';
		echo '<thead><tr><th>' . __( 'URL', 'uptime-monitor' ) . '</th><th>' . __( 'Email Alerts', 'uptime-monitor' ) . '</th><th>' . __( 'Pushover Alerts', 'uptime-monitor' ) . '</th><th>' . __( 'Actions', 'uptime-monitor' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $urls as $index => $url_data ) {
			echo '<tr>';
			echo '<td>' . esc_html( $url_data['url'] ) . '</td>';
			echo '<td>' . ( $url_data['email'] ? __( 'Enabled', 'uptime-monitor' ) : __( 'Disabled', 'uptime-monitor' ) ) . '</td>';
			echo '<td>' . ( $url_data['pushover'] ? __( 'Enabled', 'uptime-monitor' ) : __( 'Disabled', 'uptime-monitor' ) ) . '</td>';
			echo '<td><button class="button delete-url" data-id="' . esc_attr( $url_data['id'] ) . '">' . __( 'Delete', 'uptime-monitor' ) . '</button></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}
	/**
	 * Render the settings page for Uptime Monitor.
	 */
	public function render_settings_page(): void {
		// Opslaan van instellingen
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			check_admin_referer( 'uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce' );

			$monitor_interval = intval( $_POST['monitor_interval'] );
			update_option( 'uptime_monitor_interval', $monitor_interval );

			// Reschedule event
			$timestamp = wp_next_scheduled( 'monitor_uptime_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'monitor_uptime_event' );
			}
			wp_schedule_event( time(), 'uptime_monitor_interval', 'monitor_uptime_event' );

			echo '<div class="updated"><p>' . __( 'Settings saved!', 'uptime-monitor' ) . '</p></div>';
		}

		// Huidige instellingen ophalen
		$monitor_interval = get_option( 'uptime_monitor_interval', 120 );

		echo '<div class="wrap">';
		echo '<h1>' . __( 'Uptime Monitor Settings', 'uptime-monitor' ) . '</h1>';
		echo "<p>" . __( 'Pushover credentials are securely managed via your wp-config.php file. Contact your site administrator to update these values.', 'uptime-monitor' ) . "</p>";
		echo "<pre>define('PUSHOVER_USER_KEY', 'your-pushover-user-key');define('PUSHOVER_API_TOKEN', 'your-pushover-api-token');</pre>";
		echo '<form method="post">';
		wp_nonce_field( 'uptime_monitor_settings_nonce_action', 'uptime_monitor_settings_nonce' );

		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row"><label for="monitor_interval">' . __( 'Monitor Interval (seconds)', 'uptime-monitor' ) . '</label></th>';
		echo '<td><input type="number" id="monitor_interval" name="monitor_interval" value="' . esc_attr( $monitor_interval ) . '" min="60" step="60"></td>';
		echo '</tr>';
		echo '</table>';

		echo '<p><input type="submit" class="button button-primary" value="' . __( 'Save Settings', 'uptime-monitor' ) . '"></p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handles the monitoring process for registered URLs.
	 */
	public function monitor_uptime():void  {
		$this->log_error( 'monitor_uptime_event triggered at ' . date( 'Y-m-d H:i:s' ) );
		$urls = get_option( 'uptime_monitor_urls', [] );
		$this->log_error( 'URLs to monitor: ' . print_r( $urls, true ) );
		foreach ( $urls as $url_data ) {
			for ($i = 0; $i < 3; $i++) {
				$response = wp_remote_get($url_data['url'], ['timeout' => 10]);
				if (!is_wp_error($response)) break;
			}
			if ( is_wp_error( $response ) ) {
				$this->log_error( sprintf( __( 'Final HTTP request (tried 3 times) failed for %s: %s', 'uptime-monitor' ), $url_data['url'], $response->get_error_message() ) );
				continue;
			}
			$status_code = wp_remote_retrieve_response_code( $response );
			$this->log_error( 'HTTP status code for ' . $url_data['url'] . ': ' . $status_code );
			if ( $status_code >= 200 AND $status_code < 300 ) {
				$this->log_error( 'URL is up: ' . $url_data['url'] );
			} else {
				$this->log_error( sprintf( __( 'URL %s is down. HTTP Status Code: %d', 'uptime-monitor' ), $url_data['url'], $status_code ) );
				if ( $url_data['email'] ) {
					$this->send_email_alert( $url_data['url'], $status_code );
				}
				if ( $url_data['pushover'] ) {
					$this->send_pushover_alert( $url_data['url'], $status_code );
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
	private function send_email_alert( $url, $status_code ):void  {
		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf( __( 'Website Down Alert: %s', 'uptime-monitor' ), $url );
		$message     = sprintf( __( 'The website %s is down. HTTP Status Code: %d.', 'uptime-monitor' ), $url, $status_code );
		wp_mail( $admin_email, $subject, $message );
	}
	/**
	 * Sends a Pushover alert for a down URL.
	 *
	 * @param string $url The URL that is down.
	 * @param int $status_code The HTTP status code.
	 */
	private function send_pushover_alert( $url, $status_code ):void  {
		$user_key  = defined( 'PUSHOVER_USER_KEY' ) ? PUSHOVER_USER_KEY : '';
		$api_token = defined( 'PUSHOVER_API_TOKEN' ) ? PUSHOVER_API_TOKEN : '';

		if ( ! $user_key || ! $api_token ) {
			$this->log_error( sprintf( __( 'Pushover credentials are missing. Cannot send alert for URL: %s', 'uptime-monitor' ), $url ) );
			return;
		}

		$message = sprintf( __( 'The website %s is down. HTTP Status Code: %d.', 'uptime-monitor' ), $url, $status_code );
		$title   = __( 'Website Down Alert', 'uptime-monitor' );

		$post_data = [
			'token'   => $api_token,
			'user'    => $user_key,
			'message' => $message,
			'title'   => $title,
		];

		$response = wp_remote_post( 'https://api.pushover.net/1/messages.json', [
			'body' => $post_data,
		] );

		if ( is_wp_error( $response ) ) {
			$this->log_error( sprintf( __( 'Pushover notification failed for URL %s: %s', 'uptime-monitor' ), $url, $response->get_error_message() ) );
		} else {
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code != 200 ) {
				$this->log_error( sprintf( __( 'Pushover API responded with code: %d for URL: %s', 'uptime-monitor' ), $response_code, $url ) );
			}
		}
	}
	/**
	 * Logs an error message to a dedicated log file.
	 *
	 * @param string $message The error message to log.
	 */
	private function log_error( $message ):void  {
		$log_file = WP_CONTENT_DIR . '/logs/uptime-monitor.log';
		if ( ! file_exists( dirname( $log_file ) ) ) {
			mkdir( dirname( $log_file ), 0755, true );
		}
		error_log( '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL, 3, $log_file );
	}
	/**
	 * Handles adding a new URL via AJAX.
	 *
	 * @return void Outputs JSON response with success or error details.
	 */
	public function ajax_add_url():void {
		check_ajax_referer( 'uptime_monitor_nonce', 'nonce' );

		$urls = get_option( 'uptime_monitor_urls', [] );
		$new_url = sanitize_text_field( $_POST['url'] );
		$email_alert = isset( $_POST['email_alert'] ) && $_POST['email_alert'] == 1 ;
		$pushover_alert = isset( $_POST['pushover_alert'] ) && $_POST['pushover_alert'] == 1 ;

		$response = wp_remote_get( $new_url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Invalid URL: ' . esc_html( $new_url ) ] );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			wp_send_json_error( [ 'message' => 'URL is not reachable. Status code: ' . $status_code ] );
		}
		$unique_id = wp_generate_uuid4(); // Generate a unique identifier for the URL
		$urls[] = [
			'id'       => $unique_id,
			'url'      => $new_url,
			'email'    => $email_alert,
			'pushover' => $pushover_alert,
		];
		update_option( 'uptime_monitor_urls', $urls );
		wp_send_json_success( [ 'urls' => $urls ] );
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
	public function ajax_delete_url():void {
		check_ajax_referer( 'uptime_monitor_nonce', 'nonce' );

		$id_to_delete = sanitize_text_field( $_POST['id'] );
		$urls = get_option( 'uptime_monitor_urls', [] );

		foreach ( $urls as $index => $url_data ) {
			if ( $url_data['id'] === $id_to_delete ) {
				unset( $urls[ $index ] );
				$urls = array_values( $urls ); // Herstel de indexen
				update_option( 'uptime_monitor_urls', array_values( $urls ) );
				wp_send_json_success( [ 'urls' => $urls ] );
			}
		}
		wp_send_json_error( [ 'message' => 'URL not found.' ] );
	}

}

new UptimeMonitorExtension();

/**
 * Adds a custom cron schedule interval.
 *
 * This filter allows the plugin to schedule events at custom intervals. In this case,
 * it adds a "twominute" interval with a duration of 120 seconds.
 *
 * @param array $schedules The existing array of cron schedules.
 * @return array The updated array of cron schedules, including the custom interval.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	$monitor_interval = get_option( 'uptime_monitor_interval', 120 ); // Standaard naar 120 seconden
	$schedules['uptime_monitor_interval'] = [
		'interval' => $monitor_interval,
		'display'  => sprintf( __( 'Every %d seconds', 'uptime-monitor' ), $monitor_interval )
	];

	return $schedules;
} );
