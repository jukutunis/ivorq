<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Department\Models\Department;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimSegregationFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousekeepingRoomReadinessFixture();
        $this->department = Department::create([
            'property_id' => $this->property->id,
            'name' => 'Package 17 Housekeeping',
            'code' => 'P17' . Str::upper(Str::random(5)),
            'is_active' => true,
        ]);
        $this->housekeepingActor->update(['department_id' => $this->department->id]);
        $this->housekeepingInspector->update(['department_id' => $this->department->id]);
        foreach (['housekeeping.inspection.view', 'housekeeping.inspection.create', HousekeepingInspectionClaimService::CLAIM_PERMISSION] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->housekeepingInspector->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
        $this->housekeepingActor->givePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
    }

    public function test_eligible_non_cleaner_claim_is_server_owned_audited_and_exactly_replayable(): void
    {
        [, $task, $inspection] = $this->pendingInspection('P17-F-101');
        $key = 'p17-foundation-' . Str::uuid();

        $first = $this->claims()->claim($this->housekeepingInspector, $inspection->id, $key);
        $replay = $this->claims()->claim($this->housekeepingInspector, $inspection->id, $key);
        $claimed = $inspection->fresh();

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->inspection->id, $replay->inspection->id);
        $this->assertSame(InspectionStatusEnum::InProgress, $claimed->status);
        $this->assertSame($this->housekeepingInspector->id, $claimed->supervisor_id);
        $this->assertNotSame($task->completed_by, $claimed->supervisor_id);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertSame($key, $claimed->claim_idempotency_key);
        $this->assertSame(HousekeepingInspectionClaimService::EVIDENCE_VERSION, $claimed->claim_evidence_version);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $claimed->claim_source_hash);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_inspection_claimed')->where('auditable_id', $inspection->id)->count());
        $this->assertArrayNotHasKey('claim_source_hash', $replay->toArray());
        $this->assertArrayNotHasKey('claim_idempotency_key', $replay->toArray());
    }

    public function test_completed_cleaner_cannot_claim_own_task_and_creates_zero_claim_facts(): void
    {
        [, , $inspection] = $this->pendingInspection('P17-F-102');
        $before = $this->claimCounts();

        try {
            $this->claims()->claim($this->housekeepingActor, $inspection->id, 'p17-cleaner-' . Str::uuid());
            $this->fail('Expected maker-checker rejection.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingInspectionClaimService::CLEANER_PROHIBITED, $exception->getMessage());
            $this->assertSame($before, $this->claimCounts());
            $this->assertSame(InspectionStatusEnum::Pending, $inspection->fresh()->status);
        }
    }

    public function test_conflicting_idempotency_reuse_and_claimant_replacement_fail_closed(): void
    {
        [, , $firstInspection] = $this->pendingInspection('P17-F-103-A');
        [, , $secondInspection] = $this->pendingInspection('P17-F-103-B');
        $key = 'p17-shared-' . Str::uuid();
        $this->claims()->claim($this->housekeepingInspector, $firstInspection->id, $key);
        $otherInspector = $this->hkUser('P17 Other Inspector', 'p17-other-inspector@example.test');
        $this->hkAttachProperty($otherInspector, $this->property);
        $otherInspector->givePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
        $before = $this->claimCounts();

        foreach ([
            fn () => $this->claims()->claim($this->housekeepingInspector, $secondInspection->id, $key),
            fn () => $this->claims()->claim($otherInspector, $firstInspection->id, 'p17-replace-' . Str::uuid()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected controlled claim conflict.');
            } catch (DomainException $exception) {
                $this->assertContains($exception->getMessage(), [
                    HousekeepingInspectionClaimService::IDEMPOTENCY_CONFLICT,
                    HousekeepingInspectionClaimService::NOT_ELIGIBLE,
                ]);
                $this->assertSame($before, $this->claimCounts());
            }
        }
    }

    public function test_claim_identity_is_immutable_in_model_and_source_hash_revalidates(): void
    {
        [, $task, $inspection] = $this->pendingInspection('P17-F-104');
        $claimed = $this->claims()->claim($this->housekeepingInspector, $inspection->id, 'p17-immutable-' . Str::uuid())->inspection;
        $expectedHash = $this->claims()->sourceHash(
            1,
            $this->property->id,
            $inspection->id,
            $inspection->room_id,
            $task->id,
            $this->housekeepingActor->id,
            $this->housekeepingInspector->id,
        );
        $this->assertSame($expectedHash, $claimed->claim_source_hash);

        foreach (['supervisor_id', 'claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'] as $field) {
            $copy = $claimed->fresh();
            try {
                $copy->setAttribute($field, $field === 'claim_evidence_version' ? 2 : ($field === 'claimed_at' ? now()->addMinute() : 'changed-value'));
                $copy->save();
                $this->fail("Expected {$field} immutability rejection.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString('claim evidence is immutable', $exception->getMessage());
            }
        }
    }

    public function test_current_property_membership_permission_policy_and_active_actor_are_required(): void
    {
        [, , $inspection] = $this->pendingInspection('P17-F-105');
        $unauthorized = $this->hkUser('P17 Unauthorized', 'p17-unauthorized@example.test');
        $this->hkAttachProperty($unauthorized, $this->property);
        $before = $this->claimCounts();

        $this->expectDenied(fn () => $this->claims()->claim($unauthorized, $inspection->id, 'p17-denied-' . Str::uuid()), $before);
        $unauthorized->givePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $unauthorized->id)->update(['status' => 'inactive']);
        $this->expectDenied(fn () => $this->claims()->claim($unauthorized, $inspection->id, 'p17-membership-' . Str::uuid()), $before);
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $unauthorized->id)->update(['status' => 'active']);
        $unauthorized->update(['is_active' => false]);
        $this->expectDenied(fn () => $this->claims()->claim($unauthorized, $inspection->id, 'p17-inactive-' . Str::uuid()), $before);
    }

    public function test_cross_property_and_invalid_source_relationships_are_non_disclosing_and_mutation_free(): void
    {
        $invalidRoom = Room::findOrFail($this->hkCleanRoom($this->property, 'P17-F-106'));
        $invalidTask = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $invalidRoom->id,
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => null,
        ]);
        $inspection = RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $invalidRoom->id,
            'cleaning_task_id' => $invalidTask->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);
        $otherRoom = Room::findOrFail($this->hkCleanRoom($this->otherProperty, 'P17-X'));
        $otherTask = CleaningTask::create([
            'property_id' => $this->otherProperty->id,
            'room_id' => $otherRoom->id,
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $this->housekeepingActor->id,
        ]);
        $sibling = RoomInspection::create([
            'property_id' => $this->otherProperty->id,
            'room_id' => $otherRoom->id,
            'cleaning_task_id' => $otherTask->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);
        $before = $this->claimCounts();

        $this->expectDenied(fn () => $this->claims()->claim($this->housekeepingInspector, $sibling->id, 'p17-cross-' . Str::uuid()), $before);
        try {
            $this->claims()->claim($this->housekeepingInspector, $inspection->id, 'p17-source-' . Str::uuid());
            $this->fail('Expected incomplete source rejection.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingInspectionClaimService::SOURCE_CONFLICT, $exception->getMessage());
            $this->assertSame($before, $this->claimCounts());
        }
    }

    public function test_completed_assignment_contradiction_fails_closed(): void
    {
        $room = Room::findOrFail($this->hkCleanRoom($this->property, 'P17-F-107'));
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'task_type' => 'checkout_cleaning',
            'status' => 'assigned',
        ]);
        $assignment = TaskAssignment::create([
            'property_id' => $this->property->id,
            'cleaning_task_id' => $task->id,
            'user_id' => $this->housekeepingInspector->id,
            'attendant_id' => $this->housekeepingInspector->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'assigned_at' => now(),
            'assigned_by' => $this->housekeepingInspector->id,
            'assignment_action' => 'initial',
            'idempotency_key' => 'p17-assignment-' . Str::uuid(),
            'source_hash' => hash('sha256', 'p17-assignment-' . $task->id),
            'evidence_version' => 'housekeeping-assignment-v1',
        ]);
        DB::transaction(function () use ($task, $assignment): void {
            DB::table('cleaning_tasks')->where('id', $task->id)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $this->housekeepingActor->id,
            ]);
            DB::table('housekeeping_task_assignments')->where('id', $assignment->id)->update([
                'status' => 'completed',
                'closed_at' => now(),
                'completed_at' => now(),
                'closed_by' => $this->housekeepingInspector->id,
                'closure_reason' => 'Contradictory direct evidence',
            ]);
        });
        $inspection = RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'cleaning_task_id' => $task->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(HousekeepingInspectionClaimService::SOURCE_CONFLICT);
        $this->claims()->claim($this->housekeepingInspector, $inspection->id, 'p17-assignment-conflict-' . Str::uuid());
    }

    /** @return array{0: Room, 1: CleaningTask, 2: RoomInspection} */
    private function pendingInspection(string $roomNumber): array
    {
        $room = Room::findOrFail($this->hkCleanRoom($this->property, $roomNumber));
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-' . $roomNumber,
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'completed_by' => $this->housekeepingActor->id,
        ]);
        $inspection = RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'cleaning_task_id' => $task->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);

        return [$room, $task, $inspection];
    }

    private function claims(): HousekeepingInspectionClaimService
    {
        return app(HousekeepingInspectionClaimService::class);
    }

    /** @return array<string, int> */
    private function claimCounts(): array
    {
        return [
            'claimed' => RoomInspection::whereNotNull('claim_evidence_version')->count(),
            'audits' => DB::table('audit_logs')->where('event', 'housekeeping_inspection_claimed')->count(),
        ];
    }

    /** @param callable(): mixed $operation */
    private function expectDenied(callable $operation, array $before): void
    {
        try {
            $operation();
            $this->fail('Expected non-disclosing authorization rejection.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(HousekeepingInspectionClaimService::NOT_AUTHORIZED, $exception->getMessage());
            $this->assertSame($before, $this->claimCounts());
        }
    }
}
