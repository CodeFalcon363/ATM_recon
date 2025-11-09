<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

session_start();

// Note: Temp file cleanup handled by cron job for better performance at scale

if (!isset($_GET['file']) || !in_array($_GET['file'], ['gl', 'fep', 'matched', 'gl_not_fep', 'fep_not_gl', 'gl_in_filtered', 'nilled_gl_duplicates'])) {
    header('Location: index.php');
    exit;
}

$fileType = $_GET['file'];

if (in_array($fileType, ['matched', 'gl_not_fep', 'fep_not_gl', 'gl_in_filtered', 'nilled_gl_duplicates'])) {
    if (!isset($_SESSION['transaction_match'])) {
        die('Transaction match data not found. Please process the files again.');
    }
    
    $transactionMatch = unserialize($_SESSION['transaction_match']);
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    if ($fileType === 'matched') {
        $sheet->setTitle('Matched Transactions');
        $matched = $transactionMatch->getMatchedTransactions();
        $origGlHeaders = $transactionMatch->getGlHeaders();
        $origFepHeaders = $transactionMatch->getFepHeaders();

        $findIdx = function(array $headers, array $keywords) {
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim((string)$h));
                foreach ($keywords as $k) {
                    if ($k !== '' && strpos($hl, $k) !== false) return $i;
                }
            }
            return null;
        };

        $glUserIdx = $findIdx($origGlHeaders, ['user id','userid','user']);
        $glCreditIdx = $findIdx($origGlHeaders, ['credit']);
        $glDebitIdx = $findIdx($origGlHeaders, ['debit']);
        $glDateIdx = $findIdx($origGlHeaders, ['date']);
        $glDescIdx = $findIdx($origGlHeaders, ['description','narration']);

        $fepCreditIdx = $findIdx($origFepHeaders, ['credit','amount']);
        $fepDebitIdx = $findIdx($origFepHeaders, ['debit']);
        $fepDateIdx = $findIdx($origFepHeaders, ['request date','date']);
        $fepDescIdx = $findIdx($origFepHeaders, ['description','narration']);
        $fepResponseIdx = $findIdx($origFepHeaders, ['response meaning','response','status']);
        $fepFromAccountIdx = $findIdx($origFepHeaders, ['from account','from acct','account']);
        $fepTerminalIdx = $findIdx($origFepHeaders, ['terminal id','terminal','tid']);
        $fepPanIdx = $findIdx($origFepHeaders, ['pan','card no','card number']);

        $headers = ['RRN','GL User ID','GL Credit','GL Debit','GL Date','GL Description','FEP Credit','FEP Debit','FEP Date','FEP Description','FEP Response','FEP From Account','FEP Terminal','FEP PAN'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($matched as $txn) {
            $glRow = isset($txn['gl_row']) && is_array($txn['gl_row']) ? array_values($txn['gl_row']) : [];
            $fepRow = isset($txn['fep_row']) && is_array($txn['fep_row']) ? array_values($txn['fep_row']) : [];

            $getVal = function($arr, $idx) {
                if ($idx === null) return '';
                return isset($arr[$idx]) ? $arr[$idx] : '';
            };

            $out = [
                $txn['rrn'] ?? '',
                $getVal($glRow, $glUserIdx),
                $getVal($glRow, $glCreditIdx),
                $getVal($glRow, $glDebitIdx),
                $getVal($glRow, $glDateIdx),
                $getVal($glRow, $glDescIdx),
                $getVal($fepRow, $fepCreditIdx),
                $getVal($fepRow, $fepDebitIdx),
                $getVal($fepRow, $fepDateIdx),
                $getVal($fepRow, $fepDescIdx),
                $getVal($fepRow, $fepResponseIdx),
                $getVal($fepRow, $fepFromAccountIdx),
                $getVal($fepRow, $fepTerminalIdx),
                $getVal($fepRow, $fepPanIdx)
            ];

            $sheet->fromArray($out, null, 'A' . $row);
            $row++;
        }

        $fileName = 'Matched_Transactions_' . date('Y-m-d_His') . '.xlsx';
        
    } elseif ($fileType === 'gl_in_filtered') {
        $sheet->setTitle('GL Found in Filtered FEP');
        $entries = $transactionMatch->getGlFoundInFilteredFep();
        $origGlHeaders = $transactionMatch->getGlHeaders();
        $origFepHeaders = $transactionMatch->getFepHeaders();

        $findIdx = function(array $headers, array $keywords) {
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim((string)$h));
                foreach ($keywords as $k) {
                    if ($k !== '' && strpos($hl, $k) !== false) return $i;
                }
            }
            return null;
        };

        $glUserIdx = $findIdx($origGlHeaders, ['user id','userid','user']);
        $glCreditIdx = $findIdx($origGlHeaders, ['credit']);
        $glDebitIdx = $findIdx($origGlHeaders, ['debit']);
        $glDateIdx = $findIdx($origGlHeaders, ['date']);
        $glDescIdx = $findIdx($origGlHeaders, ['description','narration']);

        $fepCreditIdx = $findIdx($origFepHeaders, ['credit','amount']);
        $fepDebitIdx = $findIdx($origFepHeaders, ['debit']);
        $fepDateIdx = $findIdx($origFepHeaders, ['request date','date']);
        $fepDescIdx = $findIdx($origFepHeaders, ['description','narration']);
        $fepResponseIdx = $findIdx($origFepHeaders, ['response meaning','response','status']);
        $fepFromAccountIdx = $findIdx($origFepHeaders, ['from account','from acct','account']);
        $fepTerminalIdx = $findIdx($origFepHeaders, ['terminal id','terminal','tid']);
        $fepPanIdx = $findIdx($origFepHeaders, ['pan','card no','card number']);

        $headers = ['RRN','GL User ID','GL Credit','GL Debit','GL Date','GL Description','FEP Credit','FEP Debit','FEP Date','FEP Description','FEP Response','FEP From Account','FEP Terminal','FEP PAN'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($entries as $txn) {
            $glRow = isset($txn['gl_row']) && is_array($txn['gl_row']) ? array_values($txn['gl_row']) : [];
            $fepRow = isset($txn['fep_row']) && is_array($txn['fep_row']) ? array_values($txn['fep_row']) : [];

            $getVal = function($arr, $idx) {
                if ($idx === null) return '';
                return isset($arr[$idx]) ? $arr[$idx] : '';
            };

            $out = [
                $txn['rrn'] ?? '',
                $getVal($glRow, $glUserIdx),
                $getVal($glRow, $glCreditIdx),
                $getVal($glRow, $glDebitIdx),
                $getVal($glRow, $glDateIdx),
                $getVal($glRow, $glDescIdx),
                $getVal($fepRow, $fepCreditIdx),
                $getVal($fepRow, $fepDebitIdx),
                $getVal($fepRow, $fepDateIdx),
                $getVal($fepRow, $fepDescIdx),
                $getVal($fepRow, $fepResponseIdx),
                $getVal($fepRow, $fepFromAccountIdx),
                $getVal($fepRow, $fepTerminalIdx),
                $getVal($fepRow, $fepPanIdx)
            ];

            $sheet->fromArray($out, null, 'A' . $row);
            $row++;
        }

        $fileName = 'GL_Found_in_Filtered_FEP_' . date('Y-m-d_His') . '.xlsx';
        
    } elseif ($fileType === 'gl_not_fep') {
        $sheet->setTitle('GL Not on FEP');

        $entries = $transactionMatch->getGlNotOnFep();
        $origGlHeaders = $transactionMatch->getGlHeaders();

        $findIdx = function(array $headers, array $keywords) {
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim((string)$h));
                foreach ($keywords as $k) {
                    if ($k !== '' && strpos($hl, $k) !== false) return $i;
                }
            }
            return null;
        };

        $glUserIdx = $findIdx($origGlHeaders, ['user id','userid','user']);
        $glCreditIdx = $findIdx($origGlHeaders, ['credit']);
        $glDebitIdx = $findIdx($origGlHeaders, ['debit']);
        $glDateIdx = $findIdx($origGlHeaders, ['date']);
        $glDescIdx = $findIdx($origGlHeaders, ['description','narration']);

        $headersOut = ['RRN','GL User ID','Credit','Debit','Date','Description'];
        $sheet->fromArray($headersOut, null, 'A1');

        $row = 2;
        foreach ($entries as $txn) {
            $creditRaw = $txn['credit_raw'] ?? null;
            $debitRaw = $txn['debit_raw'] ?? null;
            $userVal = '';
            if (($creditRaw === null || $debitRaw === null) && isset($txn['gl_row']) && is_array($txn['gl_row'])) {
                $glRow = array_values($txn['gl_row']);
                $userVal = ($glUserIdx !== null && isset($glRow[$glUserIdx])) ? $glRow[$glUserIdx] : '';
                if ($creditRaw === null) $creditRaw = ($glCreditIdx !== null && isset($glRow[$glCreditIdx])) ? $glRow[$glCreditIdx] : '';
                if ($debitRaw === null) $debitRaw = ($glDebitIdx !== null && isset($glRow[$glDebitIdx])) ? $glRow[$glDebitIdx] : '';
            } else {
                if (isset($txn['gl_row']) && is_array($txn['gl_row'])) {
                    $glRow = array_values($txn['gl_row']);
                    $userVal = ($glUserIdx !== null && isset($glRow[$glUserIdx])) ? $glRow[$glUserIdx] : '';
                }
            }

            $parse = function($s) { $s = preg_replace('/[^0-9.\-]/', '', (string)$s); if ($s === '' || $s === '-') return 0.0; return (float)$s; };
            $creditNum = $parse($creditRaw);
            $debitNum = $parse($debitRaw);
            if (abs($creditNum) < 0.0001 && abs($debitNum) < 0.0001) {
                $signed = isset($txn['amount']) ? (float)$txn['amount'] : 0.0;
                if ($signed < 0) { $debitNum = abs($signed); } else { $creditNum = $signed; }
            }

            $out = [ $txn['raw_rrn'] ?? ($txn['rrn'] ?? ''), $userVal, $creditNum, $debitNum, $txn['date'] ?? '', $txn['description'] ?? '' ];
            $sheet->fromArray($out, null, 'A' . $row);
            $row++;
        }

        $fileName = 'GL_Not_on_FEP_' . date('Y-m-d_His') . '.xlsx';

    } elseif ($fileType === 'fep_not_gl') {
        $sheet->setTitle('FEP Not on GL');

        $entries = $transactionMatch->getFepNotOnGl();
        $origFepHeaders = $transactionMatch->getFepHeaders();

        $findIdx = function(array $headers, array $keywords) {
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim((string)$h));
                foreach ($keywords as $k) {
                    if ($k !== '' && strpos($hl, $k) !== false) return $i;
                }
            }
            return null;
        };

        $fepCreditIdx = $findIdx($origFepHeaders, ['credit','amount']);
        $fepDebitIdx = $findIdx($origFepHeaders, ['debit']);
        $fepDateIdx = $findIdx($origFepHeaders, ['request date','date']);
        $fepDescIdx = $findIdx($origFepHeaders, ['description','narration']);
        $fepResponseIdx = $findIdx($origFepHeaders, ['response meaning','response','status']);
        $fepFromAccountIdx = $findIdx($origFepHeaders, ['from account','from acct','account']);
        $fepTerminalIdx = $findIdx($origFepHeaders, ['terminal id','terminal','tid']);
        $fepPanIdx = $findIdx($origFepHeaders, ['pan','card no','card number']);

        $headers = ['RRN','Credit','Debit','Date','Description','Response','From Account','Terminal ID','PAN'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($entries as $txn) {
            $fepRow = isset($txn['fep_row']) && is_array($txn['fep_row']) ? array_values($txn['fep_row']) : [];
            $getVal = function($arr, $idx) { if ($idx === null) return ''; return isset($arr[$idx]) ? $arr[$idx] : ''; };

            $creditVal = $getVal($fepRow, $fepCreditIdx);
            $debitVal = $getVal($fepRow, $fepDebitIdx);
            $parse = function($s) { $s = preg_replace('/[^0-9.\-]/', '', (string)$s); if ($s === '' || $s === '-') return 0.0; return (float)$s; };
            $creditNum = $parse($creditVal);
            $debitNum = $parse($debitVal);
            if (abs($creditNum) < 0.0001 && abs($debitNum) < 0.0001) {
                $signed = isset($txn['amount']) ? (float)$txn['amount'] : 0.0;
                if ($signed < 0) { $debitNum = abs($signed); } else { $creditNum = $signed; }
            }

            $out = [ $txn['rrn'] ?? '', $creditNum, $debitNum, $getVal($fepRow, $fepDateIdx), $getVal($fepRow, $fepDescIdx), $getVal($fepRow, $fepResponseIdx), $getVal($fepRow, $fepFromAccountIdx), $getVal($fepRow, $fepTerminalIdx), $getVal($fepRow, $fepPanIdx) ];
            $sheet->fromArray($out, null, 'A' . $row);
            $row++;
        }

        $fileName = 'FEP_Not_on_GL_' . date('Y-m-d_His') . '.xlsx';
    } elseif ($fileType === 'nilled_gl_duplicates') {
        $sheet->setTitle('NILled GL Duplicates');
        $entries = $transactionMatch->getNilledGlDuplicates();
        $origGlHeaders = $transactionMatch->getGlHeaders();

        $findIdx = function(array $headers, array $keywords) {
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim((string)$h));
                foreach ($keywords as $k) {
                    if ($k !== '' && strpos($hl, $k) !== false) return $i;
                }
            }
            return null;
        };

        $glUserIdx = $findIdx($origGlHeaders, ['user id','userid','user']);

        $headersOut = ['RRN','GL User ID','Credit','Debit','Date','Description'];
        $sheet->fromArray($headersOut, null, 'A1');

        $row = 2;
        foreach ($entries as $txn) {
            $userVal = '';
            if (isset($txn['gl_row']) && $glUserIdx !== null && isset($txn['gl_row'][$glUserIdx])) {
                $userVal = $txn['gl_row'][$glUserIdx];
            }

            $creditRaw = isset($txn['credit_raw']) ? $txn['credit_raw'] : '';
            $debitRaw = isset($txn['debit_raw']) ? $txn['debit_raw'] : '';

            $creditNum = ($creditRaw !== '' && $creditRaw !== null) ? (float)$creditRaw : 0.0;
            $debitNum = ($debitRaw !== '' && $debitRaw !== null) ? (float)$debitRaw : 0.0;

            $out = [
                $txn['rrn'] ?? '',
                $userVal,
                $creditNum,
                $debitNum,
                $txn['date'] ?? '',
                $txn['description'] ?? ''
            ];

            $sheet->fromArray($out, null, 'A' . $row);
            $row++;
        }

        $fileName = 'NILled_GL_Duplicates_' . date('Y-m-d_His') . '.xlsx';
    }

    $inputFormat = 'xlsx';
    if (isset($_SESSION['processed_gl']) && file_exists($_SESSION['processed_gl'])) {
        $glPath = $_SESSION['processed_gl'];
        $ext = strtolower(pathinfo($glPath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $inputFormat = 'csv';
        }
    }

    if ($inputFormat === 'csv') {
        $fileName = str_replace('.xlsx', '.csv', $fileName);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $value = $cell->getValue();
                if ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $rowData[] = $value;
            }
            fputcsv($output, $rowData);
        }

        fclose($output);
        unset($spreadsheet, $sheet);
        gc_collect_cycles();
        exit;
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        unset($spreadsheet, $sheet, $writer);
        gc_collect_cycles();
        exit;
    }
}

$sessionKey = 'processed_' . $fileType;

if (!isset($_SESSION[$sessionKey]) || !file_exists($_SESSION[$sessionKey])) {
    die('File not found or session expired. Please process the files again.');
}

$filePath = $_SESSION[$sessionKey];

$fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$isCSV = ($fileExtension === 'csv');

$fileName = ($fileType === 'gl' ? 'Processed_GL_File' : 'Processed_FEP_File') . '_' . date('Y-m-d_His') . '.' . $fileExtension;

if ($isCSV) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    readfile($filePath);
    exit;
}

// For XLSX files, apply column filtering
$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
$reader->setReadDataOnly(true);

// Small IReadFilter to load only needed columns in one pass
class ColumnReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
    private $columns;
    private $startRow;
    public function __construct(array $columns = [], int $startRow = 1) {
        $this->columns = $columns;
        $this->startRow = $startRow;
    }
    public function readCell($column, $row, $worksheetName = '') {
        if ($row < $this->startRow) return false;
        if (empty($this->columns)) return true; // allow all columns
        return in_array($column, $this->columns, true);
    }
}

// Load full file ONCE with all columns to get headers
// We'll filter columns after detecting which ones we need
$spreadsheetSrc = $reader->load($filePath);
$sheetSrc = $spreadsheetSrc->getActiveSheet();
$highestColumn = $sheetSrc->getHighestColumn();
$headerRow = $sheetSrc->rangeToArray('A1:' . $highestColumn . '1', null, true, false, false);
$srcHeaders = [];
if (!empty($headerRow) && isset($headerRow[0])) {
    foreach ($headerRow[0] as $h) { $srcHeaders[] = strtolower(trim((string)$h)); }
}

$findIdx = function(array $headers, array $keywords) {
    foreach ($headers as $i => $h) {
        foreach ($keywords as $k) {
            if ($k !== '' && strpos($h, $k) !== false) return $i;
        }
    }
    return null;
};

// helper to convert column index (0-based) to letter
$colLetter = function($index) {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
};

// build list of columns to load based on file type and detected header indices
$columnsToLoad = [];
if ($fileType === 'gl') {
    $rrnIdx = $findIdx($srcHeaders, ['retrieval','rrn','reference']);
    $userIdx = $findIdx($srcHeaders, ['user id','userid','user']);
    $creditIdx = $findIdx($srcHeaders, ['credit']);
    $debitIdx = $findIdx($srcHeaders, ['debit']);
    $dateIdx = $findIdx($srcHeaders, ['date']);
    $descIdx = $findIdx($srcHeaders, ['description','narration']);

    foreach ([$rrnIdx, $userIdx, $creditIdx, $debitIdx, $dateIdx, $descIdx] as $idx) {
        if ($idx !== null) $columnsToLoad[] = $colLetter($idx);
    }

    // OPTIMIZATION: Already loaded above, no need to reload
    // Spreadsheet already available in $spreadsheetSrc and $sheetSrc

    $outSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $outSheet = $outSpreadsheet->getActiveSheet();
    $outHeaders = ['RRN','User ID','Credit','Debit','Date','Description'];
    $outSheet->fromArray($outHeaders, null, 'A1');
    $r = 2;

    foreach ($sheetSrc->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        $rowMap = [];
        foreach ($cellIterator as $cell) {
            $rowMap[$cell->getColumn()] = $cell->getValue();
        }
        $getByIdx = function($idx) use ($rowMap, $colLetter) {
            if ($idx === null) return '';
            $col = $colLetter($idx);
            return $rowMap[$col] ?? '';
        };

        $outSheet->fromArray([
            $getByIdx($rrnIdx),
            $getByIdx($userIdx),
            $getByIdx($creditIdx),
            $getByIdx($debitIdx),
            $getByIdx($dateIdx),
            $getByIdx($descIdx)
        ], null, 'A' . $r);
        $r++;
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($outSpreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $writer->save('php://output');

    // cleanup
    unset($spreadsheetSrc, $outSpreadsheet, $outSheet, $outHeaders);
    @unlink($filePath);
    unset($_SESSION[$sessionKey]);
    gc_collect_cycles();
    exit;

} else {
    $rrnIdx = $findIdx($srcHeaders, ['retrieval','rrn','reference']);
    $creditIdx = $findIdx($srcHeaders, ['amount','credit']);
    $debitIdx = $findIdx($srcHeaders, ['debit']);
    $dateIdx = $findIdx($srcHeaders, ['request date','date']);
    $descIdx = $findIdx($srcHeaders, ['description','narration']);
    $responseIdx = $findIdx($srcHeaders, ['response meaning','response','status']);
    $fromIdx = $findIdx($srcHeaders, ['from account','from acct','account']);
    $terminalIdx = $findIdx($srcHeaders, ['terminal id','terminal','tid']);
    $panIdx = $findIdx($srcHeaders, ['pan','card no','card number']);

    foreach ([$rrnIdx, $creditIdx, $debitIdx, $dateIdx, $descIdx, $responseIdx, $fromIdx, $terminalIdx, $panIdx] as $idx) {
        if ($idx !== null) $columnsToLoad[] = $colLetter($idx);
    }

    // OPTIMIZATION: Already loaded above, no need to reload
    // Spreadsheet already available in $spreadsheetSrc and $sheetSrc

    $outSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $outSheet = $outSpreadsheet->getActiveSheet();
    $outHeaders = ['RRN','Credit','Debit','Date','Description','Response','From Account','Terminal ID','PAN'];
    $outSheet->fromArray($outHeaders, null, 'A1');
    $r = 2;

    foreach ($sheetSrc->getRowIterator(2) as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        $rowMap = [];
        foreach ($cellIterator as $cell) {
            $rowMap[$cell->getColumn()] = $cell->getValue();
        }
        $getByIdx = function($idx) use ($rowMap, $colLetter) {
            if ($idx === null) return '';
            $col = $colLetter($idx);
            return $rowMap[$col] ?? '';
        };

        $outSheet->fromArray([
            $getByIdx($rrnIdx),
            $getByIdx($creditIdx),
            $getByIdx($debitIdx),
            $getByIdx($dateIdx),
            $getByIdx($descIdx),
            $getByIdx($responseIdx),
            $getByIdx($fromIdx),
            $getByIdx($terminalIdx),
            $getByIdx($panIdx)
        ], null, 'A' . $r);
        $r++;
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($outSpreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $writer->save('php://output');

    unset($spreadsheetSrc, $outSpreadsheet, $outSheet, $outHeaders);
    @unlink($filePath);
    unset($_SESSION[$sessionKey]);
    gc_collect_cycles();
    exit;
}