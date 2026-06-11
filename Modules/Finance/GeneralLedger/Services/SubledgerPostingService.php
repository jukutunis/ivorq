<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\PostingProfile;
use Modules\Finance\GeneralLedger\Models\PostingLog;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Enums\PostingLogStatusEnum;
use Modules\Finance\GeneralLedger\Enums\PostingEventEnum;
use Modules\Finance\GeneralLedger\Enums\AccountRoleEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;

class SubledgerPostingService
{
    public function __construct(protected GeneralLedgerService $glService) {}

    public function postAccountPayable(string $propertyId, string $apId, float $amount, string $date, string $reference, string $description): ?JournalEntry
    {
        return $this->processPosting(
            propertyId: $propertyId,
            module: 'Payables',
            event: PostingEventEnum::AccountPayable->value,
            sourceType: 'AccountPayable',
            sourceId: $apId,
            date: $date,
            reference: $reference,
            description: $description,
            amount: $amount,
            debitRoles: [AccountRoleEnum::Expense_Account->value],
            creditRoles: [AccountRoleEnum::AP_Liability->value]
        );
    }

    public function postPaymentVoucher(string $propertyId, string $pvId, float $amount, string $date, string $reference, string $description): ?JournalEntry
    {
        return $this->processPosting(
            propertyId: $propertyId,
            module: 'Payables',
            event: PostingEventEnum::PaymentVoucher->value,
            sourceType: 'PaymentVoucher',
            sourceId: $pvId,
            date: $date,
            reference: $reference,
            description: $description,
            amount: $amount,
            debitRoles: [AccountRoleEnum::AP_Liability->value],
            creditRoles: [AccountRoleEnum::Cash_Account->value, AccountRoleEnum::Bank_Account->value]
        );
    }

    protected function processPosting(
        string $propertyId, string $module, string $event, string $sourceType, string $sourceId,
        string $date, string $reference, string $description, float $amount,
        array $debitRoles, array $creditRoles
    ): ?JournalEntry {
        try {
            return DB::transaction(function () use (
                $propertyId, $module, $event, $sourceType, $sourceId, $date, $reference, $description, $amount, $debitRoles, $creditRoles
            ) {
                // Duplicate check
                $exists = JournalEntry::where('property_id', $propertyId)
                    ->where('source_module', $module)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->whereNull('reversal_of_id')
                    ->exists();

                if ($exists) {
                    throw new Exception('Duplicate posting blocked.');
                }

                // Profile lookup
                $profile = PostingProfile::where('property_id', $propertyId)
                    ->where('module', $module)
                    ->where('event', $event)
                    ->where('is_active', true)
                    ->first();

                if (!$profile) {
                    throw new Exception("Active posting profile not found for module {$module} and event {$event}.");
                }

                $debitRule = $profile->rules()->whereIn('account_role', $debitRoles)->first();
                $creditRule = $profile->rules()->whereIn('account_role', $creditRoles)->first();

                if (!$debitRule || !$creditRule) {
                    throw new Exception("Missing posting rules for required roles.");
                }

                // Create Draft Journal
                $journal = JournalEntry::create([
                    'property_id' => $propertyId,
                    'transaction_date' => $date,
                    'reference' => $reference,
                    'description' => $description,
                    'status' => JournalStatusEnum::Draft,
                    'source_module' => $module,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]);

                // Create Lines
                $journal->lines()->create([
                    'property_id' => $propertyId,
                    'account_id' => $debitRule->account_id,
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ]);

                $journal->lines()->create([
                    'property_id' => $propertyId,
                    'account_id' => $creditRule->account_id,
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ]);

                // Post
                $postedJournal = $this->glService->postJournalEntry($journal->id);

                // Log Success
                PostingLog::create([
                    'property_id' => $propertyId,
                    'source_module' => $module,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'status' => PostingLogStatusEnum::Success,
                    'journal_entry_id' => $postedJournal->id,
                ]);

                return $postedJournal;
            });
        } catch (Exception $e) {
            PostingLog::create([
                'property_id' => $propertyId,
                'source_module' => $module,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => PostingLogStatusEnum::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
