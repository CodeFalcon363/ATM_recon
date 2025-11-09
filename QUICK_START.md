# Quick Start Guide

**Last Updated:** 2025-11-03

Get the ATM GL/FEP Reconciliation System running in 5 minutes.

## Prerequisites

- PHP 7.4 or higher
- Composer (PHP package manager)
- A web server (Apache/Nginx) OR PHP's built-in server for testing

## Installation Steps

### 1. Install Dependencies

```bash
composer install
```

This installs:
- PhpSpreadsheet (Excel file handling)
- PHPUnit (testing framework, dev only)

### 2. Run the Application

**Option A: PHP Built-in Server (Quick Testing)**
```bash
cd public
php -S localhost:8000
```

**Option B: Apache/XAMPP (Production)**
- Place project in `C:\xampp\htdocs\ATM_recon`
- Ensure `mod_rewrite` is enabled in Apache
- Access via `http://localhost/ATM_recon/`

### 3. Access the Application

Open your browser to:
```
http://localhost:8000
```
(or `http://localhost/ATM_recon/` for Apache)

You'll see the upload form with a modern gradient design.

## Processing Files

### Step 1: Prepare Your Excel Files

**GL File Requirements:**
- Format: `.xlsx` (modern Excel format recommended)
- Must have columns: Description, Credit, Debit, Date
- Description must contain "load" and "unload" entries
- Remove password protection if present

**FEP File Requirements:**
- Format: `.xlsx`
- Must have columns: Response Meaning (or Response Code), Retrieval Reference Nr, Request Date, Amount, Transaction Type
- Should contain "approved" transactions or response codes "00"/"0"
- Remove password protection if present

### Step 2: Upload and Process

1. Click "Choose GL File" and select your General Ledger Excel file
2. Click "Choose FEP File" and select your Front-End Processor Excel file
3. Click "Process Files" button
4. Wait for processing (may take 30-60 seconds for large files)

### Step 3: Review Results

The results page shows:

**Summary Section:**
- Load Amount (cash sent to ATM)
- Unload Amount (cash returned from ATM)
- FEP Transactions Total
- Reconciliation Status (BALANCED / GL_MISSING / FEP_MISSING)
- Difference amount

**Multi-Cycle Detection (if applicable):**
- Yellow banner indicating multiple cycles detected
- Load/Unload counts
- Excluded edge transactions (first unload, last load)

**Transaction Matching Statistics:**
- Total matched transactions
- GL Not on FEP (credit and debit breakdowns)
- FEP Not on GL
- Nilled GL Duplicates (reversal pairs)
- Match rate percentage

**Preview Tables:**
- First 5 rows of unmatched transactions
- Color-coded status indicators

### Step 4: Download Results

Click download links for:
- `gl_processed.xlsx` - Processed GL data
- `fep_processed.xlsx` - Filtered FEP data
- `matched_transactions.xlsx` - Matched GL↔FEP transactions
- `gl_not_on_fep.xlsx` - GL missing from FEP
- `fep_not_on_gl.xlsx` - FEP missing from GL
- `nilled_gl_duplicates.xlsx` - Reversed GL pairs

## Understanding the Processing

### What Happens to GL Data:

1. **Column Detection**: Finds Description, Credit, Debit, Date columns automatically
2. **Load/Unload Extraction**:
   - LOAD = amounts in DEBIT column (cash leaving bank to ATM)
   - UNLOAD = amounts in CREDIT column (cash returning to bank)
3. **Reversal Detection**: Finds transactions with keywords like "reversal", "rvsl"
4. **Multi-Cycle Handling**:
   - Excludes first unload IF it occurred BEFORE earliest load (previous cycle)
   - Excludes last load IF it occurred AFTER latest unload (next cycle)
5. **Date Range**: Uses first load datetime → last unload datetime as window

### What Happens to FEP Data:

1. **Column Detection**: Finds Response, Retrieval Ref, Date, Amount, Tran Type columns
2. **Filter to Approved** (keeps only):
   - Text containing "approved" (without "not", "declined", "failed")
   - Response codes "00" or "0"
3. **Remove Duplicates** (by RRN):
   - INITIAL + REVERSAL pair → removes BOTH
   - Multiple INITIALs → removes ALL
   - Other duplicates → removes ALL instances
4. **Remove REVERSAL Type**: Standalone reversals filtered out
5. **Sort by Date**: Chronological order
6. **Filter by Date Range**: Only keeps transactions within GL load→unload window

### How Transactions Are Matched:

1. **RRN Extraction from GL**: Last 12-digit sequence in description
   - Example: "ATM WDL REF:782281/528210782281" → "528210782281"
2. **First-Pass Matching**: Checks against approved FEP transactions
3. **Second-Pass Matching**: Checks against rejected FEP (tracked separately as "found in filtered")
4. **Reversal Pair Detection**: GL transactions that reverse each other are "nilled"
   - Method A: Description keywords ("reversal", "rvsl")
   - Method B: Opposite credit/debit parity (within 0.01 tolerance)
5. **Raw Value Preservation**: Stores original `raw_rrn`, `credit_raw`, `debit_raw` for exports

## Debugging Tools

### Debug File Headers and Rows
```
http://localhost:8000/debug.php
```
- Shows all column names detected
- Displays first 10 rows of data
- Identifies key columns automatically
- Useful for troubleshooting column detection issues

### Debug Transaction Matching
```
http://localhost:8000/debug_matching.php
```
- Tests RRN extraction logic
- Shows matching process step-by-step
- Displays intermediate results

### Verify Processing
```
http://localhost:8000/verify.php
```
- Detailed processing logs
- Duplicate removal details
- Multi-cycle detection information

## Common Scenarios

### Scenario 1: Simple Single-Cycle Reconciliation

**Input:**
- GL: 1 load (₦1,000,000), 1 unload (₦200,000)
- FEP: 10 approved transactions totaling ₦800,000

**Expected Result:**
- Status: BALANCED (if GL difference = FEP total)
- Load count: 1, Unload count: 1
- No exclusions shown

### Scenario 2: Multi-Cycle with Edge Exclusions

**Input:**
- GL: Unload₁ (₦50,000), Load₁ (₦1,500,000), Unload₂ (₦226,000), Load₂ (₦3,000,000), Unload₃ (₦532,000), Load₃ (₦2,000,000)
- First unload occurred before first load → excluded (previous cycle)
- Last load occurred after last unload → excluded (next cycle)

**Expected Result:**
- Loads included: 2 (Load₁ + Load₂)
- Unloads included: 2 (Unload₂ + Unload₃)
- Yellow banner: "Multiple Load/Unload Cycles Detected"
- Excluded amounts displayed separately

### Scenario 3: Duplicate Handling

**Input:**
- FEP contains duplicate RRN "528210785029" twice (both REVERSAL type)

**Expected Result:**
- Both instances removed from calculations
- Duplicate removal details shown in verify.php
- Transaction not counted in FEP total

## Troubleshooting

### Error: "Password-Protected Excel Files"
**Solution:** Open file in Excel, go to File → Info → Protect Workbook → Encrypt with Password → delete password → Save

### Error: "Column not found in GL file"
**Solution:**
1. Run `debug.php` to see actual column names
2. Update `config/Columns.php` with your specific column names
3. Re-upload files

### Error: "Could not find load and unload entries"
**Solution:**
- Ensure Description column contains the words "load" and "unload"
- Check spelling and case (search is case-insensitive)
- Verify entries actually exist in the data

### Downloads Not Working
**Solution:**
- Ensure you haven't navigated away from the results page
- Download files immediately after processing
- Check PHP session is active
- Re-run reconciliation if session expired

### Large File Processing Slow or Failing
**Solution:**
- Check PHP settings: `upload_max_filesize`, `max_execution_time`, `memory_limit`
- Configured in `.htaccess` (Apache) or `.user.ini` (PHP-FPM)
- Default limits: 50MB file, 300s timeout, 256MB memory
- Increase if needed for very large files

## Key Behaviors to Remember

### GL Column Semantics (Inverted!)
```php
LOAD = DEBIT column   // Cash leaving bank TO ATM
UNLOAD = CREDIT column // Cash returning FROM ATM to bank
```
This is specific to ATM cash management accounting.

### RRN Normalization
Always extracts **last 12 digits** from GL descriptions:
```
"REF:782281/528210782281" → "528210782281"
"000000000001234567890123" → "567890123" (last 12)
```

### Amount Handling
- Credit = positive value
- Debit = negative value (when converted to signed amount)
- Prefers explicit Credit/Debit columns over calculated amounts

### FEP Pipeline Order (DO NOT CHANGE!)
```
filterApprovedOnly()
  → removeDuplicates()
  → filterByTransactionType()
  → sortByRequestDate()
  → filterByDateRange()
```
Order matters! Changing it breaks duplicate detection logic.

## Next Steps

- Review `README.md` for comprehensive documentation
- Read `TRANSACTION_MATCHING.md` for detailed matching rules
- See `TROUBLESHOOTING.md` for more solutions

## Need Help?

1. Use debugging tools (`debug.php`, `debug_matching.php`)
2. Check error logs (PHP error_log)
3. Review existing documentation
4. Run tests: `./vendor/bin/phpunit`
