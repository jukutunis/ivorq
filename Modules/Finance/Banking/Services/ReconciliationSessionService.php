<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Banking\Repositories\ReconciliationSessionRepository;

class ReconciliationSessionService
{
    public function __construct(
        protected ReconciliationSessionRepository $repository
    ) {}

    public function create(array $data): ReconciliationSession
    {
        // Enforce BR-001 implicitly: DB constraint will throw QueryException if duplicate active session.
        // We can also check here for better error message.
        $hasActive = DB::table('reconciliation_sessions')
            ->where('bank_account_id', $data['bank_account_id'])
            ->whereIn('status', [
                ReconciliationSessionStatusEnum::Open->value,
                ReconciliationSessionStatusEnum::InProgress->value,
                ReconciliationSessionStatusEnum::Review->value,
            ])->exists();

        if ($hasActive) {
            throw new Exception('An active reconciliation session already exists for this bank account.');
        }

        $data['status'] = ReconciliationSessionStatusEnum::Open;

        return $this->repository->create($data);
    }

    public function complete(string $sessionId, string $userId): ReconciliationSession
    {
        return DB::transaction(function () use ($sessionId, $userId) {
            $session = $this->repository->lockForUpdate($sessionId);

            if ($session->status === ReconciliationSessionStatusEnum::Completed || $session->status === ReconciliationSessionStatusEnum::Cancelled) {
                throw new Exception('Cannot complete a session that is already ' . $session->status->value);
            }

            // Lock the BankAccount
            $bankAccount = BankAccount::where('id', $session->bank_account_id)->lockForUpdate()->firstOrFail();

            $session->status = ReconciliationSessionStatusEnum::Completed;
            $session->completed_at = now();
            $session->completed_by = $userId;
            $session->save();

            // BR-003: Completing session updates bank_account.reconciled_balance
            $bankAccount->reconciled_balance = $session->reconciled_balance;
            $bankAccount->save();

            // BR-004: Completing session locks matches
            $session->matches()->update(['is_locked' => true]);

            return $session;
        });
    }

    public function cancel(string $sessionId, string $userId): ReconciliationSession
    {
        return DB::transaction(function () use ($sessionId, $userId) {
            $session = $this->repository->lockForUpdate($sessionId);

            if (in_array($session->status, [ReconciliationSessionStatusEnum::Completed, ReconciliationSessionStatusEnum::Cancelled])) {
                throw new Exception('Cannot cancel a session that is already ' . $session->status->value);
            }

            $session->status = ReconciliationSessionStatusEnum::Cancelled;
            $session->cancelled_at = now();
            $session->cancelled_by = $userId;
            $session->save();

            return $session;
        });
    }

    public function delete(ReconciliationSession $session): void
    {
        // BR-009: Completed/Cancelled sessions cannot be deleted.
        if ($session->status === ReconciliationSessionStatusEnum::Completed || $session->status === ReconciliationSessionStatusEnum::Cancelled) {
            throw new Exception('Cannot delete a ' . $session->status->value . ' session.');
        }

        $session->delete();
    }
}
