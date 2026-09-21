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
        // Standard 0 = nicht angegeben: ein erfundener Preis würde für jeden Nutzer
        // still ein falsches Ergebnis liefern (Verbund-Regel "keine eigene Anlage als Norm").
        $this->RegisterPropertyFloat('FestpreisCtKwh', 0.0);
        $this->RegisterPropertyFloat('FestpreisGrundpreisMonat', 0.0);

        // ── Datenquellen PV-Erzeugung/Hauslast (historisch, für Szenario 2: Speichergröße) ──
        // Beide als LEISTUNG (W) erwartet — üblicherweise InverterHub-/MeterHub-
        // Momentanwerte, nicht kumulative Zähler (anders als NetzbezugVarID oben).
        $this->RegisterPropertyInteger('PvErzeugungVarID', 0);
        $this->RegisterPropertyInteger('HausLastVarID', 0);
        $this->RegisterPropertyFloat('SpeicherPreisEurKwh', 0.0);
        $this->RegisterPropertyInteger('SpeicherAbschreibungJahre', 0);

        // ── §14a-Beitritt (Szenario 3) — reine Nutzereingabe-Annahmen, da
        // SBH_GetState (SteuerboxHub) aktuell nur den Live-Zustand liefert,
        // keine Historie (bestätigt EMS-Koordination 25.07.2026). Sobald eine
        // Historisierung existiert, kann hierauf umgestellt werden.
        $this->RegisterPropertyFloat('Paragraph14aNetzentgeltErsparnisJahr', 0.0);
        $this->RegisterPropertyInteger('Paragraph14aAnnahmeEreignisseJahr', 0);
        $this->RegisterPropertyInteger('Paragraph14aAnnahmeDauerMinuten', 0);
        $this->RegisterPropertyFloat('Paragraph14aAnnahmeReduktionKw', 0.0);

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
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);

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

        $this->setFormElement($form['elements'], 'ChangelogPanel', ['visible' => $this->ReadAttributeString('ChangelogSeen') !== '0.9']);
        $this->setFormElement($form['elements'], 'PurposeIntroPanel', ['visible' => !$this->ReadAttributeBoolean('PurposeIntroGone')]);
        $this->setFormElement($form['elements'], 'ForumHint', ['visible' => !$this->ReadAttributeBoolean('ForumHintGone')]);
        $this->setFormElement($form['elements'], 'DocVersionLabel', ['caption' => $this->buildVersionCaption()]);
        $this->setFormElement($form['elements'], 'NetztransparenzStatusLabel', ['caption' => $this->buildNetztransparenzStatusCaption()]);
        $this->setFormElement($form['elements'], 'PlantInfoStatusLabel', ['caption' => $this->buildPlantInfoStatusCaption()]);
        $this->setFormElement($form['elements'], 'PriceSourceStatusLabel', ['caption' => $this->buildPriceSourceStatusCaption()]);

        // Je Anlagenwert: Zeile mit Wert und Quelle, Eingabefeld nur wenn nichts automatisch kommt.
        $anyHidden = false;
        foreach ($this->buildPlantFieldRows() as $field => $row) {
            $this->setFormElement($form['elements'], $row['lineName'], ['caption' => $row['line'], 'color' => $row['color']]);
            $this->setFormElement($form['elements'], $field, ['visible' => $row['visible']]);
            $anyHidden = $anyHidden || !$row['visible'];
        }
        foreach ($this->buildSourceRows() as $field => $row) {
            $this->setFormElement($form['elements'], $row['lineName'], ['caption' => $row['line'], 'color' => $row['color']]);
            $this->setFormElement($form['elements'], $field, ['visible' => $row['visible']]);
            if ($field === 'NetzbezugVarID') {
                // Automatisch erkannt ist es immer ein Zählerstand, die Auswahl entfällt.
                $this->setFormElement($form['elements'], 'NetzbezugIstZaehler', ['visible' => $row['visible']]);
            }
            $anyHidden = $anyHidden || !$row['visible'];
        }
        $this->setFormElement($form['elements'], 'ShowOwnValuesButton', ['visible' => $anyHidden]);
        $this->setFormElement($form['elements'], 'EmsInstanceID', [
            'visible' => count($this->plantInfoConn['ids']) > 1 || $this->ReadPropertyInteger('EmsInstanceID') > 0,
        ]);

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

        // Ein Wert von 0 heißt "nicht angegeben" — es gibt bewusst keine erfundenen
        // Standardwerte, deshalb rechnet ein Szenario erst, wenn seine Angaben da sind.
        $price = $this->dynamicPriceSource();
        $reasons = [];
        if ($this->resolveNetzbezug()[0] <= 0) {
            $reasons[] = 'Netzbezug nicht angegeben und nicht automatisch gefunden';
        }
        if ((float) $this->ReadPropertyFloat('FestpreisCtKwh') <= 0.0) {
            $reasons[] = 'aktueller Festpreis nicht angegeben';
        }
        if (!$price['ok']) {
            $reasons[] = $price['reason'];
        }
        $scenarios[] = [
            'type'            => 'dynamicTariff',
            'label'           => 'Dynamischer Vertrag vs. Festpreis',
            'function'        => 'CalculateDynamicTariffScenario',
            'contractVersion' => '1.1',
            'available'       => count($reasons) === 0,
            'reason'          => implode('; ', $reasons),
        ];

        $reasons = [];
        if ($this->resolvePv()[0] <= 0) {
            $reasons[] = 'PV-Erzeugung nicht angegeben und nicht automatisch gefunden';
        }
        if ($this->resolveLast()[0] <= 0) {
            $reasons[] = 'Hauslast nicht angegeben';
        }
        if ((float) $this->ReadPropertyFloat('FestpreisCtKwh') <= 0.0) {
            $reasons[] = 'aktueller Bezugspreis (Festpreis) nicht angegeben';
        }
        $scenarios[] = [
            'type'            => 'storageSize',
            'label'           => 'Speichergröße',
            'function'        => 'CalculateStorageSizeScenario',
            'contractVersion' => '1.1',
            'available'       => count($reasons) === 0,
            'reason'          => implode('; ', $reasons),
        ];

        // §14a-Beitritt ist reine Nutzereingabe (keine Fremdmodul-Voraussetzung), rechnet
        // aber nur mit ausdrücklich angegebenen Annahmen — siehe KONZEPT.md Abschnitt 3.
        $missing = [];
        if ((float) $this->ReadPropertyFloat('Paragraph14aNetzentgeltErsparnisJahr') <= 0.0) {
            $missing[] = 'Netzentgelt-Ersparnis';
        }
        if ((int) $this->ReadPropertyInteger('Paragraph14aAnnahmeEreignisseJahr') <= 0
            || (int) $this->ReadPropertyInteger('Paragraph14aAnnahmeDauerMinuten') <= 0
            || (float) $this->ReadPropertyFloat('Paragraph14aAnnahmeReduktionKw') <= 0.0) {
            $missing[] = 'Dimm-Annahmen (Ereignisse, Dauer, Lastreduktion)';
        }
        $scenarios[] = [
            'type'            => 'paragraph14a',
            'label'           => '§14a-Beitritt',
            'function'        => 'CalculateParagraph14aScenario',
            'contractVersion' => '1.1',
            'available'       => count($missing) === 0,
            'reason'          => count($missing) ? implode(' und ', $missing) . ' nicht angegeben' : '',
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

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
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

        // MaintainVariable ist idempotent (create-or-update) und gehört in ApplyChanges
        // (SUITE.md Stolperstein 3) — nie RegisterVariableXXX bei jedem Lauf.
        $this->RegisterVariables();

        // 102 = mindestens ein Szenario rechenbereit, 104 = noch nichts eingerichtet
        // (neutral, kein Fehler). Ein fehlendes Partnermodul ist KEIN Fehlerstatus
        // (Regel 9d: geparkte Zustände nicht als Fehler > 200 melden); die Gründe stehen
        // je Szenario in GetAvailableScenarios() und in den Statuszeilen des Formulars.
        $anyReady = false;
        foreach ($this->GetAvailableScenarios() as $scenario) {
            $anyReady = $anyReady || $scenario['available'];
        }
        $this->SetStatus($anyReady ? 102 : 104);

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
     * Rechnet nach, was ein dynamischer Vertrag über die letzten $days Kalendertage anstelle
     * des aktuellen Festpreisvertrags gekostet hätte, auf Basis des historischen Netzbezugs.
     *
     * Preise: der tatsächliche Verlauf des dynamischen Tarifs aus dem EMS-Bezugstarif
     * („Tibber“), ct/kWh BRUTTO je Viertelstunde, zu Stundenmitteln verdichtet (siehe
     * dynamicPriceSource()). Verglichen wird NUR über Stunden, für die ein Preis vorliegt —
     * beide Seiten über dieselben Stunden, damit fehlende Preise das Ergebnis nicht
     * verzerren; `coverage` sagt, wie viel des Netzbezugs damit abgedeckt ist.
     *
     * Granularität bewusst stündlich (AC_GetAggregatedValues, aggregation=0).
     * Geldbeträge: Festpreis und dynamischer Preis in ct/kWh brutto, Ergebnisse in EUR.
     *
     * Rückgabe (contractVersion 1.1, additiv zu 1.0: coverage/coveredHours/…/reason):
     *   'contractVersion'      => '1.1',
     *   'periodDays'           => int,
     *   'periodFrom'/'periodTo'=> int (Unix, Kalendertage),
     *   'consumptionKwh'       => float,  // Netzbezug im Zeitraum insgesamt
     *   'consumptionHours'     => int,    // Stunden mit Netzbezug
     *   'coveredHours'         => int,    // davon mit Preis des dynamischen Tarifs
     *   'hoursEvaluated'       => int,    // wie coveredHours (Feld aus Vertrag 1.0, bleibt erhalten)
     *   'coverage'             => float,  // coveredHours / consumptionHours (0..1)
     *   'costFixedEur'         => float,  // nur über die abgedeckten Stunden, inkl. anteiligem Grundpreis
     *   'costDynamicEur'       => float,  // nur über die abgedeckten Stunden, inkl. Grundgebühr falls bekannt
     *   'savingsEur'           => float,  // costFixedEur - costDynamicEur (positiv = dynamisch günstiger)
     *   'avgFixedCtKwh'        => float,
     *   'avgDynamicCtKwh'      => float,  // verbrauchsgewichtet
     *   'dynamicBaseFeeEur'    => float,  // Grundgebühr des dynamischen Tarifs im Zeitraum
     *   'dynamicBaseFeeKnown'  => bool,   // false = nicht angesetzt (Tibber-Tarifkonfiguration fehlt)
     *   'priceSource'          => string, // z. B. "EMS #12345, Bezugstarif Tibber"
     *   'gapDays'              => int,    // Tage, deren Archivabfrage fehlschlug
     *   'dataComplete'         => bool,   // coverage >= 90 % und keine Archivlücke
     *   'reason'               => string, // deutsch: warum nichts/nur teilweise gerechnet wurde
     */
    public function CalculateDynamicTariffScenario(int $days): array
    {
        $fixedCt = (float) $this->ReadPropertyFloat('FestpreisCtKwh');
        $result = [
            'contractVersion'     => '1.1',
            'periodDays'          => $days,
            'periodFrom'          => 0,
            'periodTo'            => 0,
            'consumptionKwh'      => 0.0,
            'consumptionHours'    => 0,
            'hoursEvaluated'      => 0,
            'coveredHours'        => 0,
            'coverage'            => 0.0,
            'costFixedEur'        => 0.0,
            'costDynamicEur'      => 0.0,
            'savingsEur'          => 0.0,
            'avgFixedCtKwh'       => $fixedCt,
            'avgDynamicCtKwh'     => 0.0,
            'dynamicBaseFeeEur'   => 0.0,
            'dynamicBaseFeeKnown' => false,
            'priceSource'         => '',
            'gapDays'             => 0,
            'dataComplete'        => false,
            'reason'              => '',
        ];
        if ($days <= 0) {
            $result['reason'] = 'Zeitraum muss mindestens 1 Tag umfassen';
            return $result;
        }
        $avail = $this->scenarioInfo('dynamicTariff');
        if (!$avail['available']) {
            $result['reason'] = (string) $avail['reason'];
            return $result;
        }

        // Kalendertage statt fester 86400 Sekunden (Zeitumstellung, Stolperstein 18).
        $end = strtotime('today midnight');
        $start = strtotime("-$days days", $end);
        $result['periodFrom'] = $start;
        $result['periodTo'] = $end;

        [$gridVar, , , $gridIsCounter] = $this->resolveNetzbezug();
        $series = $this->hourlyKwhSeries($gridVar, $gridIsCounter, $start, $end);
        if ($series === null) {
            $result['reason'] = 'Netzbezugsvariable ist ungültig oder nicht archiviert';
            return $result;
        }
        $result['gapDays'] = $series['gapDays'];

        $sum = [];
        $cnt = [];
        foreach ($this->dynamicPriceSlots($start, $end) as [$sStart, , $ct]) {
            $h = (int) (floor($sStart / 3600) * 3600);
            $sum[$h] = ($sum[$h] ?? 0.0) + $ct;
            $cnt[$h] = ($cnt[$h] ?? 0) + 1;
        }

        $consumption = 0.0;
        $coveredKwh = 0.0;
        $consHours = 0;
        $covHours = 0;
        $costFixed = 0.0;
        $costDyn = 0.0;
        $weighted = 0.0;
        foreach ($series['kwh'] as $hour => $kwh) {
            if ($kwh <= 0.0) {
                continue;
            }
            $consHours++;
            $consumption += $kwh;
            if (!isset($cnt[$hour])) {
                continue;   // kein Preis: bewusst außen vor, nicht mit einem Ersatzpreis füllen
            }
            $dynCt = $sum[$hour] / $cnt[$hour];
            $covHours++;
            $coveredKwh += $kwh;
            $costFixed += $kwh * $fixedCt / 100.0;
            $costDyn += $kwh * $dynCt / 100.0;
            $weighted += $kwh * $dynCt;
        }
        $coverage = $consHours > 0 ? $covHours / $consHours : 0.0;

        // Grundpreise nur anteilig für den abgedeckten Teil des Zeitraums.
        $months = $days / 30.437;
        $costFixed += (float) $this->ReadPropertyFloat('FestpreisGrundpreisMonat') * $months * $coverage;
        $baseFee = $this->tibberBaseFeeMonth();
        if ($baseFee !== null) {
            $fee = $baseFee * $months * $coverage;
            $costDyn += $fee;
            $result['dynamicBaseFeeEur'] = round($fee, 2);
            $result['dynamicBaseFeeKnown'] = true;
        }

        $src = $this->dynamicPriceSource();
        $result['priceSource'] = $src['ok'] ? 'EMS #' . $src['emsId'] . ', Bezugstarif Tibber' : '';
        $result['consumptionKwh'] = round($consumption, 2);
        $result['consumptionHours'] = $consHours;
        $result['coveredHours'] = $covHours;
        $result['hoursEvaluated'] = $covHours;   // Feld aus Vertrag 1.0 bleibt (nur additive Änderungen)
        $result['coverage'] = round($coverage, 3);
        $result['costFixedEur'] = round($costFixed, 2);
        $result['costDynamicEur'] = round($costDyn, 2);
        $result['savingsEur'] = round($costFixed - $costDyn, 2);
        $result['avgDynamicCtKwh'] = $coveredKwh > 0 ? round($weighted / $coveredKwh, 3) : 0.0;
        $result['dataComplete'] = $consHours > 0 && $coverage >= 0.9 && $series['gapDays'] === 0;
        if ($consHours === 0) {
            $result['reason'] = 'Im Zeitraum wurde kein Netzbezug archiviert';
        } elseif ($covHours === 0) {
            $result['reason'] = 'Für den Zeitraum liegen keine Preise des dynamischen Tarifs vor';
        } elseif (!$result['dataComplete']) {
            $result['reason'] = sprintf('Nur %d von %d Stunden mit Preis (%d %%)%s', $covHours, $consHours, (int) round($coverage * 100),
                $series['gapDays'] > 0 ? ', ' . $series['gapDays'] . ' Tag(e) ohne Archivdaten' : '');
        }

        $this->WriteAttributeString('LastEvaluation', json_encode($result));
        // Nur eine belastbare Zahl zeigen: bei zu geringer Abdeckung bleibt der letzte gute Wert stehen.
        if ($result['dataComplete']) {
            $this->SetValue('DynamicTariffSavingsEur', $result['savingsEur']);
        }
        return $result;
    }

    /** Grundgebühr des Tibber-Tarifs (€/Monat) aus der Tarifkonfiguration, null wenn unbekannt. */
    private function tibberBaseFeeMonth(): ?float
    {
        if (!function_exists('TIBBERGR_GetTariffConfig')) {
            return null;
        }
        foreach ((@IPS_GetInstanceListByModuleID(self::TIBBER_MODULE_GUID) ?: []) as $iid) {
            try {
                $cfg = TIBBERGR_GetTariffConfig($iid);
            } catch (\Throwable $e) {
                $this->LogMessage('TIBBERGR_GetTariffConfig fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
                continue;
            }
            if (is_array($cfg) && isset($cfg['tibberBaseFeeMonth']) && is_numeric($cfg['tibberBaseFeeMonth'])) {
                return (float) $cfg['tibberBaseFeeMonth'];
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    //  Szenario 2: Speichergröße
    // -----------------------------------------------------------------

    /**
     * Simuliert den historischen Lastgang (PV-Erzeugung/Hauslast, stündlich) mit variabler
     * virtueller Speichergröße und ermittelt Autarkiegrad, Eigenverbrauch und Wirtschaftlichkeit
     * je Größe im Vergleich zur AKTUELLEN Speichergröße.
     *
     * Vereinfachtes SoC-Modell, bewusst ohne Wirkungsgradverluste und ohne Lade-/Entladeleistungs-
     * grenzen (bei kurzen Lastspitzen daher optimistisch): PV-Überschuss lädt den Speicher bis
     * voll, ein Fehlbetrag entlädt ihn bis leer, der Rest kommt aus dem Netz bzw. geht ins Netz.
     *
     * Wirtschaftlichkeit: Eine zusätzlich gespeicherte kWh spart den Bezug (`FestpreisCtKwh`), hätte
     * aber sonst eingespeist und Einspeisevergütung erhalten. Der Nutzen je verschobener kWh ist also
     * Bezugspreis MINUS Einspeisevergütung, nicht der volle Bezugspreis. Ist die Vergütung nicht
     * bekannt, wird sie mit 0 angesetzt (`feedInKnown` = false, Ergebnis dann optimistisch).
     *
     * Nur Stunden mit PV- UND Lastwert zählen; fehlende Werte werden NICHT als 0 gerechnet
     * (`coverage`, `gapDays`, `reason`).
     *
     * Rückgabe (contractVersion 1.1, additiv zu 1.0):
     *   'contractVersion'   => '1.1',
     *   'periodDays'        => int,
     *   'periodFrom'/'periodTo' => int (Unix, Kalendertage),
     *   'currentStorageKwh' => float,   // Referenzpunkt (Quelle siehe Formular)
     *   'netValueCtKwh'     => float,   // Nutzen je verschobener kWh (Bezugspreis - Vergütung), ct/kWh
     *   'feedInKnown'       => bool,
     *   'coverage'          => float,   // Anteil der Stunden mit beiden Werten (0..1)
     *   'gapDays'           => int,
     *   'sizes'             => [ [ 'storageKwh', 'selfSufficiencyPercent', 'selfConsumptionPercent',
     *                              'gridImportKwh', 'additionalKwhVsCurrent',
     *                              'additionalSavingsEurPerYear', 'paybackYears'|null,
     *                              'paybackWithinLifetime'|null ], … ],
     *   'dataComplete'      => bool,    // coverage >= 90 % und keine Archivlücke
     *   'reason'            => string,  // deutsch, leer wenn vollständig gerechnet
     */
    public function CalculateStorageSizeScenario(int $days): array
    {
        $current = $this->getSpeicherKwh();
        $fixedCt = (float) $this->ReadPropertyFloat('FestpreisCtKwh');
        $feedInCt = $this->getVerguetungCt();
        $result = [
            'contractVersion'   => '1.1',
            'periodDays'        => $days,
            'periodFrom'        => 0,
            'periodTo'          => 0,
            'currentStorageKwh' => $current,
            'netValueCtKwh'     => round($fixedCt - $feedInCt, 2),
            'feedInKnown'       => $feedInCt > 0.0,
            'coverage'          => 0.0,
            'gapDays'           => 0,
            'sizes'             => [],
            'dataComplete'      => false,
            'reason'            => '',
        ];
        if ($days <= 0) {
            $result['reason'] = 'Zeitraum muss mindestens 1 Tag umfassen';
            return $result;
        }
        $avail = $this->scenarioInfo('storageSize');
        if (!$avail['available']) {
            $result['reason'] = (string) $avail['reason'];
            return $result;
        }

        $end = strtotime('today midnight');
        $start = strtotime("-$days days", $end);
        $result['periodFrom'] = $start;
        $result['periodTo'] = $end;

        $pv = $this->hourlyKwhSeries($this->resolvePv()[0], false, $start, $end);
        $load = $this->hourlyKwhSeries($this->resolveLast()[0], false, $start, $end);
        if ($pv === null || $load === null) {
            $result['reason'] = 'PV-Erzeugungs- oder Hauslastvariable ist ungültig oder nicht archiviert';
            return $result;
        }
        $result['gapDays'] = $pv['gapDays'] + $load['gapDays'];

        $hours = array_values(array_intersect(array_keys($pv['kwh']), array_keys($load['kwh'])));
        sort($hours);
        $union = count(array_unique(array_merge(array_keys($pv['kwh']), array_keys($load['kwh']))));
        $coverage = $union > 0 ? count($hours) / $union : 0.0;
        $result['coverage'] = round($coverage, 3);
        if (count($hours) === 0) {
            $result['reason'] = 'Im Zeitraum liegen keine gemeinsamen PV- und Lastwerte vor';
            return $result;
        }

        $steps = [0.0, 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 80.0];
        if ($current > 0.0 && !in_array($current, $steps, true)) {
            $steps[] = $current;
            sort($steps);
        }

        $sim = [];
        foreach ($steps as $cap) {
            $soc = 0.0;
            $totalLoad = 0.0;
            $totalPv = 0.0;
            $grid = 0.0;
            $feedIn = 0.0;
            foreach ($hours as $h) {
                $p = $pv['kwh'][$h];
                $l = $load['kwh'][$h];
                $totalPv += $p;
                $totalLoad += $l;
                $surplus = $p - $l;
                if ($surplus >= 0) {
                    $room = $cap - $soc;
                    $stored = min($room, $surplus);
                    $soc += $stored;
                    $feedIn += $surplus - $stored;
                } else {
                    $fromStorage = min($soc, -$surplus);
                    $soc -= $fromStorage;
                    $grid += -$surplus - $fromStorage;
                }
            }
            $sim[] = [
                'cap'  => $cap,
                'grid' => $grid,
                'self' => $totalLoad > 0 ? (1 - $grid / $totalLoad) * 100 : 0.0,
                'own'  => $totalPv > 0 ? (1 - $feedIn / $totalPv) * 100 : 0.0,
            ];
        }

        // Bezug bei aktueller Größe; nur nutzbar, wenn diese Größe in den Stufen liegt (current > 0
        // wurde oben ergänzt; bei "nicht angegeben" gibt es keinen Referenzpunkt).
        $refGrid = null;
        foreach ($sim as $row) {
            if ($current > 0.0 && abs($row['cap'] - $current) < 0.01) {
                $refGrid = $row['grid'];
            }
        }
        $yearFactor = 365.0 / $days;
        $price = (float) $this->ReadPropertyFloat('SpeicherPreisEurKwh');
        $life = (int) $this->ReadPropertyInteger('SpeicherAbschreibungJahre');
        $net = ($fixedCt - $feedInCt) / 100.0;

        $best = 0.0;
        foreach ($sim as $row) {
            $entry = [
                'storageKwh'                  => $row['cap'],
                'selfSufficiencyPercent'      => round($row['self'], 1),
                'selfConsumptionPercent'      => round($row['own'], 1),
                'gridImportKwh'               => round($row['grid'], 1),
                'additionalKwhVsCurrent'      => null,
                'additionalSavingsEurPerYear' => null,
                'paybackYears'                => null,
                'paybackWithinLifetime'       => null,
            ];
            if ($refGrid !== null) {
                $saved = $refGrid - $row['grid'];
                $perYear = $saved * $yearFactor * $net;
                $entry['additionalKwhVsCurrent'] = round($saved, 1);
                $entry['additionalSavingsEurPerYear'] = round($perYear, 2);
                $extra = $row['cap'] - $current;
                if ($extra > 0.01 && $perYear > 0.01 && $price > 0.0) {
                    $entry['paybackYears'] = round($extra * $price / $perYear, 1);
                    $entry['paybackWithinLifetime'] = $life > 0 ? $entry['paybackYears'] <= $life : null;
                }
                if ($row['cap'] > $current && $perYear > $best) {
                    $best = $perYear;
                }
            }
            $result['sizes'][] = $entry;
        }

        $result['dataComplete'] = $coverage >= 0.9 && $result['gapDays'] === 0;
        if ($refGrid === null) {
            $result['reason'] = 'Aktuelle Speichergröße nicht angegeben, es gibt keinen Vergleichspunkt für die Zusatzersparnis';
        } elseif (!$result['dataComplete']) {
            $result['reason'] = sprintf('Nur %d %% der Stunden mit PV- und Lastwert%s', (int) round($coverage * 100),
                $result['gapDays'] > 0 ? ', ' . $result['gapDays'] . ' Tag(e) ohne Archivdaten' : '');
        } elseif (!$result['feedInKnown']) {
            $result['reason'] = 'Einspeisevergütung nicht angegeben, mit 0 ct gerechnet (Ergebnis optimistisch)';
        }

        // Kern-Kennzahl nur bei belastbarer Datenlage schreiben, sonst bleibt der letzte gute Wert stehen.
        if ($refGrid !== null && $result['dataComplete']) {
            $this->SetValue('StorageSizeAdditionalSavingsEur', round($best, 2));
        }
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
        $ersparnis = (float) $this->ReadPropertyFloat('Paragraph14aNetzentgeltErsparnisJahr');
        $ereignisse = (int) $this->ReadPropertyInteger('Paragraph14aAnnahmeEreignisseJahr');
        $dauerMinuten = (int) $this->ReadPropertyInteger('Paragraph14aAnnahmeDauerMinuten');
        $reduktionKw = (float) $this->ReadPropertyFloat('Paragraph14aAnnahmeReduktionKw');

        $result = [
            'contractVersion'           => '1.1',
            'netzentgeltErsparnisJahr'  => round($ersparnis, 2),
            'referenzTibberJahr'        => null,
            'angenommeneEreignisseJahr' => $ereignisse,
            'angenommeneDauerMinuten'   => $dauerMinuten,
            'angenommeneReduktionKw'    => $reduktionKw,
            'betroffeneEnergieJahrKwh'  => 0.0,
            'mittlererPreisCtKwh'       => 0.0,
            'preisBekannt'              => false,
            'dimmKostenSchaetzungJahr'  => 0.0,
            'nettoNutzenJahr'           => 0.0,
            'dimmCostIsRoughEstimate'   => true,
            'sbhLiveHistorieVerfuegbar' => false,
            'reason'                    => '',
        ];
        $avail = $this->scenarioInfo('paragraph14a');
        if (!$avail['available']) {
            $result['reason'] = (string) $avail['reason'];
            return $result;
        }

        if (function_exists('TIBBERGR_GetTariffConfig')) {
            foreach ((@IPS_GetInstanceListByModuleID(self::TIBBER_MODULE_GUID) ?: []) as $iid) {
                try {
                    $cfg = TIBBERGR_GetTariffConfig($iid);
                } catch (\Throwable $e) {
                    $this->LogMessage('TIBBERGR_GetTariffConfig fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
                    continue;
                }
                if (is_array($cfg) && ($cfg['paragraph14aEnabled'] ?? false)) {
                    // Nur Vergleichswert, nie Rechengrundlage (die Ersparnis ist netzbetreiberspezifisch).
                    $result['referenzTibberJahr'] = round((float) ($cfg['paragraph14aReductionYear'] ?? 0.0), 2);
                    break;
                }
            }
        }

        // Preisreferenz für die Dimm-Kostenannahme: ausdrücklich angegebener Bezugspreis, sonst
        // Mittel des dynamischen Tarifs der letzten 30 Kalendertage, sonst unbekannt (kein Ersatzwert).
        $priceCt = (float) $this->ReadPropertyFloat('FestpreisCtKwh');
        if ($priceCt <= 0.0) {
            $end = strtotime('today midnight');
            $slots = $this->dynamicPriceSlots(strtotime('-30 days', $end), $end);
            if (count($slots) > 0) {
                $priceCt = array_sum(array_column($slots, 2)) / count($slots);
            }
        }
        $energy = $ereignisse * ($dauerMinuten / 60.0) * $reduktionKw;
        $cost = $priceCt > 0.0 ? $energy * $priceCt / 100.0 : 0.0;
        $net = round($ersparnis - $cost, 2);

        $result['betroffeneEnergieJahrKwh'] = round($energy, 1);
        $result['mittlererPreisCtKwh'] = round($priceCt, 2);
        $result['preisBekannt'] = $priceCt > 0.0;
        $result['dimmKostenSchaetzungJahr'] = round($cost, 2);
        $result['nettoNutzenJahr'] = $net;
        if ($priceCt <= 0.0) {
            $result['reason'] = 'Kein Bezugspreis bekannt, die Dimm-Kosten sind mit 0 angesetzt (Ergebnis optimistisch)';
        } else {
            $this->SetValue('Paragraph14aNetBenefitEur', $net);
        }
        return $result;
    }

    /** Verfügbarkeitseintrag eines Szenarios nach Typ (siehe GetAvailableScenarios()). */
    private function scenarioInfo(string $type): array
    {
        foreach ($this->GetAvailableScenarios() as $sc) {
            if ($sc['type'] === $type) {
                return $sc;
            }
        }
        return ['type' => $type, 'available' => false, 'reason' => 'unbekanntes Szenario'];
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
            $this->LogMessage('Netztransparenz: Token-Anfrage ohne Antwort', KL_WARNING);
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            $this->SendDebug(__FUNCTION__, 'Token-Antwort ohne access_token: ' . $response, 0);
            $this->LogMessage('Netztransparenz: Token-Antwort ohne access_token', KL_WARNING);
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
            $this->LogMessage('Netztransparenz: ' . $errorOut, KL_WARNING);
            return null;
        }
        if ($statusCode >= 400) {
            $errorOut = "HTTP $statusCode von $url" . ($response !== '' ? (': ' . substr($response, 0, 200)) : '');
            $this->SendDebug(__FUNCTION__, $errorOut, 0);
            $this->LogMessage('Netztransparenz: ' . $errorOut, KL_WARNING);
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
     * Liefert kWh je Stunde (Unix-Stundenbeginn => kWh) für eine Archiv-Variable im
     * Zeitraum [$start, $end). $isCounter = true: Avg ist bereits der Periodenverbrauch
     * (kWh). $isCounter = false: Avg ist mittlere Leistung (W), wird auf kWh der Stunde
     * umgerechnet.
     *
     * Tageweise abgefragt (SUITE.md 9g: AC_* bricht bei > ~50 000 Werten ab und liefert
     * dann `false`) und mit Kalendertagen statt fester 86400 Sekunden (Stolperstein 18,
     * Zeitumstellung). `false` heißt Fehler, nie "keine Daten": der Tag wird geloggt und
     * als Lücke gezählt. Rückgabe ['kwh' => [Stunde => kWh], 'gapDays' => int] oder null
     * bei fehlendem Archiv/ungültiger Variable.
     *
     * @return array{kwh: array<int,float>, gapDays: int}|null
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
        $hourly = [];
        $gapDays = 0;
        for ($d = $start; $d < $end; $d = strtotime('+1 day', $d)) {
            $dEnd = min($end, strtotime('+1 day', $d));
            $rows = AC_GetAggregatedValues($archiveID, $varID, 0 /* stündlich */, $d, $dEnd, 0);
            if (!is_array($rows)) {
                $this->LogMessage('Archivabfrage für Variable ' . $varID . ' am ' . date('d.m.Y', $d) . ' fehlgeschlagen', KL_WARNING);
                $gapDays++;
                continue;
            }
            foreach ($rows as $row) {
                $avg = (float) $row['Avg'];
                $hourly[(int) $row['TimeStamp']] = $isCounter ? $avg : ($avg / 1000.0);
            }
        }
        return ['kwh' => $hourly, 'gapDays' => $gapDays];
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
    //  Quellvariablen automatisch erkennen (Discovery vor manuellem Feld)
    // -----------------------------------------------------------------
    // SUITE.md: ein Feature, das Daten eines Partnermoduls braucht, prüft ZUERST die vorhandene
    // Discovery; das manuelle Feld ist nur Ersatz. Netzbezug kommt aus MeterHub (Funktion
    // `grid`, kumulativer Zähler `energyImportID` = Bezug, Abrechnungszähler `authority=billing`
    // vor `auxiliary`), die PV-Erzeugung aus InverterHub (`pvPowerID`, W). Die Hauslast wird
    // NICHT automatisch übernommen: das Vorzeichen der MeterHub-Funktion `house` ist im Vertrag
    // nicht verbindlich festgelegt (Stand 21.09.2026, EMS fragt bei MeterHub nach).
    // Nicht raten: mehrere gleichrangige Kandidaten oder eine nicht archivierte Variable werden
    // als ⚠️ gemeldet und NICHT verwendet.

    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const METERHUB_VIRTUAL_GUID = '{ADF18291-2E60-4354-92F5-B96863C127C8}';
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';

    /** @var array<string, array> Request-lokaler Cache je Quelle. */
    private $sourceCache = [];

    /** Vertragsantwort eines Partners lesen: String (JSON) oder Array, im try/catch, Major 1 verlangt. */
    private function readPartnerContract(callable $call, string $what, ?string &$contractOut = null): ?array
    {
        try {
            $r = $call();
        } catch (\Throwable $e) {
            $this->LogMessage("$what fehlgeschlagen: " . $e->getMessage(), KL_WARNING);
            return null;
        }
        if (is_string($r)) {
            $r = json_decode($r, true);
        }
        if (!is_array($r)) {
            return null;
        }
        $contractOut = (string) ($r['contractVersion'] ?? '1.0');
        return str_starts_with($contractOut, '1.') ? $r : null;
    }

    /**
     * Netzbezug-Zähler aus MeterHub. state: ok|none|multiple|unarchived. Bei ok: 'varId' (kumulativer
     * Zähler, kWh), 'text' Quelle.
     *
     * @return array{state: string, varId: int, text: string, ids: array}
     */
    private function discoverGridImport(): array
    {
        if (isset($this->sourceCache['grid'])) {
            return $this->sourceCache['grid'];
        }
        $res = ['state' => 'none', 'varId' => 0, 'text' => '', 'ids' => []];
        $cands = [];
        foreach ([[self::METERHUB_GUID, 'MHUB_GetFunctions'], [self::METERHUB_VIRTUAL_GUID, 'MHUBV_GetFunctions']] as [$guid, $fn]) {
            if (!function_exists($fn)) {
                continue;
            }
            foreach ((@IPS_GetInstanceListByModuleID($guid) ?: []) as $iid) {
                $r = $this->readPartnerContract(fn() => $fn($iid), "$fn #$iid");
                foreach ((array) ($r['assignments'] ?? []) as $a) {
                    if (!is_array($a) || ($a['function'] ?? '') !== 'grid' || (int) ($a['energyImportID'] ?? 0) <= 0) {
                        continue;
                    }
                    if (($a['energyKind'] ?? 'counter') !== 'counter' || ($a['energyMeasured'] ?? true) === false) {
                        continue;   // nur echte, gemessene Zählerstände (nie hochgerechnet)
                    }
                    $vid = (int) $a['energyImportID'];
                    $cands[] = ['inst' => (int) $iid, 'vid' => $vid, 'auth' => (string) ($a['authority'] ?? $r['authority'] ?? 'auxiliary'),
                                'archived' => $this->getArchiveID($vid) > 0];
                }
            }
        }
        if (count($cands) === 0) {
            return $this->sourceCache['grid'] = $res;
        }
        $usable = array_values(array_filter($cands, fn($c) => $c['archived']));
        if (count($usable) === 0) {
            $res['state'] = 'unarchived';
            $res['ids'] = array_values(array_unique(array_column($cands, 'inst')));
            return $this->sourceCache['grid'] = $res;
        }
        $billing = array_values(array_filter($usable, fn($c) => $c['auth'] === 'billing'));
        $pool = count($billing) > 0 ? $billing : $usable;
        if (count($pool) > 1) {
            $res['state'] = 'multiple';
            $res['ids'] = array_values(array_unique(array_column($pool, 'inst')));
            return $this->sourceCache['grid'] = $res;
        }
        $c = $pool[0];
        $name = @IPS_GetName($c['inst']);
        $res['state'] = 'ok';
        $res['varId'] = $c['vid'];
        $res['ids'] = [$c['inst']];
        $res['text'] = 'MeterHub #' . $c['inst'] . ($name ? " „{$name}“" : '') . ', Netzanschluss, '
            . ($c['auth'] === 'billing' ? 'Abrechnungszähler' : 'Hilfszähler');
        return $this->sourceCache['grid'] = $res;
    }

    /**
     * PV-Erzeugung (Leistung, W) aus InverterHub. state: ok|none|multiple|unarchived.
     *
     * @return array{state: string, varId: int, text: string, ids: array}
     */
    private function discoverPvPower(): array
    {
        if (isset($this->sourceCache['pv'])) {
            return $this->sourceCache['pv'];
        }
        $res = ['state' => 'none', 'varId' => 0, 'text' => '', 'ids' => []];
        if (!function_exists('IHUB_GetFunctions')) {
            return $this->sourceCache['pv'] = $res;
        }
        $cands = [];
        foreach ((@IPS_GetInstanceListByModuleID(self::INVERTERHUB_GUID) ?: []) as $iid) {
            $r = $this->readPartnerContract(fn() => IHUB_GetFunctions($iid), "IHUB_GetFunctions #$iid");
            $vid = (int) ($r['pvPowerID'] ?? 0);
            if ($vid > 0) {
                $cands[] = ['inst' => (int) $iid, 'vid' => $vid, 'archived' => $this->getArchiveID($vid) > 0];
            }
        }
        if (count($cands) === 0) {
            return $this->sourceCache['pv'] = $res;
        }
        $usable = array_values(array_filter($cands, fn($c) => $c['archived']));
        if (count($usable) === 0) {
            $res['state'] = 'unarchived';
            $res['ids'] = array_column($cands, 'inst');
            return $this->sourceCache['pv'] = $res;
        }
        if (count($usable) > 1) {
            // Mehrere Wechselrichter: eine Summe bildet erst ein virtueller Wechselrichter, nicht raten.
            $res['state'] = 'multiple';
            $res['ids'] = array_column($usable, 'inst');
            return $this->sourceCache['pv'] = $res;
        }
        $name = @IPS_GetName($usable[0]['inst']);
        $res['state'] = 'ok';
        $res['varId'] = $usable[0]['vid'];
        $res['ids'] = [$usable[0]['inst']];
        $res['text'] = 'InverterHub #' . $usable[0]['inst'] . ($name ? " „{$name}“" : '') . ', PV-Leistung gesamt';
        return $this->sourceCache['pv'] = $res;
    }

    /**
     * Auflösung je Quellvariable: eigene Eingabe > automatisch erkannt > nichts.
     *
     * @return array{0: int, 1: string, 2: string} [Variablen-ID, Quelltext, Art own|auto|none]
     */
    private function resolveSource(string $prop, array $disc): array
    {
        $own = (int) $this->ReadPropertyInteger($prop);
        if ($own > 0) {
            return [$own, 'eigene Eingabe' . ($disc['state'] === 'ok' ? ', überschreibt ' . $disc['text'] : ''), 'own'];
        }
        if ($disc['state'] === 'ok') {
            return [$disc['varId'], $disc['text'], 'auto'];
        }
        return [0, 'nicht angegeben', 'none'];
    }

    /** @return array{0: int, 1: string, 2: string, 3: bool} Netzbezug: [ID, Text, Art, ist Zähler] */
    private function resolveNetzbezug(): array
    {
        $r = $this->resolveSource('NetzbezugVarID', $this->discoverGridImport());
        // Automatisch erkannt ist es immer ein kumulativer Zähler; bei eigener Wahl gilt die Angabe.
        $isCounter = $r[2] === 'auto' ? true : (bool) $this->ReadPropertyBoolean('NetzbezugIstZaehler');
        return [$r[0], $r[1], $r[2], $isCounter];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function resolvePv(): array
    {
        return $this->resolveSource('PvErzeugungVarID', $this->discoverPvPower());
    }

    /** @return array{0: int, 1: string, 2: string} Hauslast: keine automatische Quelle. */
    private function resolveLast(): array
    {
        return $this->resolveSource('HausLastVarID', ['state' => 'none']);
    }

    /**
     * Zeilen zu den drei Quellvariablen (Konvention: 🔗 automatisch grün / ✏️ eigene Eingabe /
     * ⚠️ nicht eindeutig oder nicht archiviert / ℹ️ nichts gefunden), gleiche Struktur wie
     * buildPlantFieldRows().
     *
     * @return array<string, array{line: string, lineName: string, visible: bool, color: int}>
     */
    private function buildSourceRows(): array
    {
        $rows = [];
        $mk = function (string $field, string $lineName, string $label, array $res, array $disc, string $noneText) use (&$rows) {
            [$vid, $text, $kind] = $res;
            $name = $vid > 0 ? (string) @IPS_GetName($vid) : '';
            $ref = $vid > 0 ? 'Variable #' . $vid . ($name ? " „{$name}“" : '') : '';
            $vis = true;
            $color = -1;
            if ($kind === 'auto') {
                $line = "🔗 $label: $ref ($text)";
                $vis = false;
                $color = self::COLOR_AUTO;
            } elseif ($kind === 'own') {
                $line = "✏️ $label: $ref ($text)";
            } elseif (($disc['state'] ?? '') === 'multiple') {
                $line = "⚠️ $label: mehrere gleichrangige Quellen gefunden (Instanzen #" . implode(', #', $disc['ids']) . ') — es wird keine geraten, bitte unten die Variable selbst wählen.';
            } elseif (($disc['state'] ?? '') === 'unarchived') {
                $line = "⚠️ $label: Quelle gefunden (Instanz #" . implode(', #', $disc['ids']) . '), die Variable ist aber nicht archiviert — Archivierung einschalten oder unten eine archivierte Variable wählen.';
            } else {
                $line = "ℹ️ $label: $noneText";
            }
            $rows[$field] = ['line' => $line, 'lineName' => $lineName, 'visible' => $vis, 'color' => $color];
        };
        $mk('NetzbezugVarID', 'NetzbezugLine', 'Netzbezug', $this->resolveNetzbezug(), $this->discoverGridImport(),
            'kein MeterHub mit der Funktion „Netzanschluss“ und archiviertem Zählerstand gefunden, bitte unten die archivierte Variable wählen.');
        $mk('PvErzeugungVarID', 'PvErzeugungLine', 'PV-Erzeugung', $this->resolvePv(), $this->discoverPvPower(),
            'kein InverterHub mit archivierter PV-Leistung gefunden, bitte unten die archivierte Variable (Watt) wählen.');
        $mk('HausLastVarID', 'HausLastLine', 'Hauslast', $this->resolveLast(), ['state' => 'none'],
            'wird derzeit nicht automatisch übernommen (das Vorzeichen der MeterHub-Funktion „Hausverbrauch“ ist im Vertrag nicht verbindlich festgelegt), bitte unten die archivierte Variable (Watt) wählen.');
        return $rows;
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

    /**
     * Welche EMS-Instanz gilt? Ausdrückliche Wahl gewinnt; sonst automatisch nur bei
     * genau EINER Instanz, bei mehreren wird nicht geraten.
     *
     * @return array{state: string, ids: array, candidates: array} state: ok|none|multiple
     */
    private function emsCandidates(): array
    {
        $ids = array_values(@IPS_GetInstanceListByModuleID(self::EMS_MODULE_GUID) ?: []);
        if (count($ids) === 0) {
            return ['state' => 'none', 'ids' => [], 'candidates' => []];
        }
        $selected = (int) $this->ReadPropertyInteger('EmsInstanceID');
        if ($selected > 0 && in_array($selected, $ids, true)) {
            return ['state' => 'ok', 'ids' => $ids, 'candidates' => [$selected]];
        }
        if (count($ids) > 1) {
            return ['state' => 'multiple', 'ids' => $ids, 'candidates' => []];
        }
        return ['state' => 'ok', 'ids' => $ids, 'candidates' => $ids];
    }

    // -----------------------------------------------------------------
    //  Preisverlauf der Vergangenheit (dynamischer Tarif)
    // -----------------------------------------------------------------
    // TIBBERGR_GetPriceCurve liefert nur heute und morgen — für einen Rückblick über
    // Wochen taugt es nicht. Den tatsächlichen Verlauf des Bezugstarifs führt EMS
    // (`EMS_GetPurchasePriceHistory`, Vertrag `purchaseprice` 1.0: je Viertelstunde
    // `priceCt` in ct/kWh BRUTTO, `quelle` tibber/tibber-archiv/variable/fest, null =
    // unbekannt). Nur Slots mit `quelle` tibber* zählen als echter dynamischer Preis;
    // ein Festpreis aus dieser Quelle sagt nichts über einen dynamischen Vertrag.

    /** @var array|null Request-lokaler Cache der Prüfung der Preisquelle. */
    private $priceSourceCache = null;

    /**
     * Liefert ['ok' => bool, 'reason' => string, 'emsId' => int]. `ok` heißt: EMS ist
     * eindeutig gefunden, spricht Vertragsmajor 1 und hat den Bezugstarif „Tibber“.
     */
    private function dynamicPriceSource(): array
    {
        if ($this->priceSourceCache !== null) {
            return $this->priceSourceCache;
        }
        $fail = fn(string $r, string $level = 'info') => ['ok' => false, 'reason' => $r, 'emsId' => 0, 'level' => $level];
        if (!function_exists('EMS_GetPurchasePriceHistory')) {
            return $this->priceSourceCache = $fail('Preisverlauf fehlt: EMS nicht gefunden (er liefert den Verlauf des Bezugstarifs „Tibber“)');
        }
        $ems = $this->emsCandidates();
        if ($ems['state'] === 'none') {
            return $this->priceSourceCache = $fail('Preisverlauf fehlt: keine EMS-Instanz angelegt');
        }
        if ($ems['state'] === 'multiple') {
            return $this->priceSourceCache = $fail('Preisverlauf: mehrere EMS-Instanzen, bitte im Bereich „Anlagendaten“ eine auswählen', 'warn');
        }
        $id = $ems['candidates'][0];
        $now = time();
        try {
            $r = EMS_GetPurchasePriceHistory($id, $now - 3600, $now);
        } catch (\Throwable $e) {
            $this->LogMessage('EMS_GetPurchasePriceHistory fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            return $this->priceSourceCache = $fail('Preisverlauf: EMS antwortet nicht (Vertrag „purchaseprice“)', 'warn');
        }
        if (!is_array($r) || !str_starts_with((string) ($r['contractVersion'] ?? '1.0'), '1.')) {
            return $this->priceSourceCache = $fail('Preisverlauf: EMS liefert einen anderen Vertrag (purchaseprice ' . (is_array($r) ? (string) ($r['contractVersion'] ?? '?') : '?') . ', benötigt 1.x) — EMS oder dieses Modul aktualisieren', 'warn');
        }
        if (($r['einheit'] ?? '') !== 'ct/kWh brutto') {
            return $this->priceSourceCache = $fail('Preisverlauf: EMS liefert Preise in „' . (string) ($r['einheit'] ?? '?') . '“, erwartet ct/kWh brutto', 'warn');
        }
        $tarif = (string) ($r['tarifart'] ?? '');
        if ($tarif !== 'tibber') {
            return $this->priceSourceCache = $fail('Preisverlauf fehlt: der EMS-Bezugstarif ist „' . $tarif . '“, ein Verlauf eines dynamischen Tarifs liegt nur bei „Tibber“ vor');
        }
        return $this->priceSourceCache = ['ok' => true, 'reason' => '', 'emsId' => $id, 'level' => 'ok'];
    }

    /**
     * Echte dynamische Preise [start, end, ct/kWh brutto] im Zeitraum. Ein Aufruf liefert
     * höchstens 62 Tage, längere Zeiträume werden gestückelt. Lücken (null) fehlen.
     */
    private function dynamicPriceSlots(int $from, int $to): array
    {
        $src = $this->dynamicPriceSource();
        if (!$src['ok'] || !function_exists('EMS_GetPurchasePriceHistory')) {
            return [];
        }
        $out = [];
        for ($a = $from; $a < $to; $a = $a + 62 * 86400) {
            try {
                $r = EMS_GetPurchasePriceHistory($src['emsId'], $a, min($to, $a + 62 * 86400));
            } catch (\Throwable $e) {
                $this->LogMessage('EMS_GetPurchasePriceHistory fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
                continue;
            }
            foreach ((array) ($r['slots'] ?? []) as $slot) {
                if (!is_array($slot) || !isset($slot['priceCt']) || !is_numeric($slot['priceCt'])) {
                    continue;
                }
                if (!str_starts_with((string) ($slot['quelle'] ?? ''), 'tibber')) {
                    continue;
                }
                $out[] = [(int) $slot['start'], (int) $slot['end'], (float) $slot['priceCt']];
            }
        }
        return $out;
    }

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
        $sel = $this->emsCandidates();
        $ids = $sel['ids'];
        $this->plantInfoConn['ids'] = $ids;
        if ($sel['state'] === 'none') {
            return null;
        }
        if ($sel['state'] === 'multiple') {
            $this->plantInfoConn['state'] = 'multiple';
            return null;
        }
        $candidates = $sel['candidates'];
        foreach ($candidates as $id) {
            // try/catch statt @: ein Vertragsbruch beim Partner (z. B. ArgumentCountError) darf
            // nie das Formular oder den Zyklus töten (SUITE.md Stolperstein 8).
            try {
                $info = EMS_GetPlantInfo($id);
            } catch (\Throwable $e) {
                $this->LogMessage('EMS_GetPlantInfo fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
                continue;
            }
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

    // Auflösung je Wert liefert [Wert, Quelle als Klartext, Art]. Art: 'auto' (automatisch
    // von EMS), 'own' (bewusste eigene Eingabe, hat Vorrang), 'none' (nichts verfügbar).
    // get*(), die Statuszeile und die Feldzeilen nutzen dieselbe Stelle, damit angezeigt
    // wird, was tatsächlich gilt.

    /**
     * @param float|string $own   eigene Eingabe (0 bzw. '' = nicht angegeben)
     * @param array|null   $ems   [Wert, Quelltext] oder null, wenn EMS nichts liefert
     * @return array{0: float|string, 1: string, 2: string}
     */
    private function pickValue($own, ?array $ems): array
    {
        $hasOwn = is_string($own) ? $own !== '' : $own > 0.0;
        if ($hasOwn) {
            return [$own, 'eigene Eingabe' . ($ems !== null ? ', überschreibt ' . $ems[1] : ''), 'own'];
        }
        if ($ems !== null) {
            return [$ems[0], $ems[1], 'auto'];
        }
        return [$own, 'nicht angegeben', 'none'];
    }

    private function emsKwp(): ?array
    {
        $info = $this->getPlantInfo();
        if ($info !== null && ($info['kwp'] ?? 0.0) > 0.0) {
            return [(float) $info['kwp'], 'EMS: ' . $this->quelleText((string) ($info['kwpQuelle'] ?? ''))];
        }
        return null;
    }

    /**
     * Speicherkapazität aus EMS (plantinfo 1.1, `speicherKwh`/`speicherKwhQuelle`):
     * 'wechselrichter' (über InverterHub gemessen) ist belastbar. 'einstellung'
     * (EMS-Property BAT_Capacity_kWh) kann laut EMS der nie geänderte Standardwert
     * 10 kWh sein — EMS kann eine bewusste Eingabe nicht davon unterscheiden — und
     * wird deshalb als "unbestätigt" gekennzeichnet.
     */
    private function emsSpeicherKwh(): ?array
    {
        $info = $this->getPlantInfo();
        $ems = (float) ($info['speicherKwh'] ?? 0.0);
        if ($info === null || $ems <= 0.0) {
            return null;
        }
        $quelle = (string) ($info['speicherKwhQuelle'] ?? 'fehlt');
        if ($quelle === 'wechselrichter') {
            return [$ems, 'EMS: ' . $this->quelleText($quelle)];
        }
        if ($quelle === 'einstellung') {
            return [$ems, 'EMS: ' . $this->quelleText($quelle) . ', unbestätigt'];
        }
        return null;
    }

    private function emsVerguetungCt(): ?array
    {
        $info = $this->getPlantInfo();
        if ($info === null || ($info['verguetungQuelle'] ?? 'platzhalter') === 'platzhalter') {
            return null;
        }
        $q = 'EMS: ' . $this->quelleText((string) $info['verguetungQuelle']);
        if (($info['verguetungQuelle'] ?? '') === 'berechnet' && !empty($info['verguetungGeprueft'])) {
            $q .= ', geprüft';
        }
        return [(float) ($info['verguetungCt'] ?? 0.0), $q];
    }

    private function emsInbetriebnahme(): ?array
    {
        $info = $this->getPlantInfo();
        if ($info !== null && ($info['inbetriebnahme'] ?? '') !== '') {
            return [(string) $info['inbetriebnahme'], 'EMS'];
        }
        return null;
    }

    /** @return array{0: float, 1: string, 2: string} */
    private function resolveKwp(): array
    {
        return $this->pickValue($this->ReadPropertyFloat('PvKwp'), $this->emsKwp());
    }

    /** @return array{0: float, 1: string, 2: string} */
    private function resolveSpeicherKwh(): array
    {
        return $this->pickValue($this->ReadPropertyFloat('SpeicherKwh'), $this->emsSpeicherKwh());
    }

    /** @return array{0: float, 1: string, 2: string} */
    private function resolveVerguetungCt(): array
    {
        return $this->pickValue($this->ReadPropertyFloat('EinspeiseverguetungCtKwh'), $this->emsVerguetungCt());
    }

    /** @return array{0: string, 1: string, 2: string} Inbetriebnahme als ISO-Datum. */
    private function resolveInbetriebnahme(): array
    {
        $own = $this->parseAnlageDatum($this->ReadPropertyString('InbetriebnahmeDatum')) ?? '';
        return $this->pickValue($own, $this->emsInbetriebnahme());
    }

    /** @return array{0: float, 1: string, 2: string} Wechselrichter-Leistung: keine Quelle im Verbund. */
    private function resolveWrKw(): array
    {
        return $this->pickValue($this->ReadPropertyFloat('WrKw'), null);
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

    /** Grün für automatisch übernommene Werte (Verbund-Konvention, EMS_COLOR_AUTO); -1 = Standardfarbe. */
    private const COLOR_AUTO = 0x2E8B3D;

    private const EIGENE_FELDER = ['PvKwp', 'WrKw', 'SpeicherKwh', 'EinspeiseverguetungCtKwh', 'InbetriebnahmeDatum', 'NetzbezugVarID', 'NetzbezugIstZaehler', 'PvErzeugungVarID', 'HausLastVarID'];

    /**
     * Schreibgeschützte Zeile je Feld (Verbund-Konvention "Wert kommt automatisch:
     * Eingabefeld ersetzen"): 🔗 automatisch übernommen, ✏️ eigene Eingabe, ℹ️ nichts
     * verfügbar. Zusätzlich, ob das Eingabefeld sichtbar sein soll: nur wenn nichts
     * automatisch kommt oder eine eigene Eingabe gilt. Der Wert wird NIE in das
     * Eingabefeld geschrieben — sonst würde ein Klick auf "Übernehmen" ihn als eigene
     * Angabe speichern und das Modul folgte EMS nicht mehr.
     *
     * @return array<string, array{line: string, lineName: string, visible: bool, color: int}> je Eingabefeld
     */
    private function buildPlantFieldRows(): array
    {
        $this->getPlantInfo();
        $rows = [];
        $add = function (string $field, string $lineName, string $label, array $res, string $shown, string $noneHint) use (&$rows) {
            [, $text, $kind] = $res;
            switch ($kind) {
                case 'auto':
                    $src = trim((string) preg_replace('/^EMS:?\s*/', '', $text));
                    $line = "🔗 $label: $shown (automatisch von EMS" . ($src !== '' ? ", $src" : '') . ')';
                    break;
                case 'own':
                    $line = "✏️ $label: $shown ($text)";
                    break;
                default:
                    $line = "ℹ️ $label: nicht angegeben — $noneHint";
            }
            $rows[$field] = ['line' => $line, 'lineName' => $lineName, 'visible' => $kind !== 'auto', 'color' => $kind === 'auto' ? self::COLOR_AUTO : -1];
        };

        $r = $this->resolveKwp();
        $add('PvKwp', 'PvKwpLine', 'PV-Leistung', $r, $this->fmtZahl((float) $r[0]) . ' kWp',
            'derzeit von keinem Szenario gebraucht, Eintragen ist optional');
        $r = $this->resolveWrKw();
        $add('WrKw', 'WrKwLine', 'Wechselrichter-Leistung', $r, $this->fmtZahl((float) $r[0], 1) . ' kW',
            'im Verbund liefert keine Quelle diesen Wert, derzeit von keinem Szenario gebraucht, Eintragen ist optional');
        $r = $this->resolveSpeicherKwh();
        $add('SpeicherKwh', 'SpeicherKwhLine', 'Speichergröße', $r, $this->fmtZahl((float) $r[0], 1) . ' kWh',
            'wird vom Szenario „Speichergröße“ als Ausgangspunkt gebraucht, bitte unten eintragen');
        $r = $this->resolveVerguetungCt();
        $add('EinspeiseverguetungCtKwh', 'VerguetungLine', 'Einspeisevergütung', $r, $this->fmtZahl((float) $r[0]) . ' ct/kWh',
            'wird vom Szenario „Speichergröße“ gebraucht, bitte unten eintragen');
        $r = $this->resolveInbetriebnahme();
        $add('InbetriebnahmeDatum', 'InbetriebnahmeLine', 'Inbetriebnahme', $r, $r[0] !== '' ? $this->formatAnlageDatum((string) $r[0]) : '',
            'derzeit von keinem Szenario gebraucht, Eintragen ist optional');
        return $rows;
    }

    /**
     * Knopf "Eigene Werte eingeben": blendet die verborgenen Eingabefelder nur im
     * geöffneten Formular ein (UpdateFormField), speichert nichts.
     */
    public function ShowOwnValueFields(): void
    {
        foreach (self::EIGENE_FELDER as $f) {
            $this->UpdateFormField($f, 'visible', true);
        }
        $this->UpdateFormField('ShowOwnValuesButton', 'visible', false);
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
     * Statuszeile der Preisquelle für den Rückblick des dynamischen Tarifs (Verbund-Konvention
     * "Verbindungen im Formular sichtbar machen"): zeigt, woher der Preisverlauf kommt und wie
     * viel davon vorliegt, oder was fehlt und was dann gilt.
     */
    private function buildPriceSourceStatusCaption(): string
    {
        $src = $this->dynamicPriceSource();
        if (!$src['ok']) {
            $icon = $src['level'] === 'warn' ? '⚠️' : 'ℹ️';
            return "$icon {$src['reason']}. Das Szenario „Dynamischer Vertrag“ ist so nicht verfügbar.";
        }
        $end = time();
        $n = count($this->dynamicPriceSlots($end - 2 * 86400, $end));
        $fee = $this->tibberBaseFeeMonth();
        $feeText = $fee !== null
            ? 'Grundgebühr des Tarifs aus Tibber übernommen (' . $this->fmtZahl($fee) . ' €/Monat)'
            : 'Grundgebühr des dynamischen Tarifs unbekannt, wird nicht angesetzt';
        $name = @IPS_GetName($src['emsId']);
        return '✅ Preisverlauf von EMS #' . $src['emsId'] . ($name ? " „{$name}“" : '') . ' (Bezugstarif Tibber): '
            . $n . ' Viertelstunden mit Preis in den letzten 2 Tagen. ' . $feeText . '.';
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
