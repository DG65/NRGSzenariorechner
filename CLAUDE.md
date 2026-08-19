# Hinweise für die Arbeit an diesem Repository

## Rolle im Verbund

Szenariorechner ist ein reiner **Rechner, kein Regler**: liest historische Verbrauchsdaten
(Archive Control) und Verträge anderer NRG-Stack-Module, liefert strukturierte Rückgabewerte
je Szenario. Setzt nichts durch, sucht nie proaktiv nach Konsumenten. Konzept, Datenquellen
und Phasenplan: [KONZEPT.md](KONZEPT.md).

## Grundregeln (Verbund-Standard, siehe EMS/SUITE.md)

1. **Eigenständigkeit.** Jeder Fremdaufruf (`TIBBERGR_`, `PVF_`, `LFC_`, `SGW_`, `SBH_`)
   hinter `function_exists()`/`IPS_ModuleExists()`. Fehlt ein Partner, entfällt nur das
   jeweilige Szenario — das Modul bleibt lauffähig.
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

## Koordination

Cross-Session-Rückfragen (Design-Entscheidungen, Datenformat-Abstimmung mit Dashboard) laufen
über die EMS-Koordinationssitzung, nicht direkt mit dem Nutzer.


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
