![WordPress version](https://img.shields.io/badge/WordPress-6.7-blue)
![PHP version](https://img.shields.io/badge/PHP-8.0+-blue)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)
=== Simple Uptime Monitor ===
Contributors: Robert E. Kuunders
Tags: uptime, monitoring, notifications, pushover, email, cron, rest-api, json, wordpress-plugin, export, import, json-export
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0+
Stable tag: 2.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor the uptime of your websites and receive notifications via email or Pushover.

== Description ==
**Simple Uptime Monitor** tracks the availability of websites using WordPress cron. It can notify administrators via email and/or Pushover when a site is down. Sites can be managed through an admin interface, and status logs are stored as JSON files for external analysis or REST access.

Now includes:
- Pushover & email alerting
- Multi-language support
- JSON logging
- REST endpoint for logs (authenticated)
- Customizable cron interval
- Admin UI for adding/removing sites
- Export & import of settings and monitored URLs
- Option to temporarily disable monitoring per URL
- Client integration for behind-firewall sites

**Features**
- Monitor multiple sites from a single dashboard.
- Notify via email or Pushover.
- JSON log format for external integrations.
- REST API for accessing logs externally.
- Adjustable cron intervals per WordPress settings.
- Multi-language ready.
- Export/import settings via JSON.
- Toggle monitoring on/off per URL.

**Use Cases:**
- Monitor the availability of your websites.
- Get instant alerts when a website is down.
- View status history and uptime performance in the admin dashboard.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/uptime-monitor` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to "Uptime Monitor" in the WordPress admin menu to configure URLs and notification preferences.
4. Set cron to run every minute (curl https://website.com/wp-cron.php > /dev/null 2>&1) if needed. Turn of pseudo cron in wp-config.php (define('DISABLE_WP_CRON', true);)

== Frequently Asked Questions ==

= How can I add or remove URLs? =
You can add or remove URLs directly from the admin interface under the "Uptime Monitor" section.

= What kind of notifications are supported? =
Currently, the plugin supports email notifications and Pushover notifications. You can enable or disable these for each URL individually.

= Can I customize the check interval? =
Yes, you can configure the monitoring interval via the settings page. By default, the interval is set to 2 minutes.

= Where are the logs stored? =
Logs are stored in the `wp-content/logs/uptime-monitor.log` file.

= How do I set up Pushover notifications? =
Add the following to your `wp-config.php` file:
```php
define('PUSHOVER_USER_KEY', 'your-pushover-user-key');
define('PUSHOVER_API_TOKEN', 'your-pushover-api-token');
```

= Can I export or import my monitored URLs and settings? =
Yes! You can copy/paste JSON data in the settings panel to export or import your configuration.

= Can I disable monitoring for specific URLs temporarily? =
Yes, there is a toggle per URL that allows you to disable monitoring without deleting the URL.

= Can this plugin monitor websites behind firewalls? =
You can optionally install a lightweight client plugin on the monitored site that pings this monitor proactively.

== Screenshots ==

Admin Dashboard: Add and manage URLs with options for email and Pushover notifications.
Uptime Logs: View detailed logs of uptime and downtime events.
== Changelog ==

= 2.8.0 =
- Added JSON import/export functionality via settings panel.
- Added option to disable monitoring per URL.
- Added REST API endpoint for external access to logs.
- Introduced client plugin architecture for behind-firewall monitoring (preparation).
- Improved logging format to JSON.
- Various code refactors and improved plugin structure.

= 2.0.0 =

Added namespace for improved organization.
Enhanced AJAX functionality for better user experience.
Added support for translations.
= 1.5.0 =

Introduced Pushover notifications.
Added detailed logging of events.
= 1.0.0 =

Initial release: Monitor URLs and receive email notifications.
== Upgrade Notice ==

= 2.0.0 = Namespace support added. Ensure your Pushover credentials are correctly set in wp-config.php.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

This plugin is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

== Notes ==

For feature requests, bug reports, or contributions, please visit the [GitHub repository](https://github.com/qndrs/uptime-monitor).
