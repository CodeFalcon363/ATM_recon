<?php

use PHPUnit\Framework\TestCase;
use App\Services\TransactionMatcher;
use App\Models\TransactionMatch;

class TransactionMatcherTest extends TestCase
{
    public function testSimpleMatch()
    {
        $glHeaders = ['description', 'credit', 'debit', 'date'];
        $fepHeaders = ['retrieval', 'amount', 'request date'];

        $glData = [
            ["ATM WDL REF: 000000000001234567890123", '0', '100.00', '2025-10-01']
        ];

        $fepData = [
            ["000000000001234567890123", '100.00', '2025-10-01']
        ];

        $matcher = new TransactionMatcher($glData, $fepData, $glHeaders, $fepHeaders);
        $result = $matcher->matchTransactions();

        $this->assertInstanceOf(TransactionMatch::class, $result);
        $this->assertEquals(1, $result->getMatchedCount());
        $this->assertEquals(0, $result->getGlNotOnFepCount());
        $this->assertEquals(0, $result->getFepNotOnGlCount());
    }

    public function testFoundInFilteredOut()
    {
        $glHeaders = ['description', 'credit', 'debit', 'date'];
        $fepHeaders = ['retrieval', 'amount', 'request date', 'response meaning'];

        $glData = [
            ["ATM WDL REF: 000000000001234567890124", '0', '50.00', '2025-10-02']
        ];

        $fepData = [];

        $filteredOut = [
            ["000000000001234567890124", '50.00', '2025-10-02', 'Not Approved']
        ];

        $matcher = new TransactionMatcher($glData, $fepData, $glHeaders, $fepHeaders, $filteredOut);
        $result = $matcher->matchTransactions();

    $this->assertEquals(0, $result->getMatchedCount());
    $this->assertEquals(0, $result->getGlNotOnFepCount());
    $this->assertEquals(0, $result->getFepNotOnGlCount());
    }

    public function testFepNotOnGl()
    {
        $glHeaders = ['description', 'credit', 'debit', 'date'];
        $fepHeaders = ['retrieval', 'amount', 'request date'];

        $glData = [];

        $fepData = [
            ["000000000001234567890125", '75.00', '2025-10-03']
        ];

        $matcher = new TransactionMatcher($glData, $fepData, $glHeaders, $fepHeaders);
        $result = $matcher->matchTransactions();

        $this->assertEquals(0, $result->getMatchedCount());
        $this->assertEquals(0, $result->getGlNotOnFepCount());
        $this->assertEquals(1, $result->getFepNotOnGlCount());
    }
}
