/**
 * ATM Reconciliation System - Main JavaScript
 * Shared functionality across all pages
 */

// =================================================================
// Configuration & Constants
// =================================================================

const CONFIG = {
    MAX_FILE_SIZE: 5 * 1024 * 1024, // 5MB in bytes
    ALLOWED_EXTENSIONS: ['.xlsx', '.csv'],
    ALLOWED_MIME_TYPES: [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'application/csv'
    ]
};

// =================================================================
// File Validation Functions
// =================================================================

/**
 * Validate an uploaded file
 * @param {File} file - The file to validate
 * @param {string} fieldName - Display name for error messages
 * @returns {string[]} Array of error messages (empty if valid)
 */
function validateFile(file, fieldName) {
    const errors = [];
    
    // Check if file exists
    if (!file) {
        errors.push(`${fieldName} is required`);
        return errors;
    }
    
    // Check file size
    if (file.size > CONFIG.MAX_FILE_SIZE) {
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        const maxMB = (CONFIG.MAX_FILE_SIZE / (1024 * 1024)).toFixed(0);
        errors.push(`${fieldName} is too large (${sizeMB}MB). Maximum size is ${maxMB}MB`);
    }
    
    // Check file extension
    const fileName = file.name.toLowerCase();
    const hasValidExtension = CONFIG.ALLOWED_EXTENSIONS.some(ext => fileName.endsWith(ext));
    
    if (!hasValidExtension) {
        const validExts = CONFIG.ALLOWED_EXTENSIONS.join(', ');
        errors.push(`${fieldName} must be in ${validExts} format`);
    }
    
    return errors;
}

/**
 * Format file size for display
 * @param {number} bytes - File size in bytes
 * @returns {string} Formatted file size (e.g., "2.5MB")
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

// =================================================================
// UI Helper Functions
// =================================================================

/**
 * Show error message in a designated element
 * @param {string} elementId - ID of the error display element
 * @param {string} message - Error message to display
 */
function showError(elementId, message) {
    const errorDiv = document.getElementById(elementId);
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        errorDiv.classList.add('alert', 'alert-error');
        
        // Scroll to error
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

/**
 * Hide error message
 * @param {string} elementId - ID of the error display element
 */
function hideError(elementId) {
    const errorDiv = document.getElementById(elementId);
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.classList.remove('alert', 'alert-error');
    }
}

/**
 * Show success message
 * @param {string} elementId - ID of the message display element
 * @param {string} message - Success message to display
 */
function showSuccess(elementId, message) {
    const messageDiv = document.getElementById(elementId);
    if (messageDiv) {
        messageDiv.textContent = message;
        messageDiv.style.display = 'block';
        messageDiv.classList.add('alert', 'alert-success');
    }
}

/**
 * Show loading state on a button
 * @param {HTMLButtonElement} button - Button element
 * @param {string} loadingText - Text to display while loading
 */
function setButtonLoading(button, loadingText = 'Processing...') {
    if (button) {
        button.dataset.originalText = button.textContent;
        button.textContent = loadingText;
        button.disabled = true;
        button.classList.add('loading');
    }
}

/**
 * Reset button from loading state
 * @param {HTMLButtonElement} button - Button element
 */
function resetButton(button) {
    if (button && button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
        button.disabled = false;
        button.classList.remove('loading');
    }
}

// =================================================================
// File Input Handlers
// =================================================================

/**
 * Initialize file input with validation and display
 * @param {string} inputId - ID of the file input element
 * @param {string} displayId - ID of the file name display element
 * @param {string} fieldName - Display name for validation messages
 */
function initializeFileInput(inputId, displayId, fieldName) {
    const input = document.getElementById(inputId);
    const display = document.getElementById(displayId);
    
    if (!input || !display) return;
    
    input.addEventListener('change', function(e) {
        // Hide any existing errors
        hideError('errorMessage');
        
        const file = e.target.files[0];
        
        if (file) {
            // Validate the file
            const errors = validateFile(file, fieldName);
            
            if (errors.length > 0) {
                // Show error and clear the input
                display.textContent = errors.join(', ');
                display.style.color = 'var(--status-error)';
                e.target.value = '';
            } else {
                // Show success with file info
                const fileSize = formatFileSize(file.size);
                display.textContent = `✓ ${file.name} (${fileSize})`;
                display.style.color = 'var(--status-success)';
            }
        } else {
            display.textContent = '';
        }
    });
}

// =================================================================
// Form Validation & Submission
// =================================================================

/**
 * Initialize upload form with validation
 * @param {string} formId - ID of the form element
 * @param {Object} config - Configuration object with file input IDs and field names
 */
function initializeUploadForm(formId, config) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        hideError('errorMessage');
        
        const allErrors = [];
        
        // Validate all file inputs
        config.files.forEach(fileConfig => {
            const input = document.getElementById(fileConfig.inputId);
            const file = input ? input.files[0] : null;
            const errors = validateFile(file, fileConfig.fieldName);
            allErrors.push(...errors);
        });
        
        // If there are errors, show them and stop
        if (allErrors.length > 0) {
            showError('errorMessage', 'Validation Error: ' + allErrors.join(' | '));
            return false;
        }
        
        // Set submit button to loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        setButtonLoading(submitBtn, 'Processing...');
        
        // Submit the form
        form.submit();
    });
}

// =================================================================
// Table Utilities
// =================================================================

/**
 * Add search/filter functionality to a table
 * @param {string} inputId - ID of the search input
 * @param {string} tableId - ID of the table to filter
 */
function initializeTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const filter = this.value.toUpperCase();
        const rows = table.getElementsByTagName('tr');
        
        // Start from 1 to skip header row
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < cells.length; j++) {
                const cellText = cells[j].textContent || cells[j].innerText;
                if (cellText.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            
            rows[i].style.display = found ? '' : 'none';
        }
    });
}

/**
 * Make table sortable by clicking column headers
 * @param {string} tableId - ID of the table element
 */
function initializeTableSort(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const headers = table.querySelectorAll('thead th');
    
    headers.forEach((header, index) => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            sortTable(table, index);
        });
    });
}

/**
 * Sort table by column index
 * @param {HTMLTableElement} table - Table element
 * @param {number} column - Column index to sort by
 */
function sortTable(table, column) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Determine sort direction
    const currentDir = table.dataset.sortDir || 'asc';
    const newDir = currentDir === 'asc' ? 'desc' : 'asc';
    table.dataset.sortDir = newDir;
    
    // Sort rows
    rows.sort((a, b) => {
        const aValue = a.children[column].textContent.trim();
        const bValue = b.children[column].textContent.trim();
        
        // Try to compare as numbers first
        const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
        const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return newDir === 'asc' ? aNum - bNum : bNum - aNum;
        }
        
        // Compare as strings
        return newDir === 'asc' 
            ? aValue.localeCompare(bValue)
            : bValue.localeCompare(aValue);
    });
    
    // Re-append rows in sorted order
    rows.forEach(row => tbody.appendChild(row));
}

// =================================================================
// Number Formatting
// =================================================================

/**
 * Format number as currency
 * @param {number} amount - Amount to format
 * @param {string} currency - Currency symbol (default: ₦)
 * @returns {string} Formatted currency string
 */
function formatCurrency(amount, currency = '₦') {
    return currency + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Format number with thousands separator
 * @param {number} num - Number to format
 * @returns {string} Formatted number string
 */
function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-US');
}

// =================================================================
// Copy to Clipboard
// =================================================================

/**
 * Copy text to clipboard
 * @param {string} text - Text to copy
 * @param {string} successMessage - Message to show on success
 */
function copyToClipboard(text, successMessage = 'Copied to clipboard!') {
    navigator.clipboard.writeText(text).then(() => {
        // Show temporary success message
        const tempMessage = document.createElement('div');
        tempMessage.textContent = successMessage;
        tempMessage.className = 'alert alert-success';
        tempMessage.style.position = 'fixed';
        tempMessage.style.top = '20px';
        tempMessage.style.right = '20px';
        tempMessage.style.zIndex = '9999';
        document.body.appendChild(tempMessage);
        
        setTimeout(() => {
            tempMessage.remove();
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}

// =================================================================
// Theme Detection (Optional Enhancement)
// =================================================================

/**
 * Detect if user prefers dark mode
 * @returns {boolean} True if dark mode is preferred
 */
function prefersDarkMode() {
    return window.matchMedia && 
           window.matchMedia('(prefers-color-scheme: dark)').matches;
}

/**
 * Listen for theme changes
 * @param {Function} callback - Function to call when theme changes
 */
function onThemeChange(callback) {
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)')
              .addEventListener('change', e => callback(e.matches));
    }
}

// =================================================================
// Initialization
// =================================================================

/**
 * Initialize common functionality when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function() {
    // Add gradient background class to body if needed
    if (document.body.classList.contains('gradient-bg') === false) {
        // Check if we're on a page that needs gradient background
        const gradientPages = ['index.php', 'verify.php'];
        const currentPage = window.location.pathname.split('/').pop();
        
        if (gradientPages.includes(currentPage)) {
            document.body.classList.add('gradient-bg');
        }
    }
    
    // Log theme preference (for debugging)
    console.log('Theme preference:', prefersDarkMode() ? 'dark' : 'light');
});

// =================================================================
// Export for use in other scripts (if needed)
// =================================================================

window.ATMRecon = {
    validateFile,
    formatFileSize,
    formatCurrency,
    formatNumber,
    showError,
    hideError,
    showSuccess,
    setButtonLoading,
    resetButton,
    initializeFileInput,
    initializeUploadForm,
    initializeTableSearch,
    initializeTableSort,
    copyToClipboard,
    prefersDarkMode,
    onThemeChange,
    CONFIG
};
