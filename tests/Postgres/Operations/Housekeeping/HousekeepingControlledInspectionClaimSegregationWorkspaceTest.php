<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimSegregationWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousekeepingRoomReadinessFixture();
        foreach (['housekeeping.inspection.view', HousekeepingInspectionClaimService::CLAIM_PERMISSION] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->housekeepingInspector->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
        $this->housekeepingActor->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
        ]);
    }

    public function test_index_is_current_property_scoped_and_projects_cleaner_and_claim_state_without_secrets(): void
    {
        $current = $this->pendingInspection('P17-W-101');
        $this->pendingInspection('P17-W-X', true);

        $response = $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->get('/operations/inspections', $this->headers());

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Housekeeping/Inspections/Index')
            ->has('inspections.data', 1)
            ->where('inspections.data.0.id', $current->id)
            ->where('inspections.data.0.task.completed_by_name', $this->housekeepingActor->name)
            ->where('inspections.data.0.claim.state', 'available')
            ->where('inspections.data.0.claim.can_claim', true));

        $serialized = $response->getContent();
        foreach (['claim_source_hash', 'claim_idempotency_key', 'password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_pending_show_projects_pre_action_gate_for_eligible_inspector_and_blocks_cleaner(): void
    {
        $inspection = $this->pendingInspection('P17-W-102');

        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->get('/operations/inspections/' . $inspection->id, $this->headers())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inspection.claim.can_claim', true)
                ->where('inspection.claim.is_current_actor_cleaner', false)
                ->where('inspection.task.completed_by_name', $this->housekeepingActor->name));

        $this->flushSession();
        $this->actingAs($this->housekeepingActor, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->get('/operations/inspections/' . $inspection->id, $this->headers())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inspection.claim.can_claim', false)
                ->where('inspection.claim.is_current_actor_cleaner', true));
    }

    public function test_claimed_show_gives_terminal_actions_only_to_server_projected_owner(): void
    {
        $inspection = $this->pendingInspection('P17-W-103');
        app(HousekeepingInspectionClaimService::class)->claim(
            $this->housekeepingInspector,
            $inspection->id,
            'p17-workspace-' . Str::uuid(),
        );
        $other = $this->hkUser('P17 Workspace Viewer', 'p17-workspace-viewer@example.test');
        $this->hkAttachProperty($other, $this->property);
        $other->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);

        $this->actingAs($this->housekeepingInspector, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->get('/operations/inspections/' . $inspection->id, $this->headers())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inspection.claim.state', 'claimed')
                ->where('inspection.claim.is_owned_by_current_actor', true)
                ->where('inspection.claim.can_pass', true)
                ->where('inspection.claim.can_fail', true)
                ->has('pass_context'));

        $this->flushSession();
        $this->actingAs($other, 'web')
            ->withSession($this->hkPropertySession($this->property))
            ->get('/operations/inspections/' . $inspection->id, $this->headers())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inspection.claim.is_owned_by_current_actor', false)
                ->where('inspection.claim.can_pass', false)
                ->where('inspection.claim.can_fail', false)
                ->where('pass_context', null));
    }

    public function test_workspace_source_uses_controlled_confirmation_and_memory_only_retry_identity(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));

        $this->assertStringContainsString('Claim Post-cleaning Inspection', $source);
        $this->assertStringContainsString('immutable inspector claimant', $source);
        $this->assertStringContainsString('cannot inspect their own work', $source);
        $this->assertStringContainsString('cannot silently take over', $source);
        $this->assertStringContainsString('window.crypto.randomUUID()', $source);
        $this->assertStringContainsString('retained claim command', $source);
        $this->assertStringNotContainsString("window.confirm('Start this inspection?')", $source);
        $this->assertStringNotContainsString('localStorage', $source);
        $this->assertStringNotContainsString('sessionStorage', $source);
        $this->assertStringNotContainsString('claim_source_hash', $source);
        $this->assertStringNotContainsString('claim_idempotency_key', $source);
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
