<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATM Reconciliation System</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .upload-section {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            color: #555;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-button {
            display: block;
            width: 100%;
            padding: 12px 20px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            color: #666;
        }
        
        .file-input-button:hover {
            background: #e9ecef;
            border-color: #667eea;
        }
        
        .file-name {
            margin-top: 8px;
            font-size: 12px;
            color: #28a745;
            font-weight: 500;
        }
        
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
            margin-top: 20px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .info-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #2196F3;
        }
        
        .info-box h3 {
            color: #1976D2;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #555;
            font-size: 13px;
            line-height: 1.8;
        }
        
        .file-requirements {
            background: #fff3cd;
            padding: 12px;
            border-radius: 8px;
            margin-top: 8px;
            border-left: 4px solid #ffc107;
        }
        
        .file-requirements p {
            color: #856404;
            font-size: 12px;
            margin: 2px 0;
        }
        
        .warning-box {
            background: #ffebee;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #d32f2f;
        }
        
        .warning-box h3 {
            color: #c62828;
            margin-bottom: 8px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning-box ul {
            margin-left: 20px;
            color: #b71c1c;
            font-size: 13px;
            line-height: 1.8;
        }
        
        .warning-box strong {
            color: #c62828;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 ATM Reconciliation System</h1>
        <p class="subtitle">Upload GL and FEP files for automatic reconciliation</p>
        
        <div class="warning-box">
            <h3>⚠️ Important Notice</h3>
            <ul>
                <li><strong>Monthly Cycle Only:</strong> Both GL and FEP files must contain transactions from a <strong>single month cycle</strong> only</li>
                <li>Do not upload files with transactions spanning multiple months</li>
                <li>Example: Upload January transactions only, February transactions only, etc.</li>
            </ul>
        </div>
        
        <div class="info-box">
            <h3>How it works:</h3>
            <ul>
                <li>Upload your GL file (contains load/unload transactions)</li>
                <li>Upload your FEP file (contains transaction records)</li>
                <li>System will automatically process and reconcile the files</li>
                <li>Download the processed files and view reconciliation results</li>
            </ul>
            <h3 style="margin-top: 12px;">File Requirements:</h3>
            <ul>
                <li><strong>Format:</strong> Excel .xlsx only (Excel 2007 or later)</li>
                <li><strong>Size:</strong> Maximum 1MB per file</li>
                <li><strong>Note:</strong> Older .xls format is not supported</li>
            </ul>
        </div>
        
        <form action="process.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-section">
                <label for="gl_file">GL File (Excel)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-button" for="gl_file">
                        📁 Choose GL File
                    </label>
                    <input type="file" id="gl_file" name="gl_file" accept=".xlsx" required>
                </div>
                <div class="file-name" id="gl_file_name"></div>
                <div class="file-requirements">
                    <p><strong>Requirements:</strong> .xlsx format only | Max 1MB</p>
                </div>
            </div>
            
            <div class="upload-section">
                <label for="fep_file">FEP File (Excel)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-button" for="fep_file">
                        📁 Choose FEP File
                    </label>
                    <input type="file" id="fep_file" name="fep_file" accept=".xlsx" required>
                </div>
                <div class="file-name" id="fep_file_name"></div>
                <div class="file-requirements">
                    <p><strong>Requirements:</strong> .xlsx format only | Max 1MB</p>
                </div>
            </div>
            
            <button type="submit" class="submit-btn" id="submitBtn">
                🚀 Process Files
            </button>
            
            <div id="errorMessage" class="error-message" style="display: none;"></div>
        </form>
    </div>
    
    <script>
        const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB in bytes
        const ALLOWED_EXTENSION = '.xlsx';
        
        function validateFile(file, fieldName) {
            const errors = [];
            
            // Check if file exists
            if (!file) {
                errors.push(`${fieldName} is required`);
                return errors;
            }
            
            // Check file size
            if (file.size > MAX_FILE_SIZE) {
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                errors.push(`${fieldName} is too large (${sizeMB}MB). Maximum size is 1MB`);
            }
            
            // Check file extension
            const fileName = file.name.toLowerCase();
            if (!fileName.endsWith(ALLOWED_EXTENSION)) {
                errors.push(`${fieldName} must be in .xlsx format (Excel 2007+)`);
            }
            
            return errors;
        }
        
        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            
            // Scroll to error
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function hideError() {
            document.getElementById('errorMessage').style.display = 'none';
        }
        
        function updateFileName(inputId, displayId) {
            const input = document.getElementById(inputId);
            const display = document.getElementById(displayId);
            
            input.addEventListener('change', function(e) {
                hideError();
                const file = e.target.files[0];
                
                if (file) {
                    const fieldName = inputId === 'gl_file' ? 'GL File' : 'FEP File';
                    const errors = validateFile(file, fieldName);
                    
                    if (errors.length > 0) {
                        display.textContent = '❌ ' + errors.join(', ');
                        display.style.color = '#c33';
                        e.target.value = ''; // Clear invalid file
                    } else {
                        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                        display.textContent = `✓ ${file.name} (${sizeMB}MB)`;
                        display.style.color = '#28a745';
                    }
                } else {
                    display.textContent = '';
                }
            });
        }
        
        // Initialize file name displays
        updateFileName('gl_file', 'gl_file_name');
        updateFileName('fep_file', 'fep_file_name');
        
        // Form submission validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            hideError();
            
            const glFile = document.getElementById('gl_file').files[0];
            const fepFile = document.getElementById('fep_file').files[0];
            
            // Validate both files
            const glErrors = validateFile(glFile, 'GL File');
            const fepErrors = validateFile(fepFile, 'FEP File');
            
            const allErrors = [...glErrors, ...fepErrors];
            
            if (allErrors.length > 0) {
                showError('❌ Validation Error: ' + allErrors.join(' | '));
                return false;
            }
            
            // All validations passed, submit form
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = '⏳ Processing...';
            this.submit();
        });
    </script>
</body>
</html>