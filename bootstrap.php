<?php
declare(strict_types=1);

/**
 * bootstrap.php
 * Wird als erstes von allen Einstiegspunkten (index.php, admin.php,
 * export_*.php) eingebunden. Konfiguriert PHP-Laufzeiteinstellungen,
 * die nicht per php.ini gesetzt werden koennen oder sollen.
 */

// Im Produktivbetrieb keine Fehler an den Browser ausgeben.
// Fehler werden ausschliesslich in das Server-Error-Log geschrieben.
$isDev = (getenv('AUSBILDUNG_ENV') === 'development');

ini_set('display_errors', $isDev ? '1' : '0');
ini_set('display_startup_errors', $isDev ? '1' : '0');
error_reporting(E_ALL);

// Zeitzone explizit setzen, damit date()-Funktionen konsistent arbeiten.
date_default_timezone_set('Europe/Berlin');
