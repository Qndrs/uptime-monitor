# Heartbeat monitors

Heartbeat monitors zijn bedoeld voor computers, jobs, Home Assistant-installaties, NAS-apparaten, Docker-hosts en interne applicaties die niet van buitenaf bereikbaar zijn, maar wel zelf uitgaand HTTPS-verkeer naar de monitor kunnen sturen.

De monitor wordt handmatig aangemaakt via **Uptime Monitor > Instellingen > Heartbeat monitors**. Auto-registratie is bewust geen onderdeel van deze eerste versie.

## Basisverzoek

Vervang `TOKEN` door het eenmalige token dat wordt getoond wanneer je een heartbeat monitor aanmaakt of het token vernieuwt.

```bash
curl -X POST "https://example.com/wp-json/uptime-monitor/v1/heartbeat" \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"up","message":"heartbeat ok"}'
```

`status` is optioneel en is standaard `up`. Ondersteunde waarden zijn `up`, `down` en `error`.

## Linux cron

```cron
*/5 * * * * curl -fsS -X POST "https://example.com/wp-json/uptime-monitor/v1/heartbeat" -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d '{"status":"up","message":"cron heartbeat"}' >/dev/null
```

## systemd timer

Gebruik een klein script zoals `/usr/local/bin/uptime-heartbeat.sh`:

```bash
#!/usr/bin/env sh
curl -fsS -X POST "https://example.com/wp-json/uptime-monitor/v1/heartbeat" \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"up","message":"systemd heartbeat"}'
```

Plan dit script met een normale systemd timer die past bij het verwachte interval van de monitor.

## Windows Taakplanner

Gebruik een `.bat` script en Windows Taakplanner. Hiermee draait de heartbeat op de achtergrond zonder extra software.

### Script maken

Maak `C:\Scripts\uptime-heartbeat.bat` met deze inhoud:

```bat
@echo off
curl.exe -fsS -X POST "https://example.com/wp-json/uptime-monitor/v1/heartbeat" -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d "{\"status\":\"up\",\"message\":\"windows heartbeat\"}"
```

Kies bij opslaan vanuit Kladblok voor **Alle bestanden** als bestandstype, zodat het bestand niet eindigt als `uptime-heartbeat.bat.txt`.

### Taak aanmaken

1. Open **Taakplanner** via het Windows-startmenu.
2. Kies **Basistaak maken...**.
3. Vul een naam in, bijvoorbeeld `Uptime Monitor Heartbeat`.
4. Kies **Dagelijks** als trigger. De herhaling stel je in de volgende stap in.
5. Kies **Een programma starten** als actie.
6. Blader naar `C:\Scripts\uptime-heartbeat.bat`.
7. Rond de wizard af.

### Onzichtbaar en herhaald uitvoeren

1. Open **Bibliotheek voor Taakplanner**.
2. Dubbelklik op de aangemaakte taak.
3. Kies op **Algemeen** voor **Alleen uitvoeren als de gebruiker is ingelogd**.
4. Zet **Met maximale bevoegdheden uitvoeren** aan.
5. Bewerk op **Triggers** de dagelijkse trigger.
6. Zet **Taak herhalen elke** aan en kies bijvoorbeeld `5 minuten`.
7. Zet **gedurende** op **Onbepaalde tijd**.
8. Sla de taak op.

Test direct door met rechts op de taak te klikken en **Uitvoeren** te kiezen.

## Home Assistant

Home Assistant is geschikt wanneer deze altijd aan staat. Je hebt geen shellscript nodig; gebruik de ingebouwde `rest_command` integratie.

### REST-command toevoegen

Open `configuration.yaml` met File Editor, VS Code of een Samba-share en voeg toe:

```yaml
rest_command:
  uptime_monitor_heartbeat:
    url: "https://example.com/wp-json/uptime-monitor/v1/heartbeat"
    method: POST
    headers:
      Authorization: "Bearer TOKEN"
      Content-Type: "application/json"
    payload: '{"status":"up","message":"home assistant heartbeat"}'
```

Zorg dat `rest_command:` helemaal links begint. Vervang `TOKEN` door het eenmalige heartbeat-token uit de monitor.

### Home Assistant herstarten

Nieuwe REST-commando's worden pas actief na een volledige herstart:

1. Ga naar **Instellingen > Systeem**.
2. Gebruik de aan/uit- of herstartknop rechtsboven.
3. Kies **Home Assistant herstarten**.
4. Wacht tot Home Assistant weer online is.

### Automatisering toevoegen

Maak een automatisering die het REST-command elke vijf minuten uitvoert:

1. Ga naar **Instellingen > Automatiseringen & scenes**.
2. Kies **Automatisering maken**.
3. Kies **Maak een nieuwe automatisering**.
4. Open het menu met drie puntjes en kies **Bewerken in YAML**.
5. Vervang de gegenereerde YAML door:

```yaml
alias: "Systeem: Uptime Heartbeat"
description: "Stuurt elke 5 minuten een heartbeat naar de uptime monitor"
trigger:
  - platform: time_pattern
    minutes: "/5"
condition: []
action:
  - action: rest_command.uptime_monitor_heartbeat
mode: single
```

Sla de automatisering op.

### Handmatig testen

1. Ga naar **Ontwikkelaarstools > Acties**.
2. Zoek naar `rest_command.uptime_monitor_heartbeat`.
3. Voer de actie uit.

De trigger `minutes: "/5"` draait op elke minuut die deelbaar is door vijf, bijvoorbeeld `:00`, `:05` en `:10`.

## Stale/down gedrag

Een heartbeat monitor wordt stale/down wanneer er ongeveer twee keer het ingestelde verwachte interval geen geldige ping binnenkomt. Daarmee bewaakt de monitor tegelijk de client, de lokale scheduler en de uitgaande verbinding.
