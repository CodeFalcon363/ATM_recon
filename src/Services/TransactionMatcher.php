<?php

namespace App\Services;

use App\Models\TransactionMatch;

/**
 * Matches GL and FEP transactions by RRN with reversal pair detection and nilling logic
 */
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

    /**
     * Match transactions: GL ↔ FEP by RRN, detect reversal pairs to nil duplicates
     */
    public function matchTransactions(): TransactionMatch
    {
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
                $fepMap[$rrn][] = ['rrn' => $rawRrn, 'amount' => $amount, 'date' => $date, 'row' => $row];
            }
        }

        $filteredMap = [];
        $filteredRrnIdx = $this->findColumnIndex($this->fepHeaders, ['retrieval', 'rrn', 'reference']);
        $filteredAmountIdx = $this->findColumnIndex($this->fepHeaders, ['amount']);
        $filteredDateIdx = $this->findColumnIndex($this->fepHeaders, ['request date', 'date']);
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

        $matched = [];
        $finalGlNotOnFep = [];
        $fepNotOnGl = [];
    $glFoundInFilteredFep = [];
    $glFoundInFilteredFepCount = 0;
    $glFoundInFilteredFepTotal = 0.0;
    $nilledGlDuplicates = [];
    $glNotOnFepCreditTotal = 0.0;
    $glNotOnFepDebitTotal = 0.0;

        $descriptionIdx = $this->findColumnIndex($this->glHeaders, ['description', 'narration', 'narrative']);
        $creditIdx = $this->findColumnIndexPreferring($this->glHeaders, ['credit'], ['amount']);
        $debitIdx = $this->findColumnIndexPreferring($this->glHeaders, ['debit'], ['amount']);
        $dateIdx = $this->findColumnIndex($this->glHeaders, ['date', 'transaction_date']);

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
            $gCredit = null;
            $gDebit = null;
            if ($creditIdx !== null && isset($gRow[$creditIdx]) && $gRow[$creditIdx] !== '') {
                $gCredit = $this->parseAmount($gRow[$creditIdx]);
            }
            if ($debitIdx !== null && isset($gRow[$debitIdx]) && $gRow[$debitIdx] !== '') {
                $gDebit = $this->parseAmount($gRow[$debitIdx]);
            }
            $isRev = $this->isReversalTransaction($gDesc);
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
                $filtered = array_pop($filteredMap[$rrn]);
                if (empty($filteredMap[$rrn])) {
                    unset($filteredMap[$rrn]);
                }
                if ($this->debug) {
                    error_log("FOUND IN FILTERED FEP: RRN $rrn exists in filtered-out transactions (Reason: {$filtered['filter_reason']})");
                }
                $glFoundInFilteredFepCount += 1;
                $glFoundInFilteredFepTotal += $amount;
            } else {
                if ($this->debug) {
                    error_log("TRULY GL NOT ON FEP: RRN $rrn not found in included OR filtered-out FEP");
                }
                $shouldNill = false;
                if (isset($glRrnMap[$rrn]) && count($glRrnMap[$rrn]) > 1) {
                    foreach ($glRrnMap[$rrn] as $gEntry) {
                        if (!empty($gEntry['is_reversal'])) {
                            $shouldNill = true;
                            break;
                        }
                    }

                    if (!$shouldNill) {
                        $entries = $glRrnMap[$rrn];
                        $tol = 0.01;

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

                        foreach ($credits as $c) {
                            foreach ($debits as $d) {
                                if (abs($c - $d) < $tol) {
                                    $shouldNill = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                if (!$shouldNill) {
                    $rawRrn = null;
                    foreach ($row as $cell) {
                        if (is_string($cell) && preg_match('/\d{12,}/', $cell, $m)) { $rawRrn = $m[0]; break; }
                        if (is_numeric($cell) && strlen((string)$cell) >= 12) { $rawRrn = (string)$cell; break; }
                    }
                    if ($rawRrn === null) {
                        $rawRrn = $rrn;
                    }

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
                    $creditVal = null;
                    $debitVal = null;
                    if ($creditIdx !== null && isset($row[$creditIdx])) {
                        $creditVal = $this->parseAmount((string)$row[$creditIdx]);
                    }
                    if ($debitIdx !== null && isset($row[$debitIdx])) {
                        $debitVal = $this->parseAmount((string)$row[$debitIdx]);
                    }

                    $tol = 0.005;
                    if ($creditVal !== null && abs($creditVal) > $tol) {
                        $glNotOnFepCreditTotal += $creditVal;
                    } elseif ($debitVal !== null && abs($debitVal) > $tol) {
                        $glNotOnFepDebitTotal += abs($debitVal);
                    } else {
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
                    if (isset($glRrnMap[$rrn])) {
                        foreach ($glRrnMap[$rrn] as $gEntry) {
                            $gRow = $gEntry['row'];
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
                                'amount' => $gAmt,
                                'gl_row' => $gRow,
                                'credit_raw' => ($creditIdx !== null && isset($gRow[$creditIdx])) ? $gRow[$creditIdx] : '',
                                'debit_raw' => ($debitIdx !== null && isset($gRow[$debitIdx])) ? $gRow[$debitIdx] : ''
                            ];
                        }
                    } else {
                        $nilledGlDuplicates[] = [
                            'rrn' => $rrn,
                            'date' => $date,
                            'description' => $description,
                            'amount' => $amount,
                            'gl_row' => $row,
                            'credit_raw' => ($creditIdx !== null && isset($row[$creditIdx])) ? $row[$creditIdx] : '',
                            'debit_raw' => ($debitIdx !== null && isset($row[$debitIdx])) ? $row[$debitIdx] : ''
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
            [],
            $glFoundInFilteredFepCount,
            $glFoundInFilteredFepTotal,
            $this->glHeaders,
            $glNotOnFepCreditTotal,
            $glNotOnFepDebitTotal,
            $this->fepHeaders,
            $nilledGlDuplicates
        );
    }

    private function normalizeRrn(string $rrn): string
    {
        $digits = preg_replace('/\D+/', '', $rrn);
        if ($digits === null || $digits === '') {
            return '';
        }
        if (strlen($digits) > 12) {
            return substr($digits, -12);
        }
        return $digits;
    }

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

    private function extractRrnFromDescriptionOptimized(string $description): ?string
    {
        if (preg_match_all('/\d{12,}/', $description, $matches)) {
            $last = end($matches[0]);
            return substr($last, -12);
        }

        if (preg_match('/\d{12}/', $description, $m)) {
            return $m[0];
        }

        return null;
    }
    
    private function extractGlTransactions(): array
    {
        $transactions = [];

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

            if (empty($description)) {
                continue;
            }

            $isWithdrawal = (
                stripos($description, 'wdl') !== false ||
                stripos($description, 'withdrawal') !== false ||
                stripos($description, 'atm cash') !== false ||
                stripos($description, 'atm') !== false ||
                stripos($description, 'cash') !== false
            );

            if (stripos($description, 'load') !== false ||
                stripos($description, 'unload') !== false) {
                $skippedCount++;
                continue;
            }

            $rrn = $this->extractRrnFromDescription($description);

            if ($rrn === null) {
                if ($isWithdrawal) {
                    error_log("GL Row $rowNum: No RRN found in: " . substr($description, 0, 100));
                    $noRrnCount++;
                }
                continue;
            }

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
        preg_match_all('/\d{12}/', $description, $matches);

        if (empty($matches[0])) {
            $cleaned = preg_replace('/[\s\-\/]/', '', $description);
            preg_match_all('/\d{12}/', $cleaned, $matches);

            if (empty($matches[0])) {
                preg_match_all('/\d{12,}/', $description, $matches);
                if (!empty($matches[0])) {
                    $longest = end($matches[0]);
                    return substr($longest, -12);
                }
                return null;
            }
        }

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

    private function findColumnIndexPreferring(array $headers, array $baseKeywords, array $preferKeywords): ?int
    {
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($baseKeywords as $baseKw) {
                if (strpos($headerLower, strtolower($baseKw)) !== false) {
                    foreach ($preferKeywords as $preferKw) {
                        if (strpos($headerLower, strtolower($preferKw)) !== false) {
                            return $index;
                        }
                    }
                }
            }
        }

        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($baseKeywords as $baseKw) {
                if (strpos($headerLower, strtolower($baseKw)) !== false &&
                    strpos($headerLower, 'count') === false) {
                    return $index;
                }
            }
        }

        return $this->findColumnIndex($headers, $baseKeywords);
    }

    private function parseAmount(string $amount): float
    {
        $amount = preg_replace('/[^0-9.\-]/', '', $amount);
        return (float) $amount;
    }
}