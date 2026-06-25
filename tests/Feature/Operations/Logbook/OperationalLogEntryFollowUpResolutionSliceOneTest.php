<?php

namespace Tests\Feature\Operations\Logbook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntryFollowUpResolution;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;

class OperationalLogEntryFollowUpResolutionSliceOneTest extends TestCase
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

    public function test_entry_requiring_follow_up_is_open_before_resolution()
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
            'requires_follow_up' => true,
        ]);

        $this->assertNull($entry->resolution);

        // Assert derived status: requires_follow_up is true and no resolution means "Open"
        $hasResolution = LogbookEntryFollowUpResolution::where('logbook_entry_id', $entry->id)->exists();
        $derivedStatus = !$entry->requires_follow_up ? 'Not Required' : ($hasResolution ? 'Resolved' : 'Open');
        $this->assertEquals('Open', $derivedStatus);
    }

    public function test_creator_can_append_one_valid_resolution()
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
            'requires_follow_up' => true,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed the water pressure valves.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('logbook_entry_follow_up_resolutions', [
            'logbook_entry_id' => $entry->id,
            'resolution_note' => 'Fixed the water pressure valves.',
            'resolved_by' => $this->userA->id,
            'property_id' => $this->property->id,
        ]);

        $resolution = LogbookEntryFollowUpResolution::where('logbook_entry_id', $entry->id)->first();
        $this->assertNotNull($resolution->resolved_at);

        $hasResolution = LogbookEntryFollowUpResolution::where('logbook_entry_id', $entry->id)->exists();
        $derivedStatus = !$entry->requires_follow_up ? 'Not Required' : ($hasResolution ? 'Resolved' : 'Open');
        $this->assertEquals('Resolved', $derivedStatus);
    }

    public function test_resolution_does_not_mutate_parent_entry()
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
            'submitted_at' => now()->subHour(),
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $originalEntry = $entry->fresh();

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);

        $freshEntry = $entry->fresh();
        $this->assertEquals($originalEntry->status, $freshEntry->status);
        $this->assertEquals($originalEntry->requires_follow_up, $freshEntry->requires_follow_up);
        $this->assertEquals($originalEntry->submitted_by, $freshEntry->submitted_by);
        $this->assertEquals($originalEntry->submitted_at, $freshEntry->submitted_at);
        $this->assertEquals($originalEntry->subject, $freshEntry->subject);
        $this->assertEquals($originalEntry->content, $freshEntry->content);
        $this->assertEquals($originalEntry->category, $freshEntry->category);
        $this->assertEquals($originalEntry->priority, $freshEntry->priority);
    }

    public function test_draft_entry_cannot_be_resolved()
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

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_submitted_entry_with_requires_follow_up_false_cannot_be_resolved()
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
            'requires_follow_up' => false,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_another_same_property_user_cannot_resolve_creators_entry()
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
            'requires_follow_up' => true,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_cross_property_user_cannot_resolve_entry_from_another_property()
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
            'requires_follow_up' => true,
        ]);

        // Attempt as User from another property, passing User property ID context to mock bad cross-property request
        $response = $this->actingAs($this->otherPropertyUser)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Fixed.',
            ], ['X-Property-ID' => $this->otherProperty->id]);

        $response->assertStatus(403);
    }

    public function test_second_resolution_fails_closed()
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
            'requires_follow_up' => true,
        ]);

        // Create first resolution
        LogbookEntryFollowUpResolution::create([
            'property_id' => $this->property->id,
            'logbook_entry_id' => $entry->id,
            'resolution_note' => 'First attempt note.',
            'resolved_by' => $this->userA->id,
            'resolved_at' => now(),
        ]);

        // Attempt second resolution via endpoint
        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/follow-up-resolution", [
                'resolution_note' => 'Second attempt note.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['follow_up_resolution']);
        $response->assertJsonFragment(['This follow-up has already been resolved.']);

        // Assert exactly one resolution record exists and the note is unchanged
        $this->assertEquals(1, LogbookEntryFollowUpResolution::where('logbook_entry_id', $entry->id)->count());
        $this->assertEquals('First attempt note.', LogbookEntryFollowUpResolution::where('logbook_entry_id', $entry->id)->first()->resolution_note);

        // Assert parent LogbookEntry remains Submitted and otherwise unchanged
        $entry = $entry->fresh();
        $this->assertEquals(LogbookEntryStatusEnum::Submitted, $entry->status);
        $this->assertTrue($entry->requires_follow_up);
    }

    public function test_my_operational_entries_excludes_other_same_property_user_entries_and_exposes_correct_derived_state()
    {
        $entryA = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'User A Log',
            'content' => 'Lobby fan broken.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $entryB = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'User B Log',
            'content' => 'Kitchen stove issue.',
            'category' => 'Engineering',
            'priority' => 'normal',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userB->id,
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        // Add resolution to entry A
        LogbookEntryFollowUpResolution::create([
            'property_id' => $this->property->id,
            'logbook_entry_id' => $entryA->id,
            'resolution_note' => 'Resolved Lobby Fan.',
            'resolved_by' => $this->userA->id,
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($this->userA)
            ->getJson('/api/v1/operations/logbook-entries', ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $entries = $response->json('entries');

        $this->assertCount(1, $entries);
        $this->assertEquals($entryA->id, $entries[0]['id']);
        $this->assertNotNull($entries[0]['resolution']);
        $this->assertEquals('Resolved Lobby Fan.', $entries[0]['resolution']['resolution_note']);
    }

    public function test_database_unique_constraint_has_correct_name()
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
            'requires_follow_up' => true,
        ]);

        // Create first resolution
        LogbookEntryFollowUpResolution::create([
            'property_id' => $this->property->id,
            'logbook_entry_id' => $entry->id,
            'resolution_note' => 'First attempt note.',
            'resolved_by' => $this->userA->id,
            'resolved_at' => now(),
        ]);

        // Directly attempt insertion to bypass service code and trigger database constraint
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('logbook_entry_resolution_unique');

        LogbookEntryFollowUpResolution::create([
            'property_id' => $this->property->id,
            'logbook_entry_id' => $entry->id,
            'resolution_note' => 'Direct DB duplicate note.',
            'resolved_by' => $this->userA->id,
            'resolved_at' => now(),
        ]);
    }
}
