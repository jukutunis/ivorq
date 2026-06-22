<?php
namespace Modules\Finance\CostControl\Contracts;

/**
 * Explicit non-executable constant for future lock order.
 * Do not access InventoryStock or invoke lockForUpdate here.
 */
interface FutureLockOrderContract
{
    public const REQUIRED_LOCK_ORDER = 'PropertyBusinessDate -> FinancialPeriod -> InventoryStock';
}