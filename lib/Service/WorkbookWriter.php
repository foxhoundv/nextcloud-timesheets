<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

class WorkbookWriter {
    public function write(array $rows): string {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to generate Timesheets workbooks.');
        }

        $path = tempnam(sys_get_temp_dir(), 'timesheets-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate temporary storage for a Timesheets workbook.');
        }

        $archive = new \ZipArchive();
        $opened = false;
        try {
            if ($archive->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to open temporary storage for a Timesheets workbook.');
            }
            $opened = true;
            $files = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    . '<Default Extension="xml" ContentType="application/xml"/>'
                    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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
                    . '</Relationships>',
                'xl/worksheets/sheet1.xml' => $this->worksheet($rows),
            ];
            foreach ($files as $name => $content) {
                if (!$archive->addFromString($name, $content)) {
                    throw new \RuntimeException('Unable to write a Timesheets workbook component.');
                }
            }
            if (!$archive->close()) {
                throw new \RuntimeException('Unable to finalize a Timesheets workbook.');
            }
            $opened = false;

            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Unable to read the generated Timesheets workbook.');
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
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $rowIndex => $row) {
            $xml .= '<row r="' . ($rowIndex + 1) . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $value = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $value . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
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
