<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimSegregationHttpTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousekeepingRoomReadinessFixture();
        foreach (['housekeeping.inspection.view', 'housekeeping.inspection.create', HousekeepingInspectionClaimService::CLAIM_PERMISSION] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->housekeepingInspector->givePermissionTo([
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
    }

    public function test_conduct_boundary_accepts_only_idempotency_key_and_returns_bounded_claim_evidence(): void
    {
        $inspection = $this->pendingInspection('P17-H-101');
        $key = 'p17-http-' . Str::uuid();

        $response = $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/conduct', ['idempotency_key' => $key], $this->headers());

        $response->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('inspection.id', $inspection->id)
            ->assertJsonPath('inspection.claim.state', 'claimed')
            ->assertJsonPath('inspection.claim.is_owned_by_current_actor', true)
            ->assertJsonMissingPath('inspection.claim_source_hash')
            ->assertJsonMissingPath('inspection.claim_idempotency_key');
        $this->assertSame($this->housekeepingInspector->id, $inspection->fresh()->supervisor_id);

        $this->postJson('/operations/inspections/' . $inspection->id . '/conduct', ['idempotency_key' => $key], $this->headers())
            ->assertOk()
            ->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_inspection_claimed')->where('auditable_id', $inspection->id)->count());
    }

    public function test_conduct_rejects_missing_malformed_unknown_and_browser_authority_fields(): void
    {
        $inspection = $this->pendingInspection('P17-H-102');
        $before = RoomInspection::whereNotNull('claim_evidence_version')->count();

        foreach ([
            [],
            ['idempotency_key' => 'short'],
            ['idempotency_key' => 'p17-valid-command', 'supervisor_id' => $this->housekeepingInspector->id],
            ['idempotency_key' => 'p17-valid-command', 'claim_source_hash' => str_repeat('a', 64)],
            ['idempotency_key' => 'p17-valid-command', 'property_id' => $this->otherProperty->id],
        ] as $payload) {
            $this->actingAs($this->housekeepingInspector, 'web')
                ->withSession($this->hkPropertySession($this->property))
                ->postJson('/operations/inspections/' . $inspection->id . '/conduct', $payload, $this->headers())
                ->assertUnprocessable();
            $this->assertSame($before, RoomInspection::whereNotNull('claim_evidence_version')->count());
        }
    }

    public function test_cleaner_and_cross_property_identifier_are_rejected_without_disclosure_or_mutation(): void
    {
        $inspection = $this->pendingInspection('P17-H-103');
        $this->housekeepingActor->givePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);

        $this->actingAs($this->housekeepingActor, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/conduct', ['idempotency_key' => 'p17-cleaner-http'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('message', HousekeepingInspectionClaimService::CLEANER_PROHIBITED);

        $sibling = $this->pendingInspection('P17-H-X', true);
        $this->flushSession();
        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $sibling->id . '/conduct', ['idempotency_key' => 'p17-cross-http'], $this->headers())
            ->assertForbidden();
        $this->assertSame(0, RoomInspection::whereNotNull('claim_evidence_version')->count());
    }

    public function test_generic_crud_cannot_create_post_cleaning_or_select_inspector_authority(): void
    {
        $room = Room::findOrFail($this->hkCleanRoom($this->property, 'P17-H-104'));
        $payload = [
            'room_id' => $room->id,
            'inspection_type' => 'post_cleaning',
            'inspector_id' => $this->housekeepingInspector->id,
        ];

        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections', $payload, $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['inspection_type', 'request']);
        $this->assertSame(0, RoomInspection::count());
    }

    public function test_update_boundary_is_current_property_scoped_and_prohibits_claim_authority(): void
    {
        $inspection = $this->pendingInspection('P17-H-105');
        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->putJson('/operations/inspections/' . $inspection->id, [
                'remarks' => 'Allowed note',
                'supervisor_id' => $this->housekeepingInspector->id,
                'claimed_at' => now()->toIso8601String(),
                'claim_evidence_version' => 1,
            ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supervisor_id', 'claimed_at', 'claim_evidence_version']);
        $this->assertNull($inspection->fresh()->supervisor_id);

        $sibling = $this->pendingInspection('P17-H-105-X', true);
        $this->putJson('/operations/inspections/' . $sibling->id, ['remarks' => 'Sibling mutation'], $this->headers())
            ->assertForbidden();
        $this->assertNull($sibling->fresh()->remarks);
    }

    public function test_only_claimant_may_fail_even_when_another_inspector_has_same_permissions(): void
    {
        $inspection = $this->pendingInspection('P17-H-106');
        app(HousekeepingInspectionClaimService::class)->claim($this->housekeepingInspector, $inspection->id, 'p17-terminal-owner');
        $other = $this->hkUser('P17 Same Permission Inspector', 'p17-same-permission@example.test');
        $this->hkAttachProperty($other, $this->property);
        $other->givePermissionTo([
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        ]);

        $this->actingAs($other, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/fail', ['remarks' => 'Non-owner attempt'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('message', HousekeepingInspectionClaimService::OWNERSHIP_REQUIRED);
        $this->assertSame('in_progress', $inspection->fresh()->status->value);

        $this->flushSession();
        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/fail', ['remarks' => 'Claimant records re-cleaning'], $this->headers())
            ->assertOk()
            ->assertJsonPath('inspection.status.value', 'failed');
    }

    public function test_pass_context_confirmation_and_release_remain_claimant_owned_and_sensitive(): void
    {
        $inspection = $this->pendingInspection('P17-H-107');
        app(HousekeepingInspectionClaimService::class)->claim($this->housekeepingInspector, $inspection->id, 'p17-pass-owner');
        $other = $this->hkUser('P17 Pass Non-owner', 'p17-pass-non-owner@example.test');
        $this->hkAttachProperty($other, $this->property);
        $other->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
        $reason = 'Claimant confirms room release evidence.';

        $this->actingAs($other, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/pass-confirmation', [
                'release_reason' => $reason,
                'password' => 'password',
            ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('message', HousekeepingInspectionClaimService::OWNERSHIP_REQUIRED);
        $this->assertSame('in_progress', $inspection->fresh()->status->value);

        $this->flushSession();
        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->postJson('/operations/inspections/' . $inspection->id . '/pass-confirmation', [
                'release_reason' => $reason,
                'password' => 'password',
            ], $this->headers())
            ->assertOk()
            ->assertJsonPath('release_context.inspection_status', 'in_progress');
        $this->actingAs($this->housekeepingInspector, 'web')
            ->postJson('/operations/inspections/' . $inspection->id . '/pass', [
            'release_reason' => $reason,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('inspection.status.value', 'passed');
        $this->assertSame('ready_for_sale', Room::findOrFail($inspection->room_id)->readiness_state);
    }

    private function pendingInspection(string $roomNumber, bool $otherProperty = false): RoomInspection
    {
        $property = $otherProperty ? $this->otherProperty : $this->property;
        $room = Room::findOrFail($this->hkCleanRoom($property, $roomNumber));
        $task = CleaningTask::create([
            'property_id' => $property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-' . $roomNumber,
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $this->housekeepingActor->id,
        ]);

        return RoomInspection::create([
            'property_id' => $property->id,
            'room_id' => $room->id,
            'cleaning_task_id' => $task->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Property-ID' => $this->property->id];
    }
}
