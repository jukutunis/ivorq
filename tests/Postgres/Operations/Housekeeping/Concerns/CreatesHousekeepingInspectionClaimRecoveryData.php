<?php

namespace Tests\Postgres\Operations\Housekeeping\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimRecoveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;

trait CreatesHousekeepingInspectionClaimRecoveryData
{
    use CreatesHousekeepingRoomReadinessData;

    protected User $p19Intervenor;

    protected User $p19Replacement;

    protected function setUpInspectionClaimRecoveryFixture(): void
    {
        $this->setUpHousekeepingRoomReadinessFixture();
        foreach ([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingInspectionClaimRecoveryService::INTERVENE_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->housekeepingInspector->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
        $this->p19Intervenor = $this->hkUser('Package 19 Supervisor', 'p19-supervisor@example.test');
        $this->hkAttachProperty($this->p19Intervenor, $this->property);
        $this->p19Intervenor->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimRecoveryService::INTERVENE_PERMISSION,
        ]);
        $this->p19Replacement = $this->hkUser('Package 19 Replacement', 'p19-replacement@example.test');
        $this->hkAttachProperty($this->p19Replacement, $this->property);
        $this->p19Replacement->givePermissionTo([
            'housekeeping.inspection.view',
            HousekeepingInspectionClaimService::CLAIM_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);
    }

    /** @return array{0: Room, 1: CleaningTask, 2: RoomInspection} */
    protected function p19ClaimedInspection(string $roomNumber): array
    {
        $room = Room::findOrFail($this->hkCleanRoom($this->property, $roomNumber));
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-'.$roomNumber,
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
        app(HousekeepingInspectionClaimService::class)->claim(
            $this->housekeepingInspector,
            $inspection->id,
            'p19-original-'.Str::uuid(),
        );

        return [$room, $task, $inspection->fresh()];
    }

    protected function p19MakeOriginalInactive(string $code = 'user'): void
    {
        if ($code === 'user') {
            $this->housekeepingInspector->update(['is_active' => false]);
        } elseif ($code === 'membership') {
            DB::table('property_user')->where('property_id', $this->property->id)
                ->where('user_id', $this->housekeepingInspector->id)->update(['status' => 'inactive']);
        } else {
            $this->housekeepingInspector->revokePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
        }
    }

    protected function p19Recover(RoomInspection $inspection, ?string $key = null, ?string $reason = null)
    {
        $key ??= 'p19-recovery-'.Str::uuid();
        $reason ??= 'Supervisor restores inspection continuity after objective claimant ineligibility.';
        $service = app(HousekeepingInspectionClaimRecoveryService::class);
        $service->confirmReassignment(
            $this->p19Intervenor,
            $inspection->id,
            $this->p19Replacement->id,
            $reason,
            $key,
            'password',
        );

        return $service->reassign($this->p19Intervenor, $inspection->id, $this->p19Replacement->id, $reason, $key);
    }

    protected function p19Headers(): array
    {
        return ['X-Property-ID' => $this->property->id];
    }
}
