<?php

namespace Tests\Feature\Operations\Logbook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Logbook\Models\ShiftLog;
use Modules\Operations\Logbook\Enums\ShiftLogStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Shift;
use Modules\Foundation\Department\Models\Department;
use Inertia\Testing\AssertableInertia as Assert;

class ShiftLogHandoverSliceOneTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Foundation\Concerns\CreatesFoundationData;

    protected Property $property;
    protected Property $otherProperty;
    protected User $userA;
    protected User $userB;
    protected User $otherPropertyUser;
    protected Shift $shift;
    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);

        // Setup base data
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        
        $company2 = $this->createCompany();
        $this->otherProperty = $this->createProperty($company2, ['code' => 'OTH']);

        $this->userA = $this->createUser($this->property, 'staff');
        $this->userB = $this->createUser($this->property, 'staff');
        $this->otherPropertyUser = $this->createUser($this->otherProperty, 'staff');

        // Create shift and department
        $this->shift = Shift::create([
            'property_id' => $this->property->id,
            'name' => 'Morning Shift',
            'code' => 'M',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_cross_day' => false,
            'is_active' => true,
        ]);

        $this->department = $this->createDepartment($this->property);

        // Set default session property context
        session([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
        ]);
    }

    public function test_same_property_user_creates_draft_shift_log()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/shift-logs', [
                'subject' => 'Chiller Status',
                'content' => 'Chiller #2 is working normally.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'requires_follow_up' => false,
                'shift_id' => $this->shift->id,
                'department_id' => $this->department->id,
                'area' => 'Basement',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shift_logs', [
            'subject' => 'Chiller Status',
            'content' => 'Chiller #2 is working normally.',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'property_id' => $this->property->id,
            'department_id' => $this->department->id,
        ]);
    }

    public function test_create_fails_when_department_is_omitted()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/shift-logs', [
                'subject' => 'Chiller Status',
                'content' => 'Chiller #2 is working normally.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'requires_follow_up' => false,
                'shift_id' => $this->shift->id,
                'area' => 'Basement',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_create_fails_when_department_belongs_to_another_property()
    {
        $otherDept = $this->createDepartment($this->otherProperty);

        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/shift-logs', [
                'subject' => 'Chiller Status',
                'content' => 'Chiller #2 is working normally.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'requires_follow_up' => false,
                'shift_id' => $this->shift->id,
                'department_id' => $otherDept->id,
                'area' => 'Basement',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_creator_edits_own_draft()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Leak report',
            'content' => 'Minor leak in corridor.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Leak report updated',
                'content' => 'Water leak resolved.',
                'category' => 'Maintenance',
                'priority' => 'normal',
                'requires_follow_up' => true,
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shift_logs', [
            'id' => $log->id,
            'subject' => 'Leak report updated',
            'content' => 'Water leak resolved.',
            'requires_follow_up' => true,
            'priority' => 'normal',
            'department_id' => $this->department->id,
        ]);
    }

    public function test_draft_update_can_replace_department_within_same_property()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Leak report',
            'content' => 'Minor leak.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $anotherDept = $this->createDepartment($this->property, ['name' => 'Another Department', 'code' => 'ADEP']);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Leak report updated',
                'content' => 'Water leak resolved.',
                'category' => 'Maintenance',
                'priority' => 'normal',
                'department_id' => $anotherDept->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shift_logs', [
            'id' => $log->id,
            'department_id' => $anotherDept->id,
        ]);
    }

    public function test_draft_update_cannot_clear_department()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Leak report',
            'content' => 'Minor leak.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Leak report updated',
                'content' => 'Water leak resolved.',
                'category' => 'Maintenance',
                'priority' => 'normal',
                'department_id' => '',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_draft_update_cannot_move_department_to_another_property()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Leak report',
            'content' => 'Minor leak.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $otherDept = $this->createDepartment($this->otherProperty);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Leak report updated',
                'content' => 'Water leak resolved.',
                'category' => 'Maintenance',
                'priority' => 'normal',
                'department_id' => $otherDept->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_another_same_property_user_cannot_edit_someone_else_draft()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Leak report',
            'content' => 'Minor leak in corridor.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Leak report updated by malicious user',
                'content' => 'Content edit.',
                'category' => 'Maintenance',
                'priority' => 'normal',
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_creator_submits_own_draft()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals(ShiftLogStatusEnum::Submitted, $log->fresh()->status);
        $this->assertEquals($this->userA->id, $log->fresh()->submitted_by);
        $this->assertNotNull($log->fresh()->submitted_at);
    }

    public function test_submit_fails_closed_for_legacy_style_draft_with_no_department()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Legacy Log',
            'content' => 'No department in database.',
            'category' => 'Maintenance',
            'priority' => 'low',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => null, // Legacy state
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
        $log->refresh();
        $this->assertEquals(ShiftLogStatusEnum::Draft, $log->status);
        $this->assertNull($log->submitted_by);
        $this->assertNull($log->submitted_at);
    }

    public function test_another_user_cannot_submit_someone_else_draft()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
        $this->assertEquals(ShiftLogStatusEnum::Draft, $log->fresh()->status);
    }

    public function test_submitted_log_becomes_immutable()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
        ]);

        // Creator attempts to update it
        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Updated notes',
                'content' => 'New text.',
                'category' => 'Engineering',
                'priority' => 'high',
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403); // Throws exception: only drafts can be edited.
    }

    public function test_different_same_property_user_acknowledges_submitted_shift_log()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals(ShiftLogStatusEnum::Acknowledged, $log->fresh()->status);
        $this->assertEquals($this->userB->id, $log->fresh()->acknowledged_by);
        $this->assertNotNull($log->fresh()->acknowledged_at);
    }

    public function test_creator_cannot_acknowledge_own_shift_log()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403); // Throws exception: A user cannot acknowledge their own shift log
        $this->assertEquals(ShiftLogStatusEnum::Submitted, $log->fresh()->status);
    }

    public function test_acknowledgement_of_draft_fails()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403); // Throws exception: Only submitted logs can be acknowledged
    }

    public function test_repeat_acknowledgement_fails()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Handover notes',
            'content' => 'Please monitor the HVAC panel.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Acknowledged->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'acknowledged_by' => $this->userB->id,
            'acknowledged_at' => now(),
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_requires_follow_up_remains_true_and_visible_after_acknowledgement()
    {
        $log = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Critical Leak',
            'content' => 'Leak report.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'requires_follow_up' => true,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertTrue($log->fresh()->requires_follow_up);
    }

    public function test_cross_property_actions_fail_closed()
    {
        $log = ShiftLog::create([
            'property_id' => $this->otherProperty->id,
            'subject' => 'Other Property Log',
            'content' => 'Other content.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => ShiftLogStatusEnum::Draft->value,
            'created_by' => $this->otherPropertyUser->id,
            'department_id' => $this->department->id, // database allows any ID since it doesn't do cross-property validation on insert
        ]);

        // User A tries to view other property's log
        $response = $this->actingAs($this->userA)
            ->get('/logbook', ['X-Property-ID' => $this->property->id]);
        
        $response->assertStatus(200);
        // Inertia check should not contain other property log
        $response->assertInertia(fn (Assert $page) => $page
            ->has('shiftLogs', 0)
        );

        // User A tries to update other property's log
        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/shift-logs/{$log->id}", [
                'subject' => 'Attempting update',
                'content' => 'Attacking...',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(404);

        // User A tries to submit other property's log
        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(404);

        // Put the other log into submitted status
        $log->update(['status' => ShiftLogStatusEnum::Submitted->value]);

        // User A tries to acknowledge other property's log
        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/shift-logs/{$log->id}/acknowledge", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(404);
    }

    public function test_active_logbook_page_returns_only_resolved_property_logs()
    {
        // Out of scope property log
        ShiftLog::create([
            'property_id' => $this->otherProperty->id,
            'subject' => 'Other Property Log',
            'content' => 'Other content.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->otherPropertyUser->id,
            'department_id' => $this->department->id,
        ]);

        // Same property log
        $activeLog = ShiftLog::create([
            'property_id' => $this->property->id,
            'subject' => 'Active Property Log',
            'content' => 'Active content.',
            'category' => 'Front Desk',
            'priority' => 'normal',
            'status' => ShiftLogStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->get('/logbook', ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Ivorq/Logbook/ShiftLogWorkspace')
            ->has('shiftLogs', 1)
            ->where('shiftLogs.0.id', $activeLog->id)
        );
    }
}
