<?php
/**
 * Lightweight XLSX Reader — No external dependencies
 * Uses PHP built-in ZipArchive + SimpleXMLElement
 * Returns array of rows (each row is an associative or indexed array)
 */

class XlsxReader {
    private $filePath;
    private $sheets = [];
    private $sharedStrings = [];

    public function __construct($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception('File not found: ' . $filePath);
        }
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive extension is required to read XLSX files.');
        }
        $this->filePath = $filePath;
    }

    /**
     * Parse the XLSX file and return data from a specific sheet
     * @param int $sheetIndex 0-based sheet index
     * @param bool $firstRowAsKeys Use first row as column headers
     * @return array Array of rows
     */
    public function getSheetData($sheetIndex = 0, $firstRowAsKeys = true) {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new Exception('Cannot open XLSX file. It may be corrupted.');
        }

        // 1. Parse shared strings
        $this->parseSharedStrings($zip);

        // 2. Get sheet file names from workbook
        $sheetFiles = $this->getSheetFiles($zip);

        if (!isset($sheetFiles[$sheetIndex])) {
            $zip->close();
            throw new Exception('Sheet index ' . $sheetIndex . ' does not exist.');
        }

        // 3. Parse the sheet XML
        $sheetXml = $zip->getFromName($sheetFiles[$sheetIndex]);
        $zip->close();

        if ($sheetXml === false) {
            throw new Exception('Cannot read sheet data.');
        }

        return $this->parseSheetXml($sheetXml, $firstRowAsKeys);
    }

    /**
     * Get list of sheet names
     */
    public function getSheetNames() {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) return [];

        $names = [];
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml) {
            $xml = simplexml_load_string($workbookXml);
            $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $xml->xpath('//s:sheet');
            foreach ($sheets as $sheet) {
                $attrs = $sheet->attributes('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $names[] = (string)$attrs['name'];
            }
        }
        $zip->close();
        return $names;
    }

    /**
     * Parse shared strings table
     */
    private function parseSharedStrings($zip) {
        $this->sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml === false) return;

        $xml = simplexml_load_string($ssXml);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = $xml->xpath('//s:si');
        foreach ($strings as $si) {
            $text = '';
            $tNodes = $si->xpath('.//s:t');
            foreach ($tNodes as $t) {
                $text .= (string)$t;
            }
            $this->sharedStrings[] = $text;
        }
    }

    /**
     * Get sheet file paths from workbook.xml
     */
    private function getSheetFiles($zip) {
        $files = [];
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml === false) return $files;

        $xml = simplexml_load_string($wbXml);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = $xml->xpath('//s:sheet');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsMap = [];

        if ($relsXml) {
            $rels = simplexml_load_string($relsXml);
            foreach ($rels->Relationship as $rel) {
                $id = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                // Normalize path
                if (strpos($target, '/') === 0) {
                    $filePath = substr($target, 1);
                } else {
                    $filePath = 'xl/' . $target;
                }
                $relsMap[$id] = $filePath;
            }
        }

        foreach ($sheets as $idx => $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rId = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($relsMap[$rId])) {
                $files[$idx] = $relsMap[$rId];
            }
        }

        return $files;
    }

    /**
     * Convert Excel column letter to 0-based index (A=0, B=1, ..., Z=25, AA=26)
     */
    private function colToIndex($col) {
        $col = strtoupper(trim($col));
        $len = strlen($col);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /**
     * Parse sheet XML into array of rows
     */
    private function parseSheetXml($xmlContent, $firstRowAsKeys) {
        $xml = simplexml_load_string($xmlContent);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        $headerRow = [];

        $rowElements = $xml->xpath('//s:sheetData/s:row');
        foreach ($rowElements as $rowEl) {
            $rowData = [];
            $cells = $rowEl->xpath('s:c');
            foreach ($cells as $cell) {
                $ref = (string)$cell['r']; // e.g. "A1", "B2"
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $matches);
                $colLetter = $matches[1];
                $rowNum = (int)$matches[2];
                $colIdx = $this->colToIndex($colLetter);

                $type = (string)$cell['t'];
                $value = '';

                $vNode = $cell->xpath('s:v');
                if (!empty($vNode)) {
                    $raw = (string)$vNode[0];
                    if ($type === 's' && isset($this->sharedStrings[(int)$raw])) {
                        $value = $this->sharedStrings[(int)$raw];
                    } elseif ($type === 'b') {
                        $value = ($raw === '1') ? 'TRUE' : 'FALSE';
                    } else {
                        $value = $raw;
                    }
                } else {
                    // Inline string
                    $isNode = $cell->xpath('s:is//s:t');
                    if (!empty($isNode)) {
                        $value = (string)$isNode[0];
                    }
                }

                // Pad any gaps
                while (count($rowData) <= $colIdx) {
                    $rowData[] = '';
                }
                $rowData[$colIdx] = trim($value);
            }

            // Trim trailing empty cells
            while (!empty($rowData) && end($rowData) === '') {
                array_pop($rowData);
            }

            // Skip completely empty rows
            if (empty($rowData) || (count($rowData) === 1 && $rowData[0] === '')) {
                continue;
            }

            // First row as keys?
            if ($firstRowAsKeys && empty($headerRow) && !empty($rowData)) {
                $headerRow = $rowData;
                continue;
            }

            if ($firstRowAsKeys && !empty($headerRow)) {
                $assoc = [];
                $minCols = min(count($headerRow), count($rowData));
                for ($i = 0; $i < $minCols; $i++) {
                    $assoc[$headerRow[$i]] = $rowData[$i] ?? '';
                }
                $rows[] = $assoc;
            } else {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }
}

/**
 * Helper: Generate a simple XLSX template file (using raw XML in a ZIP)
 * This creates a minimal valid .xlsx file with headers pre-filled
 */
function generateXlsxTemplate($headers, $sampleRow = [], $filename = 'template.xlsx') {
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create XLSX template.');
    }

    // Build column letters
    $colLetters = [];
    for ($i = 0; $i < count($headers); $i++) {
        $colLetters[] = getColLetter($i);
    }

    // Content Types
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');

    // Rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

    // Workbook
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');

    // Worksheet
    $sheetXml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    // Header row (bold style)
    $sheetXml .= '<row r="1">';
    foreach ($headers as $i => $h) {
        $col = $colLetters[$i];
        $sheetXml .= '<c r="' . $col . '1" t="inlineStr" s="1"><is><t>' . htmlspecialchars($h) . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    // Sample row (if provided)
    if (!empty($sampleRow)) {
        $sheetXml .= '<row r="2">';
        foreach ($sampleRow as $i => $val) {
            $col = $colLetters[$i];
            $sheetXml .= '<c r="' . $col . '2" t="inlineStr"><is><t>' . htmlspecialchars($val) . '</t></is></c>';
        }
        $sheetXml .= '</row>';
    }

    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    $zip->close();
    return $tmpFile;
}

function getColLetter($index) {
    $letter = '';
    $index++;
    while ($index > 0) {
        $index--;
        $letter = chr(ord('A') + ($index % 26)) . $letter;
        $index = (int)($index / 26);
    }
    return $letter;
}
