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
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 ATM Reconciliation System</h1>
        <p class="subtitle">Upload GL and FEP files for automatic reconciliation</p>
        
        <div class="info-box">
            <h3>How it works:</h3>
            <ul>
                <li>Upload your GL file (contains load/unload transactions)</li>
                <li>Upload your FEP file (contains transaction records)</li>
                <li>System will automatically process and reconcile the files</li>
                <li>Download the processed files and view reconciliation results</li>
            </ul>
        </div>
        
        <form action="process.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-section">
                <label for="gl_file">GL File (Excel)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-button" for="gl_file">
                        📁 Choose GL File
                    </label>
                    <input type="file" id="gl_file" name="gl_file" accept=".xlsx,.xls" required>
                </div>
                <div class="file-name" id="gl_file_name"></div>
            </div>
            
            <div class="upload-section">
                <label for="fep_file">FEP File (Excel)</label>
                <div class="file-input-wrapper">
                    <label class="file-input-button" for="fep_file">
                        📁 Choose FEP File
                    </label>
                    <input type="file" id="fep_file" name="fep_file" accept=".xlsx,.xls" required>
                </div>
                <div class="file-name" id="fep_file_name"></div>
            </div>
            
            <button type="submit" class="submit-btn" id="submitBtn">
                🚀 Process Files
            </button>
        </form>
    </div>
    
    <script>
        document.getElementById('gl_file').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            document.getElementById('gl_file_name').textContent = fileName ? '✓ ' + fileName : '';
        });
        
        document.getElementById('fep_file').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            document.getElementById('fep_file_name').textContent = fileName ? '✓ ' + fileName : '';
        });
        
        document.getElementById('uploadForm').addEventListener('submit', function() {
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = '⏳ Processing...';
        });
    </script>
</body>
</html>