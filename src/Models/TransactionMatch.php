<?php

namespace App\Models;

class TransactionMatch
{
    private $matchedTransactions;
    private $glNotOnFep;
    private $fepNotOnGl;
    private $glFoundInFilteredFep;
    private $glFoundInFilteredFepCount = 0;
    private $glFoundInFilteredFepTotal = 0.0;
    private $matchedAmount;
    private $glNotOnFepAmount;
    private $glNotOnFepCreditTotal = 0.0;
    private $glNotOnFepDebitTotal = 0.0;
    private $fepNotOnGlAmount;
    private $glFoundInFilteredFepAmount;
    private $glHeaders = [];
    private $fepHeaders = [];
    private $nilledGlDuplicates = [];
    
    public function __construct(
        array $matchedTransactions,
        array $glNotOnFep,
        array $fepNotOnGl,
        array $glFoundInFilteredFep = [],
        int $glFoundInFilteredFepCount = 0,
        float $glFoundInFilteredFepTotal = 0.0,
        array $glHeaders = [],
        float $glNotOnFepCreditTotal = 0.0,
        float $glNotOnFepDebitTotal = 0.0,
        array $fepHeaders = [],
        array $nilledGlDuplicates = []
    ) {
        $this->matchedTransactions = $matchedTransactions;
        $this->glNotOnFep = $glNotOnFep;
        $this->fepNotOnGl = $fepNotOnGl;
        $this->glFoundInFilteredFep = $glFoundInFilteredFep;
    $this->glFoundInFilteredFepCount = $glFoundInFilteredFepCount;
    $this->glFoundInFilteredFepTotal = $glFoundInFilteredFepTotal;
        $this->glHeaders = $glHeaders;
    $this->glNotOnFepCreditTotal = $glNotOnFepCreditTotal;
    $this->glNotOnFepDebitTotal = $glNotOnFepDebitTotal;
        $this->fepHeaders = $fepHeaders;
    $this->nilledGlDuplicates = $nilledGlDuplicates;
        
        // Calculate totals
        $this->matchedAmount = $this->calculateTotal($matchedTransactions, 'amount');
        $this->glNotOnFepAmount = $this->calculateTotal($glNotOnFep, 'amount');
        $this->fepNotOnGlAmount = $this->calculateTotal($fepNotOnGl, 'amount');
        $this->glFoundInFilteredFepAmount = $this->calculateTotal($glFoundInFilteredFep, 'amount');
        // If caller provided explicit count/total for filtered-found GL rows, use them
        if ($this->glFoundInFilteredFepCount === 0 && !empty($glFoundInFilteredFep)) {
            $this->glFoundInFilteredFepCount = count($glFoundInFilteredFep);
        }
        if ($this->glFoundInFilteredFepTotal === 0.0 && !empty($glFoundInFilteredFep)) {
            $this->glFoundInFilteredFepTotal = $this->glFoundInFilteredFepAmount;
        }
    }
    
    private function calculateTotal(array $transactions, string $key): float
    {
        $total = 0.0;
        foreach ($transactions as $transaction) {
            $total += $transaction[$key] ?? 0;
        }
        return $total;
    }
    
    public function getMatchedTransactions(): array
    {
        return $this->matchedTransactions;
    }
    
    public function getGlNotOnFep(): array
    {
        return $this->glNotOnFep;
    }
    
    public function getFepNotOnGl(): array
    {
        return $this->fepNotOnGl;
    }
    
    public function getGlFoundInFilteredFep(): array
    {
        return $this->glFoundInFilteredFep;
    }
    
    public function getMatchedCount(): int
    {
        return count($this->matchedTransactions);
    }
    
    public function getGlNotOnFepCount(): int
    {
        return count($this->glNotOnFep);
    }
    
    public function getFepNotOnGlCount(): int
    {
        return count($this->fepNotOnGl);
    }
    
    public function getGlFoundInFilteredFepCount(): int
    {
        return $this->glFoundInFilteredFepCount;
    }
    
    public function getMatchedAmount(): float
    {
        return $this->matchedAmount;
    }
    
    public function getGlNotOnFepAmount(): float
    {
        return $this->glNotOnFepAmount;
    }

    public function getGlNotOnFepCreditTotal(): float
    {
        return $this->glNotOnFepCreditTotal;
    }

    public function getGlNotOnFepDebitTotal(): float
    {
        return $this->glNotOnFepDebitTotal;
    }
    
    public function getFepNotOnGlAmount(): float
    {
        return $this->fepNotOnGlAmount;
    }
    
    public function getGlFoundInFilteredFepAmount(): float
    {
        // Prefer the explicitly provided total if available
        return $this->glFoundInFilteredFepTotal ?: $this->glFoundInFilteredFepAmount;
    }
    
    public function isFullyMatched(): bool
    {
        return empty($this->glNotOnFep) && empty($this->fepNotOnGl);
    }
    
    public function getMatchRate(): float
    {
        $total = count($this->matchedTransactions) + count($this->glNotOnFep) + count($this->fepNotOnGl);
        if ($total === 0) {
            return 0.0;
        }
        return (count($this->matchedTransactions) / $total) * 100;
    }
    
    public function toArray(): array
    {
        return [
            'matched_count' => $this->getMatchedCount(),
            'gl_not_on_fep_count' => $this->getGlNotOnFepCount(),
            'fep_not_on_gl_count' => $this->getFepNotOnGlCount(),
            'gl_found_in_filtered_fep_count' => $this->getGlFoundInFilteredFepCount(),
            'matched_amount' => $this->matchedAmount,
            'gl_not_on_fep_amount' => $this->glNotOnFepAmount,
            'fep_not_on_gl_amount' => $this->fepNotOnGlAmount,
            'gl_found_in_filtered_fep_amount' => $this->glFoundInFilteredFepAmount,
            'match_rate' => $this->getMatchRate(),
            'fully_matched' => $this->isFullyMatched()
        ];
    }

    public function getNilledGlDuplicates(): array
    {
        return $this->nilledGlDuplicates;
    }

    public function getGlHeaders(): array
    {
        return $this->glHeaders;
    }

    public function getFepHeaders(): array
    {
        return $this->fepHeaders;
    }
}