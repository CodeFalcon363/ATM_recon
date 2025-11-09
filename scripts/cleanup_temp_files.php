#!/usr/bin/env php
<?php
/**
 * Cleanup temporary files - runs via cron every 15 minutes
 *
 * Crontab entry:
 * */
/**15 * * * * /usr/bin/php /var/www/atm_recon/scripts/cleanup_temp_files.php >> /var/log/atm_recon_cleanup.log 2>&1
 */

$tempDir = sys_get_temp_dir();
$maxAge = 3600; // 1 hour
$deletedCount = 0;
$errorCount = 0;

echo "[" . date('Y-m-d H:i:s') . "] Starting cleanup of temp files...\n";

// Find all processed_* files
$files = glob($tempDir . '/processed_*');

if ($files === false) {
    echo "[ERROR] Failed to scan temp directory: $tempDir\n";
    exit(1);
}

echo "Found " . count($files) . " temp files to check\n";

foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $age = time() - filemtime($file);

    if ($age > $maxAge) {
        if (@unlink($file)) {
            $deletedCount++;
            echo "Deleted: " . basename($file) . " (age: " . round($age / 60) . " minutes)\n";
        } else {
            $errorCount++;
            echo "[ERROR] Failed to delete: " . basename($file) . "\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete. Deleted: $deletedCount, Errors: $errorCount\n";
echo "---\n";

exit($errorCount > 0 ? 1 : 0);
