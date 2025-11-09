<?php

namespace App\Services;

class UniversalFileReader
{
    private $reader;
    private $fileType;

    public static function create(string $filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'])) {
            return new CSVReader();
        } else {
            return new ExcelReader();
        }
    }

    public static function getFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['csv', 'txt']) ? 'csv' : 'xlsx';
    }

    public static function loadFile(string $filePath, string $password = '')
    {
        $reader = self::create($filePath);
        return $reader->loadFile($filePath, $password);
    }
}
