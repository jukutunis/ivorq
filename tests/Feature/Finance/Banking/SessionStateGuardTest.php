<?php

namespace Tests\Feature\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Banking\Services\SessionStateGuard;
use Modules\Foundation\Property\Models\Property;
use Illuminate\Support\Str;

class SessionStateGuardTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private SessionStateGuard $guard;
    private $property;
    private BankAccount $bankAccount;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->guard = new SessionStateGuard();
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        app(\Shared\Services\CurrentPropertyService::class)->setId($this->property->id);
        $this->bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'current_balance' => 1000.00
        ]);
        $this->userId = (string) Str::ulid();
    }

    public function test_backdated_protection_throws_exception()
    {
        // Create completed session for Feb
        ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Completed,
            'statement_date_start' => '2023-02-01',
            'statement_date_end' => '2023-02-28',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Backdated Protection");

        // Try to create Jan session
        $this->guard->validateCreationDate(
            $this->property->id,
            $this->bankAccount->id,
            '2023-01-01'
        );
    }

    public function test_valid_state_transitions()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'status' => ReconciliationSessionStatusEnum::Open,
            'created_by' => $this->userId,
        ]);

        $this->guard->transitionTo($session, ReconciliationSessionStatusEnum::InProgress, $this->userId);
        $this->assertEquals(ReconciliationSessionStatusEnum::InProgress, $session->fresh()->status);

        $this->guard->transitionTo($session, ReconciliationSessionStatusEnum::Review, $this->userId);
        $this->assertEquals(ReconciliationSessionStatusEnum::Review, $session->fresh()->status);

        $reviewerId = (string) Str::ulid();
        $this->guard->transitionTo($session, ReconciliationSessionStatusEnum::Completed, $reviewerId);
        $this->assertEquals(ReconciliationSessionStatusEnum::Completed, $session->fresh()->status);
        $this->assertEquals($reviewerId, $session->fresh()->reviewed_by);
        $this->assertNotNull($session->fresh()->reviewed_at);
    }

    public function test_maker_checker_violation()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'status' => ReconciliationSessionStatusEnum::Review,
            'created_by' => $this->userId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Maker Checker Violation");

        // Creator tries to review their own session
        $this->guard->transitionTo($session, ReconciliationSessionStatusEnum::Completed, $this->userId);
    }

    public function test_illegal_state_transition()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'status' => ReconciliationSessionStatusEnum::Open,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Illegal Transition");

        // Open -> Review directly
        $this->guard->transitionTo($session, ReconciliationSessionStatusEnum::Review, $this->userId);
    }
}
