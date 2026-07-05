# TODO

Werkdocument voor verbeteringen aan Simple Uptime Monitor. De volgorde hieronder is bedoeld als pragmatische roadmap: eerst stabiliteit en veiligheid, daarna productfeatures.

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

- [ ] Maak een 3.0.1 stabilisatie-release.
  - Inhoud: cron-fix, security checks, datamigratie, UI-consistentie en loggingfixes.
  - Klaar wanneer: changelog, plugin header en Git tag `v3.0.1` kloppen.

- [ ] Voeg basistests toe.
  - Focus: data-normalisatie, importvalidatie, loglimieten en REST-permissies.
  - Klaar wanneer: tests lokaal reproduceerbaar draaien.

- [ ] Draai WordPress Plugin Check en los blokkerende meldingen op.
  - Focus: security, escaping, internationalization en plugin metadata.
  - Klaar wanneer: geen kritieke meldingen meer openstaan.

- [ ] Herstel README encoding en inhoud.
  - Probleem: emoji/UTF-8 tekst is beschadigd.
  - Klaar wanneer: README leesbaar is en alleen features claimt die echt bestaan.

- [ ] Automatiseer het releasepakket.
  - Probleem: `uptime-monitor.zip` kan verouderen.
  - Klaar wanneer: zip-build reproduceerbaar is en `.git`, `.idea` en tijdelijke bestanden uitsluit.

## 3. Monitoring Features

- [ ] Toon statusgeschiedenis per URL.
  - Mogelijk: laatste checks, statuscodes, foutmeldingen en timestamps.

- [ ] Meet response time.
  - Mogelijk: gemiddelde responstijd, laatste responstijd en eenvoudige trend.

- [ ] Bereken uptimepercentage.
  - Mogelijk: per URL over 24 uur, 7 dagen en 30 dagen.

- [ ] Voeg herstelmeldingen toe.
  - Mogelijk: stuur ook bericht wanneer een down URL weer up is.

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
