<?php

namespace Modules\Finance\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Services\BankingMigrationDryRunService;
use Modules\Finance\Banking\Services\BankingMigrationPilotAuthorizationService;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Finance\Banking\Services\BankingMigrationTargetIntakeService;
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
        $canReviewPilotAuth = $actor instanceof User && $actor->can('finance.banking.migration.pilot.authorization.review');

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

        $pilotAuthorizations = [];
        if ($canView) {
            $pilotAuthorizations = BankingMigrationPilotAuthorization::with(['targetIntake', 'migrationPlan'])
                ->where('property_id', $propertyId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(function (BankingMigrationPilotAuthorization $auth) {
                    return [
                        'id' => $auth->id,
                        'migration_plan_id' => $auth->migration_plan_id,
                        'manifest_entry_id' => $auth->manifest_entry_id,
                        'target_intake_id' => $auth->target_intake_id,
                        'authorization_scope' => $auth->authorization_scope,
                        'status' => $auth->status?->value,
                        'correlation_id' => $auth->correlation_id,
                        'request_actor_id' => $auth->request_actor_id,
                        'review_actor_id' => $auth->review_actor_id,
                        'review_outcome' => $auth->review_outcome,
                        'review_timestamp' => $auth->review_timestamp?->toIso8601String(),
                        'execution_authority' => $auth->execution_authority,
                        'cutover_authority' => $auth->cutover_authority,
                        'created_at' => $auth->created_at?->toIso8601String(),
                        'updated_at' => $auth->updated_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Ivorq/Finance/BankingMigrationControlWorkspace', [
            'plans' => array_values($plans),
            'target_intakes' => array_values($targetIntakes),
            'pilot_authorizations' => array_values($pilotAuthorizations),
            'proposal_context' => $this->projectProposalContext($propertyId, $canManage),
            'permissions' => [
                'can_view' => $canView,
                'can_manage' => $canManage,
                'can_review' => $canReview,
                'can_review_pilot_auth' => $canReviewPilotAuth,
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

    public function propose(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPlanService::PERMISSION_MANAGE)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $validated = $request->validate([
            'banking_migration_plan_id' => ['required', 'string', 'size:26'],
            'banking_migration_manifest_entry_id' => ['required', 'string', 'size:26'],
            'controlled_bank_account_id' => ['required', 'string', 'size:26'],
        ]);

        $service = app(BankingMigrationTargetIntakeService::class);

        try {
            $service->propose(
                $validated['banking_migration_plan_id'],
                $validated['banking_migration_manifest_entry_id'],
                $validated['controlled_bank_account_id'],
                $user
            );

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Target intake mapping proposal created.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function review(Request $request, string $intakeId): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can('finance.banking.migration.mapping.review')) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $validated = $request->validate([
            'review_outcome' => ['required', 'string', 'in:REVIEW_ACCEPTED,REVIEW_REJECTED'],
        ]);

        $service = app(BankingMigrationTargetIntakeService::class);

        try {
            $service->review(
                $intakeId,
                $validated['review_outcome'],
                $user
            );

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Mapping proposal review recorded.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function requestPilotAuthorization(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPilotAuthorizationService::PERMISSION_REQUEST)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $validated = $request->validate([
            'banking_migration_target_intake_id' => ['required', 'string', 'size:26'],
        ]);

        $service = app(BankingMigrationPilotAuthorizationService::class);

        try {
            $service->request(
                $validated['banking_migration_target_intake_id'],
                $user
            );

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Pilot authorization requested.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function reviewPilotAuthorization(Request $request, string $pilotAuthId): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(BankingMigrationPilotAuthorizationService::PERMISSION_REVIEW)) {
            abort(403, 'Unauthorized.');
        }

        $this->resolvePropertyId($request);

        $validated = $request->validate([
            'review_outcome' => ['required', 'string', 'in:REVIEW_ACCEPTED,REVIEW_REJECTED'],
        ]);

        $service = app(BankingMigrationPilotAuthorizationService::class);

        try {
            $service->review(
                $pilotAuthId,
                $validated['review_outcome'],
                $user
            );

            return redirect()
                ->route('finance.banking.migration.index')
                ->with('success', 'Pilot authorization review recorded.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.migration.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function projectProposalContext(string $propertyId, bool $canManage): array
    {
        if (!$canManage) {
            return [
                'eligible_plans' => [],
                'eligible_manifest_entries' => [],
                'available_controlled_accounts' => [],
            ];
        }

        $eligiblePlans = BankingMigrationPlan::where('property_id', $propertyId)
            ->whereIn('status', ['DRY_RUN_COMPLETED', 'DRAFT', 'DRY_RUN_REQUESTED', 'BLOCKED'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (BankingMigrationPlan $plan) => [
                'id' => $plan->id,
                'status' => $plan->status?->value,
                'source_domain' => $plan->source_domain,
                'target_domain' => $plan->target_domain,
                'correlation_id' => $plan->correlation_id,
            ])
            ->values()
            ->all();

        $planIds = array_column($eligiblePlans, 'id');
        $eligibleEntries = [];
        if (!empty($planIds)) {
            $eligibleEntries = BankingMigrationManifestEntry::whereIn('migration_plan_id', $planIds)
                ->where('source_model', 'BankAccount')
                ->where('inventory_status', 'INVENTORIED')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (BankingMigrationManifestEntry $entry) => [
                    'id' => $entry->id,
                    'migration_plan_id' => $entry->migration_plan_id,
                    'source_domain' => $entry->source_domain,
                    'source_model' => $entry->source_model,
                    'inventory_status' => $entry->inventory_status?->value,
                ])
                ->values()
                ->all();
        }

        $availableAccounts = ControlledBankAccount::where('property_id', $propertyId)
            ->where('is_active', true)
            ->orderBy('account_name')
            ->limit(50)
            ->get()
            ->map(fn (ControlledBankAccount $account) => [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'bank_name' => $account->bank_name,
            ])
            ->values()
            ->all();

        return [
            'eligible_plans' => array_values($eligiblePlans),
            'eligible_manifest_entries' => array_values($eligibleEntries),
            'available_controlled_accounts' => array_values($availableAccounts),
        ];
    }
}
