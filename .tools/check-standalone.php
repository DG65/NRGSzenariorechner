<?php
/**
 * check-standalone.php — Prüft die Eigenständigkeit dieses Moduls (Szenariorechner).
 *
 * Grundregel des Modul-Verbunds: Kein Modul darf ein anderes voraussetzen.
 * Jeder Aufruf einer fremden Modulfunktion (MHUB_*, PVF_*, HEISHA_* ...) muss
 * deshalb innerhalb derselben Funktion durch function_exists() abgesichert sein.
 *
 * Warum das kein Stilthema ist: Ein Aufruf einer nicht vorhandenen Funktion ist
 * in PHP ein FATAL ERROR. Das vorangestellte @ unterdrückt ihn NICHT — es
 * unterdrückt nur Warnungen. Fehlt der Wächter und ist das Partnermodul nicht
 * installiert, bricht die Instanz hart ab, statt die Zusatzfunktion einfach
 * wegzulassen.
 *
 * Aufruf:  php .tools/check-standalone.php
 * Rückgabe: 0 = alles abgesichert, 1 = mindestens eine ungeschützte Stelle
 *
 * Warum der Ordner .tools heißt (mit führendem Punkt): Die Prüfung des Symcon
 * Module Store behandelt JEDEN sichtbaren Top-Level-Ordner als Modul und
 * verlangt dort eine module.json. Ein sichtbarer Ordner "tools" lässt die
 * Einreichung mit „Das Modul tools hat keine module.json" scheitern. Ordner mit
 * führendem Punkt überspringt der Scanner.
 */

// Präfixe der Partnermodule im Verbund. Neue Partner hier ergänzen.
// Präfixe der Partnermodule. SZR ist der EIGENE Präfix dieses Repos
// und gehört daher NICHT in die Liste — sonst meldet das Skript die eigenen
// Funktionen als ungeschützte Fremdaufrufe.
const FOREIGN_PREFIXES = ['NRGDASH', 'SPOT', 'OHUB', 'SBH', 'IHUB', 'IHUBD', 'IHUBNRG', 'IHUBMON', 'IHUBTILE', 'MHUB', 'MHUBD', 'CHUB', 'MIGHUB', 'PVF', 'LFC', 'HEISHA', 'SGW', 'TIBBERGR', 'TESSIE', 'EMS', 'GWET'];

$root = dirname(__DIR__);
$rx   = '/\b((' . implode('|', FOREIGN_PREFIXES) . ')_[A-Za-z_][A-Za-z0-9_]*)\s*\(/';

/**
 * Alle PHP-Dateien des Repos einsammeln. Das Verzeichnis des Skripts selbst
 * wird ausgeschlossen — bewusst über __DIR__ statt über den Ordnernamen, damit
 * der Ausschluss auch nach einer Umbenennung des Ordners greift.
 */
function phpFiles(string $dir): array {
    $self = realpath(__DIR__) . DIRECTORY_SEPARATOR;
    $out  = [];
    $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (substr($p, -4) !== '.php')            continue;
        if (strpos($p, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) continue;
        if (strpos(realpath($p) ?: $p, $self) === 0) continue;
        $out[] = $p;
    }
    sort($out);
    return $out;
}

/**
 * Zerlegt eine Datei in Funktionsrümpfe. Rückgabe je Funktion:
 * ['name' => …, 'startLine' => …, 'body' => …]
 * Kommentare und Zeichenketten werden vorher entfernt, damit Beispielaufrufe in
 * Dokumentationsblöcken nicht als echte Aufrufe zählen.
 */
function functionBodies(string $code): array {
    $clean = php_strip_whitespace_string($code);
    $out   = [];
    $len   = strlen($clean);
    $off   = 0;
    while (preg_match('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $clean, $m, PREG_OFFSET_CAPTURE, $off)) {
        $name  = $m[1][0];
        $start = $m[0][1];
        // Ab der öffnenden Klammer des Rumpfes die Klammern zählen.
        $brace = strpos($clean, '{', $m[0][1] + strlen($m[0][0]));
        if ($brace === false) { break; }
        $depth = 0; $i = $brace;
        for (; $i < $len; $i++) {
            if ($clean[$i] === '{') { $depth++; }
            elseif ($clean[$i] === '}') { $depth--; if ($depth === 0) { break; } }
        }
        $out[] = [
            'name'      => $name,
            'startLine' => substr_count($clean, "\n", 0, $start) + 1,
            'body'      => substr($clean, $brace, $i - $brace + 1),
        ];
        $off = $i + 1;
    }
    return $out;
}

/** Kommentare und Zeichenketten neutralisieren, Zeilenstruktur erhalten. */
function php_strip_whitespace_string(string $code): string {
    $out = '';
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                // Inhalt leeren, aber Anführungszeichen behalten: function_exists('X')
                // muss weiterhin erkennbar bleiben, daher nur echte Textstellen
                // unangetastet lassen, wenn sie wie ein Funktionsname aussehen.
                $out .= preg_match('/^([\'"])[A-Za-z_][A-Za-z0-9_]*\1$/', $t[1]) ? $t[1] : "''";
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$files    = phpFiles($root);
$findings = [];
$checked  = 0;

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) { continue; }
    foreach (functionBodies($code) as $fn) {
        if (!preg_match_all($rx, $fn['body'], $mm)) { continue; }
        foreach (array_unique($mm[1]) as $call) {
            $checked++;
            // Abgesichert, wenn im selben Rumpf function_exists('<call>') steht.
            $guard = "function_exists('" . $call . "')";
            if (strpos($fn['body'], $guard) !== false ||
                strpos($fn['body'], 'function_exists("' . $call . '")') !== false) {
                continue;
            }
            $findings[] = [
                'file' => ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR),
                'fn'   => $fn['name'],
                'line' => $fn['startLine'],
                'call' => $call,
            ];
        }
    }
}

echo "Eigenständigkeitsprüfung — Aufrufe fremder Modulfunktionen\n";
echo str_repeat('-', 62) . "\n";
echo 'Dateien: ' . count($files) . ' | geprüfte Aufrufe: ' . $checked . "\n\n";

if (!$findings) {
    echo "OK — jeder Fremdaufruf ist durch function_exists() abgesichert.\n";
    echo "Das Modul bleibt ohne die Partnermodule lauffähig.\n";
    exit(0);
}

echo 'FEHLER — ' . count($findings) . " ungesicherte(r) Aufruf(e):\n\n";
foreach ($findings as $f) {
    echo sprintf("  %s  Funktion %s() ab Zeile %d\n", $f['file'], $f['fn'], $f['line']);
    echo sprintf("      %s() ohne function_exists('%s')\n\n", $f['call'], $f['call']);
}
echo "Ohne Wächter bricht die Instanz mit einem Fatal Error ab, wenn das\n";
echo "Partnermodul fehlt. Bitte den Aufruf in der aufrufenden Funktion mit\n";
echo "function_exists('<Name>') absichern und die Zusatzfunktion weglassen.\n";
exit(1);
