<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATM Reconciliation System</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="centered-layout">
    <div class="container">
        <h1>ATM Reconciliation System</h1>
        <p class="subtitle">Upload GL and FEP files for automatic reconciliation</p>

        <div class="alert alert-error">
            <h3>⚠️ Important Notice</h3>
            <ul>
                <li><strong>Monthly Cycle Only:</strong> Both GL and FEP files must contain transactions from a <strong>single month cycle</strong> only</li>
                <li>Do not upload files with transactions spanning multiple months</li>
                <li>Example: Upload January transactions only, February transactions only, etc.</li>
            </ul>
        </div>
        
        <div class="alert alert-info">
            <h3>How it works:</h3>
            <ul>
                <li>Upload your GL file (contains load/unload transactions)</li>
                <li>Upload your FEP file (contains transaction records)</li>
                <li>System will automatically process and reconcile the files</li>
                <li>Download the processed files and view reconciliation results</li>
            </ul>
            <h3 style="margin-top: 12px;">File Requirements:</h3>
            <ul>
                <li><strong>Format:</strong> Excel .xlsx or CSV (.csv)</li>
                <li><strong>Size:</strong> Maximum 5MB per file</li>
                <li><strong>Note:</strong> CSV format is 60x faster! Older .xls format is not supported</li>
            </ul>
        </div>
        
        <form action="process.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label for="gl_file">GL File (Excel or CSV)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-label" for="gl_file">
                        📁 Choose GL File
                    </label>
                    <input type="file" id="gl_file" name="gl_file" accept=".xlsx,.csv" required>
                </div>
                <div class="file-name" id="gl_file_name"></div>
                <div class="alert alert-warning" style="margin-top: 8px; padding: 8px;">
                    <p style="font-size: 12px; margin: 0;"><strong>Requirements:</strong> .xlsx or .csv format | Max 5MB | CSV recommended (faster)</p>
                </div>
            </div>
            
            <div class="form-group">
                <label for="fep_file">FEP File (Excel or CSV)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-label" for="fep_file">
                        📁 Choose FEP File
                    </label>
                    <input type="file" id="fep_file" name="fep_file" accept=".xlsx,.csv" required>
                </div>
                <div class="file-name" id="fep_file_name"></div>
                <div class="alert alert-warning" style="margin-top: 8px; padding: 8px;">
                    <p style="font-size: 12px; margin: 0;"><strong>Requirements:</strong> .xlsx or .csv format | Max 5MB | CSV recommended (faster)</p>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block" id="submitBtn">
                Process Files
            </button>
            
            <div id="errorMessage" style="display: none;"></div>
        </form>
    </div>
    
    <script src="assets/js/app.js"></script>
    <script>
        // Initialize file inputs with validation
        ATMRecon.initializeFileInput('gl_file', 'gl_file_name', 'GL File');
        ATMRecon.initializeFileInput('fep_file', 'fep_file_name', 'FEP File');
        
        // Initialize form validation
        ATMRecon.initializeUploadForm('uploadForm', {
            files: [
                { inputId: 'gl_file', fieldName: 'GL File' },
                { inputId: 'fep_file', fieldName: 'FEP File' }
            ]
        });
    </script>
</body>
</html>