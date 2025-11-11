<?php

namespace App\Services;

use DateTime;
use Exception;

/**
 * Processes FEP transaction data with filtering pipeline: approved → dedup → type filter → date range
 */
class FEPProcessor
{
    private $data;
    private $headers;
    private $responseMeaningColumn;
    private $responseCodeColumn;
    private $retrievalRefColumn;
    private $requestDateColumn;
    private $amountColumn;
    private $tranTypeColumn;
    private $filteredOutTransactions = [];
    private $transactionsAfterDateRange = [];
    
    public function __construct(array $data)
    {
        $this->data = $data;

        $headerRow = 0;
        foreach ($data as $index => $row) {
            $rowStr = strtolower(implode('', $row));
            if (strpos($rowStr, 'retrieval') !== false && 
                strpos($rowStr, 'response') !== false) {
                $headerRow = $index;
                break;
            }
        }

        $this->headers = $data[$headerRow];
        $this->data = array_slice($data, $headerRow + 1);
        $this->precomputeFields();
        $this->identifyColumns();
    }

    private function precomputeFields(): void
    {
    }
    
    private function identifyColumns(): void
    {
        foreach ($this->headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            if ($this->responseMeaningColumn === null && (strpos($headerLower, 'response') !== false || strpos($headerLower, 'resp') !== false)) {
                if (strpos($headerLower, 'meaning') !== false || strpos($headerLower, 'code') !== false || strpos($headerLower, 'status') !== false || preg_match('/resp(onse)?\b/', $headerLower)) {
                    $this->responseMeaningColumn = $index;
                } else {
                    $this->responseMeaningColumn = $index;
                }
            }

            if ($this->retrievalRefColumn === null && (strpos($headerLower, 'retrieval') !== false || strpos($headerLower, 'reference') !== false || strpos($headerLower, 'rrn') !== false || strpos($headerLower, 'retrieval ref') !== false)) {
                $this->retrievalRefColumn = $index;
            }
            
            if (strpos($headerLower, 'request') !== false && 
                strpos($headerLower, 'date') !== false) {
                $this->requestDateColumn = $index;
            }
            
            if (strpos($headerLower, 'amount') !== false) {
                $this->amountColumn = $index;
            }
            
            if (strpos($headerLower, 'tran') !== false && 
                strpos($headerLower, 'type') !== false) {
                $this->tranTypeColumn = $index;
            }

            if ($this->responseCodeColumn === null && (strpos($headerLower, 'rsp') !== false || strpos($headerLower, 'response code') !== false || strpos($headerLower, 'rcode') !== false || preg_match('/\brsp\b/', $headerLower))) {
                $this->responseCodeColumn = $index;
            }
        }

        if ($this->responseMeaningColumn === null && $this->retrievalRefColumn === null) {
            throw new Exception("Required columns (response meaning or retrieval ref) not found in FEP file");
        }

        foreach ($this->data as $idx => $row) {
            $ref = ($this->retrievalRefColumn !== null && isset($row[$this->retrievalRefColumn])) ? $row[$this->retrievalRefColumn] : '';
            $normalized = $ref !== '' ? preg_replace('/\s+/', '', trim((string)$ref)) : null;
            $this->data[$idx]['__normalized_ref'] = ($normalized !== '' && $normalized !== null) ? $normalized : null;

            $dateStr = ($this->requestDateColumn !== null && isset($row[$this->requestDateColumn])) ? $row[$this->requestDateColumn] : '';
            $this->data[$idx]['__parsed_date'] = $this->parseDateTime((string)$dateStr);

            $amtRaw = ($this->amountColumn !== null && isset($row[$this->amountColumn])) ? $row[$this->amountColumn] : '';
            $amt = $amtRaw !== '' ? preg_replace('/[^0-9.\-]/', '', (string)$amtRaw) : '';
            $this->data[$idx]['__parsed_amount'] = $amt === '' ? 0.0 : (float)$amt;
        }
    }

    /**
     * Keep only approved transactions (response code '00'/'0' or text 'approved')
     */
    public function filterApprovedOnly(): self
    {
        $kept = [];
        $filtered = [];

        foreach ($this->data as $row) {
            $response = '';

            if ($this->responseCodeColumn !== null && isset($row[$this->responseCodeColumn])) {
                $response = (string)$row[$this->responseCodeColumn];
            } elseif ($this->responseMeaningColumn !== null && isset($row[$this->responseMeaningColumn])) {
                $response = (string)$row[$this->responseMeaningColumn];
            }

            $response = strtolower(trim($response));

            $hasApprovedWord = preg_match('/\b(approved|approve|authoriz|auth)\b/i', $response);
            $hasSuccessCode = preg_match('/\b0{1,2}\b/', $response);
            $hasNegation = preg_match('/\b(not|declin|fail|revers)\b/i', $response) && !preg_match('/\b0{1,2}\b/', $response);

            $isApproved = ($hasApprovedWord || $hasSuccessCode) && !$hasNegation;
            
            if ($isApproved) {
                $kept[] = $row;
            } else {
                $filtered[] = $row;
            }
        }
        
        $this->data = $kept;
        $this->filteredOutTransactions = array_merge($this->filteredOutTransactions, $filtered);
        
        return $this;
    }
    
    public function filterByTransactionType(): self
    {
        if ($this->tranTypeColumn === null) {
            return $this;
        }

        $kept = [];
        $filtered = [];

        foreach ($this->data as $row) {
            if (!isset($row[$this->tranTypeColumn])) {
                $kept[] = $row;
                continue;
            }

            $tranType = strtoupper(trim($row[$this->tranTypeColumn]));
            if ($tranType !== 'REVERSAL') {
                $kept[] = $row;
            } else {
                $filtered[] = $row;
            }
        }
        
        $this->data = $kept;
        $this->filteredOutTransactions = array_merge($this->filteredOutTransactions, $filtered);
        
        return $this;
    }

    /**
     * Handle duplicate RRNs: INITIAL+REVERSAL pairs removed, multiple INITIALs keep first only
     */
    public function removeDuplicates(): self
    {
        $rrnGroups = [];
        foreach ($this->data as $rowIndex => $row) {
            $ref = isset($row['__normalized_ref']) ? $row['__normalized_ref'] : '';
            if ($ref === '' || $ref === null) {
                continue;
            }
            if (!isset($rrnGroups[$ref])) {
                $rrnGroups[$ref] = [];
            }
            $rrnGroups[$ref][] = ['index' => $rowIndex, 'row' => $row];
        }

        error_log("Total unique RRNs found: " . count($rrnGroups));

        $rowsToFilter = [];
        $duplicateAnalysis = [
            'initial_reversal_pairs' => 0,
            'multiple_initials' => 0,
            'other_duplicates' => 0
        ];

        foreach ($rrnGroups as $ref => $group) {
            $groupSize = count($group);
            if ($groupSize <= 1) {
                continue;
            }

            $hasInitial = false;
            $hasReversal = false;
            $tranTypes = [];

            foreach ($group as $item) {
                $tranType = '';
                if ($this->tranTypeColumn !== null && isset($item['row'][$this->tranTypeColumn])) {
                    $tranType = strtoupper(trim($item['row'][$this->tranTypeColumn]));
                }
                $tranTypes[] = $tranType;

                if ($tranType === 'INITIAL') $hasInitial = true;
                if ($tranType === 'REVERSAL') $hasReversal = true;
            }

            if ($hasInitial && $hasReversal) {
                error_log("RRN $ref: Found INITIAL-REVERSAL pair - removing all instances");
                foreach ($group as $item) {
                    $rowsToFilter[$item['index']] = true;
                }
                $duplicateAnalysis['initial_reversal_pairs']++;
            } elseif ($hasInitial && !$hasReversal) {
                error_log("RRN $ref: Found $groupSize INITIAL transactions - keeping first, removing rest");
                for ($i = 1; $i < $groupSize; $i++) {
                    $rowsToFilter[$group[$i]['index']] = true;
                }
                $duplicateAnalysis['multiple_initials']++;
            } else {
                error_log("RRN $ref: Found duplicates with types [" . implode(', ', $tranTypes) . "] - removing all instances");
                foreach ($group as $item) {
                    $rowsToFilter[$item['index']] = true;
                }
                $duplicateAnalysis['other_duplicates']++;
            }
        }

        error_log("Duplicate Analysis Summary:");
        error_log("  - INITIAL/REVERSAL pairs removed: " . $duplicateAnalysis['initial_reversal_pairs']);
        error_log("  - Multiple INITIALs (kept 1): " . $duplicateAnalysis['multiple_initials']);
        error_log("  - Other duplicates removed: " . $duplicateAnalysis['other_duplicates']);
        error_log("  - Total rows to filter: " . count($rowsToFilter));
        $kept = [];
        $filtered = [];

        foreach ($this->data as $rowIndex => $row) {
            if (isset($rowsToFilter[$rowIndex])) {
                $filtered[] = $row;
            } else {
                $kept[] = $row;
            }
        }

        $this->data = $kept;
        $this->filteredOutTransactions = array_merge($this->filteredOutTransactions, $filtered);

        if (count($filtered) > 0) {
            error_log("Removed " . count($filtered) . " duplicate rows based on smart duplicate handling");
        }

        return $this;
    }
    
    public function sortByRequestDate(): self
    {
        usort($this->data, function($a, $b) {
            $dateA = $a['__parsed_date'] ?? new DateTime('1900-01-01');
            $dateB = $b['__parsed_date'] ?? new DateTime('1900-01-01');
            return $dateA <=> $dateB;
        });
        
        return $this;
    }
    
    public function filterByDateRange(DateTime $startDate, DateTime $endDate): self
    {
        $filteredCount = 0;
        $beforeStart = 0;
        $afterEnd = 0;
        $kept = [];
        $filtered = [];
        
        foreach ($this->data as $row) {
            if (!isset($row[$this->requestDateColumn])) {
                $filtered[] = $row;
                continue;
            }
            
            $rowDate = isset($row['__parsed_date']) ? $row['__parsed_date'] : $this->parseDateTime($row[$this->requestDateColumn]);
            
            // Check if before start
            if ($rowDate < $startDate) {
                $beforeStart++;
                $filtered[] = $row;
                continue;
            }
            
            // Check if after end
            if ($rowDate > $endDate) {
                $afterEnd++;
                $filtered[] = $row;
                $this->transactionsAfterDateRange[] = $row; // Track separately for variance calculation
                continue;
            }
            
            // Within range
            $filteredCount++;
            $kept[] = $row;
        }
        
        $this->data = $kept;
        $this->filteredOutTransactions = array_merge($this->filteredOutTransactions, $filtered);

        error_log("FEP Date Filtering: Kept $filteredCount transactions, excluded $beforeStart before load, $afterEnd after unload");
        
        return $this;
    }
    
    public function calculateTotalAmount(): float
    {
        $total = 0.0;
        
        foreach ($this->data as $row) {
            $total += isset($row['__parsed_amount']) ? $row['__parsed_amount'] : 0.0;
        }
        
        return $total;
    }
    
    public function getTransactionCount(): int
    {
        return count($this->data);
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }


    public function getFilteredOutTransactions(): array
    {
        return $this->filteredOutTransactions;
    }

    /**
     * Get transactions that occur after the date range (after lastUnloadDateTime)
     * These are valid FEP transactions (approved, deduplicated) that occur after cash count
     */
    public function getTransactionsAfterDateRange(): array
    {
        return $this->transactionsAfterDateRange;
    }

    /**
     * Calculate total amount of transactions after the date range
     * Used for variance calculation: Expected_cash = Available_cash - Transactions_after_cash_count
     */
    public function getTransactionsAfterDateRangeTotal(): float
    {
        $total = 0.0;

        foreach ($this->transactionsAfterDateRange as $row) {
            $total += isset($row['__parsed_amount']) ? $row['__parsed_amount'] : 0.0;
        }

        error_log("  FEP Transactions After Date Range Total: " . number_format($total, 2));

        return $total;
    }

    private function parseDateTime(string $dateString): DateTime
    {
        $dateString = trim($dateString);
        
        if (empty($dateString)) {
            return new DateTime('1900-01-01');
        }

        $formats = [
            'd/m/Y g:i A',
            'm/d/Y g:i A',
            'd/m/Y H:i',
            'm/d/Y H:i',
            'Y-m-d H:i:s',
            'd/m/Y H:i:s',
            'm/d/Y H:i:s',
            'd-m-Y H:i:s',
            'Y-m-d H:i',
            'd/m/Y H:i',
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTime($dateString);
        } catch (Exception $e) {
            return new DateTime('1900-01-01');
        }
    }
}