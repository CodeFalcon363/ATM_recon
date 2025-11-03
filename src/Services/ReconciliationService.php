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
        // Process GL file
        $glReader = new ExcelReader();
        $glReader->loadFile($this->glFilePath)
                 ->clearFormatting();
        
    // Load entire GL into array once (needed for processors and matching)
    $glData = $glReader->toArray();
    $glProcessor = new GLProcessor($glData);
        $loadUnloadData = $glProcessor->extractLoadUnloadData();
        
        // Log GL data for debugging
        error_log("GL Load: " . $loadUnloadData->getLoadAmount() . " at " . $loadUnloadData->getLoadDateTime()->format('Y-m-d H:i:s'));
        error_log("GL Unload: " . $loadUnloadData->getUnloadAmount() . " at " . $loadUnloadData->getUnloadDateTime()->format('Y-m-d H:i:s'));
        
        // Save cleaned GL file
        $this->processedGLPath = sys_get_temp_dir() . '/processed_gl_' . time() . '.xlsx';
        $glReader->saveToFile($this->processedGLPath);
    // Free GL reader/spreadsheet to reduce memory
    unset($glReader);
        
        // Process FEP file
        $fepReader = new ExcelReader();
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
        
    // Reuse the already-loaded GL array rather than re-reading the file
    $glDataRaw = $glData;
        
        // Find GL header row (same logic as GLProcessor)
        $glHeaderRow = 0;
        foreach ($glDataRaw as $index => $row) {
            $rowStr = strtolower(implode('', $row));
            if (strpos($rowStr, 'description') !== false && 
                strpos($rowStr, 'credit') !== false && 
                strpos($rowStr, 'debit') !== false) {
                $glHeaderRow = $index;
                break;
            }
        }
        
        $glHeadersForMatching = $glDataRaw[$glHeaderRow];
        $glDataForMatching = array_slice($glDataRaw, $glHeaderRow + 1);
        
        error_log("GL matching data: Header at row $glHeaderRow, " . count($glDataForMatching) . " data rows");
        
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
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Write headers
        $headers = $processor->getHeaders();
        $sheet->fromArray($headers, null, 'A1');
        
        // Write data
        $data = $processor->getData();
        $rowNum = 2;
        foreach ($data as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNum);
            $rowNum++;
        }
        
        $outputPath = sys_get_temp_dir() . '/processed_fep_' . time() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
        
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