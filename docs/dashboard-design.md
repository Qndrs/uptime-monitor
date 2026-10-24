# Dashboard Design Notes

Werknotitie voor de dashboardfase van Qndrs Availability and Heartbeat Monitor.

## Proof of concept: optioneel donker thema en kolomkeuze

Op verzoek van Robert is op 24 oktober 2026 een [interactieve ontwerpstudie](prototypes/monitor-overview.html) toegevoegd, gebaseerd op twee screenshots van het huidige overzicht. De proef voegt een optioneel donker thema en afzonderlijk schakelbare kolommen toe; de bestaande lichte basis blijft beschikbaar. De onderstaande oorspronkelijke MVP-keuzes blijven de achtergrond van de huidige productie-interface.

Zie [de uitleg bij de proef](prototypes/README.md) voor openen, bediening en grenzen. Het prototype blijft een zelfstandig referentieontwerp. De themawissel en kolomkeuze zijn daarna op verzoek van Robert geïntegreerd in de plugin, inclusief de PHP- en AJAX-rendering.

### Geïntegreerde weergavevoorkeuren

- De standaard blijft licht, met alle kolommen zichtbaar. `Light`, `Dark` en `System` zijn onafhankelijk van de kolomkeuze instelbaar.
- `Columns` biedt laatste check/HTTP-status, responstijd/gemiddelde, uptime, meldingen, incidenten, type en URL/heartbeatinterval. Status, naam en beheeracties blijven zichtbaar. `Compact overview` laat status, naam, responstijd, uptime en beheeracties staan; `Restore defaults` herstelt de kolommen zonder het thema te wijzigen.
- Voorkeuren staan in `localStorage` onder `qndrsAhmView:v1:<blog-id>:<user-id>`. De browser-origin scheidt verschillende domeinen. Er wordt geen monitoringconfiguratie aangepast en er zijn geen extra servermutaties of dependencies nodig.
- Beschadigde of geblokkeerde opslag blokkeert de bediening niet. Zonder opslag gelden nieuwe keuzes alleen voor de huidige pagina. Systeemthema volgt wijzigingen in de OS-voorkeur zolang de pagina openstaat.
- Kolommen worden uit de gridindeling verwijderd. Alleen de lijst scrolt horizontaal bij weinig ruimte. Geopende details en de gekozen kolommen blijven behouden bij AJAX-verversing.
- Donkere stijlen zijn begrensd tot het overzicht en de bijbehorende WordPress-contentachtergrond. Instellingen, het menu en de dashboardwidget behouden hun bestaande vormgeving. De fallbackbestanden in de repositoryroot blijven gelijk aan de canonieke JS/CSS-assets.

### Verificatie van de integratie

`php scripts/check-overview.php` draait de bestaande betrouwbaarheidschecks plus controles op de menustructuur, de echte PHP-renderer, de afzonderlijke kolommen, escaping en de gebruikers-/sitesleutel. De scriptbootstrap gebruikt WordPress- en database-doubles; dit is geen volledige WordPress/MySQL-integratietest.

Voor handmatige browsercontrole genereert `php scripts/check-overview.php --fixture` onder de genegeerde map `dist/` een Nederlandse overzichtspagina met echte pluginassets, een AJAX-response en varianten voor lege monitors, beschadigde opslag, geblokkeerde opslag en de dashboardwidget. Lever voor deze ontwikkelfixture de bestaande WordPress-assets aan:

```powershell
$wordpressRoot = 'D:\pad\naar\wordpress'
Copy-Item "$wordpressRoot\wp-includes\js\jquery\jquery.min.js" dist/jquery.min.js
Copy-Item "$wordpressRoot\wp-admin\css\common.css" dist/wp-common.css
Copy-Item "$wordpressRoot\wp-admin\css\forms.css" dist/wp-forms.css
Copy-Item "$wordpressRoot\wp-includes\css\buttons.css" dist/wp-buttons.css
php -S 127.0.0.1:8766 -t .
```

Open `http://127.0.0.1:8766/dist/overview-check.html`. Controleer themawissel, kolomkeuze, compacte weergave, reset, opslag na herladen, geopende historie na verversen en de mobiele scrollcontainer. De ontwikkelfixture verandert geen live data en wordt niet verpakt in de releasezip. De lokale browsercontrole is uitgevoerd; de volledige WordPress-adminomgeving met echte database en authenticatie moet nog worden getest.

## Doel

Het dashboard moet voelen als een professioneel controlepaneel voor webservices, maar zich gedragen als een goede WordPress-adminpagina. De visuele verrassing zit in statuslampjes, compacte meters, badges en uptimebalken. De functionele kern blijft: snel zien welke URL problemen heeft, hoe ernstig het is, wanneer de laatste check was en welke actie nodig is.

## Uitgangspunt

De huidige beheerpagina bevat inmiddels veel nuttige monitoringdata, maar de informatiehierarchie is niet ideaal. De historykolom neemt veel verticale ruimte in en de belangrijkste vragen staan niet centraal genoeg:

- Is alles gezond?
- Welke URL vraagt aandacht?
- Hoe lang speelt een storing?
- Wat was de laatste status, HTTP-code en responstijd?
- Welke meldingen staan aan?
- Welke actie kan de beheerder nu nemen?

## Dashboard MVP

Voor de eerste dashboardversie bouwen we geen volledig nieuw incidentplatform. We verbeteren de bestaande Uptime Monitor-pagina met een compacte dashboardlaag en een betere URL-weergave.

### 1. Statusbar bovenaan

Toon direct bovenaan:

- Algemene status: `Operational`, `Degraded` of `Incident active`.
- Aantal URL's `Up`, `Down` en `Paused`.
- Aantal actieve incidenten.
- Laatste globale checktijd.
- Volgende geplande check als dit eenvoudig betrouwbaar beschikbaar is.

Visueel: lichte WordPress-adminbasis met een duidelijke linker statusrand en statuslamp. Geen donkere cockpitinterface.

### 2. Metric cards

Onder de statusbar komen compacte kaarten:

- Health percentage: aandeel gezonde actieve URL's.
- Average response: gemiddelde responstijd over actieve URL's.
- 24h uptime: gemiddeld uptimepercentage.
- Notifications: aantal URL's met e-mail en/of Pushover actief.

Deze kaarten moeten snel scanbaar zijn en mogen de hoofdinterface niet domineren.

### 3. URL status rows

De huidige brede tabel wordt voor het dashboard vervangen of aangevuld door statusrijen/cards.

Per URL tonen:

- Statuslamp en tekstlabel: `Up`, `Down`, `Paused` of eventueel `Degraded`.
- URL als primaire identiteit.
- Laatste checktijd.
- Laatste HTTP-statuscode of foutmelding.
- Laatste responstijd en gemiddelde responstijd.
- Uptimepercentages voor 24 uur, 7 dagen en 30 dagen.
- Notificatiekanalen: e-mail, Pushover of `No alerts`.
- Incidentstatus: `No incident` of `Incident open`.
- Acties: details/history tonen, pauzeren/hervatten, verwijderen.

History wordt standaard ingeklapt. De laatste checks blijven beschikbaar, maar ze mogen de primaire scan niet domineren.

### 4. Visuele stijl

Richting: licht industrial status panel.

Gebruik:

- Statuslampjes met tekstlabels.
- Subtiele linker statusranden op URL-rijen.
- Compacte badges voor HTTP-code, responstijd, notificaties en incidentstatus.
- Uptimebalkjes of percentagechips.
- Beperkte glow alleen bij echte storing, niet permanent overal.

Niet gebruiken:

- Donkere sci-fi interface.
- Grote decoratieve meters zonder directe betekenis.
- Permanente knipperanimaties.
- Kleur als enige informatiedrager.
- Verborgen kernstatus achter hover/tooltips.

## Later Mogelijk

Deze onderdelen zijn waardevol, maar horen niet in de eerste dashboard-MVP:

- Sorteerbaarheid op alle kolommen.
- Aparte incidentkolom of incidentdetailpagina.
- Acknowledge-flow voor incidenten.
- Echte uptime-segmentstrips op basis van time buckets.
- REST status-endpoints voor externe dashboards.
- Read-only API-token voor externe dashboards.
- Logfilters en paginering.

## Bezwaren En Grenzen

- De plugin draait in WordPress admin. De UI moet passen bij WordPress en beheerders niet vermoeien.
- De dashboarddata is nu check-based. Uptimebalken kunnen in de MVP percentages tonen, maar segmentstrips vragen later een explicieter time-bucket model.
- Incidenten hebben nu state voor meldingen, maar nog geen acknowledge of detailscherm. Toon dus geen acties die niet echt worden opgeslagen.
- Mobile moet eerst antwoord geven op: wat is stuk, hoe erg is het, en wat kan ik doen?

## Afgewezen Richting

Een te rijke mockup met grote statusbar, vier metric cards, brede datatabel, sparklines, response meters, uptime-segmenten, veel iconen en een aparte incidentkolom is bewust niet de gewenste richting voor de eerste versie.

Waarom niet:

- Te veel visuele prikkels voor een dashboard dat waarschijnlijk weinig wordt bekeken.
- Te veel tegelijk: dashboard, URL-beheer, incidentlijst, rapportage en details in een scherm.
- Sparklines en segmentbalken suggereren meer historische precisie dan het huidige datamodel biedt.
- De rechter incidentkolom concurreert met de URL-lijst terwijl incidentdetails nog geen eigen workflow hebben.
- Veel iconen en micrografieken maken de pagina minder rustig dan nodig voor WordPress admin.

Wat we wel meenemen uit die mockup:

- Een compacte algemene status bovenaan.
- Statuslampjes met tekstlabels.
- Enkele metric cards, maar minder dominant.
- URL-rijen met duidelijke status, responstijd en uptime.
- Visuele accenten alleen waar ze direct helpen bij scannen.

## Eerste Implementatiestap

Voor TODO-item `Bouw een compact statusdashboard`:

- Voeg een statusbar en metric cards toe bovenaan de Uptime Monitor-pagina.
- Maak de URL-lijst scanbaarder met status rows/cards.
- Verplaats de huidige history naar een inklapbaar detailblok.
- Houd URL toevoegen en beheeracties beschikbaar, maar minder dominant dan de statusinformatie.
- Stel REST/API-uitbreiding uit naar de volgende TODO-items.
