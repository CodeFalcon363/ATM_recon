# Troubleshooting Guide

**Last Updated:** 2025-11-03

Common issues and solutions for the ATM GL/FEP Reconciliation System. For detailed technical information, see [README.md](README.md) and [TRANSACTION_MATCHING.md](TRANSACTION_MATCHING.md).

## 🔐 Password-Protected Excel Files

### Problem
Excel files are **password-protected or have workbook protection** enabled.

**Error Signature:** `ECMA-376 Encrypted file missing /EncryptionInfo`

**Common Causes:**
- Files encrypted with password
- Workbook protection enabled
- Sheet protection enabled
- Legacy Excel 97-2003 format (.xls) with .xlsx extension

---

## ✅ Solution Steps

### Method 1: Remove Password Protection (Recommended)

#### If you know the password:

1. **Open the file in Microsoft Excel**
2. Enter the password when prompted
3. Go to **File → Info → Protect Workbook**
4. Click **"Encrypt with Password"**
5. **Delete the password** (leave the field empty)
6. Click **OK**
7. **Save the file** (Ctrl+S or File → Save)

#### If you don't know the password but have edit access:

1. **Open the file in Microsoft Excel**
2. Go to **Review** tab
3. Click **"Unprotect Workbook"** or **"Unprotect Sheet"**
4. If prompted for a password, you'll need it to proceed
5. **Save the file**

### Method 2: Save in Modern Format

1. **Open the file in Microsoft Excel**
2. Go to **File → Save As**
3. Choose **"Excel Workbook (*.xlsx)"** from the format dropdown
4. Enter a new filename (e.g., `gl_file_unprotected.xlsx`)
5. Click **Save**
6. **Important:** Make sure to remove any protection first!

### Method 3: Use Google Sheets (Alternative)

1. **Upload the file to Google Drive**
2. **Open with Google Sheets**
3. Google Sheets will convert and may bypass some protections
4. **File → Download → Microsoft Excel (.xlsx)**
5. Use the downloaded file

---

## 🔍 Using the Debug Tool

After removing protection, use the debug tool to inspect your files:

1. Navigate to `http://localhost/debug.php`
2. Upload your GL or FEP file
3. The tool will show:
   - All column names
   - Sample data (first 10 rows)
   - Automatically detected key columns
   - Total row and column counts

This helps verify that:
- ✅ The file is readable
- ✅ All required columns exist
- ✅ Data is in the expected format

---

## 📋 Required Columns

### GL File Must Have:

| Column Purpose | Possible Names |
|----------------|----------------|
| Description | "Description", "Narration", "Details", "Transaction Details" |
| Amount | "Amount", "Figure", "Value", "Debit", "Credit" |
| Date/Time | "Date", "Time", "DateTime", "Transaction Date" |

**Description Column Must Contain:**
- Entries with the word "**load**" (for first load transaction)
- Entries with the word "**unload**" (for last unload transaction)

### FEP File Must Have:

| Column Purpose | Possible Names |
|----------------|----------------|
| Response Meaning | "Response Meaning", "Response", "Status", "Transaction Status" |
| Retrieval Reference | "Retrieval Reference Nr", "Retrieval Ref", "Reference Number", "RRN" |
| Request Date | "Request Date", "Transaction Date", "Date", "DateTime" |
| Amount | "Amount", "Value", "Transaction Amount" |

**Response Meaning Column Must Contain:**
- Entries with "**approved**" (these will be kept)
- Other entries will be filtered out

---

## 🛠️ Advanced Configuration

If your column names don't match the standard patterns, edit `config/columns.php`:

```php
'gl' => [
    'description_keywords' => ['your_column_name', 'description'],
    'amount_keywords' => ['your_amount_column'],
    // ... add your specific column names
],
```

---

## 🧪 Testing Your Files

### Quick Test Process:

1. **Remove all protection** from both files
2. **Run the debug tool** (`debug.php`) on each file
3. **Verify** all required columns are detected
4. **Upload to main app** (`index.php`)
5. **Review results**

### What Should Happen:

- GL file processing extracts first "load" and last "unload"
- FEP file filters to only "approved" transactions
- Duplicates in FEP are removed (based on Retrieval Reference Nr)
- FEP transactions are filtered to date range between load and unload
- Total amounts are compared
- Reconciliation status is displayed

---

## 📞 Common Errors and Fixes

### Error: "Column not found in GL file"
**Solution:** Run debug tool to see actual column names, then update `config/columns.php`

### Error: "Could not find load and unload entries"
**Solution:** 
- Check that Description column contains the words "load" and "unload"
- Verify the entries exist in the data
- Check for spelling variations

### Error: "Required columns not found in FEP file"
**Solution:** 
- Run debug tool on FEP file
- Verify column names match expected patterns
- Update `config/columns.php` if needed

### Error: "File upload failed"
**Solution:**
- Check file size (must be under PHP's upload limit)
- Verify file is actually .xlsx or .xls format
- Ensure no protection/encryption on file

### Error: Session expired / File not found
**Solution:**
- Download processed files immediately after processing
- Don't navigate away during processing
- Re-run the reconciliation if needed

---

## 🎯 Next Steps

1. **Remove protection** from both Excel files
2. **Test with debug tool** to verify file structure
3. **Run reconciliation** via main application
4. **Download processed files** and review results

---

## 💡 Pro Tips

- Always keep backup copies of original files
- Remove protection before uploading to save processing time
- Use the debug tool first if you're unsure about file structure
- Modern .xlsx format is preferred over legacy .xls format
- Keep file sizes reasonable (under 50MB) for best performance

---

## 📊 Expected Output

After successful processing, you should see:

- **Load Amount**: Total from first "load" entry in GL
- **Unload Amount**: Total from last "unload" entry in GL
- **FEP Transactions**: Sum of approved transactions in date range
- **Status**: One of:
  - ✅ "LOAD TO LOAD IS BALANCED"
  - ⚠️ "LIKELY GL NOT ON FEP EXIST"
  - ⚠️ "LIKELY FEP NOT ON GL EXIST"
- **Download Links**: For both processed files

---

Need more help? Check the main README.md or review the sample files in the examples directory.