<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Repositories\OperationalIdentityMappingRepository;

class OperationalIdentityMappingTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $property;
    protected $account;
    protected $department;
    protected $repository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->user = User::first();
        $this->property = Property::first();
        
        // Setup a dummy account if none exists
        $this->account = Account::first();
        if (!$this->account) {
            $this->account = Account::create([
                'property_id' => $this->property->id,
                'code' => '1000',
                'name' => 'Test Asset Account',
                'account_type' => 'Asset',
                'account_category' => 'CurrentAsset',
                'normal_balance' => 'Debit',
                'is_active' => true,
                'is_cash_equivalent' => false,
            ]);
        }
        
        $this->department = Department::first();
        if (!$this->department) {
            $this->department = Department::create([
                'property_id' => $this->property->id,
                'code' => 'TEST-01',
                'name' => 'Test Department',
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->user);
        
        $this->repository = new OperationalIdentityMappingRepository();
    }

    public function test_create_mapping()
    {
        $mapping = $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('gl_operational_identity_mappings', [
            'id' => $mapping->id,
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account->id,
            'cost_center_id' => null,
        ]);

        $this->assertEquals($this->account->id, $mapping->account->id);
    }

    public function test_nullable_cost_center()
    {
        // With cost center
        $mapping1 = $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::COST_OF_CONSUMPTION->value,
            'cost_center_id' => $this->department->id,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        // Without cost center
        $mapping2 = $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::COST_OF_CONSUMPTION->value,
            'cost_center_id' => null,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertEquals($this->department->id, $mapping1->cost_center_id);
        $this->assertNull($mapping2->cost_center_id);
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first() ?? Property::factory()->create();

        $mapping = $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertEquals($this->property->id, $mapping->property->id);
        $this->assertNotEquals($otherProperty->id, $mapping->property->id);
    }

    public function test_soft_delete()
    {
        $mapping = $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->repository->softDelete($mapping->id);

        $this->assertSoftDeleted('gl_operational_identity_mappings', [
            'id' => $mapping->id,
        ]);
    }

    public function test_find_active_mappings()
    {
        // Active
        $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        // Inactive
        $this->repository->create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'account_id' => $this->account->id,
            'effective_from' => now()->toDateString(),
            'is_active' => false,
        ]);

        $activeMappings = $this->repository->findActiveMappings($this->property->id);
        
        $this->assertCount(1, $activeMappings);
        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $activeMappings->first()->operational_identity);
    }
}
