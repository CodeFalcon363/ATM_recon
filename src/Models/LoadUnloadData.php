<?php

namespace App\Models;

use DateTime;

/**
 * Stores aggregated load/unload totals from GL with multi-cycle support
 */
class LoadUnloadData
{
    private $totalLoadAmount;
    private $totalUnloadAmount;
    private $firstLoadDateTime;
    private $lastUnloadDateTime;
    private $loadCount;
    private $unloadCount;
    private $excludedFirstUnload;
    private $excludedLastLoad;
    private $closingBalance;

    public function __construct(
        float $totalLoadAmount,
        float $totalUnloadAmount,
        DateTime $firstLoadDateTime,
        DateTime $lastUnloadDateTime,
        int $loadCount,
        int $unloadCount,
        ?float $excludedFirstUnload = null,
        ?float $excludedLastLoad = null,
        ?float $closingBalance = null
    ) {
        $this->totalLoadAmount = $totalLoadAmount;
        $this->totalUnloadAmount = $totalUnloadAmount;
        $this->firstLoadDateTime = $firstLoadDateTime;
        $this->lastUnloadDateTime = $lastUnloadDateTime;
        $this->loadCount = $loadCount;
        $this->unloadCount = $unloadCount;
        $this->excludedFirstUnload = $excludedFirstUnload;
        $this->excludedLastLoad = $excludedLastLoad;
        $this->closingBalance = $closingBalance;
    }
    
    public function getLoadAmount(): float
    {
        return $this->totalLoadAmount;
    }
    
    public function getUnloadAmount(): float
    {
        return $this->totalUnloadAmount;
    }
    
    public function getLoadDateTime(): DateTime
    {
        return $this->firstLoadDateTime;
    }
    
    public function getUnloadDateTime(): DateTime
    {
        return $this->lastUnloadDateTime;
    }
    
    public function getDifference(): float
    {
        return $this->totalLoadAmount - $this->totalUnloadAmount;
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
}