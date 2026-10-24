# Monitoroverzicht: dark mode en kolommen

Open [monitor-overview.html](monitor-overview.html) in een browser. Dit zelfstandige, interactieve proof of concept gebruikt voorbeeldgegevens uit Roberts twee screenshots van 24 oktober 2026. Er zijn geen dependencies, externe fonts of netwerkverzoeken naar de monitoringinstallatie.

Om de proef via localhost te openen, voer vanuit de repositoryroot uit:

```powershell
php -S 127.0.0.1:8765 -t docs/prototypes
```

Open vervolgens `http://127.0.0.1:8765/monitor-overview.html`.

## Proberen

- **Licht / Donker / Systeem:** wissel thema onafhankelijk van de kolommen. Systeem volgt de kleurvoorkeur van het besturingssysteem. De proef begint standaard donker.
- **Kolommen:** schakel laatste check, responstijd, uptime, meldingen en incidenten afzonderlijk in of uit. Type en URL/heartbeatinterval kunnen ook onder de naam worden verborgen.
- **Compact overzicht:** toon status, naam, responstijd en uptime, met toegang tot details.
- **Herstel standaard:** toon alle kolommen en aanvullende identiteitsinformatie; het thema blijft behouden.
- **Details:** open/sluit monitorinformatie. GayToys bevat ook de vijf recente controles uit het tweede screenshot.

Status, monitornaam en de detailknop blijven zichtbaar. Verborgen kolommen worden uit de gridindeling verwijderd. Bij weinig schermruimte scrolt alleen de monitorlijst horizontaal. Het WordPress-menu behoudt zijn eigen kleuren.

Thema en kolomvoorkeuren worden samen in `localStorage` opgeslagen onder `qndrs-monitor-overview-poc-v1`. Als opslag niet beschikbaar is, blijft de proef bruikbaar gedurende de sessie. Voorkeuren op een `file:`-URL kunnen per browser anders worden behandeld; localhost geeft een vaste origin.

## Grenzen en vervolg

De proef wijzigt geen pluginfunctionaliteit, monitoringconfiguratie of live data en wordt niet meegenomen in de releasezip. De productieknoppen voor toevoegen, verwijderen en pauzeren zijn bewust buiten deze ontwerpstudie gelaten. De cijfers zijn momentopnamen, geen actuele meetwaarden.

De bediening is inmiddels geïntegreerd in de bestaande PHP- en AJAX-rendering. De plugin begint standaard licht en slaat voorkeuren op per browser, site en WordPress-gebruiker. Alle teksten zijn vertaalbaar; donkere stijlen blijven beperkt tot het monitoroverzicht. Kolomkeuze en geopende details blijven behouden bij automatisch verversen. Zie [de integratienotities](../dashboard-design.md) voor de ontwikkelfixture en controles. Dit HTML-bestand blijft het oorspronkelijke zelfstandige referentieontwerp.
