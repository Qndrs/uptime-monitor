<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

// Remove options added by the plugin
delete_option('uptime_monitor_urls');
delete_option('uptime_monitor_interval');
// Verwijder logbestand als het bestaat.
$log_file = WP_CONTENT_DIR . '/logs/uptime-monitor.log';
if ( file_exists( $log_file ) ) {
	unlink( $log_file );
}

// Controleer of de map leeg is en verwijder deze.
$log_dir = dirname( $log_file );
if ( is_dir( $log_dir ) && count( scandir( $log_dir ) ) == 2 ) { // Alleen '.' en '..' aanwezig
	rmdir( $log_dir );
}
