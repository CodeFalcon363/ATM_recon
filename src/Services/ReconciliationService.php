<?php

namespace App\Services;

use App\Models\ReconciliationResult;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Orchestrates the reconciliation pipeline: GL processing → FEP filtering → transaction matching
 */
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
        $glReader = UniversalFileReader::create($this->glFilePath);
        $glReader->loadFile($this->glFilePath)
                 ->clearFormatting();

    $glData = $glReader->toArray();
    $glProcessor = new GLProcessor($glData);
        $loadUnloadData = $glProcessor->extractLoadUnloadData();

        error_log("GL Load: " . $loadUnloadData->getLoadAmount() . " at " . $loadUnloadData->getLoadDateTime()->format('Y-m-d H:i:s'));
        error_log("GL Unload: " . $loadUnloadData->getUnloadAmount() . " at " . $loadUnloadData->getUnloadDateTime()->format('Y-m-d H:i:s'));

        $glFileType = UniversalFileReader::getFileType($this->glFilePath);
        $extension = ($glFileType === 'csv') ? '.csv' : '.xlsx';
        $uniqueId = uniqid('gl_', true) . '_' . substr(session_id(), 0, 8);
        $this->processedGLPath = sys_get_temp_dir() . '/processed_' . $uniqueId . $extension;
        $glReader->saveToFile($this->processedGLPath);
    unset($glReader);

        $fepReader = UniversalFileReader::create($this->fepFilePath);
        $fepReader->loadFile($this->fepFilePath)
                  ->clearFormatting();

        $fepData = $fepReader->toArray();
        $fepProcessor = new FEPProcessor($fepData);
    unset($fepReader);

        $initialCount = $fepProcessor->getTransactionCount();
        error_log("FEP Initial transactions: " . $initialCount);

        $fepProcessor->filterApprovedOnly();
        $approvedCount = $fepProcessor->getTransactionCount();
        error_log("FEP After approved filter: " . $approvedCount);

        $fepProcessor->removeDuplicates();
        $noDupCount = $fepProcessor->getTransactionCount();
        error_log("FEP After duplicate removal: " . $noDupCount);

        $fepProcessor->filterByTransactionType();
        $noReversalCount = $fepProcessor->getTransactionCount();
        error_log("FEP After REVERSAL filter: " . $noReversalCount);

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

        $filteredOutFepData = $fepProcessor->getFilteredOutTransactions();
        error_log("Total filtered-out FEP transactions: " . count($filteredOutFepData));

        error_log("Starting transaction-level matching...");

        $glHeadersForMatching = $glProcessor->getHeaders();
        $glDataForMatching = $glProcessor->getData();

        error_log("GL matching data: " . count($glDataForMatching) . " data rows");

        $fepDataForMatching = $fepProcessor->getData();
        $fepHeadersForMatching = $fepProcessor->getHeaders();

        error_log("FEP matching data: " . count($fepDataForMatching) . " filtered transactions");

        $matcher = new TransactionMatcher(
            $glDataForMatching,
            $fepDataForMatching,
            $glHeadersForMatching,
            $fepHeadersForMatching,
            $filteredOutFepData,
            false
        );

    $this->transactionMatch = $matcher->matchTransactions();

    unset($matcher);
        
        error_log("Transaction matching complete:");
        error_log("  Matched: " . $this->transactionMatch->getMatchedCount());
        error_log("  GL found in filtered FEP: " . $this->transactionMatch->getGlFoundInFilteredFepCount());
        error_log("  GL not on FEP: " . $this->transactionMatch->getGlNotOnFepCount());
        error_log("  FEP not on GL: " . $this->transactionMatch->getFepNotOnGlCount());

        $this->processedFEPPath = $this->saveProcessedFEP($fepProcessor);

    unset($fepProcessor);
    unset($fepData);
    unset($glData);

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
        $fepFileType = UniversalFileReader::getFileType($this->fepFilePath);
        $extension = ($fepFileType === 'csv') ? '.csv' : '.xlsx';
        $uniqueId = uniqid('fep_', true) . '_' . substr(session_id(), 0, 8);
        $outputPath = sys_get_temp_dir() . '/processed_' . $uniqueId . $extension;

        $headers = $processor->getHeaders();
        $data = $processor->getData();

        if ($fepFileType === 'csv') {
            $handle = fopen($outputPath, 'w');

            fputcsv($handle, $headers);

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