# Hinweise für die Arbeit an diesem Repository

## Rolle im Verbund

Szenariorechner ist ein reiner **Rechner, kein Regler**: liest historische Verbrauchsdaten
(Archive Control) und Verträge anderer NRG-Stack-Module, liefert strukturierte Rückgabewerte
je Szenario. Setzt nichts durch, sucht nie proaktiv nach Konsumenten. Konzept, Datenquellen
und Phasenplan: [KONZEPT.md](KONZEPT.md).

## Grundregeln (Verbund-Standard, siehe EMS/SUITE.md)

1. **Eigenständigkeit.** Jeder Fremdaufruf (`TIBBERGR_`, `PVF_`, `LFC_`, `SGW_`, `SBH_`,
   `EMS_`) hinter `function_exists()`/`IPS_ModuleExists()`. Fehlt ein Partner, entfällt nur
   das jeweilige Szenario bzw. fällt auf die eigene Ersatz-Property zurück — das Modul
   bleibt lauffähig.
2. **Sprachregel Deutsch.** Alles Nutzersichtbare deutsch, keine vermeidbaren Anglizismen.
   Idents/Methodennamen ausgenommen.
3. **`contractVersion` in jeder `SZR_Calculate*Scenario()`-Rückgabe**, Start `'1.0'`.
4. **Netztransparenz.de-Zugangsdaten** (sobald Phase 4 den Endpunkt braucht): Client_ID/
   Secret als `RegisterAttributeString`, nie als Property (Memory `nrg-stack-credentials`).
   Registrierung im Extranet ist ein manueller Schritt Dietmars, rechtzeitig anstoßen.
5. **Store-/Stable-Regeln von Anfang an**: `vendor: ""` (reines Softwaremodul), `library.json`
   nur id/author/name/url/compatibility/version/build/date, Schaltflächen nur per
   `UpdateFormField`, Klassenname = Modulname.

## Abgrenzung zu anderen Modulen

- **Chart-/Dashboard-Darstellung gehört nicht hierher** — das übernimmt das parallel gebaute
  `NRGDashboard`-Modul. Vor Festlegung des Rückgabeformats bei größeren Änderungen kurz
  abstimmen, damit das Dashboard es direkt konsumieren kann.
- **Netzentgelt-Zeitvariabilität (Modul 3)** kommt aus `TIBBERGR_GetTariffConfig`, nicht
  selbst nachbilden.
- **§14a-Live-Signal** kommt aus `SBH_GetState` (SteuerboxHub), sobald die Hardware existiert
  und das Modul Werte liefert — aktuell nur Gerüst.
- **Anlagenstammdaten (kWp, Inbetriebnahme, Vergütung, Förderende, EEG-Fassung, Pflichten,
  Speicherkapazität) kommen aus `EMS_GetPlantInfo()`** (EMS 0.34.0+, Vertrag `plantinfo`,
  Speicherkapazität additiv ab **1.1**), NICHT selbst pflegen/nachbilden — das war der
  Zustand vor 13.09.2026 (dreifache Pflege in EMS/Szenariorechner/Dashboard, eigene Anlage
  als Default). Eine bewusste eigene Eingabe (>0) überschreibt den EMS-Wert,
  sonst ist sie nur Ersatz. **Automatische Werte nie in ein Eingabefeld schreiben** (sonst
  speichert „Übernehmen“ sie als eigene Angabe) — stattdessen Zeile 🔗/✏️/ℹ️ und Eingabefeld
  nur bei Bedarf sichtbar (`buildPlantFieldRows()`). `speicherKwhQuelle === 'einstellung'`
  kann der ungeänderte EMS-Standard (10 kWh) sein und wird als „unbestätigt“ gezeigt. Siehe
  `getPlantInfo()`/`get*()`-Methoden in `module.php` und KONZEPT.md Abschnitt
  "Anlagendaten".

## Koordination

Cross-Session-Rückfragen (Design-Entscheidungen, Datenformat-Abstimmung mit Dashboard) laufen
über die EMS-Koordinationssitzung, nicht direkt mit dem Nutzer.


## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.

## Konventionen, die hier gelten (aus SUITE.md, vollständig gelesen 21.09.2026)

- **Formular-Reihenfolge:** 👋 „Wozu dieses Modul?“ (einmalig dismissible, `PurposeIntroGone`) → 🆕 „Neu in
  Version X.Y“ (Versionsnummer in der Caption, pro Version dismissible) → 📖 Doku & Hilfe (Version dauerhaft) →
  Fachpanels → Feedback-Hinweis (dismissible; ohne Forum-Thread GitHub-Issues als Ziel) → 🧡 „Über dieses
  Modul“ (Lizenz/PayPal, **nicht** dismissible, ganz unten). Link-Buttons immer `onClick = echo '<URL>';` +
  `link: true`, nie die URL in `link`.
- **Keine erfundenen Standardwerte:** alle Zahlen-Properties stehen auf 0 = „nicht angegeben“; ein Szenario ist
  erst `available`, wenn seine Angaben da sind, und nennt sonst den Grund. Vor dem Ändern von Standardwerten
  den Wert an Dietmars Instanz ausdrücklich setzen (sonst ändert sich sein Live-Verhalten).
- **Jede Verbindung eine Statuszeile** (✅/⚠️/ℹ️/⛔), live in `GetConfigurationForm()`, Elemente **rekursiv**
  über `setFormElement()` suchen, automatische Werte nie ins Eingabefeld schreiben. Für Felder ohne
  Automatik-Pfad die ehrliche Zeile („wird derzeit nicht automatisch übernommen“).
- **Statuscodes:** nur 102 und 104, beide in `form.json["status"]` beschriftet. Ein fehlendes Partnermodul ist
  kein Fehlerstatus (Regel 9d), die Gründe stehen je Szenario in `GetAvailableScenarios()`.
- **Fremdaufrufe:** hinter `function_exists()` UND in `try/catch (\Throwable)`, nie `@` (fängt keinen Error,
  Stolperstein 8/13; genau so entstand der Instanz-Absturz durch `TIBBERGR_GetPriceCurve()` ohne ID).
  Fehlschläge dauerhaft per `LogMessage()` loggen, nicht nur `SendDebug()`. Eigenständigkeit prüft
  `.tools/check-standalone.php`.
- **Rechnen:** Kalendertage statt `+86400` (Stolperstein 18); Archivabfragen tageweise, `false` = Fehler
  (9g); Datenlücken sind `null`/ausgelassen, nie 0 (Stolperstein 15); Einheiten dokumentieren (16):
  Preise ct/kWh brutto, Ergebnisse EUR; keine Zahl in die Ergebnisvariable schreiben, wenn die Abdeckung unter
  90 % liegt.
- **Preise der Vergangenheit** kommen aus `EMS_GetPurchasePriceHistory` (Bezugstarif „Tibber“, echter
  Archiv-Verlauf), NICHT aus `TIBBERGR_GetPriceCurve` (nur heute/morgen) und nicht aus
  `NRGDASH_GetPriceSeries` (dort für die Vergangenheit nur BDEW-Näherung).
- **Öffentliche `SZR_`-Funktionen ohne PHP-Standardwerte**, alle Parameter typisiert (Stolperstein 8/20).
- **`module.json`:** `name` = Klassenname (nie ändern), genau **ein** Alias „NRG-Stack Szenariorechner“.
  `library.json["name"]`: „NRG-Stack Szenariorechner“ ohne Suffix.

## Prüfstand vor jedem Push

```
php .tools/check-standalone.php   # Fremdaufrufe abgesichert
php .tools/test-form.php          # Statuszeilen, Formular-Konventionen (12 Fälle)
php .tools/test-scenarios.php     # Rechnung, Lücken, Zeitumstellung, Standardwerte
```

`test-form.php` prüft am ausgelieferten JSON, dass jede Statuszeile ankommt (auch in Panels), der
statische Platzhalter weg ist, die Pflicht-Panels an der richtigen Stelle stehen und ohne Angaben kein
Szenario „verfügbar“ ist. `test-scenarios.php` rechnet die Szenarien gegen nachgebildetes IPS durch und
prüft, dass Lücken nichts Erfundenes erzeugen. Beide sind nachweislich rot, wenn die jeweiligen
Fehler wieder eingebaut werden. Neue Verbindung = neue Statuszeile + neuer Fall; CI: `.github/workflows`.
