<?php

namespace Tests\Feature\Finance\Banking;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Banking\Services\ReconciliationFinalizationService;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Illuminate\Support\Str;

class ReconciliationFinalizationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected ReconciliationFinalizationService $service;
    protected $property;
    protected $bankAccount;
    protected $session;
    protected $makerId;
    protected $reviewerId;
    protected $finalizerId;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(ReconciliationFinalizationService::class);

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        
        app(\Shared\Services\CurrentPropertyService::class)->setId($this->property->id);

        $this->bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'current_balance' => 1000.00
        ]);

        $this->makerId = (string) Str::ulid();
        $this->reviewerId = (string) Str::ulid();
        $this->finalizerId = (string) Str::ulid();

        $this->session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Completed,
            'created_by' => $this->makerId,
            'reviewed_by' => $this->reviewerId,
            'completed_by' => $this->reviewerId,
        ]);
    }

    public function test_successful_finalization()
    {
        $this->service->finalize($this->session, $this->finalizerId, 'Finalized successfully');

        $this->session->refresh();

        $this->assertEquals(ReconciliationSessionStatusEnum::Finalized, $this->session->status);
        $this->assertEquals($this->finalizerId, $this->session->finalized_by);
        $this->assertNotNull($this->session->finalized_at);
        $this->assertEquals('Finalized successfully', $this->session->finalization_notes);
    }

    public function test_maker_cannot_finalize()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Maker cannot be Finalizer/');

        $this->service->finalize($this->session, $this->makerId);
    }

    public function test_reviewer_cannot_finalize()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Reviewer cannot be Finalizer/');

        $this->service->finalize($this->session, $this->reviewerId);
    }

    public function test_configuration_error_blocks()
    {
        JournalCandidate::create([
            'property_id' => $this->property->id,
            'source_type' => ReconciliationSession::class,
            'source_id' => $this->session->id,
            'posting_event' => 'BANK_RECONCILIATION_VARIANCE',
            'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR,
            'candidate_date' => now(),
            'description' => 'Error',
            'metadata' => [
                'reconciliation_session_id' => $this->session->id
            ]
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cannot finalize session with CONFIGURATION_ERROR journals/');

        $this->service->finalize($this->session, $this->finalizerId);
    }

    public function test_duplicate_journal_blocks()
    {
        $payload = [
            'property_id' => $this->property->id,
            'source_type' => 'BankStatementLine',
            'source_id' => '123',
            'posting_event' => 'BANK_RECONCILIATION_VARIANCE',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
            'candidate_date' => now(),
            'description' => 'Test',
            'metadata' => [
                'reconciliation_session_id' => $this->session->id
            ]
        ];

        // Create duplicate
        JournalCandidate::create($payload);
        JournalCandidate::create($payload);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Duplicate journal candidates detected/');

        $this->service->finalize($this->session, $this->finalizerId);
    }

    public function test_freeze_protection()
    {
        $this->service->finalize($this->session, $this->finalizerId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Freeze Protection/');

        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => 'BSL_1',
            'matchable_type' => 'VendorPayment',
            'matchable_id' => 'VP_1',
            'amount_matched' => 100,
            'matchable_amount' => 100,
            'statement_amount' => 100,
            'bank_account_balance_before' => 0,
            'bank_account_balance_after' => 100,
            'match_method' => 'EXACT',
            'matched_by' => $this->makerId,
        ]);
    }
    
    public function test_freeze_protection_deleting()
    {
        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => 'BSL_1',
            'matchable_type' => 'VendorPayment',
            'matchable_id' => 'VP_1',
            'amount_matched' => 100,
            'matchable_amount' => 100,
            'statement_amount' => 100,
            'bank_account_balance_before' => 0,
            'bank_account_balance_after' => 100,
            'match_method' => 'EXACT',
            'matched_by' => $this->makerId,
        ]);

        $this->service->finalize($this->session, $this->finalizerId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Freeze Protection/');
        
        $match->delete();
    }
}
