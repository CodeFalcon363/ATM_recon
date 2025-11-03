# ATM GL and FEP Reconciliation System

A PHP web application that reconciles ATM General Ledger (GL) and Front-End Processor (FEP) Excel exports to identify matched transactions, discrepancies, and reversed duplicates across multi-cycle operational periods.

**Last Updated:** 2025-11-03

## What This System Does

This reconciliation tool helps you:
- **Match transactions** between GL and FEP systems using Retrieval Reference Numbers (RRNs)
- **Detect reversals and duplicates** automatically in both GL and FEP data
- **Handle multi-cycle periods** with intelligent edge transaction exclusion
- **Filter by date ranges** to reconcile only transactions within operational windows
- **Generate clean exports** with matched and unmatched transactions for audit purposes

## Key Features

### GL Processing
- Extracts **load** (cash sent to ATM) and **unload** (cash returned from ATM) transactions
- **Column semantics**: LOAD amounts in DEBIT column, UNLOAD amounts in CREDIT column
- **Reversal detection**: Identifies and nets out reversed transactions by keywords ("reversal", "rvsl", "reversed")
- **Multi-cycle handling**: Conditionally excludes edge transactions:
  - Excludes first unload if it occurred BEFORE the earliest load (belongs to previous cycle)
  - Excludes last load if it occurred AFTER the latest unload (belongs to next cycle)

### FEP Processing
- Filters to **approved transactions only** (text "approved" OR response codes "00"/"0")
- **Smart duplicate removal** based on RRN:
  - INITIAL + REVERSAL pair → removes BOTH
  - Multiple INITIALs with same RRN → removes ALL
  - Other duplicates → removes ALL instances
- Removes standalone REVERSAL transaction types
- **Date range filtering**: Only includes transactions between GL load and unload datetimes

### Transaction Matching
- **RRN extraction**: Extracts last 12-digit sequence from GL descriptions
- **Two-pass matching**:
  1. Matches against approved FEP transactions
  2. Second-pass checks rejected FEP transactions (tracked separately)
- **Amount resolution**: Prefers explicit Credit/Debit columns; Debit treated as negative
- **Reversal pair detection**: GL transactions that are reversals of each other are "nilled" (not reported as missing)
- **Raw value preservation**: Stores original `raw_rrn`, `credit_raw`, `debit_raw` for exact exports

## Quick Start

### Prerequisites
- PHP 7.4+
- Composer
- Web server (Apache/Nginx) or PHP built-in server

### Installation

1. **Install dependencies:**
```bash
composer install
```

2. **Run locally:**
```bash
cd public
php -S localhost:8000
```

3. **Open in browser:**
```
http://localhost:8000
```

4. **Upload files:**
   - Select your GL Excel file (.xlsx)
   - Select your FEP Excel file (.xlsx)
   - Click "Process Files"

### Production Deployment (Apache/XAMPP)

1. Place in web directory (e.g., `C:\xampp\htdocs\ATM_recon`)
2. Ensure Apache `mod_rewrite` is enabled
3. **Access via:** `http://localhost/ATM_recon/public/` (or configure document root to `public/` folder)
4. Configure PHP settings (in `.htaccess` or `.user.ini`):
   - `upload_max_filesize = 50M`
   - `post_max_size = 50M`
   - `max_execution_time = 300`
   - `memory_limit = 256M`
4. Access via `http://localhost/ATM_recon/`

## How It Works

### Processing Pipeline

```
1. Upload GL and FEP Excel files
   ↓
2. GLProcessor extracts load/unload data
   - Finds load/unload transactions
   - Detects and nets reversals
   - Applies multi-cycle edge exclusions
   - Returns LoadUnloadData with date range
   ↓
3. FEPProcessor filters transactions (order matters!)
   - filterApprovedOnly()
   - removeDuplicates() (smart INITIAL/REVERSAL handling)
   - filterByTransactionType() (remove REVERSAL type)
   - sortByRequestDate()
   - filterByDateRange() (GL load→unload window)
   ↓
4. TransactionMatcher performs RRN-based matching
   - Extracts RRNs from GL descriptions
   - Matches against approved FEP transactions
   - Second-pass against rejected FEP
   - Detects GL reversal pairs (nilled)
   - Returns TransactionMatch with 4 sets:
     • Matched transactions
     • GL not on FEP (truly missing)
     • FEP not on GL
     • GL found in filtered FEP (count only)
   ↓
5. Generate reconciliation result
   - Status: BALANCED | GL_MISSING | FEP_MISSING
   - Difference calculation
   - Save processed files to temp directory
   ↓
6. Display results and provide downloads
```

### Required Excel Columns

**GL File must have:**
- Description/Narration column (contains "load"/"unload" keywords)
- Credit column (for unload amounts)
- Debit column (for load amounts)
- Date column

**FEP File must have:**
- Response Meaning or Response Code column (contains "approved" or "00"/"0")
- Retrieval Reference Nr/RRN column (12-digit transaction reference)
- Request Date column
- Amount column
- Transaction Type column (contains "INITIAL" or "REVERSAL")

## Understanding the Results

### Reconciliation Status

- **✅ BALANCED**: `|GL_Difference - FEP_Total| < 0.01` (amounts match within tolerance)
- **⚠️ GL_MISSING**: Positive difference indicates transactions likely on GL but missing from FEP
- **⚠️ FEP_MISSING**: Negative difference indicates transactions likely on FEP but missing from GL

### Multi-Cycle Detection

When the system detects multiple load/unload cycles, you'll see:
- Load count and total (e.g., "2 loads included")
- Unload count and total (e.g., "2 unloads included")
- **Yellow highlight**: "Multiple Load/Unload Cycles Detected"
- Excluded amounts shown separately:
  - Excluded First Unload (Previous Cycle)
  - Excluded Last Load (Next Cycle)

### Transaction Matching Results

- **Matched Transactions**: RRN found in both GL and FEP (downloadable)
- **GL Not on FEP**: Transactions in GL but missing from approved FEP (with credit/debit breakdown)
- **FEP Not on GL**: Approved FEP transactions not found in GL
- **Nilled GL Duplicates**: Reversal pairs detected in GL (excluded from "missing" count)

### Downloads

All downloads are memory-optimized Excel files with minimal columns:
- `gl_processed.xlsx` - Processed GL with only relevant columns
- `fep_processed.xlsx` - Filtered and processed FEP transactions
- `matched_transactions.xlsx` - Successfully matched GL↔FEP transactions
- `gl_not_on_fep.xlsx` - GL transactions missing from FEP (with raw values)
- `fep_not_on_gl.xlsx` - FEP transactions missing from GL
- `nilled_gl_duplicates.xlsx` - GL reversal pairs (informational)

## Debugging Tools

- **[debug.php](public/debug.php)** - Inspect file headers and sample rows
- **[debug_matching.php](public/debug_matching.php)** - Test transaction matching logic
- **[verify.php](public/verify.php)** - Detailed verification and processing logs

## Documentation

- **[QUICK_START.md](QUICK_START.md)** - Quick installation and usage guide
- **[TRANSACTION_MATCHING.md](TRANSACTION_MATCHING.md)** - Detailed matching rules and RRN extraction
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** - Common issues and solutions

## Architecture

### Technology Stack
- **PHP 7.4+** with PSR-4 autoloading (`App\` → `src/`)
- **PhpSpreadsheet ^1.29** for Excel file manipulation
- **PHPUnit 9.6** for testing
- **Pure HTML/CSS/JS** frontend (no framework)

### Directory Structure
```
ATM_recon/
├── config/              # Column detection configuration
├── public/              # Web entry points (index, process, download, debug)
├── src/
│   ├── Models/          # Data models (LoadUnloadData, ReconciliationResult, TransactionMatch)
│   └── Services/        # Business logic (GLProcessor, FEPProcessor, TransactionMatcher, ReconciliationService)
├── tests/               # PHPUnit tests
├── vendor/              # Composer dependencies
└── tmp/                 # Temporary file storage
```

### Key Design Patterns
- **Service-oriented architecture** with clear separation of concerns
- **Pipeline processing** with strict ordering in FEPProcessor
- **Stateless design** - no database, uses session + temp files
- **Memory optimization** - immediate `unset()` of large objects, stream-based downloads

## Testing

Run the full test suite:
```bash
./vendor/bin/phpunit
```

Tests cover:
- Excel file reading
- GL load/unload extraction and multi-cycle logic
- FEP filtering, duplicate removal, and date range filtering
- RRN extraction and transaction matching
- End-to-end reconciliation scenarios

## Performance Notes

- **File size limit**: 50MB (configurable in `.htaccess`)
- **Execution timeout**: 300 seconds for large files
- **Memory limit**: 256MB (adjust if processing very large files)
- **Memory optimization**: Objects freed immediately, streaming downloads, explicit GC

For very large files (>100K rows), consider:
- Exporting to CSV format instead of Excel
- Using streaming libraries like Spout
- Increasing PHP memory limit

## Contributing

When modifying matching logic:
1. Update the code in `src/Services/TransactionMatcher.php`
2. Update documentation in `TRANSACTION_MATCHING.md`
3. Add/update unit tests in `tests/TransactionMatcherTest.php`
4. Run the full test suite

## License

Proprietary - Internal use only

## Support

For issues or questions:
- Check `TROUBLESHOOTING.md` for common problems
- Review existing documentation in project root
- Use debugging tools (`debug.php`, `debug_matching.php`) to inspect data