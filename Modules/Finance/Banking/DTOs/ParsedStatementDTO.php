<?php

namespace Modules\Finance\Banking\DTOs;

class ParsedStatementDTO
{
    /**
     * @param ParsedStatementLineDTO[] $lines
     */
    public function __construct(
        public readonly ?string $statement_date,
        public readonly ?float $opening_balance,
        public readonly ?float $closing_balance,
        public readonly ?string $currency_code,
        public readonly ?string $bank_account_reference,
        public array $lines = []
    ) {}

    public function addLine(ParsedStatementLineDTO $line): void
    {
        $this->lines[] = $line;
    }
}
