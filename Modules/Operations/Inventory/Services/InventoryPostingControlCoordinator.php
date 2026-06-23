<?php

namespace Modules\Operations\Inventory\Services;

use Throwable;
use Modules\Operations\Inventory\Exceptions\InventoryPostingRetryableException;

class InventoryPostingControlCoordinator
{
    private const RETRYABLE_SQLSTATES = [
        '40P01' => 'DEADLOCK_DETECTED',
        '40001' => 'SERIALIZATION_FAILURE',
        '55P03' => 'LOCK_TIMEOUT',
        '57014' => 'STATEMENT_TIMEOUT',
    ];

    public function executeOnce(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    private function handleFailure(Throwable $e): void
    {
        $current = $e;

        while ($current !== null) {
            $sqlState = $this->extractSqlState($current);

            if ($sqlState !== null && array_key_exists($sqlState, self::RETRYABLE_SQLSTATES)) {
                $reasonCode = self::RETRYABLE_SQLSTATES[$sqlState];
                throw new InventoryPostingRetryableException($reasonCode, $e);
            }

            $current = $current->getPrevious();
        }

        throw $e;
    }

    private function extractSqlState(Throwable $e): ?string
    {
        if (isset($e->errorInfo) && is_array($e->errorInfo) && isset($e->errorInfo[0])) {
            return (string) $e->errorInfo[0];
        }

        $code = $e->getCode();
        if (is_string($code) || is_numeric($code)) {
            return (string) $code;
        }

        return null;
    }
}
