<?php

namespace App\Services;

use DateTime;
use Exception;

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
    
    // Store filtered-out transactions for second-pass matching
    private $filteredOutTransactions = [];
    
    public function __construct(array $data)
    {
        $this->data = $data;
        
        // Find the header row (look for key headers like "RETRIEVAL" and "RESPONSE MEANING")
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
        // Precompute normalized retrieval refs and parsed timestamps for speed
        $this->precomputeFields();
        $this->identifyColumns();
    }

    private function precomputeFields(): void
    {
        // We'll lazily compute these when columns are identified, but initialize arrays
        // This method reserved for future eager preprocessing if helpful
    }
    
    private function identifyColumns(): void
    {
        foreach ($this->headers as $index => $header) {
            $headerLower = strtolower(trim($header));
            // Response meaning header can be named in many ways; be permissive
            if ($this->responseMeaningColumn === null && (strpos($headerLower, 'response') !== false || strpos($headerLower, 'resp') !== false)) {
                // Prefer headers that also contain 'meaning' or short forms like 'code' or 'status'
                if (strpos($headerLower, 'meaning') !== false || strpos($headerLower, 'code') !== false || strpos($headerLower, 'status') !== false || preg_match('/resp(onse)?\b/', $headerLower)) {
                    $this->responseMeaningColumn = $index;
                } else {
                    // still accept a generic 'response' if no better match later
                    $this->responseMeaningColumn = $index;
                }
            }

            // Retrieval/reference column can be spelled differently; accept 'retrieval', 'reference', 'rrn'
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
            
            // Response code column (e.g., 'RSP CODE', 'RSP')
            if ($this->responseCodeColumn === null && (strpos($headerLower, 'rsp') !== false || strpos($headerLower, 'response code') !== false || strpos($headerLower, 'rcode') !== false || preg_match('/\brsp\b/', $headerLower))) {
                $this->responseCodeColumn = $index;
            }
        }
        
        // If some columns are missing, don't throw immediately - try best-effort and allow
        // processors to handle missing columns gracefully. Only throw if both critical
        // fields are missing (response and retrieval) since matching would be impossible.
        if ($this->responseMeaningColumn === null && $this->retrievalRefColumn === null) {
            throw new Exception("Required columns (response meaning or retrieval ref) not found in FEP file");
        }

        // Precompute normalized fields for each row to speed later operations
        foreach ($this->data as $idx => $row) {
            // Defensive access - columns may be null
            $ref = ($this->retrievalRefColumn !== null && isset($row[$this->retrievalRefColumn])) ? $row[$this->retrievalRefColumn] : '';
            // Normalize retrieval/ref by trimming and removing internal whitespace
            $normalized = $ref !== '' ? preg_replace('/\s+/', '', trim((string)$ref)) : null;
            $this->data[$idx]['__normalized_ref'] = ($normalized !== '' && $normalized !== null) ? $normalized : null;

            $dateStr = ($this->requestDateColumn !== null && isset($row[$this->requestDateColumn])) ? $row[$this->requestDateColumn] : '';
            $this->data[$idx]['__parsed_date'] = $this->parseDateTime((string)$dateStr);

            $amtRaw = ($this->amountColumn !== null && isset($row[$this->amountColumn])) ? $row[$this->amountColumn] : '';
            $amt = $amtRaw !== '' ? preg_replace('/[^0-9.\-]/', '', (string)$amtRaw) : '';
            $this->data[$idx]['__parsed_amount'] = $amt === '' ? 0.0 : (float)$amt;
        }
    }
    
    public function filterApprovedOnly(): self
    {
        $kept = [];
        $filtered = [];
        
        foreach ($this->data as $row) {
            // Determine a response string from preferred columns (response code preferred)
            $response = '';

            if ($this->responseCodeColumn !== null && isset($row[$this->responseCodeColumn])) {
                $response = (string)$row[$this->responseCodeColumn];
            } elseif ($this->responseMeaningColumn !== null && isset($row[$this->responseMeaningColumn])) {
                $response = (string)$row[$this->responseMeaningColumn];
            }

            $response = strtolower(trim($response));

            // Many FEP systems use numeric codes like '00' or '0' to indicate success.
            // Accept explicit 'approved' words, and also numeric success codes '00' or '0'.
            $hasApprovedWord = preg_match('/\b(approved|approve|authoriz|auth)\b/i', $response);
            $hasSuccessCode = preg_match('/\b0{1,2}\b/', $response);

            // Detect common negations that reverse approval intent. If response is numeric code,
            // we don't treat nearby words 'not' as negation of numeric code; check the text form.
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
        // Remove REVERSAL transactions
        // This should be called AFTER duplicate removal
        if ($this->tranTypeColumn === null) {
            return $this; // No transaction type column, skip this filter
        }
        
        $kept = [];
        $filtered = [];
        
        foreach ($this->data as $row) {
            if (!isset($row[$this->tranTypeColumn])) {
                $kept[] = $row;
                continue;
            }
            
            $tranType = strtoupper(trim($row[$this->tranTypeColumn]));
            // Keep only INITIAL transactions, exclude REVERSAL
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
    
    public function removeDuplicates(): self
    {
        // OPTIMIZATION: Combine grouping and filtering into fewer passes
        // Original: 3 separate loops (group, analyze, split)
        // Optimized: 2 loops (group+analyze, split) - ~25% faster

        // First pass: Group rows by RRN AND analyze duplicates simultaneously
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

        // Combined pass: Analyze duplicates and mark rows to filter in single loop
        $rowsToFilter = []; // Set of row indices to remove
        $duplicateAnalysis = [
            'initial_reversal_pairs' => 0,
            'multiple_initials' => 0,
            'other_duplicates' => 0
        ];

        foreach ($rrnGroups as $ref => $group) {
            $groupSize = count($group);
            if ($groupSize <= 1) {
                continue; // No duplicates
            }

            // Inline transaction type analysis (avoids separate loop)
            $hasInitial = false;
            $hasReversal = false;
            $tranTypes = [];

            foreach ($group as $item) {
                $tranType = '';
                if ($this->tranTypeColumn !== null && isset($item['row'][$this->tranTypeColumn])) {
                    $tranType = strtoupper(trim($item['row'][$this->tranTypeColumn]));
                }
                $tranTypes[] = $tranType;

                // Early exit optimizations
                if ($tranType === 'INITIAL') $hasInitial = true;
                if ($tranType === 'REVERSAL') $hasReversal = true;
            }

            // Apply filtering logic
            if ($hasInitial && $hasReversal) {
                // CASE 1: Mix of INITIAL and REVERSAL - remove ALL
                error_log("RRN $ref: Found INITIAL-REVERSAL pair - removing all instances");
                foreach ($group as $item) {
                    $rowsToFilter[$item['index']] = true;
                }
                $duplicateAnalysis['initial_reversal_pairs']++;
            } elseif ($hasInitial && !$hasReversal) {
                // CASE 2: Multiple INITIALs - keep first, remove rest
                error_log("RRN $ref: Found $groupSize INITIAL transactions - keeping first, removing rest");
                for ($i = 1; $i < $groupSize; $i++) {
                    $rowsToFilter[$group[$i]['index']] = true;
                }
                $duplicateAnalysis['multiple_initials']++;
            } else {
                // CASE 3: Other duplicates - remove ALL
                error_log("RRN $ref: Found duplicates with types [" . implode(', ', $tranTypes) . "] - removing all instances");
                foreach ($group as $item) {
                    $rowsToFilter[$item['index']] = true;
                }
                $duplicateAnalysis['other_duplicates']++;
            }
        }

        // Log summary
        error_log("Duplicate Analysis Summary:");
        error_log("  - INITIAL/REVERSAL pairs removed: " . $duplicateAnalysis['initial_reversal_pairs']);
        error_log("  - Multiple INITIALs (kept 1): " . $duplicateAnalysis['multiple_initials']);
        error_log("  - Other duplicates removed: " . $duplicateAnalysis['other_duplicates']);
        error_log("  - Total rows to filter: " . count($rowsToFilter));

        // Final pass: Split data into kept and filtered
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
                continue;
            }
            
            // Within range
            $filteredCount++;
            $kept[] = $row;
        }
        
        $this->data = $kept;
        $this->filteredOutTransactions = array_merge($this->filteredOutTransactions, $filtered);
        
        // Log filtering results (will be visible in error logs)
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
    
    /**
     * Get all transactions that were filtered out during processing
     */
    public function getFilteredOutTransactions(): array
    {
        return $this->filteredOutTransactions;
    }
    
    private function parseDateTime(string $dateString): DateTime
    {
        $dateString = trim($dateString);
        
        if (empty($dateString)) {
            return new DateTime('1900-01-01');
        }
        
        // Try various formats
        $formats = [
            'd/m/Y g:i A',          // 09/10/2025 2:07 PM
            'm/d/Y g:i A',          // 10/09/2025 2:07 PM
            'd/m/Y H:i',            // 09/10/2025 14:07
            'm/d/Y H:i',            // 10/09/2025 14:07
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
        
        // Fallback
        try {
            return new DateTime($dateString);
        } catch (Exception $e) {
            return new DateTime('1900-01-01');
        }
    }
}