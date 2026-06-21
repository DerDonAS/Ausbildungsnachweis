<?php
declare(strict_types=1);

/**
 * helpers.php
 * Zentrale Hilfsfunktionen, die von index.php, admin.php und den
 * Export-Skripten gemeinsam genutzt werden:
 *   - HTML-Escaping
 *   - Session- und CSRF-Schutz
 *   - einheitliche Ermittlung der Zugriffsrechte des aktuellen Nutzers
 *
 * Damit entfällt der bisher in mehreren Dateien duplizierte Code.
 */

require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/db.php';

/**
 * HTML-Escaping für die Ausgabe. Frueher in index.php und admin.php
 * jeweils separat als e() definiert.
 */
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Startet bei Bedarf eine Session. Wird fuer den CSRF-Schutz benoetigt.
 * Cookie-Parameter sind defensiv gesetzt (HttpOnly, SameSite=Strict).
 */
function ensureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Nur setzen, solange noch keine Header gesendet wurden.
    if (!headers_sent()) {
        // Secure-Flag automatisch aktivieren wenn HTTPS erkannt wird;
        // lokale HTTP-Entwicklungsumgebungen funktionieren weiterhin.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => $isHttps,
        ]);
    }

    session_start();
}

/**
 * Liefert das CSRF-Token der aktuellen Session und erzeugt es bei
 * Bedarf neu.
 */
function csrfToken(): string
{
    ensureSession();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verstecktes Formularfeld mit dem aktuellen CSRF-Token.
 * Wird in jedes zustandsaendernde POST-Formular eingefuegt.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Prueft das mit einem POST-Request uebermittelte CSRF-Token.
 * Bei Fehler wird der Request mit HTTP 400 abgebrochen.
 */
function requireValidCsrfToken(): void
{
    ensureSession();

    $sent = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        http_response_code(400);
        die('Ungültiges oder fehlendes Sicherheits-Token (CSRF). Bitte die Seite neu laden und erneut versuchen.');
    }
}

/**
 * Ermittelt einheitlich die Zugriffsrechte des aktuell angemeldeten
 * Nutzers. Ersetzt die bisher in index.php, admin.php und
 * export_pdf_lib.php duplizierten Berechtigungsbloecke.
 *
 * Rueckgabe:
 *   ldapProfile         array   Rohprofil aus dem LDAP
 *   userId              string  normalisierte Benutzerkennung
 *   realname            string  Anzeigename (Fallback: "Name nicht gefunden")
 *   firstname/lastname  string
 *   isLdapAdmin         bool
 *   hatAzubiGruppe      bool
 *   hatAusbilderGruppe  bool
 *   hatLeiterGruppe     bool
 *   canApproveAsAusbilder bool
 *   canApproveAsLeiter  bool
 *   canManageGroups     bool
 *   hasAdminAccess      bool    (Ausbilder/Leiter/Admin)
 *   hasErfassungAccess  bool    (zusaetzlich freigeschaltete Azubis)
 */
function currentAccess(PDO $pdo): array
{
    $ldapProfile = getCurrentLdapProfile();
    $userId = normalizeUserId((string) $ldapProfile['username']);

    $firstname = (string) ($ldapProfile['firstname'] ?? '');
    $lastname = (string) ($ldapProfile['lastname'] ?? '');

    $realname = trim(getDisplayNameFromLdapUser($ldapProfile));
    if ($realname === '') {
        $realname = trim($firstname . ' ' . $lastname);
    }
    if ($realname === '') {
        $realname = 'Name nicht gefunden';
    }

    $gruppen = $userId !== '' ? getFreigabeGruppenForUser($pdo, $userId) : [];
    // Admin-Rechte stammen entweder aus der (statischen) LDAP-/Code-Liste
    // ODER aus der pflegbaren 'admin'-Gruppe in der Datenbank. Damit lassen
    // sich Admins ohne Code-Deployment verwalten.
    $isLdapAdmin = ($userId !== '' && isAdminUser($userId, $ldapProfile['roles'] ?? []))
        || in_array(APPROVAL_GROUP_ADMIN, $gruppen, true);
    $hatAzubiGruppe = in_array(APPROVAL_GROUP_AZUBI, $gruppen, true);
    $hatAusbilderGruppe = in_array(APPROVAL_GROUP_AUSBILDER, $gruppen, true);
    $hatLeiterGruppe = in_array(APPROVAL_GROUP_AUSBILDUNGSLEITER, $gruppen, true);

    return [
        'ldapProfile' => $ldapProfile,
        'userId' => $userId,
        'realname' => $realname,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'isLdapAdmin' => $isLdapAdmin,
        'hatAzubiGruppe' => $hatAzubiGruppe,
        'hatAusbilderGruppe' => $hatAusbilderGruppe,
        'hatLeiterGruppe' => $hatLeiterGruppe,
        'canApproveAsAusbilder' => $isLdapAdmin || $hatAusbilderGruppe,
        'canApproveAsLeiter' => $isLdapAdmin || $hatLeiterGruppe,
        'canManageGroups' => $isLdapAdmin,
        'hasAdminAccess' => $isLdapAdmin || $hatAusbilderGruppe || $hatLeiterGruppe,
        'hasErfassungAccess' => $isLdapAdmin || $hatAzubiGruppe || $hatAusbilderGruppe || $hatLeiterGruppe,
    ];
}
