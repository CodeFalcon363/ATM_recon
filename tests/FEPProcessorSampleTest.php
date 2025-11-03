<?php

use PHPUnit\Framework\TestCase;
use App\Services\FEPProcessor;

class FEPProcessorSampleTest extends TestCase
{
    public function testSampleFepKeepsApprovedRows()
    {
        // Header row copied from user's sample (tabular columns)
        $header = [
            'TERMINAL ID','CARD ACCEPTOR NAME LOC','ATM AT BRANCH','PAN','ONUS OFFUS','SYSTEM TRACE AUDIT NO','RETRIEVAL REFFERENCE NR','TRAN TYPE','RSP CODE','RSP','RESPONSE MEANING','REQUEST DATE','FLEX STAN','FLEX RRN','AMOUNT','FROM ACCOUNT','FLEX STAN 2','FLEX RNN 2'
        ];

        // Sample rows (values taken from user's message)
        $rows = [
            [
                '10441293','201_HYO_B_ADENIRAN_3','Y','506104******2066','ON_US','406748','524410406748','INITIAL','00','Approved','01/09/2025 11:11 AM','','','20000','0059760025','', ''
            ],
            [
                '10441293','201_HYO_B_ADENIRAN_3','Y','536613******1551','ON_US','235730','524409235730','INITIAL','00','Approved','01/09/2025 10:03 AM','','','20000','1890451008','', ''
            ],
            [
                '10441293','201_HYO_B_ADENIRAN_3','Y','418745******9545','ON_US','671841','524412671841','INITIAL','00','Approved','01/09/2025 1:02 PM','','','20000','0098945819','', ''
            ],
        ];

        $data = array_merge([$header], $rows);

        $processor = new FEPProcessor($data);

        // Before filtering, total rows should equal sample rows
        $this->assertGreaterThanOrEqual(3, count($processor->getData()) + count($processor->getFilteredOutTransactions()));

        $processor->filterApprovedOnly();

        $kept = $processor->getData();
        $filtered = $processor->getFilteredOutTransactions();

        // All three rows should be kept as approved
        $this->assertCount(3, $kept, 'Approved rows should be kept');
        $this->assertCount(0, $filtered, 'No rows should be filtered out as non-approved');
    }
}
