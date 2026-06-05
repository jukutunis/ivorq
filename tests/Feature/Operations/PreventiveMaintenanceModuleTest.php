<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceService;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceTaskService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class PreventiveMaintenanceModuleTest extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_pm_program_stores_in_database(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $pm = app(PreventiveMaintenanceService::class)->create([
            'property_id' => $property->id,
            'pm_code'     => 'PM-MOD-001',
            'title'       => 'Monthly HVAC Service',
            'frequency'   => PmFrequencyEnum::Monthly->value,
            'status'      => PmStatusEnum::Active->value,
        ]);

        $this->assertInstanceOf(PreventiveMaintenance::class, $pm);
        $this->assertDatabaseHas('preventive_maintenances', [
            'property_id' => $property->id,
            'pm_code'     => 'PM-MOD-001',
            'status'      => 'active',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_pm_strips_status_and_schedule_fields(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property);

        $updated = $service->update($pm->id, [
            'title'       => 'Updated PM Title',
            'status'      => PmStatusEnum::Inactive->value,
            'last_run_at' => now()->subDay(),
            'next_due_at' => now()->addMonth(),
        ]);

        $this->assertSame('Updated PM Title', $updated->title);
        $this->assertSame(PmStatusEnum::Active, $updated->status);
        $this->assertNull($updated->last_run_at);
        $this->assertNull($updated->next_due_at);
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_activate_pm_from_paused(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property, ['status' => PmStatusEnum::Paused->value]);

        $activated = $service->activate($pm->id);
        $this->assertSame(PmStatusEnum::Active, $activated->status);
    }

    public function test_deactivate_pm_from_active(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property);

        $deactivated = $service->deactivate($pm->id);
        $this->assertSame(PmStatusEnum::Inactive, $deactivated->status);
    }

    public function test_pause_pm_from_active(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property);

        $paused = $service->pause($pm->id);
        $this->assertSame(PmStatusEnum::Paused, $paused->status);
    }

    public function test_invalid_pm_status_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property, ['status' => PmStatusEnum::Inactive->value]);

        // inactive → paused is prohibited
        $this->expectException(ValidationException::class);
        $service->pause($pm->id);
    }

    // ── Generate task ─────────────────────────────────────────────────────────

    public function test_generate_task_creates_scheduled_task_and_updates_next_due_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePmModel($property, ['frequency' => PmFrequencyEnum::Monthly->value]);

        $task = $service->generateTask($pm->id);

        $this->assertInstanceOf(PreventiveMaintenanceTask::class, $task);
        $this->assertSame($pm->id, $task->preventive_maintenance_id);
        $this->assertSame(PmTaskStatusEnum::Scheduled, $task->status);

        $pm->refresh();
        $this->assertNotNull($pm->next_due_at);
        $this->assertEqualsWithDelta(30, now()->diffInDays($pm->next_due_at), 1);
    }

    // ── PM Task changeStatus ──────────────────────────────────────────────────

    public function test_pm_task_change_status_scheduled_to_assigned(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePmModel($property);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        $updated = $service->changeStatus($task->id, PmTaskStatusEnum::Assigned);
        $this->assertSame(PmTaskStatusEnum::Assigned, $updated->status);
    }

    public function test_pm_task_completed_updates_pm_schedule_via_listener(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePmModel($property, ['frequency' => PmFrequencyEnum::Weekly->value]);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::InProgress->value,
        ]);

        $service->changeStatus($task->id, PmTaskStatusEnum::Completed);

        $pm->refresh();
        $this->assertNotNull($pm->last_run_at);
        $this->assertNotNull($pm->next_due_at);
        $this->assertEqualsWithDelta(7, $pm->last_run_at->diffInDays($pm->next_due_at), 1);
    }

    public function test_pm_task_invalid_transition_throws(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePmModel($property);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        // scheduled → completed without going through assigned/in_progress
        $this->expectException(ValidationException::class);
        $service->changeStatus($task->id, PmTaskStatusEnum::Completed);
    }

    public function test_pm_task_mark_overdue_only_affects_past_non_terminal(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePmModel($property);

        $past = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->subDay(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        $future = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->addDay(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        $done = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->subDay(),
            'status'                    => PmTaskStatusEnum::Completed->value,
        ]);

        $count = $service->markOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(PmTaskStatusEnum::Overdue,    $past->fresh()->status);
        $this->assertSame(PmTaskStatusEnum::Scheduled,  $future->fresh()->status);
        $this->assertSame(PmTaskStatusEnum::Completed,  $done->fresh()->status);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_pm_policy_denies_update_and_generate_task(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PM-PB20']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $pm = $this->makePmModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update',       $pm)->denied());
        $this->assertTrue(Gate::inspect('generateTask', $pm)->denied());
        $this->assertTrue(Gate::inspect('delete',       $pm)->denied());
    }
}
