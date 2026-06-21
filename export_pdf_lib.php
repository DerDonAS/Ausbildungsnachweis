<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';



const PDF_EXPORT_ABTEILUNG = '201';
const PDF_EXPORT_AUSBILDUNGSJAHR = '';

// Gleiches IHK-Logo wie im Word-Export.
const PDF_EXPORT_LOGO_BASE64 = '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';

function pdfDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Throwable) {
        return $value;
    }
}

function pdfTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('H:i');
    } catch (Throwable) {
        return '';
    }
}

function pdfHours(float $value): string
{
    if (abs($value) < 0.001) {
        return '';
    }
    if (abs($value - round($value)) < 0.001) {
        return (string) (int) round($value);
    }
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
}

function pdfFilenamePart(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~[\\/:*?"<>|]+~u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value) !== '' ? trim($value) : 'Unbekannt';
}

function pdfExportFilename(array $bericht): string
{
    return sprintf(
        '%04d-KW%02d %s %s.pdf',
        (int) ($bericht['jahr'] ?? 0),
        (int) ($bericht['kw'] ?? 0),
        pdfFilenamePart((string) ($bericht['vorname'] ?? '')),
        pdfFilenamePart((string) ($bericht['nachname'] ?? ''))
    );
}

function pdfZipFilename(array $berichte): string
{
    if (count($berichte) === 1) {
        $one = $berichte[0];
        return str_replace('.pdf', '.zip', pdfExportFilename($one));
    }

    return 'Ausbildungsnachweise_PDF_' . (new DateTimeImmutable('now'))->format('Y-m-d_H-i') . '.zip';
}

function pdfFallbackFilename(string $filename): string
{
    $fallback = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
    if ($fallback === false || trim($fallback) === '') {
        $fallback = 'Ausbildungsnachweis.pdf';
    }
    $fallback = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $fallback) ?? $fallback;
    return $fallback;
}

function pdfCanExportBericht(array $bericht, string $currentUserId, bool $isLdapAdmin, bool $hatAusbilderGruppe, bool $hatLeiterGruppe): bool
{
    if (normalizeUserId((string) ($bericht['user_id'] ?? '')) === normalizeUserId($currentUserId)) {
        return true;
    }

    return $isLdapAdmin || $hatAusbilderGruppe || $hatLeiterGruppe;
}

function pdfStoredPersonName(?PDO $pdo, ?string $storedValue, string $placeholderName): string
{
    $storedValue = trim((string) $storedValue);
    if ($storedValue === '') {
        return $placeholderName;
    }

    if (preg_match('/[\s,]/u', $storedValue)) {
        return $storedValue;
    }

    $normalizedUserId = normalizeUserId($storedValue);
    if ($normalizedUserId !== '' && $pdo instanceof PDO) {
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
            $anzeigename = trim((string) ($row['anzeigename'] ?? ''));
            if ($anzeigename !== '') {
                return $anzeigename;
            }
        } catch (Throwable) {
            // Danach LDAP versuchen.
        }
    }

    if ($normalizedUserId !== '' && function_exists('ldap_connect')) {
        try {
            $ldapUser = getLdapUserByUsername($normalizedUserId);
            $ldapName = trim(getDisplayNameFromLdapUser($ldapUser));
            if ($ldapName !== '' && normalizeUserId($ldapName) !== $normalizedUserId) {
                return $ldapName;
            }
        } catch (Throwable) {
            // Fallback unten.
        }
    }

    return $placeholderName;
}

function pdfElectronicText(?string $date, string $name, string $fallback, string $actionText): string
{
    $dateFormatted = pdfDate($date);
    $timeFormatted = pdfTime($date);
    $name = trim($name);
    $looksTechnical = $name !== '' && normalizeUserId($name) === $name && !str_contains($name, ' ');

    if ($dateFormatted !== '' && $name !== '' && $name !== $fallback && $name !== 'Name nicht gefunden' && !$looksTechnical) {
        $suffix = $timeFormatted !== '' ? ' um ' . $timeFormatted . ' Uhr' : '';
        return $actionText . ' via LDAP-Login durch ' . $name . ' am ' . $dateFormatted . $suffix . '.';
    }

    return $fallback;
}

function pdfWindowsText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace(["\u{2013}", "\u{2014}", "\u{00A0}"], ['-', '-', ' '], $text);
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    return $converted === false ? '' : $converted;
}

function pdfEscapeString(string $text): string
{
    $text = pdfWindowsText($text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdfNum(float $number): string
{
    return rtrim(rtrim(sprintf('%.3F', $number), '0'), '.');
}

function pdfApproxWidth(string $text, float $fontSize): float
{
    $win = pdfWindowsText($text);
    $width = 0.0;
    $length = strlen($win);
    for ($i = 0; $i < $length; $i++) {
        $char = $win[$i];
        if ($char === ' ') {
            $width += $fontSize * 0.28;
        } elseif (str_contains('il.,:;!|', $char)) {
            $width += $fontSize * 0.25;
        } elseif (str_contains('mwMW@#%', $char)) {
            $width += $fontSize * 0.78;
        } else {
            $width += $fontSize * 0.50;
        }
    }
    return $width;
}

function pdfWrapText(string $text, float $maxWidth, float $fontSize, int $maxLines = 0): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return [''];
    }

    $lines = [];
    foreach (explode("\n", $text) as $rawLine) {
        $words = preg_split('/\s+/u', trim($rawLine)) ?: [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (pdfApproxWidth($candidate, $fontSize) <= $maxWidth || $line === '') {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
            if ($maxLines > 0 && count($lines) >= $maxLines) {
                $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], '.') . '...';
                return $lines;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
            if ($maxLines > 0 && count($lines) >= $maxLines) {
                return array_slice($lines, 0, $maxLines);
            }
        }
    }

    return $maxLines > 0 ? array_slice($lines, 0, $maxLines) : $lines;
}

function pdfParsePngForPdf(string $png): array
{
    if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('Logo ist keine gültige PNG-Datei.');
    }

    $offset = 8;
    $width = 0;
    $height = 0;
    $bitDepth = 8;
    $colorType = 2;
    $idat = '';
    $length = strlen($png);

    while ($offset + 8 <= $length) {
        $chunkLength = unpack('N', substr($png, $offset, 4))[1];
        $type = substr($png, $offset + 4, 4);
        $data = substr($png, $offset + 8, $chunkLength);
        $offset += 12 + $chunkLength;

        if ($type === 'IHDR') {
            $width = unpack('N', substr($data, 0, 4))[1];
            $height = unpack('N', substr($data, 4, 4))[1];
            $bitDepth = ord($data[8]);
            $colorType = ord($data[9]);
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }

    $colors = match ($colorType) {
        0 => 1,
        2 => 3,
        default => throw new RuntimeException('Das PDF-Logo unterstützt nur PNG Graustufen oder RGB ohne Transparenz.'),
    };
    $colorSpace = $colorType === 0 ? '/DeviceGray' : '/DeviceRGB';

    if ($width <= 0 || $height <= 0 || $idat === '' || $bitDepth !== 8) {
        throw new RuntimeException('PNG-Logo konnte nicht für PDF gelesen werden.');
    }

    return [
        'width' => $width,
        'height' => $height,
        'bits' => $bitDepth,
        'colors' => $colors,
        'color_space' => $colorSpace,
        'idat' => $idat,
    ];
}

final class AusbildungsnachweisPdf
{
    private string $content = '';
    private ?array $logo = null;

    public function __construct(private float $width = 595.28, private float $height = 841.89)
    {
    }

    public function setLineWidth(float $width): void
    {
        $this->content .= pdfNum($width) . " w\n";
    }

    public function setStrokeColor(float $gray): void
    {
        $this->content .= pdfNum($gray) . " G\n";
    }

    public function setFillColor(float $gray): void
    {
        $this->content .= pdfNum($gray) . " g\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->content .= pdfNum($x1) . ' ' . pdfNum($y1) . ' m ' . pdfNum($x2) . ' ' . pdfNum($y2) . " l S\n";
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $this->content .= pdfNum($x) . ' ' . pdfNum($y) . ' ' . pdfNum($w) . ' ' . pdfNum($h) . ' re ' . ($fill ? 'f' : 'S') . "\n";
    }

    public function text(float $x, float $y, string $text, float $size = 9, string $font = 'F1'): void
    {
        $this->content .= 'BT /' . $font . ' ' . pdfNum($size) . ' Tf ' . pdfNum($x) . ' ' . pdfNum($y) . ' Td (' . pdfEscapeString($text) . ") Tj ET\n";
    }

    public function centeredText(float $x, float $y, float $w, string $text, float $size = 9, string $font = 'F1'): void
    {
        $textWidth = pdfApproxWidth($text, $size);
        $this->text($x + max(0, ($w - $textWidth) / 2), $y, $text, $size, $font);
    }

    public function wrappedText(float $x, float $yTop, float $w, string $text, float $size = 9, float $leading = 11, string $font = 'F1', int $maxLines = 0): float
    {
        $lines = pdfWrapText($text, $w, $size, $maxLines);
        $y = $yTop - $size;
        foreach ($lines as $line) {
            $this->text($x, $y, $line, $size, $font);
            $y -= $leading;
        }
        return count($lines) * $leading;
    }

    public function addLogoFromBase64(string $base64): void
    {
        $png = base64_decode($base64, true);
        if ($png === false || $png === '') {
            throw new RuntimeException('PDF-Logo konnte nicht geladen werden.');
        }
        $this->logo = pdfParsePngForPdf($png);
    }

    public function image(float $x, float $y, float $w, float $h): void
    {
        if (!$this->logo) {
            return;
        }
        $this->content .= 'q ' . pdfNum($w) . ' 0 0 ' . pdfNum($h) . ' ' . pdfNum($x) . ' ' . pdfNum($y) . " cm /ImLogo Do Q\n";
    }

    public function output(): string
    {
        $objects = [];
        $resources = '<< /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> /F2 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >> /F3 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >> >>';

        $logoObjectNumber = 0;
        if ($this->logo) {
            $logoObjectNumber = 5;
            $resources .= ' /XObject << /ImLogo ' . $logoObjectNumber . ' 0 R >>';
        }
        $resources .= ' >>';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . pdfNum($this->width) . ' ' . pdfNum($this->height) . '] /Resources ' . $resources . ' /Contents 4 0 R >>';
        $objects[4] = '<< /Length ' . strlen($this->content) . ">>\nstream\n" . $this->content . "endstream";

        if ($this->logo && $logoObjectNumber > 0) {
            $logo = $this->logo;
            $decodeParms = '<< /Predictor 15 /Colors ' . (int) $logo['colors'] . ' /BitsPerComponent ' . (int) $logo['bits'] . ' /Columns ' . (int) $logo['width'] . ' >>';
            $objects[$logoObjectNumber] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $logo['width'] . ' /Height ' . (int) $logo['height'] . ' /ColorSpace ' . $logo['color_space'] . ' /BitsPerComponent ' . (int) $logo['bits'] . ' /Filter /FlateDecode /DecodeParms ' . $decodeParms . ' /Length ' . strlen($logo['idat']) . ">>\nstream\n" . $logo['idat'] . "\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }
}

function pdfDrawTableGrid(AusbildungsnachweisPdf $pdf, float $x, float $top, array $colWidths, array $rowHeights, array $fillRows = []): void
{
    $totalW = array_sum($colWidths);
    $y = $top;
    foreach ($rowHeights as $rowIndex => $rowH) {
        if (in_array($rowIndex, $fillRows, true)) {
            $pdf->setFillColor(0.78);
            $pdf->rect($x, $y - $rowH, $totalW, $rowH, true);
            $pdf->setFillColor(0);
        }
        $y -= $rowH;
    }

    $pdf->setLineWidth(0.5);
    $pdf->setStrokeColor(0);
    $totalH = array_sum($rowHeights);
    $pdf->rect($x, $top - $totalH, $totalW, $totalH, false);

    $currentX = $x;
    foreach ($colWidths as $index => $w) {
        if ($index > 0) {
            $pdf->line($currentX, $top, $currentX, $top - $totalH);
        }
        $currentX += $w;
    }

    $currentY = $top;
    foreach ($rowHeights as $index => $h) {
        if ($index > 0) {
            $pdf->line($x, $currentY, $x + $totalW, $currentY);
        }
        $currentY -= $h;
    }
}

function pdfActivityText(array $bericht, string $tag): array
{
    $typ = (string) ($bericht[$tag . '_typ'] ?? 'arbeit');
    $taetigkeiten = trim((string) ($bericht[$tag . '_taetigkeiten'] ?? ''));
    $wochenThema = trim((string) ($bericht['wochen_thema'] ?? ''));

    if ($typ === 'schule') {
        return [['text' => 'Schule', 'font' => 'F1']];
    }
    if ($typ === 'urlaub') {
        return [['text' => 'Urlaub', 'font' => 'F1']];
    }
    if ($typ === 'krank') {
        return [['text' => 'Krank', 'font' => 'F1']];
    }
    if ($typ === 'feiertag') {
        return [['text' => 'Feiertag', 'font' => 'F1']];
    }

    $parts = [];
    if ($wochenThema !== '') {
        $parts[] = ['text' => $wochenThema, 'font' => 'F2'];
    }
    if ($taetigkeiten !== '') {
        $parts[] = ['text' => $taetigkeiten, 'font' => 'F1'];
    }

    return $parts ?: [['text' => 'Arbeit', 'font' => 'F1']];
}

function pdfDrawSignature(AusbildungsnachweisPdf $pdf, float $x, float $lineY, float $w, string $text, string $label, int $maxLines = 2): void
{
    $text = trim($text) !== '' ? $text : 'Datum, Name';
    $lines = pdfWrapText($text, $w, 7.1, $maxLines);
    $textTop = $lineY + 18 + max(0, count($lines) - 1) * 8;
    $y = $textTop;
    foreach ($lines as $line) {
        $pdf->text($x, $y, $line, 7.1, 'F1');
        $y -= 8;
    }
    $pdf->line($x, $lineY, $x + $w, $lineY);
    $pdf->wrappedText($x, $lineY - 2, $w, $label, 7.0, 8, 'F1', 2);
}

function createPdfBinary(array $bericht, string $nameAzubi, array $range, ?PDO $pdo = null): string
{
    $pdf = new AusbildungsnachweisPdf();
    $pdf->addLogoFromBase64(PDF_EXPORT_LOGO_BASE64);
    $pdf->setLineWidth(0.5);
    $pdf->setStrokeColor(0);

    // Kopfbereich
    $pdf->image(49, 777, 52, 24.5);
    $pdf->centeredText(0, 775, 595.28, 'Ausbildungsnachweis', 12.5, 'F2');

    // Stammdaten-Tabelle
    $x = 50;
    $metaTop = 733;
    $metaW = 495;
    $metaRows = [19, 35, 19];
    $metaCols = [185, 70, 95, 145];
    pdfDrawTableGrid($pdf, $x, $metaTop, $metaCols, $metaRows);
    $pdf->text($x + 4, $metaTop - 13, 'Name des/der Auszubildenden:', 7.2, 'F2');
    $pdf->text($x + $metaCols[0] + 4, $metaTop - 13, $nameAzubi, 7.2, 'F1');

    $row2Top = $metaTop - $metaRows[0];
    $pdf->text($x + 4, $row2Top - 17, 'Ausbildungsjahr:', 7.2, 'F1');
    $pdf->wrappedText($x + $metaCols[0] + $metaCols[1] + 4, $row2Top - 6, 88, 'Ggf. ausbildende Abteilung:', 7.0, 8, 'F1', 2);
    $pdf->text($x + $metaCols[0] + $metaCols[1] + $metaCols[2] + 4, $row2Top - 17, PDF_EXPORT_ABTEILUNG, 7.2, 'F1');
    $pdf->text($x + $metaCols[0] + 4, $row2Top - 17, (string) normalizeAusbildungsjahr($bericht['ausbildungsjahr'] ?? 1), 7.2, 'F1');

    $row3Top = $row2Top - $metaRows[1];
    $pdf->text($x + 4, $row3Top - 13, 'Ausbildungswoche vom:', 7.2, 'F1');
    $pdf->text($x + $metaCols[0] + 4, $row3Top - 13, $range['montag']->format('d.m.Y'), 7.2, 'F1');
    $pdf->text($x + $metaCols[0] + $metaCols[1] + 4, $row3Top - 13, 'bis:', 7.2, 'F1');
    $pdf->text($x + $metaCols[0] + $metaCols[1] + $metaCols[2] + 4, $row3Top - 13, $range['freitag']->format('d.m.Y'), 7.2, 'F1');

    // Haupttabelle
    $mainTop = 636;
    $mainCols = [87, 335, 73];
    $mainRows = [42, 63, 63, 63, 63, 63];
    pdfDrawTableGrid($pdf, $x, $mainTop, $mainCols, $mainRows, [0]);
    $pdf->wrappedText($x + $mainCols[0] + 4, $mainTop - 8, $mainCols[1] - 8, 'Betriebliche Tätigkeiten, Unterweisungen, betrieblicher Unterricht, sonstige Schulungen, Themen der Fachhochschulvorlesung', 7.0, 8, 'F1', 4);
    $pdf->text($x + $mainCols[0] + $mainCols[1] + 5, $mainTop - 26, 'Stunden', 7.3, 'F3');

    $rowTop = $mainTop - $mainRows[0];
    foreach (WOCHENTAGE as $index => $tag) {
        $rowH = $mainRows[$index + 1];
        $pdf->text($x + 5, $rowTop - 12, WOCHENTAG_LABELS[$tag], 7.7, 'F1');
        $activityY = $rowTop - 20;
        foreach (pdfActivityText($bericht, $tag) as $part) {
            $used = $pdf->wrappedText($x + $mainCols[0] + 5, $activityY, $mainCols[1] - 12, (string) $part['text'], 7.4, 8.5, (string) $part['font'], 5);
            $activityY -= $used;
        }
        $stunden = pdfHours((float) ($bericht[$tag . '_stunden'] ?? 0));
        $pdf->text($x + $mainCols[0] + $mainCols[1] + 6, $rowTop - ($rowH / 2) - 3, $stunden, 7.7, 'F1');
        $rowTop -= $rowH;
    }

    $noteY = $mainTop - array_sum($mainRows) - 13;
    $pdf->text($x, $noteY, 'Durch die nachfolgende elektronische Freigabe wird die Richtigkeit und Vollständigkeit der obigen Angaben bestätigt.', 6.8, 'F1');

    $azubiText = pdfElectronicText(
        $bericht['eingereicht_am'] ?? null,
        $nameAzubi,
        'Datum, Name Azubi',
        'Elektronisch eingereicht'
    );

    $ausbilderName = trim((string) ($bericht['ausbilder_genehmigt_realname'] ?? ''));
    if ($ausbilderName === '') {
        $ausbilderName = pdfStoredPersonName($pdo, $bericht['ausbilder_genehmigt_von'] ?? null, 'Name Ausbilder');
    }
    $leiterName = trim((string) ($bericht['ausbildungsleiter_genehmigt_realname'] ?? ''));
    if ($leiterName === '') {
        $leiterName = pdfStoredPersonName($pdo, $bericht['ausbildungsleiter_genehmigt_von'] ?? null, 'Name Ausbildungsleiter');
    }

    $ausbilderText = pdfElectronicText(
        $bericht['ausbilder_genehmigt_am'] ?? null,
        $ausbilderName,
        'Datum, Name Ausbilder',
        'Dieses Dokument wurde elektronisch freigegeben. Geprüft und autorisiert'
    );
    $leiterText = pdfElectronicText(
        $bericht['ausbildungsleiter_genehmigt_am'] ?? null,
        $leiterName,
        'Datum, Name Ausbildungsleiter',
        'Final elektronisch freigegeben. Geprüft und autorisiert'
    );

    pdfDrawSignature($pdf, 55, 174, 220, $azubiText, 'Datum, elektronische Bestätigung Auszubildende/r', 2);
    pdfDrawSignature($pdf, 305, 174, 235, $ausbilderText, "Datum, elektronische Freigabe Ausbildende/r\noder Ausbilder/in", 3);
    pdfDrawSignature($pdf, 305, 104, 235, $leiterText, "Datum, elektronische Freigabe\nAbschnittsbeauftragte/r", 3);

    $hash = trim((string) ($bericht['content_hash'] ?? ''));
    if ($hash !== '') {
        $docId = 'AN-' . (int) ($bericht['jahr'] ?? 0) . '-KW' . sprintf('%02d', (int) ($bericht['kw'] ?? 0)) . '-' . pdfFilenamePart((string) ($bericht['user_id'] ?? ''));
        $pdf->text(50, 34, 'Dokumenten-ID: ' . $docId . ' · Prüfhash: ' . substr($hash, 0, 32) . '…', 6.2, 'F1');
    }
    $pdf->text(50, 23, 'Exportiert am: ' . (new DateTimeImmutable('now'))->format('d.m.Y H:i') . ' Uhr', 6.2, 'F1');

    return $pdf->output();
}

function pdfNameAzubi(array $bericht): string
{
    $name = trim(trim((string) ($bericht['vorname'] ?? '')) . ' ' . trim((string) ($bericht['nachname'] ?? '')));
    return $name !== '' ? $name : (string) ($bericht['user_id'] ?? '');
}

function pdfLoadCurrentAccess(PDO $pdo): array
{
    // Nutzt den zentralen Helfer und behaelt die bisherigen Schluessel
    // fuer Abwaertskompatibilitaet bei.
    $access = currentAccess($pdo);

    return [
        'ldapProfile' => $access['ldapProfile'],
        'userId' => $access['userId'],
        'isLdapAdmin' => $access['isLdapAdmin'],
        'hatAusbilderGruppe' => $access['hatAusbilderGruppe'],
        'hatLeiterGruppe' => $access['hatLeiterGruppe'],
    ];
}

function pdfGetBerichtFromRequest(PDO $pdo, string $userId): ?array
{
    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $bericht = findWochenberichtById($pdo, (int) $_GET['id']);

        // Sicherheit: Ein fremder Bericht darf nur geladen werden, wenn der
        // aktuelle Nutzer dafuer berechtigt ist (Ausbilder/Leiter/Admin oder
        // Eigentuemer). Andernfalls so behandeln, als gaebe es ihn nicht,
        // damit reine Azubis nicht ueber fremde IDs exportieren koennen.
        if ($bericht === null) {
            return null;
        }

        $access = pdfLoadCurrentAccess($pdo);
        if (!pdfCanExportBericht(
            $bericht,
            $userId,
            (bool) $access['isLdapAdmin'],
            (bool) $access['hatAusbilderGruppe'],
            (bool) $access['hatLeiterGruppe']
        )) {
            return null;
        }

        return $bericht;
    }

    $current = getCurrentIsoWeek();
    $jahr = isset($_GET['jahr']) ? (int) $_GET['jahr'] : (int) $current['jahr'];
    $kw = isset($_GET['kw']) ? (int) $_GET['kw'] : (int) $current['kw'];
    return findWochenbericht($pdo, $userId, $jahr, $kw);
}

function pdfNormalizeSearchQuery(string $search): string
{
    $search = trim($search);
    $search = preg_replace('/\s+/u', ' ', $search) ?? $search;
    return substr($search, 0, 120);
}

function pdfSplitSearchTerms(string $search): array
{
    $search = pdfNormalizeSearchQuery($search);
    if ($search === '') {
        return [];
    }

    $search = preg_replace('/([Kk][Ww])\s*(\d{1,2})/u', ' $2 ', $search) ?? $search;
    $search = str_replace(['/', ';', ',', '|'], ' ', $search);
    $parts = preg_split('/\s+/u', $search) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
}

function pdfApplyComboSearchFilter(string &$sql, array &$params, string $search): void
{
    $terms = pdfSplitSearchTerms($search);

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

function pdfListBerichteForZip(PDO $pdo, array $access): array
{
    $scope = (string) ($_GET['scope'] ?? 'mine');
    $params = [];

    if ($scope === 'admin') {
        if (!$access['isLdapAdmin'] && !$access['hatAusbilderGruppe'] && !$access['hatLeiterGruppe']) {
            throw new RuntimeException('Kein Zugriff auf den Admin-ZIP-Export.');
        }

        $sql = 'SELECT * FROM wochenberichte WHERE 1=1';
        $filterUserId = normalizeUserId((string) ($_GET['user_id'] ?? ''));
        $filterStatus = trim((string) ($_GET['status'] ?? ''));
        $filterSearch = pdfNormalizeSearchQuery((string) ($_GET['q'] ?? ''));
        $filterJahr = isset($_GET['jahr']) && $_GET['jahr'] !== '' ? (int) $_GET['jahr'] : null;
        $filterKw = isset($_GET['kw']) && $_GET['kw'] !== '' ? (int) $_GET['kw'] : null;

        if ($filterUserId !== '') {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $filterUserId;
        }
        if ($filterStatus !== '' && in_array($filterStatus, statusOptions(), true)) {
            $sql .= ' AND status = :status';
            $params[':status'] = $filterStatus;
        }
        if ($filterJahr !== null && $filterJahr >= 2000 && $filterJahr <= 2100) {
            $sql .= ' AND jahr = :jahr';
            $params[':jahr'] = $filterJahr;
        }
        if ($filterKw !== null && $filterKw >= 1 && $filterKw <= 53) {
            $sql .= ' AND kw = :kw';
            $params[':kw'] = $filterKw;
        }
        pdfApplyComboSearchFilter($sql, $params, $filterSearch);
        $sql .= ' ORDER BY nachname COLLATE NOCASE ASC, vorname COLLATE NOCASE ASC, user_id ASC, jahr ASC, kw ASC';
    } else {
        $sql = 'SELECT * FROM wochenberichte WHERE user_id = :user_id ORDER BY jahr ASC, kw ASC';
        $params[':user_id'] = normalizeUserId((string) $access['userId']);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pdfUniqueZipEntryName(array &$used, string $name): string
{
    $name = str_replace('\\', '/', $name);
    $base = preg_replace('/\.pdf$/i', '', $name) ?? $name;
    $candidate = $base . '.pdf';
    $counter = 2;
    while (isset($used[strtolower($candidate)])) {
        $candidate = $base . ' (' . $counter . ').pdf';
        $counter++;
    }
    $used[strtolower($candidate)] = true;
    return $candidate;
}

function pdfZipDosDateTime(): array
{
    $parts = getdate();
    $year = max(1980, (int) $parts['year']);
    $dosTime = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | (int) floor((int) $parts['seconds'] / 2);
    $dosDate = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];
    return [$dosTime, $dosDate];
}

function pdfUnsignedCrc32(string $data): int
{
    $crc = crc32($data);
    return $crc < 0 ? $crc + 4294967296 : $crc;
}

function createPdfZipBinary(array $entries): string
{
    [$dosTime, $dosDate] = pdfZipDosDateTime();
    $localData = '';
    $centralData = '';
    $offset = 0;

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string) $name);
        $data = (string) $data;
        $nameLength = strlen($name);
        $size = strlen($data);
        $crc = pdfUnsignedCrc32($data);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        ) . $name;

        $localData .= $localHeader . $data;

        $centralData .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $size;
    }

    $entryCount = count($entries);
    $centralOffset = strlen($localData);
    $centralSize = strlen($centralData);

    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, $centralSize, $centralOffset, 0);
    return $localData . $centralData . $end;
}
