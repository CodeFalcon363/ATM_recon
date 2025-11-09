<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\UniversalFileReader;
use App\Services\GLProcessor;
use App\Services\FEPProcessor;
use App\Services\TransactionMatcher;

/**
 * Verification Tool - Shows detailed filtering steps (CSV & Excel)
 * Supports both CSV and Excel formats with automatic detection
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation Verification</title>
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
            --table-border: #ddd;
            --table-header-bg: #667eea;
            --table-header-text: white;
            --excluded-bg: #fee;
            --excluded-text: #c33;
            --included-bg: #efe;
            --included-text: #2a2;
            --info-box-bg: white;
            --info-box-border: #667eea;
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
                --table-border: #334155;
                --table-header-bg: #4338ca;
                --table-header-text: #e0e7ff;
                --excluded-bg: #7f1d1d;
                --excluded-text: #fca5a5;
                --included-bg: #14532d;
                --included-text: #86efac;
                --info-box-bg: #0f172a;
                --info-box-border: #4338ca;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        h1 {
            color: var(--text-primary);
            margin-bottom: 30px;
        }

        .section {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--section-bg);
            border-radius: 12px;
        }

        h2 {
            color: var(--accent-color);
            margin-bottom: 15px;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 13px;
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

        .excluded {
            background: var(--excluded-bg);
            color: var(--excluded-text);
        }

        .included {
            background: var(--included-bg);
            color: var(--included-text);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-box {
            background: var(--info-box-bg);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--info-box-border);
        }

        .info-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 20px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .upload-form {
            margin-bottom: 30px;
        }

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
    </style>
</head>
<body>
    <div class="container">
        <h1>Reconciliation Verification Tool (CSV & Excel)</h1>

        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="file" name="gl_file" accept=".csv,.xlsx,.xls" required>
            <input type="file" name="fep_file" accept=".csv,.xlsx,.xls" required>
            <button type="submit">Verify Processing</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gl_file']) && isset($_FILES['fep_file'])) {
            try {
                // Save uploaded files with proper extensions
                $glExtension = strtolower(pathinfo($_FILES['gl_file']['name'], PATHINFO_EXTENSION));
                $fepExtension = strtolower(pathinfo($_FILES['fep_file']['name'], PATHINFO_EXTENSION));

                $glTempPath = sys_get_temp_dir() . '/verify_gl_' . time() . '.' . $glExtension;
                $fepTempPath = sys_get_temp_dir() . '/verify_fep_' . time() . '.' . $fepExtension;

                move_uploaded_file($_FILES['gl_file']['tmp_name'], $glTempPath);
                move_uploaded_file($_FILES['fep_file']['tmp_name'], $fepTempPath);

                // Detect formats
                $glFileType = UniversalFileReader::getFileType($glTempPath);
                $fepFileType = UniversalFileReader::getFileType($fepTempPath);

                // Process GL file
                $glReader = UniversalFileReader::create($glTempPath);
                $glReader->loadFile($glTempPath);
                $glData = $glReader->toArray();
                $glProcessor = new GLProcessor($glData);
                $loadUnloadData = $glProcessor->extractLoadUnloadData();

                // Display file format information
                echo '<div class="info-box" style="background: #e7f3ff; border-left-color: #2196F3; margin-bottom: 20px;">';
                echo '<strong>File Formats Detected:</strong><br>';
                echo 'GL File: <strong>' . strtoupper($glFileType) . '</strong> | ';
                echo 'FEP File: <strong>' . strtoupper($fepFileType) . '</strong>';
                echo '</div>';

                echo '<div class="section">';
                echo '<h2>GL File Analysis</h2>';
                
                if ($loadUnloadData->getLoadCount() > 1 || $loadUnloadData->getUnloadCount() > 1) {
                    echo '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #ffc107;">';
                    echo '<strong>Multiple Load/Unload Cycles Detected</strong><br>';
                    echo '<span style="font-size: 13px;">Using multi-cycle reconciliation mode</span>';
                    echo '</div>';
                }
                
                echo '<div class="info-grid">';
                echo '<div class="info-box">';
                echo '<div class="info-label">Total Load Amount (Included)</div>';
                echo '<div class="info-value">₦' . number_format($loadUnloadData->getLoadAmount(), 2) . '</div>';
                echo '<div class="info-label" style="margin-top: 5px;">' . $loadUnloadData->getLoadCount() . ' load(s)</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">First Load DateTime</div>';
                echo '<div class="info-value" style="font-size: 14px;">' . $loadUnloadData->getLoadDateTime()->format('Y-m-d H:i:s A') . '</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">Total Unload Amount (Included)</div>';
                echo '<div class="info-value">₦' . number_format($loadUnloadData->getUnloadAmount(), 2) . '</div>';
                echo '<div class="info-label" style="margin-top: 5px;">' . $loadUnloadData->getUnloadCount() . ' unload(s)</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">Last Unload DateTime</div>';
                echo '<div class="info-value" style="font-size: 14px;">' . $loadUnloadData->getUnloadDateTime()->format('Y-m-d H:i:s A') . '</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">GL Difference</div>';
                echo '<div class="info-value">₦' . number_format($loadUnloadData->getDifference(), 2) . '</div>';
                echo '</div>';
                
                if ($loadUnloadData->getExcludedFirstUnload() !== null) {
                    echo '<div class="info-box" style="background: #fee; border-left-color: #c33;">';
                    echo '<div class="info-label">Excluded First Unload</div>';
                    echo '<div class="info-value" style="color: #c33;">₦' . number_format($loadUnloadData->getExcludedFirstUnload(), 2) . '</div>';
                    echo '<div class="info-label" style="margin-top: 5px; font-size: 11px;">Occurred before first load</div>';
                    echo '</div>';
                }
                
                if ($loadUnloadData->getExcludedLastLoad() !== null) {
                    echo '<div class="info-box" style="background: #fee; border-left-color: #c33;">';
                    echo '<div class="info-label">Excluded Last Load</div>';
                    echo '<div class="info-value" style="color: #c33;">₦' . number_format($loadUnloadData->getExcludedLastLoad(), 2) . '</div>';
                    echo '<div class="info-label" style="margin-top: 5px; font-size: 11px;">Occurred after last unload</div>';
                    echo '</div>';
                }
                
                echo '</div>';
                echo '</div>';

                // Process FEP file
                $fepReader = UniversalFileReader::create($fepTempPath);
                $fepReader->loadFile($fepTempPath);
                $fepData = $fepReader->toArray();
                $fepProcessor = new FEPProcessor($fepData);
                
                $initialData = $fepProcessor->getData();
                $initialCount = count($initialData);
                
                echo '<div class="section">';
                echo '<h2>FEP File Processing Steps (CORRECT ORDER)</h2>';
                echo '<p style="background: #fff3cd; padding: 10px; border-radius: 5px; margin-bottom: 15px;"><strong>Important:</strong> The filter pipeline order matters:</p>';
                echo '<ol style="margin-left: 20px; margin-bottom: 15px; line-height: 1.8;">';
                echo '<li><strong>Filter Approved Only:</strong> Keep only approved transactions (textual "approved" or numeric codes "00"/"0")</li>';
                echo '<li><strong>Remove Duplicates:</strong> Handle RRN duplicates with smart logic - remove INITIAL/REVERSAL pairs entirely, keep first of multiple INITIALs</li>';
                echo '<li><strong>Filter Transaction Type:</strong> Exclude any remaining REVERSAL transactions</li>';
                echo '<li><strong>Sort by Date:</strong> Order transactions chronologically</li>';
                echo '<li><strong>Filter by Date Range:</strong> Keep only transactions between GL first load and last unload times</li>';
                echo '</ol>';
                echo '<div class="info-grid">';
                echo '<div class="info-box">';
                echo '<div class="info-label">1. Initial Transactions</div>';
                echo '<div class="info-value">' . $initialCount . '</div>';
                echo '</div>';
                
                // Step 1: Filter Approved
                $fepProcessor->filterApprovedOnly();
                $approvedCount = $fepProcessor->getTransactionCount();
                $approvedData = $fepProcessor->getData();
                
                echo '<div class="info-box">';
                echo '<div class="info-label">2. After Approved Filter</div>';
                echo '<div class="info-value">' . $approvedCount . '</div>';
                echo '<div class="info-label" style="margin-top: 5px;">Excluded: ' . ($initialCount - $approvedCount) . '</div>';
                echo '</div>';
                
                // Step 2: Get data before duplicate removal (includes both INITIAL and REVERSAL)
                $beforeDupData = $fepProcessor->getData();
                
                // Step 3: Remove duplicates (while both INITIAL and REVERSAL still exist!)
                $fepProcessor->removeDuplicates();
                $noDupCount = $fepProcessor->getTransactionCount();
                $noDupData = $fepProcessor->getData();
                
                $removedByDup = $approvedCount - $noDupCount;
                
                echo '<div class="info-box" style="border-left-color: #c33;">';
                echo '<div class="info-label">3. After Duplicate Removal</div>';
                echo '<div class="info-value">' . $noDupCount . '</div>';
                echo '<div class="info-label" style="margin-top: 5px; color: #c33;"><strong>Removed: ' . $removedByDup . '</strong></div>';
                echo '<div class="info-label" style="margin-top: 3px; font-size: 11px;">INITIAL+REVERSAL pairs removed entirely; kept first of multiple INITIALs</div>';
                echo '</div>';
                
                // Step 4: Filter by transaction type (remove REVERSALs)
                $fepProcessor->filterByTransactionType();
                $noReversalCount = $fepProcessor->getTransactionCount();
                
                echo '<div class="info-box">';
                echo '<div class="info-label">4. After REVERSAL Filter</div>';
                echo '<div class="info-value">' . $noReversalCount . '</div>';
                echo '<div class="info-label" style="margin-top: 5px;">Excluded: ' . ($noDupCount - $noReversalCount) . ' remaining REVERSAL(s)</div>';
                echo '</div>';
                
                $fepProcessor->sortByRequestDate();
                
                // Before date filtering
                $beforeDateFilter = $fepProcessor->getData();
                $beforeDateCount = count($beforeDateFilter);
                
                echo '<div class="info-box">';
                echo '<div class="info-label">5. Before Date Filter</div>';
                echo '<div class="info-value">' . $beforeDateCount . '</div>';
                echo '</div>';
                
                $fepProcessor->filterByDateRange(
                    $loadUnloadData->getLoadDateTime(),
                    $loadUnloadData->getUnloadDateTime()
                );
                
                $finalCount = $fepProcessor->getTransactionCount();
                $totalAmount = $fepProcessor->calculateTotalAmount();
                
                echo '<div class="info-box">';
                echo '<div class="info-label">6. After Date Range Filter</div>';
                echo '<div class="info-value">' . $finalCount . '</div>';
                echo '<div class="info-label" style="margin-top: 5px;">Excluded: ' . ($beforeDateCount - $finalCount) . '</div>';
                echo '</div>';
                
                echo '<div class="info-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">';
                echo '<div class="info-label" style="color: white;">Final Total Amount</div>';
                echo '<div class="info-value" style="color: white;">₦' . number_format($totalAmount, 2) . '</div>';
                echo '<div class="info-label" style="margin-top: 5px; color: white;">' . $finalCount . ' transactions</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Show date-filtered transactions
                echo '<div class="section">';
                echo '<h2>Transactions Excluded by Date Filter</h2>';
                echo '<p style="margin-bottom: 15px;">Load Time: <strong>' . $loadUnloadData->getLoadDateTime()->format('Y-m-d H:i:s A') . '</strong><br>';
                echo 'Unload Time: <strong>' . $loadUnloadData->getUnloadDateTime()->format('Y-m-d H:i:s A') . '</strong></p>';
                
                echo '<table>';
                echo '<thead><tr>';
                echo '<th>Request Date</th>';
                echo '<th>Amount</th>';
                echo '<th>RRN</th>';
                echo '<th>Status</th>';
                echo '<th>Reason</th>';
                echo '</tr></thead><tbody>';
                
                $headers = $fepProcessor->getHeaders();
                $rrn_idx = array_search('RETRIEVAL REFFERENCE NR', $headers);
                $date_idx = array_search('REQUEST DATE', $headers);
                $amount_idx = array_search('AMOUNT', $headers);
                $response_idx = array_search('RESPONSE MEANING', $headers);
                
                $finalData = $fepProcessor->getData();
                $finalRRNs = array_column($finalData, $rrn_idx);
                
                foreach ($beforeDateFilter as $row) {
                    $rrn = $row[$rrn_idx] ?? '';
                    
                    // Skip if this transaction is in the final data
                    if (in_array($rrn, $finalRRNs)) {
                        continue;
                    }
                    
                    $dateStr = $row[$date_idx] ?? '';
                    $amount = $row[$amount_idx] ?? '';
                    $response = $row[$response_idx] ?? '';
                    
                    if (empty($dateStr)) continue;
                    
                    $transDate = DateTime::createFromFormat('d/m/Y g:i A', $dateStr);
                    if (!$transDate) {
                        $transDate = new DateTime($dateStr);
                    }
                    
                    $reason = '';
                    $class = 'excluded';
                    if ($transDate < $loadUnloadData->getLoadDateTime()) {
                        $reason = 'Before Load Time';
                    } elseif ($transDate > $loadUnloadData->getUnloadDateTime()) {
                        $reason = 'After Unload Time';
                    }
                    
                    echo "<tr class='$class'>";
                    echo "<td>$dateStr</td>";
                    echo "<td>₦" . number_format((float)$amount, 2) . "</td>";
                    echo "<td>$rrn</td>";
                    echo "<td>$response</td>";
                    echo "<td><strong>$reason</strong></td>";
                    echo "</tr>";
                }
                
                echo '</tbody></table>';
                echo '</div>';
                
                // Final reconciliation
                $glDiff = $loadUnloadData->getDifference();
                $variance = $glDiff - $totalAmount;
                
                echo '<div class="section">';
                echo '<h2>Final Reconciliation</h2>';
                echo '<div class="info-grid">';
                echo '<div class="info-box">';
                echo '<div class="info-label">GL Load - Unload</div>';
                echo '<div class="info-value">₦' . number_format($glDiff, 2) . '</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">FEP Total (Filtered)</div>';
                echo '<div class="info-value">₦' . number_format($totalAmount, 2) . '</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">Variance</div>';
                echo '<div class="info-value">₦' . number_format($variance, 2) . '</div>';
                echo '</div>';
                echo '<div class="info-box">';
                echo '<div class="info-label">Status</div>';
                if (abs($variance) < 0.01) {
                    echo '<div class="info-value" style="color: #2a2;">BALANCED</div>';
                } elseif ($variance < 0) {
                    echo '<div class="info-value" style="color: #c33; font-size: 14px;">GL NOT ON FEP</div>';
                } else {
                    echo '<div class="info-value" style="color: #f90; font-size: 14px;">FEP NOT ON GL</div>';
                }
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                @unlink($glTempPath);
                @unlink($fepTempPath);
                
            } catch (Exception $e) {
                echo '<div style="background: #fee; color: #c33; padding: 20px; border-radius: 12px;">';
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