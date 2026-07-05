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
