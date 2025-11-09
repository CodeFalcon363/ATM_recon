<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\UniversalFileReader;

/**
 * Debug Tool - Inspect File Structure (CSV & Excel)
 *
 * This tool helps you understand the structure of your files
 * including column names, sample data, and potential issues.
 * Supports both CSV and Excel formats with automatic detection.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel File Inspector</title>
    <style>
        :root {
            /* Light mode (default) */
            --bg-gradient-start: #667eea;
            --bg-gradient-end: #764ba2;
            --card-bg: #ffffff;
            --text-primary: #333333;
            --text-secondary: #666666;
            --text-tertiary: #555555;
            --border-color: #ddd;
            --input-bg: #ffffff;
            --input-border: #ddd;
            --shadow-color: rgba(0, 0, 0, 0.3);
            --shadow-hover: rgba(102, 126, 234, 0.4);
            --info-bg: #e7f3ff;
            --info-border: #2196F3;
            --info-text: #333333;
            --error-bg: #fee;
            --error-border: #c33;
            --error-text: #c33;
            --highlight-bg: #fff3cd;
            --table-header-bg: #f8f9fa;
            --table-header-text: #555;
            --table-border: #ddd;
            --pre-bg: #f4f4f4;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-gradient-start: #1e293b;
                --bg-gradient-end: #0f172a;
                --card-bg: #1e293b;
                --text-primary: #f1f5f9;
                --text-secondary: #94a3b8;
                --text-tertiary: #cbd5e1;
                --border-color: #334155;
                --input-bg: #0f172a;
                --input-border: #475569;
                --shadow-color: rgba(0, 0, 0, 0.5);
                --shadow-hover: rgba(102, 126, 234, 0.6);
                --info-bg: #1e3a5f;
                --info-border: #3b82f6;
                --info-text: #93c5fd;
                --error-bg: #7f1d1d;
                --error-border: #ef4444;
                --error-text: #fecaca;
                --highlight-bg: #4a3c1a;
                --table-header-bg: #0f172a;
                --table-header-text: #cbd5e1;
                --table-border: #334155;
                --pre-bg: #0f172a;
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
            max-width: 1200px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 20px;
            box-shadow: 0 20px 60px var(--shadow-color);
            padding: 40px;
            transition: all 0.3s ease;
        }

        h1 {
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .subtitle {
            color: var(--text-secondary);
            margin-bottom: 30px;
        }
        
        form {
            margin-bottom: 30px;
        }
        
        .upload-section {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            color: var(--text-tertiary);
            font-weight: 600;
            margin-bottom: 8px;
        }

        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
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

        .results {
            margin-top: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--table-border);
            color: var(--text-primary);
        }

        th {
            background: var(--table-header-bg);
            font-weight: 600;
            color: var(--table-header-text);
        }

        .column-info {
            background: var(--table-header-bg);
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            color: var(--text-primary);
        }

        .highlight {
            background: var(--highlight-bg);
            padding: 2px 5px;
            border-radius: 3px;
        }

        pre {
            background: var(--pre-bg);
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12px;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>File Inspector (CSV & Excel)</h1>
        <p class="subtitle">Upload a CSV or Excel file to inspect its structure and contents</p>

        <div class="info-box">
            <strong>Purpose:</strong> This tool helps you understand your file structure,
            including column names, data types, and sample data. Use this to troubleshoot
            any issues with the reconciliation process.
            <br><br>
            <strong>Supported Formats:</strong> CSV (.csv) and Excel (.xlsx) with automatic detection
            <br><br>
            <strong>Column Requirements:</strong>
            <ul style="margin-left: 20px; margin-top: 10px; line-height: 1.8;">
                <li><strong>GL File:</strong> Description/Narration/Narrative (with RRNs), Credit/Credit_Amount, Debit/Debit_Amount, Date columns required</li>
                <li><strong>FEP File:</strong> Retrieval Reference (RRN), Response/Status, Amount, Request Date, Transaction Type columns required</li>
            </ul>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="upload-section">
                <label for="data_file">Select File (CSV or Excel)</label>
                <input type="file" id="data_file" name="data_file" accept=".csv,.xlsx,.xls" required>
            </div>
            <button type="submit">Inspect File</button>
        </form>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['data_file'])) {
            try {
                // Preserve file extension for format detection
                $fileExtension = strtolower(pathinfo($_FILES['data_file']['name'], PATHINFO_EXTENSION));
                $tempPath = sys_get_temp_dir() . '/inspect_' . time() . '.' . $fileExtension;
                move_uploaded_file($_FILES['data_file']['tmp_name'], $tempPath);

                // Detect format and use appropriate reader
                $fileType = UniversalFileReader::getFileType($tempPath);
                $reader = UniversalFileReader::create($tempPath);
                $reader->loadFile($tempPath);

                $headers = $reader->getHeaders();
                $data = $reader->toArray();

                echo '<div class="results">';
                echo '<h2>File Information</h2>';
                echo '<div class="column-info">';
                echo '<strong>File:</strong> ' . htmlspecialchars($_FILES['data_file']['name']) . '<br>';
                echo '<strong>Format:</strong> ' . strtoupper($fileType) . '<br>';

                // Show sheet name only for Excel files
                if ($fileType === 'xlsx' && method_exists($reader, 'getSpreadsheet')) {
                    $sheet = $reader->getSpreadsheet()->getActiveSheet();
                    echo '<strong>Sheet Name:</strong> ' . htmlspecialchars($sheet->getTitle()) . '<br>';
                }

                echo '<strong>Total Rows:</strong> ' . count($data) . '<br>';
                echo '<strong>Total Columns:</strong> ' . count($headers) . '<br>';
                echo '</div>';
                
                echo '<h3>Column Names</h3>';
                echo '<div class="column-info">';
                foreach ($headers as $index => $header) {
                    if ($fileType === 'xlsx') {
                        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                        echo '<strong>' . $columnLetter . ':</strong> ' . htmlspecialchars($header) . '<br>';
                    } else {
                        echo '<strong>Column ' . ($index + 1) . ':</strong> ' . htmlspecialchars($header) . '<br>';
                    }
                }
                echo '</div>';
                
                echo '<h3>Sample Data (First 10 Rows)</h3>';
                echo '<div style="overflow-x: auto;">';
                echo '<table>';
                echo '<thead><tr>';
                foreach ($headers as $header) {
                    echo '<th>' . htmlspecialchars($header) . '</th>';
                }
                echo '</tr></thead><tbody>';
                
                $sampleSize = min(10, count($data) - 1);
                for ($i = 1; $i <= $sampleSize; $i++) {
                    echo '<tr>';
                    foreach ($data[$i] as $cell) {
                        $cellValue = htmlspecialchars(substr((string)$cell, 0, 100));
                        if (strlen((string)$cell) > 100) {
                            $cellValue .= '...';
                        }
                        echo '<td>' . $cellValue . '</td>';
                    }
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
                echo '</div>';
                
                // Search for key columns
                echo '<h3>Column Detection</h3>';
                echo '<div class="column-info">';
                echo '<p style="margin-bottom: 10px; font-size: 13px; color: #666;">The system uses permissive matching to detect columns. Multiple keywords are checked for each column type.</p>';
                
                $detectedColumns = [];
                
                foreach ($headers as $index => $header) {
                    $headerLower = strtolower(trim($header));
                    $colIndex = $fileType === 'csv' ? ($index + 1) : null;

                    // For Excel, show column letter; for CSV, show index
                    if ($fileType === 'xlsx') {
                        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                        $colIdentifier = 'Column ' . $colLetter;
                    } else {
                        $colIdentifier = 'Column ' . ($index + 1);
                    }

                    if (strpos($headerLower, 'description') !== false ||
                        strpos($headerLower, 'narration') !== false ||
                        strpos($headerLower, 'narrative') !== false) {
                        $detectedColumns[] = '<span class="highlight">Description column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if (strpos($headerLower, 'credit') !== false && strpos($headerLower, 'count') === false) {
                        $detectedColumns[] = '<span class="highlight">Credit column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if (strpos($headerLower, 'debit') !== false && strpos($headerLower, 'count') === false) {
                        $detectedColumns[] = '<span class="highlight">Debit column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    // Response/Status - supports multiple formats
                    if ((strpos($headerLower, 'response') !== false) ||
                        (strpos($headerLower, 'rsp') !== false) ||
                        strpos($headerLower, 'status') !== false) {
                        $detectedColumns[] = '<span class="highlight">Response/Status column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if ((strpos($headerLower, 'retrieval') !== false &&
                        (strpos($headerLower, 'reference') !== false || strpos($headerLower, 'ref') !== false)) ||
                        strpos($headerLower, 'rrn') !== false) {
                        $detectedColumns[] = '<span class="highlight">Retrieval Reference (RRN): "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if (strpos($headerLower, 'amount') !== false) {
                        $detectedColumns[] = '<span class="highlight">Amount column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if (strpos($headerLower, 'date') !== false) {
                        $detectedColumns[] = '<span class="highlight">Date column: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }

                    if (strpos($headerLower, 'tran') !== false && strpos($headerLower, 'type') !== false) {
                        $detectedColumns[] = '<span class="highlight">Transaction Type: "' .
                                             htmlspecialchars($header) . '" (' . $colIdentifier . ')</span>';
                    }
                }
                
                if (!empty($detectedColumns)) {
                    foreach ($detectedColumns as $detection) {
                        echo $detection . '<br>';
                    }
                } else {
                    echo '<em>No standard columns automatically detected. You may need to configure column mappings manually.</em>';
                }
                
                echo '</div>';
                echo '</div>';
                
                @unlink($tempPath);
                
            } catch (Exception $e) {
                echo '<div class="error-box">';
                echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());

                if (strpos($e->getMessage(), 'password') !== false ||
                    strpos($e->getMessage(), 'encrypted') !== false) {
                    echo '<br><br><strong>Solution:</strong> The file appears to be password-protected. Please:';
                    echo '<ul style="margin-left: 20px; margin-top: 10px;">';
                    echo '<li>Open the file in Excel</li>';
                    echo '<li>Go to File → Info → Protect Workbook</li>';
                    echo '<li>Remove any password or protection</li>';
                    echo '<li>Save the file and try again</li>';
                    echo '</ul>';
                }
                
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