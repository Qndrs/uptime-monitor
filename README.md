# Qndrs Availability and Heartbeat Monitor

![WordPress version](https://img.shields.io/badge/WordPress-7.0-blue)
![PHP version](https://img.shields.io/badge/PHP-8.0+-blue)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

Qndrs Availability and Heartbeat Monitor is a lightweight WordPress plugin for monitoring websites and heartbeat clients from the WordPress dashboard. It can check multiple URLs, track firewalled clients through outbound heartbeat pings, send downtime alerts by email or Pushover, store JSON logs, and expose authenticated REST endpoints.

## Plugin Metadata

- Contributors: Robert E. Kuunders
- Tags: uptime, monitoring, notifications, pushover, cron
- Requires at least: WordPress 6.0
- Tested up to: WordPress 7.0
- Requires PHP: 8.0+
- Stable tag: 3.6.0
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
- Monitor firewalled machines or internal apps with outbound heartbeat pings.
- Dutch translation files are included.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/qndrs-availability-heartbeat-monitor`.
2. Activate **Qndrs Availability and Heartbeat Monitor** from the WordPress plugins page.
3. Open **Qndrs Monitor** in the WordPress admin menu.
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

### External service disclosure

Pushover is optional and disabled by default. The plugin only contacts Pushover when an administrator configures Pushover credentials and enables Pushover alerts for a monitor.

When enabled, the plugin sends an HTTPS POST request to `https://api.pushover.net/1/messages.json`. The request contains the configured Pushover application token, the configured Pushover user or group key, and a notification title/message with the monitored URL or monitor name, status information, and HTTP status code or error message when available.

- Pushover API documentation: https://pushover.net/api
- Pushover terms: https://pushover.net/terms
- Pushover privacy policy: https://pushover.net/privacy

## REST API

Endpoint:

```http
GET /wp-json/qndrs-availability-heartbeat-monitor/v1/logs
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

## Heartbeat Monitors

Heartbeat monitors are for machines, jobs, Home Assistant instances, NAS devices, Docker hosts or internal applications that are not reachable inbound, but can send outbound HTTPS requests to the monitor.

Create them manually in **Qndrs Monitor > Settings > Heartbeat Monitors**. The plugin shows a one-time token and a copyable `curl` example. See `docs/heartbeat-monitors.md` for cron, systemd, Windows Task Scheduler and Home Assistant examples. Dutch documentation is available in `docs/heartbeat-monitors.nl.md`.

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
      "task": "qndrs_ahm_monitor_event"
    }
  }
]
```

Pagination metadata is returned in the `X-WP-Total`, `X-WP-TotalPages`, `X-Qndrs-Ahm-Page`, and `X-Qndrs-Ahm-Per-Page` headers. Logs are capped to prevent heavy responses from taking down the WordPress request.

## History Storage

Version 3.6.0 stores status history in an indexed custom database table. Fresh installations use the table directly. Existing installations with legacy option-based history can use the controlled WP-CLI migration documented in `docs/history-storage.md`, including shadow-mode verification and rollback before finalization.

## Release Build

Create a clean plugin zip from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1
```

The build script creates `dist/qndrs-availability-heartbeat-monitor-<version>.zip` and excludes `.git`, `.idea`, temporary files, and previous build artifacts.

## Development Checks

Run the local release checks from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

The checks validate PHP syntax, JavaScript syntax when Node.js is available, version consistency, and release packaging basics.

## Changelog

### 3.6.0

- Moved status history to an indexed custom database table to prevent the complete history from being loaded into PHP memory.
- Added a controlled WP-CLI migration with shadow mode, count verification, finalization and rollback support.
- Added indexed aggregate queries for uptime and response metrics plus hourly 30-day retention cleanup.

### 3.5.3

- Improved monitor dashboard loading performance for installations with large status histories.
- Reused history, timezone and aggregate calculations during a request and limited rendered history to the five most recent checks.

### 3.5.2

- Fixed the WordPress dashboard widget button link after the WordPress.org slug rename.

### 3.5.1

- Renamed the plugin for WordPress.org review with a distinctive Qndrs display name and slug.
- Prefixed AJAX actions, REST namespace, cron hooks, options and token headers to avoid naming collisions.
- Improved the release zip structure for WordPress plugin uploads.

### 3.5.0

- Added heartbeat monitors for firewalled machines, jobs, Home Assistant, NAS devices and internal apps.
- Added a token-protected heartbeat REST endpoint with manual token rotation.
- Added heartbeat monitor management to the settings page with copyable endpoint and curl examples.
- Added documentation for cron, systemd timers, Windows Task Scheduler and Home Assistant heartbeat clients.

### 3.4.0

- Added live dashboard refresh without full page reload.
- Added optional auto-refresh for the Qndrs Monitor admin dashboard.
- Added automatic WordPress dashboard widget refresh with a visual countdown indicator.

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

Public release notes are tracked in the changelog. Development notes and operational deployment notes are kept outside the public plugin repository.

## License

This plugin is open-source software licensed under [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
