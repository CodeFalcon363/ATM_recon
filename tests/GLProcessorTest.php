<?php

use PHPUnit\Framework\TestCase;
use App\Services\GLProcessor;
use App\Models\LoadUnloadData;

class GLProcessorTest extends TestCase
{
    public function testExtractLoadUnloadData()
    {
        $headers = ['description', 'credit', 'debit', 'date'];
        $data = [
            $headers,
            ['LOAD 2025-10-01 / 08:00AM', '', '1000', '2025-10-01'],
            ['UNLOAD 2025-10-02 / 05:00PM', '950', '', '2025-10-02'],
            ['LOAD 2025-10-03 / 09:00AM', '', '500', '2025-10-03'],
            ['UNLOAD 2025-10-04 / 06:00PM', '400', '', '2025-10-04'],
        ];

        $processor = new GLProcessor($data);
        $result = $processor->extractLoadUnloadData();

        $this->assertInstanceOf(LoadUnloadData::class, $result);
        $this->assertGreaterThan(0, $result->getLoadAmount());
        $this->assertGreaterThan(0, $result->getUnloadAmount());
    }
}
