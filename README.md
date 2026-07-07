# Simple Uptime Monitor

![WordPress version](https://img.shields.io/badge/WordPress-7.0-blue)
![PHP version](https://img.shields.io/badge/PHP-8.0+-blue)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

Simple Uptime Monitor is a lightweight WordPress plugin for monitoring external websites from the WordPress dashboard. It can check multiple URLs, send downtime alerts by email or Pushover, store JSON logs, and expose logs through an authenticated REST endpoint.

## Plugin Metadata

- Contributors: Robert E. Kuunders
- Tags: uptime, monitoring, notifications, pushover, cron
- Requires at least: WordPress 6.0
- Tested up to: WordPress 7.0
- Requires PHP: 8.0+
- Stable tag: 3.3.0
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

## Features

- Monitor multiple external websites from the WordPress dashboard.
- Enable or disable monitoring per URL.
- Send downtime and recovery alerts by email and/or Pushover.
- Show per-URL status history, uptime percentages, and response times.
- Send recovery alerts when an incident is resolved.
- Throttle repeated downtime alerts during the same incident.
- Configure the monitoring interval, retry attempts, timeout, down status codes, and Pushover credentials from the settings page.
- Export and import plugin configuration as JSON.
- Store structured logs in the WordPress database with an entry limit.
- Read logs through an authenticated REST endpoint.
- Dutch translation files are included.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/uptime-monitor`.
2. Activate **Simple Uptime Monitor** from the WordPress plugins page.
3. Open **Uptime Monitor** in the WordPress admin menu.
4. Add one or more URLs and choose the notification channels.
5. Optional: use a real server cron for more reliable checks:

```bash
curl https://yourwebsite.com/wp-cron.php > /dev/null 2>&1
```

If you use a real server cron, also add this to `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

## Pushover

Pushover credentials can be stored from the plugin settings page. Stored credentials are masked in the admin UI and are not included in JSON configuration exports.

You can also define credentials in `wp-config.php`. Constants take precedence over stored settings:

```php
define('PUSHOVER_USER_KEY', 'your-user-key');
define('PUSHOVER_API_TOKEN', 'your-api-token');
```

## REST API

Endpoint:

```http
GET /wp-json/uptime-monitor/v1/logs
```

Query parameters:

- `type`: filter by log type, for example `info` or `error`.
- `url`: filter logs whose `data.url` contains the given URL or domain.
- `date_from`: include logs from this UTC date or datetime, for example `2026-07-07`.
- `date_to`: include logs up to this UTC date or datetime.
- `page`: page number, default `1`.
- `per_page`: entries per page, default `100`, maximum `100`.
- `limit`: shortcut for the first page with up to `1000` entries.
- `order`: `desc` for newest first, or `asc` for oldest first. Default: `desc`.

Authentication:

Use a WordPress user with `manage_options`, for example with a WordPress Application Password, or use the plugin read-only API token as a Bearer token.

Example response:

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

Pagination metadata is returned in the `X-WP-Total`, `X-WP-TotalPages`, `X-Uptime-Monitor-Page`, and `X-Uptime-Monitor-Per-Page` headers. Logs are capped to prevent heavy responses from taking down the WordPress request.

## Release Build

Create a clean plugin zip from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1
```

The build script creates `dist/uptime-monitor-<version>.zip` and excludes `.git`, `.idea`, temporary files, and previous build artifacts.

## Development Checks

Run the local release checks from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

The checks validate PHP syntax, JavaScript syntax when Node.js is available, version consistency, and release packaging basics.

## Changelog

### 3.3.0

- Added a compact WordPress dashboard widget for monitoring status.
- Shows active incidents and non-up URLs directly on the WordPress dashboard.
- Added a subtle alarm beacon for active incidents.

### 3.2.0

- Added filters and pagination to the REST log endpoint.
- Added read-only API token access for filtered log responses.
- Documented supported log query parameters in the REST API viewer and README.

### 3.1.1

- Fixed dashboard date/time display to use day-month-year and 24-hour time.
- Improved AJAX error handling so HTTP and SSL validation errors are shown clearly.
- Sorted active incidents and down URLs to the top of the dashboard list.

### 3.1.0

- Added per-URL status history with recent checks, status codes, timestamps, and error messages.
- Added response time tracking with average response time and simple trend display.
- Added uptime percentages for 24 hours, 7 days, and 30 days.
- Added recovery notifications when a down URL becomes reachable again.
- Added alert throttling with a maximum of three downtime alerts per incident.
- Added configurable retry attempts, request timeout, and down status code ranges.

### 3.0.1

- Fixed cron scheduling on activation and settings changes.
- Added data normalization for existing and imported URL records.
- Added explicit capability checks for AJAX actions.
- Improved URL and import validation.
- Fixed admin table rendering after AJAX updates.
- Hardened JSON log handling and REST log responses for large log files.
- Cleaned README encoding and release documentation.

### 3.0.0

- Updated Dutch language files.
- Extended logging for REST access.

### 2.9.0

- Added toggle per URL to disable or enable monitoring.
- Improved REST implementation and JSON logging.
- Added JSON import and export for full configuration.
- Refined multilingual support and translation strings.

### 2.8.0

- Introduced JSON import and export UI.
- Added REST endpoint for authenticated log retrieval.
- Started planning for client-side monitoring support.

### 2.0.0

- Added namespacing and code restructuring.
- Added multilingual strings and error handling improvements.

### 1.5.0

- Added Pushover notifications.
- Improved event and error logging.

### 1.0.0

- Initial release with URL monitoring and email alerts.

## Roadmap

See [TODO.md](TODO.md) for the current stabilization, release-quality, and feature roadmap.

## License

This plugin is open-source software licensed under [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
