<?php

namespace App\Services;

class CSVReader
{
    private $data = [];
    private $filePath;

    public function loadFile(string $filePath, string $password = ''): self
    {
        if (!file_exists($filePath)) {
            throw new \Exception("CSV file not found: $filePath");
        }

        $this->filePath = $filePath;
        $this->data = [];

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \Exception("Cannot open CSV file: $filePath");
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if (count(array_filter($row, function($val) { return $val !== null && $val !== ''; })) === 0) {
                continue;
            }

            $this->data[] = $row;
            $rowNum++;
        }

        fclose($handle);

        return $this;
    }

    public function clearFormatting(): self
    {
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function streamRows(callable $callback, int $chunkSize = 1000): void
    {
        if (empty($this->data)) {
            $handle = fopen($this->filePath, 'r');
            if ($handle === false) {
                throw new \Exception("Cannot open CSV file: $this->filePath");
            }

            $rowNum = 0;
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                if (count(array_filter($row, function($val) { return $val !== null && $val !== ''; })) === 0) {
                    continue;
                }

                $callback($rowNum, $row);
                $rowNum++;
            }

            fclose($handle);
        } else {
            foreach ($this->data as $rowNum => $row) {
                $callback($rowNum, $row);
            }
        }
    }

    public function getHeaders(): array
    {
        return $this->data[0] ?? [];
    }

    public function saveToFile(string $outputPath): void
    {
        $handle = fopen($outputPath, 'w');
        if ($handle === false) {
            throw new \Exception("Cannot create CSV file: $outputPath");
        }

        foreach ($this->data as $row) {
            $rowData = [];
            foreach ($row as $cell) {
                if ($cell instanceof \DateTime) {
                    $rowData[] = $cell->format('Y-m-d H:i:s');
                } else {
                    $rowData[] = $cell;
                }
            }
            fputcsv($handle, $rowData);
        }

        fclose($handle);
    }
}
