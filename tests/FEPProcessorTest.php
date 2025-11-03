<?php

use PHPUnit\Framework\TestCase;
use App\Services\FEPProcessor;

class FEPProcessorTest extends TestCase
{
    public function testFilterAndDuplicateRemoval()
    {
    $headers = ['retrieval reference', 'amount', 'request date', 'response meaning', 'tran type'];
        $data = [
            $headers,
            ['RRN1', '100.00', '2025-10-01 10:00', 'Approved', 'INITIAL'],
            ['RRN2', '50.00', '2025-10-01 11:00', 'Not Approved', 'INITIAL'],
            ['RRN1', '100.00', '2025-10-01 10:01', 'Approved', 'REVERSAL'],
            ['RRN3', '150.00', '2025-10-02 12:00', 'Approved', 'INITIAL'],
        ];

        $processor = new FEPProcessor($data);

        $this->assertEquals(4, $processor->getTransactionCount());

        $processor->filterApprovedOnly();
        $this->assertEquals(3, $processor->getTransactionCount());

        $processor->removeDuplicates();
        // RRN1 duplicates removed entirely
        $this->assertEquals(1, $processor->getTransactionCount());

        $processor->filterByTransactionType();
        $this->assertEquals(1, $processor->getTransactionCount());
    }
}
