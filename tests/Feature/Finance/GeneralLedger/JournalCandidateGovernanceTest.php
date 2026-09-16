<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Exceptions\JournalCandidateBalanceException;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalCandidateLine;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\JournalCandidateService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Tests\TestCase;

class JournalCandidateGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;

    protected $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        $this->actingAs(User::first());
        $this->service = app(JournalCandidateService::class);
    }

    private function createBalancedCandidate(JournalCandidateStatusEnum $status = JournalCandidateStatusEnum::PENDING_REVIEW): JournalCandidate
    {
        $candidate = JournalCandidate::create([
            'property_id' => $this->property->id,
            'source_type' => 'Test',
            'source_id' => '123',
            'posting_event' => 'TestEvent',
            'status' => $status->value,
            'candidate_date' => now(),
            'description' => 'Test Candidate',
        ]);

        JournalCandidateLine::create([
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 100.00,
        ]);

        JournalCandidateLine::create([
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => 100.00,
        ]);

        return $candidate->load('lines');
    }

    private function createUnbalancedCandidate(): JournalCandidate
    {
        $candidate = JournalCandidate::create([
            'property_id' => $this->property->id,
            'source_type' => 'Test',
            'source_id' => '123',
            'posting_event' => 'TestEvent',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now(),
            'description' => 'Unbalanced Candidate',
        ]);

        JournalCandidateLine::create([
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 100.00,
        ]);

        JournalCandidateLine::create([
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => 50.00, // Unbalanced
        ]);

        return $candidate->load('lines');
    }

    public function test_approval_success()
    {
        $candidate = $this->createBalancedCandidate();

        $approved = $this->service->approve($candidate->id);

        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertEquals(auth()->id(), $approved->approved_by);
    }

    public function test_approval_failure_unbalanced()
    {
        $candidate = $this->createUnbalancedCandidate();

        $this->expectException(JournalCandidateBalanceException::class);
        $this->service->approve($candidate->id);
    }

    public function test_reject_success_and_audit_tracking()
    {
        $candidate = $this->createBalancedCandidate();

        $reason = 'Missing documentation';
        $rejected = $this->service->reject($candidate->id, $reason);

        $this->assertEquals(JournalCandidateStatusEnum::REJECTED, $rejected->status);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertEquals(auth()->id(), $rejected->rejected_by);
        $this->assertEquals($reason, $rejected->rejection_reason);
    }

    public function test_reject_missing_reason()
    {
        $candidate = $this->createBalancedCandidate();

        $this->expectException(ValidationException::class);
        $this->service->reject($candidate->id, '   '); // Empty reason
    }

    public function test_direct_mark_posted_is_disabled_without_mutation_or_journal_evidence()
    {
        $candidate = $this->createBalancedCandidate(JournalCandidateStatusEnum::APPROVED);

        try {
            $this->service->markPosted($candidate->id);
            $this->fail('Directly marking a candidate as POSTED must remain disabled.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('controlled JournalCandidate review', $exception->getMessage());
        }

        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $candidate->fresh()->status);
        $this->assertFalse(JournalEntry::query()->where('journal_candidate_id', $candidate->id)->exists());
    }

    public function test_posting_failed_status()
    {
        $candidate = $this->createBalancedCandidate(JournalCandidateStatusEnum::APPROVED);

        $failed = $this->service->markPostingFailed($candidate->id, 'GL period is closed');

        $this->assertEquals(JournalCandidateStatusEnum::POSTING_FAILED, $failed->status);
        $this->assertEquals('GL period is closed', $failed->metadata['posting_error']);
    }
}
