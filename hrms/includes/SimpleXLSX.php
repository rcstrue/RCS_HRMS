<?php
/**
 * SimpleXLSX - Simple XLSX Parser
 * A lightweight PHP class for parsing XLSX files
 */

class SimpleXLSX {
    private $workbook;
    private $sheets = [];
    private $sheetNames = [];
    private $sharedStrings = [];
    
    public function __construct($filename) {
        if (!file_exists($filename)) {
            throw new Exception('File not found: ' . $filename);
        }
        
        $this->loadFile($filename);
    }
    
    public static function parse($filename) {
        return new self($filename);
    }
    
    private function loadFile($filename) {
        $zip = new ZipArchive();
        
        if ($zip->open($filename) !== true) {
            throw new Exception('Failed to open XLSX file');
        }
        
        // Get shared strings
        if (($index = $zip->locateName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($zip->getFromIndex($index));
            foreach ($xml->si as $si) {
                $this->sharedStrings[] = (string)$si->t;
            }
        }
        
        // Get workbook
        if (($index = $zip->locateName('xl/workbook.xml')) !== false) {
            $xml = simplexml_load_string($zip->getFromIndex($index));
            foreach ($xml->sheets->sheet as $sheet) {
                $this->sheetNames[] = (string)$sheet['name'];
            }
        }
        
        // Get worksheets
        for ($i = 1; $i <= count($this->sheetNames); $i++) {
            $sheetFile = 'xl/worksheets/sheet' . $i . '.xml';
            if (($index = $zip->locateName($sheetFile)) !== false) {
                $this->sheets[$i - 1] = $zip->getFromIndex($index);
            }
        }
        
        $zip->close();
    }
    
    public function rows($sheetIndex = 0) {
        if (!isset($this->sheets[$sheetIndex])) {
            return [];
        }
        
        $xml = simplexml_load_string($this->sheets[$sheetIndex]);
        $rows = [];
        
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $colIndex = 0;
            
            foreach ($row->c as $cell) {
                // Get column index from cell reference (A1, B1, etc.)
                $ref = (string)$cell['r'];
                preg_match('/([A-Z]+)/', $ref, $matches);
                $col = 0;
                $colLetters = $matches[1];
                for ($i = 0; $i < strlen($colLetters); $i++) {
                    $col = $col * 26 + (ord($colLetters[$i]) - 64);
                }
                $col--; // Convert to 0-based index
                
                // Fill empty cells
                while (count($rowData) < $col) {
                    $rowData[] = '';
                }
                
                // Get cell value
                $value = '';
                $type = (string)$cell['t'];
                
                if ($type === 's') {
                    // Shared string
                    $index = (int)$cell->v;
                    $value = $this->sharedStrings[$index] ?? '';
                } elseif ($type === 'str') {
                    // Formula string
                    $value = (string)$cell->v;
                } elseif (isset($cell->v)) {
                    $value = (string)$cell->v;
                    
                    // Check for date format
                    if (isset($cell['s'])) {
                        $styleIndex = (int)$cell['s'];
                        // Simple date detection - if number and reasonable date range
                        if (is_numeric($value) && $value > 25569 && $value < 50000) {
                            $value = date('Y-m-d', ($value - 25569) * 86400);
                        }
                    }
                }
                
                $rowData[] = $value;
            }
            
            $rows[] = $rowData;
        }
        
        return $rows;
    }
    
    public function sheetNames() {
        return $this->sheetNames;
    }
    
    public function getSheetCount() {
        return count($this->sheets);
    }
}
