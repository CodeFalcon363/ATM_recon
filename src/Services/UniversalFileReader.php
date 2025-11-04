<?php

namespace App\Services;

/**
 * Universal File Reader - Auto-detects and uses appropriate reader
 *
 * Automatically chooses CSVReader or ExcelReader based on file extension
 * Provides unified interface for both file types
 */
class UniversalFileReader
{
    private $reader;
    private $fileType;

    /**
     * Create appropriate reader based on file extension
     *
     * @param string $filePath
     * @return ExcelReader|CSVReader
     */
    public static function create(string $filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'])) {
            return new CSVReader();
        } else {
            return new ExcelReader();
        }
    }

    /**
     * Get file type
     *
     * @param string $filePath
     * @return string 'csv' or 'xlsx'
     */
    public static function getFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['csv', 'txt']) ? 'csv' : 'xlsx';
    }

    /**
     * Load file with auto-detection
     *
     * @param string $filePath
     * @param string $password
     * @return ExcelReader|CSVReader
     */
    public static function loadFile(string $filePath, string $password = '')
    {
        $reader = self::create($filePath);
        return $reader->loadFile($filePath, $password);
    }
}
