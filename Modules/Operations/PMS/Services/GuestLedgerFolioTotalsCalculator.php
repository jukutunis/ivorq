<?php

namespace Modules\Operations\PMS\Services;

use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;

/**
 * PMS Guest Ledger — Pure Folio Totals Calculator (GLF-D).
 *
 * Extracted from GuestLedgerFolioAggregateService::recalculateLocked() as a
 * pure calculation component. Does NOT persist anything. Used by GLF-D for
 * read-only fresh-totals-vs-cached comparison.
 *
 * The sign conventions follow the existing aggregate service:
 *   - Charges (RoomCharge, Tax, ServiceCharge, Adjustment, Other):
 *     stored as positive amounts → accumulate into total_charges.
 *   - Payment: stored as negative → absolute value accumulated as total_payments.
 *   - PaymentReversal: stored as positive → deducted from total_payments.
 *   - Deposit: stored as negative → absolute value accumulated as total_deposits.
 *   - DepositReversal: stored as positive → deducted from total_deposits.
 *   - ArTransfer: stored as negative → absolute value accumulated as total_ar_transfers.
 *   - ArTransferReversal: stored as positive → deducted from total_ar_transfers.
 *   - balance = totalCharges − totalPayments − totalDeposits − totalArTransfers.
 *
 * All arithmetic uses BCMath scale-2 strings. No floats.
 */
class GuestLedgerFolioTotalsCalculator
{
    /**
     * @param  \Illuminate\Support\Collection<int, FolioItem> $activeItems  Non-void FolioItems.
     * @return array{total_charges: string, total_payments: string, total_deposits: string, total_ar_transfers: string, balance: string}
     */
    public function calculate(\Illuminate\Support\Collection $activeItems): array
    {
        $totalCharges    = '0.00';
        $totalPayments   = '0.00';
        $totalDeposits   = '0.00';
        $totalArTransfers = '0.00';

        foreach ($activeItems as $item) {
            $amt = (string) $item->amount;

            // Payment: negative amount → absolute value is the payment contribution
            if ($item->item_type === FolioItemTypeEnum::Payment && bccomp($amt, '0.00', 2) < 0) {
                $totalPayments = bcadd($totalPayments, bcsub('0.00', $amt, 2), 2);
                continue;
            }

            // PaymentReversal: positive amount → undoes payment
            if ($item->item_type === FolioItemTypeEnum::PaymentReversal && bccomp($amt, '0.00', 2) > 0) {
                $totalPayments = bcsub($totalPayments, $amt, 2);
                continue;
            }

            // Deposit: negative amount → absolute value is the deposit contribution
            if ($item->item_type === FolioItemTypeEnum::Deposit && bccomp($amt, '0.00', 2) < 0) {
                $totalDeposits = bcadd($totalDeposits, bcsub('0.00', $amt, 2), 2);
                continue;
            }

            // DepositReversal: positive amount → undoes deposit
            if ($item->item_type === FolioItemTypeEnum::DepositReversal && bccomp($amt, '0.00', 2) > 0) {
                $totalDeposits = bcsub($totalDeposits, $amt, 2);
                continue;
            }

            // ArTransfer: negative amount → absolute value is the AR transfer contribution
            if ($item->item_type === FolioItemTypeEnum::ArTransfer && bccomp($amt, '0.00', 2) < 0) {
                $totalArTransfers = bcadd($totalArTransfers, bcsub('0.00', $amt, 2), 2);
                continue;
            }

            // ArTransferReversal: positive amount → undoes AR transfer
            if ($item->item_type === FolioItemTypeEnum::ArTransferReversal && bccomp($amt, '0.00', 2) > 0) {
                $totalArTransfers = bcsub($totalArTransfers, $amt, 2);
                continue;
            }

            // Everything else: charges (room_charge, tax, service_charge, adjustment, other)
            if (in_array($item->item_type, [
                FolioItemTypeEnum::RoomCharge,
                FolioItemTypeEnum::Tax,
                FolioItemTypeEnum::ServiceCharge,
                FolioItemTypeEnum::Adjustment,
                FolioItemTypeEnum::Other,
            ], true)) {
                $totalCharges = bcadd($totalCharges, $amt, 2);
            }
        }

        $balance = bcsub(
            bcsub(bcsub($totalCharges, $totalPayments, 2), $totalDeposits, 2),
            $totalArTransfers,
            2
        );

        return [
            'total_charges'      => $totalCharges,
            'total_payments'     => $totalPayments,
            'total_deposits'     => $totalDeposits,
            'total_ar_transfers' => $totalArTransfers,
            'balance'            => $balance,
        ];
    }
}
