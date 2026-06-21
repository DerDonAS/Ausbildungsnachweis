<?php
declare(strict_types=1);

require_once __DIR__ . '/export_pdf_lib.php';

$pdo = getDb();
$access = pdfLoadCurrentAccess($pdo);
$userId = (string) $access['userId'];

if ($userId === '') {
    http_response_code(403);
    die('Keine Benutzerkennung erkannt.');
}

try {
    $berichte = pdfListBerichteForZip($pdo, $access);
} catch (Throwable $e) {
    http_response_code(403);
    die($e->getMessage());
}

$entries = [];
$usedNames = [];

foreach ($berichte as $bericht) {
    if (!pdfCanExportBericht($bericht, $userId, (bool) $access['isLdapAdmin'], (bool) $access['hatAusbilderGruppe'], (bool) $access['hatLeiterGruppe'])) {
        continue;
    }

    try {
        $range = getWeekRange((int) $bericht['jahr'], (int) $bericht['kw']);
        $pdfBinary = createPdfBinary($bericht, pdfNameAzubi($bericht), $range, $pdo);
        $entryName = pdfUniqueZipEntryName($usedNames, pdfExportFilename($bericht));
        $entries[$entryName] = $pdfBinary;
    } catch (Throwable) {
        // Einzelne defekte Berichte überspringen, damit der Sammel-Export nicht komplett abbricht.
    }
}

if (!$entries) {
    http_response_code(404);
    die('Keine exportierbaren Wochenberichte für die aktuelle Auswahl gefunden.');
}

$zipBinary = createPdfZipBinary($entries);
$filename = pdfZipFilename($berichte);
$fallbackFilename = pdfFallbackFilename($filename);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . addcslashes($fallbackFilename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($zipBinary));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $zipBinary;
exit;
