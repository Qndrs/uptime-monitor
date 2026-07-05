=== Simple Uptime Monitor ===
Contributors: qndrs
Tags: uptime, monitoring, notifications, pushover, cron
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor external websites from WordPress and receive downtime alerts by email or Pushover.

== Description ==

Simple Uptime Monitor checks external URLs from the WordPress dashboard. It supports multiple monitored URLs, per-URL monitoring toggles, email alerts, Pushover alerts, JSON import/export, structured logging, and an authenticated REST endpoint for logs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/uptime-monitor`.
2. Activate Simple Uptime Monitor from the WordPress plugins page.
3. Open Uptime Monitor in the WordPress admin menu.
4. Add one or more URLs and choose notification channels.

For more reliable checks, configure a real server cron to call `wp-cron.php`.

== Frequently Asked Questions ==

= How do I configure Pushover? =

Add these constants to `wp-config.php`:

`define('PUSHOVER_USER_KEY', 'your-user-key');`
`define('PUSHOVER_API_TOKEN', 'your-api-token');`

= Where are logs stored? =

Logs are stored in the WordPress database option `uptime_monitor_logs` and capped to avoid unbounded growth.

= Is the REST endpoint public? =

No. The log endpoint requires a user with the `manage_options` capability.

== Changelog ==

= 3.0.1 =

* Fixed cron scheduling on activation and settings changes.
* Added data normalization for existing and imported URL records.
* Added explicit capability checks for AJAX actions.
* Improved URL and import validation.
* Fixed admin table rendering after AJAX updates.
* Hardened JSON log handling and REST log responses for large log files.
* Cleaned README encoding and release documentation.

= 3.0.0 =

* Updated Dutch language files.
* Extended logging for REST access.

= 2.9.0 =

* Added toggle per URL to disable or enable monitoring.
* Improved REST implementation and JSON logging.
* Added JSON import and export for full configuration.

= 1.0.0 =

* Initial release with URL monitoring and email alerts.
