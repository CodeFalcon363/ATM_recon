<?php

namespace App\Services;

use App\Models\LoadUnloadData;
use DateTime;
use Exception;

class GLProcessor
{
    private $data;
    private $headers;
    private $descriptionColumn;
    private $creditColumn;
    private $debitColumn;
    private $dateColumn;
    
    public function __construct(array $data)
    {
        $this->data = $data;
        
        // Find the header row (look for "DESCRIPTION" or similar key headers)
        $headerRow = 0;
        foreach ($data as $index => $row) {
            $rowStr = strtolower(implode('', $row));
            if (strpos($rowStr, 'description') !== false && 
                strpos($rowStr, 'credit') !== false && 
                strpos($rowStr, 'debit') !== false) {
                $headerRow = $index;
                break;
            }
        }
        
        // Normalize header row and data rows to numeric-indexed arrays
        $this->headers = isset($data[$headerRow]) ? array_values((array)$data[$headerRow]) : [];
        $this->data = array_values(array_slice($data, $headerRow + 1));
        $this->identifyColumns();
    }
    
    private function identifyColumns(): void
    {
        // Find Description, Credit, Debit, and Date columns
        foreach ($this->headers as $index => $header) {
            $headerLower = strtolower(trim($header));
            
            if (strpos($headerLower, 'description') !== false || 
                strpos($headerLower, 'narration') !== false) {
                $this->descriptionColumn = $index;
            }
            
            if (strpos($headerLower, 'credit') !== false) {
                $this->creditColumn = $index;
            }
            
            if (strpos($headerLower, 'debit') !== false) {
                $this->debitColumn = $index;
            }
            
            if (strpos($headerLower, 'date') !== false) {
                $this->dateColumn = $index;
            }
        }
        
        if ($this->descriptionColumn === null) {
            throw new Exception("Description column not found in GL file");
        }
    }
    
    public function extractLoadUnloadData(): LoadUnloadData
    {
        $loads = [];
        $unloads = [];
        $loadReversals = [];
        $unloadReversals = [];
        
        // First pass: collect ALL load and unload entries (including reversals)
        // We'll also compute running totals and track min/max datetimes to avoid
        // building a merged transactions array later.
        $totalLoadAmount = 0.0;
        $totalLoadReversalAmount = 0.0;
        $totalUnloadAmount = 0.0;
        $totalUnloadReversalAmount = 0.0;
        $minDatetime = null;
        $maxDatetime = null;

        foreach ($this->data as $row) {
            if (!is_array($row) || count($row) === 0) {
                continue;
            }
            // Ensure numeric indexing (PhpSpreadsheet may produce associative arrays)
            $row = array_values($row);
            if (!isset($row[$this->descriptionColumn]) || $row[$this->descriptionColumn] === null || $row[$this->descriptionColumn] === '') {
                continue;
            }

            $description = strtolower(trim($row[$this->descriptionColumn]));
            
            // Check if this is a reversal transaction
            $isReversal = $this->isReversalTransaction($description);
            
            // Find loads (check for "load" but not "unload")
            if (strpos($description, 'load') !== false && 
                strpos($description, 'unload') === false) {
                $entry = $this->extractEntry($row, 'load', $isReversal);

                if ($isReversal) {
                    $loadReversals[] = $entry;
                    $totalLoadReversalAmount += $entry['amount'];
                } else {
                    $loads[] = $entry;
                    $totalLoadAmount += $entry['amount'];
                }

                // adjust min/max datetime
                if ($minDatetime === null || $entry['datetime'] < $minDatetime) {
                    $minDatetime = $entry['datetime'];
                }
                if ($maxDatetime === null || $entry['datetime'] > $maxDatetime) {
                    $maxDatetime = $entry['datetime'];
                }
            }
            
            // Find unloads
            if (strpos($description, 'unload') !== false) {
                $entry = $this->extractEntry($row, 'unload', $isReversal);

                if ($isReversal) {
                    $unloadReversals[] = $entry;
                    $totalUnloadReversalAmount += $entry['amount'];
                } else {
                    $unloads[] = $entry;
                    $totalUnloadAmount += $entry['amount'];
                }

                // adjust min/max datetime
                if ($minDatetime === null || $entry['datetime'] < $minDatetime) {
                    $minDatetime = $entry['datetime'];
                }
                if ($maxDatetime === null || $entry['datetime'] > $maxDatetime) {
                    $maxDatetime = $entry['datetime'];
                }
            }
        }
        
        if (empty($loads) && empty($loadReversals)) {
            throw new Exception("Could not find any load entries in GL file");
        }
        
        if (empty($unloads) && empty($unloadReversals)) {
            throw new Exception("Could not find any unload entries in GL file");
        }
        
        // Log reversal counts
        if (!empty($loadReversals)) {
            error_log("GL Processing: Found " . count($loadReversals) . " LOAD REVERSALS");
        }
        if (!empty($unloadReversals)) {
            error_log("GL Processing: Found " . count($unloadReversals) . " UNLOAD REVERSALS");
        }
        
        // Sort the primary lists (loads/unloads). Reversals don't need to be sorted
        // for our calculations so we skip sorting them to save CPU/time.
        if (count($loads) > 1) {
            usort($loads, function($a, $b) {
                return $a['datetime'] <=> $b['datetime'];
            });
        }

        if (count($unloads) > 1) {
            usort($unloads, function($a, $b) {
                return $a['datetime'] <=> $b['datetime'];
            });
        }
        
        // Handle exclusions only for non-reversal transactions
        $excludedLastLoad = null;
        $excludedFirstUnload = null;

        // Determine earliest load datetime (before excluding anything)
        $earliestLoadDatetime = null;
        if (!empty($loads)) {
            // After sorting, first element is the earliest
            $earliestLoadDatetime = $loads[0]['datetime'];
        }

        // Determine latest unload datetime (before excluding anything)
        $latestUnloadDatetime = null;
        if (!empty($unloads)) {
            // After sorting, last element is the latest
            $latestUnloadDatetime = $unloads[count($unloads) - 1]['datetime'];
        }

        // Conditionally exclude first unload only if it occurred before the earliest load
        if (!empty($unloads)) {
            $firstUnloadCandidate = $unloads[0];
            if ($earliestLoadDatetime === null || $firstUnloadCandidate['datetime'] < $earliestLoadDatetime) {
                // Candidate occurred before the first load -> exclude it
                $excludedFirstUnload = array_shift($unloads);
            } else {
                // Candidate occurred at or after first load -> do not exclude
                $excludedFirstUnload = null;
            }
        }

        // Conditionally exclude last load only if it occurred after the latest unload
        if (!empty($loads)) {
            $lastLoadCandidate = $loads[count($loads) - 1];
            if ($latestUnloadDatetime === null || $lastLoadCandidate['datetime'] > $latestUnloadDatetime) {
                // Candidate occurred after the last unload -> exclude it (for next cycle)
                $excludedLastLoad = array_pop($loads);
            } else {
                // Candidate occurred at or before last unload -> do not exclude
                $excludedLastLoad = null;
            }
        }
        // Adjust running totals to remove the excluded last load / first unload
        // (these represent the boundary transactions that should not be included
        // in the current cycle's totals).
        if ($excludedLastLoad !== null) {
            $totalLoadAmount -= $excludedLastLoad['amount'];
        }

        if ($excludedFirstUnload !== null) {
            $totalUnloadAmount -= $excludedFirstUnload['amount'];
        }

        // Calculate net totals using running totals collected earlier, after adjustments.
        $netLoadAmount = $totalLoadAmount - $totalLoadReversalAmount;
        $netUnloadAmount = $totalUnloadAmount - $totalUnloadReversalAmount;
        
        // Get date range: use min/max datetimes collected earlier (including reversals)
        $firstLoadDateTime = $minDatetime ?? new DateTime();
        $lastUnloadDateTime = $maxDatetime ?? new DateTime();
        
        // Enhanced logging
        error_log("GL Processing - Multiple Cycles with Reversals:");
        error_log("  LOADS:");
        error_log("    Normal loads found: " . (count($loads) + ($excludedLastLoad ? 1 : 0)));
        error_log("    Included normal loads: " . count($loads) . " = " . number_format($totalLoadAmount, 2));
        error_log("    Load reversals: " . count($loadReversals) . " = -" . number_format($totalLoadReversalAmount, 2));
        error_log("    Net Load Amount: " . number_format($netLoadAmount, 2));
        
        error_log("  UNLOADS:");
        error_log("    Normal unloads found: " . (count($unloads) + ($excludedFirstUnload ? 1 : 0)));
        error_log("    Included normal unloads: " . count($unloads) . " = " . number_format($totalUnloadAmount, 2));
        error_log("    Unload reversals: " . count($unloadReversals) . " = -" . number_format($totalUnloadReversalAmount, 2));
        error_log("    Net Unload Amount: " . number_format($netUnloadAmount, 2));
        
        if ($excludedFirstUnload) {
            error_log("  Excluded first unload: " . number_format($excludedFirstUnload['amount'], 2));
        }
        if ($excludedLastLoad) {
            error_log("  Excluded last load: " . number_format($excludedLastLoad['amount'], 2));
        }
        
        return new LoadUnloadData(
            $netLoadAmount,
            $netUnloadAmount,
            $firstLoadDateTime,
            $lastUnloadDateTime,
            count($loads) + count($loadReversals),  // Total load count including reversals
            count($unloads) + count($unloadReversals),  // Total unload count including reversals
            $excludedFirstUnload ? $excludedFirstUnload['amount'] : null,
            $excludedLastLoad ? $excludedLastLoad['amount'] : null
        );
    }
    
    /**
     * Check if a transaction description indicates a reversal
     */
    private function isReversalTransaction(string $description): bool
    {
        $descriptionLower = strtolower($description);
        
        // Check for common reversal indicators
        return (
            strpos($descriptionLower, 'reversal') !== false ||
            strpos($descriptionLower, 'rvsl') !== false ||
            strpos($descriptionLower, 'reversed') !== false ||
            strpos($descriptionLower, 'reverse') !== false
        );
    }
    
    private function extractEntry(array $row, string $type, bool $isReversal = false): array
    {
        $amount = $this->extractAmount($row, $type);
        $datetime = $this->extractDateTime($row);
        
        return [
            'amount' => $amount,
            'datetime' => $datetime,
            'is_reversal' => $isReversal
        ];
    }
    
    private function extractAmount(array $row, string $type): float
    {
        // For LOAD, amount is in DEBIT column
        // For UNLOAD, amount is in CREDIT column
        $columnIndex = ($type === 'load') ? $this->debitColumn : $this->creditColumn;
        
        if ($columnIndex !== null && isset($row[$columnIndex])) {
            $amount = $row[$columnIndex];
            // Remove currency symbols, commas, and spaces
            $amount = preg_replace('/[^0-9.\-]/', '', $amount);
            return (float) $amount;
        }
        
        return 0.0;
    }
    
    private function extractDateTime(array $row): DateTime
    {
        // First try to extract from description (includes time)
        $description = $row[$this->descriptionColumn];
        
        // Pattern 1: 2025-10-09 / 08:29AM
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*\/\s*(\d{1,2}:\d{2}[AP]M)/', $description, $matches)) {
            $dateStr = $matches[1] . ' ' . $matches[2];
            return $this->parseDateTime($dateStr);
        }
        
        // Pattern 2: 10/10/2025 / 04:31PM
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\s*\/\s*(\d{1,2}:\d{2}[AP]M)/', $description, $matches)) {
            $dateStr = $matches[1] . ' ' . $matches[2];
            return $this->parseDateTime($dateStr);
        }
        
        // Fallback: use date column if available
        if ($this->dateColumn !== null && isset($row[$this->dateColumn])) {
            return $this->parseDateTime($row[$this->dateColumn]);
        }
        
        return new DateTime();
    }
    
    private function parseDateTime(string $dateString): DateTime
    {
        $dateString = trim($dateString);
        
        // Try various formats
        $formats = [
            'Y-m-d H:iA',           // 2025-10-09 08:29AM
            'm/d/Y H:iA',           // 10/10/2025 04:31PM
            'd/m/Y H:iA',           // 09/10/2025 04:31PM
            'Y-m-d h:iA',           // 2025-10-09 8:29AM
            'm/d/Y h:iA',           // 10/10/2025 4:31PM
            'd/m/Y h:iA',           // 09/10/2025 4:31PM
            'd-M-y',                // 09-Oct-25
            'Y-m-d H:i:s',
            'd/m/Y H:i:s',
            'm/d/Y H:i:s',
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
            return new DateTime();
        }
    }
}