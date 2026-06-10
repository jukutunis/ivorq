<?php

namespace Modules\Finance\Banking\Services;

use Exception;

class BankStatementParserService
{
    public function parseCsv(string $csvContent): array
    {
        $lines = explode("\n", trim($csvContent));
        if (empty($lines)) {
            throw new Exception('CSV file is empty.');
        }

        $header = str_getcsv(array_shift($lines));
        $expectedHeader = ['transaction_date', 'description', 'reference', 'amount'];

        // Normalize header
        $header = array_map(fn($col) => strtolower(trim($col)), $header);

        if ($header !== $expectedHeader) {
            throw new Exception('Invalid CSV template. Expected columns: ' . implode(',', $expectedHeader));
        }

        $parsedData = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $row = str_getcsv($line);

            if (count($row) !== count($expectedHeader)) {
                throw new Exception("Invalid row length at line " . ($lineNum + 2));
            }

            $rowData = array_combine($expectedHeader, $row);

            if (empty($rowData['transaction_date'])) {
                throw new Exception("Transaction date missing at line " . ($lineNum + 2));
            }
            if (!is_numeric($rowData['amount'])) {
                throw new Exception("Invalid amount at line " . ($lineNum + 2));
            }

            $parsedData[] = $rowData;
        }

        return $parsedData;
    }
}
