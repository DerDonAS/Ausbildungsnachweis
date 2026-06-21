<?php
declare(strict_types=1);

/**
 * ldap.php
 * LDAP-Anbindung an das DirX-Verzeichnis des LBV NRW zur Vorbelegung
 * der Benutzerdaten (Windows-Anmeldung -> LDAP-Lookup) und zur
 * Bestimmung von Admin-/Ausbilder-Rechten.
 */

// ldaps:// erzwingt TLS (Port 636). Kein Fallback auf unverschluesseltes LDAP.
const LDAP_HOST = 'ldaps://dirxserv.lbv.nrw.de';
const LDAP_BASE_DN = 'ou=Landesamt für Besoldung und Versorgung,o=Landesverwaltung Nordrhein-Westfalen,c=de';

/**
 * Test-Override der Windows-Anmeldung NUR fuer die lokale Entwicklung.
 *
 * Setzt die Umgebungsvariable AUSBILDUNG_DEV_USER (z. B. in .env oder
 * der Server-Konfiguration). Auf dem Produktivserver NICHT setzen –
 * dann greift die echte Windows-Anmeldung via LOGON_USER.
 *
 * Es gibt keinen hardcodierten Fallback mehr, damit keine echten
 * Benutzerkennungen im Quellcode landen.
 */
function devTestUser(): string
{
    $value = getenv('AUSBILDUNG_DEV_USER');
    if ($value !== false) {
        return trim((string) $value);
    }

    return '';
}

/**
 * Statische Admin-Liste aus Umgebungsvariable AUSBILDUNG_ADMIN_USERS.
 * Kommagetrennte Benutzerkennungen, z. B.: GD7LBV5,ME8LBV5,SA7LBV5
 * Leer lassen (oder nicht setzen) wenn Admins ausschliesslich ueber
 * die DB-Gruppe 'admin' verwaltet werden sollen.
 */
function getStaticAdminUsers(): array
{
    $env = getenv('AUSBILDUNG_ADMIN_USERS');
    if ($env === false || trim($env) === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        fn(string $u) => strtoupper(trim($u)),
        explode(',', $env)
    )));
}


function getCurrentUser(): string
{
    $devUser = devTestUser();
    if ($devUser !== '') {
        return $devUser;
    }

    $user = $_SERVER['LOGON_USER'] ?? ($_SERVER['REMOTE_USER'] ?? ($_SERVER['AUTH_USER'] ?? ''));

    if (strpos($user, '\\') !== false) {
        $parts = explode('\\', $user);
        $user = end($parts);
    }

    if (strpos($user, '@') !== false) {
        $parts = explode('@', $user);
        $user = $parts[0];
    }

    return trim($user);
}

function ldapValueEscape(string $value): string
{
    if (function_exists('ldap_escape')) {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    return str_replace(
        ['\\', '*', '(', ')', "\x00"],
        ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
        $value
    );
}

/**
 * @return resource|\LDAP\Connection
 */
function ldapConnection()
{
    $connection = ldap_connect(LDAP_HOST);

    if (!$connection) {
        throw new RuntimeException('LDAP-Verbindung konnte nicht aufgebaut werden.');
    }

    ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

    return $connection;
}

function extractMultiValue(array $entry, string $key): array
{
    if (!isset($entry[$key])) {
        return [];
    }

    $result = [];
    $count = isset($entry[$key]['count']) ? (int) $entry[$key]['count'] : 0;

    for ($i = 0; $i < $count; $i++) {
        $value = trim((string) $entry[$key][$i]);
        if ($value !== '') {
            $result[] = strtoupper($value);
        }
    }

    return array_values(array_unique($result));
}

function normalizeLdapEntry(array $entry): array
{
    $roles = extractMultiValue($entry, 'hfunktion');

    return [
        'username' => $entry['lbvuserid'][0] ?? '',
        'firstname' => $entry['givenname'][0] ?? '',
        'lastname' => $entry['sn'][0] ?? '',
        'ou' => $entry['ou'][0] ?? '',
        'hfunktion' => $roles[0] ?? '',
        'roles' => $roles,
    ];
}

function getLdapUserByUsername(string $username): ?array
{
    if ($username === '') {
        return null;
    }

    $ldap = ldapConnection();
    $filter = '(LBVuserid=' . ldapValueEscape($username) . ')';
    $attributes = ['LBVuserid', 'givenname', 'sn', 'ou', 'hfunktion'];

    $search = ldap_search($ldap, LDAP_BASE_DN, $filter, $attributes);
    if ($search === false) {
        ldap_close($ldap);
        return null;
    }

    $entries = ldap_get_entries($ldap, $search);
    ldap_close($ldap);

    if (!isset($entries['count']) || (int) $entries['count'] < 1) {
        return null;
    }

    return normalizeLdapEntry($entries[0]);
}

function getLdapUsersByOu(string $ou): array
{
    if ($ou === '') {
        return [];
    }

    $ldap = ldapConnection();
    $filter = '(ou=' . ldapValueEscape($ou) . ')';
    $attributes = ['LBVuserid', 'givenname', 'sn', 'ou', 'hfunktion'];

    $search = ldap_search($ldap, LDAP_BASE_DN, $filter, $attributes);
    if ($search === false) {
        ldap_close($ldap);
        return [];
    }

    $entries = ldap_get_entries($ldap, $search);
    ldap_close($ldap);

    $users = [];

    if (!isset($entries['count'])) {
        return $users;
    }

    for ($i = 0; $i < (int) $entries['count']; $i++) {
        $normalized = normalizeLdapEntry($entries[$i]);

        if ($normalized['username'] === '') {
            continue;
        }

        $users[] = $normalized;
    }

    usort($users, function ($a, $b) {
        return strcasecmp(
            trim($a['lastname'] . ' ' . $a['firstname']),
            trim($b['lastname'] . ' ' . $b['firstname'])
        );
    });

    return $users;
}

function isAdminRole($role): bool
{
    $roles = is_array($role) ? $role : [trim((string) $role)];

    foreach ($roles as $singleRole) {
        $singleRole = strtoupper(trim((string) $singleRole));
        if (in_array($singleRole, ['RL', 'SRL', 'TL'], true)) {
            return true;
        }
    }

    return false;
}

function isAdminUser(string $username, $role): bool
{
    return in_array(strtoupper($username), getStaticAdminUsers(), true) || isAdminRole($role);
}

function getDisplayNameFromLdapUser(?array $user): string
{
    if (!$user) {
        return '';
    }

    $name = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
    return $name !== '' ? $name : ($user['username'] ?? '');
}

/**
 * Liefert ein vollständiges Profil für die aktuell angemeldete
 * Windows-Benutzerkennung inkl. Fallback-Meldungen, falls LDAP
 * nicht erreichbar ist oder kein Eintrag existiert.
 */
function getCurrentLdapProfile(): array
{
    $username = getCurrentUser();

    $fallback = [
        'found' => false,
        'username' => $username,
        'firstname' => '',
        'lastname' => '',
        'ou' => '',
        'roles' => [],
        'isAdmin' => false,
        'message' => '',
    ];

    if ($username === '') {
        $fallback['message'] = 'Die Windows-Benutzerkennung wurde nicht vom Webserver übergeben.';
        return $fallback;
    }

    if (!function_exists('ldap_connect')) {
        $fallback['message'] = 'Die PHP-LDAP-Erweiterung ist auf diesem System nicht aktiviert.';
        return $fallback;
    }

    try {
        $ldapUser = getLdapUserByUsername($username);

        if (!$ldapUser) {
            $fallback['message'] = 'Zur erkannten Benutzerkennung wurde kein LDAP-Eintrag gefunden.';
            return $fallback;
        }

        return [
            'found' => true,
            'username' => (string) ($ldapUser['username'] ?? $username),
            'firstname' => (string) ($ldapUser['firstname'] ?? ''),
            'lastname' => (string) ($ldapUser['lastname'] ?? ''),
            'ou' => (string) ($ldapUser['ou'] ?? ''),
            'roles' => $ldapUser['roles'] ?? [],
            'isAdmin' => isAdminUser($ldapUser['username'] ?? $username, $ldapUser['roles'] ?? []),
            'message' => '',
        ];
    } catch (Throwable $exception) {
        $fallback['message'] = 'LDAP-Abfrage konnte nicht durchgeführt werden: ' . $exception->getMessage();
        return $fallback;
    }
}
