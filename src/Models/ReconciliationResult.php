<?php

namespace App\Models;

/**
 * Final reconciliation result with BALANCED/GL_MISSING/FEP_MISSING status determination
 */
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
    private $closingBalance;
    private $transactionsAfterCashCount;
    private $expectedCash;
    private $glVariance;
    private $glVarianceStatus;

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
        ?float $excludedLastLoad = null,
        ?float $closingBalance = null,
        ?float $transactionsAfterCashCount = null
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
        $this->closingBalance = $closingBalance;
        $this->transactionsAfterCashCount = $transactionsAfterCashCount ?? 0.0;

        // Calculate variance metrics
        // Expected Cash = Available Cash (excludedLastLoad) - Transactions After Cash Count
        $availableCash = $excludedLastLoad ?? 0.0;
        $this->expectedCash = $availableCash - $this->transactionsAfterCashCount;

        // GL Variance = GL Balance - Expected Cash
        $this->glVariance = ($closingBalance ?? 0.0) - $this->expectedCash;

        // Determine GL variance status
        $this->determineGLVarianceStatus();

        $this->difference = ($loadAmount - $unloadAmount) - $successfulTransactions;
        $this->determineStatus();
    }
    
    private function determineGLVarianceStatus(): void
    {
        // GL Variance Status based on GL Balance vs Expected Cash
        if (abs($this->glVariance) < 0.01) {
            $this->glVarianceStatus = 'GL IS BALANCED';
        } elseif ($this->glVariance > 0) {
            $this->glVarianceStatus = 'OVERAGE - POSSIBLE OUTSTANDING ITEM';
        } else {
            $this->glVarianceStatus = 'SHORTAGE - POSSIBLE OUTSTANDING ITEM';
        }
    }

    private function determineStatus(): void
    {
        $loadUnloadDiff = $this->loadAmount - $this->unloadAmount;

        // Net Load vs Valid FEP comparison
        if (abs($loadUnloadDiff - $this->successfulTransactions) < 0.01) {
            $this->status = 'BALANCED';
            $this->message = 'LOAD TO LOAD IS BALANCED';
        } else {
            $this->status = 'NOT_BALANCED';
            $this->message = 'LOAD TO LOAD IS NOT BALANCED';
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

    public function getClosingBalance(): ?float
    {
        return $this->closingBalance;
    }

    public function getTransactionsAfterCashCount(): float
    {
        return $this->transactionsAfterCashCount;
    }

    public function getExpectedCash(): float
    {
        return $this->expectedCash;
    }

    public function getGLVariance(): float
    {
        return $this->glVariance;
    }

    public function getGLVarianceStatus(): string
    {
        return $this->glVarianceStatus;
    }

    public function getAvailableCash(): float
    {
        return $this->excludedLastLoad ?? 0.0;
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
            'excluded_last_load' => $this->excludedLastLoad,
            'closing_balance' => $this->closingBalance,
            'available_cash' => $this->getAvailableCash(),
            'transactions_after_cash_count' => $this->transactionsAfterCashCount,
            'expected_cash' => $this->expectedCash,
            'gl_variance' => $this->glVariance,
            'gl_variance_status' => $this->glVarianceStatus
        ];
    }
}