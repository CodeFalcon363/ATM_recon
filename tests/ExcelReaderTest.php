<?php

use PHPUnit\Framework\TestCase;
use App\Services\ExcelReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelReaderTest extends TestCase
{
    private $tmpFile;

    protected function setUp(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['H1', 'H2'],
            ['A1', 'B1'],
            ['A2', 'B2']
        ], null, 'A1');

        $this->tmpFile = sys_get_temp_dir() . '/test_excel_' . uniqid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($this->tmpFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    public function testToArray()
    {
        $r = new ExcelReader();
        $r->loadFile($this->tmpFile);
        $arr = $r->toArray();

        $this->assertIsArray($arr);
        $this->assertEquals('H1', $arr[0][0]);
        $this->assertEquals('B1', $arr[1][1]);
    }

    public function testStreamRows()
    {
        $r = new ExcelReader();
        $r->loadFile($this->tmpFile);

        $collected = [];
        $r->streamRows(function($rowNum, $row) use (&$collected) {
            $collected[] = $row;
        }, 1);

        $this->assertCount(3, $collected);
        $this->assertEquals('H2', $collected[0][1]);
    }
}
