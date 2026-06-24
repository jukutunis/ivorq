<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Services\PeriodControlService;
use Modules\Foundation\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\PostgresTestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;

class PeriodControlServiceLockCompatibilityTest extends PostgresTestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private function createFixture(): array
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser($property);

        return [$property, $user];
    }

    public function test_it_locks_and_closes_financial_period()
    {
        [$property, $user] = $this->createFixture();

        $period = FinancialPeriod::create([
            'property_id' => $property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);

        $service = new PeriodControlService();
        $service->close($property->id, 2026, 6, $user->id);

        $period->refresh();
        $this->assertEquals(FinancialPeriodStatusEnum::Closed, $period->status);
        $this->assertEquals($user->id, $period->closed_by);
        $this->assertNotNull($period->closed_at);
        $this->assertNotNull($period->closing_snapshot_at);
    }

    public function test_it_locks_and_reopens_financial_period()
    {
        [$property, $user] = $this->createFixture();

        $period = FinancialPeriod::create([
            'property_id' => $property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => FinancialPeriodStatusEnum::Closed,
        ]);

        $service = new PeriodControlService();
        $service->reopen($property->id, 2026, 6, $user->id, "Correction needed");

        $period->refresh();
        $this->assertEquals(FinancialPeriodStatusEnum::Reopened, $period->status);
        $this->assertEquals($user->id, $period->opened_by);
        $this->assertNotNull($period->opened_at);
        $this->assertNull($period->closed_at);
        $this->assertNull($period->closed_by);
    }
}
