# TODO

Werkdocument voor verbeteringen aan Simple Uptime Monitor. De volgorde hieronder is bedoeld als pragmatische roadmap: eerst stabiliteit en veiligheid, daarna productfeatures.

## Huidige status

- Pauzepunt: 2026-07-05.
- Release `v3.0.1` is afgerond, getagd en gepusht.
- Tag `v3.0.1` wijst naar releasecode commit `b6fdc03`.
- Laatste main-commit bij pauze: `175c475` (`Mark 3.0.1 release complete`).
- Plugin Check is opnieuw gedraaid en gaf geen fouten.
- Testsite `https://qndrs.training/simpleuptimemonitor/` draaide gezond na deployment.
- Deploymentroute op dit moment: SFTP naar `/home/qndrs/public_html/simpleuptimemonitor/wp-content/plugins/uptime-monitor`.
- Huidige SSH-account heeft geen shell; server-side Git deploy is daarmee nog niet mogelijk.
- Releasepakket is lokaal reproduceerbaar via `scripts/build-release.ps1`.
- Lokale releasechecks draaien via `scripts/check-release.ps1`.
- Volgende inhoudelijke fase: nadenken over features, waarschijnlijk starten bij monitoringhistorie, response time, uptimepercentage, alert throttling en Pushover-configuratie.

## 1. Stabilisatie

- [x] Fix WP-Cron scheduling bij activatie.
  - Probleem: activatie gebruikt nu een recurrence die niet overeenkomt met de geregistreerde custom schedule.
  - Klaar wanneer: activatie direct een geldige `monitor_uptime_event` plant met de ingestelde interval.

- [x] Normaliseer het URL data-model.
  - Probleem: sommige routes/imports kunnen URL records zonder `id` of `enabled` opleveren.
  - Klaar wanneer: alle opgeslagen URL records altijd `id`, `url`, `email`, `pushover` en `enabled` hebben.

- [x] Voeg een migratiepad toe voor bestaande installaties.
  - Probleem: oudere pluginversies hebben records zonder nieuwe velden.
  - Klaar wanneer: bestaande opties bij upgrade automatisch worden aangevuld zonder data te verliezen.

- [x] Voeg capability checks toe aan alle AJAX-acties.
  - Probleem: AJAX checkt nu wel nonces, maar niet overal expliciet `current_user_can('manage_options')`.
  - Klaar wanneer: toevoegen, verwijderen en togglen alleen kan door beheerders.

- [x] Maak de admin-tabel consistent na AJAX updates.
  - Probleem: JavaScript rendert na update minder kolommen dan de PHP-render en mist de monitoring-toggle.
  - Klaar wanneer: de tabel na toevoegen/verwijderen/togglen dezelfde kolommen en controls behoudt.

- [x] Verhard URL-validatie en sanitization.
  - Probleem: `sanitize_text_field()` is niet genoeg voor URL-invoer.
  - Klaar wanneer: alleen geldige `http`/`https` URLs worden opgeslagen en output overal correct escaped is.

- [x] Maak importvalidatie strenger.
  - Probleem: JSON-import accepteert nog te veel vormvarianten.
  - Klaar wanneer: import schema-validatie doet, ongeldige records overslaat of blokkeert, en duidelijke feedback geeft.

- [x] Rond logging robuust af.
  - Probleem: grote JSON-logbestanden kunnen performanceproblemen veroorzaken.
  - Klaar wanneer: logrotatie, max entries, max bestandsgrootte en foutafhandeling getest zijn.

## 2. Releasekwaliteit

- [x] Maak een 3.0.1 stabilisatie-release.
  - Inhoud: cron-fix, security checks, datamigratie, UI-consistentie en loggingfixes.
  - Klaar wanneer: changelog, plugin header en Git tag `v3.0.1` kloppen.

- [x] Voeg basistests toe.
  - Focus: data-normalisatie, importvalidatie, loglimieten en REST-permissies.
  - Klaar wanneer: tests lokaal reproduceerbaar draaien.

- [x] Draai WordPress Plugin Check en los blokkerende meldingen op.
  - Focus: security, escaping, internationalization en plugin metadata.
  - Status: cleanup toegepast voor escaping, input handling, i18n, readme.txt en loggingopslag; hercontrole zonder fouten afgerond.
  - Klaar wanneer: geen kritieke meldingen meer openstaan.

- [x] Herstel README encoding en inhoud.
  - Probleem: emoji/UTF-8 tekst is beschadigd.
  - Klaar wanneer: README leesbaar is en alleen features claimt die echt bestaan.

- [x] Automatiseer het releasepakket.
  - Probleem: `uptime-monitor.zip` kan verouderen.
  - Klaar wanneer: zip-build reproduceerbaar is en `.git`, `.idea` en tijdelijke bestanden uitsluit.

## 3. Monitoring Features

- [x] Toon statusgeschiedenis per URL.
  - Mogelijk: laatste checks, statuscodes, foutmeldingen en timestamps.
  - Status: compacte per-URL geschiedenis toegevoegd aan de admin-tabel, inclusief statusbadge, HTTP-code, timestamp en foutmelding.

- [x] Meet response time.
  - Mogelijk: gemiddelde responstijd, laatste responstijd en eenvoudige trend.
  - Status: cron-checks meten responstijd in milliseconden; de history toont per check de responstijd, plus gemiddelde en trend.

- [ ] Bereken uptimepercentage.
  - Mogelijk: per URL over 24 uur, 7 dagen en 30 dagen.

- [ ] Voeg herstelmeldingen toe.
  - Mogelijk: stuur ook bericht wanneer een down URL weer up is.

- [ ] Werk Pushover-configuratie uit.
  - Huidig: `PUSHOVER_USER_KEY` en `PUSHOVER_API_TOKEN` moeten in `wp-config.php` staan.
  - Voorstel: sla credentials op als WordPress options in de database via de settingspagina, met gemaskeerde invoervelden en een testknop.
  - Veiligheid: exporteer secrets niet in de gewone JSON-configuratie, log ze nooit, en bescherm opslag met nonce/capability checks.
  - Compatibiliteit: blijf `wp-config.php` constants ondersteunen als fallback of expliciete override voor bestaande installaties.
  - Klaar wanneer: een beheerder Pushover kan configureren zonder bestandstoegang en bestaande configuraties blijven werken.

- [ ] Voeg alert throttling toe.
  - Mogelijk: voorkom herhaalde meldingen bij elke cron-run zolang dezelfde storing voortduurt.

- [ ] Maak retry-gedrag configureerbaar.
  - Mogelijk: aantal pogingen, timeout en welke statuscodes als down tellen.

## 4. Dashboard En REST

- [ ] Bouw een compact statusdashboard.
  - Mogelijk: groen/rood/oranje status, laatst gecontroleerd, downtime-duur en snelle acties.

- [ ] Breid de REST API uit met status-endpoints.
  - Mogelijk: `GET /status`, `GET /status/{id}` en gefilterde logresponses.

- [ ] Onderzoek API-token toegang voor externe dashboards.
  - Mogelijk: read-only token naast WordPress admin authenticatie.

- [ ] Voeg logfilters en paginering toe.
  - Mogelijk: filter op URL, type, datumrange en limiet.

## 5. Client Plugin / Firewalled Sites

- [ ] Werk de client-plugin architectuur uit.
  - Probleem: README claimt support voor sites achter firewalls, maar de concrete heartbeat-flow ontbreekt nog.

- [ ] Maak een heartbeat endpoint.
  - Mogelijk: client sites melden periodiek hun status aan de monitor.

- [ ] Voeg tokenbeveiliging toe voor client pings.
  - Mogelijk: per site een geheim token, rotatebaar vanuit admin.

- [ ] Documenteer installatie van de client plugin.
  - Klaar wanneer: README of aparte docs de setup stap voor stap uitlegt.

## 6. Deployment En Samenwerking

- [ ] Leg testsite-deployment vast.
  - Huidige route: SFTP naar `wp-content/plugins/uptime-monitor` op de testsite.
  - Klaar wanneer: stappen, backup-locatie en verificatiechecks beschreven zijn.

- [ ] Voeg een korte contributor workflow toe.
  - Mogelijk: branchnamen, reviewafspraken, testcommando's en releaseproces.

- [ ] Beslis over server-side Git of SFTP als lange termijn route.
  - Context: huidige SSH-account heeft geen shell, SFTP werkt wel.
  - Klaar wanneer: team weet welke deploymentroute standaard is.
