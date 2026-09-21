# Szenariorechner

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.9.0--beta.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSzenariorechner/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSzenariorechner/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

"Was wäre wenn?"-Wirtschaftlichkeitsrechner für den NRG-Stack. Rechnet nach, was
verschiedene Entscheidungen bringen würden — Wechsel auf einen dynamischen Stromvertrag,
eine andere Speichergröße, §14a-Beitritt, oder das Ende der EEG-Förderung/Solarspitzengesetz-
Optionswechsel.

Reiner Rechner, kein Regler — setzt nichts durch, greift auf Verbund-Verträge lesend zu.

**Stand:** Version 0.9.0-beta.1 — Szenarien "Dynamischer Vertrag vs. Festpreis", "Speichergröße" und
"§14a-Beitritt", je Szenario eine Ergebnisvariable an der Instanz. Ein Szenario rechnet erst, wenn
seine Angaben da sind; es gibt keine erfundenen Standardwerte, und Zahlen erscheinen nur bei
tragfähiger Datenlage. Konzept für alle Szenario-Typen: [KONZEPT.md](KONZEPT.md).

**Datenquellen:** Anlagendaten und der Preisverlauf des dynamischen Tarifs kommen, wenn vorhanden,
automatisch vom NRG-Stack EMS; der Netzbezug kommt automatisch aus MeterHub (Abrechnungszähler bevorzugt),
die PV-Erzeugung aus InverterHub. Die Hauslast ist derzeit eine im Formular gewählte archivierte Variable.

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
internen Kompatibilitäts-Manifest des NRG-Stack.
