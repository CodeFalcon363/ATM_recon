<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\UniversalFileReader;
use App\Services\TransactionMatcher;

/**
 * Transaction Matching Debug Tool (CSV & Excel)
 * Shows exactly what RRNs are being extracted from GL and FEP
 * Supports both CSV and Excel formats with automatic detection
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Matching Debug</title>
    <style>
        :root {
            /* Light mode (default) */
            --bg-gradient-start: #667eea;
            --bg-gradient-end: #764ba2;
            --card-bg: #ffffff;
            --text-primary: #333333;
            --text-secondary: #666666;
            --border-color: #ddd;
            --input-bg: #ffffff;
            --shadow-color: rgba(0, 0, 0, 0.3);
            --shadow-hover: rgba(102, 126, 234, 0.4);
            --section-bg: #f8f9fa;
            --accent-color: #667eea;
            --info-bg: #e7f3ff;
            --info-border: #2196F3;
            --info-text: #333333;
            --error-bg: #fee;
            --error-border: #c33;
            --error-text: #c33;
            --table-border: #ddd;
            --table-header-bg: #667eea;
            --table-header-text: white;
            --pre-bg: #f4f4f4;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-gradient-start: #1e293b;
                --bg-gradient-end: #0f172a;
                --card-bg: #1e293b;
                --text-primary: #f1f5f9;
                --text-secondary: #94a3b8;
                --border-color: #334155;
                --input-bg: #0f172a;
                --shadow-color: rgba(0, 0, 0, 0.5);
                --shadow-hover: rgba(102, 126, 234, 0.6);
                --section-bg: #0f172a;
                --accent-color: #818cf8;
                --info-bg: #1e3a5f;
                --info-border: #3b82f6;
                --info-text: #93c5fd;
                --error-bg: #7f1d1d;
                --error-border: #ef4444;
                --error-text: #fecaca;
                --table-border: #334155;
                --table-header-bg: #4338ca;
                --table-header-text: #e0e7ff;
                --pre-bg: #0f172a;
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
            min-height: 100vh;
            padding: 20px;
            transition: background 0.3s ease;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 20px;
            box-shadow: 0 20px 60px var(--shadow-color);
            padding: 40px;
            transition: all 0.3s ease;
        }
        h1 { color: var(--text-primary); margin-bottom: 30px; }
        .upload-form { margin-bottom: 30px; }
        input[type="file"] {
            padding: 10px;
            margin-right: 10px;
            background: var(--input-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        button {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px var(--shadow-hover);
        }
        .section {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--section-bg);
            border-radius: 12px;
        }
        h2 { color: var(--accent-color); margin-bottom: 15px; font-size: 20px; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 12px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--table-border);
            color: var(--text-primary);
        }
        th {
            background: var(--table-header-bg);
            color: var(--table-header-text);
            font-weight: 600;
        }
        .info-box {
            background: var(--info-bg);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--info-border);
            color: var(--info-text);
        }
        .error-box {
            background: var(--error-bg);
            color: var(--error-text);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--error-border);
        }
        pre {
            background: var(--pre-bg);
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 11px;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Transaction Matching Debug Tool (CSV & Excel)</h1>
        <div class="info-box">
            <strong>Purpose:</strong> This tool shows exactly what RRNs are being extracted from both GL and FEP files,
            helping you understand why transactions might not be matching.
            <br><br>
            <strong>Supported Formats:</strong> CSV (.csv) and Excel (.xlsx) with automatic detection
            <br><br>
            <strong>Key Matching Rules:</strong>
            <ul style="margin-left: 20px; margin-top: 10px; line-height: 1.8;">
                <li><strong>RRN Extraction:</strong> Last 12 digits from GL description; normalized to 12 digits from FEP retrieval reference</li>
                <li><strong>Amount Handling:</strong> Prefers explicit Credit/Debit columns; treats Debit as negative when deriving signed amount</li>
                <li><strong>Duplicate Reversals:</strong> GL entries with reversal keywords or matching Credit/Debit pairs are "NILed" (not exported as unmatched)</li>
                <li><strong>Three-Pass Matching:</strong> Matched transactions, GL found in filtered-out FEP (count only), and truly unmatched</li>
            </ul>
        </div>

        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="file" name="gl_file" accept=".csv,.xlsx,.xls" required>
            <input type="file" name="fep_file" accept=".csv,.xlsx,.xls" required>
            <button type="submit">Debug Matching</button>
        </form>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gl_file']) && isset($_FILES['fep_file'])) {
            try {
                // Save uploaded files with proper extensions
                $glExtension = strtolower(pathinfo($_FILES['gl_file']['name'], PATHINFO_EXTENSION));
                $fepExtension = strtolower(pathinfo($_FILES['fep_file']['name'], PATHINFO_EXTENSION));

                $glTempPath = sys_get_temp_dir() . '/debug_gl_' . time() . '.' . $glExtension;
                $fepTempPath = sys_get_temp_dir() . '/debug_fep_' . time() . '.' . $fepExtension;

                move_uploaded_file($_FILES['gl_file']['tmp_name'], $glTempPath);
                move_uploaded_file($_FILES['fep_file']['tmp_name'], $fepTempPath);

                // Detect formats
                $glFileType = UniversalFileReader::getFileType($glTempPath);
                $fepFileType = UniversalFileReader::getFileType($fepTempPath);

                // Read GL file
                $glReader = UniversalFileReader::create($glTempPath);
                $glReader->loadFile($glTempPath);
                $glDataRaw = $glReader->toArray();

                // Find GL header row (look for "DESCRIPTION"/"NARRATIVE", "CREDIT", "DEBIT")
                $glHeaderRow = 0;
                foreach ($glDataRaw as $index => $row) {
                    $rowStr = strtolower(implode('', $row));
                    $hasDesc = (strpos($rowStr, 'description') !== false ||
                               strpos($rowStr, 'narrative') !== false ||
                               strpos($rowStr, 'narration') !== false);
                    if ($hasDesc &&
                        strpos($rowStr, 'credit') !== false &&
                        strpos($rowStr, 'debit') !== false) {
                        $glHeaderRow = $index;
                        break;
                    }
                }

                $glHeaders = $glDataRaw[$glHeaderRow];
                $glData = array_slice($glDataRaw, $glHeaderRow + 1);

                echo '<div class="info-box">';
                echo '<strong>GL Format:</strong> ' . strtoupper($glFileType) . '<br>';
                echo '<strong>GL Header Row Found at Index:</strong> ' . $glHeaderRow . '<br>';
                echo '<strong>GL Data Rows:</strong> ' . count($glData);
                echo '</div>';
                
                // Read FEP file
                $fepReader = UniversalFileReader::create($fepTempPath);
                $fepReader->loadFile($fepTempPath);
                $fepDataRaw = $fepReader->toArray();

                // Find FEP header row (look for "RETRIEVAL" and "RESPONSE")
                $fepHeaderRow = 0;
                foreach ($fepDataRaw as $index => $row) {
                    $rowStr = strtolower(implode('', $row));
                    if (strpos($rowStr, 'retrieval') !== false &&
                        strpos($rowStr, 'response') !== false) {
                        $fepHeaderRow = $index;
                        break;
                    }
                }

                $fepHeaders = $fepDataRaw[$fepHeaderRow];
                $fepData = array_slice($fepDataRaw, $fepHeaderRow + 1);

                echo '<div class="info-box">';
                echo '<strong>FEP Format:</strong> ' . strtoupper($fepFileType) . '<br>';
                echo '<strong>FEP Header Row Found at Index:</strong> ' . $fepHeaderRow . '<br>';
                echo '<strong>FEP Data Rows:</strong> ' . count($fepData);
                echo '</div>';
                
                // Show GL Headers
                echo '<div class="section">';
                echo '<h2>GL File Headers</h2>';
                echo '<pre>' . htmlspecialchars(print_r($glHeaders, true)) . '</pre>';
                echo '</div>';
                
                // Show FEP Headers
                echo '<div class="section">';
                echo '<h2>FEP File Headers</h2>';
                echo '<pre>' . htmlspecialchars(print_r($fepHeaders, true)) . '</pre>';
                echo '</div>';
                
                // Show sample GL descriptions with RRN extraction
                echo '<div class="section">';
                echo '<h2>GL Sample Descriptions & RRN Extraction</h2>';
                echo '<table>';
                echo '<thead><tr><th>Row</th><th>Description</th><th>Extracted RRN</th></tr></thead>';
                echo '<tbody>';
                
                $descIdx = null;
                foreach ($glHeaders as $idx => $header) {
                    $headerLower = strtolower($header);
                    if (strpos($headerLower, 'description') !== false ||
                        strpos($headerLower, 'narrative') !== false ||
                        strpos($headerLower, 'narration') !== false) {
                        $descIdx = $idx;
                        break;
                    }
                }
                
                $count = 0;
                foreach ($glData as $rowNum => $row) {
                    if ($count >= 20) break;
                    if (!isset($row[$descIdx])) continue;
                    
                    $description = $row[$descIdx];
                    if (empty($description)) continue;
                    
                    // Skip load/unload entries
                    $descLower = strtolower($description);
                    if (strpos($descLower, 'load') !== false || strpos($descLower, 'unload') !== false) {
                        continue;
                    }
                    
                    // Extract RRN using the same logic as TransactionMatcher
                    // Try to find sequences of 12 or more digits and use last occurrence's last 12 digits
                    preg_match_all('/\d{12,}/', $description, $matches);
                    if (!empty($matches[0])) {
                        $last = end($matches[0]);
                        $rrn = substr($last, -12);
                    } else {
                        // Fallback: any 12-digit sequence
                        preg_match('/\d{12}/', $description, $m);
                        $rrn = !empty($m) ? $m[0] : 'NO RRN';
                    }
                    
                    echo '<tr>';
                    echo '<td>' . ($rowNum + 2) . '</td>';
                    echo '<td style="font-size: 11px;">' . htmlspecialchars(substr($description, 0, 100)) . '</td>';
                    echo '<td><strong>' . htmlspecialchars($rrn) . '</strong></td>';
                    echo '</tr>';
                    $count++;
                }
                echo '</tbody></table>';
                echo '</div>';
                
                // Show sample FEP RRNs
                echo '<div class="section">';
                echo '<h2>FEP Sample RRNs</h2>';
                echo '<p style="margin-bottom: 10px; font-size: 13px; color: #666;">Showing raw RRNs from FEP file (normalized to last 12 digits during matching)</p>';
                echo '<table>';
                echo '<thead><tr><th>Row</th><th>RRN (Raw)</th><th>Normalized RRN</th><th>Amount</th><th>Date</th></tr></thead>';
                echo '<tbody>';
                
                $rrnIdx = null;
                $amountIdx = null;
                $dateIdx = null;
                
                foreach ($fepHeaders as $idx => $header) {
                    $headerLower = strtolower($header);
                    if (stripos($headerLower, 'retrieval') !== false && stripos($headerLower, 'ref') !== false) {
                        $rrnIdx = $idx;
                    }
                    if (stripos($headerLower, 'amount') !== false) {
                        $amountIdx = $idx;
                    }
                    if (stripos($headerLower, 'request') !== false && stripos($headerLower, 'date') !== false) {
                        $dateIdx = $idx;
                    }
                }
                
                $count = 0;
                foreach ($fepData as $rowNum => $row) {
                    if ($count >= 20) break;
                    if (!isset($row[$rrnIdx])) continue;
                    
                    $rrn = $row[$rrnIdx];
                    $amount = isset($row[$amountIdx]) ? $row[$amountIdx] : '';
                    $date = isset($row[$dateIdx]) ? $row[$dateIdx] : '';
                    
                    if (empty($rrn)) continue;
                    
                    // Normalize RRN to last 12 digits (same as TransactionMatcher)
                    $digits = preg_replace('/\D+/', '', $rrn);
                    $normalizedRrn = strlen($digits) > 12 ? substr($digits, -12) : $digits;
                    
                    echo '<tr>';
                    echo '<td>' . ($rowNum + 2) . '</td>';
                    echo '<td><strong>' . htmlspecialchars($rrn) . '</strong></td>';
                    echo '<td style="color: #667eea;"><strong>' . htmlspecialchars($normalizedRrn) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($amount) . '</td>';
                    echo '<td>' . htmlspecialchars($date) . '</td>';
                    echo '</tr>';
                    $count++;
                }
                echo '</tbody></table>';
                echo '</div>';
                
                // Perform actual matching
                echo '<div class="section">';
                echo '<h2>Running Transaction Matcher</h2>';
                echo '<p style="margin-bottom: 15px; font-size: 13px; color: #666;">Note: Matching uses normalized RRNs (last 12 digits). GL amounts prefer explicit Credit/Debit columns.</p>';
                
                $matcher = new TransactionMatcher($glData, $fepData, $glHeaders, $fepHeaders);
                $result = $matcher->matchTransactions();
                
                echo '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 20px;">';
                echo '<div style="background: #d4edda; padding: 20px; border-radius: 10px; text-align: center;">';
                echo '<div style="font-size: 32px; font-weight: bold; color: #155724;">' . $result->getMatchedCount() . '</div>';
                echo '<div style="color: #155724; margin-top: 10px;">Matched</div>';
                echo '<div style="font-size: 12px; color: #155724; margin-top: 5px;">₦' . number_format($result->getMatchedAmount(), 2) . '</div>';
                echo '</div>';
                echo '<div style="background: #e2e3e5; padding: 20px; border-radius: 10px; text-align: center;">';
                echo '<div style="font-size: 32px; font-weight: bold; color: #383d41;">' . $result->getGlFoundInFilteredFepCount() . '</div>';
                echo '<div style="color: #383d41; margin-top: 10px;">GL in Filtered FEP</div>';
                echo '<div style="font-size: 11px; color: #383d41; margin-top: 5px;">Found in excluded FEP<br>(Not truly missing)</div>';
                echo '</div>';
                echo '<div style="background: #f8d7da; padding: 20px; border-radius: 10px; text-align: center;">';
                echo '<div style="font-size: 32px; font-weight: bold; color: #721c24;">' . $result->getGlNotOnFepCount() . '</div>';
                echo '<div style="color: #721c24; margin-top: 10px;">GL Not on FEP</div>';
                echo '<div style="font-size: 12px; color: #721c24; margin-top: 5px;">Credit: ₦' . number_format($result->getGlNotOnFepCreditTotal(), 2) . '<br>Debit: ₦' . number_format($result->getGlNotOnFepDebitTotal(), 2) . '</div>';
                echo '</div>';
                echo '<div style="background: #fff3cd; padding: 20px; border-radius: 10px; text-align: center;">';
                echo '<div style="font-size: 32px; font-weight: bold; color: #856404;">' . $result->getFepNotOnGlCount() . '</div>';
                echo '<div style="color: #856404; margin-top: 10px;">FEP Not on GL</div>';
                echo '<div style="font-size: 12px; color: #856404; margin-top: 5px;">₦' . number_format($result->getFepNotOnGlAmount(), 2) . '</div>';
                echo '</div>';
                echo '</div>';

                if (count($result->getNilledGlDuplicates()) > 0) {
                    echo '<div style="margin-top: 20px; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #6c757d;">';
                    echo '<strong>GL Duplicate Reversals NILed:</strong> ' . count($result->getNilledGlDuplicates()) . ' entries';
                    echo '<div style="font-size: 12px; color: #666; margin-top: 5px;">These GL entries were identified as reversal pairs and excluded from "GL Not on FEP" report</div>';
                    echo '</div>';
                }
                
                echo '</div>';
                
                @unlink($glTempPath);
                @unlink($fepTempPath);
                
            } catch (Exception $e) {
                echo '<div class="error-box">';
                echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
                echo '</div>';
            }
        }
        ?>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="index.php" style="color: #667eea; text-decoration: none; font-weight: 600;">
                ← Back to Main Application
            </a>
        </div>
    </div>
</body>
</html>