<?php

// ===========================================================================
// Szenariorechner — "Was wäre wenn?"-Wirtschaftlichkeitsrechner für den
// NRG-Stack. Reiner Rechner, kein Regler: liest historische Verbrauchsdaten
// (Archive Control) und Marktdaten anderer Verbund-Module und liefert
// strukturierte Rückgabewerte je Szenario-Typ. Setzt selbst nichts durch.
//
// Konzept und Phasenplan: siehe KONZEPT.md im Repo-Wurzelverzeichnis.
//
// Bisheriger Stand:
//  Phase 1 — dynamischer Vertrag vs. Festpreis, auf Basis des historischen
//            Netzbezugs (AC_GetAggregatedValues) und der TibberGridReward-
//            Preiskurve (TIBBERGR_GetPriceCurve).
//  Phase 2 — Speichergröße: vereinfachte SoC-Simulation aus historischer
//            PV-Erzeugung/Hauslast, Autarkiegrad und Amortisation je Größe.
//  Phase 3 — §14a-Beitritt: Netzentgelt-Ersparnis vs. monetarisierte
//            Dimm-Annahmen (reine Nutzereingabe, SBH_GetState liefert noch
//            keine Historie — bestätigt EMS-Koordination 25.07.2026).
// Weitere Szenarien (Förderende/Solarspitzengesetz) folgen als eigene
// SZR_Calculate*Scenario()-Funktionen in späteren Phasen.
// ===========================================================================

class Szenariorechner extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ── Anlagendaten (Referenz Memory anlage-dietmar, generisch als Property) ──
        $this->RegisterPropertyFloat('PvKwp', 9.18);
        $this->RegisterPropertyFloat('WrKw', 29.9);
        $this->RegisterPropertyFloat('SpeicherKwh', 40.0);
        $this->RegisterPropertyFloat('EinspeiseverguetungCtKwh', 18.36);
        $this->RegisterPropertyString('InbetriebnahmeDatum', '');

        // ── Datenquelle Netzbezug (historisch, für Szenario 1: dynamischer Vertrag) ──
        // Energie-Zähler (kWh, kumulativ) am Netzanschluss, üblicherweise eine
        // MeterHub-/InverterHub-Instanzvariable. AC_GetAggregatedValues liefert
        // für Zähler bereits den Verbrauch je Periode (Avg), nicht den Rohstand
        // (siehe Memory ips-counter-aggregation) — daher keine weitere Umrechnung
        // nötig, solange NetzbezugIstZaehler = true.
        $this->RegisterPropertyInteger('NetzbezugVarID', 0);
        $this->RegisterPropertyBoolean('NetzbezugIstZaehler', true);

        // ── Vergleichs-Festpreis (aktueller Vertrag des Nutzers) ──
        $this->RegisterPropertyFloat('FestpreisCtKwh', 32.0);
        $this->RegisterPropertyFloat('FestpreisGrundpreisMonat', 12.0);

        // ── Datenquellen PV-Erzeugung/Hauslast (historisch, für Szenario 2: Speichergröße) ──
        // Beide als LEISTUNG (W) erwartet — üblicherweise InverterHub-/MeterHub-
        // Momentanwerte, nicht kumulative Zähler (anders als NetzbezugVarID oben).
        $this->RegisterPropertyInteger('PvErzeugungVarID', 0);
        $this->RegisterPropertyInteger('HausLastVarID', 0);
        $this->RegisterPropertyFloat('SpeicherPreisEurKwh', 400.0);
        $this->RegisterPropertyInteger('SpeicherAbschreibungJahre', 15);

        // ── §14a-Beitritt (Szenario 3) — reine Nutzereingabe-Annahmen, da
        // SBH_GetState (SteuerboxHub) aktuell nur den Live-Zustand liefert,
        // keine Historie (bestätigt EMS-Koordination 25.07.2026). Sobald eine
        // Historisierung existiert, kann hierauf umgestellt werden.
        $this->RegisterPropertyFloat('Paragraph14aNetzentgeltErsparnisJahr', 150.0);
        $this->RegisterPropertyInteger('Paragraph14aAnnahmeEreignisseJahr', 20);
        $this->RegisterPropertyInteger('Paragraph14aAnnahmeDauerMinuten', 120);
        $this->RegisterPropertyFloat('Paragraph14aAnnahmeReduktionKw', 4.2);

        $this->RegisterAttributeString('LastEvaluation', '{}');
        $this->RegisterAttributeString('ChangelogSeen', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->setElementVisible($form, 'ChangelogPanel', $this->ReadAttributeString('ChangelogSeen') !== '0.3');
        $this->setElementVisible($form, 'ForumHint', !$this->ReadAttributeBoolean('ForumHintGone'));
        $this->injectVersionLabel($form);

        return json_encode($form);
    }

    // Versionszeile im Doku-Panel dauerhaft sichtbar (Verbund-Konvention), aus
    // library.json ermittelt statt fest im Formular verdrahtet.
    private function injectVersionLabel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{9B2E1A3F-6C7D-4E8B-9A1C-2D3E4F5A6B7C}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Szenariorechner Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Szenariorechner';
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'DocVersionLabel') {
                $el['caption'] = $verTxt;
                return;
            }
        }
        unset($el);
    }

    private function setElementVisible(array &$form, string $name, bool $visible): void
    {
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === $name) {
                $el['visible'] = $visible;
                return;
            }
        }
        unset($el);
    }

    public function DismissChangelog(string $version)
    {
        $this->WriteAttributeString('ChangelogSeen', $version);
        $this->UpdateFormField('ChangelogPanel', 'visible', false);
    }

    public function DismissForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $status = 102;
        $message = '';

        if ($this->ReadPropertyInteger('NetzbezugVarID') <= 0) {
            $status = 104;
            $message = 'Netzbezugsvariable nicht konfiguriert';
        } elseif (!function_exists('TIBBERGR_GetPriceCurve')) {
            // Eigenständigkeitsregel: Modul bleibt lauffähig, meldet nur die
            // fehlende Kopplung. Ohne TibberGridReward kann Szenario 1 nicht
            // rechnen (Netztransparenz-Spotmarktpreise als Alternativquelle
            // sind für Phase 1 bewusst noch nicht angebunden, siehe KONZEPT.md).
            $status = 200;
            $message = 'TibberGridRewards nicht gefunden — Preiskurve fehlt für Szenario "Dynamischer Vertrag"';
        }

        $this->SetStatus($status);
        if ($message !== '') {
            $this->SendDebug(__FUNCTION__, $message, 0);
        }
    }

    // -----------------------------------------------------------------
    //  Szenario 1: Dynamischer Vertrag vs. Festpreis
    // -----------------------------------------------------------------

    /**
     * Rechnet nach, was ein dynamischer Vertrag (TibberGridReward-Preiskurve,
     * viertelstündlich) über die letzten $days Tage anstelle des aktuellen
     * Festpreisvertrags gekostet hätte, auf Basis des historischen Netzbezugs.
     *
     * Granularität bewusst stündlich (AC_GetAggregatedValues, aggregation=0):
     * robust, ohne Rohdaten-Integration; die Preiskurve wird je Stunde aus dem
     * Mittel ihrer Viertelstunden-Slots gebildet. Feinere Auflösung (analog
     * Lastprognose::integratedProfile) ist ein möglicher späterer Ausbau, kein
     * Blocker für eine erste funktionsfähige Phase.
     *
     * Rückgabe:
     *   'contractVersion'      => '1.0',
     *   'periodDays'           => int,
     *   'periodFrom'/'periodTo'=> int (Unix),
     *   'hoursEvaluated'       => int,       // Stunden mit vollständigen Daten
     *   'consumptionKwh'       => float,     // Summe Netzbezug im Zeitraum
     *   'costFixedEur'         => float,     // Kosten mit aktuellem Festpreis (inkl. Grundpreis anteilig)
     *   'costDynamicEur'       => float,     // Kosten mit dynamischer Preiskurve (ohne Grundpreis, Tibber-eigener wird nicht unterstellt)
     *   'savingsEur'           => float,     // costFixedEur - costDynamicEur (positiv = dynamisch günstiger)
     *   'avgFixedCtKwh'        => float,
     *   'avgDynamicCtKwh'      => float,
     *   'dataComplete'         => bool,      // false, wenn Archiv oder Preiskurve Lücken hatten
     */
    public function CalculateDynamicTariffScenario(int $days): array
    {
        $result = [
            'contractVersion' => '1.0',
            'periodDays'      => $days,
            'periodFrom'      => 0,
            'periodTo'        => 0,
            'hoursEvaluated'  => 0,
            'consumptionKwh'  => 0.0,
            'costFixedEur'    => 0.0,
            'costDynamicEur'  => 0.0,
            'savingsEur'      => 0.0,
            'avgFixedCtKwh'   => $this->ReadPropertyFloat('FestpreisCtKwh'),
            'avgDynamicCtKwh' => 0.0,
            'dataComplete'    => false,
        ];

        if ($days <= 0) {
            return $result;
        }

        $varID = $this->ReadPropertyInteger('NetzbezugVarID');
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            $this->SendDebug(__FUNCTION__, 'Netzbezugsvariable fehlt/ungültig', 0);
            return $result;
        }

        if (!function_exists('TIBBERGR_GetPriceCurve')) {
            $this->SendDebug(__FUNCTION__, 'TibberGridRewards nicht vorhanden', 0);
            return $result;
        }

        $end = strtotime('today midnight');
        $start = $end - ($days * 86400);
        $result['periodFrom'] = $start;
        $result['periodTo'] = $end;

        $isCounter = $this->ReadPropertyBoolean('NetzbezugIstZaehler');
        $hourlyKwh = $this->hourlyKwhSeries($varID, $isCounter, $start, $end);
        if ($hourlyKwh === null) {
            return $result;
        }

        // Preiskurve holen und auf Stundenmittel verdichten.
        $slots = TIBBERGR_GetPriceCurve();
        $hourlyPriceSum = [];
        $hourlyPriceCount = [];
        foreach ($slots as $slot) {
            if (!isset($slot['start'], $slot['price'])) {
                continue;
            }
            $hourStart = (int) (floor($slot['start'] / 3600) * 3600);
            if ($hourStart < $start || $hourStart >= $end) {
                continue;
            }
            $hourlyPriceSum[$hourStart] = ($hourlyPriceSum[$hourStart] ?? 0.0) + (float) $slot['price'];
            $hourlyPriceCount[$hourStart] = ($hourlyPriceCount[$hourStart] ?? 0) + 1;
        }

        $fixedCtKwh = $this->ReadPropertyFloat('FestpreisCtKwh');
        $grundpreisMonat = $this->ReadPropertyFloat('FestpreisGrundpreisMonat');

        $consumptionKwh = 0.0;
        $costFixed = 0.0;
        $costDynamic = 0.0;
        $priceWeightedSum = 0.0;
        $hoursEvaluated = 0;
        $hoursMissingPrice = 0;

        foreach ($hourlyKwh as $hourStart => $kwh) {
            if ($kwh <= 0.0) {
                continue;
            }
            $consumptionKwh += $kwh;
            $costFixed += $kwh * $fixedCtKwh / 100.0;

            if (isset($hourlyPriceSum[$hourStart]) && $hourlyPriceCount[$hourStart] > 0) {
                $dynCtKwh = $hourlyPriceSum[$hourStart] / $hourlyPriceCount[$hourStart];
                $costDynamic += $kwh * $dynCtKwh / 100.0;
                $priceWeightedSum += $kwh * $dynCtKwh;
                $hoursEvaluated++;
            } else {
                // Keine Preisdaten für diese Stunde: konservativ mit Festpreis bewertet,
                // damit die Differenz nicht künstlich verzerrt wird.
                $costDynamic += $kwh * $fixedCtKwh / 100.0;
                $hoursMissingPrice++;
            }
        }

        $grundpreisAnteil = $grundpreisMonat * ($days / 30.437);

        $result['consumptionKwh'] = round($consumptionKwh, 2);
        $result['hoursEvaluated'] = $hoursEvaluated;
        $result['costFixedEur'] = round($costFixed + $grundpreisAnteil, 2);
        $result['costDynamicEur'] = round($costDynamic + $grundpreisAnteil, 2);
        $result['savingsEur'] = round($result['costFixedEur'] - $result['costDynamicEur'], 2);
        $result['avgDynamicCtKwh'] = $consumptionKwh > 0 ? round($priceWeightedSum / $consumptionKwh, 3) : 0.0;
        $result['dataComplete'] = ($hoursMissingPrice === 0) && (count($hourlyKwh) > 0);

        $this->WriteAttributeString('LastEvaluation', json_encode($result));

        return $result;
    }

    // -----------------------------------------------------------------
    //  Szenario 2: Speichergröße
    // -----------------------------------------------------------------

    /**
     * Simuliert den historischen Lastgang (PV-Erzeugung/Hauslast, stündlich)
     * mit variabler virtueller Speichergröße und ermittelt den daraus
     * resultierenden Autarkiegrad sowie die Wirtschaftlichkeit je Größe.
     *
     * Vereinfachtes SoC-Modell (Phase 2, bewusst ohne Wirkungsgradverluste):
     * PV-Überschuss (PV > Last) lädt den virtuellen Speicher bis 100 % SoC,
     * Fehlbetrag (Last > PV) entlädt ihn bis 0 % SoC, darüber hinaus wird aus
     * dem Netz bezogen bzw. ins Netz eingespeist. Kein Modell für Lade-/
     * Entladeleistungsgrenzen — bei sehr kurzen Lastspitzen daher optimistisch.
     *
     * Rückgabe:
     *   'contractVersion' => '1.0',
     *   'periodDays'       => int,
     *   'periodFrom'/'periodTo' => int (Unix),
     *   'currentStorageKwh'=> float,  // aktuell konfigurierte Speichergröße (Referenzpunkt)
     *   'sizes'            => [
     *       [ 'storageKwh' => float, 'selfSufficiencyPercent' => float,
     *         'selfConsumptionPercent' => float, 'gridImportKwh' => float,
     *         'additionalKwhVsCurrent' => float, 'additionalSavingsEurPerYear' => float,
     *         'paybackYears' => float|null ],
     *       …
     *   ],
     *   'dataComplete'     => bool,
     */
    public function CalculateStorageSizeScenario(int $days): array
    {
        $result = [
            'contractVersion'   => '1.0',
            'periodDays'        => $days,
            'periodFrom'        => 0,
            'periodTo'          => 0,
            'currentStorageKwh' => $this->ReadPropertyFloat('SpeicherKwh'),
            'sizes'             => [],
            'dataComplete'      => false,
        ];

        if ($days <= 0) {
            return $result;
        }

        $pvVarID = $this->ReadPropertyInteger('PvErzeugungVarID');
        $lastVarID = $this->ReadPropertyInteger('HausLastVarID');
        if ($pvVarID <= 0 || $lastVarID <= 0) {
            $this->SendDebug(__FUNCTION__, 'PV-Erzeugungs-/Hauslastvariable nicht konfiguriert', 0);
            return $result;
        }

        $end = strtotime('today midnight');
        $start = $end - ($days * 86400);
        $result['periodFrom'] = $start;
        $result['periodTo'] = $end;

        $pvKwh = $this->hourlyKwhSeries($pvVarID, false, $start, $end);
        $lastKwh = $this->hourlyKwhSeries($lastVarID, false, $start, $end);
        if ($pvKwh === null || $lastKwh === null) {
            return $result;
        }

        $hours = array_unique(array_merge(array_keys($pvKwh), array_keys($lastKwh)));
        sort($hours);

        $fixedCtKwh = $this->ReadPropertyFloat('FestpreisCtKwh');
        $einspeiseCtKwh = $this->ReadPropertyFloat('EinspeiseverguetungCtKwh');
        $speicherPreis = $this->ReadPropertyFloat('SpeicherPreisEurKwh');

        $stepsKwh = [0.0, 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 80.0];
        $currentStorage = $result['currentStorageKwh'];
        $baselineGridImport = null;

        foreach ($stepsKwh as $storageKwh) {
            $soc = 0.0;
            $totalLoad = 0.0;
            $gridImport = 0.0;
            foreach ($hours as $h) {
                $pv = $pvKwh[$h] ?? 0.0;
                $load = $lastKwh[$h] ?? 0.0;
                $totalLoad += $load;
                $surplus = $pv - $load;
                if ($surplus >= 0) {
                    $soc = min($storageKwh, $soc + $surplus);
                    // Restüberschuss nach vollem Speicher wird eingespeist (hier nicht
                    // weiter gebraucht, Einspeisung fließt nicht in gridImport ein).
                } else {
                    $deficit = -$surplus;
                    $fromStorage = min($soc, $deficit);
                    $soc -= $fromStorage;
                    $gridImport += ($deficit - $fromStorage);
                }
            }

            if ($baselineGridImport === null && $storageKwh == 0.0) {
                $baselineGridImport = $gridImport;
            }

            $selfSufficiency = $totalLoad > 0 ? (1 - $gridImport / $totalLoad) * 100 : 0.0;

            $entry = [
                'storageKwh'                  => $storageKwh,
                'selfSufficiencyPercent'       => round($selfSufficiency, 1),
                'gridImportKwh'                => round($gridImport, 1),
                'additionalKwhVsCurrent'       => null,
                'additionalSavingsEurPerYear'  => null,
                'paybackYears'                 => null,
            ];
            $result['sizes'][] = $entry;
        }

        // Zusätzliche Ersparnis je Größe gegenüber der AKTUELL konfigurierten
        // Speichergröße (currentStorageKwh), nicht gegenüber 0 kWh — die Frage
        // ist "lohnt sich eine Vergrößerung", nicht "lohnt sich ein Speicher".
        $currentEntry = null;
        foreach ($result['sizes'] as $entry) {
            if (abs($entry['storageKwh'] - $currentStorage) < 0.01) {
                $currentEntry = $entry;
                break;
            }
        }
        $currentGridImport = $currentEntry['gridImportKwh'] ?? null;

        if ($currentGridImport !== null && $days > 0) {
            $yearFactor = 365.0 / $days;
            foreach ($result['sizes'] as &$entry) {
                $savedKwh = $currentGridImport - $entry['gridImportKwh'];
                $entry['additionalKwhVsCurrent'] = round($savedKwh, 1);
                $savingsPerYear = $savedKwh * $yearFactor * $fixedCtKwh / 100.0;
                $entry['additionalSavingsEurPerYear'] = round($savingsPerYear, 2);

                $additionalStorageKwh = $entry['storageKwh'] - $currentStorage;
                if ($additionalStorageKwh > 0.01 && $savingsPerYear > 0.01) {
                    $invest = $additionalStorageKwh * $speicherPreis;
                    $entry['paybackYears'] = round($invest / $savingsPerYear, 1);
                }
            }
            unset($entry);
        }

        $result['dataComplete'] = count($hours) > 0;
        return $result;
    }

    // -----------------------------------------------------------------
    //  Szenario 3: §14a-Beitritt
    // -----------------------------------------------------------------

    /**
     * Wägt die Netzentgelt-Ersparnis eines §14a-Beitritts gegen eine grob
     * monetarisierte Dimm-Annahme ab. REINE Nutzereingabe-Rechnung: SBH_GetState
     * (SteuerboxHub) liefert derzeit nur den Live-Zustand, keine Ereignis-Historie
     * (bestätigt EMS-Koordination 25.07.2026) — sobald eine Historisierung
     * existiert, kann auf echte Häufigkeit/Dauer umgestellt werden, siehe
     * KONZEPT.md Abschnitt 3.
     *
     * Dimm-Kosten-Schätzung: angenommene Ereignisse/Jahr × Dauer × angenommene
     * Lastreduktion (kW) ergibt eine "verhinderte" Energiemenge/Jahr, bewertet
     * zum mittleren Strompreis (dynamische Preiskurve falls TibberGridReward
     * vorhanden, sonst Festpreis) — eine GROBE Näherung für den Wert der
     * verschobenen/verhinderten Last, kein realer Schaden (die Energie wird ja
     * meist nur zeitlich verschoben, nicht vernichtet). Explizit als Näherung
     * gekennzeichnet ('dimmCostIsRoughEstimate' => true).
     *
     * Rückgabe:
     *   'contractVersion'          => '1.0',
     *   'netzentgeltErsparnisJahr' => float,  // € (Property-Eingabe)
     *   'referenzTibberJahr'       => float|null, // € aus TIBBERGR_GetTariffConfig, falls verfügbar (nur Vergleichswert)
     *   'angenommeneEreignisseJahr'=> int,
     *   'angenommeneDauerMinuten'  => int,
     *   'angenommeneReduktionKw'   => float,
     *   'betroffeneEnergieJahrKwh' => float,
     *   'mittlererPreisCtKwh'      => float,
     *   'dimmKostenSchaetzungJahr' => float,  // €, Näherung
     *   'nettoNutzenJahr'          => float,  // netzentgeltErsparnisJahr - dimmKostenSchaetzungJahr
     *   'dimmCostIsRoughEstimate'  => true,
     *   'sbhLiveHistorieVerfuegbar'=> bool,   // informativ, aktuell immer false
     */
    public function CalculateParagraph14aScenario(): array
    {
        $ersparnis = $this->ReadPropertyFloat('Paragraph14aNetzentgeltErsparnisJahr');
        $ereignisse = $this->ReadPropertyInteger('Paragraph14aAnnahmeEreignisseJahr');
        $dauerMinuten = $this->ReadPropertyInteger('Paragraph14aAnnahmeDauerMinuten');
        $reduktionKw = $this->ReadPropertyFloat('Paragraph14aAnnahmeReduktionKw');

        $referenzTibberJahr = null;
        if (function_exists('TIBBERGR_GetTariffConfig')) {
            $ids = @IPS_GetInstanceListByModuleID('{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');
            foreach (($ids ?: []) as $iid) {
                $cfg = @TIBBERGR_GetTariffConfig($iid);
                if (is_array($cfg) && ($cfg['paragraph14aEnabled'] ?? false)) {
                    $referenzTibberJahr = (float) ($cfg['paragraph14aReductionYear'] ?? 0.0);
                    break;
                }
            }
        }

        $betroffeneEnergieJahrKwh = $ereignisse * ($dauerMinuten / 60.0) * $reduktionKw;

        $mittlererPreisCtKwh = $this->ReadPropertyFloat('FestpreisCtKwh');
        if (function_exists('TIBBERGR_GetPriceCurve')) {
            $slots = @TIBBERGR_GetPriceCurve();
            if (is_array($slots) && count($slots) > 0) {
                $sum = 0.0;
                $n = 0;
                foreach ($slots as $slot) {
                    if (isset($slot['price'])) {
                        $sum += (float) $slot['price'];
                        $n++;
                    }
                }
                if ($n > 0) {
                    $mittlererPreisCtKwh = $sum / $n;
                }
            }
        }

        $dimmKostenSchaetzungJahr = $betroffeneEnergieJahrKwh * $mittlererPreisCtKwh / 100.0;

        return [
            'contractVersion'           => '1.0',
            'netzentgeltErsparnisJahr'  => round($ersparnis, 2),
            'referenzTibberJahr'        => $referenzTibberJahr !== null ? round($referenzTibberJahr, 2) : null,
            'angenommeneEreignisseJahr' => $ereignisse,
            'angenommeneDauerMinuten'   => $dauerMinuten,
            'angenommeneReduktionKw'    => $reduktionKw,
            'betroffeneEnergieJahrKwh'  => round($betroffeneEnergieJahrKwh, 1),
            'mittlererPreisCtKwh'       => round($mittlererPreisCtKwh, 2),
            'dimmKostenSchaetzungJahr'  => round($dimmKostenSchaetzungJahr, 2),
            'nettoNutzenJahr'           => round($ersparnis - $dimmKostenSchaetzungJahr, 2),
            'dimmCostIsRoughEstimate'   => true,
            'sbhLiveHistorieVerfuegbar' => false,
        ];
    }

    // -----------------------------------------------------------------
    //  Hilfsfunktionen
    // -----------------------------------------------------------------

    /**
     * Liefert kWh je Stunde (Unix-Stundenbeginn => kWh) für eine Archiv-Variable
     * im Zeitraum [$start, $end). $isCounter = true: Avg ist bereits der
     * Periodenverbrauch (kWh). $isCounter = false: Avg ist mittlere Leistung
     * (W), wird auf kWh der Stunde umgerechnet. Rückgabe null bei fehlendem
     * Archiv oder fehlenden Daten (Aufrufer bricht dann ab, statt mit einer
     * leeren/irreführenden Rechnung fortzufahren).
     */
    private function hourlyKwhSeries(int $varID, bool $isCounter, int $start, int $end): ?array
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            $this->SendDebug(__FUNCTION__, "Variable $varID fehlt/ungültig", 0);
            return null;
        }
        $archiveID = $this->getArchiveID($varID);
        if ($archiveID === 0) {
            $this->SendDebug(__FUNCTION__, "Variable $varID ist nicht archiviert", 0);
            return null;
        }
        $rows = AC_GetAggregatedValues($archiveID, $varID, 0 /* stündlich */, $start, $end, 0);
        if (!is_array($rows) || count($rows) === 0) {
            $this->SendDebug(__FUNCTION__, 'Keine Archivdaten im Zeitraum', 0);
            return null;
        }
        $hourlyKwh = [];
        foreach ($rows as $row) {
            $hourStart = (int) $row['TimeStamp'];
            $avg = (float) $row['Avg'];
            $hourlyKwh[$hourStart] = $isCounter ? $avg : ($avg / 1000.0);
        }
        return $hourlyKwh;
    }

    private function getArchiveID(int $varID): int
    {
        if (!IPS_ModuleExists('{43192F0B-135B-4CE7-A0A7-1475603F3060}')) {
            return 0;
        }
        $aid = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        foreach ($aid as $id) {
            if (@AC_GetLoggingStatus($id, $varID)) {
                return $id;
            }
        }
        return 0;
    }
}
