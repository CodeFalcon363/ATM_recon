<?php

namespace App\Models;

class ReconciliationResult
{
    private $loadAmount;
    private $unloadAmount;
    private $successfulTransactions;
    private $difference;
    private $status;
    private $message;
    private $loadDateTime;
    private $unloadDateTime;
    private $transactionCount;
    private $loadCount;
    private $unloadCount;
    private $excludedFirstUnload;
    private $excludedLastLoad;
    
    public function __construct(
        float $loadAmount,
        float $unloadAmount,
        float $successfulTransactions,
        $loadDateTime,
        $unloadDateTime,
        int $transactionCount,
        int $loadCount = 1,
        int $unloadCount = 1,
        ?float $excludedFirstUnload = null,
        ?float $excludedLastLoad = null
    ) {
        $this->loadAmount = $loadAmount;
        $this->unloadAmount = $unloadAmount;
        $this->successfulTransactions = $successfulTransactions;
        $this->loadDateTime = $loadDateTime;
        $this->unloadDateTime = $unloadDateTime;
        $this->transactionCount = $transactionCount;
        $this->loadCount = $loadCount;
        $this->unloadCount = $unloadCount;
        $this->excludedFirstUnload = $excludedFirstUnload;
        $this->excludedLastLoad = $excludedLastLoad;
        $this->difference = ($loadAmount - $unloadAmount) - $successfulTransactions;
        $this->determineStatus();
    }
    
    private function determineStatus(): void
    {
        $loadUnloadDiff = $this->loadAmount - $this->unloadAmount;
        
        if (abs($loadUnloadDiff - $this->successfulTransactions) < 0.01) {
            $this->status = 'BALANCED';
            $this->message = 'LOAD TO LOAD IS BALANCED';
        } else {
            // If (Load - Unload) - FEP_total is positive => GL > FEP => likely GL not on FEP
            // If negative => FEP > GL => likely FEP not on GL
            if ($this->difference > 0) {
                $this->status = 'GL_MISSING';
                $this->message = 'LIKELY GL NOT ON FEP EXIST';
            } else {
                $this->status = 'FEP_MISSING';
                $this->message = 'LIKELY FEP NOT ON GL EXIST';
            }
        }
    }
    
    public function getLoadAmount(): float
    {
        return $this->loadAmount;
    }
    
    public function getUnloadAmount(): float
    {
        return $this->unloadAmount;
    }
    
    public function getSuccessfulTransactions(): float
    {
        return $this->successfulTransactions;
    }
    
    public function getDifference(): float
    {
        return $this->difference;
    }
    
    public function getStatus(): string
    {
        return $this->status;
    }
    
    public function getMessage(): string
    {
        return $this->message;
    }
    
    public function getLoadDateTime()
    {
        return $this->loadDateTime;
    }
    
    public function getUnloadDateTime()
    {
        return $this->unloadDateTime;
    }
    
    public function getTransactionCount(): int
    {
        return $this->transactionCount;
    }
    
    public function getLoadCount(): int
    {
        return $this->loadCount;
    }
    
    public function getUnloadCount(): int
    {
        return $this->unloadCount;
    }
    
    public function getExcludedFirstUnload(): ?float
    {
        return $this->excludedFirstUnload;
    }
    
    public function getExcludedLastLoad(): ?float
    {
        return $this->excludedLastLoad;
    }
    
    public function toArray(): array
    {
        return [
            'load_amount' => $this->loadAmount,
            'unload_amount' => $this->unloadAmount,
            'load_unload_difference' => $this->loadAmount - $this->unloadAmount,
            'successful_transactions' => $this->successfulTransactions,
            'difference' => $this->difference,
            'status' => $this->status,
            'message' => $this->message,
            'load_datetime' => $this->loadDateTime,
            'unload_datetime' => $this->unloadDateTime,
            'transaction_count' => $this->transactionCount,
            'load_count' => $this->loadCount,
            'unload_count' => $this->unloadCount,
            'excluded_first_unload' => $this->excludedFirstUnload,
            'excluded_last_load' => $this->excludedLastLoad
        ];
    }
}