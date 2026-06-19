<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Services\JournalCandidateService;

class JournalCandidateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $property;
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->user = User::first();
        $this->property = Property::first();
        $this->actingAs($this->user);
        
        $this->service = app(JournalCandidateService::class);
    }

    public function test_candidate_creation_with_lines()
    {
        $candidate = $this->service->create([
            'property_id' => $this->property->id,
            'source_type' => 'InventoryTransaction',
            'source_id' => '01HXXXXXX',
            'posting_event' => 'StockOpnameVariance',
            'candidate_date' => now()->toDateString(),
            'description' => 'Stock Opname Variance Posting',
        ], [
            [
                'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => 100.00,
            ],
            [
                'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => 100.00,
            ]
        ]);

        $this->assertDatabaseHas('journal_candidates', [
            'id' => $candidate->id,
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'property_id' => $this->property->id,
            'source_type' => 'InventoryTransaction',
        ]);

        $this->assertCount(2, $candidate->lines);
        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => '100.0000',
        ]);
    }

    public function test_submit_for_review_and_approve()
    {
        $candidate = $this->service->create([
            'property_id' => $this->property->id,
            'source_type' => 'InventoryTransaction',
            'source_id' => '01HXXXXXY',
            'posting_event' => 'StockOpnameVariance',
            'candidate_date' => now()->toDateString(),
        ]);

        $this->assertEquals(JournalCandidateStatusEnum::DRAFT, $candidate->status);

        $submitted = $this->service->submitForReview($candidate->id);
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $submitted->status);

        $approved = $this->service->approve($candidate->id);
        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_reject_and_posted_transitions()
    {
        $candidate = $this->service->create([
            'property_id' => $this->property->id,
            'source_type' => 'InventoryTransaction',
            'source_id' => '01HXXXXXZ',
            'posting_event' => 'StockOpnameVariance',
            'candidate_date' => now()->toDateString(),
        ]);

        $approved = $this->service->approve($candidate->id);
        $posted = $this->service->markPosted($approved->id);
        
        $this->assertEquals(JournalCandidateStatusEnum::POSTED, $posted->status);

        // Should not reject POSTED
        $this->expectException(ValidationException::class);
        $this->service->reject($posted->id, 'Rejection reason');
    }

    public function test_property_isolation()
    {
        $candidate = $this->service->create([
            'property_id' => $this->property->id,
            'source_type' => 'InventoryTransaction',
            'source_id' => '01HXXXXXZ',
            'posting_event' => 'StockOpnameVariance',
            'candidate_date' => now()->toDateString(),
        ]);

        $otherProperty = Property::skip(1)->first();
        
        $this->assertEquals($this->property->id, $candidate->property_id);
        $this->assertNotEquals($otherProperty->id, $candidate->property_id);
    }
}
