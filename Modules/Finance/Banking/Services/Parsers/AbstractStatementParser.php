<?php

namespace Modules\Finance\Banking\Services\Parsers;

use Carbon\Carbon;
use Exception;
use Modules\Finance\Banking\Services\Parsers\Contracts\BankStatementParserInterface;

abstract class AbstractStatementParser implements BankStatementParserInterface
{
    /**
     * Normalize a date string to Y-m-d format.
     *
     * @throws Exception if date is invalid.
     */
    protected function normalizeDate(string $dateString, ?string $format = null): string
    {
        try {
            if ($format) {
                return Carbon::createFromFormat($format, $dateString)->format('Y-m-d');
            }
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (Exception $e) {
            throw new Exception("Invalid date format: {$dateString}");
        }
    }

    /**
     * Normalize an amount string to a float.
     *
     * @throws Exception if amount is invalid.
     */
    protected function normalizeAmount(string $amountString, bool $isOutflow = false): float
    {
        // Detect parentheses for negative numbers
        if (preg_match('/^\(.*\)$/', trim($amountString))) {
            $isOutflow = true;
        }

        // Remove spaces and currency symbols
        $cleanString = preg_replace('/[^0-9.,\-]/', '', $amountString);

        if ($cleanString === '') {
            throw new Exception("Invalid amount format: {$amountString}");
        }

        // Handle European formats (e.g. 1.000,50) vs US formats (e.g. 1,000.50)
        // If the string contains both, guess based on position
        $lastCommaPos = strrpos($cleanString, ',');
        $lastDotPos = strrpos($cleanString, '.');

        if ($lastCommaPos !== false && $lastDotPos !== false) {
            if ($lastCommaPos > $lastDotPos) {
                // 1.000,50
                $cleanString = str_replace('.', '', $cleanString);
                $cleanString = str_replace(',', '.', $cleanString);
            } else {
                // 1,000.50
                $cleanString = str_replace(',', '', $cleanString);
            }
        } elseif ($lastCommaPos !== false) {
            // Check if it's likely a decimal separator (e.g. 100,50)
            if (strlen($cleanString) - $lastCommaPos <= 3) {
                $cleanString = str_replace(',', '.', $cleanString);
            } else {
                // Likely a thousand separator (e.g. 100,000)
                $cleanString = str_replace(',', '', $cleanString);
            }
        }

        if (!is_numeric($cleanString)) {
            throw new Exception("Invalid amount format: {$amountString}");
        }

        $amount = (float) $cleanString;

        // Apply sign based on inflow/outflow logic
        if ($isOutflow) {
            return -abs($amount);
        }

        return abs($amount);
    }

    /**
     * Normalize reference string.
     */
    protected function normalizeReference(?string $reference): ?string
    {
        if (empty($reference)) {
            return null;
        }

        $clean = trim($reference);
        return $clean === '' ? null : $clean;
    }
}
