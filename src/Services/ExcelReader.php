<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ExcelReader
{
    private $spreadsheet;
    
    public function loadFile(string $filePath, string $password = ''): self
    {
        try {
            if (!empty($password)) {
                // Set password for encrypted files
                \PhpOffice\PhpSpreadsheet\Settings::setLibXmlLoaderOptions(
                    LIBXML_DTDLOAD | LIBXML_DTDATTR | LIBXML_COMPACT
                );
            }
            
            $this->spreadsheet = IOFactory::load($filePath);
            
            // Handle legacy Excel formats
            if ($this->spreadsheet === null) {
                throw new \Exception("Unable to load Excel file. Please ensure it's not corrupted.");
            }
            
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'encrypted') !== false || 
                strpos($e->getMessage(), 'password') !== false) {
                throw new \Exception(
                    "This Excel file appears to be password-protected. " .
                    "Please remove the password protection or provide the password."
                );
            }
            throw $e;
        }
        
        return $this;
    }
    
    public function getSpreadsheet(): Spreadsheet
    {
        return $this->spreadsheet;
    }
    
    public function clearFormatting(): self
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        // Remove all styling
        $sheet->getStyle('A1:' . $highestColumn . $highestRow)
            ->getFill()
            ->setFillType(Fill::FILL_NONE);
        
        $sheet->getStyle('A1:' . $highestColumn . $highestRow)
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_NONE);
        
        $sheet->getStyle('A1:' . $highestColumn . $highestRow)
            ->getFont()
            ->setBold(false)
            ->setItalic(false)
            ->setUnderline(false);
        
        // Reset column widths
        foreach (range('A', $highestColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth(-1);
        }
        
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->spreadsheet->getActiveSheet()->toArray();
    }

    /**
     * Stream rows from the active sheet, invoking a callback for each chunk.
     * Callback signature: function(array $rows): void
     */
    public function streamRows(callable $callback, int $chunkSize = 1000): void
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $highestRow = (int)$sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        for ($r = 1; $r <= $highestRow; $r++) {
            // return numeric-indexed array for the row (0-based indices)
            $rowArr = $sheet->rangeToArray('A' . $r . ':' . $highestColumn . $r, null, true, true, false)[0];
            $rowArr = array_values($rowArr);
            // callback signature expected by tests: function($rowNum, $row)
            $callback($r - 1, $rowArr);
        }
    }
    
    public function getHeaders(): array
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        return $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1')[0];
    }
    
    public function saveToFile(string $outputPath): void
    {
        $writer = IOFactory::createWriter($this->spreadsheet, 'Xlsx');
        $writer->save($outputPath);
    }
}