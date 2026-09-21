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
// SZR_Calculate*Scenario()-Funktionen in späteren Phasen — Datenbasis dafür
// ist jetzt EMS_GetPlantInfo() (foerderende/eegFassung/pflichten), siehe
// getPlantInfo() unten.
// ===========================================================================

class Szenariorechner extends IPSModule
{
    // EMS führt die Anlagenstammdaten jetzt zentral (EMS 0.34.0, Vertrag
    // 'plantinfo' 1.0) — Modul-GUID der Zielinstanz, nicht per Präfix raten.
    private const EMS_MODULE_GUID = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}';
    private const TIBBER_MODULE_GUID = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';

    public function Create()
    {
        parent::Create();

        // ── Anlagendaten — NUR Ersatzfeld, wenn kein EMS installiert ist
        // oder EMS_GetPlantInfo() keine Angabe liefert (getPlantInfo()/
        // get*()-Zugriffsmethoden unten lösen EMS > eigene Property auf).
        // Standardwerte bewusst "nicht angegeben" statt Dietmars eigener
        // Anlage (Verbund-Regel "keine eigene Anlage als Norm", Rückmeldung
        // EMS-Koordination 13.09.2026).
        $this->RegisterPropertyInteger('EmsInstanceID', 0);
        $this->RegisterPropertyFloat('PvKwp', 0.0);
        $this->RegisterPropertyFloat('WrKw', 0.0);
        $this->RegisterPropertyFloat('SpeicherKwh', 0.0);
        $this->RegisterPropertyFloat('EinspeiseverguetungCtKwh', 0.0);
        // Format TT.MM.JJJJ (Verbund-Regel 9b), altes JJJJ-MM-TT wird beim
        // Lesen weiterhin erkannt (parseAnlageDatum()).
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

        // ── Netztransparenz.de-Zugang (Vorbereitung Szenario 4) ──
        // Client_ID/Secret sind dauerhaft benötigte Zugangsdaten (OAuth2
        // Client-Credentials, kein einmaliger Handshake) und gehören daher als
        // Attribut gespeichert, nicht als Property (Memory nrg-stack-credentials).
        // Eingabe über maskierte Einmal-Property-Felder, die ApplyChanges in die
        // Attribute überträgt und danach leert.
        $this->RegisterPropertyString('NetztransparenzClientIdInput', '');
        $this->RegisterPropertyString('NetztransparenzClientSecretInput', '');
        $this->RegisterAttributeString('NetztransparenzClientId', '');
        $this->RegisterAttributeString('NetztransparenzClientSecret', '');
        $this->RegisterAttributeString('NetztransparenzToken', '');
        $this->RegisterAttributeInteger('NetztransparenzTokenExpires', 0);
        $this->RegisterAttributeInteger('NetztransparenzLastTestSuccess', 0);
        $this->RegisterAttributeString('NetztransparenzLastTestError', '');

        $this->RegisterAttributeString('LastEvaluation', '{}');
        $this->RegisterAttributeString('ChangelogSeen', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterVariables();

        // Täglich alle verfügbaren Szenarien neu rechnen, damit die
        // Kern-Ergebnisvariablen nicht dauerhaft veraltet stehen bleiben —
        // reiner Komfort für den schnellen Blick auf die Instanz, die
        // eigentliche Darstellung bleibt Sache des Dashboard-Moduls.
        $this->RegisterTimer('RefreshScenarios', 0, 'SZR_RefreshScenarioVariables($_IPS[\'TARGET\']);');
    }

    /**
     * Ergebnisvariablen (Rückmeldung Dietmar, 27.07.2026: ohne diese ist auf
     * der Instanz selbst nichts sichtbar). Bewusst nur eine Kern-Kennzahl je
     * Szenario, keine vollständige Ergebnisdarstellung — die bleibt Sache des
     * Dashboard-Moduls (siehe KONZEPT.md, Abschnitt "Ergebnis-Darstellung").
     */
    private function RegisterVariables(): void
    {
        $pos = 0;
        $this->MaintainVariable('NetztransparenzStatus', $this->Translate('Netztransparenz connection status'), VARIABLETYPE_STRING, '', $pos++, true);
        $this->MaintainVariable('DynamicTariffSavingsEur', $this->Translate('Savings dynamic tariff vs. fixed price (last 30 days, EUR)'), VARIABLETYPE_FLOAT, '', $pos++, true);
        $this->MaintainVariable('StorageSizeAdditionalSavingsEur', $this->Translate('Best additional storage size saving found (EUR/year)'), VARIABLETYPE_FLOAT, '', $pos++, true);
        $this->MaintainVariable('Paragraph14aNetBenefitEur', $this->Translate('Section 14a net benefit (EUR/year)'), VARIABLETYPE_FLOAT, '', $pos++, true);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->setFormElement($form['elements'], 'ChangelogPanel', ['visible' => $this->ReadAttributeString('ChangelogSeen') !== '0.6']);
        $this->setFormElement($form['elements'], 'ForumHint', ['visible' => !$this->ReadAttributeBoolean('ForumHintGone')]);
        $this->setFormElement($form['elements'], 'DocVersionLabel', ['caption' => $this->buildVersionCaption()]);
        $this->setFormElement($form['elements'], 'NetztransparenzStatusLabel', ['caption' => $this->buildNetztransparenzStatusCaption()]);
        $this->setFormElement($form['elements'], 'PlantInfoStatusLabel', ['caption' => $this->buildPlantInfoStatusCaption()]);
        $this->setFormElement($form['elements'], 'TibberStatusLabel', ['caption' => $this->buildTibberStatusCaption()]);

        return json_encode($form);
    }

    /**
     * Setzt Eigenschaften am benannten Formularelement — sucht REKURSIV über alle
     * `items` (ExpansionPanel, RowLayout, …). Nur oberste Ebene zu durchsuchen war
     * der Fehler, durch den Statuszeilen in Panels nie ersetzt wurden (21.09.2026).
     * Rückgabe true, wenn das Element gefunden wurde.
     */
    private function setFormElement(array &$items, string $name, array $props): bool
    {
        foreach ($items as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? '') === $name) {
                foreach ($props as $k => $v) {
                    $el[$k] = $v;
                }
                return true;
            }
            if (isset($el['items']) && is_array($el['items']) && $this->setFormElement($el['items'], $name, $props)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sammel-Getter für die Dashboard-Kopplung (abgestimmt 25.07.2026): listet
     * alle Szenario-Typen mit Anzeigename und Verfügbarkeit, damit der
     * Konsument neue Szenarien nicht fest verdrahten muss (Discovery-Rolle,
     * analog den Hub-Verträgen). Jeder Eintrag benennt die zugehörige
     * `SZR_Calculate*Scenario()`-Funktion; deren Feldlisten stehen als PHPDoc
     * direkt über der jeweiligen Funktion.
     *
     * Rückgabe je Eintrag:
     *   'type'            => string,  // stabiler Schlüssel, Teil des Vertrags
     *   'label'            => string,  // deutscher Anzeigename
     *   'function'         => string,  // Funktionsname ohne SZR_-Präfix
     *   'contractVersion'  => '1.0',
     *   'available'        => bool,    // Voraussetzungen erfüllt?
     *   'reason'           => string,  // bei available=false: was fehlt (deutsch)
     */
    public function GetAvailableScenarios(): array
    {
        $scenarios = [];

        $tibberOk = function_exists('TIBBERGR_GetPriceCurve');
        $netzbezugOk = $this->ReadPropertyInteger('NetzbezugVarID') > 0;
        $scenarios[] = [
            'type'            => 'dynamicTariff',
            'label'           => 'Dynamischer Vertrag vs. Festpreis',
            'function'        => 'CalculateDynamicTariffScenario',
            'contractVersion' => '1.0',
            'available'       => $tibberOk && $netzbezugOk,
            'reason'          => !$netzbezugOk
                ? 'Netzbezugsvariable nicht konfiguriert'
                : (!$tibberOk ? 'TibberGridRewards nicht gefunden' : ''),
        ];

        $storageVarsOk = $this->ReadPropertyInteger('PvErzeugungVarID') > 0
            && $this->ReadPropertyInteger('HausLastVarID') > 0;
        $scenarios[] = [
            'type'            => 'storageSize',
            'label'           => 'Speichergröße',
            'function'        => 'CalculateStorageSizeScenario',
            'contractVersion' => '1.0',
            'available'       => $storageVarsOk,
            'reason'          => $storageVarsOk ? '' : 'PV-Erzeugungs-/Hauslastvariable nicht konfiguriert',
        ];

        // §14a-Beitritt ist reine Nutzereingabe (keine Fremdmodul-Voraussetzung),
        // daher immer verfügbar — siehe KONZEPT.md Abschnitt 3.
        $scenarios[] = [
            'type'            => 'paragraph14a',
            'label'           => '§14a-Beitritt',
            'function'        => 'CalculateParagraph14aScenario',
            'contractVersion' => '1.0',
            'available'       => true,
            'reason'          => '',
        ];

        return $scenarios;
    }

    /**
     * Prüft die hinterlegten Netztransparenz-Zugangsdaten mit einem echten
     * Token- und Datenabruf (aktueller Monat, Marktwert Solar) und schreibt
     * Erfolg/Fehler samt Zeitpunkt ins Attribut, damit ein Laie im Formular
     * eine verständliche Erfolgsmeldung statt eines rohen Instanzstatus sieht
     * (Rückmeldung Dietmar/EMS-Koordination, 25.07.2026). Wird automatisch
     * nach jeder Übernahme neuer Zugangsdaten aufgerufen (ApplyChanges) und
     * kann zusätzlich manuell über den Formular-Button angestoßen werden.
     */
    public function TestNetztransparenzConnection(): array
    {
        $now = time();
        $error = '';

        $token = $this->getNetztransparenzToken();
        if ($token === null) {
            $error = 'Zugangsdaten fehlen oder Token-Abruf fehlgeschlagen';
        } else {
            // Letzten VOLLSTÄNDIGEN Monat abfragen, nicht den laufenden:
            // Monatsmarktwerte werden laut API-Doku nur 1×/Monat veröffentlicht,
            // der laufende Monat liefert daher regelmäßig eine leere Antwort,
            // die sonst fälschlich als Verbindungsfehler gedeutet würde.
            $lastMonthTs = strtotime('first day of last month', $now);
            $year = (int) date('Y', $lastMonthTs);
            $month = (int) date('n', $lastMonthTs);
            $fetchError = null;
            $rows = $this->getMarktwertSolar($year, $month, $year, $month, $fetchError);
            if ($rows === null) {
                $error = 'Token-Abruf erfolgreich, aber Testabruf der Marktwerte fehlgeschlagen'
                    . ($fetchError !== null ? " ($fetchError)" : '');
            } elseif (count($rows) === 0) {
                $error = "Token-Abruf erfolgreich, aber keine Marktwerte für $month/$year erhalten (evtl. noch nicht veröffentlicht)";
            }
        }

        if ($error === '') {
            $this->WriteAttributeInteger('NetztransparenzLastTestSuccess', $now);
            $this->WriteAttributeString('NetztransparenzLastTestError', '');
        } else {
            $this->WriteAttributeString('NetztransparenzLastTestError', $error);
            $this->SendDebug(__FUNCTION__, $error, 0);
        }

        $caption = $this->buildNetztransparenzStatusCaption();
        $this->SetValue('NetztransparenzStatus', $caption);

        // Nur wirksam, wenn das Formular gerade offen ist (Button-Aufruf) —
        // bei automatischem Aufruf aus ApplyChanges ist das Formular meist
        // geschlossen, UpdateFormField schlägt dann harmlos fehl (@).
        @$this->UpdateFormField('NetztransparenzStatusLabel', 'caption', $caption);

        return ['success' => $error === '', 'error' => $error, 'testedAt' => $error === '' ? $now : null];
    }

    private function buildNetztransparenzStatusCaption(): string
    {
        $hasCredentials = $this->ReadAttributeString('NetztransparenzClientId') !== ''
            && $this->ReadAttributeString('NetztransparenzClientSecret') !== '';
        if (!$hasCredentials) {
            return 'Zugangsdaten fehlen — Szenario "Förderende/Solarspitzengesetz" noch nicht verfügbar.';
        }

        $lastSuccess = $this->ReadAttributeInteger('NetztransparenzLastTestSuccess');
        $lastError = $this->ReadAttributeString('NetztransparenzLastTestError');

        if ($lastError !== '') {
            $suffix = $lastSuccess > 0 ? ' (zuletzt erfolgreich: ' . date('d.m.Y H:i', $lastSuccess) . ')' : '';
            return "⚠️ Verbindung fehlgeschlagen: $lastError$suffix";
        }
        if ($lastSuccess > 0) {
            return '✅ Verbindung zu Netztransparenz.de erfolgreich getestet, letzter Abruf: ' . date('d.m.Y H:i', $lastSuccess);
        }
        return 'Zugangsdaten hinterlegt, aber noch nicht getestet — Schaltfläche "Verbindung testen" verwenden.';
    }

    /**
     * Übernimmt einmalig eingegebene Netztransparenz-Zugangsdaten aus den
     * maskierten Property-Feldern ins Attribut und leert die Property-Felder
     * anschließend. Rückgabe true, wenn eine Übernahme stattgefunden hat (der
     * Aufrufer bricht dann seinen eigenen ApplyChanges-Durchlauf ab, siehe dort).
     */
    private function takeOverNetztransparenzCredentials(): bool
    {
        $idInput = $this->ReadPropertyString('NetztransparenzClientIdInput');
        $secretInput = $this->ReadPropertyString('NetztransparenzClientSecretInput');
        if ($idInput === '' && $secretInput === '') {
            return false;
        }
        if ($idInput !== '') {
            $this->WriteAttributeString('NetztransparenzClientId', $idInput);
        }
        if ($secretInput !== '') {
            $this->WriteAttributeString('NetztransparenzClientSecret', $secretInput);
        }
        // Token-Cache verwerfen, da sich die Zugangsdaten geändert haben.
        $this->WriteAttributeString('NetztransparenzToken', '');
        $this->WriteAttributeInteger('NetztransparenzTokenExpires', 0);

        IPS_SetProperty($this->InstanceID, 'NetztransparenzClientIdInput', '');
        IPS_SetProperty($this->InstanceID, 'NetztransparenzClientSecretInput', '');
        IPS_ApplyChanges($this->InstanceID);

        // Sofort testen, statt den Nutzer raten zu lassen, ob die neu
        // eingegebenen Zugangsdaten funktionieren (Rückmeldung Dietmar/EMS-
        // Koordination, 25.07.2026).
        $this->TestNetztransparenzConnection();
        return true;
    }

    // Versionszeile im Doku-Panel dauerhaft sichtbar (Verbund-Konvention), aus
    // der Bibliothek ermittelt statt fest im Formular verdrahtet.
    private function buildVersionCaption(): string
    {
        $lib = @IPS_GetLibrary('{9B2E1A3F-6C7D-4E8B-9A1C-2D3E4F5A6B7C}');
        return (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Szenariorechner Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Szenariorechner';
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

        if ($this->takeOverNetztransparenzCredentials()) {
            // Eigenen Aufruf sofort beenden: takeOverNetztransparenzCredentials()
            // hat die Eingabefelder geleert und einen neuen ApplyChanges-Durchlauf
            // angestoßen, der den Status unten mit sauberem Property-Stand setzt.
            return;
        }

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

        $this->SetTimerInterval('RefreshScenarios', 24 * 60 * 60 * 1000);
        // Sofort einmal rechnen statt bis zu 24h auf den ersten Wert zu warten.
        try {
            $this->RefreshScenarioVariables();
        } catch (\Throwable $e) {
            // Ein Rechenfehler darf das Anlegen/Speichern der Instanz nie verhindern.
            $this->SendDebug(__FUNCTION__, 'Sofortberechnung fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Rechnet alle verfügbaren Szenarien einmal durch und schreibt je Szenario
     * eine Kern-Kennzahl in die zugehörige Ergebnisvariable (siehe
     * RegisterVariables()). Läuft täglich per Timer, zusätzlich sofort nach
     * jedem ApplyChanges. Nicht verfügbare Szenarien (siehe
     * GetAvailableScenarios()) werden übersprungen, ihre Variable bleibt beim
     * letzten bekannten Wert stehen.
     */
    public function RefreshScenarioVariables(): void
    {
        foreach ($this->GetAvailableScenarios() as $scenario) {
            if (!$scenario['available']) {
                continue;
            }
            switch ($scenario['type']) {
                case 'dynamicTariff':
                    $this->CalculateDynamicTariffScenario(30);
                    break;
                case 'storageSize':
                    $this->CalculateStorageSizeScenario(30);
                    break;
                case 'paragraph14a':
                    $this->CalculateParagraph14aScenario();
                    break;
            }
        }

        if ($this->ReadAttributeString('NetztransparenzClientId') !== ''
            && $this->ReadAttributeString('NetztransparenzClientSecret') !== '') {
            $this->TestNetztransparenzConnection();
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
        $slots = $this->getTibberPriceCurve();
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
        $this->SetValue('DynamicTariffSavingsEur', $result['savingsEur']);

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
            'currentStorageKwh' => $this->getSpeicherKwh(),
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
        $einspeiseCtKwh = $this->getVerguetungCt();
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

        // Kern-Kennzahl für die Ergebnisvariable: die beste gefundene
        // Zusatzersparnis unter den GRÖSSEREN Stufen als der aktuellen
        // Speichergröße (0, wenn keine Vergrößerung sich lohnt).
        $bestAdditionalSavings = 0.0;
        foreach ($result['sizes'] as $entry) {
            if ($entry['storageKwh'] > $currentStorage
                && ($entry['additionalSavingsEurPerYear'] ?? 0.0) > $bestAdditionalSavings) {
                $bestAdditionalSavings = $entry['additionalSavingsEurPerYear'];
            }
        }
        $this->SetValue('StorageSizeAdditionalSavingsEur', $bestAdditionalSavings);

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
            $ids = @IPS_GetInstanceListByModuleID(self::TIBBER_MODULE_GUID);
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
        {
            $slots = $this->getTibberPriceCurve();
            if (count($slots) > 0) {
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
        $nettoNutzenJahr = round($ersparnis - $dimmKostenSchaetzungJahr, 2);
        $this->SetValue('Paragraph14aNetBenefitEur', $nettoNutzenJahr);

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
            'nettoNutzenJahr'           => $nettoNutzenJahr,
            'dimmCostIsRoughEstimate'   => true,
            'sbhLiveHistorieVerfuegbar' => false,
        ];
    }

    // -----------------------------------------------------------------
    //  Netztransparenz.de-Client (Vorbereitung Szenario 4)
    // -----------------------------------------------------------------
    // NICHT als eigenständige Kopplung hinter function_exists() zu sichern
    // (das gilt für Verbund-Module) — das ist eine echte externe HTTP-API,
    // daher direkte Fehlerbehandlung statt Guard. Client_ID/Secret siehe
    // Create()/takeOverNetztransparenzCredentials(). Endpunkt-Pfade gegen die
    // öffentliche Swagger-UI verifiziert (27.07.2026, siehe KONZEPT.md
    // Abschnitt Netztransparenz.de-API — PFAD-Segmente, nicht Query-String,
    // abweichend von der älteren PDF-Doku).

    private const NETZTRANSPARENZ_TOKEN_URL = 'https://identity.netztransparenz.de/users/connect/token';
    private const NETZTRANSPARENZ_BASE_URL = 'https://ds.netztransparenz.de/api/v1/data';

    /**
     * Liefert einen gültigen Access-Token (Client-Credentials-Flow), aus dem
     * Attribut-Cache falls noch gültig (Token 1h gültig, 60 s Sicherheitsmarge).
     * Rückgabe null ohne konfigurierte Zugangsdaten oder bei Fehler.
     */
    private function getNetztransparenzToken(): ?string
    {
        $clientId = $this->ReadAttributeString('NetztransparenzClientId');
        $clientSecret = $this->ReadAttributeString('NetztransparenzClientSecret');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cached = $this->ReadAttributeString('NetztransparenzToken');
        $expires = $this->ReadAttributeInteger('NetztransparenzTokenExpires');
        if ($cached !== '' && $expires > (time() + 60)) {
            return $cached;
        }

        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents(self::NETZTRANSPARENZ_TOKEN_URL, false, $context);
        if ($response === false) {
            $this->SendDebug(__FUNCTION__, 'Token-Anfrage fehlgeschlagen (kein Response)', 0);
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            $this->SendDebug(__FUNCTION__, 'Token-Antwort ohne access_token: ' . $response, 0);
            return null;
        }

        $token = (string) $data['access_token'];
        $ttl = (int) ($data['expires_in'] ?? 3600);
        $this->WriteAttributeString('NetztransparenzToken', $token);
        $this->WriteAttributeInteger('NetztransparenzTokenExpires', time() + $ttl);
        return $token;
    }

    /**
     * Ruft einen Netztransparenz-Datenendpunkt ab (CSV-Antwort, Semikolon-
     * getrennt) und liefert die geparsten Zeilen als assoziative Arrays
     * (Kopfzeile als Schlüssel). Rückgabe null ohne Token oder bei Fehler.
     */
    /**
     * $errorOut (Referenz) erhält bei Rückgabe null einen menschenlesbaren
     * Grund — insbesondere den HTTP-Status, damit "Endpunkt/Berechtigung
     * falsch" (4xx) von "für den Zeitraum liegen noch keine Daten vor" (leerer
     * 200er-Body, z. B. laufender Monat, Monatsmarktwerte werden nur 1×/Monat
     * veröffentlicht) unterscheidbar ist.
     */
    private function fetchNetztransparenzCsv(string $path, array $query = [], ?string &$errorOut = null, ?int &$statusOut = null): ?array
    {
        $token = $this->getNetztransparenzToken();
        if ($token === null) {
            $errorOut = 'kein gültiger Token';
            return null;
        }
        $url = self::NETZTRANSPARENZ_BASE_URL . '/' . ltrim($path, '/');
        if (count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer $token\r\n",
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $statusCode = 0;
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m)) {
            $statusCode = (int) $m[1];
        }
        $statusOut = $statusCode;

        if ($response === false) {
            $errorOut = "keine Antwort (HTTP $statusCode, $url)";
            $this->SendDebug(__FUNCTION__, $errorOut, 0);
            return null;
        }
        if ($statusCode >= 400) {
            $errorOut = "HTTP $statusCode von $url" . ($response !== '' ? (': ' . substr($response, 0, 200)) : '');
            $this->SendDebug(__FUNCTION__, $errorOut, 0);
            return null;
        }
        if (trim($response) === '') {
            // Kein Fehler, aber keine Daten für den angefragten Zeitraum
            // (z. B. laufender Monat, Monatsmarktwerte erscheinen erst danach).
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response));
        if (count($lines) < 2) {
            return [];
        }
        $header = str_getcsv(array_shift($lines), ';');
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, ';');
            $rows[] = array_combine($header, array_pad($cols, count($header), null));
        }
        return $rows;
    }

    /**
     * Marktwert Solar (Monatsmarktwerte, Format 12) für den angegebenen
     * Monatsbereich. Rückgabe je Monat: 'monat' (String "M/JJJJ"),
     * 'marktwertSolarCtKwh' (float), 'negativePreise1h'/'2h'/'3h'/'4h'/'6h' (bool).
     * Rückgabe null ohne Zugangsdaten oder bei Fehler.
     */
    private function getMarktwertSolar(int $yearFrom, int $monthFrom, int $yearTo, int $monthTo, ?string &$errorOut = null): ?array
    {
        // Live-API (öffentliche Swagger-UI, https://ds.netztransparenz.de,
        // Stand 27.07.2026) erwartet PFAD-Segmente in dieser Reihenfolge,
        // NICHT Query-Parameter:
        //   GET /api/v1/data/marktpraemie/{monthFrom}/{yearFrom}/{monthTo}/{yearTo}
        // Weicht von der älteren PDF-Doku (v1.14, query-string yearFrom=/
        // monthFrom=) ab — daher der ursprüngliche 404. Andere Endpunkte in
        // dieser API-Familie (Jahresmarktpraemie/{year}, redispatch/{dateFrom}/
        // {dateTo} usw.) folgen demselben Pfad-Segment-Muster.
        $path = sprintf(
            'marktpraemie/%s/%d/%s/%d',
            str_pad((string) $monthFrom, 2, '0', STR_PAD_LEFT),
            $yearFrom,
            str_pad((string) $monthTo, 2, '0', STR_PAD_LEFT),
            $yearTo
        );

        $status = null;
        $rows = $this->fetchNetztransparenzCsv($path, [], $errorOut, $status);
        if ($rows === null) {
            return null;
        }
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'monat'               => $row['Monat'] ?? '',
                'marktwertSolarCtKwh' => isset($row['MW Solar in ct/kWh']) ? (float) str_replace(',', '.', $row['MW Solar in ct/kWh']) : null,
                // Spaltenname seit API-Format-12-Anpassung (Doku-Historie 1.18,
                // 14.01.2026) "Negative Preise (XH)", nicht mehr "Negative
                // Stunden (XH)" wie im ursprünglich zugrunde gelegten Stand
                // v1.14 (07.02.2025).
                'negativePreise1h'    => ($row['Negative Preise (1H)'] ?? '') === 'Ja',
                'negativePreise2h'    => ($row['Negative Preise (2H)'] ?? '') === 'Ja',
                'negativePreise3h'    => ($row['Negative Preise (3H)'] ?? '') === 'Ja',
                'negativePreise4h'    => ($row['Negative Preise (4H)'] ?? '') === 'Ja',
                'negativePreise6h'    => ($row['Negative Preise (6H)'] ?? '') === 'Ja',
            ];
        }
        return $result;
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

    /**
     * Preiskurve der ersten TibberGridRewards-Instanz, die eine liefert.
     * TIBBERGR_GetPriceCurve verlangt die Instanz-ID (Vertrag: `(int $id): array`) —
     * ein Aufruf ohne Argument ist in PHP 8 ein Fatal Error, den `@` nicht abfängt.
     * Rückgabe: ['id' => Instanz (0 = keine mit Preisen), 'slots' => Liste].
     */
    private function findTibberPriceCurve(): array
    {
        if (!function_exists('TIBBERGR_GetPriceCurve')) {
            return ['id' => 0, 'slots' => []];
        }
        foreach ((@IPS_GetInstanceListByModuleID(self::TIBBER_MODULE_GUID) ?: []) as $iid) {
            $slots = @TIBBERGR_GetPriceCurve($iid);
            if (is_array($slots) && count($slots) > 0) {
                return ['id' => (int) $iid, 'slots' => $slots];
            }
        }
        return ['id' => 0, 'slots' => []];
    }

    private function getTibberPriceCurve(): array
    {
        return $this->findTibberPriceCurve()['slots'];
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

    // -----------------------------------------------------------------
    //  Anlagendaten — EMS_GetPlantInfo() als führende Quelle
    // -----------------------------------------------------------------
    // EMS führt die Anlagendaten seit 0.34.0 zentral (Vertrag 'plantinfo'
    // 1.0, rein lesend), statt dass EMS/Szenariorechner/Dashboard sie
    // dreifach halten. Eigenständigkeitsregel: fehlt EMS oder liefert die
    // Major nicht (contractVersion), fällt jede get*()-Methode auf die
    // eigene Property zurück — nie hart abbrechen.

    /** @var array|null Request-lokaler Cache, damit ein Aufruf max. 1x EMS fragt. */
    private $plantInfoCache = null;
    /** @var array Verbindungszustand zu EMS: state ok|none|multiple|contract, id, ids, contract. */
    private $plantInfoConn = ['state' => 'none', 'id' => 0, 'ids' => [], 'contract' => ''];

    private function getPlantInfo(): ?array
    {
        if ($this->plantInfoCache !== null) {
            return $this->plantInfoCache ?: null;
        }
        $this->plantInfoCache = false;
        $this->plantInfoConn = ['state' => 'none', 'id' => 0, 'ids' => [], 'contract' => ''];
        if (!function_exists('EMS_GetPlantInfo')) {
            return null;
        }
        $ids = array_values(@IPS_GetInstanceListByModuleID(self::EMS_MODULE_GUID) ?: []);
        $this->plantInfoConn['ids'] = $ids;
        if (count($ids) === 0) {
            return null;
        }
        // Ausdrückliche Wahl gewinnt; sonst nur bei genau EINER Instanz automatisch —
        // bei mehreren wird nicht geraten (Verbund-Konvention Verbindungsstatus).
        $selected = $this->ReadPropertyInteger('EmsInstanceID');
        if ($selected > 0 && in_array($selected, $ids, true)) {
            $candidates = [$selected];
        } elseif (count($ids) > 1) {
            $this->plantInfoConn['state'] = 'multiple';
            return null;
        } else {
            $candidates = $ids;
        }
        foreach ($candidates as $id) {
            $info = @EMS_GetPlantInfo($id);
            if (!is_array($info)) {
                continue;
            }
            $ver = (string) ($info['contractVersion'] ?? '1.0');
            if (str_starts_with($ver, '1.')) {
                $this->plantInfoCache = $info;
                $this->plantInfoConn = ['state' => 'ok', 'id' => $id, 'ids' => $ids, 'contract' => $ver];
                return $info;
            }
            $this->plantInfoConn = ['state' => 'contract', 'id' => $id, 'ids' => $ids, 'contract' => $ver];
        }
        return null;
    }

    private const QUELLE_TEXT = [
        'eingetragen'    => 'im EMS eingetragen',
        'prognose'       => 'PV-Prognose',
        'wechselrichter' => 'Wechselrichter gemessen',
        'einstellung'    => 'EMS-Einstellung',
        'variable'       => 'EMS-Variable',
        'berechnet'      => 'aus EEG-Tabelle berechnet',
    ];

    private function quelleText(string $q): string
    {
        return self::QUELLE_TEXT[$q] ?? $q;
    }

    // Auflösung je Wert liefert [Wert, Quelle als Klartext]. get*() und die
    // Statuszeile nutzen dieselbe Stelle, damit angezeigt wird, was tatsächlich gilt.

    /** @return array{0: float, 1: string} kWp: EMS > eigene Eingabe > nicht angegeben. */
    private function resolveKwp(): array
    {
        $info = $this->getPlantInfo();
        if ($info !== null && ($info['kwp'] ?? 0.0) > 0.0) {
            return [(float) $info['kwp'], 'EMS: ' . $this->quelleText((string) ($info['kwpQuelle'] ?? ''))];
        }
        $own = $this->ReadPropertyFloat('PvKwp');
        return [$own, $own > 0.0 ? 'eigene Eingabe' : 'nicht angegeben'];
    }

    /**
     * Speicherkapazität (kWh) — Referenzpunkt für das Speichergrößen-Szenario.
     * EMS liefert seit 0.34.2 (plantinfo 1.1) `speicherKwh`/`speicherKwhQuelle`:
     * 'wechselrichter' (über InverterHub gemessen, `bat_capacity`) ist
     * vertrauenswürdig und hat Vorrang. 'einstellung' (EMS-Property
     * BAT_Capacity_kWh) kann laut EMS der nie geänderte Standardwert 10 kWh
     * sein — EMS kann eine bewusste Eingabe nicht von diesem Default
     * unterscheiden. Deshalb bei 'einstellung' die EIGENE Eingabe vorziehen,
     * wenn sie gesetzt ist; ist auch sie 0, ist der EMS-Wert immer noch besser
     * als gar keiner. 'fehlt' (0) fällt auf die eigene Eingabe zurück.
     *
     * @return array{0: float, 1: string}
     */
    private function resolveSpeicherKwh(): array
    {
        $info = $this->getPlantInfo();
        $own = $this->ReadPropertyFloat('SpeicherKwh');
        $ownQ = $own > 0.0 ? 'eigene Eingabe' : 'nicht angegeben';
        if ($info === null) {
            return [$own, $ownQ];
        }
        $quelle = (string) ($info['speicherKwhQuelle'] ?? 'fehlt');
        $ems = (float) ($info['speicherKwh'] ?? 0.0);
        if ($quelle === 'wechselrichter' && $ems > 0.0) {
            return [$ems, 'EMS: ' . $this->quelleText($quelle)];
        }
        if ($quelle === 'einstellung' && $ems > 0.0) {
            return $own > 0.0
                ? [$own, 'eigene Eingabe, EMS-Einstellung nicht übernommen']
                : [$ems, 'EMS: ' . $this->quelleText($quelle) . ', unbestätigt'];
        }
        return [$own, $ownQ];
    }

    /** @return array{0: float, 1: string} Einspeisevergütung (ct/kWh): EMS > eigene Eingabe. */
    private function resolveVerguetungCt(): array
    {
        $info = $this->getPlantInfo();
        if ($info !== null && ($info['verguetungQuelle'] ?? 'platzhalter') !== 'platzhalter') {
            $q = 'EMS: ' . $this->quelleText((string) $info['verguetungQuelle']);
            if (($info['verguetungQuelle'] ?? '') === 'berechnet' && !empty($info['verguetungGeprueft'])) {
                $q .= ', geprüft';
            }
            return [(float) ($info['verguetungCt'] ?? 0.0), $q];
        }
        $own = $this->ReadPropertyFloat('EinspeiseverguetungCtKwh');
        return [$own, $own > 0.0 ? 'eigene Eingabe' : 'nicht angegeben'];
    }

    /** @return array{0: string, 1: string} Inbetriebnahme als ISO-Datum (leer = unbekannt). */
    private function resolveInbetriebnahme(): array
    {
        $info = $this->getPlantInfo();
        if ($info !== null && ($info['inbetriebnahme'] ?? '') !== '') {
            return [(string) $info['inbetriebnahme'], 'EMS'];
        }
        $own = $this->parseAnlageDatum($this->ReadPropertyString('InbetriebnahmeDatum')) ?? '';
        return [$own, $own !== '' ? 'eigene Eingabe' : 'nicht angegeben'];
    }

    private function getKwp(): float
    {
        return $this->resolveKwp()[0];
    }

    private function getSpeicherKwh(): float
    {
        return $this->resolveSpeicherKwh()[0];
    }

    private function getVerguetungCt(): float
    {
        return $this->resolveVerguetungCt()[0];
    }

    private function getInbetriebnahmeIso(): string
    {
        return $this->resolveInbetriebnahme()[0];
    }

    /** Förderende als ISO-Datum — NUR über EMS ermittelbar (keine eigene Berechnung hier). */
    private function getFoerderendeIso(): ?string
    {
        $info = $this->getPlantInfo();
        return ($info['foerderende'] ?? '') !== '' ? (string) $info['foerderende'] : null;
    }

    /** EEG-Fassung der Inbetriebnahme — NUR über EMS ermittelbar. */
    private function getEegFassung(): ?string
    {
        $info = $this->getPlantInfo();
        return $info['eegFassung'] ?? null;
    }

    /** Rechtspflichten der Anlage (code+text) — NUR über EMS ermittelbar, sonst leer. */
    private function getPflichten(): array
    {
        $info = $this->getPlantInfo();
        return $info['pflichten'] ?? [];
    }

    private function fmtZahl(float $v, int $dec = 2): string
    {
        return rtrim(rtrim(number_format($v, $dec, ',', ''), '0'), ',');
    }

    /**
     * Statuszeile der EMS-Verbindung (Verbund-Konvention "Verbindungen im Formular
     * sichtbar machen"): live berechnet, nennt Wert UND Quelle je Feld und sagt bei
     * 0 ausdrücklich, dass es "nicht angegeben" bedeutet und was stattdessen gilt.
     */
    private function buildPlantInfoStatusCaption(): string
    {
        $this->getPlantInfo();
        $conn = $this->plantInfoConn;
        [$kwp, $kwpQ] = $this->resolveKwp();
        [$spk, $spkQ] = $this->resolveSpeicherKwh();
        [$verg, $vergQ] = $this->resolveVerguetungCt();
        [$ibn, $ibnQ] = $this->resolveInbetriebnahme();

        $fehlt = [];
        $teile = [];
        $teile[] = $kwp > 0.0 ? $this->fmtZahl($kwp) . " kWp ($kwpQ)" : 'kWp nicht angegeben';
        $teile[] = $spk > 0.0 ? 'Speicher ' . $this->fmtZahl($spk, 1) . " kWh ($spkQ)" : 'Speicher nicht angegeben';
        $teile[] = $verg > 0.0 ? 'Vergütung ' . $this->fmtZahl($verg) . " ct/kWh ($vergQ)" : 'Vergütung nicht angegeben';
        $teile[] = $ibn !== '' ? 'Inbetriebnahme ' . $this->formatAnlageDatum($ibn) . " ($ibnQ)" : 'Inbetriebnahme nicht angegeben';
        if ($kwp <= 0.0) {
            $fehlt[] = 'kWp';
        }
        if ($spk <= 0.0) {
            $fehlt[] = 'Speicher';
        }
        if ($verg <= 0.0) {
            $fehlt[] = 'Vergütung';
        }
        if ($ibn === '') {
            $fehlt[] = 'Inbetriebnahme';
        }
        $werte = implode(', ', $teile);

        switch ($conn['state']) {
            case 'ok':
                $name = @IPS_GetName($conn['id']);
                $wer = 'EMS #' . $conn['id'] . ($name ? " „{$name}“" : '') . ', Vertrag ' . $conn['contract'];
                $info = $this->getPlantInfo() ?? [];
                $extra = '';
                if (($info['foerderende'] ?? '') !== '') {
                    $extra .= '; Förderende ' . $this->formatAnlageDatum((string) $info['foerderende']);
                }
                $codes = array_filter(array_map(fn($p) => (string) ($p['code'] ?? ''), (array) ($info['pflichten'] ?? [])));
                if (count($codes) > 0) {
                    $extra .= '; Pflichten: ' . implode(', ', $codes);
                }
                if (count($fehlt) === 0) {
                    return "✅ Anlagendaten von $wer übernommen: $werte$extra.";
                }
                return "⚠️ $wer antwortet, liefert aber nicht alles (fehlt: " . implode(', ', $fehlt)
                    . "). Es gilt: $werte$extra. Im EMS-Panel „Anlage“ ergänzen oder unten eigene Werte eintragen.";
            case 'multiple':
                $liste = '#' . implode(', #', $conn['ids']);
                return "⚠️ Mehrere EMS-Instanzen gefunden ($liste) — unten die zu verwendende auswählen. Bis dahin gelten die eigenen Angaben: $werte.";
            case 'contract':
                return '⚠️ EMS #' . $conn['id'] . ' liefert Anlagendaten im Vertrag ' . $conn['contract']
                    . ' — dieses Modul versteht 1.x; Modul oder EMS aktualisieren. Bis dahin gelten die eigenen Angaben: ' . $werte . '.';
            default:
                return "ℹ️ Kein EMS gefunden — es gelten die eigenen Angaben unten: $werte. Ein Wert von 0 heißt „nicht angegeben“, nicht „nichts vorhanden“.";
        }
    }

    /**
     * Statuszeile der Tibber-Preisquelle (Dynamischer Vertrag, §14a-Preisannahme).
     */
    private function buildTibberStatusCaption(): string
    {
        if (!function_exists('TIBBERGR_GetPriceCurve')) {
            return 'ℹ️ TibberGridRewards nicht gefunden — das Szenario „Dynamischer Vertrag“ ist nicht verfügbar, das §14a-Szenario rechnet mit dem Festpreis.';
        }
        $ids = array_values(@IPS_GetInstanceListByModuleID(self::TIBBER_MODULE_GUID) ?: []);
        if (count($ids) === 0) {
            return 'ℹ️ Keine TibberGridRewards-Instanz angelegt — das Szenario „Dynamischer Vertrag“ ist nicht verfügbar, das §14a-Szenario rechnet mit dem Festpreis.';
        }
        $cur = $this->findTibberPriceCurve();
        if ($cur['id'] === 0) {
            return '⚠️ TibberGridRewards #' . $ids[0] . ' gefunden, liefert aber keine Preiskurve (Zugangsschlüssel und Haus dort prüfen). Es gilt der Festpreis.';
        }
        $ende = 0;
        foreach ($cur['slots'] as $s) {
            $ende = max($ende, (int) ($s['end'] ?? 0));
        }
        $bis = $ende > 0 ? ', bis ' . date('d.m.Y H:i', $ende) : '';
        $mehr = count($ids) > 1 ? ' (mehrere Instanzen: die erste mit Preisen wird verwendet)' : '';
        return '✅ Preiskurve von TibberGridRewards #' . $cur['id'] . ': ' . count($cur['slots']) . ' Zeitabschnitte' . $bis . $mehr . '.';
    }

    /**
     * Akzeptiert Anlagen-Datumseingaben in BEIDEN Formaten (Verbund-Regel 9b:
     * nutzersichtbar TT.MM.JJJJ, altes JJJJ-MM-TT wird weiterhin gelesen) und
     * liefert intern immer ISO (JJJJ-MM-TT). Null bei leerer/ungültiger Eingabe.
     */
    private function parseAnlageDatum(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    /** ISO-Datum (JJJJ-MM-TT) für die Anzeige nach TT.MM.JJJJ (Verbund-Regel 9b). */
    private function formatAnlageDatum(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }
        return "$m[3].$m[2].$m[1]";
    }
}
