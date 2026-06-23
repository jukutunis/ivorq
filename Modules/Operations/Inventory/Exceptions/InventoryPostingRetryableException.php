<?php

namespace Modules\Operations\Inventory\Exceptions;

use RuntimeException;
use Throwable;

class InventoryPostingRetryableException extends RuntimeException
{
    private string $reasonCode;

    public function __construct(string $reasonCode, Throwable $previous)
    {
        parent::__construct(
            "Retryable inventory posting failure: {$reasonCode}",
            0,
            $previous
        );
        $this->reasonCode = $reasonCode;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }
}
