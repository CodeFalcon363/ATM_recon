<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\ReconciliationService;

session_start();

// Note: Temp file cleanup handled by cron job (scripts/cleanup_temp_files.php)
// for better performance with high concurrent users

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$errors = [];
$result = null;

define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_MIME_TYPES', [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv',
    'text/plain',
    'application/csv',
]);
define('ALLOWED_EXTENSIONS', ['xlsx', 'csv']);
function validateUploadedFile($file, $fieldName) {
    $errors = [];

    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "{$fieldName} is required";
        return $errors;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = "{$fieldName} exceeds maximum allowed size";
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors[] = "{$fieldName} was only partially uploaded";
                break;
            default:
                $errors[] = "Error uploading {$fieldName}";
        }
        return $errors;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        $sizeMB = round($file['size'] / (1024 * 1024), 5);
        $errors[] = "{$fieldName} is too large ({$sizeMB}MB). Maximum size is 5MB";
    }

    $fileName = $file['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($fileExtension, ALLOWED_EXTENSIONS)) {
        $errors[] = "{$fieldName} must be in .xlsx or .csv format. Uploaded: .{$fileExtension}";
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_MIME_TYPES)) {
        $errors[] = "{$fieldName} has invalid file type. Expected .xlsx or .csv format (got: {$mimeType})";
    }

    $handle = fopen($file['tmp_name'], 'rb');
    $signature = fread($handle, 4);
    fclose($handle);

    if ($fileExtension === 'xlsx') {
        if (substr($signature, 0, 2) !== 'PK') {
            $errors[] = "{$fieldName} does not appear to be a valid Excel file";
        }
    } elseif ($fileExtension === 'csv') {
        $firstBytes = substr($signature, 0, 3);
        if ($firstBytes === "\xEF\xBB\xBF") {
            // Valid UTF-8 BOM
        } else {
            $isPrintable = ctype_print($signature[0]) || ord($signature[0]) >= 0x20;
            if (!$isPrintable && $signature[0] !== "\n" && $signature[0] !== "\r") {
                $errors[] = "{$fieldName} does not appear to be a valid CSV file";
            }
        }
    }

    return $errors;
}

try {
    if (!isset($_FILES['gl_file']) || !isset($_FILES['fep_file'])) {
        throw new Exception('Both GL and FEP files are required');
    }

    $glErrors = validateUploadedFile($_FILES['gl_file'], 'GL File');
    if (!empty($glErrors)) {
        $errors = array_merge($errors, $glErrors);
    }

    $fepErrors = validateUploadedFile($_FILES['fep_file'], 'FEP File');
    if (!empty($fepErrors)) {
        $errors = array_merge($errors, $fepErrors);
    }

    if (!empty($errors)) {
        throw new Exception(implode('<br>', $errors));
    }

    $glExtension = strtolower(pathinfo($_FILES['gl_file']['name'], PATHINFO_EXTENSION));
    $fepExtension = strtolower(pathinfo($_FILES['fep_file']['name'], PATHINFO_EXTENSION));

    $glTempPath = sys_get_temp_dir() . '/gl_upload_' . time() . '.' . $glExtension;
    $fepTempPath = sys_get_temp_dir() . '/fep_upload_' . time() . '.' . $fepExtension;

    move_uploaded_file($_FILES['gl_file']['tmp_name'], $glTempPath);
    move_uploaded_file($_FILES['fep_file']['tmp_name'], $fepTempPath);

    $service = new ReconciliationService($glTempPath, $fepTempPath);
    $result = $service->process();

    $_SESSION['processed_gl'] = $service->getProcessedGLPath();
    $_SESSION['processed_fep'] = $service->getProcessedFEPPath();

    $transactionMatch = $service->getTransactionMatch();
    if ($transactionMatch) {
        $_SESSION['transaction_match'] = serialize($transactionMatch);
    }

    @unlink($glTempPath);
    @unlink($fepTempPath);
    
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation Results</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        /* Page-specific styles for results page */
        body {
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .result-card {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: 0 20px 60px var(--shadow-lg);
            padding: 40px;
            margin-bottom: 20px;
        }

        h1 {
            margin-bottom: 30px;
            font-size: 32px;
            text-align: center;
        }

        .status-badge {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 30px;
            text-align: center;
            width: 100%;
            color: white;
        }

        .status-balanced {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .status-gl-missing {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .status-fep-missing {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            background: var(--bg-section);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--theme-primary);
        }

        .info-label {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .info-value {
            color: var(--text-primary);
            font-size: 20px;
            font-weight: bold;
        }

        .info-value.amount {
            color: var(--theme-primary);
        }

        .info-value.negative {
            color: var(--status-error);
        }

        .info-value.positive {
            color: var(--status-success);
        }

        .download-section {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .download-btn {
            flex: 1;
            min-width: 200px;
            padding: 15px 25px;
            background: linear-gradient(135deg, var(--theme-primary) 0%, var(--accent-primary-dark) 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: block;
        }

        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px var(--shadow-hover);
        }

        .back-btn {
            background: linear-gradient(135deg, #a8a8a8 0%, #7a7a7a 100%);
        }

        .details-section {
            background: var(--bg-section);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .details-section h3 {
            color: var(--text-primary);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 600;
        }
        
        /* Dark mode adjustments */
        @media (prefers-color-scheme: dark) {
            .status-balanced {
                background: linear-gradient(135deg, #047857 0%, #10b981 100%);
            }
            
            .status-gl-missing {
                background: linear-gradient(135deg, #be123c 0%, #fb7185 100%);
            }
            
            .status-fep-missing {
                background: linear-gradient(135deg, #ea580c 0%, #fbbf24 100%);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!empty($errors)): ?>
            <div class="result-card">
                <div class="alert alert-error">
                    <h2>Error Processing Files</h2>
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
                <a href="index.php" class="download-btn back-btn">← Back to Upload</a>
            </div>
        <?php elseif ($result): ?>
            <div class="result-card">
                <h1>Reconciliation Results</h1>

                <div class="status-badge status-<?php echo strtolower(str_replace('_', '-', $result->getStatus())); ?>">
                    <?php echo htmlspecialchars($result->getMessage()); ?>
                </div>

                <?php if ($result->getClosingBalance() !== null): ?>
                <!-- GL Variance Section (New) -->
                <div class="details-section" style="background: <?php
                    if (abs($result->getGLVariance()) < 0.01) {
                        echo '#d4edda';
                    } elseif ($result->getGLVariance() > 0) {
                        echo '#fff3cd';
                    } else {
                        echo '#f8d7da';
                    }
                ?>; border-left-color: <?php
                    if (abs($result->getGLVariance()) < 0.01) {
                        echo '#28a745';
                    } elseif ($result->getGLVariance() > 0) {
                        echo '#ffc107';
                    } else {
                        echo '#dc3545';
                    }
                ?>; margin-bottom: 30px;">
                    <h3 style="color: <?php
                        if (abs($result->getGLVariance()) < 0.01) {
                            echo '#155724';
                        } elseif ($result->getGLVariance() > 0) {
                            echo '#856404';
                        } else {
                            echo '#721c24';
                        }
                    ?>;">GL Balance Analysis</h3>

                    <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center;">
                        <div style="font-size: 20px; font-weight: bold; color: <?php
                            if (abs($result->getGLVariance()) < 0.01) {
                                echo '#155724';
                            } elseif ($result->getGLVariance() > 0) {
                                echo '#856404';
                            } else {
                                echo '#721c24';
                            }
                        ?>;">
                            <?php echo htmlspecialchars($result->getGLVarianceStatus()); ?>
                        </div>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">GL Balance (Closing):</span>
                        <span class="detail-value">₦<?php echo number_format($result->getClosingBalance(), 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Available Cash (Last Load Excluded):</span>
                        <span class="detail-value">₦<?php echo number_format($result->getAvailableCash(), 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Transactions After Cash Count (Before EOD):</span>
                        <span class="detail-value">₦<?php echo number_format($result->getTransactionsAfterCashCount(), 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Expected Cash:</span>
                        <span class="detail-value">₦<?php echo number_format($result->getExpectedCash(), 2); ?></span>
                    </div>
                    <div class="detail-row" style="background: <?php echo abs($result->getGLVariance()) < 0.01 ? '#e8f5e9' : '#fef3cd'; ?>; padding: 15px; margin-top: 10px; border-radius: 8px;">
                        <span class="detail-label" style="font-size: 16px;"><strong>Variance (GL Balance - Expected Cash):</strong></span>
                        <span class="detail-value" style="font-size: 18px; color: <?php
                            if (abs($result->getGLVariance()) < 0.01) {
                                echo '#28a745';
                            } elseif ($result->getGLVariance() > 0) {
                                echo '#ffc107';
                            } else {
                                echo '#dc3545';
                            }
                        ?>;">
                            ₦<?php echo number_format($result->getGLVariance(), 2); ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Load to Load Reconciliation -->
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Total Load Amount</div>
                        <div class="info-value amount">
                            ₦<?php echo number_format($result->getLoadAmount(), 2); ?>
                        </div>
                        <?php if ($result->getLoadCount() > 1): ?>
                            <div class="info-label" style="margin-top: 5px; font-size: 11px;">
                                <?php echo $result->getLoadCount(); ?> loads included
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Total Unload Amount</div>
                        <div class="info-value amount">
                            ₦<?php echo number_format($result->getUnloadAmount(), 2); ?>
                        </div>
                        <?php if ($result->getUnloadCount() > 1): ?>
                            <div class="info-label" style="margin-top: 5px; font-size: 11px;">
                                <?php echo $result->getUnloadCount(); ?> unloads included
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">NET LOAD PAID OUT</div>
                        <div class="info-value">
                            ₦<?php echo number_format($result->getLoadAmount() - $result->getUnloadAmount(), 2); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Valid FEP Transactions</div>
                        <div class="info-value amount">
                            ₦<?php echo number_format($result->getSuccessfulTransactions(), 2); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Valid Transaction Count after FEP processing</div>
                        <div class="info-value">
                            <?php echo number_format($result->getTransactionCount()); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">VARIANCE (Net load - Valid FEP)</div>
                        <div class="info-value <?php echo $result->getDifference() < 0 ? 'negative' : ($result->getDifference() > 0 ? 'positive' : ''); ?>">
                            ₦<?php echo number_format($result->getDifference(), 2); ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($result->getLoadCount() > 1 || $result->getUnloadCount() > 1): ?>
                <div class="details-section" style="background: #fff3cd; border-left-color: #ffc107;">
                    <h3>Multiple Load/Unload Cycles Detected</h3>
                    <div class="detail-row">
                        <span class="detail-label">Processing Mode:</span>
                        <span class="detail-value">Multi-Cycle Reconciliation</span>
                    </div>
                    <?php if ($result->getExcludedFirstUnload()): ?>
                    <div class="detail-row">
                        <span class="detail-label">Excluded First Unload (Previous Cycle):</span>
                        <span class="detail-value">₦<?php echo number_format($result->getExcludedFirstUnload(), 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($result->getExcludedLastLoad()): ?>
                    <div class="detail-row">
                        <span class="detail-label">Excluded Last Load (Next Cycle):</span>
                        <span class="detail-value">₦<?php echo number_format($result->getExcludedLastLoad(), 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($transactionMatch): ?>
                <div class="details-section">
                    <h3>Transaction-Level Matching</h3>
                    <div class="detail-row" style="background: <?php echo $transactionMatch->isFullyMatched() ? '#d4edda' : '#fff3cd'; ?>; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div style="text-align: center; width: 100%;">
                            <strong style="font-size: 18px;">
                                Match Rate: <?php echo number_format($transactionMatch->getMatchRate(), 1); ?>%
                            </strong>
                            <?php if ($transactionMatch->isFullyMatched()): ?>
                                <div style="color: #155724; margin-top: 5px;">All transactions matched</div>
                            <?php else: ?>
                                <div style="color: #856404; margin-top: 5px;">Some transactions unmatched</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                        <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center; border-left: 4px solid #28a745;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;">
                                <?php echo $transactionMatch->getMatchedCount(); ?>
                            </div>
                            <div style="font-size: 12px; color: #155724; margin-top: 5px;">Matched Transactions</div>
                            <div style="font-size: 14px; font-weight: bold; color: #155724; margin-top: 5px;">
                                ₦<?php echo number_format($transactionMatch->getMatchedAmount(), 2); ?>
                            </div>
                        </div>
                        
                        <div style="background: #e2e3e5; padding: 15px; border-radius: 8px; text-align: center; border-left: 4px solid #6c757d;">
                            <div style="font-size: 24px; font-weight: bold; color: #343a40;">
                                <?php echo $transactionMatch->getGlFoundInFilteredFepCount(); ?>
                            </div>
                            <div style="font-size: 12px; color: #343a40; margin-top: 5px;">GL Found in Filtered FEP</div>
                            <div style="font-size: 14px; font-weight: bold; color: #343a40; margin-top: 5px;">
                                ₦<?php echo number_format($transactionMatch->getGlFoundInFilteredFepAmount(), 2); ?>
                            </div>
                        </div>
                        
                        <div style="background: #f8d7da; padding: 15px; border-radius: 8px; text-align: center; border-left: 4px solid #dc3545;">
                            <div style="font-size: 24px; font-weight: bold; color: #721c24;">
                                <?php echo $transactionMatch->getGlNotOnFepCount(); ?>
                            </div>
                            <div style="font-size: 12px; color: #721c24; margin-top: 5px;">GL Not on FEP</div>
                            <div style="font-size: 14px; font-weight: bold; color: #721c24; margin-top: 5px;">
                                ₦<?php echo number_format($transactionMatch->getGlNotOnFepCreditTotal(), 2); ?> &nbsp;&amp;&nbsp; -₦<?php echo number_format($transactionMatch->getGlNotOnFepDebitTotal(), 2); ?>
                            </div>
                        </div>
                        
                        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center; border-left: 4px solid #ffc107;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;">
                                <?php echo $transactionMatch->getFepNotOnGlCount(); ?>
                            </div>
                            <div style="font-size: 12px; color: #856404; margin-top: 5px;">FEP Not on GL</div>
                            <div style="font-size: 14px; font-weight: bold; color: #856404; margin-top: 5px;">
                                ₦<?php echo number_format($transactionMatch->getFepNotOnGlAmount(), 2); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($transactionMatch->getNilledGlDuplicates() && count($transactionMatch->getNilledGlDuplicates()) > 0): ?>
                    <div style="margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #6c757d;">
                        <h4 style="color: #6c757d; margin-bottom: 10px;">GL Duplicate NILled Entries</h4>
                        <p style="font-size: 13px; color: #6c757d; margin-bottom: 10px;">These GL duplicate entries were NILled off because a reversal pair was detected — you can download them for review.</p>
                        <div style="max-height: 160px; overflow-y: auto; background: white; padding: 10px; border-radius: 5px;">
                            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #6c757d; color: white; text-align: left;">
                                        <th style="padding: 8px; width: 20%;">RRN</th>
                                        <th style="padding: 8px; width: 20%;">Date</th>
                                        <th style="padding: 8px; width: 40%;">Description</th>
                                        <th style="padding: 8px; width: 20%; text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($transactionMatch->getNilledGlDuplicates(), 0, 5) as $txn): ?>
                                    <tr style="border-bottom: 1px solid #dee2e6;">
                                        <?php
                                            $rrn = '';
                                            $date = '';
                                            $desc = '';
                                            $amt = 0.0;

                                            if (is_array($txn)) {
                                                if (isset($txn['rrn'])) {
                                                    $rrn = $txn['rrn'];
                                                } elseif (isset($txn[0])) {
                                                    $rrn = $txn[0];
                                                }

                                                if (isset($txn['date'])) {
                                                    $date = $txn['date'];
                                                } elseif (isset($txn[2])) {
                                                    $date = $txn[2];
                                                }

                                                if (isset($txn['description'])) {
                                                    $desc = $txn['description'];
                                                } else {
                                                    $desc = is_array($txn) ? implode(' | ', array_slice($txn, 0, 4)) : (string)$txn;
                                                }

                                                if (isset($txn['amount'])) {
                                                    $amt = $txn['amount'];
                                                } else {
                                                    foreach ($txn as $cell) {
                                                        if (is_numeric(str_replace([',',' '],'',$cell))) {
                                                            $amt = (float)str_replace([',',' '],'',$cell);
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        ?>
                                        <td style="padding: 8px; font-size: 12px; vertical-align: top;"><?php echo htmlspecialchars($rrn); ?></td>
                                        <td style="padding: 8px; font-size: 12px; vertical-align: top;"><?php echo htmlspecialchars($date); ?></td>
                                        <td style="padding: 8px; font-size: 12px; vertical-align: top;">
                                            <?php echo htmlspecialchars(substr($desc, 0, 140)); ?>
                                        </td>
                                        <td style="padding: 8px; font-size: 12px; vertical-align: top; text-align: right;">
                                            ₦<?php echo number_format((float)$amt, 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($transactionMatch->getGlNotOnFepCount() > 0): ?>
                    <div style="margin-top: 15px;">
                        <h4 style="color: #dc3545; margin-bottom: 10px;">GL Transactions Not Found in FEP (Truly Missing)</h4>
                        <div style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px;">
                            <table style="width: 100%; font-size: 12px;">
                                <thead>
                                    <tr style="background: #dc3545; color: white;">
                                        <th style="padding: 8px;">RRN</th>
                                        <th style="padding: 8px;">Credit</th>
                                        <th style="padding: 8px;">Debit</th>
                                        <th style="padding: 8px;">Date</th>
                                        <th style="padding: 8px;">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $glHeaders = $transactionMatch->getGlHeaders();
                                        $creditIdx = null; $debitIdx = null;
                                        foreach ($glHeaders as $i => $h) {
                                            $hl = strtolower(trim((string)$h));
                                            if ($creditIdx === null && strpos($hl, 'credit') !== false) $creditIdx = $i;
                                            if ($debitIdx === null && strpos($hl, 'debit') !== false) $debitIdx = $i;
                                        }

                                        foreach (array_slice($transactionMatch->getGlNotOnFep(), 0, 5) as $txn):
                                                $creditRaw = $txn['credit_raw'] ?? null;
                                                $debitRaw = $txn['debit_raw'] ?? null;

                                                if ($creditRaw === null || $debitRaw === null) {
                                                    $glRow = isset($txn['gl_row']) && is_array($txn['gl_row']) ? $txn['gl_row'] : [];
                                                    $creditRaw = ($creditIdx !== null && isset($glRow[$creditIdx])) ? $glRow[$creditIdx] : '';
                                                    $debitRaw = ($debitIdx !== null && isset($glRow[$debitIdx])) ? $glRow[$debitIdx] : '';
                                                }

                                                $parse = function($s) {
                                                    $s = preg_replace('/[^0-9.\-]/', '', (string)$s);
                                                    if ($s === '' || $s === '-') return 0.0;
                                                    return (float)$s;
                                                };

                                                $creditVal = $parse($creditRaw);
                                                $debitVal = $parse($debitRaw);

                                                if (abs($creditVal) < 0.0001 && abs($debitVal) < 0.0001) {
                                                    $signed = isset($txn['amount']) ? (float)$txn['amount'] : 0.0;
                                                    if ($signed < 0) {
                                                        $debitVal = abs($signed);
                                                    } else {
                                                        $creditVal = $signed;
                                                    }
                                                }
                                    ?>
                                    <tr style="border-bottom: 1px solid #dee2e6;">
                                        <td style="padding: 8px;"><?php echo htmlspecialchars($txn['raw_rrn'] ?? $txn['rrn']); ?></td>
                                        <td style="padding: 8px; text-align: right;">₦<?php echo number_format($creditVal, 2); ?></td>
                                        <td style="padding: 8px; text-align: right;">₦<?php echo number_format($debitVal, 2); ?></td>
                                        <td style="padding: 8px;"><?php echo htmlspecialchars($txn['date']); ?></td>
                                        <td style="padding: 8px; font-size: 11px;">
                                            <?php
                                                if (isset($txn['gl_row']) && is_array($txn['gl_row'])) {
                                                    echo htmlspecialchars(substr(implode(' | ', array_slice($txn['gl_row'], 0, 4)), 0, 100)) . '...';
                                                } else {
                                                    echo htmlspecialchars(substr($txn['description'], 0, 50)) . '...';
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($transactionMatch->getFepNotOnGlCount() > 0): ?>
                    <div style="margin-top: 15px;">
                        <h4 style="color: #ffc107; margin-bottom: 10px;">FEP Transactions Not Found in GL</h4>
                        <div style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px;">
                            <table style="width: 100%; font-size: 12px;">
                                <thead>
                                    <tr style="background: #ffc107; color: #333;">
                                        <th style="padding: 8px;">RRN</th>
                                        <th style="padding: 8px;">Amount</th>
                                        <th style="padding: 8px;">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($transactionMatch->getFepNotOnGl(), 0, 5) as $txn): ?>
                                    <tr style="border-bottom: 1px solid #dee2e6;">
                                        <td style="padding: 8px;"><?php echo htmlspecialchars($txn['rrn']); ?></td>
                                        <td style="padding: 8px;">₦<?php echo number_format($txn['amount'], 2); ?></td>
                                        <td style="padding: 8px;"><?php echo htmlspecialchars($txn['date']); ?></td>
                                        <td style="padding: 8px; font-size: 11px;">
                                            <?php
                                                if (isset($txn['fep_row']) && is_array($txn['fep_row'])) {
                                                    echo htmlspecialchars(substr(implode(' | ', array_slice($txn['fep_row'], 0, 4)), 0, 100));
                                                } else {
                                                    echo '';
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="details-section">
                    <h3>Transaction Period</h3>
                    <div class="detail-row">
                        <span class="detail-label">First Load Date:</span>
                        <span class="detail-value">
                            <?php echo $result->getLoadDateTime()->format('Y-m-d H:i:s'); ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Last Unload Date:</span>
                        <span class="detail-value">
                            <?php echo $result->getUnloadDateTime()->format('Y-m-d H:i:s'); ?>
                        </span>
                    </div>
                </div>
                
                <div class="download-section">
                    <a href="download.php?file=gl" class="download-btn">
                        Download Processed GL File
                    </a>
                    <a href="download.php?file=fep" class="download-btn">
                        Download Processed FEP File
                    </a>
                    <?php if ($transactionMatch): ?>
                    <a href="download.php?file=matched" class="download-btn" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        Download Matched Transactions
                    </a>
                    <?php if ($transactionMatch->getNilledGlDuplicates() && count($transactionMatch->getNilledGlDuplicates()) > 0): ?>
                    <a href="download.php?file=nilled_gl_duplicates" class="download-btn" style="background: linear-gradient(135deg, #6c757d 0%, #343a40 100%);">
                        Download NILled GL Duplicates
                    </a>
                    <?php endif; ?>
                    <a href="download.php?file=gl_not_fep" class="download-btn" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                        Download GL Not on FEP
                    </a>
                    <a href="download.php?file=fep_not_gl" class="download-btn" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                        Download FEP Not on GL
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="download-btn back-btn">
                        ← Process New Files
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>