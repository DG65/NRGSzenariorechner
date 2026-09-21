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
    foreach (['ems_ok', 'ems_fehlt', 'ems_keins', 'ems_mehrere', 'ems_vertrag', 'ems_eigen', 'ems_einstellung', 'tibber_ok', 'tibber_leer', 'tibber_keins'] as $sc) {
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
}
function IPS_GetLibrary($g) { return ['Version' => '0.7.0-beta.1', 'Build' => 15]; }
function IPS_GetName($id) { return 'EMS'; }

$props = [];
$emsIds = [];
$tibberIds = [];
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
    case 'tibber_ok': $tibberIds = [60001]; break;
    case 'tibber_leer': $tibberIds = [60001]; $tibberSlots = []; break;
    case 'tibber_keins': break;
}
if ($scenario !== 'ems_keins') {
    // EMS-Funktion existiert in allen Szenarien außer "kein EMS installiert".
    eval('function EMS_GetPlantInfo($id) { return $GLOBALS["plant"]; }');
}
if ($scenario !== 'tibber_keins') {
    eval('function TIBBERGR_GetPriceCurve(int $id): array { return $GLOBALS["tibberSlots"]; }');
}
function IPS_GetInstanceListByModuleID($g)
{
    return str_contains($g, '31C61A7B') ? $GLOBALS['emsIds'] : $GLOBALS['tibberIds'];
}

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

foreach (['PlantInfoStatusLabel', 'TibberStatusLabel', 'DocVersionLabel', 'NetztransparenzStatusLabel'] as $n) {
    $e = findEl($el, $n);
    $check($e !== null, "$n fehlt im Formular");
}
$plantLine = findEl($el, 'PlantInfoStatusLabel')['caption'] ?? '';
$tibLine = findEl($el, 'TibberStatusLabel')['caption'] ?? '';
$verLine = findEl($el, 'DocVersionLabel')['caption'] ?? '';

$check(!str_contains($plantLine, 'wird geprüft') && !str_contains($plantLine, 'sobald installiert'), "Anlagendaten: statischer Platzhalter steht noch: $plantLine");
$check(!str_contains($tibLine, 'wird geprüft'), "Tibber: statischer Platzhalter steht noch: $tibLine");
$check(str_contains($verLine, 'Version 0.7.0'), "Versionszeile nicht ersetzt: $verLine");

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
    case 'tibber_ok':
        $check(str_starts_with($tibLine, '✅') && str_contains($tibLine, '#60001') && str_contains($tibLine, '2 Zeitabschnitte'), "erwartet ✅ Tibber: $tibLine");
        break;
    case 'tibber_leer':
        $check(str_starts_with($tibLine, '⚠️'), "erwartet ⚠️ Tibber: $tibLine");
        break;
    case 'tibber_keins':
        $check(str_starts_with($tibLine, 'ℹ️'), "erwartet ℹ️ Tibber: $tibLine");
        break;
}


// ---- Eingabefelder: Zeile + Sichtbarkeit je Zustand -----------------------------
$lineOf = fn(string $n) => findEl($el, $n)['caption'] ?? '';
$visOf = fn(string $n) => findEl($el, $n)['visible'] ?? null;
$inputs = ['PvKwp' => 'PvKwpLine', 'WrKw' => 'WrKwLine', 'SpeicherKwh' => 'SpeicherKwhLine', 'EinspeiseverguetungCtKwh' => 'VerguetungLine', 'InbetriebnahmeDatum' => 'InbetriebnahmeLine'];
foreach ($inputs as $in => $ln) {
    $check(findEl($el, $in) !== null && findEl($el, $ln) !== null, "$in/$ln fehlt im Formular");
    $check(!str_contains($lineOf($ln), '…'), "$ln: Platzhalter steht noch");
    // Der automatische Wert darf nie ins Eingabefeld geschrieben werden (sonst würde "Übernehmen" ihn speichern).
    $check(!array_key_exists('value', findEl($el, $in) ?? []), "$in: enthält 'value' - automatischer Wert im Eingabefeld");
}
$mehrere = $scenario === 'ems_mehrere';
$check($visOf('EmsInstanceID') === $mehrere, 'EmsInstanceID sichtbar=' . var_export($visOf('EmsInstanceID'), true) . ' (erwartet ' . var_export($mehrere, true) . ')');

switch ($scenario) {
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

if ($errors) {
    echo "ROT   $scenario\n";
    foreach ($errors as $e) {
        echo "        - $e\n";
    }
    exit(1);
}
echo "grün  $scenario\n";
exit(0);
