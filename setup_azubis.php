<?php
declare(strict_types=1);

/**
 * setup_azubis.php
 *
 * Einmaliges CLI-Hilfsskript: legt Azubis als Gruppe in der Datenbank an.
 * Aufruf ausschliesslich per Kommandozeile:
 *
 *   php setup_azubis.php GI7LBV5 "Max Mustermann"
 *   php setup_azubis.php GI7LBV5 "Max Mustermann" HP8LBV5 "Maria Muster"
 *
 * Oder als CSV-Datei (user_id,anzeigename pro Zeile):
 *
 *   php setup_azubis.php --csv azubis.csv
 *
 * Das Skript ist idempotent: mehrfaches Ausfuehren aktualisiert nur den
 * Anzeigenamen, legt keine Dubletten an.
 *
 * WICHTIG: Dieses Skript darf NICHT ueber den Webserver erreichbar sein.
 * Keine Echtdaten (Namen, Kennungen) im Quellcode eintragen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript ist nur per CLI ausfuehrbar.');
}

require_once __DIR__ . '/helpers.php';

$args = array_slice($argv, 1);

if (count($args) === 0) {
    fwrite(STDERR, "Verwendung:\n");
    fwrite(STDERR, "  php setup_azubis.php <USER_ID> \"<Name>\" [<USER_ID> \"<Name>\" ...]\n");
    fwrite(STDERR, "  php setup_azubis.php --csv <datei.csv>\n\n");
    fwrite(STDERR, "CSV-Format: eine Zeile pro Azubi, Komma als Trennzeichen\n");
    fwrite(STDERR, "  GI7LBV5,Max Mustermann\n");
    exit(1);
}

$azubis = [];

if ($args[0] === '--csv') {
    $csvFile = $args[1] ?? '';
    if ($csvFile === '' || !is_file($csvFile)) {
        fwrite(STDERR, "Fehler: CSV-Datei nicht gefunden: {$csvFile}\n");
        exit(1);
    }

    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = str_getcsv($line);
        $userId = trim($parts[0] ?? '');
        $name = trim($parts[1] ?? '');
        if ($userId !== '' && $name !== '') {
            $azubis[$userId] = $name;
        }
    }
} else {
    if (count($args) % 2 !== 0) {
        fwrite(STDERR, "Fehler: Argumente muessen als Paare angegeben werden: <USER_ID> \"<Name>\"\n");
        exit(1);
    }

    for ($i = 0; $i < count($args); $i += 2) {
        $userId = trim($args[$i]);
        $name = trim($args[$i + 1]);
        if ($userId !== '' && $name !== '') {
            $azubis[$userId] = $name;
        }
    }
}

if (count($azubis) === 0) {
    fwrite(STDERR, "Fehler: Keine gueltige Azubi-Liste ermittelt.\n");
    exit(1);
}

$actor = 'setup_azubis.php';
$pdo = getDb();
$fehler = 0;

echo "Azubi-Gruppen werden gesetzt:\n";
foreach ($azubis as $userId => $name) {
    try {
        saveFreigabeGruppe($pdo, normalizeUserId($userId), $name, APPROVAL_GROUP_AZUBI, $actor);
        printf("  OK  %-12s %s\n", normalizeUserId($userId), $name);
    } catch (Throwable $e) {
        printf("  ERR %-12s %s  -> %s\n", normalizeUserId($userId), $name, $e->getMessage());
        $fehler++;
    }
}

echo "\nFertig." . ($fehler > 0 ? " {$fehler} Fehler aufgetreten." : '') . "\n";
exit($fehler > 0 ? 1 : 0);
