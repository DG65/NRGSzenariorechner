# Szenariorechner — Konzept

„Was wäre wenn?"-Wirtschaftlichkeitsrechner für den NRG-Stack. Rechnet auf Basis
historischer eigener Verbrauchs-/Erzeugungsdaten (Archive Control) und externer
Marktdaten nach, was verschiedene Entscheidungen gebracht hätten bzw. bringen würden.
Reiner Rechner, kein Regler — analog zur Rollenteilung von SteuerboxHub: erfasst/rechnet,
setzt nichts durch.

Modul-Präfix: `SZR`. Repo: `github.com/DG65/NRGSzenariorechner`.

## Datenquellen im Verbund (bereits vorhanden, alle hinter `function_exists()`)

| Quelle | Vertrag | Nutzung hier |
|---|---|---|
| Archive Control (IPS-Kern) | `AC_GetAggregatedValues` | historischer Lastgang/Erzeugung an InverterHub-/MeterHub-Instanzen |
| TibberGridRewards | `TIBBERGR_GetPriceCurve`, `TIBBERGR_GetTariffConfig` | dynamische Vergleichspreiskurve, Tarifkomponenten |
| Prognose-Suite | `PVF_GetForecast`, `LFC_GetForecast` | Vorwärtssimulation (Speichergröße, Förderende) |
| StromGedacht | `SGW_GetState`, `SGW_GetForecast` | weiches Signal bei §14a-nahen Szenarien |
| SteuerboxHub | `SBH_GetState` | §14a-/Solarspitzen-Steuerbox-Szenarien, sobald Hardware existiert |
| Netztransparenz.de (extern, neu) | REST/OAuth2 | Marktwert Solar, negative Spotpreis-Stunden, Redispatch — siehe unten |

Künftig (noch nicht verfügbar): `EMS_GetSpecialEvents` — Fenster mit externem Regeleingriff
(Sondereffekte). Sobald verfügbar, werden diese Fenster aus historischen Rückrechnungen
ausgeschlossen (gleiches Prinzip wie bei lernenden Modulen, siehe Memory
`ems-sondereffekt-markierung`) — ein "Was wäre der Netzbezug ohne Notladung gewesen"-Fehler
würde sonst in die Wirtschaftlichkeitsrechnung einfließen. Bis dahin: keine Bereinigung,
im Formular als bekannte Einschränkung vermerkt.

## Netztransparenz.de-API — Rechercheergebnis (Stand Doku v1.14, 07.02.2025)

- **Zugang:** Registrierung als "API-User" im Extranet nötig (kostenlos, aber Account
  erforderlich), dort bis zu 5 OAuth-Clients über den "OAuth-Manager" anlegbar.
  Kein anonymer Zugriff.
- **Auth:** OAuth2 Client-Credentials-Flow. Token-URL
  `https://identity.netztransparenz.de/users/connect/token`, `grant_type=client_credentials`,
  Client_ID/Client_Secret aus dem Extranet. Token 1h gültig, danach erneuern.
  → passt NICHT auf "Handshake bevorzugt" (kein Peer-zu-Peer im LAN, echte Cloud-API) —
  Client_ID/Secret werden wie bei Tibber als **Attribut**, nicht Property, gespeichert
  (siehe Memory `nrg-stack-credentials`).
- **Basis-URL:** `https://ds.netztransparenz.de/api/v1/data`, Health-Check unter
  `.../api/v1/health`. Format: CSV (Ausnahme NRV-Saldo-Ampel: JSON).
- **Rate-Limit:** 2 Anfragen/Sekunde/Quell-IP, bei Überschreitung 2h-IP-Sperre. Für
  seltene Tages-/Wochenabrufe unkritisch, aber: Zugriffe cachen, nicht bei jedem
  Formular-Öffnen neu abrufen.
- **WICHTIG, per Live-Test an Instanz #42890 am 27.07.2026 richtiggestellt:** Die
  PDF-Doku (v1.14) beschreibt für `marktpraemie` Query-Parameter
  (`?yearFrom=&monthFrom=&yearTo=&monthTo=`) — das führt live zu HTTP 404. Die
  öffentliche Swagger-UI (https://api-portal.netztransparenz.de/public-swagger-ui,
  ohne Login einsehbar, Server `https://ds.netztransparenz.de`) zeigt den tatsächlich
  gültigen Pfad: **`GET /api/v1/data/marktpraemie/{monthFrom}/{yearFrom}/{monthTo}/{yearTo}`**
  — Monat/Jahr als PFAD-Segmente, nicht als Query-String, und in dieser Reihenfolge
  (Monat vor Jahr). Andere Endpunkte derselben Familie folgen demselben Muster
  (`Jahresmarktpraemie/{year}`, `redispatch/{dateFrom}/{dateTo}` usw.). Vor jeder
  neuen Endpunkt-Anbindung die Swagger-UI gegenprüfen, nicht blind aus der PDF-Doku
  übernehmen — sie kann hinter der Live-API zurückliegen.
- **Relevante Endpunkte für diesen Rechner:**
  - `GET api/v1/data/marktpraemie/{monthFrom}/{yearFrom}/{monthTo}/{yearTo}` —
    Monatsmarktwerte (u.a. "MW Solar in ct/kWh"), inkl. Flags "Negative Preise
    (1H/2H/3H/4H/6H/15MIN/2CT)" je Monat (Spaltenname laut Doku-Historie 1.18/1.23
    inzwischen "Negative Preise (XH)", nicht mehr "Negative Stunden (XH)" — beim
    CSV-Parsing beachten, falls der Live-Header vom Stand 07.02.2025 abweicht, den
    dieses Modul bisher zugrunde legt).
  - `GET api/v1/data/Jahresmarktpraemie` — Jahresmarktwerte, gleiche Struktur pro Jahr.
  - `GET api/v1/data/NegativePreise/{1|3|4|6}` — Stunden mit negativem Spotpreis nach
    der jeweiligen X-Stunden-Regel (viertelstundenscharfe EPEX-Werte selbst liefert diese
    API NICHT direkt als eigener Endpunkt — die 1/4h-Auktionswerte sind Teil der
    Vermarktungs-Endpunkte, nicht als reiner Spotpreis-Zeitreihe; für viertelstundenscharfe
    negative Preise wird stattdessen `Spotmarktpreise` (aktuell nur stündlich, Format 15)
    bzw. die Tibber-Preiskurve (bereits viertelstundenscharf) herangezogen).
  - `GET api/v1/data/Spotmarktpreise` — EPEX Day-Ahead, stündlich, ct/kWh (Redundanz zu
    Tibber, aber unabhängig von einem Tibber-Vertrag nutzbar — relevant, falls der Nutzer
    KEINEN dynamischen Vertrag hat und trotzdem "was hätte ein dynamischer Vertrag
    gebracht" durchrechnen will, ohne TibberGridReward zu installieren).
  - `GET api/v1/data/redispatch` — Redispatch-Maßnahmen, informativ für Kontext, kein
    direkter Rechengrund in Phase 1–4.
- **Fazit:** Kein Blocker, aber Registrierungsaufwand — für Phase 1 (dynamischer Vertrag)
  NICHT nötig, weil Tibber die Preiskurve schon liefert. Erst ab Phase "Förderende/
  Solarspitzengesetz" wird der Marktwert-Solar-Endpunkt gebraucht (Vergleich EEG-Vergütung
  vs. Marktwert nach Formelabzug). Registrierung daher erst vor Phase 4 nötig, kein
  Grund, sie jetzt schon vorzuziehen.
- **Zentrales Verbund-Modul statt eigenem Client (Fund ModbusSlave/DVHub, 22.09.2026):**
  `DG65/NRGNetztransparenz` (Präfix `NTP`) bündelt den API-Zugriff für den ganzen Verbund
  (DVHub, künftig EMS für §51, hier für Phase 4) — Grund: 2 Anfragen/Sekunde/IP-Limit,
  mehrere unabhängig pollende Module könnten sich sonst gegenseitig aussperren (2h-Sperre).
  Verträge: `NTP_IsNegativePriceHour($id, $unixTimestamp): bool` (Live-Klassifikation,
  gecacht), `NTP_GetHistoricalNegativePreise($id, $logic, $from, $to): string`,
  `NTP_GetConnectionState($id): string`. Live gegen die Swagger-UI verifiziert (22.09.2026):
  `/NegativePreise/{logic}/{dateFrom}/{dateTo}` — auch hier Pfadsegmente statt Query
  (derselbe Fallstrick wie bei `marktpraemie`), gültige `logic`-Werte **1, 2, 3, 4, 6, 15**
  (nicht nur 1/3/4/6, wie oben aus der PDF abgeleitet — hier korrigiert). Zusätzlich ein
  zweiter Endpunkt ohne `logic` (`/NegativePreise/{dateFrom}/{dateTo}`, wendet selbst die
  aktuell gültige Regel an) für reine Live-Klassifikation ohne EEG-Fassung-Kenntnis.
  **Kein automatisches Chunking bei `GetHistoricalNegativePreise()`** (Stand 22.09.2026,
  DVHub-Rückmeldung): reicht `from`/`to` unverändert durch, kein bekanntes Zeitraum-Limit
  in der Doku, aber nie gegen einen mehrjährigen Zeitraum getestet — bei Timeout/Fehler in
  Phase 4 zuerst dort melden (nützt dann allen), nicht selbst nachbauen.
  **logic-zu-EEG-Fassung-Zuordnung bleibt offen**, bei DVHub wie hier — wird ins
  `NRGNetztransparenz`-CLAUDE.md eingetragen, sobald einer von beiden sie tatsächlich
  braucht (bei uns: Phase 4, § 51a/Solarspitzengesetz-Vergütungsausfall je Fassung).
  **Entscheidung (22.09.2026):** Eigenen Client (`getNetztransparenzToken()`/
  `fetchNetztransparenzCsv()`, bislang an kein Szenario angeschlossen) beim Bau von
  Phase 4 auf `NTP_*` umstellen statt weiterzupflegen. Dietmars bereits eingetragenes
  Client-ID/Secret (Instanz #42890) müsste dabei einmal sichtbar in eine neue
  NRGNetztransparenz-Instanz übertragen werden — über EMS-Koordination abzustimmen,
  kein stiller Umzug.

## Anlagendaten — EMS_GetPlantInfo() als führende Quelle (geändert 13.09.2026)

Ursprünglich eigene Properties mit Dietmars Anlage als Default (`PvKwp` 9.18,
`WrKw` 29.9, `SpeicherKwh` 40, `EinspeiseverguetungCtKwh` 18.36) — verstieß gegen
die Verbund-Regel "keine eigene Anlage als Norm". Seit EMS 0.34.0 führt EMS diese
Daten zentral (`EMS_GetPlantInfo()`, Vertrag `plantinfo` **1.0**, rein lesend),
damit sie nicht dreifach (EMS/Szenariorechner/Dashboard) gepflegt werden.

**Auflösung (siehe `getPlantInfo()`/`get*()`-Methoden in `module.php`):** EMS,
sofern installiert und die Major passt, sonst eigene Property als Ersatzfeld,
sonst 0.0/leer ("nicht angegeben") — nie mehr Dietmars Werte als Default.
`WrKw` hat KEIN EMS-Gegenstück und bleibt reines Ersatzfeld (aktuell ohnehin in
keiner Berechnung verwendet, nur Anlagendaten-Anzeige) — bei Bedarf könnte EMS
das additiv ergänzen (Quelle wäre InverterHub), bislang aber kein Szenario, das
es bräuchte. `PvKwp`/`EinspeiseverguetungCtKwh`/`InbetriebnahmeDatum` werden bei
vorhandenem EMS überschrieben. `foerderende`/`eegFassung`/`pflichten[]` gibt es
NUR über EMS (keine eigene Nachbildung der EEG-Tabellenlogik hier) — Datenbasis
für das noch nicht gebaute Szenario 4 (Förderende/Solarspitzengesetz), siehe dort.

**Rangfolge und Anzeige (seit 0.7.0):** Eine bewusste eigene Eingabe (>0) schlägt den
EMS-Wert, sonst gilt der EMS-Wert, sonst „nicht angegeben“. Das Formular zeigt je Feld
eine schreibgeschützte Zeile — 🔗 automatisch von EMS (mit Quelle), ✏️ eigene Eingabe
(„überschreibt EMS: …“), ℹ️ nichts verfügbar — und das Eingabefeld nur, wenn nichts
automatisch kommt oder eine eigene Eingabe gilt; ein Knopf blendet die übrigen im
geöffneten Formular ein (`UpdateFormField`, speichert nichts). Der automatische Wert wird
NIE in das Eingabefeld geschrieben: „Übernehmen“ würde ihn sonst als eigene Angabe
speichern, das Modul folgte EMS nicht mehr und „0 = nicht angegeben“ verlöre seine
Bedeutung.

**`SpeicherKwh` seit EMS 0.34.2 (`plantinfo` **1.1**, additiv) ebenfalls über EMS**
(`speicherKwh`/`speicherKwhQuelle`): `wechselrichter` (über InverterHub gemessen,
`bat_capacity`) ist belastbar. `einstellung` (EMS-Property `BAT_Capacity_kWh`) kann laut
EMS der nie geänderte Standardwert 10 kWh sein und wird deshalb als „unbestätigt“
gekennzeichnet; eine eigene Eingabe schlägt sie ohnehin.

Wer welchen Wert braucht: `SpeicherKwh` und Einspeisevergütung das Szenario
„Speichergröße“; `PvKwp`, `WrKw`, Inbetriebnahme derzeit kein Szenario (vorgesehen für
Förderende/Solarspitzengesetz) — die ℹ️-Zeile sagt das ehrlich statt „wird gebraucht“.

Datumsformat (Verbund-Regel 9b, 13.09.2026): nutzersichtbar **TT.MM.JJJJ**,
`parseAnlageDatum()`/`formatAnlageDatum()` lesen zusätzlich das alte JJJJ-MM-TT.

## Szenario-Typen

### 1. Dynamischer Vertrag (Phase 1)

**Frage:** Was hätte ein dynamischer Stromvertrag in den letzten N Tagen gegenüber dem
aktuellen Festpreis gekostet/gebracht?

**Rechnung:** Historischer Netzbezug (`AC_GetAggregatedValues`, stündlich, tageweise abgefragt) bewertet
einmal mit dem Festpreis (Nutzereingabe, ct/kWh brutto) und einmal mit dem **tatsächlichen Preisverlauf des
dynamischen Tarifs** aus `EMS_GetPurchasePriceHistory` (Bezugstarif „Tibber“, ct/kWh brutto je Viertelstunde,
zu Stundenmitteln). Verglichen wird nur über Stunden mit Preis (`coverage`); fehlende Preise werden nicht
mit einem Ersatzwert gefüllt. Grundgebühr des dynamischen Tarifs aus `TIBBERGR_GetTariffConfig`, falls
bekannt (sonst nicht angesetzt, `dynamicBaseFeeKnown`).

**Korrektur 21.09.2026:** Die erste Fassung holte die Preise über `TIBBERGR_GetPriceCurve`. Das liefert nur
heute und morgen, der 30-Tage-Rückblick rechnete daher praktisch nichts (übrige Stunden wurden mit dem
Festpreis „aufgefüllt“, Ersparnis ≈ 0). `NRGDASH_GetPriceSeries` scheidet ebenfalls aus (Vergangenheit nur als
BDEW-Näherung).

**Offen (Entwurfsfrage):** Ein Nutzer OHNE Tibber (das Kernpublikum für „lohnt sich ein dynamischer
Vertrag?“) hat keinen Verlauf. Denkbar: Börsenpreis-Modul (`SPOT_GetPriceHistory`, netto) plus ein
Aufschlagsmodell (Beschaffung, Netzentgelt, Steuern/Umlagen, MwSt) analog Tibber-`components`. Braucht eine
Entscheidung, ob der Szenariorechner ein Aufschlagsmodell selbst führt oder das Börsenpreis-Modul es liefert.

**Eingaben:** Festpreis (ct/kWh brutto), Grundpreis (€/Monat), Netzbezugsvariable.
**Automatisch:** Preisverlauf und ggf. Grundgebühr des dynamischen Tarifs.

### 2. Speichergröße

**Frage:** Lohnt eine größere Batterie, und ab welcher Größe sinkt der Grenznutzen?

**Rechnung:** Simulation von Erzeugung und Hauslast (stündlich, nur Stunden mit BEIDEN Werten) mit gestuften
virtuellen Speichergrößen (Überschuss lädt, Defizit entlädt). Nutzen je zusätzlich verschobener kWh =
**Bezugspreis minus Einspeisevergütung** (die verschobene kWh hätte sonst Vergütung erhalten; die erste Fassung
setzte den vollen Bezugspreis an und überschätzte die Ersparnis erheblich). Fehlt die Vergütung, wird mit 0 ct
gerechnet und das Ergebnis als optimistisch gekennzeichnet. Amortisation gegenüber der AKTUELLEN Größe.
Kein Wirkungsgradmodell, keine Leistungsgrenzen (optimistisch).

**Eingaben:** Speicherpreis, Nutzungsdauer, Bezugspreis (Festpreis), Erzeugungs- und Lastvariable.
**Automatisch:** aktuelle Speichergröße und Einspeisevergütung aus `EMS_GetPlantInfo`.

**Zielgröße (ab 0.10.0):** `ZielgroesseSpeicherKwh` (0 = nicht angegeben) simuliert zusätzlich zum
festen Raster (0/10/…/80 kWh) genau diese eine Größe und hebt sie in der Rückgabe unter `target`
gesondert hervor (`null`, solange keine Zielgröße gesetzt ist). Dient als Vorstufe für ein Dashboard-
Eingabefeld "was würde X kWh bringen" statt nur des groben Standardrasters — kein eigenes neues
Rechenmodell, derselbe SoC-Simulationslauf bekommt nur einen zusätzlichen Stützpunkt.

`SZR_CalculateStorageSizeScenario(int $InstanceID, int $days, int $targetKwh)` nimmt die Zielgröße
seit 0.10.0 zusätzlich als PFLICHT-Parameter (kein PHP-Standardwert, SUITE.md Stolperstein 8/20):
`$targetKwh > 0` übersteuert die Property einmalig für diesen Aufruf, ohne sie zu verändern — Grund
ist die neue `NRGDashboardSzenarien`-Kachel (Abstimmung mit der Dashboard-Session 23.09.2026): ein
Slider in der Kachel ruft die Funktion per `RequestAction()` direkt mit Live-Parametern auf, ohne
Property+ApplyChanges-Umweg. `0` übergeben, wenn kein Override gewünscht ist (dann gilt die Property
wie bisher). Die beiden anderen Szenario-Funktionen (`CalculateDynamicTariffScenario`,
`CalculateParagraph14aScenario`) bleiben unverändert, ihre Signatur passt für die Kachel schon.

## Dashboard-Anbindung (NRGDashboardSzenarien)

Abgestimmt mit der Dashboard-Session am 23.09.2026: eine neue, eigenständige Kachel
`NRGDashboardSzenarien` (Geschwister von PVMonitor/WPMonitor, gehört zum NRGDashboard-Modul, nicht zu
SZR) findet die SZR-Instanz automatisch (Modul-GUID `{7F3A9C1E-4B5D-4A6F-8C2E-1D9B3A7E5F4C}`, Muster
"genau eine Instanz, sonst die einzige aktive, sonst 0 = nicht raten"), zeigt die Verbindung als
eigene Statuszeile (✅/⚠️/ℹ️) und ruft `SZR_GetAvailableScenarios($id)` für die Discovery. Ein
"Beauftragen"-Knopf/Slider löst `RequestAction()` in der Kachel aus, die direkt eine der drei
`SZR_Calculate*()`-Funktionen mit Live-Parametern aufruft (kein Timer, kein Property+ApplyChanges-
Umweg) und das Ergebnis per `UpdateVisualizationValue()` sofort zurück in die Karte schreibt.
Rückgabeformat unverändert (`contractVersion`, `dataComplete`/`reason`, Kernkennzahl) — kein
Wrapper nötig, Dashboard zeigt `reason` prominent bei `dataComplete:false`. Kein Push/
`VISU_PostNotificationEx` (SUITE.md Stolperstein 22 betrifft OS-Benachrichtigungen, hier unpassend,
es ist ein normaler Karten-Refresh). Optik (macOS-Card-Stil, `-apple-system`-Font-Stack, dezente
Schatten) liegt vollständig bei der Dashboard-Kachel, eigenes CSS in ihrer `module.html`.

### 3. §14a-Beitritt

**Frage:** Lohnt sich der Wechsel in die reduzierten §14a-Netzentgelte (gegen
Steuerbarkeit/Dimmung der steuerbaren Verbraucher)?

**Rechnung:** Netzentgelt-Ersparnis (Nutzereingabe, da anlagen-/netzbetreiberspezifisch,
`TibberGridReward/GetTariffConfig` liefert bereits `paragraph14aReductionYear` als
Referenzwert falls dort schon gepflegt) vs. geschätzte Komfort-/Ertragseinbuße durch
Dimmung. Für Letzteres: `SBH_GetState`-Vertrag bereits als Datenquelle vorgesehen, ABER
SteuerboxHub liefert noch keine Werte (Hardware fehlt) — Phase-3-Rechnung nutzt deshalb
zunächst ein Nutzereingabe-Szenario ("angenommene Dimm-Häufigkeit/-Dauer pro Jahr laut
Netzbetreiber-Erfahrungswerten") statt Live-Daten. Sobald `SBH_GetState` echte
`loadDimmActive`-Historie liefert (Modul selbst müsste dafür historisieren, aktuell nur
Momentanzustand — Rückfrage an SteuerboxHub-Sitzung nötig, falls Historisierung gewünscht
ist), kann auf echte Häufigkeit umgestellt werden.
**Eingaben:** Netzentgelt-Differenz, angenommene Dimm-Parameter.
**Automatisch gezogen:** `SBH_GetState` (sobald verfügbar), sonst nur Property-Eingabe.
**Komplexität:** mittel, aber mit Fremdabhängigkeit (SteuerboxHub-Baustand) — bewusst
NICHT Phase 1.

### 4. Förderende / Solarspitzengesetz-Optionswechsel

**Frage a) (Förderende):** Was passiert wirtschaftlich, wenn die 20-jährige
EEG-Förderung endet (bei Dietmars Anlage weit in der Zukunft, aber Modul soll generisch
für jede Inbetriebnahme rechnen)? Vergleich Marktwert Solar (Direktvermarktung/Überschuss-
Eigenverbrauch) vs. bisherige Vergütung.

**Frage b) (Solarspitzengesetz-Freiwilligkeit, Bestandsanlagen vor 25.02.2025):**
Lohnt sich der freiwillige Wechsel in die neue negative-Preise-Regel (0,6 ct/kWh Bonus,
dafür Vergütungsausfall bei negativen Preisen, kompensiert am Förderende)? Reine
Barwert-Abwägung: Bonus über Restlaufzeit vs. Erwartungswert der ausfallenden
Vergütungsstunden × Erzeugung in diesen Stunden, abgezinst auf den späteren
Nachholzeitpunkt.

**Rechnung:** Braucht Marktwert Solar (`marktpraemie`/`Jahresmarktpraemie`-Endpunkt,
siehe oben) UND historische/prognostizierte negative-Preis-Stunden
(`NegativePreise/{n}`-Endpunkte bzw. Tibber-Preiskurve) verknüpft mit der eigenen
PV-Erzeugung in diesen Stunden (`PVF_GetForecast` für Vorwärtssimulation, Archive Control
für Rückrechnung). **Dies ist der Punkt, an dem der Netztransparenz-Zugang nötig wird** —
vorher (Phase 1–2, ggf. 3) kommt der Rechner ohne aus.
**Eingaben:** keine mehr nötig für Inbetriebnahmedatum/Vergütung/Bestandsschutz — kommt
seit 13.09.2026 aus `EMS_GetPlantInfo()` (`inbetriebnahme`, `foerderende`, `eegFassung`,
`verguetungCt`, `pflichten[]` mit Codes wie `negativpreis`/`einspeisung60`/`ue20`, siehe
Abschnitt "Anlagendaten" oben). Ohne EMS bleiben nur die groben Ersatzfelder, echtes
Rechnen für dieses Szenario ohne EMS ist nicht sinnvoll möglich (Bestandsschutz-/
Pflichten-Logik lebt bewusst nur dort, keine Nachbildung hier).
**Automatisch gezogen:** `EMS_GetPlantInfo()` (Stammdaten/Pflichten), Netztransparenz
Marktwert Solar + negative Preise, eigene PV-Historie.
**Komplexität:** hoch, mehrere gekoppelte Annahmen (Abzinsung, Erzeugungsprognose über
Jahrzehnte) — bewusst letzte Phase, noch nicht gebaut.

## Ergebnis-Darstellung

Reine Rechenergebnisse als strukturierter Rückgabewert (`SZR_CalculateXyzScenario()`,
je Szenario eine Funktion, alle mit `contractVersion`), keine eigene Chart-Kachel in
diesem Modul — Chart-Darstellung ist Sache des parallel gebauten "NRG-Stack Dashboard"-
Moduls (Repo `NRGDashboard`, siehe README dort). Abstimmung mit der Dashboard-Sitzung vor
Festlegung des exakten Rückgabeformats, damit das Dashboard es direkt konsumieren kann
(gleiche Kopplung wie EMS↔Hubs: Rechner liefert Daten, Dashboard stellt dar, kein
Rollentausch).

**Ergänzt 27.07.2026 (Rückmeldung Dietmar); seit 0.8.0 schreiben die Kennzahlen nur bei mindestens 90 % Datenabdeckung:** Ohne jede eigene Variable war auf der
Instanz selbst nichts sichtbar — Nutzerbestätigung: vier Kern-Ergebnisvariablen
(`NetztransparenzStatus`, `DynamicTariffSavingsEur`, `StorageSizeAdditionalSavingsEur`,
`Paragraph14aNetBenefitEur`) ergänzt, je eine Kennzahl pro Szenario, kein vollständiges
Ergebnis-Objekt als Variable. Werden durch einen täglichen Timer
(`SZR_RefreshScenarioVariables`) sowie unmittelbar bei jedem `Calculate*Scenario()`-Aufruf
aktualisiert. Bleibt bewusst schmal — die vollständige Darstellung (alle Felder, alle
Speichergrößen-Stufen usw.) bleibt Aufgabe des Dashboards, das weiterhin die
`Calculate*Scenario()`-Funktionen direkt aufruft.

## Bauplan (Phasen, jede lauffähig)

1. **Dynamischer Vertrag** — `SZR_CalculateDynamicTariffScenario()`. Nutzt nur
   bestehende Verbund-Verträge, kein externer Zugang. **→ Phase 1, gebaut.**
2. **Speichergröße** — `SZR_CalculateStorageSizeScenario()`. Vereinfachtes SoC-Modell
   (kein Wirkungsgrad, keine Lade-/Entladeleistungsgrenzen), stündliche Auflösung wie
   Phase 1. Amortisation gegenüber der AKTUELL konfigurierten Speichergröße gerechnet
   (Frage: lohnt sich eine Vergrößerung?), nicht gegenüber 0 kWh. **→ Phase 2, gebaut.**
3. **§14a-Beitritt** — `SZR_CalculateParagraph14aScenario()`. EMS-Koordination
   25.07.2026 bestätigt: `SBH_GetState` liefert derzeit nur den Live-Zustand, keine
   Ereignis-Historie, und wird das absehbar nicht (Dietmar hat keine §14a-Hardware).
   Rechnet daher dauerhaft mit Nutzereingabe-Annahmen statt Live-Daten. **→ Phase 3, gebaut.**
4. **Förderende/Solarspitzengesetz** — `SZR_CalculateFeedInEndScenario()` +
   `SZR_CalculateNegativePriceOptInScenario()`. Braucht Netztransparenz-Registrierung
   (Client_ID/Secret-Beschaffung ist ein manueller Schritt Dietmars im Extranet, nicht
   automatisierbar — rechtzeitig vor Phase 4 anstoßen).
