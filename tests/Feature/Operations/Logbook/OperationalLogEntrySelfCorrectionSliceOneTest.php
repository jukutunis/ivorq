<?php

namespace Tests\Feature\Operations\Logbook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntrySelfCorrection;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;

class OperationalLogEntrySelfCorrectionSliceOneTest extends TestCase
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

    public function test_creator_can_append_valid_self_correction_to_submitted_entry()
    {
        $submittedAt = now()->subMinutes(10);
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'AC Leaking',
            'content' => 'Lobby AC unit 3 is leaking water.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => $submittedAt,
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $originalEntry = $entry->fresh();

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Wrong AC Unit number',
                'correction_content' => 'Correction: It is actually AC unit 4, not unit 3.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);

        // Assert self-correction is persisted correctly
        $this->assertDatabaseHas('logbook_entry_self_corrections', [
            'logbook_entry_id' => $entry->id,
            'correction_reason' => 'Wrong AC Unit number',
            'correction_content' => 'Correction: It is actually AC unit 4, not unit 3.',
            'corrected_by' => $this->userA->id,
            'property_id' => $this->property->id,
        ]);

        $correction = LogbookEntrySelfCorrection::where('logbook_entry_id', $entry->id)->first();
        $this->assertNotNull($correction->corrected_at);

        // Assert parent remains unchanged
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

    public function test_creator_can_append_multiple_corrections_chronologically()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'AC Leaking',
            'content' => 'Lobby AC unit 3 is leaking water.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        // First correction
        $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Wrong unit',
                'correction_content' => 'Unit 4, not 3.',
            ], ['X-Property-ID' => $this->property->id]);

        // Second correction after a brief pause to ensure distinct timestamps
        $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Also priority typo',
                'correction_content' => 'Should be medium priority, not high.',
            ], ['X-Property-ID' => $this->property->id]);

        $this->assertEquals(2, LogbookEntrySelfCorrection::where('logbook_entry_id', $entry->id)->count());

        $corrections = $entry->fresh()->corrections;
        $this->assertCount(2, $corrections);
        $this->assertEquals('Wrong unit', $corrections[0]->correction_reason);
        $this->assertEquals('Also priority typo', $corrections[1]->correction_reason);
    }

    public function test_draft_entry_cannot_receive_correction()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'Draft log',
            'content' => 'Testing draft log entry.',
            'category' => 'Engineering',
            'priority' => 'low',
            'status' => LogbookEntryStatusEnum::Draft->value,
            'created_by' => $this->userA->id,
            'department_id' => $this->department->id,
            'requires_follow_up' => false,
        ]);

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Correction on draft',
                'correction_content' => 'Should fail.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
        $this->assertEquals(0, LogbookEntrySelfCorrection::where('logbook_entry_id', $entry->id)->count());
    }

    public function test_another_same_property_user_cannot_append_correction()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'AC Leaking',
            'content' => 'Lobby AC unit 3 is leaking water.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Hacker correction',
                'correction_content' => 'I did not create this, but correcting it anyway.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
        $this->assertEquals(0, LogbookEntrySelfCorrection::where('logbook_entry_id', $entry->id)->count());
    }

    public function test_cross_property_context_cannot_append_correction()
    {
        $entry = LogbookEntry::create([
            'property_id' => $this->property->id,
            'subject' => 'AC Leaking',
            'content' => 'Lobby AC unit 3 is leaking water.',
            'category' => 'Engineering',
            'priority' => 'high',
            'status' => LogbookEntryStatusEnum::Submitted->value,
            'created_by' => $this->userA->id,
            'submitted_by' => $this->userA->id,
            'submitted_at' => now(),
            'department_id' => $this->department->id,
            'requires_follow_up' => true,
        ]);

        $response = $this->actingAs($this->otherPropertyUser)
            ->postJson("/api/v1/operations/logbook-entries/{$entry->id}/self-corrections", [
                'correction_reason' => 'Cross property correction',
                'correction_content' => 'Should fail.',
            ], ['X-Property-ID' => $this->otherProperty->id]);

        $response->assertStatus(403);
        $this->assertEquals(0, LogbookEntrySelfCorrection::where('logbook_entry_id', $entry->id)->count());
    }

    public function test_my_operational_entries_displays_only_own_entries_with_corrections_loaded()
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

        // Add self-correction to entry A
        LogbookEntrySelfCorrection::create([
            'property_id' => $this->property->id,
            'logbook_entry_id' => $entryA->id,
            'correction_reason' => 'Correction reason.',
            'correction_content' => 'Correction content.',
            'corrected_by' => $this->userA->id,
            'corrected_at' => now(),
        ]);

        $response = $this->actingAs($this->userA)
            ->getJson('/api/v1/operations/logbook-entries', ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $entries = $response->json('entries');

        $this->assertCount(1, $entries);
        $this->assertEquals($entryA->id, $entries[0]['id']);
        $this->assertCount(1, $entries[0]['corrections']);
        $this->assertEquals('Correction reason.', $entries[0]['corrections'][0]['correction_reason']);
    }
}
