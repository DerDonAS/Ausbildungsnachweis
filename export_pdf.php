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

$bericht = pdfGetBerichtFromRequest($pdo, $userId);
if (!$bericht) {
    http_response_code(404);
    die('Kein Wochenbericht für den PDF-Export gefunden. Bitte die Woche zuerst speichern.');
}

if (!pdfCanExportBericht($bericht, $userId, (bool) $access['isLdapAdmin'], (bool) $access['hatAusbilderGruppe'], (bool) $access['hatLeiterGruppe'])) {
    http_response_code(403);
    die('Kein Zugriff auf diesen PDF-Export.');
}

try {
    $range = getWeekRange((int) $bericht['jahr'], (int) $bericht['kw']);
    $pdfBinary = createPdfBinary($bericht, pdfNameAzubi($bericht), $range, $pdo);
} catch (Throwable $e) {
    error_log('PDF-Export fehlgeschlagen: ' . $e->getMessage());
    http_response_code(500);
    die('PDF-Export konnte nicht erstellt werden. Bitte später erneut versuchen oder den Administrator informieren.');
}

$filename = pdfExportFilename($bericht);
$fallbackFilename = pdfFallbackFilename($filename);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . addcslashes($fallbackFilename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($pdfBinary));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdfBinary;
exit;
