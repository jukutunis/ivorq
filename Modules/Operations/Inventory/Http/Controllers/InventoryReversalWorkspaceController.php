<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\InventoryReversalCandidateGuard;
use Modules\Foundation\Approval\Models\ApprovalRequest;

class InventoryReversalWorkspaceController extends Controller
{
    public function __construct(
        private readonly InventoryReversalCandidateGuard $candidateGuard
    ) {}

    public function index(InventoryTransaction $transaction): InertiaResponse
    {
        if (!auth()->user()->hasPermissionTo('inventory.reversal.request')) {
            abort(403, 'Unauthorized.');
        }

        $approvableType = $transaction->getMorphClass();

        $existingApproval = ApprovalRequest::where('approvable_type', $approvableType)
            ->where('approvable_id', $transaction->id)
            ->first();

        $existingReversal = InventoryTransaction::where('reverses_inventory_transaction_id', $transaction->id)->first();

        $isEligible = true;
        $blocker = null;

        if ($existingReversal) {
            $isEligible = false;
            $blocker = 'This transaction has already been reversed.';
        } elseif ($existingApproval) {
            $isEligible = false;
            $blocker = 'An approval request already exists for this transaction. Status: ' . $existingApproval->status;
        } else {
            try {
                DB::transaction(function () use ($transaction) {
                    $this->candidateGuard->guard($transaction->id);
                });
            } catch (\Throwable $e) {
                $isEligible = false;
                $blocker = $e->getMessage();
            }
        }

        $idempotencyKey = $isEligible ? 'req_idem_' . (string) Str::uuid() : null;

        return Inertia::render('Operations/Inventory/InventoryReversalWorkspace', [
            'transaction' => $transaction,
            'isEligible' => $isEligible,
            'blocker' => $blocker,
            'idempotencyKey' => $idempotencyKey,
            'existingApproval' => $existingApproval,
            'existingReversal' => $existingReversal,
        ]);
    }
}
