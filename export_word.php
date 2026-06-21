<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';



const WORD_EXPORT_ABTEILUNG = '201';
const WORD_EXPORT_AUSBILDUNGSJAHR = '';
const WORD_EXPORT_LOGO_BASE64 = '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';

function xmlText(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wordDate(?string $value): string
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

function wordTime(?string $value): string
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

function wordElectronicText(?string $date, string $name, string $fallback, string $actionText): string
{
    $dateFormatted = wordDate($date);
    $timeFormatted = wordTime($date);
    $name = trim($name);

    $looksTechnical = $name !== '' && normalizeUserId($name) === $name && !str_contains($name, ' ');
    if ($dateFormatted !== '' && $name !== '' && $name !== $fallback && $name !== 'Name nicht gefunden' && !$looksTechnical) {
        $suffix = $timeFormatted !== '' ? ' um ' . $timeFormatted . ' Uhr' : '';
        return $actionText . ' via LDAP-Login durch ' . $name . ' am ' . $dateFormatted . $suffix . '.';
    }

    return $fallback;
}

function wordHours(float $value): string
{
    if (abs($value) < 0.001) {
        return '';
    }

    if (abs($value - round($value)) < 0.001) {
        return (string) (int) round($value);
    }

    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
}

function wordFilenamePart(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~[\\\/:*?"<>|]+~u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value) !== '' ? trim($value) : 'Unbekannt';
}

function wordExportFilename(array $bericht): string
{
    $jahr = (int) ($bericht['jahr'] ?? 0);
    $kw = (int) ($bericht['kw'] ?? 0);
    $vorname = wordFilenamePart((string) ($bericht['vorname'] ?? ''));
    $nachname = wordFilenamePart((string) ($bericht['nachname'] ?? ''));

    return sprintf('%04d-KW%02d %s %s.docx', $jahr, $kw, $vorname, $nachname);
}

function wordFallbackFilename(string $filename): string
{
    $fallback = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
    if ($fallback === false || trim($fallback) === '') {
        $fallback = 'Ausbildungsnachweis.docx';
    }
    $fallback = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $fallback) ?? $fallback;
    return $fallback;
}

function wordSignatureName(?string $date, ?string $name, string $placeholder): string
{
    $date = wordDate($date);
    $name = trim((string) $name);

    if ($date !== '' && $name !== '') {
        return $date . ', ' . $name;
    }
    if ($date !== '') {
        return $date;
    }
    if ($name !== '') {
        return $name;
    }
    return $placeholder;
}


function wordStoredPersonName(?PDO $pdo, ?string $storedValue, string $placeholderName): string
{
    $storedValue = trim((string) $storedValue);
    if ($storedValue === '') {
        return $placeholderName;
    }

    // Wenn bereits ein Klarname gespeichert wurde, diesen unverändert verwenden.
    // Nutzerkennungen wie GI7LBV5 enthalten normalerweise keine Leerzeichen/Kommas.
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
            // Fallback unten: LDAP versuchen, sonst Platzhalter verwenden.
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
            // Wenn LDAP nicht erreichbar ist, niemals die Nutzerkennung in den Export schreiben.
        }
    }

    // Wichtig: Im Word-Export soll niemals die technische Nutzerkennung stehen.
    return $placeholderName;
}

function canExportBericht(array $bericht, string $currentUserId, bool $isLdapAdmin, bool $hatAusbilderGruppe, bool $hatLeiterGruppe): bool
{
    if (normalizeUserId((string) ($bericht['user_id'] ?? '')) === normalizeUserId($currentUserId)) {
        return true;
    }

    return $isLdapAdmin || $hatAusbilderGruppe || $hatLeiterGruppe;
}

function docxRun(string $text, bool $bold = false, bool $italic = false, int $size = 20): string
{
    $parts = preg_split('/\R/u', $text);
    if (!$parts) {
        $parts = [''];
    }

    $runPr = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
        . ($bold ? '<w:b/>' : '')
        . ($italic ? '<w:i/>' : '')
        . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';

    $xml = '';
    foreach ($parts as $index => $part) {
        if ($index > 0) {
            $xml .= '<w:r><w:br/></w:r>';
        }
        $xml .= '<w:r><w:rPr>' . $runPr . '</w:rPr><w:t xml:space="preserve">' . xmlText($part) . '</w:t></w:r>';
    }

    return $xml;
}

function docxParagraphFromRuns(array $runs, array $options = []): string
{
    $align = isset($options['align']) ? '<w:jc w:val="' . xmlText((string) $options['align']) . '"/>' : '';
    $after = (int) ($options['after'] ?? 0);
    $before = (int) ($options['before'] ?? 0);
    $line = isset($options['line']) ? ' w:line="' . (int) $options['line'] . '" w:lineRule="auto"' : '';
    $border = !empty($options['bottomBorder'])
        ? '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="000000"/></w:pBdr>'
        : '';

    return '<w:p><w:pPr>' . $align . '<w:spacing w:before="' . $before . '" w:after="' . $after . '"' . $line . '/>' . $border . '</w:pPr>' . implode('', $runs) . '</w:p>';
}

function docxParagraph(string $text = '', array $options = []): string
{
    $runs = [docxRun(
        $text,
        (bool) ($options['bold'] ?? false),
        (bool) ($options['italic'] ?? false),
        (int) ($options['size'] ?? 20)
    )];

    return docxParagraphFromRuns($runs, $options);
}

function docxCell(string $content, int $width, array $options = []): string
{
    $gridspan = (int) ($options['gridspan'] ?? 1);
    $valign = (string) ($options['valign'] ?? 'top');
    $shading = (string) ($options['shading'] ?? '');
    $noBorders = !empty($options['noBorders']);

    $tcPr = '<w:tcW w:w="' . $width . '" w:type="dxa"/>';
    if ($gridspan > 1) {
        $tcPr .= '<w:gridSpan w:val="' . $gridspan . '"/>';
    }
    if ($valign !== '') {
        $tcPr .= '<w:vAlign w:val="' . xmlText($valign) . '"/>';
    }
    if ($shading !== '') {
        $tcPr .= '<w:shd w:fill="' . xmlText($shading) . '"/>';
    }
    if ($noBorders) {
        $tcPr .= '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>';
    }

    return '<w:tc><w:tcPr>' . $tcPr . '</w:tcPr>' . $content . '</w:tc>';
}

function docxRow(array $cells, int $height = 0, bool $exact = false): string
{
    $trPr = '';
    if ($height > 0) {
        $trPr = '<w:trPr><w:trHeight w:val="' . $height . '" w:hRule="' . ($exact ? 'exact' : 'atLeast') . '"/></w:trPr>';
    }

    return '<w:tr>' . $trPr . implode('', $cells) . '</w:tr>';
}

function docxTable(array $rows, array $widths, ?int $width = null, bool $borders = true): string
{
    $width = $width ?? array_sum($widths);
    $borderXml = $borders
        ? '<w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:left w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:right w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/></w:tblBorders>'
        : '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';

    $grid = '';
    foreach ($widths as $colWidth) {
        $grid .= '<w:gridCol w:w="' . (int) $colWidth . '"/>';
    }

    return '<w:tbl><w:tblPr><w:tblW w:w="' . $width . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>' . $borderXml . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>' . implode('', $rows) . '</w:tbl>';
}

function docxImageParagraph(): string
{
    $cx = 1116000; // ca. 31 mm
    $cy = 526300;  // ca. 14,6 mm

    return '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="1" name="IHK Logo"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="ihk-logo.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
}

function docxActivityContent(array $bericht, string $tag): string
{
    $typ = (string) ($bericht[$tag . '_typ'] ?? 'arbeit');
    $taetigkeiten = trim((string) ($bericht[$tag . '_taetigkeiten'] ?? ''));
    $wochenThema = trim((string) ($bericht['wochen_thema'] ?? ''));

    if ($typ === 'schule') {
        return docxParagraph('Schule', ['size' => 20]);
    }
    if ($typ === 'urlaub') {
        return docxParagraph('Urlaub', ['size' => 20]);
    }
    if ($typ === 'krank') {
        return docxParagraph('Krank', ['size' => 20]);
    }
    if ($typ === 'feiertag') {
        return docxParagraph('Feiertag', ['size' => 20]);
    }

    $content = '';
    if ($wochenThema !== '') {
        $content .= docxParagraph($wochenThema, ['size' => 20, 'bold' => true, 'after' => 0]);
    }
    if ($taetigkeiten !== '') {
        $content .= docxParagraph($taetigkeiten, ['size' => 20, 'line' => 220]);
    }

    return $content !== '' ? $content : docxParagraph('Arbeit', ['size' => 20]);
}

function docxSignatureLine(string $text, int $before = 0): string
{
    return docxParagraph($text !== '' ? $text : "\u{00A0}", [
        'size' => 18,
        'line' => 240,
        'before' => $before,
        'after' => 30,
        'bottomBorder' => true,
    ]);
}

function buildDocxDocumentXml(array $bericht, string $nameAzubi, array $range, ?PDO $pdo = null): string
{
    $pageW = 11906;
    $pageH = 16838;
    $marginL = 1270;
    $marginR = 1270;
    $marginT = 690;
    $marginB = 650;
    $contentW = $pageW - $marginL - $marginR;

    $header = docxTable([
        docxRow([
            docxCell(docxImageParagraph(), 2100, ['noBorders' => true, 'valign' => 'center']),
            docxCell(docxParagraph('Ausbildungsnachweis', ['bold' => true, 'size' => 30, 'align' => 'center']), 5166, ['noBorders' => true, 'valign' => 'center']),
            docxCell(docxParagraph('', ['size' => 20]), 2100, ['noBorders' => true, 'valign' => 'center']),
        ], 900, true),
    ], [2100, 5166, 2100], $contentW, false);

    $metaWidths = [3300, 2200, 1700, 2166];
    $meta = docxTable([
        docxRow([
            docxCell(docxParagraph('Name des/der Auszubildenden:', ['size' => 18]), $metaWidths[0], ['valign' => 'center']),
            docxCell(docxParagraph($nameAzubi, ['size' => 18]), array_sum(array_slice($metaWidths, 1)), ['gridspan' => 3, 'valign' => 'center']),
        ], 300, true),
        docxRow([
            docxCell(docxParagraph('Ausbildungsjahr:', ['size' => 18]), $metaWidths[0], ['valign' => 'center']),
            docxCell(docxParagraph((string) normalizeAusbildungsjahr($bericht['ausbildungsjahr'] ?? 1), ['size' => 18]), $metaWidths[1], ['valign' => 'center']),
            docxCell(docxParagraph("Ggf. ausbildende\nAbteilung:", ['size' => 18, 'line' => 190]), $metaWidths[2], ['valign' => 'center']),
            docxCell(docxParagraph(WORD_EXPORT_ABTEILUNG, ['size' => 18]), $metaWidths[3], ['valign' => 'center']),
        ], 740, true),
        docxRow([
            docxCell(docxParagraph('Ausbildungswoche vom:', ['size' => 18]), $metaWidths[0], ['valign' => 'center']),
            docxCell(docxParagraph($range['montag']->format('d.m.Y'), ['size' => 18]), $metaWidths[1], ['valign' => 'center']),
            docxCell(docxParagraph('bis:', ['size' => 18]), $metaWidths[2], ['valign' => 'center']),
            docxCell(docxParagraph($range['freitag']->format('d.m.Y'), ['size' => 18]), $metaWidths[3], ['valign' => 'center']),
        ], 300, true),
    ], $metaWidths, $contentW, true);

    $mainWidths = [1700, 6400, 1266];
    $mainRows = [
        docxRow([
            docxCell(docxParagraph('', ['size' => 18]), $mainWidths[0], ['shading' => 'BFBFBF']),
            docxCell(docxParagraph("Betriebliche Tätigkeiten, Unterweisungen, betrieblicher Unterricht,\nsonstige Schulungen, Themen der Fachhochschulvorlesung", ['size' => 17, 'line' => 190]), $mainWidths[1], ['shading' => 'BFBFBF']),
            docxCell(docxParagraph('Stunden', ['size' => 18, 'italic' => true]), $mainWidths[2], ['shading' => 'BFBFBF', 'valign' => 'center']),
        ], 780, true),
    ];

    foreach (WOCHENTAGE as $tag) {
        $mainRows[] = docxRow([
            docxCell(docxParagraph(WOCHENTAG_LABELS[$tag], ['size' => 18]), $mainWidths[0], ['valign' => 'top']),
            docxCell(docxActivityContent($bericht, $tag), $mainWidths[1], ['valign' => 'center']),
            docxCell(docxParagraph(wordHours((float) ($bericht[$tag . '_stunden'] ?? 0)), ['size' => 18]), $mainWidths[2], ['valign' => 'center']),
        ], 1410, false);
    }

    $main = docxTable($mainRows, $mainWidths, $contentW, true);

    $azubiText = wordElectronicText(
        $bericht['eingereicht_am'] ?? null,
        $nameAzubi,
        'Datum, Name Azubi',
        'Elektronisch eingereicht'
    );
    $ausbilderName = trim((string) ($bericht['ausbilder_genehmigt_realname'] ?? ''));
    if ($ausbilderName === '') {
        $ausbilderName = wordStoredPersonName($pdo, $bericht['ausbilder_genehmigt_von'] ?? null, 'Name Ausbilder');
    }
    $leiterName = trim((string) ($bericht['ausbildungsleiter_genehmigt_realname'] ?? ''));
    if ($leiterName === '') {
        $leiterName = wordStoredPersonName($pdo, $bericht['ausbildungsleiter_genehmigt_von'] ?? null, 'Name Ausbildungsleiter');
    }

    $ausbilderText = wordElectronicText(
        $bericht['ausbilder_genehmigt_am'] ?? null,
        $ausbilderName,
        'Datum, Name Ausbilder',
        'Dieses Dokument wurde elektronisch freigegeben. Geprüft und autorisiert'
    );
    $leiterText = wordElectronicText(
        $bericht['ausbildungsleiter_genehmigt_am'] ?? null,
        $leiterName,
        'Datum, Name Ausbildungsleiter',
        'Final elektronisch freigegeben. Geprüft und autorisiert'
    );

    $leftSignature = docxSignatureLine($azubiText)
        . docxParagraph('Datum, elektronische Bestätigung Auszubildende/r', ['size' => 16, 'line' => 190]);

    $rightSignature = docxSignatureLine($ausbilderText)
        . docxParagraph("Datum, elektronische Freigabe Ausbildende/r\noder Ausbilder/in", ['size' => 16, 'line' => 180])
        . docxSignatureLine($leiterText, 280)
        . docxParagraph("Datum, elektronische Freigabe\nAbschnittsbeauftragte/r", ['size' => 16, 'line' => 180]);

    $signatures = docxTable([
        docxRow([
            docxCell($leftSignature, 4300, ['noBorders' => true, 'valign' => 'top']),
            docxCell(docxParagraph('', ['size' => 18]), 700, ['noBorders' => true, 'valign' => 'top']),
            docxCell($rightSignature, 4366, ['noBorders' => true, 'valign' => 'top']),
        ]),
    ], [4300, 700, 4366], $contentW, false);

    $body = $header
        . docxParagraph('', ['size' => 8, 'after' => 170])
        . $meta
        . docxParagraph('', ['size' => 8, 'after' => 510])
        . $main
        . docxParagraph('Durch die nachfolgende elektronische Freigabe wird die Richtigkeit und Vollständigkeit der obigen Angaben bestätigt.', ['size' => 17, 'after' => 230])
        . $signatures;

    $hash = trim((string) ($bericht['content_hash'] ?? ''));
    if ($hash !== '') {
        $body .= docxParagraph('Dokumenten-ID: AN-' . (int) ($bericht['jahr'] ?? 0) . '-KW' . sprintf('%02d', (int) ($bericht['kw'] ?? 0)) . '-' . wordFilenamePart((string) ($bericht['user_id'] ?? '')) . ' · Prüfhash: ' . substr($hash, 0, 24) . '…', ['size' => 13, 'after' => 0]);
    }

    $sectPr = '<w:sectPr><w:pgSz w:w="' . $pageW . '" w:h="' . $pageH . '"/><w:pgMar w:top="' . $marginT . '" w:right="' . $marginR . '" w:bottom="' . $marginB . '" w:left="' . $marginL . '" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
        . $body
        . $sectPr
        . '</w:body></w:document>';
}

function zipDosDateTime(): array
{
    $parts = getdate();
    $year = max(1980, (int) $parts['year']);
    $dosTime = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | ((int) floor((int) $parts['seconds'] / 2));
    $dosDate = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];
    return [$dosTime, $dosDate];
}

function zipUnsignedCrc32(string $data): int
{
    $crc = crc32($data);
    if ($crc < 0) {
        $crc += 4294967296;
    }
    return $crc;
}

function createZipBinary(array $entries): string
{
    [$dosTime, $dosDate] = zipDosDateTime();
    $localData = '';
    $centralData = '';
    $offset = 0;

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string) $name);
        $data = (string) $data;
        $nameLength = strlen($name);
        $size = strlen($data);
        $crc = zipUnsignedCrc32($data);

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

    $endOfCentralDirectory = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entryCount,
        $entryCount,
        $centralSize,
        $centralOffset,
        0
    );

    return $localData . $centralData . $endOfCentralDirectory;
}

function createDocxFile(array $bericht, string $nameAzubi, array $range, ?PDO $pdo = null): string
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'nachweis_');
    if ($tmpFile === false) {
        throw new RuntimeException('Temporäre Exportdatei konnte nicht erstellt werden.');
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/></Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/ihk-logo.png"/></Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:zoom w:percent="100"/></w:settings>';
    $logo = base64_decode(WORD_EXPORT_LOGO_BASE64, true);
    if ($logo === false || $logo === '') {
        throw new RuntimeException('IHK-Logo konnte nicht geladen werden.');
    }

    $zipBinary = createZipBinary([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'word/_rels/document.xml.rels' => $documentRels,
        'word/document.xml' => buildDocxDocumentXml($bericht, $nameAzubi, $range, $pdo),
        'word/styles.xml' => $styles,
        'word/settings.xml' => $settings,
        'word/media/ihk-logo.png' => $logo,
    ]);

    if (file_put_contents($tmpFile, $zipBinary) === false) {
        @unlink($tmpFile);
        throw new RuntimeException('DOCX-Datei konnte nicht geschrieben werden.');
    }

    return $tmpFile;
}

$pdo = getDb();
$access = currentAccess($pdo);
$userId = $access['userId'];

if ($userId === '') {
    http_response_code(403);
    die('Keine Benutzerkennung erkannt.');
}

$isLdapAdmin = $access['isLdapAdmin'];
$hatAusbilderGruppe = $access['hatAusbilderGruppe'];
$hatLeiterGruppe = $access['hatLeiterGruppe'];

$bericht = null;
if (isset($_GET['id']) && $_GET['id'] !== '') {
    $kandidat = findWochenberichtById($pdo, (int) $_GET['id']);
    // Fremde Berichte nur laden, wenn der Nutzer dafuer berechtigt ist.
    if ($kandidat !== null
        && canExportBericht($kandidat, $userId, $isLdapAdmin, $hatAusbilderGruppe, $hatLeiterGruppe)) {
        $bericht = $kandidat;
    }
} else {
    $current = getCurrentIsoWeek();
    $jahr = isset($_GET['jahr']) ? (int) $_GET['jahr'] : (int) $current['jahr'];
    $kw = isset($_GET['kw']) ? (int) $_GET['kw'] : (int) $current['kw'];
    $bericht = findWochenbericht($pdo, $userId, $jahr, $kw);
}

if (!$bericht) {
    http_response_code(404);
    die('Kein Wochenbericht für den Export gefunden. Bitte die Woche zuerst speichern.');
}

if (!canExportBericht($bericht, $userId, $isLdapAdmin, $hatAusbilderGruppe, $hatLeiterGruppe)) {
    http_response_code(403);
    die('Kein Zugriff auf diesen Word-Export.');
}

$range = getWeekRange((int) $bericht['jahr'], (int) $bericht['kw']);
$nameAzubi = trim(trim((string) ($bericht['vorname'] ?? '')) . ' ' . trim((string) ($bericht['nachname'] ?? '')));
if ($nameAzubi === '') {
    $nameAzubi = (string) ($bericht['user_id'] ?? '');
}

$filename = wordExportFilename($bericht);
$fallbackFilename = wordFallbackFilename($filename);

try {
    $tmpDocx = createDocxFile($bericht, $nameAzubi, $range, $pdo);
} catch (Throwable $e) {
    error_log('Word-Export fehlgeschlagen: ' . $e->getMessage());
    http_response_code(500);
    die('Word-Export konnte nicht erstellt werden. Bitte später erneut versuchen oder den Administrator informieren.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . addcslashes($fallbackFilename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($tmpDocx));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($tmpDocx);
@unlink($tmpDocx);
exit;
