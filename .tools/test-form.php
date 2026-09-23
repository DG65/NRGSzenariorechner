<?php
// Prüfstand für die Statuszeilen im Konfigurationsformular (Verbund-Konvention
// "Verbindungen im Formular sichtbar machen", SUITE.md, 21.09.2026).
//
// Prüft nach GetConfigurationForm(), dass jede Statuszeile im ausgelieferten JSON
// steht (auch in verschachtelten Panels), der statische Platzhalter weg ist und der
// Inhalt zum Verbindungszustand passt. Aufruf:  php .tools/test-form.php
// Rückgabewert 0 = alles grün.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$scenario = $argv[1] ?? null;

if ($scenario === null) {
    $fails = 0;
    foreach (['ems_ok', 'ems_fehlt', 'ems_keins', 'ems_mehrere', 'ems_vertrag', 'ems_eigen', 'ems_einstellung', 'preis_ok', 'preis_fest', 'preis_keins', 'quelle_ok', 'quelle_mehrere', 'quelle_keins', 'haus_vorzeichen'] as $sc) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $sc, $rc);
        $fails += $rc === 0 ? 0 : 1;
    }
    echo $fails === 0 ? "\nAlle Prüfungen grün.\n" : "\n$fails Szenario(en) rot.\n";
    exit($fails === 0 ? 0 : 1);
}

// ---- Nachgebildetes IPS ------------------------------------------------------
class IPSModule
{
    public $InstanceID = 900;
    public function __construct() {}
    public function SendDebug(...$a) {}
    public function ReadPropertyInteger($n) { return $GLOBALS['props'][$n] ?? 0; }
    public function ReadPropertyFloat($n) { return (float) ($GLOBALS['props'][$n] ?? 0.0); }
    public function ReadPropertyString($n) { return (string) ($GLOBALS['props'][$n] ?? ''); }
    public function ReadPropertyBoolean($n) { return (bool) ($GLOBALS['props'][$n] ?? false); }
    public function ReadAttributeString($n) { return ''; }
    public function ReadAttributeBoolean($n) { return false; }
    public function ReadAttributeInteger($n) { return 0; }
    public function LogMessage($m, $l) {}
    public function SetValue($i, $v) {}
    public function WriteAttributeString($n, $v) {}
}
const KL_WARNING = 103;
function IPS_GetLibrary($g) { return ['Version' => '0.10.0-beta.1', 'Build' => 20]; }
function IPS_GetName($id) { return 'EMS'; }

$props = [];
$emsIds = [];
$tibberIds = [];
$meterIds = [];
$ihubIds = [];
$houseCounter = true;
$houseVals = 400.0;
$tarif = 'tibber';
$plant = [
    'contractVersion' => '1.1', 'inbetriebnahme' => '2012-10-24', 'kwp' => 9.18, 'kwpQuelle' => 'prognose',
    'speicherKwh' => 40.0, 'speicherKwhQuelle' => 'wechselrichter', 'verguetungCt' => 18.36,
    'verguetungQuelle' => 'berechnet', 'verguetungGeprueft' => true, 'foerderende' => '2032-12-31',
    'pflichten' => [['code' => 'negativpreis', 'text' => 'x'], ['code' => 'einspeisung60', 'text' => 'y']],
];
$tibberSlots = [['start' => 1000, 'end' => 1900, 'price' => 25.0], ['start' => 1900, 'end' => 2800, 'price' => 26.0]];

switch ($scenario) {
    case 'ems_ok': $emsIds = [55472]; break;
    case 'ems_fehlt': $emsIds = [55472]; $plant['kwp'] = 0.0; $plant['kwpQuelle'] = 'fehlt'; break;
    case 'ems_keins': break;
    case 'ems_mehrere': $emsIds = [55472, 55473]; break;
    case 'ems_vertrag': $emsIds = [55472]; $plant['contractVersion'] = '2.0'; break;
    case 'ems_eigen': $emsIds = [55472]; $props['PvKwp'] = 10.0; break;
    case 'ems_einstellung': $emsIds = [55472]; $plant['speicherKwhQuelle'] = 'einstellung'; $plant['speicherKwh'] = 10.0; break;
    case 'preis_ok': $emsIds = [55472]; break;
    case 'preis_fest': $emsIds = [55472]; $tarif = 'fest'; break;
    case 'preis_keins': break;
    case 'quelle_ok': $meterIds = [10]; $ihubIds = [20]; break;
    case 'quelle_mehrere': $meterIds = [10, 11]; $ihubIds = [20, 21]; break;
    case 'quelle_keins': break;
    case 'haus_vorzeichen': $meterIds = [10]; $houseCounter = false; $houseVals = -400.0; break;
}
if ($scenario !== 'ems_keins') {
    // EMS-Funktion existiert in allen Szenarien außer "kein EMS installiert".
    eval('function EMS_GetPlantInfo($id) { return $GLOBALS["plant"]; }');
}
if ($scenario !== 'ems_keins' && $scenario !== 'preis_keins') {
    eval('function EMS_GetPurchasePriceHistory(int $id, int $from, int $to): array { return ["contractVersion" => "1.0", "einheit" => "ct/kWh brutto", "tarifart" => $GLOBALS["tarif"], "slots" => [["start" => $from, "end" => $from + 900, "priceCt" => 25.0, "quelle" => "tibber-archiv"], ["start" => $from + 900, "end" => $from + 1800, "priceCt" => 27.0, "quelle" => "tibber"]]]; }');
}
function IPS_GetInstanceListByModuleID($g)
{
    if (str_contains($g, '43192F0B')) { return [1]; }
    if (str_contains($g, 'BAB8E05C')) { return $GLOBALS['meterIds']; }
    if (str_contains($g, 'BBE2C593')) { return $GLOBALS['ihubIds']; }
    return str_contains($g, '31C61A7B') ? $GLOBALS['emsIds'] : $GLOBALS['tibberIds'];
}
function IPS_ModuleExists($g) { return true; }
function IPS_VariableExists($id) { return true; }
function AC_GetLoggingStatus($a, $v) { return true; }
function MHUB_GetFunctions(int $id)
{
    $house = $GLOBALS['houseCounter']
        ? ['function' => 'house', 'energyImportID' => 800 + $id, 'energyKind' => 'counter', 'energyMeasured' => true, 'powerID' => 850 + $id, 'authority' => 'billing']
        : ['function' => 'house', 'energyImportID' => 0, 'powerID' => 850 + $id, 'authority' => 'billing'];
    return json_encode(['contractVersion' => '1.3', 'assignments' => [
        ['function' => 'grid', 'energyImportID' => 500 + $id, 'energyKind' => 'counter', 'energyMeasured' => true, 'authority' => 'billing'], $house]]);
}
function AC_GetAggregatedValues($a, $var, $lvl, $from, $to, $lim)
{
    $rows = [];
    for ($t = $from; $t < $to; $t += 3600) { $rows[] = ['TimeStamp' => $t, 'Avg' => $GLOBALS['houseVals']]; }
    return $rows;
}
function IHUB_GetFunctions(int $id): array { return ['contractVersion' => '1.3', 'pvPowerID' => 700 + $id]; }

require dirname(__DIR__) . '/Szenariorechner/module.php';

// ---- Prüfung ------------------------------------------------------------------
function findEl(array $items, string $name): ?array
{
    foreach ($items as $el) {
        if (($el['name'] ?? '') === $name) {
            return $el;
        }
        if (isset($el['items']) && ($f = findEl($el['items'], $name)) !== null) {
            return $f;
        }
    }
    return null;
}

$errors = [];
$check = function (bool $ok, string $msg) use (&$errors) {
    if (!$ok) {
        $errors[] = $msg;
    }
};

$form = json_decode((new Szenariorechner())->GetConfigurationForm(), true);
$check(is_array($form), 'GetConfigurationForm liefert kein JSON');
$el = $form['elements'];

foreach (['PlantInfoStatusLabel', 'PriceSourceStatusLabel', 'DocVersionLabel', 'NetztransparenzStatusLabel'] as $n) {
    $e = findEl($el, $n);
    $check($e !== null, "$n fehlt im Formular");
}
$plantLine = findEl($el, 'PlantInfoStatusLabel')['caption'] ?? '';
$tibLine = findEl($el, 'PriceSourceStatusLabel')['caption'] ?? '';
$verLine = findEl($el, 'DocVersionLabel')['caption'] ?? '';

$check(!str_contains($plantLine, 'wird geprüft') && !str_contains($plantLine, 'sobald installiert'), "Anlagendaten: statischer Platzhalter steht noch: $plantLine");
$check(!str_contains($tibLine, 'wird geprüft'), "Preisquelle: statischer Platzhalter steht noch: $tibLine");
$check(str_contains($verLine, 'Version 0.10.0'), "Versionszeile nicht ersetzt: $verLine");

switch ($scenario) {
    case 'ems_ok':
        $check(str_starts_with($plantLine, '✅'), "erwartet ✅: $plantLine");
        foreach (['#55472 „EMS“', '9,18 kWp (EMS: PV-Prognose)', 'Speicher 40 kWh (EMS: Wechselrichter gemessen)', '18,36 ct/kWh (EMS: aus EEG-Tabelle berechnet, geprüft)', '24.10.2012', 'Förderende 31.12.2032', 'negativpreis'] as $t) {
            $check(str_contains($plantLine, $t), "fehlt in Zeile: $t");
        }
        break;
    case 'ems_fehlt':
        $check(str_starts_with($plantLine, '⚠️') && str_contains($plantLine, 'fehlt: kWp'), "erwartet ⚠️ mit fehlt: kWp: $plantLine");
        break;
    case 'ems_keins':
        $check(str_starts_with($plantLine, 'ℹ️') && str_contains($plantLine, 'nicht angegeben'), "erwartet ℹ️ mit 'nicht angegeben': $plantLine");
        break;
    case 'ems_mehrere':
        $check(str_starts_with($plantLine, '⚠️') && str_contains($plantLine, '#55472') && str_contains($plantLine, '#55473'), "erwartet ⚠️ mit beiden IDs: $plantLine");
        break;
    case 'ems_vertrag':
        $check(str_starts_with($plantLine, '⚠️') && str_contains($plantLine, '2.0'), "erwartet ⚠️ mit Vertrag 2.0: $plantLine");
        break;
    case 'preis_ok':
        $check(str_starts_with($tibLine, '✅') && str_contains($tibLine, 'EMS #55472') && str_contains($tibLine, 'Bezugstarif Tibber'), "erwartet ✅ Preisquelle: $tibLine");
        break;
    case 'preis_fest':
        $check(str_starts_with($tibLine, 'ℹ️') && str_contains($tibLine, 'fest'), "erwartet ℹ️ (Bezugstarif fest): $tibLine");
        break;
    case 'preis_keins':
        $check(str_starts_with($tibLine, 'ℹ️'), "erwartet ℹ️ Preisquelle: $tibLine");
        break;
}


// ---- Eingabefelder: Zeile + Sichtbarkeit je Zustand -----------------------------
$lineOf = fn(string $n) => findEl($el, $n)['caption'] ?? '';
$visOf = fn(string $n) => findEl($el, $n)['visible'] ?? null;
$colOf = fn(string $n) => findEl($el, $n)['color'] ?? null;
$src = ['NetzbezugVarID' => 'NetzbezugLine', 'PvErzeugungVarID' => 'PvErzeugungLine', 'HausLastVarID' => 'HausLastLine'];
foreach ($src as $in => $ln) {
    $check(findEl($el, $in) !== null && findEl($el, $ln) !== null && !str_contains($lineOf($ln), '…'), "$in/$ln fehlt oder Platzhalter steht noch");
    $check(!array_key_exists('value', findEl($el, $in) ?? []), "$in: enthält 'value'");
}
$inputs = ['PvKwp' => 'PvKwpLine', 'WrKw' => 'WrKwLine', 'SpeicherKwh' => 'SpeicherKwhLine', 'EinspeiseverguetungCtKwh' => 'VerguetungLine', 'InbetriebnahmeDatum' => 'InbetriebnahmeLine'];
foreach ($inputs as $in => $ln) {
    $check(findEl($el, $in) !== null && findEl($el, $ln) !== null, "$in/$ln fehlt im Formular");
    $check(!str_contains($lineOf($ln), '…'), "$ln: Platzhalter steht noch");
    // Farbe je Zustand: nur 🔗 grün (0x2E8B3D), sonst Standardfarbe -1.
    $auto = str_starts_with($lineOf($ln), '🔗');
    $check($colOf($ln) === ($auto ? 0x2E8B3D : -1), "$ln: Farbe " . var_export($colOf($ln), true) . ($auto ? ' statt grün' : ' statt Standard (-1)'));
    // Der automatische Wert darf nie ins Eingabefeld geschrieben werden (sonst würde "Übernehmen" ihn speichern).
    $check(!array_key_exists('value', findEl($el, $in) ?? []), "$in: enthält 'value' - automatischer Wert im Eingabefeld");
}
$mehrere = $scenario === 'ems_mehrere';
$check($visOf('EmsInstanceID') === $mehrere, 'EmsInstanceID sichtbar=' . var_export($visOf('EmsInstanceID'), true) . ' (erwartet ' . var_export($mehrere, true) . ')');

switch ($scenario) {
    case 'quelle_ok':
        $check(str_starts_with($lineOf('NetzbezugLine'), '🔗') && $colOf('NetzbezugLine') === 0x2E8B3D && $visOf('NetzbezugVarID') === false, 'Netzbezug automatisch: 🔗, grün, Eingabefeld verborgen: ' . $lineOf('NetzbezugLine'));
        $check(str_contains($lineOf('NetzbezugLine'), 'MeterHub #10') && str_contains($lineOf('NetzbezugLine'), 'Abrechnungszähler'), 'Netzbezug nennt Instanz und Zählerart');
        $check(str_starts_with($lineOf('PvErzeugungLine'), '🔗') && $visOf('PvErzeugungVarID') === false, 'PV automatisch: 🔗, Eingabefeld verborgen: ' . $lineOf('PvErzeugungLine'));
        $check(str_starts_with($lineOf('HausLastLine'), '🔗') && $visOf('HausLastVarID') === false && str_contains($lineOf('HausLastLine'), 'Hausverbrauch'), 'Hauslast automatisch (Zählerstand): 🔗, Feld verborgen: ' . $lineOf('HausLastLine'));
        $check($visOf('ShowOwnValuesButton') === true, 'Knopf für eigene Werte sichtbar, solange etwas verborgen ist');
        break;
    case 'quelle_mehrere':
        $check(str_starts_with($lineOf('NetzbezugLine'), '⚠️') && $visOf('NetzbezugVarID') === true, 'Netzbezug mehrere: ⚠️ und Feld sichtbar: ' . $lineOf('NetzbezugLine'));
        $check(str_starts_with($lineOf('PvErzeugungLine'), '⚠️') && $visOf('PvErzeugungVarID') === true, 'PV mehrere: ⚠️ und Feld sichtbar: ' . $lineOf('PvErzeugungLine'));
        break;
    case 'haus_vorzeichen':
        $check(str_starts_with($lineOf('HausLastLine'), '⚠️') && str_contains($lineOf('HausLastLine'), 'dauerhaft negativ') && $visOf('HausLastVarID') === true && str_contains($lineOf('HausLastLine'), 'nicht umgedreht'), 'Hauslast dauerhaft negativ: ⚠️, Konfigurationsfehler, nicht umgedreht, Feld sichtbar: ' . $lineOf('HausLastLine'));
        break;
    case 'quelle_keins':
        $check(str_starts_with($lineOf('NetzbezugLine'), 'ℹ️') && $visOf('NetzbezugVarID') === true, 'Netzbezug ohne MeterHub: ℹ️, Feld sichtbar');
        break;
    case 'ems_ok':
        $check($lineOf('PvKwpLine') === '🔗 PV-Leistung: 9,18 kWp (automatisch von EMS, PV-Prognose)', 'PvKwpLine: ' . $lineOf('PvKwpLine'));
        $check(str_starts_with($lineOf('SpeicherKwhLine'), '🔗') && str_contains($lineOf('SpeicherKwhLine'), '40 kWh'), 'SpeicherKwhLine: ' . $lineOf('SpeicherKwhLine'));
        $check(str_contains($lineOf('InbetriebnahmeLine'), '24.10.2012'), 'InbetriebnahmeLine: ' . $lineOf('InbetriebnahmeLine'));
        foreach (['PvKwp', 'SpeicherKwh', 'EinspeiseverguetungCtKwh', 'InbetriebnahmeDatum'] as $in) {
            $check($visOf($in) === false, "$in muss verborgen sein");
        }
        $check($visOf('WrKw') === true && str_starts_with($lineOf('WrKwLine'), 'ℹ️'), 'WrKw (keine Quelle im Verbund) muss sichtbar sein mit ℹ️: ' . $lineOf('WrKwLine'));
        $check($visOf('ShowOwnValuesButton') === true, 'Knopf "Eigene Werte" muss sichtbar sein');
        break;
    case 'ems_fehlt':
        $check($visOf('PvKwp') === true && str_starts_with($lineOf('PvKwpLine'), 'ℹ️'), 'kWp fehlt: Feld sichtbar mit ℹ️: ' . $lineOf('PvKwpLine'));
        $check($visOf('SpeicherKwh') === false, 'Speicher kommt automatisch: Feld verborgen');
        break;
    case 'ems_keins':
        foreach ($inputs as $in => $ln) {
            $check($visOf($in) === true && str_starts_with($lineOf($ln), 'ℹ️'), "$in ohne EMS: Feld sichtbar mit ℹ️: " . $lineOf($ln));
        }
        $check($visOf('ShowOwnValuesButton') === false, 'ohne EMS ist nichts verborgen, Knopf muss weg sein');
        break;
    case 'ems_eigen':
        $check($visOf('PvKwp') === true && str_starts_with($lineOf('PvKwpLine'), '✏️') && str_contains($lineOf('PvKwpLine'), 'überschreibt'), 'eigene Eingabe: ✏️, überschreibt EMS, Feld sichtbar: ' . $lineOf('PvKwpLine'));
        $check($visOf('SpeicherKwh') === false, 'Speicher weiter automatisch: Feld verborgen');
        break;
    case 'ems_einstellung':
        $check(str_contains($lineOf('SpeicherKwhLine'), 'unbestätigt'), 'EMS-Einstellung muss als unbestätigt erkennbar sein: ' . $lineOf('SpeicherKwhLine'));
        break;
}


// ---- Verbund-Konventionen am ausgelieferten Formular (SUITE.md) --------------------
$els = $form['elements'];
$check(($els[0]['name'] ?? '') === 'PurposeIntroPanel' && ($els[0]['expanded'] ?? false) === true, '"Wozu dieses Modul?" muss ganz oben stehen und aufgeklappt sein');
$check(str_contains($els[1]['caption'] ?? '', 'Neu in Version 0.9'), 'Neu-Panel muss die Versionsnummer in der Caption tragen: ' . ($els[1]['caption'] ?? ''));
$last = end($els);
$check(str_contains($last['caption'] ?? '', 'Über dieses Modul') && !isset($last['name']), '"Über dieses Modul" muss ganz unten stehen und darf nicht dismissible sein (kein name)');
$forum = findEl($els, 'ForumHint');
$linkOk = false;
foreach ($forum['items'] ?? [] as $it) {
    if (($it['link'] ?? null) === true && str_starts_with((string) ($it['onClick'] ?? ''), "echo '")) { $linkOk = true; }
}
$check($linkOk, 'Feedback-Hinweis braucht einen Link-Button im Muster onClick=echo …, link=true (nie die URL in link)');
$check(!str_contains(json_encode($form, JSON_UNESCAPED_UNICODE), 'Link folgt'), 'Hinweis "Link folgt" darf nicht stehen bleiben');
$codes = array_column($form['status'] ?? [], 'code');
$check(in_array(102, $codes, true) && in_array(104, $codes, true), 'form.json["status"] muss 102 und 104 beschriften');
$walk = function (array $items) use (&$walk, $check) {
    foreach ($items as $it) {
        if (($it['type'] ?? '') === 'PopupButton') {
            $c = (string) ($it['caption'] ?? '');
            $check(substr_count($c, '?') === 1 && str_ends_with($c, '?'), "PopupButton-Caption braucht genau ein Fragezeichen am Ende: $c");
        }
        if (isset($it['items'])) { $walk($it['items']); }
    }
};
$walk($els);
// Keine erfundenen Standardwerte: ohne Angaben darf kein Szenario "verfügbar" sein.
$sc = (new Szenariorechner())->GetAvailableScenarios();
foreach ($sc as $one) {
    $check($one['available'] === false && $one['reason'] !== '', 'Ohne Angaben darf Szenario ' . $one['type'] . ' nicht verfügbar sein und muss einen Grund nennen');
}

if ($errors) {
    echo "ROT   $scenario\n";
    foreach ($errors as $e) {
        echo "        - $e\n";
    }
    exit(1);
}
echo "grün  $scenario\n";
exit(0);
