<?php

namespace Modules\Operations\Inventory\Exceptions;

class InventoryReversalExecutionRejectedException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = "")
    {
        parent::__construct($message ?: "Reversal execution rejected: " . $reason);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
