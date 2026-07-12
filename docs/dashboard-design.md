# Dashboard Design Notes

Werknotitie voor de dashboardfase van Qndrs Availability and Heartbeat Monitor.

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
