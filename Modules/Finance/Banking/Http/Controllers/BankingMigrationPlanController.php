<?php

namespace Modules\Finance\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Services\BankingMigrationDryRunService;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class BankingMigrationPlanController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $actor = $request->user();

        $canView = $actor instanceof User && $actor->can(BankingMigrationPlanService::PERMISSION_VIEW);
        $canManage = $actor instanceof User && $actor->can(BankingMigrationPlanService::PERMISSION_MANAGE);

        $service = app(BankingMigrationPlanService::class);

        $plans = [];
        if ($canView) {
            $planModels = $service->listForProperty($propertyId);
            $plans = array_map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'source_domain' => $plan->source_domain,
                    'target_domain' => $plan->target_domain,
                    'status' => $plan->status?->value,
                    'correlation_id' => $plan->correlation_id,
                    'cutover_authority' => $plan->cutover_authority,
                    'execution_authority' => $plan->execution_authority,
                    'dry_run_completed_at' => $plan->dry_run_metadata['completed_at'] ?? null,
                    'created_actor_id' => $plan->created_actor_id,
                    'created_at' => $plan->created_at?->toIso8601String(),
                    'updated_at' => $plan->updated_at?->toIso8601String(),
                ];
            }, $planModels);
        }

        $canReview = $actor instanceof User && $actor->can('finance.banking.migration.mapping.review');

        $targetIntakes = [];
        if ($canView) {
            $targetIntakes = BankingMigrationTargetIntake::with(['manifestEntry', 'controlledBankAccount'])
                ->where('property_id', $propertyId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(function (BankingMigrationTargetIntake $intake) {
                    $sourceCategory = null;
                    if ($intake->manifestEntry) {
                        $sourceCategory = match ($intake->manifestEntry->source_model) {
                            'BankAccount' => 'bank_account',
                            default => $intake->manifestEntry->source_model,
                        };
                    }

                    return [
                        'id' => $intake->id,
                        'migration_plan_id' => $intake->migration_plan_id,
                        'manifest_entry_id' => $intake->manifest_entry_id,
                        'source_domain' => $intake->source_domain,
                        'source_model' => $intake->source_model,
                        'source_category' => $sourceCategory,
                        'target_domain' => $intake->target_domain,
                        'target_model' => $intake->target_model,
                        'controlled_bank_account_id' => $intake->controlled_bank_account_id,
                        'status' => $intake->status?->value,
                        'correlation_id' => $intake->correlation_id,
                        'proposal_actor_id' => $intake->proposal_actor_id,
                        'review_actor_id' => $intake->review_actor_id,
                        'review_outcome' => $intake->review_outcome,
                        'review_timestamp' => $intake->review_timestamp?->toIso8601String(),
                        'execution_authority' => $intake->execution_authority,
                        'cutover_authority' => $intake->cutover_authority,
                        'created_at' => $intake->created_at?->toIso8601String(),
                        'updated_at' => $intake->updated_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Ivorq/Finance/BankingMigrationControlWorkspace', [
            'plans' => array_values($plans),
            'target_intakes' => array_values($targetIntakes),
            'permissions' => [
                'can_view' => $canView,
                'can_manage' => $canManage,
                'can_review' => $canReview,
            ],
            'constants' => [
                'source_domain' => BankingMigrationPlanService::SOURCE_DOMAIN,
                'target_domain' => BankingMigrationPlanService::TARGET_DOMAIN,
                'cutover_not_authorized' => BankingMigrationPlanService::CUTOVER_NOT_AUTHORIZED,
                'execution_unavailable' => BankingMigrationPlanService::EXECUTION_UNAVAILABLE,
            ],
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPlanService::PERMISSION_MANAGE)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $validated = $request->validate([
            'request_identity' => ['required', 'string', 'max:120'],
        ]);

        $service = app(BankingMigrationPlanService::class);

        try {
            $service->createPlan(
                $validated['request_identity'],
                $user
            );

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Migration plan created.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function requestDryRun(Request $request, string $planId): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPlanService::PERMISSION_MANAGE)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $service = app(BankingMigrationPlanService::class);

        try {
            $service->requestDryRun($planId, $user);

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Dry run requested.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function executeDryRun(Request $request, string $planId): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPlanService::PERMISSION_MANAGE)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $service = app(BankingMigrationDryRunService::class);

        try {
            $service->executeDryRun($planId, $user);

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Dry run completed.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
