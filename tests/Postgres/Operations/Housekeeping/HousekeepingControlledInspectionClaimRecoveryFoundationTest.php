<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimRecoveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingInspectionClaimRecoveryData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoveryFoundationTest extends PostgresTestCase
{
    use CreatesHousekeepingInspectionClaimRecoveryData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInspectionClaimRecoveryFixture();
    }

    public function test_each_objective_original_ineligibility_condition_creates_one_append_only_recovery(): void
    {
        foreach ([
            'user' => 'original_user_inactive_or_deleted',
            'membership' => 'original_property_membership_inactive_or_missing',
            'permission' => 'original_conduct_permission_missing',
        ] as $condition => $expectedCode) {
            [, , $inspection] = $this->p19ClaimedInspection('P19-F-'.strtoupper($condition));
            $original = collect(['supervisor_id', 'claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'])
                ->mapWithKeys(fn (string $field) => [$field => $inspection->getRawOriginal($field)])->all();
            $this->p19MakeOriginalInactive($condition);
            $result = $this->p19Recover($inspection);

            $this->assertFalse($result->replayed);
            $this->assertSame($expectedCode, $result->reassignment->original_ineligibility_code);
            $this->assertSame($this->p19Replacement->id, $result->reassignment->replacement_claimant_id);
            $this->assertSame($original, collect(array_keys($original))->mapWithKeys(
                fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)]
            )->all());
            $this->assertSame(1, HousekeepingInspectionClaimReassignment::where('room_inspection_id', $inspection->id)->count());

            $this->housekeepingInspector->update(['is_active' => true]);
            DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->housekeepingInspector->id)->update(['status' => 'active']);
            if (! $this->housekeepingInspector->hasPermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION)) {
                $this->housekeepingInspector->givePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
            }
        }
    }

    public function test_original_still_eligible_and_invalid_replacement_boundaries_fail_closed(): void
    {
        [, $task, $inspection] = $this->p19ClaimedInspection('P19-F-BOUNDARY');
        $service = app(HousekeepingInspectionClaimRecoveryService::class);
        $operation = fn (string $replacement) => $service->confirmReassignment(
            $this->p19Intervenor, $inspection->id, $replacement,
            'Bounded recovery reason.', 'p19-boundary-'.Str::uuid(), 'password'
        );
        try {
            $operation($this->p19Replacement->id);
            $this->fail('Expected eligible-original rejection.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingInspectionClaimRecoveryService::ORIGINAL_STILL_ELIGIBLE, $exception->getMessage());
        }

        $this->p19MakeOriginalInactive();
        foreach ([
            $this->housekeepingInspector->id => HousekeepingInspectionClaimRecoveryService::REPLACEMENT_ORIGINAL_PROHIBITED,
            $task->completed_by => HousekeepingInspectionClaimRecoveryService::REPLACEMENT_CLEANER_PROHIBITED,
        ] as $replacement => $marker) {
            try {
                $operation((string) $replacement);
                $this->fail('Expected replacement segregation rejection.');
            } catch (DomainException $exception) {
                $this->assertSame($marker, $exception->getMessage());
            }
        }
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_exact_replay_recovers_same_evidence_but_changed_or_second_command_fails(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-F-REPLAY');
        $this->p19MakeOriginalInactive();
        $key = 'p19-exact-'.Str::uuid();
        $reason = 'Exact committed recovery replay.';
        $first = $this->p19Recover($inspection, $key, $reason);
        $replay = app(HousekeepingInspectionClaimRecoveryService::class)->reassign(
            $this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->reassignment->id, $replay->reassignment->id);

        foreach ([
            [$key, 'Changed replay reason.', HousekeepingInspectionClaimRecoveryService::IDEMPOTENCY_CONFLICT],
            ['p19-second-'.Str::uuid(), $reason, HousekeepingInspectionClaimRecoveryService::ALREADY_COMPLETED],
        ] as [$attemptKey, $attemptReason, $marker]) {
            try {
                app(HousekeepingInspectionClaimRecoveryService::class)->reassign(
                    $this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $attemptReason, $attemptKey
                );
                $this->fail('Expected replay boundary rejection.');
            } catch (DomainException $exception) {
                $this->assertSame($marker, $exception->getMessage());
            }
        }
        $this->assertSame(1, HousekeepingInspectionClaimReassignment::count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_inspection_claim_reassigned')->count());
    }

    public function test_effective_claimant_replaces_terminal_authority_without_rewriting_original_claim(): void
    {
        [, $task, $inspection] = $this->p19ClaimedInspection('P19-F-AUTHORITY');
        $this->p19MakeOriginalInactive();
        $this->p19Recover($inspection);
        $assignments = TaskAssignment::withoutGlobalScopes()->where('cleaning_task_id', $task->id)->where('status', 'completed')->get();
        $service = app(HousekeepingInspectionClaimService::class);

        try {
            $service->assertTerminalAuthority($this->housekeepingInspector->fresh(), $this->property->id, $inspection->fresh(), $task, $assignments);
            $this->fail('Expected original claimant authority rejection.');
        } catch (HttpException|DomainException $exception) {
            $this->assertContains($exception->getMessage(), [HousekeepingInspectionClaimService::NOT_AUTHORIZED, HousekeepingInspectionClaimService::OWNERSHIP_REQUIRED]);
        }
        $service->assertTerminalAuthority($this->p19Replacement, $this->property->id, $inspection->fresh(), $task, $assignments);
        $this->assertSame($this->housekeepingInspector->id, $inspection->fresh()->supervisor_id);
    }

    public function test_sensitive_confirmation_is_registered_password_checked_and_bound_to_every_command_input(): void
    {
        $this->assertContains(HousekeepingInspectionClaimRecoveryService::INTENT, app(SensitiveActionConfirmationService::class)->registeredIntents());
        [, , $inspection] = $this->p19ClaimedInspection('P19-F-CONFIRM');
        $this->p19MakeOriginalInactive();
        $service = app(HousekeepingInspectionClaimRecoveryService::class);
        $key = 'p19-confirm-'.Str::uuid();
        $reason = 'Confirmation binding proof.';

        try {
            $service->confirmReassignment($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key, 'wrong-password');
            $this->fail('Expected password rejection.');
        } catch (DomainException $exception) {
            $this->assertSame('The password is incorrect.', $exception->getMessage());
        }
        $service->confirmReassignment($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key, 'password');

        foreach ([
            [$this->p19Replacement->id, 'Changed reason.', $key],
            [$this->p19Replacement->id, $reason, 'p19-confirm-changed-key'],
        ] as [$replacement, $attemptReason, $attemptKey]) {
            try {
                $service->reassign($this->p19Intervenor, $inspection->id, $replacement, $attemptReason, $attemptKey);
                $this->fail('Expected confirmation binding rejection.');
            } catch (DomainException $exception) {
                $this->assertSame(HousekeepingInspectionClaimRecoveryService::CONFIRMATION_REQUIRED, $exception->getMessage());
            }
        }
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_confirmation_is_revalidated_against_live_replacement_and_original_eligibility(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-F-REVALIDATE');
        $this->p19MakeOriginalInactive();
        $service = app(HousekeepingInspectionClaimRecoveryService::class);
        $key = 'p19-revalidate-'.Str::uuid();
        $reason = 'Live eligibility revalidation.';
        $service->confirmReassignment($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key, 'password');
        $this->p19Replacement->update(['is_active' => false]);
        try {
            $service->reassign($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key);
            $this->fail('Expected replacement rejection.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingInspectionClaimRecoveryService::REPLACEMENT_INVALID, $exception->getMessage());
        }

        $this->p19Replacement->update(['is_active' => true]);
        $this->housekeepingInspector->update(['is_active' => true]);
        try {
            $service->reassign($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key);
            $this->fail('Expected recovered-original rejection.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingInspectionClaimRecoveryService::ORIGINAL_STILL_ELIGIBLE, $exception->getMessage());
        }
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }
}
