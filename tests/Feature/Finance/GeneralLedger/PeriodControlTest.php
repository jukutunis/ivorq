<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Database\Factories\PropertyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Services\BankStatementService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Modules\Finance\GeneralLedger\Services\PeriodControlService;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Tests\TestCase;

class PeriodControlTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;

    protected PeriodControlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = PropertyFactory::new()->create()->id;
        $this->service = app(PeriodControlService::class);
        Cache::flush();
    }

    public function test_missing_period_fails_closed_without_auto_creation()
    {
        $this->assertFalse($this->service->isOpen($this->propertyId, 2026, 6));

        $this->assertDatabaseMissing('gl_financial_periods', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
        ]);
    }

    public function test_period_status_cache_invalidation()
    {
        FinancialPeriod::create([
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);
        $userId = (string) Str::ulid();

        $this->service->close($this->propertyId, 2026, 6, $userId);
        $this->assertFalse($this->service->isOpen($this->propertyId, 2026, 6));
    }

    public function test_only_latest_closed_period_can_reopen()
    {
        $userId = (string) Str::ulid();
        foreach ([5, 6] as $month) {
            FinancialPeriod::create([
                'property_id' => $this->propertyId,
                'period_year' => 2026,
                'period_month' => $month,
                'status' => FinancialPeriodStatusEnum::Open,
            ]);
        }

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
        FinancialPeriod::create([
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reopen requires a reason.');

        $this->service->reopen($this->propertyId, 2026, 6, $userId, '   ');
    }

    public function test_closing_records_snapshot_timestamp()
    {
        $userId = (string) Str::ulid();
        FinancialPeriod::create([
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);
        $this->service->close($this->propertyId, 2026, 6, $userId);

        $period = FinancialPeriod::where('property_id', $this->propertyId)->first();
        $this->assertNotNull($period->closing_snapshot_at);
        $this->assertNotNull($period->closed_at);
        $this->assertEquals($userId, $period->closed_by);
    }

    public function test_closed_period_blocks_gl_posting()
    {
        $userId = (string) Str::ulid();
        FinancialPeriod::create([
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);
        $this->service->close($this->propertyId, 2026, 6, $userId);
        PropertyBusinessDate::factory()->create([
            'property_id' => $this->propertyId,
            'business_date' => '2026-06-15',
        ]);

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

    public function test_subledger_defers_period_authority_to_general_ledger_service()
    {
        $source = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/SubledgerPostingService.php'));
        $ledger = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/GeneralLedgerService.php'));

        $this->assertStringNotContainsString('PeriodControlService', $source);
        $this->assertStringContainsString('FinancialPeriod::where', $ledger);
        $this->assertStringContainsString('Auto-creation is not permitted', $ledger);
    }

    public function test_closed_period_blocks_banking_changes()
    {
        $userId = (string) Str::ulid();
        FinancialPeriod::create([
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);
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
