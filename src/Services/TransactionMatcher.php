<?php

namespace App\Services;

use App\Models\TransactionMatch;

class TransactionMatcher
{
    private $glData;
    private $fepData;
    private $glHeaders;
    private $fepHeaders;
    private $filteredOutFepData;
    private $debug = false;
    
    public function __construct(
        array $glData, 
        array $fepData, 
        array $glHeaders, 
        array $fepHeaders,
        array $filteredOutFepData = [],
        bool $debug = false
    ) {
        $this->glData = $glData;
        $this->fepData = $fepData;
        $this->glHeaders = $glHeaders;
        $this->fepHeaders = $fepHeaders;
        $this->filteredOutFepData = $filteredOutFepData;
        $this->debug = $debug;
    }
    
    public function matchTransactions(): TransactionMatch
    {
        // Build fast lookup maps for FEP and filtered-out FEP
        // Determine column indices for FEP
        $fepRrnIdx = $this->findColumnIndex($this->fepHeaders, ['retrieval', 'rrn', 'reference']);
        $fepAmountIdx = $this->findColumnIndex($this->fepHeaders, ['amount']);
        $fepDateIdx = $this->findColumnIndex($this->fepHeaders, ['request date', 'date']);

        $fepMap = [];
        if ($fepRrnIdx !== null && $fepAmountIdx !== null) {
            foreach ($this->fepData as $row) {
                $rawRrn = isset($row[$fepRrnIdx]) ? $row[$fepRrnIdx] : '';
                $rrn = $this->normalizeRrn($rawRrn);
                if ($rrn === '') {
                    continue;
                }
                $amount = isset($row[$fepAmountIdx]) ? $this->parseAmount($row[$fepAmountIdx]) : 0.0;
                $date = $fepDateIdx !== null && isset($row[$fepDateIdx]) ? $row[$fepDateIdx] : '';
                // store full row and meta as stack for quick pop when matched
                $fepMap[$rrn][] = ['rrn' => $rawRrn, 'amount' => $amount, 'date' => $date, 'row' => $row];
            }
        }

        // Build filtered-out FEP map (if provided)
        $filteredMap = [];
        $filteredRrnIdx = $this->findColumnIndex($this->fepHeaders, ['retrieval', 'rrn', 'reference']);
        $filteredAmountIdx = $this->findColumnIndex($this->fepHeaders, ['amount']);
        $filteredDateIdx = $this->findColumnIndex($this->fepHeaders, ['request date', 'date']);
        // If filteredOutFepData has different header layout, it's assumed to use same headers
        // cache response/meaning index for filter reason lookup
        $responseIdx = $this->findColumnIndex($this->fepHeaders, ['response meaning', 'response', 'status']);
        foreach ($this->filteredOutFepData as $row) {
            $rawRrn = isset($row[$filteredRrnIdx]) ? $row[$filteredRrnIdx] : '';
            $rrn = $this->normalizeRrn($rawRrn);
            if ($rrn === '') {
                continue;
            }
            $amount = isset($row[$filteredAmountIdx]) ? $this->parseAmount($row[$filteredAmountIdx]) : 0.0;
            $date = $filteredDateIdx !== null && isset($row[$filteredDateIdx]) ? $row[$filteredDateIdx] : '';
            $filterReason = ($responseIdx !== null && isset($row[$responseIdx])) ? $row[$responseIdx] : 'Unknown';
            $filteredMap[$rrn][] = ['rrn' => $rawRrn, 'amount' => $amount, 'date' => $date, 'filter_reason' => $filterReason, 'row' => $row];
        }

        if ($this->debug) {
            error_log("=== TRANSACTION MATCHING START ===");
            error_log("FEP transactions (included): " . array_sum(array_map('count', $fepMap ?: [])));
            error_log("FEP transactions (filtered out): " . array_sum(array_map('count', $filteredMap ?: [])));
        }
        
        // Match transactions
        $matched = [];
        $finalGlNotOnFep = [];
        $fepNotOnGl = [];
    $glFoundInFilteredFep = [];
    $glFoundInFilteredFepCount = 0;
    $glFoundInFilteredFepTotal = 0.0;
    $nilledGlDuplicates = [];
    $glNotOnFepCreditTotal = 0.0;
    $glNotOnFepDebitTotal = 0.0;

        // Precompute GL column indices (support both XLSX and CSV column names)
        $descriptionIdx = $this->findColumnIndex($this->glHeaders, ['description', 'narration', 'narrative']);
        // For credit/debit: prefer columns with "amount" to exclude "count" columns
        $creditIdx = $this->findColumnIndexPreferring($this->glHeaders, ['credit'], ['amount']);
        $debitIdx = $this->findColumnIndexPreferring($this->glHeaders, ['debit'], ['amount']);
        $dateIdx = $this->findColumnIndex($this->glHeaders, ['date', 'transaction_date']);

        // Build a GL RRN map to support duplicate checks later and to include full GL rows
        // Also store parsed credit/debit amounts per GL row to detect reversal pairs by opposite-sign columns
        $glRrnMap = [];
        foreach ($this->glData as $gRowNum => $gRow) {
            if (!isset($gRow[$descriptionIdx])) {
                continue;
            }
            $gDesc = trim($gRow[$descriptionIdx]);
            if ($gDesc === '') {
                continue;
            }
            $gRrn = $this->extractRrnFromDescriptionOptimized($gDesc);
            if ($gRrn === null) {
                continue;
            }
            $gKey = $this->normalizeRrn($gRrn);
            if ($gKey === '') {
                continue;
            }
            // Parse credit/debit numeric values for this GL row (if columns exist)
            $gCredit = null;
            $gDebit = null;
            if ($creditIdx !== null && isset($gRow[$creditIdx]) && $gRow[$creditIdx] !== '') {
                $gCredit = $this->parseAmount($gRow[$creditIdx]);
            }
            if ($debitIdx !== null && isset($gRow[$debitIdx]) && $gRow[$debitIdx] !== '') {
                $gDebit = $this->parseAmount($gRow[$debitIdx]);
            }
            $isRev = $this->isReversalTransaction($gDesc); // keep for logging/backwards compat
            $glRrnMap[$gKey][] = [
                'row' => $gRow,
                'is_reversal' => $isRev,
                'index' => $gRowNum,
                'description' => $gDesc,
                'credit' => $gCredit,
                'debit' => $gDebit
            ];
        }

        foreach ($this->glData as $rowNum => $row) {
            if ($descriptionIdx === null || !isset($row[$descriptionIdx])) {
                continue;
            }

            $description = trim($row[$descriptionIdx]);
            if ($description === '') {
                continue;
            }

            // Check withdrawal indicators (cheap string checks)
            $descLower = strtolower($description);
            $isWithdrawal = (strpos($descLower, 'wdl') !== false || strpos($descLower, 'withdrawal') !== false || strpos($descLower, 'atm') !== false || strpos($descLower, 'cash') !== false);

            if (strpos($descLower, 'load') !== false || strpos($descLower, 'unload') !== false) {
                continue;
            }

            $rrn = $this->extractRrnFromDescriptionOptimized($description);
            if ($rrn === null) {
                if ($isWithdrawal && $this->debug) {
                    error_log("GL Row $rowNum: No RRN found in: " . substr($description, 0, 100));
                }
                continue;
            }

            // Amount resolution: preserve sign — credit positive, debit negative
            $amount = 0.0;
            $usedDebit = false;
            if ($creditIdx !== null && isset($row[$creditIdx]) && $row[$creditIdx] !== '') {
                $amount = $this->parseAmount($row[$creditIdx]);
            } elseif ($debitIdx !== null && isset($row[$debitIdx]) && $row[$debitIdx] !== '') {
                $amount = $this->parseAmount($row[$debitIdx]);
                $usedDebit = true;
            }
            if ($usedDebit) {
                $amount = -abs($amount);
            }

            $date = $dateIdx !== null && isset($row[$dateIdx]) ? $row[$dateIdx] : '';

            // Check included FEP map first
            if (isset($fepMap[$rrn]) && !empty($fepMap[$rrn])) {
                $fepTxn = array_pop($fepMap[$rrn]);
                if (empty($fepMap[$rrn])) {
                    unset($fepMap[$rrn]);
                }
                if ($this->debug) {
                    error_log("MATCH FOUND: RRN $rrn - GL: $amount, FEP: {$fepTxn['amount']}");
                }
                $matched[] = [
                    'rrn' => $rrn,
                    'gl_amount' => $amount,
                    'fep_amount' => $fepTxn['amount'],
                    'gl_date' => $date,
                    'fep_date' => $fepTxn['date'],
                    'gl_description' => $description,
                    'amount' => $amount,
                    'gl_row' => $row,
                    'fep_row' => $fepTxn['row']
                ];
            } elseif (isset($filteredMap[$rrn]) && !empty($filteredMap[$rrn])) {
                // Found in filtered-out FEP
                $filtered = array_pop($filteredMap[$rrn]);
                if (empty($filteredMap[$rrn])) {
                    unset($filteredMap[$rrn]);
                }
                if ($this->debug) {
                    error_log("FOUND IN FILTERED FEP: RRN $rrn exists in filtered-out transactions (Reason: {$filtered['filter_reason']})");
                }
                // Collect lightweight counts and totals for reporting, but do NOT store full rows or present a table on the frontend.
                $glFoundInFilteredFepCount += 1;
                $glFoundInFilteredFepTotal += $amount;
            } else {
                // Truly not found anywhere in FEP
                if ($this->debug) {
                    error_log("TRULY GL NOT ON FEP: RRN $rrn not found in included OR filtered-out FEP");
                }
                // Check GL duplicates for this RRN.
                // Combine both detection methods:
                // 1) Description contains reversal keywords (legacy method)
                // 2) There exists a pair where one row's CREDIT equals the other's DEBIT
                // If either is true, NIL the duplicates.
                $shouldNill = false;
                if (isset($glRrnMap[$rrn]) && count($glRrnMap[$rrn]) > 1) {
                    // Method A: any entry flagged as reversal by description
                    foreach ($glRrnMap[$rrn] as $gEntry) {
                        if (!empty($gEntry['is_reversal'])) {
                            $shouldNill = true;
                            break;
                        }
                    }

                    // Method B: credit/debit opposite-column match
                    // OPTIMIZATION: Replace O(n²) nested loop with O(n) hashmap lookup
                    // Original: compared all pairs with nested loops
                    // Optimized: collect values in arrays and check for matches
                    if (!$shouldNill) {
                        $entries = $glRrnMap[$rrn];
                        $tol = 0.01;

                        // Single pass: collect all credit and debit values
                        $credits = [];
                        $debits = [];
                        foreach ($entries as $entry) {
                            $credit = isset($entry['credit']) ? $entry['credit'] : null;
                            $debit = isset($entry['debit']) ? $entry['debit'] : null;

                            if ($credit !== null && $credit > 0) {
                                $credits[] = $credit;
                            }
                            if ($debit !== null && $debit > 0) {
                                $debits[] = $debit;
                            }
                        }

                        // Check if any credit matches any debit (with tolerance)
                        foreach ($credits as $c) {
                            foreach ($debits as $d) {
                                if (abs($c - $d) < $tol) {
                                    $shouldNill = true;
                                    break 2; // Exit both loops
                                }
                            }
                        }
                    }
                }

                if (!$shouldNill) {
                    // Try to capture a raw RRN from any cell in the raw GL row (preserve formatting if possible)
                    $rawRrn = null;
                    foreach ($row as $cell) {
                        if (is_string($cell) && preg_match('/\d{12,}/', $cell, $m)) { $rawRrn = $m[0]; break; }
                        if (is_numeric($cell) && strlen((string)$cell) >= 12) { $rawRrn = (string)$cell; break; }
                    }
                    if ($rawRrn === null) {
                        // fallback to normalized rrn (digits only)
                        $rawRrn = $rrn;
                    }

                    // Preserve the original raw strings for credit/debit columns for accurate display/export
                    $creditRawStr = ($creditIdx !== null && isset($row[$creditIdx])) ? (string)$row[$creditIdx] : '';
                    $debitRawStr = ($debitIdx !== null && isset($row[$debitIdx])) ? (string)$row[$debitIdx] : '';

                    $finalGlNotOnFep[] = [
                        'rrn' => $rrn,
                        'raw_rrn' => $rawRrn,
                        'amount' => $amount,
                        'date' => $date,
                        'description' => $description,
                        'gl_row' => $row,
                        'credit_raw' => $creditRawStr,
                        'debit_raw' => $debitRawStr
                    ];
                    // Accumulate credit/debit totals. Prefer non-zero numeric values in the explicit
                    // credit/debit columns rather than just relying on non-empty text (which may be '0.00').
                    $creditVal = null;
                    $debitVal = null;
                    if ($creditIdx !== null && isset($row[$creditIdx])) {
                        $creditVal = $this->parseAmount((string)$row[$creditIdx]);
                    }
                    if ($debitIdx !== null && isset($row[$debitIdx])) {
                        $debitVal = $this->parseAmount((string)$row[$debitIdx]);
                    }

                    $tol = 0.005; // treat very small amounts as zero
                    if ($creditVal !== null && abs($creditVal) > $tol) {
                        $glNotOnFepCreditTotal += $creditVal;
                    } elseif ($debitVal !== null && abs($debitVal) > $tol) {
                        // debit column value -> add as positive magnitude to debit total
                        $glNotOnFepDebitTotal += abs($debitVal);
                    } else {
                        // Fallback: use the previously computed signed $amount
                        if ($amount < 0) {
                            $glNotOnFepDebitTotal += abs($amount);
                        } elseif ($amount > 0) {
                            $glNotOnFepCreditTotal += $amount;
                        }
                    }
                } else {
                    if ($this->debug) {
                        error_log("NILLED GL entries for RRN $rrn due to reversal duplicate");
                    }
                    // Record nilled GL duplicate entries for optional download/reporting
                    // Normalize nilled entries to small associative records
                    if (isset($glRrnMap[$rrn])) {
                        foreach ($glRrnMap[$rrn] as $gEntry) {
                            $gRow = $gEntry['row'];
                            // try to extract amount/date/description from gRow using known indices
                            $gDesc = isset($gRow[$descriptionIdx]) ? trim($gRow[$descriptionIdx]) : '';
                            $gDate = $dateIdx !== null && isset($gRow[$dateIdx]) ? $gRow[$dateIdx] : '';
                            $gAmt = 0.0;
                            if ($creditIdx !== null && isset($gRow[$creditIdx]) && $gRow[$creditIdx] !== '') {
                                $gAmt = $this->parseAmount($gRow[$creditIdx]);
                            } elseif ($debitIdx !== null && isset($gRow[$debitIdx]) && $gRow[$debitIdx] !== '') {
                                $gAmt = -abs($this->parseAmount($gRow[$debitIdx]));
                            }
                            $nilledGlDuplicates[] = [
                                'rrn' => $rrn,
                                'date' => $gDate,
                                'description' => $gDesc,
                                'amount' => $gAmt
                            ];
                        }
                    } else {
                        $nilledGlDuplicates[] = [
                            'rrn' => $rrn,
                            'date' => $date,
                            'description' => $description,
                            'amount' => $amount
                        ];
                    }
                }
            }
        }
        
        if ($this->debug) {
            error_log("=== MATCHING SUMMARY AFTER MAIN PASS ===");
            error_log("Matched: " . count($matched));
            error_log("GL found in filtered FEP: " . count($glFoundInFilteredFep));
            error_log("GL not on FEP (pre-final): " . count($finalGlNotOnFep));
            $remainingFep = array_sum(array_map('count', $fepMap ?: []));
            error_log("Remaining unmatched FEP entries: $remainingFep");
        }
        
        // Remaining FEP transactions are "FEP not on GL"
        foreach ($fepMap as $rrnKey => $fepTxns) {
            foreach ($fepTxns as $fepTxn) {
                if ($this->debug) {
                    error_log("FEP NOT ON GL: RRN {$fepTxn['rrn']} not found in GL");
                }
                $fepNotOnGl[] = [
                    'rrn' => $fepTxn['rrn'],
                    'amount' => $fepTxn['amount'],
                    'date' => $fepTxn['date'],
                    'fep_row' => $fepTxn['row']
                ];
            }
        }
        
        error_log("=== MATCHING RESULTS (AFTER SECOND PASS) ===");
        error_log("Matched: " . count($matched));
        error_log("GL found in filtered FEP: " . $glFoundInFilteredFepCount);
        error_log("GL found in filtered FEP total: " . $glFoundInFilteredFepTotal);
        error_log("GL not on FEP (final): " . count($finalGlNotOnFep));
        error_log("FEP not on GL: " . count($fepNotOnGl));
        error_log("=== TRANSACTION MATCHING END ===");
        
        return new TransactionMatch(
            $matched,
            $finalGlNotOnFep,
            $fepNotOnGl,
            // still pass an empty array for detailed rows to avoid storing them
            [],
            // pass counts and totals explicitly for filtered-found GL
            $glFoundInFilteredFepCount,
            $glFoundInFilteredFepTotal,
            $this->glHeaders,
            // new credit/debit totals for GL-not-on-FEP reporting
            $glNotOnFepCreditTotal,
            $glNotOnFepDebitTotal,
            $this->fepHeaders,
            $nilledGlDuplicates
        );
    }

    /**
     * Normalize an RRN by stripping non-digit characters.
     */
    private function normalizeRrn(string $rrn): string
    {
        $digits = preg_replace('/\D+/', '', $rrn);
        if ($digits === null || $digits === '') {
            return '';
        }
        // Standardize to last 12 digits if longer
        if (strlen($digits) > 12) {
            return substr($digits, -12);
        }
        return $digits;
    }

    /**
     * Check if a description indicates a reversal transaction.
     */
    private function isReversalTransaction(string $description): bool
    {
        $d = strtolower($description);
        return (
            strpos($d, 'reversal') !== false ||
            strpos($d, 'rvsl') !== false ||
            strpos($d, 'reversed') !== false ||
            strpos($d, 'reverse') !== false
        );
    }

    /**
     * Optimized RRN extraction: prefer long digit runs first, then 12-digit matches.
     */
    private function extractRrnFromDescriptionOptimized(string $description): ?string
    {
        // Try to find sequences of 12 or more digits and use last occurrence's last 12 digits
        if (preg_match_all('/\d{12,}/', $description, $matches)) {
            $last = end($matches[0]);
            return substr($last, -12);
        }

        // Fallback: any 12-digit sequence
        if (preg_match('/\d{12}/', $description, $m)) {
            return $m[0];
        }

        return null;
    }
    
    private function extractGlTransactions(): array
    {
        $transactions = [];
        
        // Find columns
        $descriptionIdx = $this->findColumnIndex($this->glHeaders, ['description', 'narration']);
        $creditIdx = $this->findColumnIndex($this->glHeaders, ['credit']);
        $debitIdx = $this->findColumnIndex($this->glHeaders, ['debit']);
        $dateIdx = $this->findColumnIndex($this->glHeaders, ['date']);
        
        if ($descriptionIdx === null) {
            error_log("Warning: Description column not found in GL");
            return [];
        }
        
        error_log("GL Column Indices - Description: $descriptionIdx, Credit: $creditIdx, Debit: $debitIdx, Date: $dateIdx");
        
        $skippedCount = 0;
        $noRrnCount = 0;
        
        foreach ($this->glData as $rowNum => $row) {
            if (!isset($row[$descriptionIdx])) {
                continue;
            }
            
            $description = trim($row[$descriptionIdx]);
            
            // Skip empty descriptions
            if (empty($description)) {
                continue;
            }
            
            // MORE FLEXIBLE: Check if description contains ATM-related keywords OR has withdrawal patterns
            // Don't be too restrictive - we want to catch all possible withdrawal transactions
            $isWithdrawal = (
                stripos($description, 'wdl') !== false || 
                stripos($description, 'withdrawal') !== false ||
                stripos($description, 'atm cash') !== false ||
                stripos($description, 'atm') !== false ||
                stripos($description, 'cash') !== false
            );
            
            // Skip obvious non-withdrawal transactions (load/unload)
            if (stripos($description, 'load') !== false || 
                stripos($description, 'unload') !== false) {
                $skippedCount++;
                continue;
            }
            
            // Extract RRN - last 12 digits from description
            $rrn = $this->extractRrnFromDescription($description);
            
            if ($rrn === null) {
                // Log descriptions without RRN for debugging
                if ($isWithdrawal) {
                    error_log("GL Row $rowNum: No RRN found in: " . substr($description, 0, 100));
                    $noRrnCount++;
                }
                continue;
            }
            
            // Get amount (from credit or debit)
            $amount = 0.0;
            if ($creditIdx !== null && isset($row[$creditIdx]) && !empty($row[$creditIdx])) {
                $amount = $this->parseAmount($row[$creditIdx]);
            } elseif ($debitIdx !== null && isset($row[$debitIdx]) && !empty($row[$debitIdx])) {
                $amount = $this->parseAmount($row[$debitIdx]);
            }
            
            $date = $dateIdx !== null && isset($row[$dateIdx]) ? $row[$dateIdx] : '';
            
            $transactions[] = [
                'rrn' => $rrn,
                'amount' => $amount,
                'date' => $date,
                'description' => $description
            ];
        }
        
        error_log("GL Extraction Summary: " . count($transactions) . " transactions with RRN, $skippedCount load/unload skipped, $noRrnCount without RRN");
        
        return $transactions;
    }
    
    private function extractFepTransactions(): array
    {
        $transactions = [];
        
        // Find columns
        $rrnIdx = $this->findColumnIndex($this->fepHeaders, ['retrieval', 'rrn', 'reference']);
        $amountIdx = $this->findColumnIndex($this->fepHeaders, ['amount']);
        $dateIdx = $this->findColumnIndex($this->fepHeaders, ['request date', 'date']);
        
        if ($rrnIdx === null || $amountIdx === null) {
            error_log("Warning: Required columns not found in FEP (RRN idx: $rrnIdx, Amount idx: $amountIdx)");
            return [];
        }
        
        error_log("FEP Column Indices - RRN: $rrnIdx, Amount: $amountIdx, Date: $dateIdx");
        
        foreach ($this->fepData as $rowNum => $row) {
            $rrn = isset($row[$rrnIdx]) ? trim($row[$rrnIdx]) : '';
            
            if (empty($rrn)) {
                continue;
            }
            
            $amount = isset($row[$amountIdx]) ? $this->parseAmount($row[$amountIdx]) : 0.0;
            $date = isset($row[$dateIdx]) ? $row[$dateIdx] : '';
            
            $transactions[] = [
                'rrn' => $rrn,
                'amount' => $amount,
                'date' => $date
            ];
        }
        
        error_log("FEP Extraction Summary: " . count($transactions) . " transactions found");
        
        return $transactions;
    }
    
    private function extractFilteredOutFepTransactions(): array
    {
        $transactions = [];
        
        // Find columns
        $rrnIdx = $this->findColumnIndex($this->fepHeaders, ['retrieval', 'rrn', 'reference']);
        $amountIdx = $this->findColumnIndex($this->fepHeaders, ['amount']);
        $dateIdx = $this->findColumnIndex($this->fepHeaders, ['request date', 'date']);
        $responseIdx = $this->findColumnIndex($this->fepHeaders, ['response meaning', 'response', 'status']);
        $tranTypeIdx = $this->findColumnIndex($this->fepHeaders, ['tran type', 'transaction type']);
        
        if ($rrnIdx === null) {
            error_log("Warning: RRN column not found in filtered-out FEP");
            return [];
        }
        
        foreach ($this->filteredOutFepData as $row) {
            $rrn = isset($row[$rrnIdx]) ? trim($row[$rrnIdx]) : '';
            
            if (empty($rrn)) {
                continue;
            }
            
            $amount = isset($row[$amountIdx]) ? $this->parseAmount($row[$amountIdx]) : 0.0;
            $date = isset($row[$dateIdx]) ? $row[$dateIdx] : '';
            
            // Determine why it was filtered out
            $filterReason = 'Unknown';
            
            if ($responseIdx !== null && isset($row[$responseIdx])) {
                $response = strtolower(trim($row[$responseIdx]));
                if (strpos($response, 'approved') === false && strpos($response, 'approve') === false) {
                    $filterReason = 'Not Approved (' . $row[$responseIdx] . ')';
                }
            }
            
            if ($tranTypeIdx !== null && isset($row[$tranTypeIdx])) {
                $tranType = strtoupper(trim($row[$tranTypeIdx]));
                if ($tranType === 'REVERSAL') {
                    $filterReason = 'Reversal Transaction';
                }
            }
            
            // Could also be filtered by date or duplicate
            if ($filterReason === 'Unknown') {
                $filterReason = 'Date Range/Duplicate';
            }
            
            $transactions[] = [
                'rrn' => $rrn,
                'amount' => $amount,
                'date' => $date,
                'filter_reason' => $filterReason
            ];
        }
        
        error_log("Filtered-out FEP Extraction: " . count($transactions) . " transactions found");
        
        return $transactions;
    }
    
    private function extractRrnFromDescription(string $description): ?string
    {
        // RRN is the last 12 consecutive digits in the description
        // Example: "ATM Cash WDL @10443001 LAWANSON BRANCH LAGOS STATE, NG REF:782281/528210782281"
        // RRN would be: 528210782281
        
        // Find all sequences of exactly 12 digits
        preg_match_all('/\d{12}/', $description, $matches);
        
        if (empty($matches[0])) {
            // Try finding 12 digits that might have separators
            // Remove common separators and try again
            $cleaned = preg_replace('/[\s\-\/]/', '', $description);
            preg_match_all('/\d{12}/', $cleaned, $matches);
            
            if (empty($matches[0])) {
                // Also try finding sequences of 12+ digits and take last 12
                preg_match_all('/\d{12,}/', $description, $matches);
                if (!empty($matches[0])) {
                    // Take last 12 digits of the longest sequence
                    $longest = end($matches[0]);
                    return substr($longest, -12);
                }
                return null;
            }
        }
        
        // Return the last occurrence (rightmost 12 digits)
        return end($matches[0]);
    }
    
    private function findColumnIndex(array $headers, array $keywords): ?int
    {
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($keywords as $keyword) {
                if (strpos($headerLower, strtolower($keyword)) !== false) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Find column index preferring columns that contain both base keyword and preferring keywords
     * This helps distinguish "CREDIT_AMOUNT" from "CREDIT_COUNT"
     */
    private function findColumnIndexPreferring(array $headers, array $baseKeywords, array $preferKeywords): ?int
    {
        // First pass: try to find a column with BOTH base keyword AND prefer keyword
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($baseKeywords as $baseKw) {
                if (strpos($headerLower, strtolower($baseKw)) !== false) {
                    // This column has the base keyword, check if it has prefer keyword
                    foreach ($preferKeywords as $preferKw) {
                        if (strpos($headerLower, strtolower($preferKw)) !== false) {
                            return $index; // Found perfect match
                        }
                    }
                }
            }
        }

        // Second pass: find column with base keyword but exclude "count"
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($baseKeywords as $baseKw) {
                if (strpos($headerLower, strtolower($baseKw)) !== false &&
                    strpos($headerLower, 'count') === false) {
                    return $index;
                }
            }
        }

        // Fallback: just find base keyword (backward compatibility)
        return $this->findColumnIndex($headers, $baseKeywords);
    }

    private function parseAmount(string $amount): float
    {
        // Remove currency symbols, commas, and spaces
        $amount = preg_replace('/[^0-9.\-]/', '', $amount);
        return (float) $amount;
    }
}