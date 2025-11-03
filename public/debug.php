<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\ExcelReader;

/**
 * Debug Tool - Inspect Excel File Structure
 * 
 * This tool helps you understand the structure of your Excel files
 * including column names, sample data, and potential issues.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel File Inspector</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
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
            color: #555;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 2px dashed #ddd;
            border-radius: 8px;
        }
        
        button {
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .info-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #2196F3;
        }
        
        .error-box {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
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
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }
        
        .column-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
        }
        
        .highlight {
            background: #fff3cd;
            padding: 2px 5px;
            border-radius: 3px;
        }
        
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Excel File Inspector</h1>
        <p class="subtitle">Upload an Excel file to inspect its structure and contents</p>
        
        <div class="info-box">
            <strong>Purpose:</strong> This tool helps you understand your Excel file structure, 
            including column names, data types, and sample data. Use this to troubleshoot 
            any issues with the reconciliation process.
            <br><br>
            <strong>Column Requirements:</strong>
            <ul style="margin-left: 20px; margin-top: 10px; line-height: 1.8;">
                <li><strong>GL File:</strong> Description (with RRNs), Credit, Debit, Date columns required</li>
                <li><strong>FEP File:</strong> Retrieval Reference (RRN), Response/Status, Amount, Request Date, Transaction Type columns required</li>
            </ul>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="upload-section">
                <label for="excel_file">Select Excel File</label>
                <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
            </div>
            <button type="submit">📊 Inspect File</button>
        </form>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
            try {
                $tempPath = sys_get_temp_dir() . '/inspect_' . time() . '.xlsx';
                move_uploaded_file($_FILES['excel_file']['tmp_name'], $tempPath);
                
                $reader = new ExcelReader();
                $reader->loadFile($tempPath);
                
                $headers = $reader->getHeaders();
                $data = $reader->toArray();
                $sheet = $reader->getSpreadsheet()->getActiveSheet();
                
                echo '<div class="results">';
                echo '<h2>📋 File Information</h2>';
                echo '<div class="column-info">';
                echo '<strong>File:</strong> ' . htmlspecialchars($_FILES['excel_file']['name']) . '<br>';
                echo '<strong>Sheet Name:</strong> ' . htmlspecialchars($sheet->getTitle()) . '<br>';
                echo '<strong>Total Rows:</strong> ' . count($data) . '<br>';
                echo '<strong>Total Columns:</strong> ' . count($headers) . '<br>';
                echo '</div>';
                
                echo '<h3>Column Names</h3>';
                echo '<div class="column-info">';
                foreach ($headers as $index => $header) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    echo '<strong>' . $columnLetter . ':</strong> ' . htmlspecialchars($header) . '<br>';
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
                echo '<h3>🔎 Column Detection</h3>';
                echo '<div class="column-info">';
                echo '<p style="margin-bottom: 10px; font-size: 13px; color: #666;">The system uses permissive matching to detect columns. Multiple keywords are checked for each column type.</p>';
                
                $detectedColumns = [];
                
                foreach ($headers as $index => $header) {
                    $headerLower = strtolower(trim($header));
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    
                    if (strpos($headerLower, 'description') !== false || 
                        strpos($headerLower, 'narration') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Description column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if (strpos($headerLower, 'credit') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Credit column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if (strpos($headerLower, 'debit') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Debit column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    // Response/Status - supports multiple formats
                    if ((strpos($headerLower, 'response') !== false) || 
                        (strpos($headerLower, 'rsp') !== false) ||
                        strpos($headerLower, 'status') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Response/Status column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if ((strpos($headerLower, 'retrieval') !== false && 
                        (strpos($headerLower, 'reference') !== false || strpos($headerLower, 'ref') !== false)) ||
                        strpos($headerLower, 'rrn') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Retrieval Reference (RRN): "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if (strpos($headerLower, 'amount') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Amount column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if (strpos($headerLower, 'date') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Date column: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
                    }
                    
                    if (strpos($headerLower, 'tran') !== false && strpos($headerLower, 'type') !== false) {
                        $detectedColumns[] = '<span class="highlight">✅ Transaction Type: "' . 
                                             htmlspecialchars($header) . '" (Column ' . $colLetter . ')</span>';
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
                echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
                
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