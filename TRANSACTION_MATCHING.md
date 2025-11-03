# Transaction Matching Rules and RRN Extraction

**Last Updated:** 2025-11-03

This document describes the transaction-level matching algorithm implemented in [src/Services/TransactionMatcher.php](src/Services/TransactionMatcher.php).

## Overview

The system matches individual GL transactions with FEP transactions using **Retrieval Reference Numbers (RRNs)** as the primary key. The matching process includes:

1. RRN extraction from GL descriptions
2. Two-pass matching (approved FEP + rejected FEP)
3. Reversal pair detection in GL
4. Raw value preservation for exports

## RRN Extraction from GL

### Pattern Recognition

RRNs are extracted from the Description column using an optimized pattern that looks for the **last 12-digit sequence** in the text.

**Extraction Algorithm:**
```php
// Step 1: Find all digit sequences in description
preg_match_all('/\d+/', $description, $matches)

// Step 2: Take the last matched sequence
$lastDigitSequence = end($matches[0])

// Step 3: Extract last 12 digits if sequence > 12 digits
$rrn = substr($lastDigitSequence, -12)
```

### Examples

| GL Description | Extracted RRN | Explanation |
|---|---|---|
| `ATM WDL REF:782281/528210782281` | `528210782281` | Last 12 digits of last sequence |
| `ATM CASH WDL 000000000001234567890123` | `567890123` | Last 12 of longer sequence (takes substring from end) |
| `CASH WDL 123456789012` | `123456789012` | Exactly 12 digits |
| `ATM 123-456-7890 REF: 998877665544` | `998877665544` | Ignores hyphens, uses last digit sequence |
| `TRANSFER 100` | `null` | No 12-digit sequence found |

### RRN Normalization

After extraction, RRNs are normalized for matching:

```php
// Remove all non-digits
$normalized = preg_replace('/\D+/', '', $rrn);

// Take last 12 digits
$normalizedRrn = substr($normalized, -12);
```

**Why last 12 digits?**
- RRNs in banking systems are typically 12 digits
- Some descriptions may contain multiple numbers
- Taking the last sequence prioritizes the reference number (usually appears at the end)

## Matching Process

### Step 1: Build FEP Lookup Maps

Two maps are created for fast RRN lookup:

**fepMap (Approved Transactions)**
```php
[
  '528210782281' => [
    ['rrn' => '528210782281', 'amount' => 20000, 'date' => '2025-10-09', 'row' => [...]]
  ],
  '528213146289' => [...],
  ...
]
```

**filteredMap (Rejected Transactions)**
```php
[
  '528210999999' => [
    ['rrn' => '528210999999', 'amount' => 5000, 'filter_reason' => 'Insufficient Funds', 'row' => [...]]
  ],
  ...
]
```

### Step 2: Process Each GL Transaction

For each GL transaction with a valid RRN:

```
1. Extract RRN from description
2. Normalize RRN (last 12 digits)
3. Check fepMap:
   ├─ FOUND → Mark as MATCHED
   │          Remove from fepMap
   │          Store in matched array
   └─ NOT FOUND → Continue to step 4

4. Check filteredMap:
   ├─ FOUND → Mark as GL_FOUND_IN_FILTERED
   │          Count++ and track amount
   │          (Transaction was rejected by FEP)
   └─ NOT FOUND → Continue to step 5

5. Check for reversal pairs in GL:
   ├─ Method A: Description keywords
   │  └─ Contains "reversal", "rvsl", "reversed", "reverse"
   │
   ├─ Method B: Numeric parity (opposite Credit/Debit)
   │  └─ Find matching RRN with opposite column values
   │     (credit ≈ debit within 0.01 tolerance)
   │
   └─ If reversal pair detected:
      ├─ Mark as NILLED
      └─ Add to nilledGlDuplicates array

6. If not matched and not nilled:
   └─ Mark as GL_NOT_ON_FEP
      Extract amounts from Credit/Debit columns
      Store raw_rrn, credit_raw, debit_raw
```

### Step 3: Identify Remaining FEP Transactions

After processing all GL transactions:

```php
foreach (remaining entries in fepMap) {
  // These are FEP transactions not found in GL
  add to fepNotOnGl array
}
```

## Amount Resolution

### GL Amount Extraction

The system prefers explicit Credit/Debit columns over calculated amounts:

**Priority Order:**
1. **Explicit Credit column** (if non-empty numeric value exists)
2. **Explicit Debit column** (if non-empty numeric value exists)
3. **Fallback to signed amount** (if Credit/Debit missing)

**Important:** Debit amounts are treated as **negative** when converting to signed amounts:

```php
$credit = (float)$row[$creditIdx];  // Positive value
$debit = -abs((float)$row[$debitIdx]); // Negative value

$signedAmount = $credit + $debit; // Net amount
```

### FEP Amount Parsing

```php
// Remove currency symbols and non-numeric characters
$amount = preg_replace('/[^0-9.\-]/', '', $rawAmount);
$amount = (float)$amount;
```

## Reversal Detection in GL

### Method A: Description Keywords

Checks for common reversal indicators in the description:

```php
$descriptionLower = strtolower($description);

$isReversal = (
    strpos($descriptionLower, 'reversal') !== false ||
    strpos($descriptionLower, 'rvsl') !== false ||
    strpos($descriptionLower, 'reversed') !== false ||
    strpos($descriptionLower, 'reverse') !== false
);
```

### Method B: Numeric Parity Detection

Detects reversal pairs by finding transactions with the **same RRN** where one row's Credit equals another's Debit:

```php
// For each GL transaction with RRN "123456789012":
Row 1: Credit = 20000, Debit = 0
Row 2: Credit = 0, Debit = 20000

// Check parity:
if (abs(Row1.credit - Row2.debit) < 0.01 &&
    Row1.credit > 0 && Row2.debit > 0) {
    // This is a reversal pair
    // Both rows are NILLED (excluded from GL_NOT_ON_FEP)
}
```

**Why tolerance of 0.01?**
- Floating-point precision issues
- Minor rounding in currency conversion
- Prevents false negatives from tiny differences

### Nilled Transactions

Transactions identified as reversal pairs are:
- **Not reported** as "GL Not on FEP" (because they cancel each other)
- **Tracked separately** in `nilledGlDuplicates` array for audit trail
- **Downloadable** for review (informational only, doesn't affect reconciliation totals)

## Raw Value Preservation

For GL_NOT_ON_FEP entries, the system stores raw original values to ensure exact export formatting:

```php
$glNotOnFepEntry = [
    'raw_rrn' => $originalRrnString,      // Exact RRN as extracted
    'credit_raw' => $row[$creditIdx],     // Original credit value
    'debit_raw' => $row[$debitIdx],       // Original debit value
    'description' => $description,
    'date' => $row[$dateIdx],
    'row' => $row  // Full original row for additional columns
];
```

**Why preserve raw values?**
- Avoids formatting inconsistencies in exports
- Ensures exact values match original GL file
- Prevents header-index mismatches
- Useful for audit and debugging

## Transaction Categories

### 1. Matched Transactions

**Definition:** GL transaction RRN found in approved FEP transactions

**Characteristics:**
- RRN exists in both GL and FEP (post-filtering)
- Successfully matched by normalized RRN
- Amounts may differ (matching is RRN-only, not amount-based)

**Download:** `matched_transactions.xlsx`

### 2. GL Not on FEP

**Definition:** GL transaction RRN not found in approved FEP, not identified as reversal pair

**Sub-categories:**
- **Credit transactions** (`glNotOnFepCreditTotal`)
- **Debit transactions** (`glNotOnFepDebitTotal`)

**Possible Reasons:**
- Transaction failed at FEP (check "GL Found in Filtered")
- FEP system didn't log the transaction
- RRN extraction error (rare)
- Date range mismatch (transaction outside GL load→unload window)

**Download:** `gl_not_on_fep.xlsx`

### 3. FEP Not on GL

**Definition:** Approved FEP transaction RRN not found in GL data

**Possible Reasons:**
- GL system didn't record the transaction
- Transaction occurred outside GL reporting period
- RRN format mismatch (very rare)
- GL description doesn't contain RRN

**Download:** `fep_not_on_gl.xlsx`

### 4. GL Found in Filtered FEP

**Definition:** GL transaction RRN found in rejected/filtered FEP transactions

**Characteristics:**
- Not counted in main FEP total
- Tracked as count + total amount only (not detailed list)
- Indicates transaction was attempted but failed

**Common Filter Reasons:**
- "Insufficient Funds"
- "Incorrect PIN"
- "Card Expired"
- "Declined"

**Display:** Summary count and total only (UI intentionally suppresses detailed table)

### 5. Nilled GL Duplicates

**Definition:** GL transactions identified as reversal pairs (cancel each other out)

**Characteristics:**
- Same RRN appears multiple times in GL
- Reversal detected by keywords OR numeric parity
- Excluded from "GL Not on FEP" count
- Tracked separately for transparency

**Download:** `nilled_gl_duplicates.xlsx` (informational)

## Match Rate Calculation

```php
$totalGlTransactions = count($matchedTransactions) +
                       count($glNotOnFep) +
                       count($nilledGlDuplicates);

$matchRate = ($totalGlTransactions > 0)
    ? (count($matchedTransactions) / $totalGlTransactions) * 100
    : 0;
```

**Interpretation:**
- **100%**: All GL transactions matched with FEP
- **< 100%**: Some GL transactions missing from approved FEP
- **Note:** FEP_NOT_ON_GL doesn't reduce match rate (calculated from GL perspective)

## Column Detection

The system uses flexible keyword matching to find columns:

**GL Columns:**
```php
'description' => ['description', 'narration', 'details']
'credit' => ['credit']
'debit' => ['debit']
'date' => ['date', 'time', 'datetime']
```

**FEP Columns:**
```php
'retrieval' => ['retrieval', 'rrn', 'reference']
'amount' => ['amount', 'value']
'request_date' => ['request date', 'transaction date', 'date']
'response' => ['response meaning', 'response', 'status']
```

**Case-insensitive matching:** All column headers converted to lowercase for comparison.

## Performance Optimizations

### 1. Fast Lookup Maps

Uses associative arrays (hash maps) for O(1) RRN lookups instead of nested loops:

```php
// O(1) lookup
if (isset($fepMap[$normalizedRrn])) { ... }

// Instead of O(n) loop
foreach ($fepData as $fepRow) { ... }
```

### 2. Precomputed Column Indices

Column indices calculated once at the start:

```php
$descriptionIdx = $this->findColumnIndex($glHeaders, ['description', 'narration']);
// Used for all rows instead of searching headers each time
```

### 3. Early Bailout

Skips processing for rows without required data:

```php
if (!isset($row[$descriptionIdx]) || trim($row[$descriptionIdx]) === '') {
    continue; // Skip this row
}
```

### 4. Cached RRN Normalization

RRNs normalized once and stored in maps, not re-normalized on each comparison.

## Testing

See [tests/TransactionMatcherTest.php](tests/TransactionMatcherTest.php) for unit tests covering:

- **testSimpleMatch()**: Basic RRN matching
- **testFoundInFilteredOut()**: Second-pass matching against rejected FEP
- **testFepNotOnGl()**: Unmatched FEP detection
- **testNilledDuplicates()**: Reversal pair detection
- **testAmountResolution()**: Credit/Debit column preference

**Run tests:**
```bash
./vendor/bin/phpunit tests/TransactionMatcherTest.php
```

## Modifying Matching Logic

When changing matching rules, follow this checklist:

1. ✅ Update code in `src/Services/TransactionMatcher.php`
2. ✅ Update this documentation (`TRANSACTION_MATCHING.md`)
3. ✅ Add/update unit tests in `tests/TransactionMatcherTest.php`
4. ✅ Run full test suite: `./vendor/bin/phpunit`
5. ✅ Test with real GL/FEP files using `debug_matching.php`

## Debugging

### Debug Mode

Enable debug logging in TransactionMatcher:

```php
$matcher = new TransactionMatcher(
    $glData,
    $fepData,
    $glHeaders,
    $fepHeaders,
    $filteredOutFepData,
    $debug = true  // Enable debug logging
);
```

Logs will output to PHP error_log showing:
- FEP transaction counts (included vs filtered)
- Match/no-match decisions per GL transaction
- RRN extraction results
- Reversal pair detection

### Debug Matching Tool

Use [public/debug_matching.php](public/debug_matching.php) to:
- Test RRN extraction from sample descriptions
- See step-by-step matching process
- Identify issues with specific transactions

### Common Issues

**Issue: RRN not extracted from GL**
- Check description format: must contain 12+ consecutive digits
- Verify it's the LAST digit sequence in description
- Use `debug_matching.php` to test extraction

**Issue: Match not found despite correct RRN**
- Check if FEP transaction was filtered (not approved)
- Look in "GL Found in Filtered" count
- Verify RRN normalization (last 12 digits)

**Issue: Reversal pair not detected**
- Check description keywords ("reversal", "rvsl", etc.)
- Verify Credit/Debit numeric parity (within 0.01)
- Ensure same RRN in both transactions

**Issue: Wrong amounts in GL Not on FEP export**
- Check raw value preservation (`raw_rrn`, `credit_raw`, `debit_raw`)
- Verify Credit/Debit column indices
- Ensure columns contain numeric values

## Examples

### Example 1: Simple Match

**GL:**
```
Description: "ATM WDL REF:528210782281"
Credit: 0
Debit: 20000
Date: 2025-10-09
```

**FEP:**
```
Retrieval Ref: 528210782281
Amount: 20000
Response: Approved
Date: 2025-10-09
```

**Result:** MATCHED

---

### Example 2: Found in Filtered

**GL:**
```
Description: "ATM WDL REF:528210999999"
Credit: 0
Debit: 5000
```

**FEP (Filtered Out):**
```
Retrieval Ref: 528210999999
Amount: 5000
Response: Insufficient Funds
```

**Result:** GL_FOUND_IN_FILTERED (count = 1, total = 5000)

---

### Example 3: Reversal Pair (Nilled)

**GL Row 1:**
```
Description: "ATM WDL REF:528210111111"
Credit: 10000
Debit: 0
```

**GL Row 2:**
```
Description: "ATM WDL REVERSAL REF:528210111111"
Credit: 0
Debit: 10000
```

**Result:** Both rows NILLED (excluded from GL_NOT_ON_FEP)

---

### Example 4: FEP Not on GL

**FEP:**
```
Retrieval Ref: 528210888888
Amount: 15000
Response: Approved
```

**GL:** (No transaction with RRN 528210888888)

**Result:** FEP_NOT_ON_GL

---

## Summary

The transaction matching system provides:
- ✅ **Robust RRN extraction** from varying GL description formats
- ✅ **Two-pass matching** to track rejected FEP transactions
- ✅ **Smart reversal detection** using keywords and numeric parity
- ✅ **Raw value preservation** for accurate exports
- ✅ **Performance optimization** with hash map lookups
- ✅ **Comprehensive categorization** of all transaction types
- ✅ **Detailed audit trail** with downloadable reports

For implementation details, see [src/Services/TransactionMatcher.php](src/Services/TransactionMatcher.php).
