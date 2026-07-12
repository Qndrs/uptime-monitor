<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

// Remove options added by the plugin
delete_option('qndrs_ahm_urls');
delete_option('qndrs_ahm_interval');
delete_option('qndrs_ahm_logs');
delete_option('qndrs_ahm_history');
delete_option('qndrs_ahm_incidents');
delete_option('qndrs_ahm_retry_attempts');
delete_option('qndrs_ahm_request_timeout');
delete_option('qndrs_ahm_down_status_codes');
delete_option('qndrs_ahm_pushover_user_key');
delete_option('qndrs_ahm_pushover_api_token');
delete_option('qndrs_ahm_read_api_token_hash');
delete_option('qndrs_ahm_read_api_token_last4');
delete_option('qndrs_ahm_read_api_token_created_at');

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
delete_option('uptime_monitor_read_api_token_hash');
delete_option('uptime_monitor_read_api_token_last4');
delete_option('uptime_monitor_read_api_token_created_at');
