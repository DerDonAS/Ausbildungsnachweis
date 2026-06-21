<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';

ensureSession();

function approvalInfoText(?string $user, ?string $time): string
{
    $user = trim((string) $user);
    $time = trim((string) $time);

    if ($user === '' && $time === '') {
        return 'offen';
    }

    if ($user !== '' && $time !== '') {
        return $user . ' · ' . $time;
    }

    return $user !== '' ? $user : $time;
}


function getApprovalActorDisplayName(PDO $pdo, array $ldapProfile, string $userId): string
{
    $normalizedUserId = normalizeUserId($userId);

    try {
        $stmt = $pdo->prepare("SELECT anzeigename FROM freigabe_gruppen
            WHERE user_id = :user_id AND TRIM(anzeigename) <> ''
            ORDER BY CASE gruppe
                WHEN 'ausbilder' THEN 1
                WHEN 'ausbildungsleiter' THEN 2
                WHEN 'azubi' THEN 3
                ELSE 4
            END, anzeigename COLLATE NOCASE ASC
            LIMIT 1");
        $stmt->execute([':user_id' => $normalizedUserId]);
        $row = $stmt->fetch();
        $gruppenName = trim((string) ($row['anzeigename'] ?? ''));
        if ($gruppenName !== '') {
            return $gruppenName;
        }
    } catch (Throwable) {
        // Danach LDAP-Profil verwenden.
    }

    $ldapName = trim(getDisplayNameFromLdapUser($ldapProfile));
    if ($ldapName !== '' && normalizeUserId($ldapName) !== $normalizedUserId) {
        return $ldapName;
    }

    return 'Name nicht gefunden';
}

function activeStatusOptions(): array
{
    return [
        STATUS_ENTWURF,
        STATUS_EINGEREICHT,
        STATUS_AUSBILDER_GENEHMIGT,
        STATUS_ABGELEHNT,
    ];
}

function isoWeekMonday(int $isoYear, int $isoWeek): DateTimeImmutable
{
    return (new DateTimeImmutable('now'))->setISODate($isoYear, $isoWeek, 1)->setTime(0, 0, 0);
}

function rueckstandCutoffMonday(int $graceWeeks = 2): DateTimeImmutable
{
    // Aktuelle Woche zählt nie als Rückstand. Zusätzlich bleiben die letzten
    // zwei abgeschlossenen Wochen als Karenzzeit offen.
    // Beispiel: aktuelle KW 25 => KW 24 und KW 23 sind noch okay, KW 22 und älter wird geprüft.
    return (new DateTimeImmutable('now'))->modify('monday this week')->modify('-' . ($graceWeeks + 1) . ' weeks')->setTime(0, 0, 0);
}

function getFirstSubmittedWeekForAzubi(PDO $pdo, string $userId): ?array
{
    $userId = normalizeUserId($userId);

    // Bevor die erste echte Abgabe passiert ist, wird kein Rückstand gezählt.
    // Damit bekommen neue Azubis nicht plötzlich zig alte fehlende Wochen angezeigt.
    $stmt = $pdo->prepare("SELECT jahr, kw FROM wochenberichte
        WHERE user_id = :user_id
          AND (
              eingereicht_am IS NOT NULL
              OR status IN (:eingereicht, :ausbilder_genehmigt, :freigegeben, :abgelehnt)
          )
        ORDER BY jahr ASC, kw ASC
        LIMIT 1");
    $stmt->execute([
        ':user_id' => $userId,
        ':eingereicht' => STATUS_EINGEREICHT,
        ':ausbilder_genehmigt' => STATUS_AUSBILDER_GENEHMIGT,
        ':freigegeben' => STATUS_FREIGEGEBEN,
        ':abgelehnt' => STATUS_ABGELEHNT,
    ]);

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'jahr' => (int) $row['jahr'],
        'kw' => (int) $row['kw'],
    ];
}

function listReportStatusMapForAzubi(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT jahr, kw, status FROM wochenberichte WHERE user_id = :user_id');
    $stmt->execute([':user_id' => normalizeUserId($userId)]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['jahr'] . '-' . (int) $row['kw']] = (string) $row['status'];
    }

    return $map;
}

function getAzubiRueckstandsWarnungen(PDO $pdo): array
{
    $azubis = listFreigabeGruppenByGroup($pdo, APPROVAL_GROUP_AZUBI);
    $cutoffMonday = rueckstandCutoffMonday(2);
    $warnings = [];

    foreach ($azubis as $azubi) {
        $userId = normalizeUserId((string) $azubi['user_id']);
        if ($userId === '') {
            continue;
        }

        $firstSubmittedWeek = getFirstSubmittedWeekForAzubi($pdo, $userId);
        if ($firstSubmittedWeek === null) {
            // Noch keine echte Abgabe => kein Tracking und keine Altlastenwarnung.
            continue;
        }

        $startMonday = isoWeekMonday((int) $firstSubmittedWeek['jahr'], (int) $firstSubmittedWeek['kw']);
        if ($startMonday > $cutoffMonday) {
            continue;
        }

        $reportStatusMap = listReportStatusMapForAzubi($pdo, $userId);
        $fehlendeWochen = [];
        $missingCount = 0;
        $draftCount = 0;
        $rejectedCount = 0;

        for ($weekMonday = $startMonday; $weekMonday <= $cutoffMonday; $weekMonday = $weekMonday->modify('+1 week')) {
            $jahr = (int) $weekMonday->format('o');
            $kw = (int) $weekMonday->format('W');
            $key = $jahr . '-' . $kw;
            $status = $reportStatusMap[$key] ?? null;

            if ($status === null) {
                $fehlendeWochen[] = 'KW ' . $kw . '/' . $jahr . ' · fehlt';
                $missingCount++;
                continue;
            }

            if ($status === STATUS_ENTWURF) {
                $fehlendeWochen[] = 'KW ' . $kw . '/' . $jahr . ' · Entwurf';
                $draftCount++;
                continue;
            }

            if ($status === STATUS_ABGELEHNT) {
                $fehlendeWochen[] = 'KW ' . $kw . '/' . $jahr . ' · abgelehnt/offen';
                $rejectedCount++;
                continue;
            }

            // Eingereicht, Ausbilder genehmigt und final freigegeben gelten als abgegeben.
        }

        if ($fehlendeWochen !== []) {
            $warnings[] = [
                'user_id' => $userId,
                'name' => trim((string) ($azubi['anzeigename'] ?? '')),
                'start' => 'KW ' . (int) $firstSubmittedWeek['kw'] . '/' . (int) $firstSubmittedWeek['jahr'],
                'wochen' => $fehlendeWochen,
                'gesamt' => count($fehlendeWochen),
                'fehlt' => $missingCount,
                'entwurf' => $draftCount,
                'abgelehnt' => $rejectedCount,
            ];
        }
    }

    usort($warnings, static function (array $a, array $b): int {
        return ($b['gesamt'] <=> $a['gesamt']) ?: strcmp((string) ($a['name'] ?: $a['user_id']), (string) ($b['name'] ?: $b['user_id']));
    });

    return $warnings;
}

function listAzubiFilterOptions(PDO $pdo): array
{
    $options = [];

    foreach (listFreigabeGruppenByGroup($pdo, APPROVAL_GROUP_AZUBI) as $azubi) {
        $userId = normalizeUserId((string) $azubi['user_id']);
        if ($userId !== '') {
            $options[$userId] = trim((string) ($azubi['anzeigename'] ?? ''));
        }
    }

    $stmt = $pdo->query("SELECT user_id, nachname, vorname FROM wochenberichte GROUP BY user_id ORDER BY nachname COLLATE NOCASE ASC, vorname COLLATE NOCASE ASC, user_id ASC");
    if ($stmt) {
        foreach ($stmt->fetchAll() as $row) {
            $userId = normalizeUserId((string) $row['user_id']);
            $name = trim(trim((string) ($row['nachname'] ?? '')) . ', ' . trim((string) ($row['vorname'] ?? '')), ', ');
            if ($userId !== '' && !isset($options[$userId])) {
                $options[$userId] = $name;
            } elseif ($userId !== '' && ($options[$userId] ?? '') === '' && $name !== '') {
                $options[$userId] = $name;
            }
        }
    }

    ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
}

function applyCommonFilters(string &$sql, array &$params, ?int $filterJahr, ?int $filterKw, string $filterUserId): void
{
    if ($filterJahr !== null) {
        $sql .= ' AND jahr = :jahr';
        $params[':jahr'] = $filterJahr;
    }

    if ($filterKw !== null) {
        $sql .= ' AND kw = :kw';
        $params[':kw'] = $filterKw;
    }

    if ($filterUserId !== '') {
        $sql .= ' AND user_id = :filter_user_id';
        $params[':filter_user_id'] = $filterUserId;
    }
}

function normalizeAdminSearchQuery(string $search): string
{
    $search = trim($search);
    $search = preg_replace('/\s+/u', ' ', $search) ?? $search;
    return substr($search, 0, 120);
}

function splitAdminSearchTerms(string $search): array
{
    $search = normalizeAdminSearchQuery($search);
    if ($search === '') {
        return [];
    }

    $search = preg_replace('/([Kk][Ww])\s*(\d{1,2})/u', ' $2 ', $search) ?? $search;
    $search = str_replace(['/', ';', ',', '|'], ' ', $search);
    $parts = preg_split('/\s+/u', $search) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
}

function applyComboSearchFilter(string &$sql, array &$params, string $search): void
{
    $terms = splitAdminSearchTerms($search);

    foreach ($terms as $index => $term) {
        $cleanNumber = preg_replace('/^kw/iu', '', $term) ?? $term;
        $cleanNumber = trim($cleanNumber);

        if (preg_match('/^\d{1,2}$/', $cleanNumber) === 1) {
            $kw = (int) $cleanNumber;
            if ($kw >= 1 && $kw <= 53) {
                $key = ':search_kw_' . $index;
                $sql .= ' AND kw = ' . $key;
                $params[$key] = $kw;
                continue;
            }
        }

        if (preg_match('/^\d{4}$/', $cleanNumber) === 1) {
            $year = (int) $cleanNumber;
            if ($year >= 2000 && $year <= 2100) {
                $key = ':search_jahr_' . $index;
                $sql .= ' AND jahr = ' . $key;
                $params[$key] = $year;
                continue;
            }
        }

        $key = ':search_name_' . $index;
        $sql .= " AND (
            LOWER(COALESCE(vorname, '') || ' ' || COALESCE(nachname, '') || ' ' || COALESCE(user_id, '')) LIKE " . $key . "
            OR LOWER(COALESCE(nachname, '') || ' ' || COALESCE(vorname, '') || ' ' || COALESCE(user_id, '')) LIKE " . $key . "
        )";
        $params[$key] = '%' . strtolower($term) . '%';
    }
}

function hasArchiveSearchCriteria(string $search, ?int $filterJahr, ?int $filterKw, string $filterUserId): bool
{
    return normalizeAdminSearchQuery($search) !== '' || $filterJahr !== null || $filterKw !== null || $filterUserId !== '';
}

function adminSearchNameTerms(string $search): array
{
    $nameTerms = [];
    foreach (splitAdminSearchTerms($search) as $term) {
        $cleanNumber = preg_replace('/^kw/iu', '', $term) ?? $term;
        $cleanNumber = trim($cleanNumber);
        if (preg_match('/^\d{1,2}$/', $cleanNumber) === 1 || preg_match('/^\d{4}$/', $cleanNumber) === 1) {
            continue;
        }
        $nameTerms[] = strtolower($term);
    }
    return $nameTerms;
}

function adminUserMatchesNameSearch(string $userId, string $name, array $nameTerms): bool
{
    if (!$nameTerms) {
        return true;
    }

    $haystack = strtolower($name . ' ' . $userId);
    foreach ($nameTerms as $term) {
        if (!str_contains($haystack, $term)) {
            return false;
        }
    }

    return true;
}

function adminSearchCalendarWeekYear(string $search, ?int $filterJahr, ?int $filterKw): array
{
    $jahr = $filterJahr;
    $kw = $filterKw;

    foreach (splitAdminSearchTerms($search) as $term) {
        $cleanNumber = preg_replace('/^kw/iu', '', $term) ?? $term;
        $cleanNumber = trim($cleanNumber);

        if ($kw === null && preg_match('/^\d{1,2}$/', $cleanNumber) === 1) {
            $candidateKw = (int) $cleanNumber;
            if ($candidateKw >= 1 && $candidateKw <= 53) {
                $kw = $candidateKw;
                continue;
            }
        }

        if ($jahr === null && preg_match('/^\d{4}$/', $cleanNumber) === 1) {
            $candidateYear = (int) $cleanNumber;
            if ($candidateYear >= 2000 && $candidateYear <= 2100) {
                $jahr = $candidateYear;
            }
        }
    }

    return [$jahr, $kw];
}

function statusCalendarWeeks(?int $filterJahr, ?int $filterKw, int $count = 8): array
{
    if ($filterKw !== null) {
        $year = $filterJahr ?? (int) (new DateTimeImmutable('now'))->format('o');
        return [
            [
                'jahr' => $year,
                'kw' => $filterKw,
                'label' => 'KW ' . $filterKw,
            ]
        ];
    }

    $now = new DateTimeImmutable('now');
    $baseMonday = $now->modify('monday this week');

    if ($filterJahr !== null && $filterJahr !== (int) $now->format('o')) {
        // Bei einem reinen Jahresfilter die letzten Wochen dieses ISO-Jahres anzeigen.
        $baseMonday = (new DateTimeImmutable())->setISODate($filterJahr, 52, 1);
    }

    $weeks = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $date = $baseMonday->modify('-' . $i . ' week');
        $isoYear = (int) $date->format('o');
        if ($filterJahr !== null && $isoYear !== $filterJahr) {
            continue;
        }

        $week = (int) $date->format('W');
        $weeks[] = [
            'jahr' => $isoYear,
            'kw' => $week,
            'label' => 'KW ' . $week,
        ];
    }

    return $weeks;
}

function buildStatusCalendar(PDO $pdo, array $azubiOptions, string $filterUserId, ?int $filterJahr, ?int $filterKw, string $filterSearch = ''): array
{
    $users = [];

    $nameTerms = adminSearchNameTerms($filterSearch);

    if ($filterUserId !== '') {
        $name = (string) ($azubiOptions[$filterUserId] ?? $filterUserId);
        if (adminUserMatchesNameSearch($filterUserId, $name, $nameTerms)) {
            $users[$filterUserId] = $name;
        }
    } else {
        foreach ($azubiOptions as $userId => $name) {
            $normalized = normalizeUserId((string) $userId);
            $displayName = (string) $name;
            if ($normalized !== '' && adminUserMatchesNameSearch($normalized, $displayName, $nameTerms)) {
                $users[$normalized] = $displayName;
            }
        }
    }

    [$calendarJahr, $calendarKw] = adminSearchCalendarWeekYear($filterSearch, $filterJahr, $filterKw);
    $weeks = statusCalendarWeeks($calendarJahr, $calendarKw);
    $reports = [];

    if (!$users || !$weeks) {
        return [
            'users' => $users,
            'weeks' => $weeks,
            'reports' => $reports,
        ];
    }

    $params = [];
    $userPlaceholders = [];
    $weekConditions = [];

    $i = 0;
    foreach (array_keys($users) as $userId) {
        $key = ':user_' . $i;
        $userPlaceholders[] = $key;
        $params[$key] = $userId;
        $i++;
    }

    foreach ($weeks as $i => $week) {
        $yearKey = ':year_' . $i;
        $weekKey = ':week_' . $i;
        $weekConditions[] = '(jahr = ' . $yearKey . ' AND kw = ' . $weekKey . ')';
        $params[$yearKey] = (int) $week['jahr'];
        $params[$weekKey] = (int) $week['kw'];
    }

    $sql = 'SELECT id, user_id, jahr, kw, status, is_locked, aktualisiert_am
        FROM wochenberichte
        WHERE user_id IN (' . implode(', ', $userPlaceholders) . ')
        AND (' . implode(' OR ', $weekConditions) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $mapKey = normalizeUserId((string) $row['user_id']) . '|' . (int) $row['jahr'] . '|' . (int) $row['kw'];
        $reports[$mapKey] = $row;
    }

    return [
        'users' => $users,
        'weeks' => $weeks,
        'reports' => $reports,
    ];
}

function statusCalendarCellClass(?array $bericht): string
{
    if (!$bericht) {
        return 'status-calendar-missing';
    }

    return match ((string) $bericht['status']) {
        STATUS_FREIGEGEBEN => 'status-calendar-done',
        STATUS_EINGEREICHT => 'status-calendar-waiting-trainer',
        STATUS_AUSBILDER_GENEHMIGT => 'status-calendar-waiting-leader',
        STATUS_ABGELEHNT => 'status-calendar-rejected',
        STATUS_ENTWURF => 'status-calendar-draft',
        default => 'status-calendar-draft',
    };
}

function statusCalendarCellText(?array $bericht): string
{
    if (!$bericht) {
        return 'Fehlt';
    }

    return match ((string) $bericht['status']) {
        STATUS_FREIGEGEBEN => 'Frei',
        STATUS_EINGEREICHT => 'Ausb.',
        STATUS_AUSBILDER_GENEHMIGT => 'Leit.',
        STATUS_ABGELEHNT => 'Abgel.',
        STATUS_ENTWURF => 'Entw.',
        default => (string) $bericht['status'],
    };
}

function statusCalendarTitle(?array $bericht, int $kw, int $jahr): string
{
    if (!$bericht) {
        return 'KW ' . $kw . '/' . $jahr . ': kein Bericht vorhanden';
    }

    return 'KW ' . $kw . '/' . $jahr . ': ' . (string) $bericht['status'] . ' · aktualisiert ' . (string) ($bericht['aktualisiert_am'] ?? '');
}

function renderStatusCalendar(array $calendar): void
{
    $users = $calendar['users'] ?? [];
    $weeks = $calendar['weeks'] ?? [];
    $reports = $calendar['reports'] ?? [];
    ?>
    <section class="status-calendar-panel">
        <div class="status-calendar-header">
            <div>
                <p class="section-kicker">Statusübersicht</p>
                <h3>Ampel-Kalender</h3>
                <p class="hint">Schneller Überblick: fehlt, Entwurf, wartet auf Ausbilder, wartet auf Leitung oder final
                    freigegeben.</p>
            </div>
            <div class="status-calendar-legend" aria-label="Legende">
                <span><i class="legend-missing"></i>Fehlt</span>
                <span><i class="legend-draft"></i>Entwurf</span>
                <span><i class="legend-waiting-trainer"></i>Ausbilder</span>
                <span><i class="legend-waiting-leader"></i>Leitung</span>
                <span><i class="legend-done"></i>Frei</span>
                <span><i class="legend-rejected"></i>Abgelehnt</span>
            </div>
        </div>

        <?php if (!$users || !$weeks): ?>
            <p class="empty-state compact-empty">Keine Azubis oder Kalenderwochen für die Übersicht gefunden.</p>
        <?php else: ?>
            <div class="status-calendar-scroll">
                <table class="status-calendar-table">
                    <thead>
                        <tr>
                            <th>Azubi</th>
                            <?php foreach ($weeks as $week): ?>
                                <th><?= e($week['label']) ?><small><?= (int) $week['jahr'] ?></small></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $calendarUserId => $calendarName): ?>
                            <tr>
                                <th>
                                    <strong><?= e(trim((string) $calendarName) !== '' ? (string) $calendarName : $calendarUserId) ?></strong>
                                    <small><?= e($calendarUserId) ?></small>
                                </th>
                                <?php foreach ($weeks as $week):
                                    $mapKey = $calendarUserId . '|' . (int) $week['jahr'] . '|' . (int) $week['kw'];
                                    $bericht = $reports[$mapKey] ?? null;
                                    $cellClass = statusCalendarCellClass($bericht);
                                    $cellText = statusCalendarCellText($bericht);
                                    $title = statusCalendarTitle($bericht, (int) $week['kw'], (int) $week['jahr']);
                                    ?>
                                    <td>
                                        <?php if ($bericht): ?>
                                            <a href="admin.php?user_id=<?= e($calendarUserId) ?>&kw=<?= (int) $week['kw'] ?>&jahr=<?= (int) $week['jahr'] ?>"
                                                class="status-calendar-cell <?= e($cellClass) ?>" title="<?= e($title) ?>">
                                                <?= e($cellText) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="status-calendar-cell <?= e($cellClass) ?>"
                                                title="<?= e($title) ?>"><?= e($cellText) ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function renderTagesdaten(array $bericht): void
{
    $wochenThema = trim((string) ($bericht['wochen_thema'] ?? ''));
    ?>
    <?php if ($wochenThema !== ''): ?>
        <div class="week-topic-display">
            <span>Wochenbereich / Hospitierung</span>
            <strong><?= e($wochenThema) ?></strong>
            <small>Gilt für Arbeitstage. Berufsschultage bleiben davon getrennt.</small>
        </div>
    <?php endif; ?>
    <div class="day-overview-grid">
        <?php foreach (WOCHENTAGE as $tag):
            $typ = (string) ($bericht[$tag . '_typ'] ?? 'arbeit');
            $stunden = (float) ($bericht[$tag . '_stunden'] ?? 0);
            $taetigkeiten = trim((string) ($bericht[$tag . '_taetigkeiten'] ?? ''));
            $wochenThemaGilt = $wochenThema !== '' && $typ === 'arbeit';
            ?>
            <div class="day-detail-card <?= $wochenThemaGilt ? 'has-week-topic' : '' ?>">
                <div class="day-detail-head">
                    <strong><?= e(WOCHENTAG_LABELS[$tag]) ?></strong>
                    <span><?= number_format($stunden, 1, ',', '.') ?> Std.</span>
                </div>
                <span class="day-type-pill"><?= e(tagTypLabel($typ)) ?></span>
                <?php if ($wochenThemaGilt): ?>
                    <div class="day-week-topic">Wochenbereich: <?= e($wochenThema) ?></div>
                <?php endif; ?>
                <?php if ($taetigkeiten !== ''): ?>
                    <p><?= nl2br(e($taetigkeiten)) ?></p>
                <?php elseif ($wochenThemaGilt): ?>
                    <p class="hint">Keine Tagesdetails eingetragen; der Wochenbereich gilt für diesen Arbeitstag.</p>
                <?php else: ?>
                    <p class="hint">Keine Tätigkeiten eingetragen.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderBerichtCard(array $bericht, bool $canApproveAsAusbilder, bool $canApproveAsLeiter, bool $showActions): void
{
    $status = (string) $bericht['status'];
    $wartetAufAusbilder = $status === STATUS_EINGEREICHT;
    $wartetAufLeiter = $status === STATUS_AUSBILDER_GENEHMIGT;
    $istFinal = $status === STATUS_FREIGEGEBEN;
    $istAbgelehnt = $status === STATUS_ABGELEHNT;
    $name = trim(trim((string) ($bericht['nachname'] ?? '')) . ', ' . trim((string) ($bericht['vorname'] ?? '')), ', ');
    if ($name === '') {
        $name = 'Ohne Namen';
    }
    ?>
    <article class="admin-report-card <?= $istFinal ? 'is-done' : '' ?>">
        <div class="report-card-top">
            <div class="report-title-block">
                <p class="section-kicker">KW <?= (int) $bericht['kw'] ?> / <?= (int) $bericht['jahr'] ?></p>
                <h3><?= e($name) ?></h3>
                <span class="muted-line report-user-id">User-ID: <?= e($bericht['user_id']) ?></span>
                <?php if (trim((string) ($bericht['wochen_thema'] ?? '')) !== ''): ?>
                    <span class="week-topic-chip">Wochenbereich: <?= e($bericht['wochen_thema']) ?></span>
                <?php endif; ?>
                <span class="muted-line report-training-year">Ausbildungsjahr:
                    <?= (int) ($bericht['ausbildungsjahr'] ?? 1) ?></span>
            </div>
            <div class="report-card-stats">
                <span class="status-badge <?= statusBadgeClass($status) ?>"><?= e($status) ?></span>
                <strong><?= number_format(sumStunden($bericht), 1, ',', '.') ?> Std.</strong>
                <span>Aktualisiert: <?= e($bericht['aktualisiert_am']) ?></span>
                <span>Version: <?= (int) ($bericht['version'] ?? 1) ?> ·
                    <?= ((int) ($bericht['is_locked'] ?? 0) === 1) ? 'gesperrt' : 'bearbeitbar' ?></span>
                <?php if (trim((string) ($bericht['content_hash'] ?? '')) !== ''): ?>
                    <span class="hash-short">Hash: <?= e(substr((string) $bericht['content_hash'], 0, 12)) ?>…</span>
                <?php endif; ?>
                <a href="export_word.php?id=<?= (int) $bericht['id'] ?>" class="button-link small word-export-button">Word
                    Export</a>
                <a href="export_pdf.php?id=<?= (int) $bericht['id'] ?>" class="button-link small pdf-export-button">PDF
                    Export</a>
            </div>
        </div>

        <div class="report-approval-grid">
            <div>
                <span>1. Freigabe Ausbilder</span>
                <strong><?= e(approvalInfoText(($bericht['ausbilder_genehmigt_realname'] ?? '') !== '' ? $bericht['ausbilder_genehmigt_realname'] : ($bericht['ausbilder_genehmigt_von'] ?? null), $bericht['ausbilder_genehmigt_am'] ?? null)) ?></strong>
            </div>
            <div>
                <span>2. Freigabe Ausbildungsleitung</span>
                <strong><?= e(approvalInfoText(($bericht['ausbildungsleiter_genehmigt_realname'] ?? '') !== '' ? $bericht['ausbildungsleiter_genehmigt_realname'] : ($bericht['ausbildungsleiter_genehmigt_von'] ?? null), $bericht['ausbildungsleiter_genehmigt_am'] ?? null)) ?></strong>
            </div>
        </div>

        <details class="admin-details improved-details">
            <summary>Tagesdaten anzeigen</summary>
            <?php renderTagesdaten($bericht); ?>
        </details>

        <?php $auditLog = getNachweisAuditLog(getDb(), (int) $bericht['id']); ?>
        <?php if ($auditLog): ?>
            <details class="admin-details audit-details">
                <summary>Audit-Log anzeigen</summary>
                <ul class="audit-list">
                    <?php foreach ($auditLog as $audit): ?>
                        <li>
                            <strong><?= e(formatAuditActionLabel((string) $audit['action'])) ?></strong>
                            <span><?= e($audit['created_at']) ?> · <?= e($audit['actor_realname']) ?>
                                (<?= e($audit['actor_role']) ?>)</span>
                            <?php if (trim((string) ($audit['comment'] ?? '')) !== ''): ?>
                                <em><?= e($audit['comment']) ?></em>
                            <?php endif; ?>
                            <code><?= e(substr((string) $audit['chain_hash'], 0, 16)) ?>…</code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php if ($showActions && ($wartetAufAusbilder || $wartetAufLeiter)): ?>
            <form method="post" class="approval-action-form report-action-panel">
                <?= csrfField() ?>
                <input type="hidden" name="bericht_id" value="<?= (int) $bericht['id'] ?>">
                <label class="block-label compact-label">
                    <span>Kommentar</span>
                    <textarea name="kommentar" rows="2"
                        placeholder="Optional bei Genehmigung, Pflicht bei Ablehnung"></textarea>
                </label>
                <div class="approval-buttons">
                    <?php if ($wartetAufAusbilder): ?>
                        <button type="submit" name="admin_action" value="ausbilder_genehmigen" <?= !$canApproveAsAusbilder ? 'disabled title="Nur Gruppe Ausbilder"' : '' ?>>1. Genehmigung</button>
                        <button type="submit" name="admin_action" value="ablehnen" class="button-danger" <?= !$canApproveAsAusbilder ? 'disabled title="Nur Gruppe Ausbilder"' : '' ?>>Ablehnen</button>
                    <?php elseif ($wartetAufLeiter): ?>
                        <button type="submit" name="admin_action" value="ausbildungsleiter_genehmigen" <?= !$canApproveAsLeiter ? 'disabled title="Nur Gruppe Ausbildungsleiter"' : '' ?>>2. Genehmigung</button>
                        <button type="submit" name="admin_action" value="ablehnen" class="button-danger" <?= !$canApproveAsLeiter ? 'disabled title="Nur Gruppe Ausbildungsleiter"' : '' ?>>Ablehnen</button>

                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($istFinal): ?>
            <p class="done-note">Erledigt und final freigegeben.</p>
        <?php elseif ($istAbgelehnt): ?>
            <div class="approval-comment">
                <strong>Abgelehnt.</strong>
                <?= trim((string) ($bericht['ausbilder_kommentar'] ?? '')) !== '' ? e($bericht['ausbilder_kommentar']) : 'Wartet auf erneute Einreichung.' ?>
            </div>
        <?php else: ?>
            <p class="hint">Noch nicht eingereicht.</p>
        <?php endif; ?>
    </article>
    <?php
}

$pdo = getDb();
$access = currentAccess($pdo);

$ldapProfile = $access['ldapProfile'];
$userId = $access['userId'];

$isLdapAdmin = $access['isLdapAdmin'];
$hatAzubiGruppe = $access['hatAzubiGruppe'];
$hatAusbilderGruppe = $access['hatAusbilderGruppe'];
$hatLeiterGruppe = $access['hatLeiterGruppe'];

$canManageGroups = $access['canManageGroups'];
$canApproveAsAusbilder = $access['canApproveAsAusbilder'];
$canApproveAsLeiter = $access['canApproveAsLeiter'];
$hasAdminAccess = $access['hasAdminAccess'];
$approvalActorName = getApprovalActorDisplayName($pdo, $ldapProfile, $userId);

if (!$hasAdminAccess) {
    http_response_code(403);
    die('Kein Zugriff: Diese Seite ist nur für Admins, Ausbilder oder Ausbildungsleiter vorgesehen.');
}

$errors = [];
$success = '';

// --- POST-Verarbeitung: Gruppenpflege und Freigabeaktionen --------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $adminAction = (string) ($_POST['admin_action'] ?? '');

    try {
        if ($adminAction === 'gruppe_speichern') {
            if (!$canManageGroups) {
                throw new RuntimeException('Nur Admins dürfen Gruppen bearbeiten.');
            }

            saveFreigabeGruppe(
                $pdo,
                (string) ($_POST['gruppe_user_id'] ?? ''),
                (string) ($_POST['gruppe_anzeigename'] ?? ''),
                (string) ($_POST['gruppe'] ?? ''),
                $userId
            );
            $success = 'Gruppe wurde gespeichert.';
        } elseif ($adminAction === 'gruppe_loeschen') {
            if (!$canManageGroups) {
                throw new RuntimeException('Nur Admins dürfen Gruppen bearbeiten.');
            }

            deleteFreigabeGruppe($pdo, (int) ($_POST['gruppen_id'] ?? 0));
            $success = 'Gruppe wurde entfernt.';
        } elseif ($adminAction === 'berufsschule_speichern') {
            if (!$canApproveAsAusbilder && !$canApproveAsLeiter) {
                throw new RuntimeException('Nur Ausbilder oder Ausbildungsleiter dürfen Berufsschultage pflegen.');
            }

            $schuleUserId = normalizeUserId((string) ($_POST['schule_user_id'] ?? ''));
            if ($schuleUserId === '') {
                throw new RuntimeException('Bitte einen Azubi für die Berufsschultage auswählen.');
            }

            saveBerufsschultage(
                $pdo,
                $schuleUserId,
                [
                    'montag' => isset($_POST['montag']),
                    'dienstag' => isset($_POST['dienstag']),
                    'mittwoch' => isset($_POST['mittwoch']),
                    'donnerstag' => isset($_POST['donnerstag']),
                    'freitag' => isset($_POST['freitag']),
                ]
            );

            $success = 'Berufsschultage wurden gespeichert.';
        } elseif (in_array($adminAction, ['ausbilder_genehmigen', 'ausbildungsleiter_genehmigen', 'ablehnen'], true)) {
            $berichtId = (int) ($_POST['bericht_id'] ?? 0);
            $kommentar = trim((string) ($_POST['kommentar'] ?? ''));
            $bericht = findWochenberichtById($pdo, $berichtId);

            if (!$bericht) {
                throw new RuntimeException('Der ausgewählte Wochenbericht wurde nicht gefunden.');
            }

            $status = (string) $bericht['status'];
            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            if ($adminAction === 'ausbilder_genehmigen') {
                if (!$canApproveAsAusbilder) {
                    throw new RuntimeException('Für die erste Genehmigung ist die Gruppe Ausbilder erforderlich.');
                }
                if ($status !== STATUS_EINGEREICHT) {
                    throw new RuntimeException('Die erste Genehmigung ist nur bei eingereichten Berichten möglich.');
                }

                $stmt = $pdo->prepare('UPDATE wochenberichte SET
                    status = :status,
                    ausbilder_kommentar = :kommentar,
                    ausbilder_genehmigt_am = :now,
                    ausbilder_genehmigt_von = :actor_name,
                    ausbilder_genehmigt_username = :actor_username,
                    ausbilder_genehmigt_realname = :actor_realname,
                    ausbildungsleiter_genehmigt_am = NULL,
                    ausbildungsleiter_genehmigt_von = NULL,
                    ausbildungsleiter_genehmigt_username = NULL,
                    ausbildungsleiter_genehmigt_realname = NULL,
                    freigegeben_am = NULL,
                    freigegeben_von = NULL,
                    is_locked = 1,
                    aktualisiert_am = :now2
                    WHERE id = :id AND status = :erwartet');
                $stmt->execute([
                    ':status' => STATUS_AUSBILDER_GENEHMIGT,
                    ':kommentar' => $kommentar,
                    ':now' => $now,
                    ':now2' => $now,
                    ':actor_name' => $approvalActorName,
                    ':actor_username' => $userId,
                    ':actor_realname' => $approvalActorName,
                    ':id' => $berichtId,
                    ':erwartet' => STATUS_EINGEREICHT,
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Der Bericht wurde zwischenzeitlich geändert. Bitte die Seite neu laden.');
                }

                addNachweisAuditLog(
                    $pdo,
                    $berichtId,
                    'approved_trainer',
                    $userId,
                    $approvalActorName,
                    'Ausbilder',
                    $status,
                    STATUS_AUSBILDER_GENEHMIGT,
                    $kommentar,
                    $now
                );
                addHistorie($pdo, $berichtId, $userId, 'Erste Genehmigung durch Ausbilder erteilt.');
                $success = 'Erste Genehmigung wurde erteilt.';
            } elseif ($adminAction === 'ausbildungsleiter_genehmigen') {
                if (!$canApproveAsLeiter) {
                    throw new RuntimeException('Für die zweite Genehmigung ist die Gruppe Ausbildungsleiter erforderlich.');
                }
                if ($status !== STATUS_AUSBILDER_GENEHMIGT) {
                    throw new RuntimeException('Die zweite Genehmigung ist erst nach der Ausbilder-Genehmigung möglich.');
                }

                $stmt = $pdo->prepare('UPDATE wochenberichte SET
                    status = :status,
                    ausbildungsleiter_genehmigt_am = :now,
                    ausbildungsleiter_genehmigt_von = :actor_name,
                    ausbildungsleiter_genehmigt_username = :actor_username,
                    ausbildungsleiter_genehmigt_realname = :actor_realname,
                    freigegeben_am = :now2,
                    freigegeben_von = :actor_name2,
                    is_locked = 1,
                    aktualisiert_am = :now3
                    WHERE id = :id AND status = :erwartet');
                $stmt->execute([
                    ':status' => STATUS_FREIGEGEBEN,
                    ':now' => $now,
                    ':now2' => $now,
                    ':now3' => $now,
                    ':actor_name' => $approvalActorName,
                    ':actor_name2' => $approvalActorName,
                    ':actor_username' => $userId,
                    ':actor_realname' => $approvalActorName,
                    ':id' => $berichtId,
                    ':erwartet' => STATUS_AUSBILDER_GENEHMIGT,
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Der Bericht wurde zwischenzeitlich geändert. Bitte die Seite neu laden.');
                }

                addNachweisAuditLog(
                    $pdo,
                    $berichtId,
                    'approved_leader',
                    $userId,
                    $approvalActorName,
                    'Ausbildungsleiter',
                    $status,
                    STATUS_FREIGEGEBEN,
                    $kommentar,
                    $now
                );

                if ($kommentar !== '') {
                    $stmt = $pdo->prepare('UPDATE wochenberichte SET ausbilder_kommentar = :kommentar WHERE id = :id');
                    $stmt->execute([':kommentar' => $kommentar, ':id' => $berichtId]);
                }

                addHistorie($pdo, $berichtId, $userId, 'Zweite Genehmigung durch Ausbildungsleiter erteilt. Wochenbericht final freigegeben.');
                $success = 'Zweite Genehmigung wurde erteilt. Der Wochenbericht ist jetzt erledigt.';
            } else { // ablehnen
                $ablehnendeRolle = '';
                if ($status === STATUS_EINGEREICHT) {
                    if (!$canApproveAsAusbilder) {
                        throw new RuntimeException('Ablehnung auf dieser Stufe ist nur für Ausbilder möglich.');
                    }
                    $ablehnendeRolle = 'Ausbilder';
                } elseif ($status === STATUS_AUSBILDER_GENEHMIGT) {
                    if (!$canApproveAsLeiter) {
                        throw new RuntimeException('Ablehnung auf dieser Stufe ist nur für Ausbildungsleiter möglich.');
                    }
                    $ablehnendeRolle = 'Ausbildungsleiter';
                } else {
                    throw new RuntimeException('Dieser Wochenbericht kann in seinem aktuellen Status nicht abgelehnt werden.');
                }

                if ($kommentar === '') {
                    throw new RuntimeException('Bitte bei einer Ablehnung einen Kommentar angeben.');
                }

                $stmt = $pdo->prepare('UPDATE wochenberichte SET
                    status = :status,
                    ausbilder_kommentar = :kommentar,
                    freigegeben_am = NULL,
                    freigegeben_von = NULL,
                    ausbilder_genehmigt_am = NULL,
                    ausbilder_genehmigt_von = NULL,
                    ausbilder_genehmigt_username = NULL,
                    ausbilder_genehmigt_realname = NULL,
                    ausbildungsleiter_genehmigt_am = NULL,
                    ausbildungsleiter_genehmigt_von = NULL,
                    ausbildungsleiter_genehmigt_username = NULL,
                    ausbildungsleiter_genehmigt_realname = NULL,
                    is_locked = 0,
                    locked_at = NULL,
                    locked_by_username = NULL,
                    locked_by_realname = NULL,
                    aktualisiert_am = :now
                    WHERE id = :id AND status = :erwartet');
                $stmt->execute([
                    ':status' => STATUS_ABGELEHNT,
                    ':kommentar' => $kommentar,
                    ':now' => $now,
                    ':id' => $berichtId,
                    ':erwartet' => $status,
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Der Bericht wurde zwischenzeitlich geändert. Bitte die Seite neu laden.');
                }

                addNachweisAuditLog(
                    $pdo,
                    $berichtId,
                    'rejected',
                    $userId,
                    $approvalActorName,
                    $ablehnendeRolle,
                    $status,
                    STATUS_ABGELEHNT,
                    $kommentar,
                    $now
                );
                addHistorie($pdo, $berichtId, $userId, 'Wochenbericht durch ' . $ablehnendeRolle . ' abgelehnt: ' . $kommentar);
                $success = 'Wochenbericht wurde abgelehnt und für den Azubi wieder entsperrt.';
            }
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

// --- Filter (GET-Parameter) ----------------------------------

$filterStatus = (string) ($_GET['status'] ?? '');
$filterSearch = normalizeAdminSearchQuery((string) ($_GET['q'] ?? ''));
$filterJahr = isset($_GET['jahr']) && $_GET['jahr'] !== '' ? (int) $_GET['jahr'] : null;
$filterKw = isset($_GET['kw']) && $_GET['kw'] !== '' ? (int) $_GET['kw'] : null;
$filterUserId = normalizeUserId((string) ($_GET['user_id'] ?? ''));

if ($filterKw !== null && ($filterKw < 1 || $filterKw > 53)) {
    $filterKw = null;
}
if ($filterJahr !== null && ($filterJahr < 2000 || $filterJahr > 2100)) {
    $filterJahr = null;
}

$sql = 'SELECT * FROM wochenberichte WHERE status <> :final_status';
$params = [':final_status' => STATUS_FREIGEGEBEN];

if ($filterStatus !== '' && in_array($filterStatus, activeStatusOptions(), true)) {
    $sql .= ' AND status = :status';
    $params[':status'] = $filterStatus;
}
applyCommonFilters($sql, $params, $filterJahr, $filterKw, $filterUserId);
applyComboSearchFilter($sql, $params, $filterSearch);
$sql .= ' ORDER BY jahr DESC, kw DESC, nachname ASC, vorname ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$berichte = $stmt->fetchAll();

$archiveSearchActive = hasArchiveSearchCriteria($filterSearch, $filterJahr, $filterKw, $filterUserId);
$stmtDoneCount = $pdo->query('SELECT COUNT(*) FROM wochenberichte WHERE status = ' . $pdo->quote(STATUS_FREIGEGEBEN));
$erledigteGesamtCount = $stmtDoneCount ? (int) $stmtDoneCount->fetchColumn() : 0;
$erledigteBerichte = [];

if ($archiveSearchActive) {
    $sqlDone = 'SELECT * FROM wochenberichte WHERE status = :done_status';
    $paramsDone = [':done_status' => STATUS_FREIGEGEBEN];
    applyCommonFilters($sqlDone, $paramsDone, $filterJahr, $filterKw, $filterUserId);
    applyComboSearchFilter($sqlDone, $paramsDone, $filterSearch);
    $sqlDone .= ' ORDER BY jahr DESC, kw DESC, nachname ASC, vorname ASC';

    $stmtDone = $pdo->prepare($sqlDone);
    $stmtDone->execute($paramsDone);
    $erledigteBerichte = $stmtDone->fetchAll();
}

$freigabeGruppen = listFreigabeGruppen($pdo);
$azubiFilterOptions = listAzubiFilterOptions($pdo);
$rueckstandsWarnungen = ($isLdapAdmin || $canApproveAsAusbilder) ? getAzubiRueckstandsWarnungen($pdo) : [];
$statusCalendar = buildStatusCalendar($pdo, $azubiFilterOptions, $filterUserId, $filterJahr, $filterKw, $filterSearch);

$rollenAnzeige = [];
if ($isLdapAdmin) {
    $rollenAnzeige[] = 'Admin';
}
if ($hatAzubiGruppe) {
    $rollenAnzeige[] = 'Azubi';
}
if ($hatAusbilderGruppe) {
    $rollenAnzeige[] = 'Ausbilder';
}
if ($hatLeiterGruppe) {
    $rollenAnzeige[] = 'Ausbildungsleiter';
}

if (!$rollenAnzeige) {
    $rollenAnzeige[] = 'Leserechte';
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LBV NRW · Ausbildungsnachweis · Adminbereich</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <main class="page-shell admin-shell">
        <header class="agency-header">
            <div class="brand-block">
                <img src="nrw-wappen.jpg" alt="Wappen Nordrhein-Westfalen" class="coat-of-arms">
                <div>
                    <p class="authority">Landesamt für Besoldung und Versorgung NRW</p>
                    <h1>Adminbereich · Ausbildungsnachweise</h1>
                </div>
            </div>
            <div class="header-actions">
                <a href="index.php" class="button-link small">Zur eigenen Erfassung</a>
            </div>
        </header>

        <section class="intro-card single">
            <div>
                <p class="section-kicker">Freigabeprozess</p>
                <p>
                    Angemeldet als <?= e($userId) ?> · Rolle: <?= e(implode(', ', $rollenAnzeige)) ?>.

                </p>
            </div>
        </section>

        <?php if ($errors): ?>
            <div class="notice error">
                <strong>Aktion konnte nicht ausgeführt werden:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="notice success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($rueckstandsWarnungen): ?>
            <section class="notice warning admin-warning-window backlog-warning-panel">
                <div class="backlog-warning-head">
                    <div>
                        <strong>Rückstands-Warnung für Ausbilder</strong>
                        <p>
                            Angezeigt werden alle Wochen, die älter als zwei Wochen sind und seit der ersten tatsächlichen
                            Abgabe des Azubis noch fehlen,
                            nur als Entwurf vorliegen oder nach Ablehnung wieder offen sind.
                        </p>
                    </div>
                    <span class="backlog-warning-count"><?= count($rueckstandsWarnungen) ?>
                        Azubi<?= count($rueckstandsWarnungen) === 1 ? '' : 's' ?></span>
                </div>

                <div class="backlog-warning-list">
                    <?php foreach ($rueckstandsWarnungen as $warnung): ?>
                        <details class="backlog-warning-item" open>
                            <summary>
                                <span>
                                    <strong><?= e($warnung['name'] !== '' ? $warnung['name'] : $warnung['user_id']) ?></strong>
                                    <?= $warnung['name'] !== '' ? '<small>(' . e($warnung['user_id']) . ')</small>' : '' ?>
                                </span>
                                <span class="backlog-summary-meta">
                                    <?= (int) $warnung['gesamt'] ?> offen · Tracking ab <?= e($warnung['start']) ?>
                                </span>
                            </summary>
                            <div class="backlog-warning-stats">
                                <span>Fehlt: <?= (int) $warnung['fehlt'] ?></span>
                                <span>Entwurf: <?= (int) $warnung['entwurf'] ?></span>
                                <span>Abgelehnt/offen: <?= (int) $warnung['abgelehnt'] ?></span>
                            </div>
                            <div class="backlog-week-list">
                                <?php foreach ($warnung['wochen'] as $woche): ?>
                                    <span><?= e($woche) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="data-card">
            <form method="get" class="filter-row admin-filter-row">
                <label class="inline-label status-filter">
                    <span>Status</span>
                    <select name="status">
                        <option value="">Alle offenen</option>
                        <?php foreach (activeStatusOptions() as $statusOption): ?>
                            <option value="<?= e($statusOption) ?>" <?= $filterStatus === $statusOption ? 'selected' : '' ?>>
                                <?= e($statusOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="inline-label azubi-filter">
                    <span>Azubi</span>
                    <select name="user_id">
                        <option value="">Alle Azubis</option>
                        <?php foreach ($azubiFilterOptions as $optionUserId => $optionName): ?>
                            <option value="<?= e($optionUserId) ?>" <?= $filterUserId === $optionUserId ? 'selected' : '' ?>>
                                <?= e($optionName !== '' ? $optionName . ' · ' . $optionUserId : $optionUserId) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="inline-label combo-filter">
                    <span>Kombi-Suche</span>
                    <input type="search" name="q" value="<?= e($filterSearch) ?>"
                        placeholder="z. B. Name KW oder Name Jahr">
                    <small>Name/User-ID + KW/Jahr kombinierbar</small>
                </label>
                <label class="inline-label tiny-filter">
                    <span>KW</span>
                    <input type="number" name="kw" value="<?= $filterKw !== null ? e((string) $filterKw) : '' ?>"
                        min="1" max="53" placeholder="alle">
                </label>
                <label class="inline-label tiny-filter">
                    <span>Jahr</span>
                    <input type="number" name="jahr" value="<?= $filterJahr !== null ? e((string) $filterJahr) : '' ?>"
                        min="2000" max="2100" placeholder="alle">
                </label>
                <button type="submit" class="button-secondary">Filtern</button>
                <a href="admin.php" class="button-link small">Zurücksetzen</a>
                <?php
                $pdfZipParams = ['scope' => 'admin'];
                if ($filterStatus !== '') {
                    $pdfZipParams['status'] = $filterStatus;
                }
                if ($filterSearch !== '') {
                    $pdfZipParams['q'] = $filterSearch;
                }
                if ($filterUserId !== '') {
                    $pdfZipParams['user_id'] = $filterUserId;
                }
                if ($filterKw !== null) {
                    $pdfZipParams['kw'] = $filterKw;
                }
                if ($filterJahr !== null) {
                    $pdfZipParams['jahr'] = $filterJahr;
                }
                ?>
                <a href="export_pdf_zip.php?<?= e(http_build_query($pdfZipParams)) ?>"
                    class="button-link small pdf-zip-button">PDF-ZIP exportieren</a>
            </form>

            <div class="data-card-header">
                <div>
                    <p class="section-kicker">Offene Bearbeitung</p>
                    <h2>Wochenberichte</h2>
                </div>
                <span><?= count($berichte) ?> Einträge</span>
            </div>

            <?php renderStatusCalendar($statusCalendar); ?>

            <?php if (!$berichte): ?>
                <p class="empty-state">Keine offenen Wochenberichte für die aktuelle Filterauswahl gefunden.</p>
            <?php else: ?>
                <div class="admin-report-list">
                    <?php foreach ($berichte as $bericht): ?>
                        <?php renderBerichtCard($bericht, $canApproveAsAusbilder, $canApproveAsLeiter, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="data-card done-section archive-search-section">
            <div class="data-card-header compact-archive-header">
                <div>
                    <p class="section-kicker">Archivierte Freigaben</p>
                    <h2>Erledigte Wochenberichte</h2>
                </div>
                <span><?= $archiveSearchActive ? count($erledigteBerichte) . ' Treffer' : $erledigteGesamtCount . ' gesamt' ?></span>
            </div>

            <?php if (!$archiveSearchActive): ?>
                <div class="archive-search-hint">
                    <strong>Archiv ausgeblendet.</strong>
                    <span>Nutze die Kombi-Suche oder einen Azubi-/KW-/Jahr-Filter.</span>
                    <small>Beispiel: <code>Name 25</code> zeigt nur Name in KW 25. <code>Malte</code> zeigt alle
                        freigegebenen Wochen von Malte.</small>
                </div>
            <?php elseif (!$erledigteBerichte): ?>
                <p class="empty-state">Keine final freigegebenen Wochenberichte für diese Suche gefunden.</p>
            <?php else: ?>
                <details class="archive-results-details" open>
                    <summary>Erledigte Wochenberichte anzeigen (<?= count($erledigteBerichte) ?>)</summary>
                    <div class="admin-report-list compact-done-list">
                        <?php foreach ($erledigteBerichte as $bericht): ?>
                            <?php renderBerichtCard($bericht, $canApproveAsAusbilder, $canApproveAsLeiter, false); ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section class="data-card berufsschule-section">
            <div class="data-card-header">
                <div>
                    <p class="section-kicker">Wiederkehrende Berufsschultage</p>
                    <h2>Berufsschultage je Azubi</h2>
                </div>
            </div>
            <p class="hint">
                Die hier gesetzten Wochentage werden bei neuen Wochen automatisch als Berufsschule vorbelegt.
            </p>

            <?php
            $schuleUserId = normalizeUserId((string) ($_GET['schule_user_id'] ?? ''));
            $schuleAktuell = $schuleUserId !== '' ? getBerufsschultage($pdo, $schuleUserId) : [];
            ?>

            <form method="get" class="filter-row">
                <label class="inline-label">
                    <span>Azubi</span>
                    <select name="schule_user_id" onchange="this.form.submit()">
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($azubiFilterOptions as $optUserId => $optName): ?>
                            <option value="<?= e((string) $optUserId) ?>" <?= $schuleUserId === normalizeUserId((string) $optUserId) ? 'selected' : '' ?>>
                                <?= e((string) $optName) ?> (<?= e((string) $optUserId) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button type="submit" class="button-secondary">Anzeigen</button></noscript>
            </form>

            <?php if ($schuleUserId !== ''): ?>
                <form method="post" class="approval-group-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="admin_action" value="berufsschule_speichern">
                    <input type="hidden" name="schule_user_id" value="<?= e($schuleUserId) ?>">
                    <?php foreach (WOCHENTAGE as $tag): ?>
                        <label class="inline-label checkbox-inline">
                            <input type="checkbox" name="<?= e($tag) ?>" value="1"
                                <?= !empty($schuleAktuell[$tag]) ? 'checked' : '' ?>>
                            <span><?= e(WOCHENTAG_LABELS[$tag]) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit">Berufsschultage speichern</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($canManageGroups): ?>
            <section class="data-card group-management-section">
                <div class="data-card-header">
                    <div>
                        <p class="section-kicker">Manuelle Gruppenverwaltung</p>
                        <h2>Benutzergruppen</h2>
                    </div>
                    <span><?= count($freigabeGruppen) ?> Einträge</span>
                </div>
                <p class="hint group-hint">
                    Azubi = Zugriff auf die eigene Erfassung. Ausbilder = erste Genehmigung. Ausbildungsleiter = finale
                    zweite Genehmigung. Admin = Vollzugriff inkl. Gruppenverwaltung (ersetzt die fest im Code hinterlegte
                    Admin-Liste).
                </p>

                <form method="post" class="approval-group-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="admin_action" value="gruppe_speichern">
                    <label class="inline-label">
                        <span>User-ID</span>
                        <input type="text" name="gruppe_user_id" placeholder="z. B. GI7LBV5" required>
                    </label>
                    <label class="inline-label">
                        <span>Anzeigename</span>
                        <input type="text" name="gruppe_anzeigename" placeholder="optional">
                    </label>
                    <label class="inline-label">
                        <span>Gruppe</span>
                        <select name="gruppe" required>
                            <?php foreach (approvalGroupOptions() as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Gruppe setzen</button>
                </form>

                <?php if (!$freigabeGruppen): ?>
                    <p class="empty-state">Noch keine Gruppen gesetzt.</p>
                <?php else: ?>
                    <div class="table-wrap compact-table-wrap">
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>User-ID</th>
                                    <th>Name</th>
                                    <th>Gruppe</th>
                                    <th>Gesetzt von</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($freigabeGruppen as $gruppe): ?>
                                    <tr>
                                        <td><?= e($gruppe['user_id']) ?></td>
                                        <td><?= e($gruppe['anzeigename']) ?></td>
                                        <td><span
                                                class="role-badge role-<?= e($gruppe['gruppe']) ?>"><?= e(approvalGroupLabel($gruppe['gruppe'])) ?></span>
                                        </td>
                                        <td><?= e($gruppe['erstellt_von']) ?></td>
                                        <td>
                                            <form method="post" class="inline-form"
                                                onsubmit="return confirm('Gruppe wirklich entfernen?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="admin_action" value="gruppe_loeschen">
                                                <input type="hidden" name="gruppen_id" value="<?= (int) $gruppe['id'] ?>">
                                                <button type="submit" class="button-danger small-button">Entfernen</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>