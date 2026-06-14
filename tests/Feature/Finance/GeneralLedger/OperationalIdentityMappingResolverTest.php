<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;

class OperationalIdentityMappingResolverTest extends TestCase
{
    use RefreshDatabase;

    protected $property;
    protected $account1;
    protected $account2;
    protected $department;
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->property = Property::first();
        
        $this->account1 = Account::first();
        if (!$this->account1) {
            $this->account1 = Account::create([
                'property_id' => $this->property->id,
                'code' => '1000',
                'name' => 'Account 1',
                'account_type' => 'Asset',
                'account_category' => 'CurrentAsset',
                'normal_balance' => 'Debit',
                'is_active' => true,
                'is_cash_equivalent' => false,
            ]);
        }

        $this->account2 = Account::create([
            'property_id' => $this->property->id,
            'code' => '2000',
            'name' => 'Account 2',
            'account_type' => 'Expense',
            'account_category' => 'Expense',
            'normal_balance' => 'Debit',
            'is_active' => true,
            'is_cash_equivalent' => false,
        ]);
        
        $this->department = Department::first();
        if (!$this->department) {
            $this->department = Department::create([
                'property_id' => $this->property->id,
                'code' => 'DEPT-1',
                'name' => 'Test Department',
                'is_active' => true,
            ]);
        }

        $this->actingAs(User::first());
        
        $this->service = app(OperationalIdentityMappingService::class);
    }

    public function test_exact_match()
    {
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::COST_OF_CONSUMPTION->value,
            'cost_center_id' => $this->department->id,
            'account_id' => $this->account1->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $resolved = $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::COST_OF_CONSUMPTION,
            Carbon::parse('2026-06-15'),
            $this->department->id
        );

        $this->assertEquals($this->account1->id, $resolved->account_id);
        $this->assertEquals($this->department->id, $resolved->cost_center_id);
    }

    public function test_fallback_match()
    {
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::COST_OF_CONSUMPTION->value,
            'cost_center_id' => null, // Generic fallback
            'account_id' => $this->account2->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        // Requesting with a specific cost center, but only fallback exists
        $resolved = $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::COST_OF_CONSUMPTION,
            Carbon::parse('2026-06-15'),
            $this->department->id
        );

        $this->assertEquals($this->account2->id, $resolved->account_id);
        $this->assertNull($resolved->cost_center_id);
    }

    public function test_effective_date_resolution()
    {
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'cost_center_id' => null,
            'account_id' => $this->account1->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-05-31',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'cost_center_id' => null,
            'account_id' => $this->account2->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'is_active' => true,
        ]);

        $resolvedOld = $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2026-03-15')
        );

        $this->assertEquals($this->account1->id, $resolvedOld->account_id);

        $resolvedNew = $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2026-06-15')
        );

        $this->assertEquals($this->account2->id, $resolvedNew->account_id);

        // Before first effective date -> Exception
        $this->expectException(OperationalIdentityMappingNotFoundException::class);
        $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2025-12-31')
        );
    }

    public function test_inactive_mapping_ignored()
    {
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account1->id,
            'effective_from' => '2026-01-01',
            'is_active' => false,
        ]);

        $this->expectException(OperationalIdentityMappingNotFoundException::class);
        $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2026-06-15')
        );
    }

    public function test_exception_thrown_when_not_found()
    {
        $this->expectException(OperationalIdentityMappingNotFoundException::class);
        $this->service->resolve(
            $this->property->id,
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2026-06-15')
        );
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first() ?? Property::factory()->create();

        OperationalIdentityMapping::create([
            'property_id' => $otherProperty->id, // Mapped to other property
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account1->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->expectException(OperationalIdentityMappingNotFoundException::class);
        $this->service->resolve(
            $this->property->id, // Trying to resolve for primary property
            OperationalIdentityEnum::INVENTORY,
            Carbon::parse('2026-06-15')
        );
    }
}
