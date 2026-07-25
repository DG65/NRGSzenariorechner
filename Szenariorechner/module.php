<?php

// ===========================================================================
// Szenariorechner — "Was wäre wenn?"-Wirtschaftlichkeitsrechner für den
// NRG-Stack. Reiner Rechner, kein Regler: liest historische Verbrauchsdaten
// (Archive Control) und Marktdaten anderer Verbund-Module und liefert
// strukturierte Rückgabewerte je Szenario-Typ. Setzt selbst nichts durch.
//
// Konzept und Phasenplan: siehe KONZEPT.md im Repo-Wurzelverzeichnis.
//
// Phase 1 (dieses Modul, Stand): dynamischer Vertrag vs. Festpreis, auf Basis
// des bestehenden historischen Netzbezugs (AC_GetAggregatedValues) und der
// TibberGridReward-Preiskurve (TIBBERGR_GetPriceCurve). Weitere Szenarien
// (Speichergröße, §14a-Beitritt, Förderende/Solarspitzengesetz) folgen als
// eigene SZR_Calculate*Scenario()-Funktionen in späteren Phasen.
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

        $this->RegisterAttributeString('LastEvaluation', '{}');
        $this->RegisterAttributeString('ChangelogSeen', '');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->ReadAttributeString('ChangelogSeen') === '0.1') {
            foreach ($form['elements'] as &$el) {
                if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Neu in Version')) {
                    $el['expanded'] = false;
                    $el['visible'] = false;
                }
            }
            unset($el);
        }
        return json_encode($form);
    }

    public function DismissChangelog(string $version)
    {
        $this->WriteAttributeString('ChangelogSeen', $version);
        $this->UpdateFormField('ChangelogPanel', 'visible', false);
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

        $archiveID = $this->getArchiveID($varID);
        if ($archiveID === 0) {
            $this->SendDebug(__FUNCTION__, 'Netzbezugsvariable ist nicht archiviert', 0);
            return $result;
        }

        $end = strtotime('today midnight');
        $start = $end - ($days * 86400);
        $result['periodFrom'] = $start;
        $result['periodTo'] = $end;

        // Netzbezug je Stunde (kWh) aus dem Archiv.
        $rows = AC_GetAggregatedValues($archiveID, $varID, 0 /* stündlich */, $start, $end, 0);
        if (!is_array($rows) || count($rows) === 0) {
            $this->SendDebug(__FUNCTION__, 'Keine Archivdaten im Zeitraum', 0);
            return $result;
        }

        $isCounter = $this->ReadPropertyBoolean('NetzbezugIstZaehler');
        $hourlyKwh = [];
        foreach ($rows as $row) {
            $hourStart = (int) $row['TimeStamp'];
            $avg = (float) $row['Avg'];
            // Zähler: Avg ist bereits der Verbrauch der Stunde (kWh).
            // Leistungsvariable (W): Avg ist mittlere Leistung -> * 1h in kWh.
            $hourlyKwh[$hourStart] = $isCounter ? $avg : ($avg / 1000.0);
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
    //  Hilfsfunktionen
    // -----------------------------------------------------------------

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
