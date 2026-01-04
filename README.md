# Simple Uptime Monitor

![WordPress version](https://img.shields.io/badge/WordPress-6.9-blue)
![PHP version](https://img.shields.io/badge/PHP-8.0+-blue)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

## Plugin Metadata

- **Contributors:** Robert E. Kuunders  
- **Tags:** uptime, monitoring, notifications, pushover, email, cron, rest-api, json, wordpress-plugin, export, import, json-export  
- **Requires at least:** WordPress 6.0  
- **Tested up to:** WordPress 6.9  
- **Requires PHP:** 8.0+  
- **Stable tag:** 3.0.0  
- **License:** GPLv2 or later  
- **License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

---

## Description

**Simple Uptime Monitor** is a lightweight yet powerful plugin to monitor the uptime and availability of external websites. Get real-time alerts via email or Pushover when a site is down, directly from your WordPress dashboard. All status checks are stored as structured JSON logs for analysis or REST access.

### 🔧 Features

- 📡 Monitor multiple external websites from your WordPress dashboard.
- 🔔 Get alerts via email and/or Pushover when a URL is unreachable.
- 🛠 Temporarily disable monitoring per URL.
- 📤 Export and 📥 import configuration using JSON.
- 🔁 Adjust the monitoring interval via admin settings.
- 🗂 REST API endpoint to retrieve logs.
- 🌐 Supports multilingual usage.
- 🔒 Includes client plugin support for sites behind firewalls.
- 🧾 Logs are stored in JSON format for better interoperability.

### ✅ Use Cases

- Uptime monitoring for multiple client or business websites.
- Integration with external dashboards using JSON or REST.
- Receive immediate notifications during outages.
- Monitor internal firewalled services with client-side pings.

---

## Installation

1. Upload the plugin to `/wp-content/plugins/uptime-monitor` or install it via the WordPress plugin directory.
2. Activate the plugin via the **Plugins** menu in WordPress.
3. Go to **Uptime Monitor** in the admin menu to start adding URLs.
4. Configure notifications per URL (email or Pushover).
5. (Optional) Add this cron command on your server:  
   ```bash
   curl https://yourwebsite.com/wp-cron.php > /dev/null 2>&1
   ```  
   Also set in `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```
### REST API: Fetch logs

**Endpoint**:  
`GET /wp-json/uptime-monitor/v1/logs`

**Authentication**:  
Use Basic Auth with your WordPress username and an [Application Password](https://wordpress.org/support/article/application-passwords/)

**Response**:  
JSON array of log entries:
```json
[
   {
      "timestamp": "2026-01-04 13:53:44",
      "type": "info",
      "message": "Cron job started.",
      "data": {
         "task": "monitor_uptime_event"
      }
   }
]
```
---

## Frequently Asked Questions

### How do I add or remove URLs?

Go to the **Uptime Monitor** page in the WordPress admin and use the form to manage your list.

### How can I get alerts when a site is down?

Enable email and/or Pushover alerts per URL.

### How do I configure Pushover?

Add the following constants in `wp-config.php`:

```php
define('PUSHOVER_USER_KEY', 'your-user-key');
define('PUSHOVER_API_TOKEN', 'your-api-token');
```

### Where are logs stored?

In `wp-content/logs/uptime-monitor.json`.

### What is the endpoint for reading the logfile?

At: `/wp-json/uptime-monitor/v1/logs`.

### Can I export/import my configuration?

Yes! Use the settings page to copy and paste your entire configuration in JSON.

### Can this monitor sites behind firewalls?

Yes, with a lightweight client plugin installed on the target site, it can actively ping your monitor.

### Can I disable monitoring temporarily?

Yes. Use the checkbox per URL to toggle monitoring on or off.

---

## Screenshots

1. Dashboard to manage URLs and settings  
2. Settings panel with JSON export/import  
3. JSON log file example  
4. REST API output for external integrations  

---

## Changelog

### 3.0.0
- Updated language files (NL)
- Extended logging for REST access

### 2.9.0
- Added toggle per URL to disable/enable monitoring.
- Improved REST implementation and JSON logging.
- Added JSON import/export for full configuration.
- Refined multilingual support and translation strings.

### 2.8.0
- Introduced JSON import/export UI.
- REST endpoint for authenticated log retrieval.
- Initial support for client plugin architecture.

### 2.0.0
- Namespacing and code restructuring.
- Support for multi-language strings and error handling.

### 1.5.0
- Added Pushover notifications.
- Improved logging of events and errors.

### 1.0.0
- Initial release: monitor uptime and get email alerts.

---

## License

This plugin is open-source software licensed under [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

---

## Contributing

Feedback, bug reports or feature requests? Visit the [GitHub repository](https://github.com/qndrs/uptime-monitor).