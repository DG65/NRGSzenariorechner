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
    if (str_contains($g, '31C61A7B')) { return $GLOBALS['emsIds'] ?? []; }
    if (str_contains($g, 'BAB8E05C')) { return $GLOBALS['meterIds'] ?? []; }
    if (str_contains($g, 'BBE2C593')) { return $GLOBALS['ihubIds'] ?? []; }
    return [];
}
function MHUB_GetFunctions(int $id) { return json_encode($GLOBALS['mhub'][$id] ?? []); }
function IHUB_GetFunctions(int $id): array { return $GLOBALS['ihub'][$id] ?? []; }
function IPS_GetLibrary($g) { return ['Version' => '0.8.0', 'Build' => 16]; }
function IPS_GetName($id) { return 'EMS'; }
function AC_GetLoggingStatus($a, $v) { return $GLOBALS['archived'][$v] ?? true; }
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
    $GLOBALS['meterIds'] = [];
    $GLOBALS['ihubIds'] = [];
    $GLOBALS['mhub'] = [];
    $GLOBALS['ihub'] = [];
    $GLOBALS['archived'] = [];
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
$r = $m->CalculateStorageSizeScenario(2, 0);
$row20 = null;
foreach ($r['sizes'] as $x) { if ($x['storageKwh'] === 20.0) { $row20 = $x; } }
check(abs($r['netValueCtKwh'] - 22.0) < 0.001 && $r['feedInKnown'] === true, 'Speicher: Nutzen je kWh = Bezugspreis minus Vergütung = 22 ct', (string) $r['netValueCtKwh']);
// Referenz 10 kWh: Netzbezug 2 kWh/Tag = 4 kWh; 20 kWh: 0. Gespart 4 kWh * 182,5 * 0,22 = 160,60 €/Jahr.
check($row20 !== null && abs($row20['additionalSavingsEurPerYear'] - 160.60) < 0.05, 'Speicher: Zusatzersparnis 20 kWh = 160,60 €/Jahr (nicht mit vollem Bezugspreis 219 €)', json_encode($row20));
check($row20 !== null && $row20['paybackYears'] === round(10 * 500 / 160.6, 1), 'Speicher: Amortisation aus Zusatzinvestition', json_encode($row20['paybackYears'] ?? null));
check(($m->written['StorageSizeAdditionalSavingsEur'] ?? null) === 160.6, 'Speicher: Kennzahl-Variable bei vollständiger Datenlage', json_encode($m->written));

// E1b) Zielgröße außerhalb des Standardrasters wird zusätzlich simuliert und hervorgehoben
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0,
            'SpeicherKwh' => 10.0, 'ZielgroesseSpeicherKwh' => 45.0],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 0);
$row45 = null;
foreach ($r['sizes'] as $x) { if ($x['storageKwh'] === 45.0) { $row45 = $x; } }
check($row45 !== null, 'Zielgröße: 45 kWh (außerhalb 0/10/…/80) wird zusätzlich simuliert', json_encode(array_column($r['sizes'], 'storageKwh')));
check($r['target'] !== null && $r['target']['storageKwh'] === 45.0, 'Zielgröße: Ergebnis hebt den Zielpunkt gesondert hervor', json_encode($r['target']));
check($r['targetStorageKwh'] === 45.0, 'Zielgröße: wird aus der Property übernommen', (string) $r['targetStorageKwh']);

// E1c) Ohne Zielgröße bleibt "target" leer, kein zusätzlicher Rasterpunkt
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0, 'SpeicherKwh' => 10.0],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 0);
check($r['target'] === null && count($r['sizes']) === 8, 'Zielgröße: ohne Angabe bleibt es beim Standardraster (0/10/…/80, aktuelle 10 liegt schon darin)', json_encode(array_column($r['sizes'], 'storageKwh')));

// E1d) Live-Parameter (z. B. Dashboard-Slider per RequestAction) übersteuert die Property, ohne sie zu verändern
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0,
            'SpeicherKwh' => 10.0, 'ZielgroesseSpeicherKwh' => 45.0],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 70);
check($r['targetStorageKwh'] === 70.0 && $r['target']['storageKwh'] === 70.0, 'Live-Parameter: 70 kWh übersteuert die Property (45)', json_encode([$r['targetStorageKwh'], $r['target']['storageKwh'] ?? null]));

// E2) Fehlende Lastwerte werden NICHT als 0 gerechnet
$ldGap = fn($t) => ((int) date('G', $t) < 6) ? null : (((int) date('G', $t) === 20) ? 12000.0 : 0.0);
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'SpeicherKwh' => 10.0],
    ['series' => [200 => $pv, 201 => $ldGap]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 0);
check($r['coverage'] < 0.9 && $r['dataComplete'] === false && !isset($m->written['StorageSizeAdditionalSavingsEur']), 'Speicher: Lastlücken senken die Abdeckung und verhindern die Kennzahl', json_encode([$r['coverage'], $m->written]));

// E3) Ohne Vergütung wird das offen gesagt
$m = fresh(['PvErzeugungVarID' => 200, 'HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'SpeicherKwh' => 10.0],
    ['series' => [200 => $pv, 201 => $ld]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 0);
check($r['feedInKnown'] === false && str_contains($r['reason'], 'Einspeisevergütung nicht angegeben'), 'Speicher: fehlende Vergütung wird als optimistisch gemeldet', $r['reason']);


// I) Automatische Erkennung der Quellvariablen -------------------------------------------------
$grid = fn(int $vid, string $auth = 'billing', array $more = []) => array_merge(['contractVersion' => '1.3', 'authority' => $auth,
    'assignments' => [array_merge(['function' => 'grid', 'energyImportID' => $vid, 'energyKind' => 'counter', 'energyMeasured' => true, 'authority' => $auth], $more)]], []);
// I1: ein Abrechnungszähler wird ohne jede Eingabe übernommen und rechnet
$m = fresh(['FestpreisCtKwh' => 30.0], ['series' => [500 => $one], 'priceAt' => fn($t) => 20.0, 'meterIds' => [10], 'mhub' => [10 => $grid(500)]]);
$av = $m->GetAvailableScenarios()[0];
$r = $m->CalculateDynamicTariffScenario(2);
check($av['available'] === true && abs($r['savingsEur'] - 4.80) < 0.01, 'Erkennung: MeterHub-Netzzähler ohne Eingabe übernommen, Szenario rechnet', json_encode([$av, $r['savingsEur']]));
// I2: billing schlägt auxiliary
$m = fresh(['FestpreisCtKwh' => 30.0], ['series' => [501 => $one, 502 => fn() => 9.0], 'priceAt' => fn($t) => 20.0, 'meterIds' => [10, 11], 'mhub' => [10 => $grid(502, 'auxiliary'), 11 => $grid(501, 'billing')]]);
$r = $m->CalculateDynamicTariffScenario(2);
check(abs($r['consumptionKwh'] - 48.0) < 0.01, 'Erkennung: Abrechnungszähler (billing) wird dem Hilfszähler vorgezogen', (string) $r['consumptionKwh']);
// I3: zwei gleichrangige → nicht raten
$m = fresh(['FestpreisCtKwh' => 30.0], ['series' => [501 => $one, 502 => $one], 'priceAt' => fn($t) => 20.0, 'meterIds' => [10, 11], 'mhub' => [10 => $grid(501), 11 => $grid(502)]]);
$av = $m->GetAvailableScenarios()[0];
check($av['available'] === false && str_contains($av['reason'], 'Netzbezug'), 'Erkennung: zwei gleichrangige Zähler werden nicht geraten', $av['reason']);
// I4: nicht archiviert, hochgerechnet, fremder Vertrag → nicht verwendet
foreach ([['nicht archiviert', $grid(500), ['archived' => [500 => false]]],
          ['hochgerechnet', $grid(500, 'billing', ['energyMeasured' => false]), []],
          ['Vertrag 2.0', array_merge($grid(500), ['contractVersion' => '2.0']), []]] as [$name, $mh, $ex]) {
    $m = fresh(['FestpreisCtKwh' => 30.0], array_merge(['series' => [500 => $one], 'priceAt' => fn($t) => 20.0, 'meterIds' => [10], 'mhub' => [10 => $mh]], $ex));
    check($m->GetAvailableScenarios()[0]['available'] === false, "Erkennung: Zähler wird nicht verwendet ($name)");
}
// I5: eigene Eingabe überschreibt die Automatik
$m = fresh(['FestpreisCtKwh' => 30.0, 'NetzbezugVarID' => 501, 'NetzbezugIstZaehler' => true], ['series' => [500 => fn() => 9.0, 501 => $one], 'priceAt' => fn($t) => 20.0, 'meterIds' => [10], 'mhub' => [10 => $grid(500)]]);
$r = $m->CalculateDynamicTariffScenario(2);
check(abs($r['consumptionKwh'] - 48.0) < 0.01, 'Erkennung: eigene Eingabe überschreibt den automatisch gefundenen Zähler', (string) $r['consumptionKwh']);
// I6: PV aus InverterHub, Hauslast bleibt manuell
$pvW = fn($t) => ((int) date('G', $t) === 12) ? 15000.0 : 0.0;
$m = fresh(['HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0, 'SpeicherKwh' => 10.0],
    ['series' => [700 => $pvW, 201 => $ld], 'ihubIds' => [20], 'ihub' => [20 => ['contractVersion' => '1.3', 'pvPowerID' => 700]]]);
$GLOBALS['emsIds'] = [];
$av = $m->GetAvailableScenarios()[1];
$r = $m->CalculateStorageSizeScenario(2, 0);
check($av['available'] === true && count($r['sizes']) > 0, 'Erkennung: PV-Leistung aus InverterHub, Speicher-Szenario rechnet mit manueller Hauslast', json_encode($av));
// I6b: Hauslast aus MeterHub `house` (Zählerstand, vorzeichenfrei)
$houseKwh = fn($t) => ((int) date('G', $t) === 20) ? 12.0 : 0.0;   // Zähler: kWh je Stunde
$houseAssign = fn(int $vid, array $more = []) => ['contractVersion' => '1.3', 'assignments' => [array_merge(['function' => 'house', 'energyImportID' => $vid, 'energyKind' => 'counter', 'energyMeasured' => true, 'authority' => 'billing', 'powerID' => 0], $more)]];
$m = fresh(['FestpreisCtKwh' => 30.0, 'EinspeiseverguetungCtKwh' => 8.0, 'SpeicherKwh' => 10.0],
    ['series' => [700 => $pvW, 800 => $houseKwh], 'ihubIds' => [20], 'ihub' => [20 => ['contractVersion' => '1.3', 'pvPowerID' => 700]], 'meterIds' => [12], 'mhub' => [12 => $houseAssign(800)]]);
$GLOBALS['emsIds'] = [];
$r = $m->CalculateStorageSizeScenario(2, 0);
$row20 = null; foreach ($r['sizes'] as $x) { if ($x['storageKwh'] === 20.0) { $row20 = $x; } }
check($m->GetAvailableScenarios()[1]['available'] === true && $row20 !== null && abs($row20['additionalSavingsEurPerYear'] - 160.60) < 0.05, 'Hauslast: Zählerstand aus MeterHub house wird ohne Eingabe übernommen und rechnet richtig', json_encode($row20));
// I6c: Hauslast als Leistung: Vorzeichenprüfung
$pos = fn($t) => 400.0; $neg = fn($t) => -400.0; $zero = fn($t) => 0.0;
$mixed = fn($t) => (intdiv($t, 3600) % 3 === 0) ? -300.0 : 500.0;   // 33 % negativ
foreach ([['positiv', $pos, true], ['dauerhaft negativ', $neg, false], ['Median 0', $zero, false], ['über 20 % negativ', $mixed, false]] as [$name, $fnv, $expect]) {
    $m = fresh(['FestpreisCtKwh' => 30.0], ['series' => [700 => $pvW, 850 + 12 => $fnv], 'ihubIds' => [20], 'ihub' => [20 => ['contractVersion' => '1.3', 'pvPowerID' => 700]],
        'meterIds' => [12], 'mhub' => [12 => $houseAssign(0, ['energyImportID' => 0])]]);
    $GLOBALS['mhub'][12]['assignments'][0]['powerID'] = 862;
    $av = $m->GetAvailableScenarios()[1];
    check($av['available'] === $expect, "Hauslast Leistung: Vorzeichen $name → " . ($expect ? 'verwendet' : 'nicht verwendet und nicht umgedreht'), json_encode($av));
}
// I6d: Hauslast: hochgerechneter Zähler wird ignoriert, Leistung wird stattdessen geprüft
$m = fresh(['FestpreisCtKwh' => 30.0], ['series' => [700 => $pvW, 800 => $houseKwh, 862 => $pos], 'ihubIds' => [20], 'ihub' => [20 => ['contractVersion' => '1.3', 'pvPowerID' => 700]],
    'meterIds' => [12], 'mhub' => [12 => $houseAssign(800, ['energyMeasured' => false, 'powerID' => 862])]]);
check($m->GetAvailableScenarios()[1]['available'] === true, 'Hauslast: hochgerechneter Zähler wird nicht genommen, die Leistung übernimmt (nach Vorzeichenprüfung)');
// I7: mehrere Wechselrichter → keine Summe raten
$m = fresh(['HausLastVarID' => 201, 'FestpreisCtKwh' => 30.0], ['series' => [700 => $pvW, 701 => $pvW, 201 => $ld], 'ihubIds' => [20, 21],
    'ihub' => [20 => ['contractVersion' => '1.3', 'pvPowerID' => 700], 21 => ['contractVersion' => '1.3', 'pvPowerID' => 701]]]);
check($m->GetAvailableScenarios()[1]['available'] === false, 'Erkennung: mehrere Wechselrichter werden nicht zu einer geratenen Summe');

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
