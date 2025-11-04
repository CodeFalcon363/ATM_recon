<?php

namespace App\Services;

use App\Models\ReconciliationResult;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReconciliationService
{
    private $glFilePath;
    private $fepFilePath;
    private $processedGLPath;
    private $processedFEPPath;
    private $transactionMatch;
    
    public function __construct(string $glFilePath, string $fepFilePath)
    {
        $this->glFilePath = $glFilePath;
        $this->fepFilePath = $fepFilePath;
    }
    
    public function process(): ReconciliationResult
    {
        // Process GL file - auto-detect CSV or XLSX
        $glReader = UniversalFileReader::create($this->glFilePath);
        $glReader->loadFile($this->glFilePath)
                 ->clearFormatting();
        
    // Load entire GL into array once (needed for processors and matching)
    $glData = $glReader->toArray();
    $glProcessor = new GLProcessor($glData);
        $loadUnloadData = $glProcessor->extractLoadUnloadData();
        
        // Log GL data for debugging
        error_log("GL Load: " . $loadUnloadData->getLoadAmount() . " at " . $loadUnloadData->getLoadDateTime()->format('Y-m-d H:i:s'));
        error_log("GL Unload: " . $loadUnloadData->getUnloadAmount() . " at " . $loadUnloadData->getUnloadDateTime()->format('Y-m-d H:i:s'));
        
        // Save cleaned GL file (same format as input)
        $glFileType = UniversalFileReader::getFileType($this->glFilePath);
        $extension = ($glFileType === 'csv') ? '.csv' : '.xlsx';
        $this->processedGLPath = sys_get_temp_dir() . '/processed_gl_' . time() . $extension;
        $glReader->saveToFile($this->processedGLPath);
    // Free GL reader/spreadsheet to reduce memory
    unset($glReader);
        
        // Process FEP file - auto-detect CSV or XLSX
        $fepReader = UniversalFileReader::create($this->fepFilePath);
        $fepReader->loadFile($this->fepFilePath)
                  ->clearFormatting();
        
        $fepData = $fepReader->toArray();
        $fepProcessor = new FEPProcessor($fepData);
    // Free FEP reader spreadsheet if memory is constrained
    unset($fepReader);
        
        $initialCount = $fepProcessor->getTransactionCount();
        error_log("FEP Initial transactions: " . $initialCount);
        
        // CRITICAL: Apply filters in correct order
        // 1. Filter to Approved only (keeps both INITIAL and REVERSAL if approved)
        $fepProcessor->filterApprovedOnly();
        $approvedCount = $fepProcessor->getTransactionCount();
        error_log("FEP After approved filter: " . $approvedCount);
        
        // 2. Find and remove duplicates BEFORE filtering transaction type
        //    This ensures we detect duplicates even if one is INITIAL and one is REVERSAL
        $fepProcessor->removeDuplicates();
        $noDupCount = $fepProcessor->getTransactionCount();
        error_log("FEP After duplicate removal: " . $noDupCount);
        
        // 3. Now filter out REVERSAL transactions (this catches any non-duplicate REVERSALs)
        $fepProcessor->filterByTransactionType();
        $noReversalCount = $fepProcessor->getTransactionCount();
        error_log("FEP After REVERSAL filter: " . $noReversalCount);
        
        // 4. Sort and filter by date range
        $fepProcessor->sortByRequestDate()
                     ->filterByDateRange(
                         $loadUnloadData->getLoadDateTime(),
                         $loadUnloadData->getUnloadDateTime()
                     );
        
        $finalCount = $fepProcessor->getTransactionCount();
        error_log("FEP After date range filter: " . $finalCount);
        
        $successfulTransactions = $fepProcessor->calculateTotalAmount();
        $transactionCount = $fepProcessor->getTransactionCount();
        
        error_log("FEP Total Amount: " . $successfulTransactions);
        
        // Get filtered-out transactions for second-pass matching
        $filteredOutFepData = $fepProcessor->getFilteredOutTransactions();
        error_log("Total filtered-out FEP transactions: " . count($filteredOutFepData));
        
        // ===== TRANSACTION-LEVEL MATCHING =====
        // Match individual GL transactions with FEP transactions by RRN
        error_log("Starting transaction-level matching...");

        // Reuse GLProcessor's already-identified headers to avoid duplicate detection
        $glHeadersForMatching = $glProcessor->getHeaders();
        $glDataForMatching = $glProcessor->getData();

        error_log("GL matching data: " . count($glDataForMatching) . " data rows");
        
        // Get FEP data (already filtered by FEPProcessor)
        $fepDataForMatching = $fepProcessor->getData();
        $fepHeadersForMatching = $fepProcessor->getHeaders();
        
        error_log("FEP matching data: " . count($fepDataForMatching) . " filtered transactions");
        
        $matcher = new TransactionMatcher(
            $glDataForMatching,
            $fepDataForMatching,
            $glHeadersForMatching,
            $fepHeadersForMatching,
            $filteredOutFepData, // Pass filtered-out transactions for second-pass matching
            false // debug off for performance
        );
        
    $this->transactionMatch = $matcher->matchTransactions();

    // Free matcher internals if heavy
    unset($matcher);
        
        error_log("Transaction matching complete:");
        error_log("  Matched: " . $this->transactionMatch->getMatchedCount());
        error_log("  GL found in filtered FEP: " . $this->transactionMatch->getGlFoundInFilteredFepCount());
        error_log("  GL not on FEP: " . $this->transactionMatch->getGlNotOnFepCount());
        error_log("  FEP not on GL: " . $this->transactionMatch->getFepNotOnGlCount());
        
        // Save processed FEP file
        $this->processedFEPPath = $this->saveProcessedFEP($fepProcessor);

    // Free processor and any large arrays now that files are saved
    unset($fepProcessor);
    unset($fepData);
    unset($glData);
        
        // Create reconciliation result
        return new ReconciliationResult(
            $loadUnloadData->getLoadAmount(),
            $loadUnloadData->getUnloadAmount(),
            $successfulTransactions,
            $loadUnloadData->getLoadDateTime(),
            $loadUnloadData->getUnloadDateTime(),
            $transactionCount,
            $loadUnloadData->getLoadCount(),
            $loadUnloadData->getUnloadCount(),
            $loadUnloadData->getExcludedFirstUnload(),
            $loadUnloadData->getExcludedLastLoad()
        );
    }
    
    private function saveProcessedFEP(FEPProcessor $processor): string
    {
        // Determine output format based on input file type
        $fepFileType = UniversalFileReader::getFileType($this->fepFilePath);
        $extension = ($fepFileType === 'csv') ? '.csv' : '.xlsx';
        $outputPath = sys_get_temp_dir() . '/processed_fep_' . time() . $extension;

        $headers = $processor->getHeaders();
        $data = $processor->getData();

        if ($fepFileType === 'csv') {
            // Save as CSV (fast)
            $handle = fopen($outputPath, 'w');
            
            // Write headers
            fputcsv($handle, $headers);
            
            // Write data rows, converting DateTime objects to strings
            foreach ($data as $row) {
                $rowData = [];
                foreach ($row as $cell) {
                    if ($cell instanceof \DateTime) {
                        $rowData[] = $cell->format('Y-m-d H:i:s');
                    } else {
                        $rowData[] = $cell;
                    }
                }
                fputcsv($handle, $rowData);
            }
            fclose($handle);
        } else {
            // Save as XLSX (slower but needed for Excel compatibility)
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray($headers, null, 'A1');

            $rowNum = 2;
            foreach ($data as $row) {
                $sheet->fromArray($row, null, 'A' . $rowNum);
                $rowNum++;
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
        }

        return $outputPath;
    }
    
    public function getProcessedGLPath(): ?string
    {
        return $this->processedGLPath;
    }
    
    public function getProcessedFEPPath(): ?string
    {
        return $this->processedFEPPath;
    }
    
    public function getTransactionMatch()
    {
        return $this->transactionMatch;
    }
}