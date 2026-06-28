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
        if (!auth()->user()->hasPermissionTo('inventory.reversal.request') &&
            !auth()->user()->hasPermissionTo('inventory.reversal.execute')) {
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

        // State 2: Final Approved & Controlled Execution readiness
        $isExecutionAvailable = false;
        $executionIdempotencyKey = null;
        $requesterName = null;
        $approverName = null;
        $approvedAt = null;
        $workflowLabel = null;

        if ($existingApproval) {
            $requester = DB::table('users')->where('id', $existingApproval->requester_id)->first();
            if ($requester) {
                $requesterName = $requester->name;
            }

            $workflow = DB::table('approval_workflows')->where('id', $existingApproval->workflow_id)->first();
            if ($workflow) {
                $workflowLabel = $workflow->name;
            }

            if ($existingApproval->status === 'Approved') {
                $approveAction = DB::table('approval_actions')
                    ->where('approval_request_id', $existingApproval->id)
                    ->where('action_type', 'Approved')
                    ->first();

                if ($approveAction) {
                    $approver = DB::table('users')->where('id', $approveAction->user_id)->first();
                    if ($approver) {
                        $approverName = $approver->name;
                    }
                    $approvedAt = $approveAction->created_at;
                }

                if (!$existingReversal && auth()->user()->hasPermissionTo('inventory.reversal.execute')) {
                    $isExecutionAvailable = true;
                    $executionIdempotencyKey = 'exec_idem_' . (string) Str::uuid();
                }
            }
        }

        return Inertia::render('Operations/Inventory/InventoryReversalWorkspace', [
            'transaction' => $transaction,
            'isEligible' => $isEligible,
            'blocker' => $blocker,
            'idempotencyKey' => $idempotencyKey,
            'existingApproval' => $existingApproval,
            'existingReversal' => $existingReversal,
            'requesterName' => $requesterName,
            'approverName' => $approverName,
            'approvedAt' => $approvedAt,
            'workflowLabel' => $workflowLabel,
            'isExecutionAvailable' => $isExecutionAvailable,
            'executionIdempotencyKey' => $executionIdempotencyKey,
        ]);
    }
}
