=== Simple Uptime Monitor ===
Contributors: qndrs
Tags: uptime, monitoring, notifications, pushover, cron
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor external websites from WordPress and receive downtime alerts by email or Pushover.

== Description ==

Simple Uptime Monitor checks external URLs from the WordPress dashboard. It supports multiple monitored URLs, per-URL monitoring toggles, email alerts, configurable Pushover alerts, status history, response times, uptime percentages, JSON import/export, structured logging, and an authenticated REST endpoint for logs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/uptime-monitor`.
2. Activate Simple Uptime Monitor from the WordPress plugins page.
3. Open Uptime Monitor in the WordPress admin menu.
4. Add one or more URLs and choose notification channels.

For more reliable checks, configure a real server cron to call `wp-cron.php`.

== Frequently Asked Questions ==

= How do I configure Pushover? =

Configure Pushover credentials from the plugin settings page. Stored credentials are masked and are not included in JSON exports.

You can also add these constants to `wp-config.php`; constants take precedence over stored settings:

`define('PUSHOVER_USER_KEY', 'your-user-key');`
`define('PUSHOVER_API_TOKEN', 'your-api-token');`

= Where are logs stored? =

Logs are stored in the WordPress database option `uptime_monitor_logs` and capped to avoid unbounded growth. The REST log endpoint supports `type`, `url`, `date_from`, `date_to`, `page`, `per_page`, `limit`, and `order` query parameters.

= Is the REST endpoint public? =

No. REST endpoints require a user with the `manage_options` capability or the plugin read-only API token.

== Changelog ==

= 3.3.0 =

* Added a compact WordPress dashboard widget for monitoring status.
* Shows active incidents and non-up URLs directly on the WordPress dashboard.
* Added a subtle alarm beacon for active incidents.

= 3.2.0 =

* Added filters and pagination to the REST log endpoint.
* Added read-only API token access for filtered log responses.
* Documented supported log query parameters in the REST API viewer and README.

= 3.1.1 =

* Fixed dashboard date/time display to use day-month-year and 24-hour time.
* Improved AJAX error handling so HTTP and SSL validation errors are shown clearly.
* Sorted active incidents and down URLs to the top of the dashboard list.

= 3.1.0 =

* Added per-URL status history, response time tracking, and uptime percentages.
* Added recovery notifications and throttled downtime alerts per incident.
* Added configurable retry attempts, request timeout, and down status code ranges.

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
