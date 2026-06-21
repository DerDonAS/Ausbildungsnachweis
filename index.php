<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';

ensureSession();

// --- Benutzer & Rechte -----------------------------------------------------

$pdo = getDb();
$access = currentAccess($pdo);

$userId = $access['userId'];
$vorname = $access['firstname'];
$nachname = $access['lastname'];
$actorRealname = $access['realname'];
$ldapProfile = $access['ldapProfile'];
$isLdapAdmin = $access['isLdapAdmin'];

if ($userId === '') {
    http_response_code(403);
    die('Keine Benutzerkennung erkannt. Bitte über den Webserver mit Windows-Anmeldung aufrufen.');
}

$hatAzubiGruppe = $access['hatAzubiGruppe'];
$hatAusbilderGruppe = $access['hatAusbilderGruppe'];
$hatLeiterGruppe = $access['hatLeiterGruppe'];

// Azubis werden bewusst manuell freigeschaltet, damit neue Auszubildende gezielt Zugriff erhalten.
$hasErfassungAccess = $access['hasErfassungAccess'];
$hasAdminAccess = $access['hasAdminAccess'];

if (!$hasErfassungAccess) {
    http_response_code(403);
    die('Kein Zugriff: Diese Erfassung ist nur für freigeschaltete Azubis, Ausbilder, Ausbildungsleiter oder Admins vorgesehen.');
}

// --- Kalenderwoche bestimmen (automatisch oder über GET) -------------------

$current = getCurrentIsoWeek();
$jahr = isset($_GET['jahr']) ? (int) $_GET['jahr'] : $current['jahr'];
$kw = isset($_GET['kw']) ? (int) $_GET['kw'] : $current['kw'];

// Grobe Validierung der KW/Jahr-Werte
if ($jahr < 2000 || $jahr > 2100) {
    $jahr = $current['jahr'];
}
if (!isValidIsoWeek($jahr, $kw)) {
    $kw = $current['kw'];
    $jahr = $current['jahr'];
}

$errors = [];
$success = '';

// --- POST-Verarbeitung -------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $postJahr = (int) ($_POST['jahr'] ?? $jahr);
    $postKw = (int) ($_POST['kw'] ?? $kw);
    $action = (string) ($_POST['action'] ?? 'speichern');

    // Serverseitige Sperrprüfung: eingereichte/genehmigte Wochenberichte sind Read-Only.
    // Korrekturen sind erst wieder möglich, wenn der Ausbilder aktiv ablehnt.
    $vorhandenerBericht = findWochenbericht($pdo, $userId, $postJahr, $postKw);
    $berichtGesperrt = isBerichtBearbeitungGesperrt($vorhandenerBericht);

    if ($berichtGesperrt) {
        $errors[] = 'Dieser Wochenbericht wurde bereits genehmigt oder freigegeben und kann nicht mehr bearbeitet werden.';
        $jahr = $postJahr;
        $kw = $postKw;
    }

    $wochenThema = trim((string) ($_POST['wochen_thema'] ?? ''));
    $ausbildungsjahrInput = normalizeAusbildungsjahr($_POST['ausbildungsjahr'] ?? 1);
    $tagesDaten = [];
    if (!$berichtGesperrt) {
        foreach (WOCHENTAGE as $tag) {
            $typ = (string) ($_POST[$tag . '_typ'] ?? 'arbeit');
            $erlaubteTypen = ['arbeit', 'schule', 'urlaub', 'krank', 'feiertag'];
            if (!in_array($typ, $erlaubteTypen, true)) {
                $typ = 'arbeit';
            }

            $stundenRoh = str_replace(',', '.', (string) ($_POST[$tag . '_stunden'] ?? '8'));
            $stunden = is_numeric($stundenRoh) ? (float) $stundenRoh : 0.0;
            // Bei Abwesenheit/Berufsschule werden keine Arbeitsstunden gezaehlt;
            // ansonsten auf den Bereich 0..24 begrenzen.
            $stunden = normalizeTagesStunden($typ, $stunden);

            // Bei Urlaub/Krank/Feiertag ergeben Tätigkeiten keinen Sinn,
            // werden aber falls vorhanden trotzdem als Bemerkung übernommen.
            $taetigkeiten = trim((string) ($_POST[$tag . '_taetigkeiten'] ?? ''));

            $tagesDaten[$tag] = [
                'typ' => $typ,
                'stunden' => $stunden,
                'taetigkeiten' => $taetigkeiten,
            ];
        }

        // Validierung: An Arbeitstagen mit erfassten Stunden sollten Tätigkeiten
        // angegeben werden. Bei Berufsschule, Urlaub, Krank und Feiertag ist
        // keine Tätigkeitsbeschreibung erforderlich.
        foreach (WOCHENTAGE as $tag) {
            $d = $tagesDaten[$tag];
            if ($d['typ'] === 'arbeit' && $d['stunden'] > 0 && $d['taetigkeiten'] === '' && $wochenThema === '') {
                $errors[] = WOCHENTAG_LABELS[$tag] . ': Bitte Tätigkeiten oder einen Wochenbereich/Hospitierung angeben, wenn Stunden erfasst wurden.';
            }
        }
    }

    if (!$errors) {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $bestehend = findWochenbericht($pdo, $userId, $postJahr, $postKw);

        $neuerStatus = STATUS_ENTWURF;
        if ($action === 'einreichen') {
            $neuerStatus = STATUS_EINGEREICHT;
        } elseif ($bestehend) {
            // Bereits eingereichte/abgelehnte Berichte bleiben in ihrem
            // Status, solange nicht aktiv neu eingereicht wird, außer
            // sie waren noch im Entwurf.
            $neuerStatus = $bestehend['status'];
        }

        if ($bestehend) {
            $sql = 'UPDATE wochenberichte SET
                nachname = :nachname, vorname = :vorname, status = :status,
                ausbildungsjahr = :ausbildungsjahr,
                wochen_thema = :wochen_thema,
                montag_typ = :montag_typ, montag_stunden = :montag_stunden, montag_taetigkeiten = :montag_taetigkeiten,
                dienstag_typ = :dienstag_typ, dienstag_stunden = :dienstag_stunden, dienstag_taetigkeiten = :dienstag_taetigkeiten,
                mittwoch_typ = :mittwoch_typ, mittwoch_stunden = :mittwoch_stunden, mittwoch_taetigkeiten = :mittwoch_taetigkeiten,
                donnerstag_typ = :donnerstag_typ, donnerstag_stunden = :donnerstag_stunden, donnerstag_taetigkeiten = :donnerstag_taetigkeiten,
                freitag_typ = :freitag_typ, freitag_stunden = :freitag_stunden, freitag_taetigkeiten = :freitag_taetigkeiten,
                aktualisiert_am = :aktualisiert_am,
                eingereicht_am = :eingereicht_am,
                ausbilder_kommentar = :ausbilder_kommentar,
                ausbilder_genehmigt_am = :ausbilder_genehmigt_am,
                ausbilder_genehmigt_von = :ausbilder_genehmigt_von,
                ausbildungsleiter_genehmigt_am = :ausbildungsleiter_genehmigt_am,
                ausbildungsleiter_genehmigt_von = :ausbildungsleiter_genehmigt_von,
                freigegeben_am = :freigegeben_am,
                freigegeben_von = :freigegeben_von,
                is_locked = :is_locked,
                locked_at = :locked_at,
                locked_by_username = :locked_by_username,
                locked_by_realname = :locked_by_realname,
                version = :version
                WHERE id = :id';

            $stmt = $pdo->prepare($sql);
            $wirdNeuEingereicht = $action === 'einreichen';
            $neueVersion = (int) ($bestehend['version'] ?? 1);
            if ($wirdNeuEingereicht && (string) ($bestehend['status'] ?? '') === STATUS_ABGELEHNT) {
                $neueVersion++;
            }

            $params = [
                ':nachname' => $nachname,
                ':vorname' => $vorname,
                ':status' => $neuerStatus,
                ':ausbildungsjahr' => $ausbildungsjahrInput,
                ':wochen_thema' => $wochenThema,
                ':aktualisiert_am' => $now,
                ':eingereicht_am' => $wirdNeuEingereicht ? $now : ($bestehend['eingereicht_am'] ?? null),
                ':ausbilder_kommentar' => $wirdNeuEingereicht ? '' : ($bestehend['ausbilder_kommentar'] ?? ''),
                ':ausbilder_genehmigt_am' => $wirdNeuEingereicht ? null : ($bestehend['ausbilder_genehmigt_am'] ?? null),
                ':ausbilder_genehmigt_von' => $wirdNeuEingereicht ? null : ($bestehend['ausbilder_genehmigt_von'] ?? null),
                ':ausbildungsleiter_genehmigt_am' => $wirdNeuEingereicht ? null : ($bestehend['ausbildungsleiter_genehmigt_am'] ?? null),
                ':ausbildungsleiter_genehmigt_von' => $wirdNeuEingereicht ? null : ($bestehend['ausbildungsleiter_genehmigt_von'] ?? null),
                ':freigegeben_am' => $wirdNeuEingereicht ? null : ($bestehend['freigegeben_am'] ?? null),
                ':freigegeben_von' => $wirdNeuEingereicht ? null : ($bestehend['freigegeben_von'] ?? null),
                ':is_locked' => $wirdNeuEingereicht ? 1 : 0,
                ':locked_at' => $wirdNeuEingereicht ? $now : null,
                ':locked_by_username' => $wirdNeuEingereicht ? $userId : null,
                ':locked_by_realname' => $wirdNeuEingereicht ? $actorRealname : null,
                ':version' => $neueVersion,
                ':id' => $bestehend['id'],
            ];
            foreach (WOCHENTAGE as $tag) {
                $params[':' . $tag . '_typ'] = $tagesDaten[$tag]['typ'];
                $params[':' . $tag . '_stunden'] = $tagesDaten[$tag]['stunden'];
                $params[':' . $tag . '_taetigkeiten'] = $tagesDaten[$tag]['taetigkeiten'];
            }
            $stmt->execute($params);
            $berichtId = (int) $bestehend['id'];
        } else {
            $sql = 'INSERT INTO wochenberichte (
                user_id, nachname, vorname, jahr, kw, status, ausbildungsjahr, wochen_thema,
                montag_typ, montag_stunden, montag_taetigkeiten,
                dienstag_typ, dienstag_stunden, dienstag_taetigkeiten,
                mittwoch_typ, mittwoch_stunden, mittwoch_taetigkeiten,
                donnerstag_typ, donnerstag_stunden, donnerstag_taetigkeiten,
                freitag_typ, freitag_stunden, freitag_taetigkeiten,
                erstellt_am, aktualisiert_am, eingereicht_am,
                is_locked, locked_at, locked_by_username, locked_by_realname, version
            ) VALUES (
                :user_id, :nachname, :vorname, :jahr, :kw, :status, :ausbildungsjahr, :wochen_thema,
                :montag_typ, :montag_stunden, :montag_taetigkeiten,
                :dienstag_typ, :dienstag_stunden, :dienstag_taetigkeiten,
                :mittwoch_typ, :mittwoch_stunden, :mittwoch_taetigkeiten,
                :donnerstag_typ, :donnerstag_stunden, :donnerstag_taetigkeiten,
                :freitag_typ, :freitag_stunden, :freitag_taetigkeiten,
                :erstellt_am, :aktualisiert_am, :eingereicht_am,
                :is_locked, :locked_at, :locked_by_username, :locked_by_realname, :version
            )';

            $stmt = $pdo->prepare($sql);
            $params = [
                ':user_id' => $userId,
                ':nachname' => $nachname,
                ':vorname' => $vorname,
                ':jahr' => $postJahr,
                ':kw' => $postKw,
                ':status' => $neuerStatus,
                ':ausbildungsjahr' => $ausbildungsjahrInput,
                ':wochen_thema' => $wochenThema,
                ':erstellt_am' => $now,
                ':aktualisiert_am' => $now,
                ':eingereicht_am' => $neuerStatus === STATUS_EINGEREICHT ? $now : null,
                ':is_locked' => $neuerStatus === STATUS_EINGEREICHT ? 1 : 0,
                ':locked_at' => $neuerStatus === STATUS_EINGEREICHT ? $now : null,
                ':locked_by_username' => $neuerStatus === STATUS_EINGEREICHT ? $userId : null,
                ':locked_by_realname' => $neuerStatus === STATUS_EINGEREICHT ? $actorRealname : null,
                ':version' => 1,
            ];
            foreach (WOCHENTAGE as $tag) {
                $params[':' . $tag . '_typ'] = $tagesDaten[$tag]['typ'];
                $params[':' . $tag . '_stunden'] = $tagesDaten[$tag]['stunden'];
                $params[':' . $tag . '_taetigkeiten'] = $tagesDaten[$tag]['taetigkeiten'];
            }
            $stmt->execute($params);
            $berichtId = (int) $pdo->lastInsertId();
        }

        $ereignis = $action === 'einreichen'
            ? 'Wochenbericht eingereicht.'
            : ($bestehend ? 'Wochenbericht aktualisiert (Entwurf).' : 'Wochenbericht angelegt (Entwurf).');
        addHistorie($pdo, $berichtId, $userId, $ereignis);
        if ($action === 'einreichen') {
            addNachweisAuditLog(
                $pdo,
                $berichtId,
                'submitted',
                $userId,
                $actorRealname,
                'Azubi',
                $bestehend ? (string) ($bestehend['status'] ?? '') : null,
                STATUS_EINGEREICHT,
                'Wochenbericht elektronisch eingereicht und gesperrt.',
                $now
            );
        } else {
            refreshBerichtContentHash($pdo, $berichtId);
        }

        $success = $action === 'einreichen'
            ? 'Der Wochenbericht für KW ' . $postKw . '/' . $postJahr . ' wurde eingereicht.'
            : 'Der Wochenbericht für KW ' . $postKw . '/' . $postJahr . ' wurde gespeichert.';

        $jahr = $postJahr;
        $kw = $postKw;
    }
}

// --- Daten für die Ansicht laden -------------------------------------------

$aktuellerBericht = findWochenbericht($pdo, $userId, $jahr, $kw);
$vorwochenBericht = $aktuellerBericht ? null : findPreviousWochenbericht($pdo, $userId, $jahr, $kw);
$alleWochen = listWochenberichte($pdo, $userId);
$historie = $aktuellerBericht ? getHistorieFuer($pdo, (int) $aktuellerBericht['id']) : [];

$weekRange = getWeekRange($jahr, $kw);
$wochenLabel = formatWeekRangeLabel($jahr, $kw);

$istGesperrt = isBerichtBearbeitungGesperrt($aktuellerBericht);
$ausbildungsjahrWert = defaultAusbildungsjahrForWeek($aktuellerBericht, $vorwochenBericht, $jahr, $kw);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && isset($_POST['ausbildungsjahr'])) {
    $ausbildungsjahrWert = normalizeAusbildungsjahr($_POST['ausbildungsjahr']);
}
$wochenThemaWert = $aktuellerBericht ? (string) ($aktuellerBericht['wochen_thema'] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && isset($_POST['wochen_thema'])) {
    $wochenThemaWert = trim((string) $_POST['wochen_thema']);
}

// Fest gepflegte Berufsschultage des Azubis (Adminbereich). Diese werden
// bei neuen, noch nicht gespeicherten Wochen automatisch als Berufsschule
// vorbelegt.
$festeSchultage = getBerufsschultage($pdo, $userId);
$festeSchultagListe = [];
foreach (WOCHENTAGE as $tag) {
    if (!empty($festeSchultage[$tag])) {
        $festeSchultagListe[$tag] = true;
    }
}

$uebernommeneSchultage = [];
if (!$aktuellerBericht) {
    foreach (WOCHENTAGE as $tag) {
        $ausVorwoche = $vorwochenBericht
            && (string) ($vorwochenBericht[$tag . '_typ'] ?? '') === 'schule';
        if (!empty($festeSchultagListe[$tag]) || $ausVorwoche) {
            $uebernommeneSchultage[] = WOCHENTAG_LABELS[$tag];
        }
    }
}

function feldWert(?array $bericht, string $tag, string $feld, $default = '')
{
    if (!$bericht) {
        return $default;
    }
    return $bericht[$tag . '_' . $feld] ?? $default;
}

function typVorbelegung(?array $aktuellerBericht, ?array $vorwochenBericht, string $tag, array $festeSchultage = []): string
{
    // Ein bereits gespeicherter Bericht behaelt seine erfassten Werte.
    if ($aktuellerBericht) {
        return (string) ($aktuellerBericht[$tag . '_typ'] ?? 'arbeit');
    }

    // Sonst: fest gepflegte Berufsschultage haben Vorrang, danach die
    // Uebernahme aus der Vorwoche.
    if (!empty($festeSchultage[$tag])) {
        return 'schule';
    }

    return ((string) ($vorwochenBericht[$tag . '_typ'] ?? '') === 'schule') ? 'schule' : 'arbeit';
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LBV NRW · Ausbildungsnachweis</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <main class="page-shell">
        <header class="agency-header">
            <div class="brand-block">
                <img src="nrw-wappen.jpg" alt="Wappen Nordrhein-Westfalen" class="coat-of-arms">
                <div>
                    <p class="authority">Landesamt für Besoldung und Versorgung NRW</p>
                    <h1>Ausbildungsnachweis für Fachinformatiker für Systemintegration</h1>
                </div>
            </div>
            <div class="header-actions">

                <?php if ($hasAdminAccess): ?>
                    <a href="admin.php" class="button-link small">Adminbereich</a>
                <?php endif; ?>
            </div>
        </header>

        <section class="intro-card">
            <div>
                <p class="section-kicker">Digitale Erfassung des Ausbildungsnachweises</p>
                <p>
                    Bitte erfassen Sie pro Wochentag die geleisteten Stunden und die ausgeführten Tätigkeiten.
                </p>
            </div>
            <div class="ldap-status <?= $ldapProfile['found'] ? 'ok' : 'warn' ?>">
                <strong>
                    <?= $ldapProfile['found'] ? 'Angemeldet' : 'LDAP-Erkennung nicht vollständig' ?>
                </strong>
                <span>
                    <?php if ($ldapProfile['found']): ?>
                        Angemeldet als <?= e($vorname . ' ' . $nachname) ?> (<?= e($userId) ?>)
                        <?= $ldapProfile['ou'] !== '' ? ' · ' . e($ldapProfile['ou']) : '' ?>
                        <?= $hasAdminAccess ? ' · Freigabe/Admin' : ($hatAzubiGruppe ? ' · Azubi' : '') ?>
                    <?php else: ?>
                        <?= e($ldapProfile['message']) ?>
                    <?php endif; ?>
                </span>
            </div>
        </section>

        <?php if ($errors): ?>
            <div class="notice error">
                <strong>Bitte prüfen Sie die Eingaben:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="notice success">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <div class="layout-columns">
            <!-- Wochenliste -->
            <section class="data-card weeks-list">
                <div class="data-card-header">
                    <h2>Meine Wochen</h2>
                    <div class="header-mini-actions">
                        <span><?= count($alleWochen) ?> erfasst</span>
                        <?php if ($alleWochen): ?>
                            <a href="export_pdf_zip.php?scope=mine" class="button-link small pdf-zip-button">Alle PDFs als
                                ZIP</a>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="get" class="week-jump">
                    <label class="inline-label">
                        <span>Jahr</span>
                        <input type="number" name="jahr" value="<?= e((string) $jahr) ?>" min="2000" max="2100">
                    </label>
                    <label class="inline-label">
                        <span>KW</span>
                        <input type="number" name="kw" value="<?= e((string) $kw) ?>" min="1" max="53">
                    </label>
                    <button type="submit" class="button-secondary">Anzeigen</button>
                    <a href="index.php" class="button-link small">Aktuelle KW</a>
                </form>

                <?php if (!$alleWochen): ?>
                    <p class="empty-state">Noch keine Wochenberichte erfasst.</p>
                <?php else: ?>
                    <ul class="week-list">
                        <?php foreach ($alleWochen as $woche): ?>
                            <li class="<?= ((int) $woche['jahr'] === $jahr && (int) $woche['kw'] === $kw) ? 'active' : '' ?>">
                                <a href="?jahr=<?= (int) $woche['jahr'] ?>&kw=<?= (int) $woche['kw'] ?>">
                                    <span class="week-list-kw">KW <?= (int) $woche['kw'] ?> / <?= (int) $woche['jahr'] ?></span>
                                    <span
                                        class="status-badge <?= statusBadgeClass($woche['status']) ?>"><?= e($woche['status']) ?></span>
                                    <span class="week-list-hours"><?= number_format(sumStunden($woche), 1, ',', '.') ?>
                                        Std.</span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- Formular für die gewählte Woche -->
            <section class="form-card week-form">
                <div class="data-card-header week-form-header">
                    <div>
                        <h2>KW <?= $kw ?> / <?= $jahr ?></h2>
                        <span><?= e($wochenLabel) ?></span>
                    </div>
                    <?php if ($aktuellerBericht): ?>
                        <div class="header-mini-actions">
                            <a href="export_word.php?jahr=<?= (int) $jahr ?>&kw=<?= (int) $kw ?>"
                                class="button-link small word-export-button">Word Export</a>
                            <a href="export_pdf.php?jahr=<?= (int) $jahr ?>&kw=<?= (int) $kw ?>"
                                class="button-link small pdf-export-button">PDF Export</a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($aktuellerBericht): ?>
                    <span class="status-badge large <?= statusBadgeClass($aktuellerBericht['status']) ?>">
                        Status: <?= e($aktuellerBericht['status']) ?>
                    </span>
                    <?php if ($aktuellerBericht['status'] === STATUS_ABGELEHNT && $aktuellerBericht['ausbilder_kommentar']): ?>
                        <div class="notice error">
                            <strong>Rückmeldung des Ausbilders:</strong>
                            <p><?= e($aktuellerBericht['ausbilder_kommentar']) ?></p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="status-badge large status-entwurf">Status: Neuer Entwurf</span>
                <?php endif; ?>

                <?php if ($istGesperrt): ?>
                    <div class="notice warn-static">
                        Dieser Wochenbericht ist gesperrt. Korrekturen sind erst wieder möglich, wenn er aktiv abgelehnt
                        wird.
                    </div>
                <?php endif; ?>

                <?php if ($uebernommeneSchultage): ?>
                    <div class="notice success compact-notice">
                        Berufsschultage automatisch vorbelegt: <?= e(implode(', ', $uebernommeneSchultage)) ?>. Alle
                        anderen Tage bleiben auf Arbeit.
                    </div>
                <?php endif; ?>

                <form method="post" id="wochenForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="jahr" value="<?= e((string) $jahr) ?>">
                    <input type="hidden" name="kw" value="<?= e((string) $kw) ?>">

                    <fieldset class="week-topic-panel" <?= $istGesperrt ? 'disabled' : '' ?>>
                        <div class="week-meta-grid">
                            <label class="block-label">
                                <span>Ausbildungsjahr</span>
                                <input type="number" name="ausbildungsjahr"
                                    value="<?= e((string) $ausbildungsjahrWert) ?>" min="1" max="10" step="1">
                            </label>
                            <label class="block-label">
                                <span>Wochenbereich / Hospitierung</span>
                                <input type="text" name="wochen_thema" value="<?= e($wochenThemaWert) ?>"
                                    placeholder="z. B. Desktop Service, Service Desk, IT-Beschaffung">
                            </label>
                        </div>

                    </fieldset>

                    <div class="days-grid">
                        <?php foreach (WOCHENTAGE as $tag):
                            $datum = $weekRange['montag']->modify('+' . array_search($tag, WOCHENTAGE) . ' days');
                            $typ = typVorbelegung($aktuellerBericht, $vorwochenBericht, $tag, $festeSchultagListe);
                            $stunden = feldWert($aktuellerBericht, $tag, 'stunden', 0);
                            $taetigkeiten = feldWert($aktuellerBericht, $tag, 'taetigkeiten', '');
                            ?>
                            <fieldset class="day-block" <?= $istGesperrt ? 'disabled' : '' ?>>
                                <legend><?= WOCHENTAG_LABELS[$tag] ?> · <?= $datum->format('d.m.Y') ?></legend>

                                <div class="day-row">
                                    <label class="inline-label">
                                        <span>Art</span>
                                        <select name="<?= $tag ?>_typ" class="tag-typ-select">
                                            <?php foreach (['arbeit' => 'Arbeit', 'schule' => 'Berufsschule', 'urlaub' => 'Urlaub', 'krank' => 'Krank', 'feiertag' => 'Feiertag'] as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $typ === $value ? 'selected' : '' ?>>
                                                    <?= $label ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="inline-label">
                                        <span>Stunden</span>
                                        <input type="number" step="0.25" min="0" max="24" name="<?= $tag ?>_stunden"
                                            value="<?= e((string) $stunden) ?>">
                                    </label>
                                </div>

                                <label class="block-label">
                                    <span>Tätigkeiten</span>
                                    <textarea name="<?= $tag ?>_taetigkeiten" rows="3"
                                        placeholder="Ausgeführte Tätigkeiten am <?= WOCHENTAG_LABELS[$tag] ?>..."><?= e($taetigkeiten) ?></textarea>
                                </label>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>

                    <div class="week-summary">
                        Gesamtstunden in dieser Woche:
                        <strong><?= $aktuellerBericht ? number_format(sumStunden($aktuellerBericht), 1, ',', '.') : '0,0' ?></strong>
                        <span class="hint">(wird nach dem Speichern aktualisiert)</span>
                    </div>

                    <?php if (!$istGesperrt): ?>
                        <div class="button-row">
                            <button type="submit" name="action" value="speichern" class="button-secondary">Als Entwurf
                                speichern</button>
                            <button type="submit" name="action" value="einreichen">Einreichen</button>
                        </div>
                        <small>Nach dem Einreichen wird die Woche gesperrt. Änderungen sind dann nur nach einer Ablehnung
                            wieder möglich.</small>
                    <?php endif; ?>
                </form>

                <?php if ($historie): ?>
                    <details class="history-block">
                        <summary>Historie zu dieser Woche (<?= count($historie) ?>)</summary>
                        <ul class="history-list">
                            <?php foreach ($historie as $eintrag): ?>
                                <li>
                                    <span class="history-time"><?= e($eintrag['zeitpunkt']) ?></span>
                                    <span class="history-actor"><?= e($eintrag['akteur']) ?></span>
                                    <span class="history-event"><?= e($eintrag['ereignis']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        // Tätigkeitsfeld bei Urlaub/Krank/Feiertag optisch zurücknehmen (UX-Hinweis only, keine harte Sperre).
        document.querySelectorAll('.tag-typ-select').forEach(function (select) {
            function update() {
                var block = select.closest('.day-block');
                var textarea = block.querySelector('textarea');
                var istNichtTaetigkeitspflichtig = ['schule', 'urlaub', 'krank', 'feiertag'].includes(select.value);
                textarea.placeholder = istNichtTaetigkeitspflichtig ? 'Keine Tätigkeit erforderlich' : 'Ausgeführte Tätigkeiten...';
                block.classList.toggle('is-absence', istNichtTaetigkeitspflichtig);
            }
            select.addEventListener('change', update);
            update();
        });
    </script>
</body>

</html>