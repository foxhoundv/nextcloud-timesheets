<?php

declare(strict_types=1);

use OCA\Timesheets\Service\WorkbookWriter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = $argv[1] ?? '';
if (!is_file($root . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php tests/workbook.php /path/to/nextcloud\n");
    exit(2);
}
define('OC_CONSOLE', 1);
require_once $root . '/lib/base.php';
\OC_App::loadApps();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . "\n";
}
function documents(string $content): array {
    $path = tempnam(sys_get_temp_dir(), 'timesheets-workbook-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary workbook test file.');
    }
    $archive = new ZipArchive();
    $opened = false;
    try {
        if (file_put_contents($path, $content) !== strlen($content) || $archive->open($path) !== true) {
            throw new RuntimeException('Cannot inspect generated workbook.');
        }
        $opened = true;
        $result = [];
        foreach (['[Content_Types].xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $name) {
            $xml = $archive->getFromName($name);
            $document = new DOMDocument();
            if ($xml === false || !$document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Missing or invalid workbook XML: ' . $name);
            }
            $result[$name] = new DOMXPath($document);
        }
        return $result;
    } finally {
        if ($opened) {
            $archive->close();
        }
        unlink($path);
    }
}
$writer = new WorkbookWriter();
$long = str_repeat('Long explanatory text with normal word boundaries. ', 12);
$rows = [
    ['Clock in', 'Country', 'State / province / region', 'Actual duration', 'Rounding interval (minutes)', 'Narrow', 'Wide', 'Notes'],
    ['2026-11-04 13:15:00 PST', 'United States', 'Oregon', '123:45:59', 'Not captured', 'iiiiiiii', 'WWWWWWWW', $long],
    ['2026-11-04 13:15:00 PST', 'Canada', 'British Columbia', '0:00:00', 30, null, '', "First line\nSecond line\nThird line"],
    [],
    ['<&" preserved', '', '', '', '', '', '', str_repeat('W', 200)],
    array_merge(array_fill(0, 26, ''), ['Column beyond Z']),
];
$documents = documents($writer->write($rows));
$sheet = $documents['xl/worksheets/sheet1.xml'];
$sheet->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$styles = $documents['xl/styles.xml'];
$styles->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$types = $documents['[Content_Types].xml'];
$types->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
$relations = $documents['xl/_rels/workbook.xml.rels'];
$relations->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
check($types->query('//t:Override[@PartName="/xl/styles.xml"]')->length === 1
    && $relations->query('//r:Relationship[@Target="styles.xml"]')->length === 1,
    'Workbook package registers and links the shared font and wrapping styles');
check($styles->query('//s:fonts/s:font/s:name[@val="Calibri"]')->length === 1
    && $styles->query('//s:cellXfs/s:xf[2]/s:alignment[@wrapText="1" and @vertical="top"]')->length === 1,
    'Cells use the measured Calibri font with wrapping and top alignment');
check($sheet->query('//s:cols/s:col')->length === 27
    && $sheet->query('//s:cols/following-sibling::s:sheetData')->length === 1,
    'All used columns, including columns beyond Z, have widths before sheet data');
$widths = [];
foreach ($sheet->query('//s:cols/s:col') as $column) {
    $widths[(int) $column->getAttribute('min')] = (float) $column->getAttribute('width');
    check($column->getAttribute('customWidth') === '1'
        && $column->getAttribute('min') === $column->getAttribute('max')
        && (float) $column->getAttribute('width') >= 2 && (float) $column->getAttribute('width') <= 60,
        'Column ' . $column->getAttribute('min') . ' has a valid explicit content width');
}
check($widths[1] >= 23.28 && $widths[1] <= 23.5 && $widths[5] >= 25.74 && $widths[5] <= 26,
    'Timestamp values and headings fit independently measured Calibri text widths with a small margin');
check($widths[7] > $widths[6], 'Wide glyphs receive more room than narrow glyphs of the same character count');
check($widths[8] === 60.0, 'Long notes and unbroken values wrap at the configured maximum column width');
check($sheet->query('//s:c[not(@s="1")]')->length === 0, 'Every populated cell uses the wrapping style');
check((float) $sheet->query('//s:row[@r="2"]')->item(0)->getAttribute('ht') >= 150,
    'Long explanatory notes increase row height enough for multiple lines');
check((float) $sheet->query('//s:row[@r="3"]')->item(0)->getAttribute('ht') >= 45,
    'Explicit line breaks increase row height');
check((float) $sheet->query('//s:row[@r="5"]')->item(0)->getAttribute('ht') > 15,
    'Long unbroken strings also receive wrapped row height');
check($sheet->query('//s:c[@r="A5"]/s:is/s:t')->item(0)->textContent === '<&" preserved'
    && $sheet->query('//s:c[@r="H2"]/s:is/s:t')->item(0)->textContent === $long
    && $sheet->query('//s:c[@r="E3"]/s:is/s:t')->item(0)->textContent === '30',
    'Formatting preserves XML-sensitive text, long notes and numeric values');
check($sheet->query('//s:c[@r="AA6"]')->length === 1, 'Column addressing beyond Z remains intact');
check((float) $sheet->query('//s:row[@r="4"]')->item(0)->getAttribute('ht') === 15.0,
    'Blank separator rows keep normal height');

$unicode = documents($writer->write([['München', '東京', ''], ["A\nB", str_repeat('界', 200), null]]));
$unicodeSheet = $unicode['xl/worksheets/sheet1.xml'];
$unicodeSheet->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
check($unicodeSheet->query('//s:c[@r="A1"]/s:is/s:t')->item(0)->textContent === 'München'
    && $unicodeSheet->query('//s:c[@r="B1"]/s:is/s:t')->item(0)->textContent === '東京',
    'Unicode country/region and employee text survives sizing');
check((float) $unicodeSheet->query('//s:col[@min="2"]')->item(0)->getAttribute('width') === 60.0
    && (float) $unicodeSheet->query('//s:row[@r="2"]')->item(0)->getAttribute('ht') > 15,
    'Wide Unicode characters participate in column sizing and row wrapping');
check((float) $unicodeSheet->query('//s:col[@min="3"]')->item(0)->getAttribute('width') === 2.0,
    'Empty cells do not unnecessarily widen a column');
$multiline = documents($writer->write([["One\nTwo\nThree"]]));
$multilineSheet = $multiline['xl/worksheets/sheet1.xml'];
$multilineSheet->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
check((float) $multilineSheet->query('//s:col')->item(0)->getAttribute('width') < 8
    && (float) $multilineSheet->query('//s:row')->item(0)->getAttribute('ht') === 45.0,
    'Multiline cells size to their longest line rather than the entire string');
$empty = documents($writer->write([]));
$emptySheet = $empty['xl/worksheets/sheet1.xml'];
$emptySheet->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
check($emptySheet->query('//s:col|//s:row')->length === 0, 'Empty workbooks remain valid without phantom columns or rows');
echo "Workbook formatting checks passed.\n";
