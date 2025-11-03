<?php

/**
 * Column Configuration
 * 
 * If your Excel files have different column names than expected,
 * you can configure the mappings here.
 */

return [
    'gl' => [
        // Keywords to search for in GL file columns (case-insensitive)
        'description_keywords' => ['description', 'narration', 'details', 'transaction details'],
        'amount_keywords' => ['amount', 'figure', 'value', 'debit', 'credit'],
        'date_keywords' => ['date', 'time', 'datetime', 'transaction date'],
        
        // Keywords to identify load/unload in descriptions
        'load_keywords' => ['load', 'loading', 'cash load', 'replenishment'],
        'unload_keywords' => ['unload', 'unloading', 'cash unload', 'withdrawal'],
    ],
    
    'fep' => [
        // Keywords to search for in FEP file columns (case-insensitive)
        'response_meaning_keywords' => ['response meaning', 'response', 'status', 'transaction status'],
        'retrieval_ref_keywords' => ['retrieval reference', 'retrieval ref', 'reference number', 'ref no', 'rrn'],
        'request_date_keywords' => ['request date', 'transaction date', 'date', 'datetime'],
        'amount_keywords' => ['amount', 'value', 'transaction amount'],
        
        // Keywords to identify approved transactions
        'approved_keywords' => ['approved', 'approve', 'success', 'successful', 'completed'],
    ],
    
    // Date format patterns to try when parsing dates
    'date_formats' => [
        'Y-m-d H:i:s',
        'd/m/Y H:i:s',
        'm/d/Y H:i:s',
        'd-m-Y H:i:s',
        'm-d-Y H:i:s',
        'Y/m/d H:i:s',
        'Y-m-d H:i',
        'd/m/Y H:i',
        'm/d/Y H:i',
        'd-m-Y H:i',
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'd-m-Y',
        'Y/m/d',
        'd.m.Y',
        'Y.m.d',
    ],
    
    // Currency symbol to use in display
    'currency_symbol' => '₦',
    
    // Decimal places for amounts
    'decimal_places' => 2,
    
    // Tolerance for comparing amounts (to handle floating point precision)
    'amount_tolerance' => 0.01,
];