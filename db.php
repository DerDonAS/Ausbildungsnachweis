<?php
declare(strict_types=1);


const DB_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ausbildungsnachweis.sqlite';
const DB_FILE_FALLBACK = __DIR__ . DIRECTORY_SEPARATOR . 'ausbildungsnachweis.sqlite';

const STATUS_ENTWURF = 'Entwurf';
const STATUS_EINGEREICHT = 'Eingereicht';
const STATUS_AUSBILDER_GENEHMIGT = 'Ausbilder genehmigt';
const STATUS_FREIGEGEBEN = 'Freigegeben';
const STATUS_ABGELEHNT = 'Abgelehnt';

const APPROVAL_GROUP_AZUBI = 'azubi';
const APPROVAL_GROUP_AUSBILDER = 'ausbilder';
const APPROVAL_GROUP_AUSBILDUNGSLEITER = 'ausbildungsleiter';
const APPROVAL_GROUP_ADMIN = 'admin';

const WOCHENTAGE = ['montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag'];

const WOCHENTAG_LABELS = [
    'montag' => 'Montag',
    'dienstag' => 'Dienstag',
    'mittwoch' => 'Mittwoch',
    'donnerstag' => 'Donnerstag',
    'freitag' => 'Freitag',
];

/**
 * Nutzt bevorzugt einen ueber die Umgebungsvariable AUSBILDUNG_DB_PATH
 * gesetzten Pfad. Damit kann die SQLite-Datei AUSSERHALB des Web-Root
 * abgelegt werden (empfohlen, damit sie bei einer Fehlkonfiguration des
 * Webservers nicht direkt herunterladbar ist).
 *
 * Faellt zurueck auf /data/ausbildungsnachweis.sqlite. Falls beim
 * Prototypen die Datenbank direkt neben den PHP-Dateien liegt, wird
 * diese Datei automatisch weiterverwendet.
 */
function getDbFilePath(): string
{
    $envPath = getenv('AUSBILDUNG_DB_PATH');
    if (is_string($envPath) && trim($envPath) !== '') {
        return trim($envPath);
    }

    if (file_exists(DB_FILE) || !file_exists(DB_FILE_FALLBACK)) {
        return DB_FILE;
    }

    return DB_FILE_FALLBACK;
}

/**
 * Liefert eine PDO-Verbindung zur SQLite-Datenbank und stellt sicher,
 * dass das benötigte Schema existiert.
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbFile = getDbFilePath();
    $dataDir = dirname($dbFile);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initSchema($pdo);

    return $pdo;
}

function initSchema(PDO $pdo): void
{
    // Eine Zeile = eine Kalenderwoche eines Auszubildenden.
    $pdo->exec("CREATE TABLE IF NOT EXISTS wochenberichte (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        nachname TEXT,
        vorname TEXT,
        jahr INTEGER NOT NULL,
        kw INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'Entwurf',
        ausbildungsjahr INTEGER NOT NULL DEFAULT 1,
        wochen_thema TEXT NOT NULL DEFAULT '',

        montag_typ TEXT NOT NULL DEFAULT 'arbeit',
        montag_stunden REAL NOT NULL DEFAULT 8,
        montag_taetigkeiten TEXT NOT NULL DEFAULT '',

        dienstag_typ TEXT NOT NULL DEFAULT 'arbeit',
        dienstag_stunden REAL NOT NULL DEFAULT 8,
        dienstag_taetigkeiten TEXT NOT NULL DEFAULT '',

        mittwoch_typ TEXT NOT NULL DEFAULT 'arbeit',
        mittwoch_stunden REAL NOT NULL DEFAULT 8,
        mittwoch_taetigkeiten TEXT NOT NULL DEFAULT '',

        donnerstag_typ TEXT NOT NULL DEFAULT 'arbeit',
        donnerstag_stunden REAL NOT NULL DEFAULT 8,
        donnerstag_taetigkeiten TEXT NOT NULL DEFAULT '',

        freitag_typ TEXT NOT NULL DEFAULT 'arbeit',
        freitag_stunden REAL NOT NULL DEFAULT 8,
        freitag_taetigkeiten TEXT NOT NULL DEFAULT '',

        ausbilder_kommentar TEXT NOT NULL DEFAULT '',
        erstellt_am TEXT NOT NULL,
        aktualisiert_am TEXT NOT NULL,
        eingereicht_am TEXT,

        ausbilder_genehmigt_am TEXT,
        ausbilder_genehmigt_von TEXT,
        ausbildungsleiter_genehmigt_am TEXT,
        ausbildungsleiter_genehmigt_von TEXT,

        freigegeben_am TEXT,
        freigegeben_von TEXT,

        is_locked INTEGER NOT NULL DEFAULT 0,
        locked_at TEXT,
        locked_by_username TEXT,
        locked_by_realname TEXT,
        version INTEGER NOT NULL DEFAULT 1,
        content_hash TEXT NOT NULL DEFAULT '',
        ausbilder_genehmigt_username TEXT,
        ausbilder_genehmigt_realname TEXT,
        ausbildungsleiter_genehmigt_username TEXT,
        ausbildungsleiter_genehmigt_realname TEXT,

        UNIQUE(user_id, jahr, kw)
    )");

    migrateWochenberichte($pdo);

    // Historie / einfache Anzeige je Wochenbericht.
    $pdo->exec("CREATE TABLE IF NOT EXISTS historie (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wochenbericht_id INTEGER NOT NULL,
        zeitpunkt TEXT NOT NULL,
        akteur TEXT NOT NULL,
        ereignis TEXT NOT NULL,
        FOREIGN KEY (wochenbericht_id) REFERENCES wochenberichte(id) ON DELETE CASCADE
    )");

    // Manipulationssicherer Audit-Log: Einträge werden nur angehängt und nicht überschrieben.
    $pdo->exec("CREATE TABLE IF NOT EXISTS nachweis_audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bericht_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        actor_username TEXT NOT NULL,
        actor_realname TEXT NOT NULL,
        actor_role TEXT NOT NULL,
        created_at TEXT NOT NULL,
        old_status TEXT,
        new_status TEXT,
        comment TEXT NOT NULL DEFAULT '',
        content_hash TEXT NOT NULL,
        previous_hash TEXT NOT NULL DEFAULT '',
        chain_hash TEXT NOT NULL,
        FOREIGN KEY (bericht_id) REFERENCES wochenberichte(id) ON DELETE CASCADE
    )");

    // Manuell gesetzte Freigabegruppen für den Adminbereich.
    $pdo->exec("CREATE TABLE IF NOT EXISTS freigabe_gruppen (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        anzeigename TEXT NOT NULL DEFAULT '',
        gruppe TEXT NOT NULL CHECK (gruppe IN ('azubi', 'ausbilder', 'ausbildungsleiter', 'admin')),
        erstellt_am TEXT NOT NULL,
        erstellt_von TEXT NOT NULL,
        UNIQUE(user_id, gruppe)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS berufsschultage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        montag INTEGER DEFAULT 0,
        dienstag INTEGER DEFAULT 0,
        mittwoch INTEGER DEFAULT 0,
        donnerstag INTEGER DEFAULT 0,
        freitag INTEGER DEFAULT 0,
        UNIQUE(user_id)
    )");

    migrateFreigabeGruppen($pdo);

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wochenberichte_user ON wochenberichte(user_id, jahr, kw)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historie_bericht ON historie(wochenbericht_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_bericht ON nachweis_audit_log(bericht_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_freigabe_gruppen_user ON freigabe_gruppen(user_id)");
}

function migrateWochenberichte(PDO $pdo): void
{
    ensureColumn($pdo, 'wochenberichte', 'ausbildungsjahr', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'wochenberichte', 'wochen_thema', "TEXT NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'wochenberichte', 'ausbilder_genehmigt_am', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbilder_genehmigt_von', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbildungsleiter_genehmigt_am', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbildungsleiter_genehmigt_von', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'is_locked', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'wochenberichte', 'locked_at', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'locked_by_username', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'locked_by_realname', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'version', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'wochenberichte', 'content_hash', "TEXT NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'wochenberichte', 'ausbilder_genehmigt_username', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbilder_genehmigt_realname', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbildungsleiter_genehmigt_username', 'TEXT');
    ensureColumn($pdo, 'wochenberichte', 'ausbildungsleiter_genehmigt_realname', 'TEXT');

    // Bestehende eingereichte/genehmigte/freigegebene Berichte nachträglich sperren.
    $pdo->exec("UPDATE wochenberichte
        SET is_locked = 1
        WHERE status IN ('Eingereicht', 'Ausbilder genehmigt', 'Freigegeben')
          AND COALESCE(is_locked, 0) = 0");
}

function migrateFreigabeGruppen(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'freigabe_gruppen'");
    $createSql = $stmt ? (string) $stmt->fetchColumn() : '';

    // Aufräumen, falls ein vorheriger Migrationsversuch abgebrochen wurde.
    // Genau dadurch kam der Fehler: table freigabe_gruppen_neu already exists.
    // Die Tabelle gilt als aktuell, sobald die CHECK-Constraint die
    // 'admin'-Gruppe enthaelt (das ist der jeweils neueste Stand).
    if ($createSql === '' || str_contains($createSql, "'admin'")) {
        $pdo->exec('DROP TABLE IF EXISTS freigabe_gruppen_neu');
        return;
    }

    // Ältere Versionen erlaubten nur einen Teil der Gruppen. Fuer neue
    // Gruppen (zuletzt 'admin') muss die SQLite-CHECK-Constraint einmalig
    // erneuert werden, da SQLite kein ALTER der Constraint kennt.
    $alreadyInTransaction = $pdo->inTransaction();

    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->exec('DROP TABLE IF EXISTS freigabe_gruppen_neu');

        $pdo->exec("CREATE TABLE freigabe_gruppen_neu (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            anzeigename TEXT NOT NULL DEFAULT '',
            gruppe TEXT NOT NULL CHECK (gruppe IN ('azubi', 'ausbilder', 'ausbildungsleiter', 'admin')),
            erstellt_am TEXT NOT NULL,
            erstellt_von TEXT NOT NULL,
            UNIQUE(user_id, gruppe)
        )");

        $pdo->exec("INSERT OR IGNORE INTO freigabe_gruppen_neu (id, user_id, anzeigename, gruppe, erstellt_am, erstellt_von)
            SELECT id, user_id, anzeigename, gruppe, erstellt_am, erstellt_von FROM freigabe_gruppen");

        $pdo->exec('DROP TABLE freigabe_gruppen');
        $pdo->exec('ALTER TABLE freigabe_gruppen_neu RENAME TO freigabe_gruppen');

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = $stmt ? $stmt->fetchAll() : [];

    foreach ($columns as $existingColumn) {
        if (($existingColumn['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

/**
 * Liefert die gespeicherten Berufsschultage eines Azubis als Zeile
 * (montag..freitag = 0/1). Leeres Array, wenn nichts hinterlegt ist.
 */
function getBerufsschultage(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM berufsschultage WHERE user_id = :user_id');
    $stmt->execute([':user_id' => normalizeUserId($userId)]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Speichert (oder aktualisiert) die wiederkehrenden Berufsschultage
 * eines Azubis.
 */
function saveBerufsschultage(PDO $pdo, string $userId, array $tage): void
{
    $userId = normalizeUserId($userId);
    if ($userId === '') {
        throw new InvalidArgumentException('Bitte eine User-ID angeben.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO berufsschultage (user_id, montag, dienstag, mittwoch, donnerstag, freitag)
         VALUES (:user_id, :montag, :dienstag, :mittwoch, :donnerstag, :freitag)
         ON CONFLICT(user_id) DO UPDATE SET
            montag = excluded.montag,
            dienstag = excluded.dienstag,
            mittwoch = excluded.mittwoch,
            donnerstag = excluded.donnerstag,
            freitag = excluded.freitag'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':montag' => !empty($tage['montag']) ? 1 : 0,
        ':dienstag' => !empty($tage['dienstag']) ? 1 : 0,
        ':mittwoch' => !empty($tage['mittwoch']) ? 1 : 0,
        ':donnerstag' => !empty($tage['donnerstag']) ? 1 : 0,
        ':freitag' => !empty($tage['freitag']) ? 1 : 0,
    ]);
}

/**
 * Berechnet ISO-Kalenderwoche und zugehöriges ISO-Jahr für ein Datum.
 * Gibt ['jahr' => int, 'kw' => int] zurück.
 */
function getIsoWeekFor(DateTimeImmutable $date): array
{
    return [
        'jahr' => (int) $date->format('o'),
        'kw' => (int) $date->format('W'),
    ];
}

function getCurrentIsoWeek(): array
{
    return getIsoWeekFor(new DateTimeImmutable('now'));
}

/**
 * Liefert Montag und Freitag (als DateTimeImmutable) einer ISO-Kalenderwoche.
 */
function getWeekRange(int $jahr, int $kw): array
{
    // ISO-8601: Woche 1 ist die Woche mit dem ersten Donnerstag des Jahres.
    $monday = new DateTimeImmutable();
    $monday = $monday->setISODate($jahr, $kw, 1);
    $friday = $monday->modify('+4 days');

    return ['montag' => $monday, 'freitag' => $friday];
}

function formatWeekRangeLabel(int $jahr, int $kw): string
{
    $range = getWeekRange($jahr, $kw);
    return $range['montag']->format('d.m.Y') . ' – ' . $range['freitag']->format('d.m.Y');
}

/**
 * Anzahl der ISO-Kalenderwochen eines Jahres (52 oder 53).
 * Der 28.12. liegt per ISO-8601 immer in der letzten Woche des Jahres.
 */
function isoWeeksInYear(int $jahr): int
{
    return (int) (new DateTimeImmutable())->setDate($jahr, 12, 28)->format('W');
}

/**
 * Prueft, ob eine KW im angegebenen Jahr tatsaechlich existiert.
 */
function isValidIsoWeek(int $jahr, int $kw): bool
{
    return $kw >= 1 && $kw <= isoWeeksInYear($jahr);
}

/**
 * Normalisiert die Stunden eines Tages abhaengig vom Typ.
 * Abwesenheitstypen (Urlaub, Krank, Feiertag) haben keine Arbeitsstunden,
 * Berufsschule wird ebenfalls nicht als Arbeitszeit gezaehlt.
 */
function normalizeTagesStunden(string $typ, float $stunden): float
{
    if (in_array($typ, ['urlaub', 'krank', 'feiertag', 'schule'], true)) {
        return 0.0;
    }

    if ($stunden < 0) {
        return 0.0;
    }
    if ($stunden > 24) {
        return 24.0;
    }

    return $stunden;
}

/**
 * Holt einen Wochenbericht für die angegebene Kombination.
 */
function findWochenbericht(PDO $pdo, string $userId, int $jahr, int $kw): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM wochenberichte WHERE user_id = :user_id AND jahr = :jahr AND kw = :kw');
    $stmt->execute([':user_id' => $userId, ':jahr' => $jahr, ':kw' => $kw]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function getPreviousIsoWeekKey(int $jahr, int $kw): array
{
    $previousMonday = getWeekRange($jahr, $kw)['montag']->modify('-7 days');

    return getIsoWeekFor($previousMonday);
}

function findPreviousWochenbericht(PDO $pdo, string $userId, int $jahr, int $kw): ?array
{
    $previous = getPreviousIsoWeekKey($jahr, $kw);

    return findWochenbericht($pdo, $userId, (int) $previous['jahr'], (int) $previous['kw']);
}

function normalizeAusbildungsjahr($value): int
{
    $jahr = (int) $value;
    if ($jahr < 1) {
        return 1;
    }
    if ($jahr > 10) {
        return 10;
    }

    return $jahr;
}

function defaultAusbildungsjahrForWeek(?array $aktuellerBericht, ?array $vorwochenBericht, int $jahr, int $kw): int
{
    if ($aktuellerBericht) {
        return normalizeAusbildungsjahr($aktuellerBericht['ausbildungsjahr'] ?? 1);
    }

    if (!$vorwochenBericht) {
        return 1;
    }

    $ausbildungsjahr = normalizeAusbildungsjahr($vorwochenBericht['ausbildungsjahr'] ?? 1);

    $currentRange = getWeekRange($jahr, $kw);
    $previousRange = getWeekRange((int) ($vorwochenBericht['jahr'] ?? $jahr), (int) ($vorwochenBericht['kw'] ?? $kw));
    $augustFirst = new DateTimeImmutable($currentRange['freitag']->format('Y') . '-08-01 00:00:00');

    // Wenn zwischen Vorwoche und dieser Woche der 01.08. erreicht wird,
    // beginnt automatisch das naechste Ausbildungsjahr.
    if ($previousRange['freitag'] < $augustFirst && $currentRange['freitag'] >= $augustFirst) {
        $ausbildungsjahr++;
    }

    return normalizeAusbildungsjahr($ausbildungsjahr);
}

function findWochenberichtById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM wochenberichte WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function listWochenberichte(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM wochenberichte WHERE user_id = :user_id ORDER BY jahr DESC, kw DESC');
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll();
}

function addHistorie(PDO $pdo, int $wochenberichtId, string $akteur, string $ereignis): void
{
    $stmt = $pdo->prepare('INSERT INTO historie (wochenbericht_id, zeitpunkt, akteur, ereignis)
        VALUES (:wochenbericht_id, :zeitpunkt, :akteur, :ereignis)');
    $stmt->execute([
        ':wochenbericht_id' => $wochenberichtId,
        ':zeitpunkt' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ':akteur' => $akteur,
        ':ereignis' => $ereignis,
    ]);
}

function getHistorieFuer(PDO $pdo, int $wochenberichtId): array
{
    $stmt = $pdo->prepare('SELECT * FROM historie WHERE wochenbericht_id = :id ORDER BY zeitpunkt DESC, id DESC');
    $stmt->execute([':id' => $wochenberichtId]);

    return $stmt->fetchAll();
}

function berichtContentSnapshot(array $bericht): array
{
    $snapshot = [
        'user_id' => normalizeUserId((string) ($bericht['user_id'] ?? '')),
        'nachname' => trim((string) ($bericht['nachname'] ?? '')),
        'vorname' => trim((string) ($bericht['vorname'] ?? '')),
        'jahr' => (int) ($bericht['jahr'] ?? 0),
        'kw' => (int) ($bericht['kw'] ?? 0),
        'ausbildungsjahr' => (int) ($bericht['ausbildungsjahr'] ?? 1),
        'wochen_thema' => trim((string) ($bericht['wochen_thema'] ?? '')),
    ];

    foreach (WOCHENTAGE as $tag) {
        $snapshot[$tag] = [
            'typ' => trim((string) ($bericht[$tag . '_typ'] ?? 'arbeit')),
            'stunden' => (float) ($bericht[$tag . '_stunden'] ?? 0),
            'taetigkeiten' => str_replace(["\r\n", "\r"], "\n", trim((string) ($bericht[$tag . '_taetigkeiten'] ?? ''))),
        ];
    }

    return $snapshot;
}

function calculateBerichtContentHash(array $bericht): string
{
    return hash('sha256', json_encode(berichtContentSnapshot($bericht), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function refreshBerichtContentHash(PDO $pdo, int $wochenberichtId): string
{
    $bericht = findWochenberichtById($pdo, $wochenberichtId);
    if (!$bericht) {
        throw new RuntimeException('Wochenbericht für Hash-Berechnung nicht gefunden.');
    }

    $hash = calculateBerichtContentHash($bericht);
    $stmt = $pdo->prepare('UPDATE wochenberichte SET content_hash = :content_hash WHERE id = :id');
    $stmt->execute([':content_hash' => $hash, ':id' => $wochenberichtId]);

    return $hash;
}

function getLatestAuditChainHash(PDO $pdo, int $wochenberichtId): string
{
    $stmt = $pdo->prepare('SELECT chain_hash FROM nachweis_audit_log WHERE bericht_id = :id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':id' => $wochenberichtId]);
    $hash = $stmt->fetchColumn();

    return is_string($hash) ? $hash : '';
}

function addNachweisAuditLog(
    PDO $pdo,
    int $wochenberichtId,
    string $action,
    string $actorUsername,
    string $actorRealname,
    string $actorRole,
    ?string $oldStatus,
    ?string $newStatus,
    string $comment = '',
    ?string $createdAt = null
): void {
    $createdAt = $createdAt ?: (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $actorUsername = normalizeUserId($actorUsername);
    $actorRealname = trim($actorRealname);
    if ($actorRealname === '' || normalizeUserId($actorRealname) === $actorUsername) {
        $actorRealname = 'Name nicht gefunden';
    }
    $contentHash = refreshBerichtContentHash($pdo, $wochenberichtId);
    $previousHash = getLatestAuditChainHash($pdo, $wochenberichtId);

    $chainPayload = [
        'bericht_id' => $wochenberichtId,
        'action' => $action,
        'actor_username' => $actorUsername,
        'actor_realname' => $actorRealname,
        'actor_role' => $actorRole,
        'created_at' => $createdAt,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'comment' => $comment,
        'content_hash' => $contentHash,
        'previous_hash' => $previousHash,
    ];
    $chainHash = hash('sha256', json_encode($chainPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $stmt = $pdo->prepare('INSERT INTO nachweis_audit_log (
        bericht_id, action, actor_username, actor_realname, actor_role, created_at,
        old_status, new_status, comment, content_hash, previous_hash, chain_hash
    ) VALUES (
        :bericht_id, :action, :actor_username, :actor_realname, :actor_role, :created_at,
        :old_status, :new_status, :comment, :content_hash, :previous_hash, :chain_hash
    )');
    $stmt->execute([
        ':bericht_id' => $wochenberichtId,
        ':action' => $action,
        ':actor_username' => $actorUsername,
        ':actor_realname' => $actorRealname,
        ':actor_role' => $actorRole,
        ':created_at' => $createdAt,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':comment' => $comment,
        ':content_hash' => $contentHash,
        ':previous_hash' => $previousHash,
        ':chain_hash' => $chainHash,
    ]);
}

function getNachweisAuditLog(PDO $pdo, int $wochenberichtId): array
{
    $stmt = $pdo->prepare('SELECT * FROM nachweis_audit_log WHERE bericht_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $wochenberichtId]);

    return $stmt->fetchAll();
}

function formatAuditActionLabel(string $action): string
{
    return match ($action) {
        'submitted' => 'Eingereicht',
        'approved_trainer' => '1. Genehmigung',
        'approved_leader' => '2. Genehmigung',
        'rejected' => 'Abgelehnt',
        'admin_unlock' => 'Admin-Entsperrung',
        default => $action,
    };
}

/**
 * Summe der erfassten Stunden eines Wochenberichts (alle Tage).
 */
function sumStunden(array $bericht): float
{
    $sum = 0.0;
    foreach (WOCHENTAGE as $tag) {
        $sum += (float) ($bericht[$tag . '_stunden'] ?? 0);
    }
    return $sum;
}

function tagTypLabel(string $typ): string
{
    return match ($typ) {
        'arbeit' => 'Arbeit',
        'schule' => 'Berufsschule',
        'urlaub' => 'Urlaub',
        'krank' => 'Krank',
        'feiertag' => 'Feiertag',
        default => $typ,
    };
}

function statusOptions(): array
{
    return [
        STATUS_ENTWURF,
        STATUS_EINGEREICHT,
        STATUS_AUSBILDER_GENEHMIGT,
        STATUS_FREIGEGEBEN,
        STATUS_ABGELEHNT,
    ];
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        STATUS_ENTWURF => 'status-entwurf',
        STATUS_EINGEREICHT => 'status-eingereicht',
        STATUS_AUSBILDER_GENEHMIGT => 'status-ausbilder-genehmigt',
        STATUS_FREIGEGEBEN => 'status-freigegeben',
        STATUS_ABGELEHNT => 'status-abgelehnt',
        default => 'status-entwurf',
    };
}

function isBerichtBearbeitungGesperrt(?array $bericht): bool
{
    if (!$bericht) {
        return false;
    }

    if ((int) ($bericht['is_locked'] ?? 0) === 1) {
        return true;
    }

    return in_array((string) $bericht['status'], [STATUS_EINGEREICHT, STATUS_AUSBILDER_GENEHMIGT, STATUS_FREIGEGEBEN], true);
}

function approvalGroupOptions(): array
{
    return [
        APPROVAL_GROUP_AZUBI => 'Azubi',
        APPROVAL_GROUP_AUSBILDER => 'Ausbilder',
        APPROVAL_GROUP_AUSBILDUNGSLEITER => 'Ausbildungsleiter',
        APPROVAL_GROUP_ADMIN => 'Admin',
    ];
}

function approvalGroupLabel(string $gruppe): string
{
    $options = approvalGroupOptions();
    return $options[$gruppe] ?? $gruppe;
}

function normalizeUserId(string $userId): string
{
    return strtoupper(trim($userId));
}

function isValidApprovalGroup(string $gruppe): bool
{
    return array_key_exists($gruppe, approvalGroupOptions());
}

function saveFreigabeGruppe(PDO $pdo, string $userId, string $anzeigename, string $gruppe, string $actor): void
{
    $userId = normalizeUserId($userId);
    $anzeigename = trim($anzeigename);
    $actor = normalizeUserId($actor);

    if ($userId === '') {
        throw new InvalidArgumentException('Bitte eine User-ID angeben.');
    }

    if (!isValidApprovalGroup($gruppe)) {
        throw new InvalidArgumentException('Ungültige Freigabegruppe.');
    }

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO freigabe_gruppen (user_id, anzeigename, gruppe, erstellt_am, erstellt_von)
        VALUES (:user_id, :anzeigename, :gruppe, :erstellt_am, :erstellt_von)
        ON CONFLICT(user_id, gruppe) DO UPDATE SET
            anzeigename = excluded.anzeigename,
            erstellt_am = excluded.erstellt_am,
            erstellt_von = excluded.erstellt_von');
    $stmt->execute([
        ':user_id' => $userId,
        ':anzeigename' => $anzeigename,
        ':gruppe' => $gruppe,
        ':erstellt_am' => $now,
        ':erstellt_von' => $actor,
    ]);
}

function deleteFreigabeGruppe(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM freigabe_gruppen WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function listFreigabeGruppen(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM freigabe_gruppen ORDER BY gruppe ASC, anzeigename COLLATE NOCASE ASC, user_id ASC');
    return $stmt ? $stmt->fetchAll() : [];
}

function listFreigabeGruppenByGroup(PDO $pdo, string $gruppe): array
{
    $stmt = $pdo->prepare('SELECT * FROM freigabe_gruppen WHERE gruppe = :gruppe ORDER BY anzeigename COLLATE NOCASE ASC, user_id ASC');
    $stmt->execute([':gruppe' => $gruppe]);
    return $stmt->fetchAll();
}

function getFreigabeGruppenForUser(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT gruppe FROM freigabe_gruppen WHERE user_id = :user_id ORDER BY gruppe ASC');
    $stmt->execute([':user_id' => normalizeUserId($userId)]);
    return array_map(static fn(array $row): string => (string) $row['gruppe'], $stmt->fetchAll());
}

function userHasFreigabeGruppe(PDO $pdo, string $userId, string $gruppe): bool
{
    return in_array($gruppe, getFreigabeGruppenForUser($pdo, $userId), true);
}
