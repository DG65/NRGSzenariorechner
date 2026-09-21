<?php
// Rechen-Prüfstand der Szenarien gegen nachgebildetes IPS (Muster MeterHub test-virtual.php).
// Prüft, dass die Zahlen stimmen UND dass bei dünner Datenlage nichts Erfundenes geschrieben
// wird. Aufruf:  php .tools/test-scenarios.php   (Rückgabewert 0 = grün)

if (PHP_SAPI !== 'cli') {
    exit(1);
}
date_default_timezone_set('Europe/Berlin');

// ---- Nachgebildetes IPS ------------------------------------------------------
class IPSModule
{
    public $InstanceID = 900;
    public $written = [];      // SetValue-Aufrufe
    public $logged = [];       // LogMessage-Aufrufe
    public function __construct() {}
    public function SendDebug(...$a) {}
    public function ReadPropertyInteger($n) { return $GLOBALS['props'][$n] ?? 0; }
    public function ReadPropertyFloat($n) { return (float) ($GLOBALS['props'][$n] ?? 0.0); }
    public function ReadPropertyString($n) { return (string) ($GLOBALS['props'][$n] ?? ''); }
    public function ReadPropertyBoolean($n) { return (bool) ($GLOBALS['props'][$n] ?? false); }
    public function ReadAttributeString($n) { return ''; }
    public function ReadAttributeBoolean($n) { return false; }
    public function ReadAttributeInteger($n) { return 0; }
    public function WriteAttributeString($n, $v) {}
    public function SetValue($i, $v) { $this->written[$i] = $v; }
    public function LogMessage($m, $l) { $this->logged[] = $m; }
}
const KL_WARNING = 103;
function IPS_VariableExists($id) { return true; }
function IPS_ModuleExists($g) { return true; }
function IPS_GetInstanceListByModuleID($g)
{
    if (str_contains($g, '43192F0B')) { return [1]; }            // Archive Control
    return str_contains($g, '31C61A7B') ? ($GLOBALS['emsIds'] ?? []) : [];
}
function IPS_GetLibrary($g) { return ['Version' => '0.8.0', 'Build' => 16]; }
function IPS_GetName($id) { return 'EMS'; }
function AC_GetLoggingStatus($a, $v) { return true; }
function AC_GetAggregatedValues($a, $var, $lvl, $from, $to, $lim)
{
    $gapDay = $GLOBALS['gapDayStart'] ?? null;
    if ($gapDay !== null && $from === $gapDay) { return false; }
    $rows = [];
    for ($t = $from; $t < $to; $t += 3600) {
        $v = $GLOBALS['series'][$var]($t);
        if ($v !== null) { $rows[] = ['TimeStamp' => $t, 'Avg' => $v]; }
    }
    return $rows;
}
function EMS_GetPurchasePriceHistory(int $id, int $from, int $to): array
{
    $slots = [];
    for ($t = intdiv($from, 3600) * 3600; $t < $to; $t += 900) {
        $ct = $GLOBALS['priceAt']($t);
        if ($ct !== null) { $slots[] = ['start' => $t, 'end' => $t + 900, 'priceCt' => $ct, 'quelle' => 'tibber-archiv']; }
    }
    return ['contractVersion' => '1.0', 'einheit' => 'ct/kWh brutto', 'tarifart' => 'tibber', 'slots' => $slots];
}

require dirname(__DIR__) . '/Szenariorechner/module.php';

// ---- Prüfrahmen ---------------------------------------------------------------
$errors = 0;
function check(bool $ok, string $name, string $detail = ''): void
{
    global $errors;
    if (!$ok) { $errors++; }
    echo ($ok ? 'grün  ' : 'ROT   ') . $name . ($ok ? '' : "  -> $detail") . "\n";
}
function fresh(array $props, array $extra = []): Szenariorechner
{
    $GLOBALS['props'] = $props;
    $GLOBALS['emsIds'] = [55472];
    $GLOBALS['gapDayStart'] = null;
    foreach ($extra as $k => $v) { $GLOBALS[$k] = $v; }
    return new Szenariorechner();
}
$one = fn() => 1.0;   // 1 kWh je Stunde (Zählerverbrauch)

// A) Dynamischer Vertrag: vollständige Abdeckung ------------------------------------
$m = fresh(['NetzbezugVarID' => 100, 'NetzbezugIstZaehler' => true, 'FestpreisCtKwh' => 30.0],
    ['series' => [100 => $one], 'priceAt' => fn($t) => 20.0]);
$r = $m->CalculateDynamicTariffScenario(2);
check($r['consumptionHours'] === 48 && $r['coveredHours'] === 48, 'dynamisch: 48 Stunden, alle mit Preis', json_encode([$r['consumptionHours'], $r['coveredHours']]));
check(abs($r['costFixedEur'] - 14.40) < 0.01 && abs($r['costDynamicEur'] - 9.60) < 0.01 && abs($r['savingsEur'] - 4.80) < 0.01, 'dynamisch: Kosten 14,40 vs 9,60, Ersparnis 4,80', json_encode([$r['costFixedEur'], $r['costDynamicEur'], $r['savingsEur']]));
check($r['dataComplete'] === true && ($m->written['DynamicTariffSavingsEur'] ?? null) === 4.8, 'dynamisch: Variable wird bei vollständiger Datenlage geschrieben');
check(date('H:i', $r['periodFrom']) === '00:00' && date('H:i', $r['periodTo']) === '00:00', 'dynamisch: Zeitraum beginnt/endet um 00:00 (2 Tage)');

// A2) Zeitumstellung: 200 Tage überspannen einen Wechsel, Grenze muss trotzdem 00:00 sein
$m = fresh(['NetzbezugVarID' => 100, 'NetzbezugIstZaehler' => true, 'FestpreisCtKwh' => 30.0],
    ['series' => [100 => fn() => null], 'priceAt' => fn($t) => null]);
$r = $m->CalculateDynamicTariffScenario(200);
check(date('H:i', $r['periodFrom']) === '00:00', 'Zeitumstellung: Beginn nach 200 Tagen ist 00:00 (nicht 23:00/01:00)', date('c', $r['periodFrom']));

// B) Lücken bei den Preisen: nur abgedeckte Stunden zählen, nichts wird erfunden ----------
$m = fresh(['NetzbezugVarID' => 100, 'NetzbezugIstZaehler' => true, 'FestpreisCtKwh' => 30.0],
    ['series' => [100 => $one], 'priceAt' => fn($t) => ((int) date('G', $t) < 6) ? null : 20.0]);
$r = $m->CalculateDynamicTariffScenario(2);
check($r['coveredHours'] === 36 && $r['consumptionHours'] === 48, 'Preislücken: 36 von 48 Stunden abgedeckt', json_encode([$r['coveredHours'], $r['consumptionHours']]));
check(abs($r['savingsEur'] - 3.60) < 0.01, 'Preislücken: Ersparnis nur über abgedeckte Stunden (3,60, nicht mit Ersatzpreis gefüllt)', (string) $r['savingsEur']);
check($r['dataComplete'] === false && !isset($m->written['DynamicTariffSavingsEur']) && str_contains($r['reason'], '36 von 48'), 'Preislücken: Abdeckung 75 % → keine Variable, Grund nennt 36 von 48', $r['reason']);

// C) Archivlücke: false ist ein Fehler, kein "keine Daten" ----------------------------------
$gapDay = strtotime('-1 day', strtotime('today midnight'));
$m = fresh(['NetzbezugVarID' => 100, 'NetzbezugIstZaehler' => true, 'FestpreisCtKwh' => 30.0],
    ['series' => [100 => $one], 'priceAt' => fn($t) => 20.0, 'gapDayStart' => $gapDay]);
$GLOBALS['gapDayStart'] = $gapDay;
$r = $m->CalculateDynamicTariffScenario(2);
check($r['gapDays'] === 1 && $r['dataComplete'] === false && count($m->logged) >= 1, 'Archivlücke: 1 Tag gezählt, Ergebnis unvollständig, dauerhaft geloggt', json_encode([$r['gapDays'], $m->logged]));

// D) Ohne Festpreis rechnet nichts -----------------------------------------------------------
$m = fresh(['NetzbezugVarID' => 100, 'NetzbezugIstZaehler' => true, 'FestpreisCtKwh' => 0.0],
    ['series' => [100 => $one], 'priceAt' => fn($t) => 20.0]);
$r = $m->CalculateDynamicTariffScenario(2);
check($r['reason'] !== '' && str_contains($r['reason'], 'Festpreis') && $r['savingsEur'] === 0.0 && $m->written === [], 'Festpreis fehlt: kein Ergebnis, Grund genannt, nichts geschrieben', $r['reason']);

// E) Speichergröße: Einspeisevergütung wird abgezogen -----------------------------------------
// Je Tag 15 kWh Überschuss um 12 Uhr, nachts 12 kWh Last um 20 Uhr (Leistung in W).
$pv = fn($t) => ((int) date('G', $t) === 12) ? 15000.0 : 0.0;
$ld = fn($t) => ((int) date('G', $t) === 20) ? 12000.0 : 0.0;
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0,
            'SpeicherKwh' => 10.0, 'SpeicherPreisEurKwh' => 500.0, 'SpeicherAbschreibungJahre' => 15],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];   // ohne EMS: Werte kommen aus den eigenen Eingaben
$r = $m->CalculateStorageSizeScenario(2);
$row20 = null;
foreach ($r['sizes'] as $x) { if ($x['storageKwh'] === 20.0) { $row20 = $x; } }
check(abs($r['netValueCtKwh'] - 22.0) < 0.001 && $r['feedInKnown'] === true, 'Speicher: Nutzen je kWh = Bezugspreis minus Vergütung = 22 ct', (string) $r['netValueCtKwh']);
// Referenz 10 kWh: Netzbezug 2 kWh/Tag = 4 kWh; 20 kWh: 0. Gespart 4 kWh * 182,5 * 0,22 = 160,60 €/Jahr.
check($row20 !== null && abs($row20['additionalSavingsEurPerYear'] - 160.60) < 0.05, 'Speicher: Zusatzersparnis 20 kWh = 160,60 €/Jahr (nicht mit vollem Bezugspreis 219 €)', json_encode($row20));
check($row20 !== null && $row20['paybackYears'] === round(10 * 500 / 160.6, 1), 'Speicher: Amortisation aus Zusatzinvestition', json_encode($row20['paybackYears'] ?? null));
check(($m->written['StorageSizeAdditionalSavingsEur'] ?? null) === 160.6, 'Speicher: Kennzahl-Variable bei vollständiger Datenlage', json_encode($m->written));

// E2) Fehlende Lastwerte werden NICHT als 0 gerechnet
$ldGap = fn($t) => ((int) date('G', $t) < 6) ? null : (((int) date('G', $t) === 20) ? 12000.0 : 0.0);
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'SpeicherKwh' => 10.0],
    ['series' => [200 => $pv, 201 => $ldGap]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2);
check($r['coverage'] < 0.9 && $r['dataComplete'] === false && !isset($m->written['StorageSizeAdditionalSavingsEur']), 'Speicher: Lastlücken senken die Abdeckung und verhindern die Kennzahl', json_encode([$r['coverage'], $m->written]));

// E3) Ohne Vergütung wird das offen gesagt
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'SpeicherKwh' => 10.0],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2);
check($r['feedInKnown'] === false && str_contains($r['reason'], 'Einspeisevergütung nicht angegeben'), 'Speicher: fehlende Vergütung wird als optimistisch gemeldet', $r['reason']);

// F) §14a: ohne Annahmen kein Ergebnis, mit Annahmen nachrechenbar -----------------------------
$m = fresh([], ['series' => []]);
$r = $m->CalculateParagraph14aScenario();
check($r['reason'] !== '' && $m->written === [], '§14a: ohne Angaben kein Ergebnis und nichts geschrieben', $r['reason']);
$m = fresh(['Paragraph14aNetzentgeltErsparnisJahr' => 150.0, 'Paragraph14aAnnahmeEreignisseJahr' => 20, 'Paragraph14aAnnahmeDauerMinuten' => 120,
            'Paragraph14aAnnahmeReduktionKw' => 4.2, 'FestpreisCtKwh' => 30.0], ['series' => []]);
$r = $m->CalculateParagraph14aScenario();
check(abs($r['betroffeneEnergieJahrKwh'] - 168.0) < 0.05 && abs($r['nettoNutzenJahr'] - 99.6) < 0.01, '§14a: 168 kWh × 30 ct = 50,40 €, Netto 99,60 €', json_encode([$r['betroffeneEnergieJahrKwh'], $r['nettoNutzenJahr']]));

// G) Standardwerte: keine erfundenen Zahlen in den Voreinstellungen -----------------------------
$src = file_get_contents(dirname(__DIR__) . '/Szenariorechner/module.php');
preg_match_all("/RegisterProperty(?:Float|Integer)\('([A-Za-z0-9]+)', ([0-9.]+)\)/", $src, $mm, PREG_SET_ORDER);
$nonZero = array_filter($mm, fn($x) => (float) $x[2] !== 0.0);
check(count($nonZero) === 0, 'Voreinstellungen: alle Zahlen-Properties stehen auf 0 (= nicht angegeben)', json_encode(array_column($nonZero, 1)));

// H) Kein @ vor Fremdaufrufen (fängt keinen Error, SUITE.md Stolperstein 8/13)
check(!preg_match('/@(EMS_|TIBBERGR_|NRGDASH_|SBH_|PVF_|LFC_)/', $src), 'Fremdaufrufe ohne @ (try/catch statt Unterdrückung)');

echo $errors === 0 ? "\nAlle Prüfungen grün.\n" : "\n$errors Prüfung(en) rot.\n";
exit($errors === 0 ? 0 : 1);
