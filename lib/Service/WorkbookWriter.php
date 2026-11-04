<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\Files\GenericFileException;

class WorkbookWriter {
    private const MAX_COLUMN_WIDTH = 60;
    // Calibri 11 character advances at 96 DPI, indexed from ASCII space through tilde.
    private const CHARACTER_WIDTHS = [
        3.32, 4.78, 5.88, 7.31, 7.44, 10.49, 10.01, 3.24, 4.45, 4.45, 7.31, 7.31, 3.66, 4.5, 3.71, 5.67,
        7.44, 7.44, 7.44, 7.44, 7.44, 7.44, 7.44, 7.44, 7.44, 7.44, 3.93, 3.93, 7.31, 7.31, 7.31, 6.8,
        13.12, 8.49, 7.98, 7.83, 9.03, 7.17, 6.74, 9.26, 9.14, 3.7, 4.68, 7.62, 6.17, 12.54, 9.47, 9.72, 7.58,
        9.87, 7.97, 6.74, 7.15, 9.42, 8.33, 13.05, 7.62, 7.15, 6.87, 4.5, 5.67, 4.5, 7.31, 7.31, 4.27,
        7.03, 7.71, 6.21, 7.71, 7.3, 4.48, 6.91, 7.71, 3.37, 3.51, 6.67, 3.37, 11.72, 7.71, 7.74, 7.71,
        7.71, 5.12, 5.74, 4.92, 7.71, 6.63, 10.49, 6.36, 6.64, 5.8, 4.62, 6.76, 4.62, 7.31,
    ];

    public function write(array $rows): string {
        if (!class_exists(\ZipArchive::class)) {
            throw new GenericFileException('The PHP zip extension is required to generate Timesheets workbooks.');
        }

        $path = tempnam(sys_get_temp_dir(), 'timesheets-xlsx-');
        if ($path === false) {
            throw new GenericFileException('Unable to allocate temporary storage for a Timesheets workbook.');
        }

        $archive = new \ZipArchive();
        $opened = false;
        try {
            if ($archive->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new GenericFileException('Unable to open temporary storage for a Timesheets workbook.');
            }
            $opened = true;
            $files = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    . '<Default Extension="xml" ContentType="application/xml"/>'
                    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                    . '</Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                    . '</Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    . '<sheets><sheet name="Timesheet" sheetId="1" r:id="rId1"/></sheets>'
                    . '</workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                    . '</Relationships>',
                'xl/styles.xml' => $this->styles(),
                'xl/worksheets/sheet1.xml' => $this->worksheet($rows),
            ];
            foreach ($files as $name => $content) {
                if (!$archive->addFromString($name, $content)) {
                    throw new GenericFileException('Unable to write a Timesheets workbook component.');
                }
            }
            if (!$archive->close()) {
                throw new GenericFileException('Unable to finalize a Timesheets workbook.');
            }
            $opened = false;

            $content = file_get_contents($path);
            if ($content === false) {
                throw new GenericFileException('Unable to read the generated Timesheets workbook.');
            }
            return $content;
        } finally {
            if ($opened) {
                $archive->close();
            }
            unlink($path);
        }
    }

    private function worksheet(array $rows): string {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $width = 2;
                foreach (preg_split('/\r\n|\r|\n/', (string) ($value ?? '')) as $line) {
                    $width = max($width, $this->textWidth($line) / 7 + 2);
                }
                $widths[$columnIndex] = min(self::MAX_COLUMN_WIDTH, max($widths[$columnIndex] ?? 2, $width));
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $index => $width) {
                $column = $index + 1;
                $xml .= '<col min="' . $column . '" max="' . $column . '" width="'
                    . number_format(ceil($width * 100) / 100, 2, '.', '') . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach (array_values($rows) as $rowIndex => $row) {
            $lines = 1;
            foreach (array_values($row) as $index => $value) {
                $lines = max($lines, $this->wrappedLines((string) ($value ?? ''), max(1, ($widths[$index] - 2) * 7)));
            }
            $xml .= '<row r="' . ($rowIndex + 1) . '" ht="' . min(409.5, $lines * 15) . '" customHeight="1">';
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $value = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<c r="' . $reference . '" s="1" t="inlineStr"><is><t xml:space="preserve">'
                    . $value . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function textWidth(string $text): float {
        $width = 0;
        foreach (mb_str_split(str_replace("\t", '    ', $text), 1, 'UTF-8') as $character) {
            $code = ord($character);
            $width += strlen($character) === 1 && $code >= 32 && $code <= 126
                ? self::CHARACTER_WIDTHS[$code - 32] : mb_strwidth($character, 'UTF-8') * 8;
        }
        return $width;
    }

    private function wrappedLines(string $text, float $available): int {
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $count++;
            $used = 0;
            foreach (preg_split('/([ \t]+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $word) {
                $width = $this->textWidth($word);
                if ($used > 0 && $used + $width > $available) {
                    $count++;
                    $used = 0;
                    if (trim($word) === '') {
                        continue;
                    }
                }
                foreach (mb_str_split(str_replace("\t", '    ', $word), 1, 'UTF-8') as $character) {
                    $width = $this->textWidth($character);
                    if ($used > 0 && $used + $width > $available) {
                        $count++;
                        $used = 0;
                    }
                    $used += $width;
                }
            }
        }
        return $count;
    }

    private function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function columnName(int $column): string {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }
        return $name;
    }
}
