<?php

namespace App\Services;

use App\Models\LoadUnloadData;
use DateTime;
use Exception;

/**
 * Extracts load/unload data from GL files supporting multi-cycle operations
 */
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

        // Locate header row containing description, credit, and debit columns
        $headerRow = 0;
        foreach ($data as $index => $row) {
            $rowStr = strtolower(implode('', $row));
            $hasDescription = (strpos($rowStr, 'description') !== false ||
                              strpos($rowStr, 'narrative') !== false ||
                              strpos($rowStr, 'narration') !== false);
            $hasCredit = strpos($rowStr, 'credit') !== false;
            $hasDebit = strpos($rowStr, 'debit') !== false;

            if ($hasDescription && $hasCredit && $hasDebit) {
                $headerRow = $index;
                break;
            }
        }

        $this->headers = isset($data[$headerRow]) ? array_values((array)$data[$headerRow]) : [];
        $this->data = array_values(array_slice($data, $headerRow + 1));
        $this->identifyColumns();
    }

    private function identifyColumns(): void
    {
        foreach ($this->headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            if ($this->descriptionColumn === null) {
                if (strpos($headerLower, 'description') !== false ||
                    strpos($headerLower, 'narration') !== false ||
                    strpos($headerLower, 'narrative') !== false) {
                    $this->descriptionColumn = $index;
                }
            }

            if (strpos($headerLower, 'credit') !== false &&
                strpos($headerLower, 'count') === false) {
                $this->creditColumn = $index;
            }

            if (strpos($headerLower, 'debit') !== false &&
                strpos($headerLower, 'count') === false) {
                $this->debitColumn = $index;
            }

            if (strpos($headerLower, 'date') !== false) {
                $this->dateColumn = $index;
            }
        }

        if ($this->descriptionColumn === null) {
            throw new Exception("Description column not found in GL file. Headers: " . implode(', ', $this->headers));
        }
    }

    /**
     * Extract load/unload totals with reversal handling and edge transaction exclusion
     */
    public function extractLoadUnloadData(): LoadUnloadData
    {
        $loads = [];
        $unloads = [];
        $loadReversals = [];
        $unloadReversals = [];

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
            $row = array_values($row);
            if (!isset($row[$this->descriptionColumn]) || $row[$this->descriptionColumn] === null || $row[$this->descriptionColumn] === '') {
                continue;
            }

            $description = strtolower(trim($row[$this->descriptionColumn]));

            $isReversal = $this->isReversalTransaction($description);

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

                if ($minDatetime === null || $entry['datetime'] < $minDatetime) {
                    $minDatetime = $entry['datetime'];
                }
                if ($maxDatetime === null || $entry['datetime'] > $maxDatetime) {
                    $maxDatetime = $entry['datetime'];
                }
            }

            if (strpos($description, 'unload') !== false) {
                $entry = $this->extractEntry($row, 'unload', $isReversal);

                if ($isReversal) {
                    $unloadReversals[] = $entry;
                    $totalUnloadReversalAmount += $entry['amount'];
                } else {
                    $unloads[] = $entry;
                    $totalUnloadAmount += $entry['amount'];
                }

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

        if (!empty($loadReversals)) {
            error_log("GL Processing: Found " . count($loadReversals) . " LOAD REVERSALS");
        }
        if (!empty($unloadReversals)) {
            error_log("GL Processing: Found " . count($unloadReversals) . " UNLOAD REVERSALS");
        }

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

        // Exclude edge transactions that belong to adjacent cycles
        $excludedLastLoad = null;
        $excludedFirstUnload = null;

        $earliestLoadDatetime = null;
        if (!empty($loads)) {
            $earliestLoadDatetime = $loads[0]['datetime'];
        }

        $latestUnloadDatetime = null;
        if (!empty($unloads)) {
            $latestUnloadDatetime = $unloads[count($unloads) - 1]['datetime'];
        }

        if (!empty($unloads)) {
            $firstUnloadCandidate = $unloads[0];
            if ($earliestLoadDatetime === null || $firstUnloadCandidate['datetime'] < $earliestLoadDatetime) {
                $excludedFirstUnload = array_shift($unloads);
            } else {
                $excludedFirstUnload = null;
            }
        }

        if (!empty($loads)) {
            $lastLoadCandidate = $loads[count($loads) - 1];
            if ($latestUnloadDatetime === null || $lastLoadCandidate['datetime'] > $latestUnloadDatetime) {
                $excludedLastLoad = array_pop($loads);
            } else {
                $excludedLastLoad = null;
            }
        }

        if ($excludedLastLoad !== null) {
            $totalLoadAmount -= $excludedLastLoad['amount'];
        }

        if ($excludedFirstUnload !== null) {
            $totalUnloadAmount -= $excludedFirstUnload['amount'];
        }

        $netLoadAmount = $totalLoadAmount - $totalLoadReversalAmount;
        $netUnloadAmount = $totalUnloadAmount - $totalUnloadReversalAmount;

        $firstLoadDateTime = $minDatetime ?? new DateTime();
        $lastUnloadDateTime = $maxDatetime ?? new DateTime();

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
            count($loads) + count($loadReversals),
            count($unloads) + count($unloadReversals),
            $excludedFirstUnload ? $excludedFirstUnload['amount'] : null,
            $excludedLastLoad ? $excludedLastLoad['amount'] : null
        );
    }

    private function isReversalTransaction(string $description): bool
    {
        $descriptionLower = strtolower($description);

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
        // Business rule: LOAD in DEBIT column, UNLOAD in CREDIT column
        $columnIndex = ($type === 'load') ? $this->debitColumn : $this->creditColumn;

        if ($columnIndex !== null && isset($row[$columnIndex])) {
            $amount = $row[$columnIndex];
            $amount = preg_replace('/[^0-9.\-]/', '', $amount);
            return (float) $amount;
        }

        return 0.0;
    }

    private function extractDateTime(array $row): DateTime
    {
        $description = $row[$this->descriptionColumn];

        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*\/\s*(\d{1,2}:\d{2}[AP]M)/', $description, $matches)) {
            $dateStr = $matches[1] . ' ' . $matches[2];
            return $this->parseDateTime($dateStr);
        }

        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\s*\/\s*(\d{1,2}:\d{2}[AP]M)/', $description, $matches)) {
            $dateStr = $matches[1] . ' ' . $matches[2];
            return $this->parseDateTime($dateStr);
        }

        if ($this->dateColumn !== null && isset($row[$this->dateColumn])) {
            return $this->parseDateTime($row[$this->dateColumn]);
        }

        return new DateTime();
    }

    private function parseDateTime(string $dateString): DateTime
    {
        $dateString = trim($dateString);

        $formats = [
            'Y-m-d H:iA',
            'm/d/Y H:iA',
            'd/m/Y H:iA',
            'Y-m-d h:iA',
            'm/d/Y h:iA',
            'd/m/Y h:iA',
            'd-M-y',
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

        try {
            return new DateTime($dateString);
        } catch (Exception $e) {
            return new DateTime();
        }
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getData(): array
    {
        return $this->data;
    }
}