<?php

namespace Modules\Finance\Banking\Services\Parsers;

use Exception;
use Modules\Finance\Banking\DTOs\ParsedStatementDTO;
use Modules\Finance\Banking\DTOs\ParsedStatementLineDTO;

class CsvStatementParser extends AbstractStatementParser
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array(strtolower($extension), ['csv', 'txt']) && 
               in_array($mimeType, ['text/csv', 'text/plain', 'application/csv']);
    }

    public function parse(string $content, array $config = []): ParsedStatementDTO
    {
        $lines = explode("\n", trim($content));
        if (empty($lines)) {
            throw new Exception('File is empty.');
        }

        $hasHeader = $config['has_header'] ?? true;
        if ($hasHeader) {
            array_shift($lines);
        }

        // Required mappings
        $dateCol = $config['date_col'] ?? 0;
        $descCol = $config['desc_col'] ?? 1;
        
        // Optional mappings
        $refCol = $config['ref_col'] ?? null;
        
        // Amount mapping (single or separate)
        $amountCol = $config['amount_col'] ?? null;
        $amountInCol = $config['amount_in_col'] ?? null;
        $amountOutCol = $config['amount_out_col'] ?? null;
        
        if ($amountCol === null && ($amountInCol === null || $amountOutCol === null)) {
            throw new Exception("Config must provide either 'amount_col' or both 'amount_in_col' and 'amount_out_col'.");
        }

        $dateFormat = $config['date_format'] ?? null;

        $parsedStatement = new ParsedStatementDTO(
            statement_date: null,
            opening_balance: null,
            closing_balance: null,
            currency_code: null,
            bank_account_reference: null
        );

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $row = str_getcsv($line, ',', '"', '\\');

            // Validate row length
            $maxRequiredCol = max($dateCol, $descCol, $refCol ?? -1, $amountCol ?? -1, $amountInCol ?? -1, $amountOutCol ?? -1);
            if (count($row) <= $maxRequiredCol) {
                throw new Exception("Malformed row at line " . ($lineNum + ($hasHeader ? 2 : 1)));
            }

            try {
                $transactionDate = $this->normalizeDate($row[$dateCol], $dateFormat);
                
                $description = trim($row[$descCol]);
                
                $reference = $refCol !== null ? $this->normalizeReference($row[$refCol]) : null;
                
                $amount = 0.0;
                if ($amountCol !== null) {
                    // Single column
                    $rawAmount = $row[$amountCol];
                    // If it has a minus sign, it will be negative automatically via normalizeAmount if we pass isOutflow based on sign?
                    // Actually, normalizeAmount strips signs if we don't handle it. Let's adjust logic.
                    // If rawAmount has '-', it is outflow.
                    $isOutflow = str_starts_with(trim($rawAmount), '-');
                    $amount = $this->normalizeAmount($rawAmount, $isOutflow);
                } else {
                    // Separate columns
                    $in = trim($row[$amountInCol]);
                    $out = trim($row[$amountOutCol]);
                    
                    if (!empty($in) && !empty($out)) {
                        throw new Exception("Row cannot have both inflow and outflow.");
                    }
                    
                    if (!empty($in)) {
                        $amount = $this->normalizeAmount($in, false);
                    } elseif (!empty($out)) {
                        $amount = $this->normalizeAmount($out, true);
                    } else {
                        throw new Exception("Amount is missing.");
                    }
                }

                $parsedLine = new ParsedStatementLineDTO(
                    transaction_date: $transactionDate,
                    description: $description,
                    reference: $reference,
                    external_reference: null, // Often provided separately or derived
                    amount: $amount
                );

                $parsedStatement->addLine($parsedLine);
                
            } catch (Exception $e) {
                throw new Exception("Error parsing line " . ($lineNum + ($hasHeader ? 2 : 1)) . ": " . $e->getMessage());
            }
        }

        return $parsedStatement;
    }
}
