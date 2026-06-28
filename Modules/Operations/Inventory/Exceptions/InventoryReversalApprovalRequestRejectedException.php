<?php

namespace Modules\Operations\Inventory\Exceptions;

class InventoryReversalApprovalRequestRejectedException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = "")
    {
        parent::__construct($message ?: "Reversal approval request rejected: " . $reason);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
