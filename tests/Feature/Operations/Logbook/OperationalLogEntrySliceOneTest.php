<?php

namespace Tests\Feature\Operations\Logbook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;

class OperationalLogEntrySliceOneTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Foundation\Concerns\CreatesFoundationData;

    protected Property $property;
    protected Property $otherProperty;
    protected User $userA;
    protected User $userB;
    protected User $otherPropertyUser;
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

        $this->department = $this->createDepartment($this->property);

        // Set default session property context
        session([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
        ]);
    }

    public function test_same_property_user_creates_draft_logbook_entry()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/logbook-entries', [
                'subject' => 'AC Fan Noise',
                'content' => 'AC Fan #1 in lobby is making noise.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'requires_follow_up' => true,
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('logbook_entries', [
            'subject' => 'AC Fan Noise',
            'content' => 'AC Fan #1 in lobby is making noise.',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'property_id' => $this->property->id,
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);
    }

    public function test_create_fails_when_department_is_omitted()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/logbook-entries', [
                'subject' => 'AC Fan Noise',
                'content' => 'AC Fan #1 in lobby is making noise.',
                'category' => 'Engineering',
                'priority' => 'normal',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_create_fails_when_department_belongs_to_another_property()
    {
        $otherDept = $this->createDepartment($this->otherProperty);

        $response = $this->actingAs($this->userA)
            ->postJson('/api/v1/operations/logbook-entries', [
                'subject' => 'AC Fan Noise',
                'content' => 'AC Fan #1 in lobby is making noise.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => $otherDept->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_creator_can_replace_department_within_same_property_while_draft()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $anotherDept = $this->createDepartment($this->property);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/logbook-entries/{$entry->id}", [
                'subject' => 'Water issue updated',
                'content' => 'Low water pressure.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => $anotherDept->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('logbook_entries', [
            'id' => $entry->id,
            'subject' => 'Water issue updated',
            'department_id' => $anotherDept->id,
        ]);
    }

    public function test_creator_cannot_clear_department_while_draft()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/logbook-entries/{$entry->id}", [
                'subject' => 'Water issue updated',
                'content' => 'Low water pressure.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => '',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_id']);
    }

    public function test_another_user_cannot_edit_creator_draft()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->patchJson("/api/v1/operations/logbook-entries/{$entry->id}", [
                'subject' => 'Hacked Subject',
                'content' => 'Low water pressure.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_creator_submits_valid_draft()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals(LogbookEntryStatusEnum::Submitted, $entry->fresh()->status);
        $this->assertEquals($this->userA->id, $entry->fresh()->submitted_by);
        $this->assertNotNull($entry->fresh()->submitted_at);
    }

    public function test_submitted_entry_cannot_be_edited()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->patchJson("/api/v1/operations/logbook-entries/{$entry->id}", [
                'subject' => 'Hacked Subject',
                'content' => 'Hacked.',
                'category' => 'Engineering',
                'priority' => 'normal',
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_submitted_entry_cannot_be_submitted_again()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_requires_follow_up_persists_on_draft_and_submitted()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Water issue',
            'content' => 'Low water pressure.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $this->assertTrue($entry->requires_follow_up);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/submit", [], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertTrue($entry->fresh()->requires_follow_up);
    }

    public function test_my_operational_entries_excludes_other_users_entries()
    {
        // User A draft entry
        $entryA = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'User A Log',
            'content' => 'Lobby fan broken.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
        ]);

        // User B draft entry
        $entryB = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'User B Log',
            'content' => 'Kitchen stove issue.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userB->id,
            'department_id' => $this->department->id,
        ]);

        // User A requests their list
        $response = $this->actingAs($this->userA)
            ->getJson('/api/v1/operations/logbook-entries', ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $entries = $response->json('entries');
        
        $this->assertCount(1, $entries);
        $this->assertEquals($entryA->id, $entries[0]['id']);
    }
}
