<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Services\PeriodControlService;
use Modules\Finance\GeneralLedger\Services\SubledgerPostingService;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException;
use Modules\Finance\Banking\Services\BankStatementService;

class PeriodControlTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected PeriodControlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        $this->service = app(PeriodControlService::class);
        Cache::flush();
    }

    public function test_missing_period_auto_created()
    {
        $this->assertTrue($this->service->isOpen($this->propertyId, 2026, 6));

        $this->assertDatabaseHas('gl_financial_periods', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open->value,
        ]);
    }

    public function test_period_status_cache_invalidation()
    {
        $this->service->isOpen($this->propertyId, 2026, 6);
        $userId = (string) Str::ulid();
        
        $this->service->close($this->propertyId, 2026, 6, $userId);
        
        $this->assertFalse($this->service->isOpen($this->propertyId, 2026, 6));
    }

    public function test_only_latest_closed_period_can_reopen()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 5);
        $this->service->isOpen($this->propertyId, 2026, 6);

        $this->service->close($this->propertyId, 2026, 5, $userId);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        // Attempt to reopen May (not latest)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only the most recently closed period can be reopened.');

        $this->service->reopen($this->propertyId, 2026, 5, $userId, 'Mistake');
    }

    public function test_reopen_requires_reason()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 6);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reopen requires a reason.');

        $this->service->reopen($this->propertyId, 2026, 6, $userId, '   ');
    }

    public function test_closing_records_snapshot_timestamp()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 6);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $period = FinancialPeriod::where('property_id', $this->propertyId)->first();
        $this->assertNotNull($period->closing_snapshot_at);
        $this->assertNotNull($period->closed_at);
        $this->assertEquals($userId, $period->closed_by);
    }

    public function test_closed_period_blocks_gl_posting()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 6);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $glService = app(GeneralLedgerService::class);

        $journal = JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-06-15',
            'reference' => 'TEST',
            'description' => 'TEST',
            'status' => JournalStatusEnum::Draft,
            'source_module' => 'Manual',
            'source_type' => 'Manual',
            'source_id' => 'Manual',
        ]);

        $this->expectException(PeriodClosedException::class);
        $glService->postJournalEntry($journal->id);
    }

    public function test_closed_period_blocks_ap_posting()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 6);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $postingService = app(SubledgerPostingService::class);

        $this->expectException(PeriodClosedException::class);
        $postingService->postAccountPayable(
            $this->propertyId,
            (string) Str::ulid(),
            100.0,
            '2026-06-15',
            'REF',
            'DESC'
        );
    }

    public function test_closed_period_blocks_banking_changes()
    {
        $userId = (string) Str::ulid();
        $this->service->isOpen($this->propertyId, 2026, 6);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $bankService = app(BankStatementService::class);

        $this->expectException(PeriodClosedException::class);
        $bankService->create([
            'property_id' => $this->propertyId,
            'bank_account_id' => (string) Str::ulid(),
            'statement_date' => '2026-06-15',
            'opening_balance' => 0.0,
            'imported_closing_balance' => 0.0,
        ]);
    }
}
