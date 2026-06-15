<?php

namespace Modules\Finance\Banking\DTOs;

class ParsedStatementLineDTO
{
    public function __construct(
        public readonly string $transaction_date,
        public readonly string $description,
        public readonly ?string $reference,
        public readonly ?string $external_reference,
        public readonly float $amount,
        public readonly ?float $running_balance = null,
        public readonly array $metadata = []
    ) {}
}
