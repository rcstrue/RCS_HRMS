<?php
/**
 * RCS HRMS Pro - Excel Reader Class
 * Handles reading Excel/CSV files for attendance and employee import
 */

class ExcelReader {
    
    // Read Excel or CSV file
    public function read($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($extension === 'csv') {
            return $this->readCSV($filePath);
        } elseif (in_array($extension, ['xls', 'xlsx'])) {
            return $this->readExcel($filePath);
        }
        
        return [];
    }
    
    // Read CSV file
    private function readCSV($filePath) {
        $data = [];
        $headers = [];
        $firstRow = true;
        
        if (($handle = fopen($filePath, 'r')) !== FALSE) {
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if ($firstRow) {
                    // First row as headers
                    $headers = array_map('strtolower', array_map('trim', $row));
                    $firstRow = false;
                    continue;
                }
                
                if (count($headers) === count($row)) {
                    $data[] = array_combine($headers, $row);
                } else {
                    // Use numeric keys
                    $data[] = $row;
                }
            }
            fclose($handle);
        }
        
        return $data;
    }
    
    // Read Excel file (simple implementation without external libraries)
    private function readExcel($filePath) {
        // For XLSX files, we'll parse the XML content
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($extension === 'xlsx') {
            return $this->parseXLSX($filePath);
        }
        
        // Fallback to CSV-like parsing for XLS
        return $this->readCSV($filePath);
    }
    
    // Parse XLSX file (simplified)
    private function parseXLSX($filePath) {
        $data = [];
        $headers = [];
        
        try {
            // XLSX is a ZIP file with XML content
            $zip = new ZipArchive;
            if ($zip->open($filePath) === TRUE) {
                // Get shared strings
                $sharedStrings = [];
                $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
                if ($sharedStringsXML) {
                    $xml = simplexml_load_string($sharedStringsXML);
                    foreach ($xml->si as $si) {
                        $sharedStrings[] = (string)$si->t;
                    }
                }
                
                // Get sheet data
                $sheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
                if ($sheetXML) {
                    $xml = simplexml_load_string($sheetXML);
                    $rows = $xml->sheetData->row;
                    $firstRow = true;
                    
                    foreach ($rows as $row) {
                        $rowData = [];
                        $colIndex = 0;
                        
                        foreach ($row->c as $cell) {
                            $value = '';
                            $type = (string)$cell['t'];
                            $v = (string)$cell->v;
                            
                            if ($type === 's' && isset($sharedStrings[intval($v)])) {
                                $value = $sharedStrings[intval($v)];
                            } elseif ($type === 'n' || $type === '') {
                                $value = $v;
                            } else {
                                $value = $v;
                            }
                            
                            $rowData[$colIndex] = $value;
                            $colIndex++;
                        }
                        
                        if ($firstRow) {
                            $headers = array_map('strtolower', array_map('trim', $rowData));
                            $firstRow = false;
                        } else {
                            if (!empty($headers) && count($headers) === count($rowData)) {
                                $data[] = array_combine($headers, $rowData);
                            } else {
                                $data[] = $rowData;
                            }
                        }
                    }
                }
                
                $zip->close();
            }
        } catch (Exception $e) {
            // Return empty array on error
            return [];
        }
        
        return $data;
    }
    
    // Write to CSV
    public function writeCSV($filePath, $data, $headers = null) {
        $handle = fopen($filePath, 'w');
        
        if ($headers) {
            fputcsv($handle, $headers, ',', '"', '\\');
        } elseif (!empty($data)) {
            fputcsv($handle, array_keys($data[0]), ',', '"', '\\');
        }
        
        foreach ($data as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        
        fclose($handle);
        
        return true;
    }
    
    // Generate Excel template
    public function generateTemplate($type) {
        $sampleEmployeeCode = 'EMP001';
        $sampleEmployeeName = 'Ramesh Kumar';
        $sampleDate = '01-01-2025';
        
        $templates = [
            'attendance' => [
                ['employee_code', 'employee_name', 'date', 'status', 'check_in', 'check_out', 'overtime_hours'],
                [$sampleEmployeeCode, $sampleEmployeeName, $sampleDate, 'P', '09:00', '18:00', '1'],
                [$sampleEmployeeCode, $sampleEmployeeName, '02-01-2025', 'P', '09:00', '18:00', '0']
            ],
            'employees' => [
                ['employee_code', 'first_name', 'last_name', 'gender', 'date_of_birth', 'mobile_number', 'aadhaar_number', 'pan_number', 'bank_name', 'bank_account_number', 'bank_ifsc_code', 'designation', 'skill_category', 'worker_category', 'date_of_joining', 'basic_salary', 'da', 'hra', 'state', 'is_pf_applicable', 'is_esi_applicable'],
                [$sampleEmployeeCode, 'Ramesh', 'Kumar', 'male', $sampleDate, '9876543210', '123456789012', 'ABCDE1234F', 'SBI', '12345678901', 'SBIN0001234', 'Security Guard', 'skilled', 'worker', $sampleDate, '12000', '1500', '3000', 'Gujarat', 'yes', 'yes']
            ],
            'pf_active' => [
                ['uan', 'member_name', 'gender', 'dob', 'father_name', 'date_of_joining', 'gross_salary'],
                ['123456789012', $sampleEmployeeName, 'M', $sampleDate, 'Shri Ram', $sampleDate, '16500']
            ]
        ];
        
        return $templates[$type] ?? [];
    }
}
?>
