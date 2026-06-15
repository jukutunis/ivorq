<?php

namespace Modules\Finance\Banking\Services\Parsers\Contracts;

use Modules\Finance\Banking\DTOs\ParsedStatementDTO;

interface BankStatementParserInterface
{
    /**
     * Parse the raw file content into a structured DTO.
     */
    public function parse(string $content, array $config = []): ParsedStatementDTO;

    /**
     * Check if this parser supports the given format or file.
     */
    public function supports(string $mimeType, string $extension): bool;
}
