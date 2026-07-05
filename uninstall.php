<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

// Remove options added by the plugin
delete_option('uptime_monitor_urls');
delete_option('uptime_monitor_interval');
delete_option('uptime_monitor_logs');
delete_option('uptime_monitor_history');
delete_option('uptime_monitor_incidents');
delete_option('uptime_monitor_retry_attempts');
delete_option('uptime_monitor_request_timeout');
delete_option('uptime_monitor_down_status_codes');
delete_option('uptime_monitor_pushover_user_key');
delete_option('uptime_monitor_pushover_api_token');
