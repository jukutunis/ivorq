<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Illuminate\Database\Eloquent\Relations\Relation;

class ReconciliationMatchService
{
    /**
     * Persist user-confirmed matches.
     */
    public function storeMatches(ReconciliationSession $session, array $matches, string $userId): array
    {
        return DB::transaction(function () use ($session, $matches, $userId) {
            // Lock session
            $session = ReconciliationSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (in_array($session->status, [ReconciliationSessionStatusEnum::Completed, ReconciliationSessionStatusEnum::Cancelled])) {
                throw new Exception('Cannot save matches to a session that is already ' . $session->status->value);
            }

            $bankAccount = BankAccount::findOrFail($session->bank_account_id);
            $savedMatches = [];

            foreach ($matches as $matchData) {
                $line = BankStatementLine::where('id', $matchData['bank_statement_id'] ?? $matchData['bank_statement_line_id'])
                    ->where('bank_statement_id', function ($query) use ($session) {
                        $query->select('id')->from('bank_statements')->where('bank_account_id', $session->bank_account_id);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($line->reconciliationMatch()->exists()) {
                    throw new Exception("Bank statement line {$line->id} is already matched.");
                }

                $matchableClass = $matchData['matchable_type'];
                // Ensure proper class name resolution if morphed
                if (Relation::getMorphedModel($matchableClass)) {
                    $matchableClass = Relation::getMorphedModel($matchableClass);
                }

                if (!class_exists($matchableClass)) {
                    throw new Exception("Invalid matchable type: {$matchableClass}");
                }

                $matchable = $matchableClass::where('id', $matchData['matchable_id'])->lockForUpdate()->firstOrFail();

                if ($matchable->reconciliationMatch()->exists()) {
                    throw new Exception("Matchable record {$matchable->id} is already matched.");
                }

                // Gather snapshot data depending on the model
                $matchableReference = null;
                $matchableAmount = 0;

                if ($matchableClass === \Modules\Finance\Payables\Models\PaymentVoucher::class) {
                    $matchableReference = $matchable->reference_no;
                    $matchableAmount = $matchable->total_amount;
                }

                // Recalculate balances: simple simulation for snapshots
                $balanceBefore = $bankAccount->reconciled_balance;
                $balanceAfter = $balanceBefore + $line->amount;

                $match = new ReconciliationMatch([
                    'property_id' => $session->property_id,
                    'reconciliation_session_id' => $session->id,
                    'bank_statement_line_id' => $line->id,
                    'matchable_type' => $matchData['matchable_type'],
                    'matchable_id' => $matchData['matchable_id'],
                    'amount_matched' => abs($line->amount), // Usually they match, or partial
                    'matchable_reference' => $matchableReference,
                    'matchable_amount' => $matchableAmount,
                    'statement_reference' => $line->reference,
                    'statement_amount' => $line->amount,
                    'bank_account_balance_before' => $balanceBefore,
                    'bank_account_balance_after' => $balanceAfter,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $match->save();

                // Update session reconciled balance (simplified projection)
                // Actually, the session balance should be computed when matches are saved or completed
                $session->reconciled_balance += $line->amount;
                $session->save();

                $savedMatches[] = $match;
            }

            return $savedMatches;
        });
    }
}
