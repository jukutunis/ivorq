<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Models\ApSettlementAllocation;

class ApOutstandingProjectionService
{
    public function outstandingForPostedApJournal(JournalEntry $apJournal): string
    {
        $this->assertPostedApLiabilityJournal($apJournal);

        $sourceAmount = $this->journalAmount($apJournal);
        $allocated = ApSettlementAllocation::where('ap_journal_entry_id', $apJournal->id)
            ->lockForUpdate()
            ->get()
            ->sum(fn (ApSettlementAllocation $allocation): int => $this->amountToCents($allocation->allocation_amount));

        $outstanding = $this->amountToCents($sourceAmount) - $allocated;
        if ($outstanding < 0) {
            throw new DomainException('AP settlement allocations exceed posted AP liability evidence.');
        }

        return number_format($outstanding / 100, 2, '.', '');
    }

    private function assertPostedApLiabilityJournal(JournalEntry $journal): void
    {
        if (
            $journal->status !== JournalStatusEnum::Posted ||
            $journal->source_module !== 'Payables' ||
            $journal->source_type !== 'SupplierInvoice' ||
            $journal->posting_event !== 'SupplierInvoiceGrniClearingApLiability'
        ) {
            throw new DomainException('Outstanding AP projection requires posted AP liability JournalEntry evidence.');
        }
    }

    private function journalAmount(JournalEntry $journal): string
    {
        if (!$journal->relationLoaded('lines')) {
            $journal->load('lines');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($journal->lines as $line) {
            $debitTotal += $this->amountToCents($line->debit_amount);
            $creditTotal += $this->amountToCents($line->credit_amount);
        }

        if ($debitTotal !== $creditTotal || $debitTotal <= 0) {
            throw new DomainException('Posted AP liability JournalEntry is not balanced.');
        }

        return number_format($debitTotal / 100, 2, '.', '');
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
