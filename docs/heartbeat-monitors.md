# Heartbeat Monitors

Heartbeat monitors are for machines, jobs, Home Assistant instances, NAS devices, Docker hosts or internal applications that are not reachable inbound, but can send outbound HTTPS requests to the monitor.

The monitor must be created manually in **Qndrs Monitor > Settings > Heartbeat Monitors**. Auto-registration is intentionally not part of the MVP.

## Basic request

Replace `TOKEN` with the one-time token shown when creating or rotating a heartbeat monitor.

```bash
curl -X POST "https://example.com/wp-json/qndrs-availability-heartbeat-monitor/v1/heartbeat" \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"up","message":"heartbeat ok"}'
```

`status` is optional and defaults to `up`. Supported values are `up`, `down` and `error`.

## Linux cron

```cron
*/5 * * * * curl -fsS -X POST "https://example.com/wp-json/qndrs-availability-heartbeat-monitor/v1/heartbeat" -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d '{"status":"up","message":"cron heartbeat"}' >/dev/null
```

## systemd timer

Use a small script such as `/usr/local/bin/uptime-heartbeat.sh`:

```bash
#!/usr/bin/env sh
curl -fsS -X POST "https://example.com/wp-json/qndrs-availability-heartbeat-monitor/v1/heartbeat" \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"up","message":"systemd heartbeat"}'
```

Schedule it with a normal systemd timer matching the expected interval configured in the monitor.

## Windows Task Scheduler

Use a `.bat` script and Windows Task Scheduler. This keeps the heartbeat running in the background without third-party tooling.

### Create the script

Create `C:\Scripts\uptime-heartbeat.bat` with this content:

```bat
@echo off
curl.exe -fsS -X POST "https://example.com/wp-json/qndrs-availability-heartbeat-monitor/v1/heartbeat" -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d "{\"status\":\"up\",\"message\":\"windows heartbeat\"}"
```

When saving from Notepad, choose **All files** as the file type so the file does not become `uptime-heartbeat.bat.txt`.

### Create the task

1. Open **Task Scheduler** from the Windows Start menu.
2. Choose **Create Basic Task...**.
3. Enter a name such as `Qndrs Monitor Heartbeat`.
4. Choose **Daily** as the trigger. The repetition interval is configured in the next step.
5. Choose **Start a program** as the action.
6. Browse to `C:\Scripts\uptime-heartbeat.bat`.
7. Finish the wizard.

### Run hidden and repeat

1. Open **Task Scheduler Library**.
2. Double-click the created task.
3. On **General**, select **Run only when user is logged on**.
4. Enable **Run with highest privileges**.
5. On **Triggers**, edit the daily trigger.
6. Enable **Repeat task every** and set the interval, for example `5 minutes`.
7. Set **for a duration of** to **Indefinitely**.
8. Save the task.

To test immediately, right-click the task and choose **Run**.

## Home Assistant

Home Assistant is a good fit when it is always on. No shell script is needed; use the built-in `rest_command` integration.

### Add the REST command

Open `configuration.yaml` with File Editor, VS Code or a Samba share and add:

```yaml
rest_command:
  qndrs_ahm_heartbeat:
    url: "https://example.com/wp-json/qndrs-availability-heartbeat-monitor/v1/heartbeat"
    method: POST
    headers:
      Authorization: "Bearer TOKEN"
      Content-Type: "application/json"
    payload: '{"status":"up","message":"home assistant heartbeat"}'
```

Make sure `rest_command:` starts at the left edge of the file. Replace `TOKEN` with the one-time heartbeat token from the monitor.

### Restart Home Assistant

New REST commands become available only after a full Home Assistant restart:

1. Go to **Settings > System**.
2. Use the power/restart button in the top right.
3. Choose **Restart Home Assistant**.
4. Wait until Home Assistant is online again.

### Add the automation

Create an automation that calls the REST command every five minutes:

1. Go to **Settings > Automations & scenes**.
2. Choose **Create automation**.
3. Choose **Create new automation**.
4. Open the three-dot menu and choose **Edit in YAML**.
5. Replace the generated YAML with:

```yaml
alias: "System: Uptime Heartbeat"
description: "Sends a heartbeat to the uptime monitor every 5 minutes"
trigger:
  - platform: time_pattern
    minutes: "/5"
condition: []
action:
  - action: rest_command.qndrs_ahm_heartbeat
mode: single
```

Save the automation.

### Test the command

To test manually:

1. Go to **Developer Tools > Actions**.
2. Search for `rest_command.qndrs_ahm_heartbeat`.
3. Run the action.

The `minutes: "/5"` trigger runs on every minute divisible by five, for example `:00`, `:05`, `:10` and so on.

## Stale behavior

A heartbeat monitor becomes stale/down when no valid ping arrives for roughly twice the configured expected interval. This means it monitors the client, its scheduler and outbound connectivity together.
