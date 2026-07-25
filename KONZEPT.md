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
- **Relevante Endpunkte für diesen Rechner:**
  - `GET api/v1/data/marktpraemie` — Monatsmarktwerte (u.a. "MW Solar in ct/kWh"),
    inkl. Flags "Negative Stunden (1H/3H/4H/6H)" je Monat.
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
  vs. Marktwert nach Formelabzug). Registrierung daher erst vor Phase 3 nötig, kein
  Grund, sie jetzt schon vorzuziehen.

## Anlagendaten (Property, Referenz Memory `anlage-dietmar`)

`PvKwp` (9.18), `WrKw` (29.9), `SpeicherKwh` (40), `EinspeiseverguetungCtKwh` (18.36),
`InbetriebnahmeDatum` (für §51a-Stichtag 25.02.2025 und 20-Jahres-Förderende relevant).
Als Properties, weil Rechenparameter, keine Kachel-Editierliste — Punkt 11 der
Store-Review-Checkliste greift hier nicht (kein Listen-Formular).

## Szenario-Typen

### 1. Dynamischer Vertrag (Phase 1 — Empfehlung als Start)

**Frage:** Was hätte ein dynamischer Stromvertrag in den letzten N Monaten gegenüber dem
aktuellen Festpreis gekostet/gebracht?

**Rechnung:** Historischer Netzbezug (`AC_GetAggregatedValues` an der MeterHub-/
InverterHub-Instanz, 15-Minuten-Summierung wie in `ips-counter-aggregation` dokumentiert)
× viertelstundenscharfe Preiskurve (`TIBBERGR_GetPriceCurve`, rückwirkend soweit im Cache/
Archiv vorhanden) vs. × aktueller Festpreis (Nutzereingabe ct/kWh). Differenz = Ersparnis/
Mehrkosten. Optional: Einspeisung ebenfalls mit dynamischem Vermarktungspreis statt fixer
Einspeisevergütung gegenrechnen (zeigt zugleich Wirkung von Batteriespeicher-Verschiebung
NICHT — das ist Szenario 2, hier nur Tarifvergleich bei unverändertem Verhalten).

**Eingaben:** aktueller Festpreis (ct/kWh), Grundpreis (€/Monat), Analysezeitraum.
**Automatisch gezogen:** Lastgang, Preiskurve.
**Einfachheit:** hoch — beide Datenquellen (Archive Control, Tibber) sind bereits im
Verbund vorhanden und liefern exakt das Nötige. Kein externer API-Zugang nötig.

### 2. Speichergröße

**Frage:** Welche Speichergröße wäre wirtschaftlich sinnvoll (Grenznutzen sinkender
Autarkiegewinn je zusätzlicher kWh)?

**Rechnung:** Simulation des historischen Lastgangs (Verbrauch, PV-Erzeugung) mit
variabler virtueller Speichergröße (0…80 kWh in Schritten) nach einfachem
Ladezustands-Modell (Überschuss lädt, Defizit entlädt, Grenzen SoC 0–100 %, kein
Wirkungsgradmodell in Phase 1 — vereinfachend, später verfeinerbar) → Autarkiegrad/
Eigenverbrauchsquote je Größe → Grenznutzenkurve. Wirtschaftlichkeit: eingesparter
Netzbezug × Strompreis vs. Anschaffungskosten (Nutzereingabe €/kWh Speicher) über
Abschreibungsdauer.
**Eingaben:** Speicherpreis (€/kWh), Abschreibungsdauer, evtl. bereits vorhandene 40 kWh
als Startpunkt (Vergrößerung vs. Neuanschaffung).
**Automatisch gezogen:** historischer Lastgang + PV-Erzeugung.
**Komplexität:** mittel — braucht ein eigenes (einfaches) Simulationsmodell, keine
externen Datenquellen zusätzlich.

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
**Eingaben:** Inbetriebnahmedatum, aktuelle Vergütung, Bestandsschutz-Status (vor/nach
Stichtag).
**Automatisch gezogen:** Netztransparenz Marktwert Solar + negative Preise, eigene PV-Historie.
**Komplexität:** hoch, mehrere gekoppelte Annahmen (Abzinsung, Erzeugungsprognose über
Jahrzehnte) — bewusst letzte Phase.

## Ergebnis-Darstellung

Reine Rechenergebnisse als strukturierter Rückgabewert (`SZR_CalculateXyzScenario()`,
je Szenario eine Funktion, alle mit `contractVersion`), keine eigene Chart-Kachel in
diesem Modul — Chart-Darstellung ist Sache des parallel gebauten "NRG-Stack Dashboard"-
Moduls (Repo `NRGDashboard`, siehe README dort). Abstimmung mit der Dashboard-Sitzung vor
Festlegung des exakten Rückgabeformats, damit das Dashboard es direkt konsumieren kann
(gleiche Kopplung wie EMS↔Hubs: Rechner liefert Daten, Dashboard stellt dar, kein
Rollentausch).

## Bauplan (Phasen, jede lauffähig)

1. **Dynamischer Vertrag** — `SZR_CalculateDynamicTariffScenario()`. Nutzt nur
   bestehende Verbund-Verträge, kein externer Zugang. **→ Phase 1, gebaut.**
2. **Speichergröße** — `SZR_CalculateStorageSizeScenario()`. Vereinfachtes SoC-Modell
   (kein Wirkungsgrad, keine Lade-/Entladeleistungsgrenzen), stündliche Auflösung wie
   Phase 1. Amortisation gegenüber der AKTUELL konfigurierten Speichergröße gerechnet
   (Frage: lohnt sich eine Vergrößerung?), nicht gegenüber 0 kWh. **→ Phase 2, gebaut.**
3. **§14a-Beitritt** — `SZR_CalculateParagraph14aScenario()`. Wartet auf
   Rückkopplung mit SteuerboxHub-Historisierung (Rückfrage nötig).
4. **Förderende/Solarspitzengesetz** — `SZR_CalculateFeedInEndScenario()` +
   `SZR_CalculateNegativePriceOptInScenario()`. Braucht Netztransparenz-Registrierung
   (Client_ID/Secret-Beschaffung ist ein manueller Schritt Dietmars im Extranet, nicht
   automatisierbar — rechtzeitig vor Phase 4 anstoßen).
