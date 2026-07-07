<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class PropertyBaseCurrencyGovernanceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->user = User::first();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
    }

    public function test_property_creation_accepts_initial_currency(): void
    {
        $property = Property::create([
            'company_id' => $this->property->company_id,
            'name' => 'Currency Test Property',
            'slug' => 'currency-test-' . \Illuminate\Support\Str::random(4),
            'code' => 'CTP',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $this->assertEquals('EUR', $property->currency);
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'currency' => 'EUR']);
    }

    public function test_property_currency_cannot_change_through_supported_application_boundary(): void
    {
        $originalCurrency = $this->property->currency;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Property base currency is immutable and cannot be changed.');

        $this->property->update(['currency' => 'EUR']);
    }

    public function test_property_currency_cannot_change_through_direct_postgresql_update(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('properties')
            ->where('id', $this->property->id)
            ->update(['currency' => 'JPY']);
    }

    public function test_property_non_currency_update_remains_allowed_when_existing_policy_allows(): void
    {
        $originalName = $this->property->name;
        $newName = 'Updated Property Name';

        $this->property->update(['name' => $newName]);
        $this->property->refresh();

        $this->assertEquals($newName, $this->property->name);
        $this->assertNotEquals($originalName, $this->property->name);
    }

    public function test_property_non_currency_fields_remain_updateable(): void
    {
        $this->property->update(['timezone' => 'Asia/Tokyo']);
        $this->property->refresh();
        $this->assertEquals('Asia/Tokyo', $this->property->timezone);

        $this->property->update(['address' => '123 Test St']);
        $this->property->refresh();
        $this->assertEquals('123 Test St', $this->property->address);
    }

    public function test_currency_change_attempt_does_not_mutate_inventory_purchasing_or_finance(): void
    {
        $itemBefore = DB::table('inventory_items')->count();
        $movementsBefore = DB::table('inventory_stock_movements')->count();
        $poBefore = DB::table('purchase_orders')->count();

        try {
            DB::transaction(function () {
                DB::table('properties')
                    ->where('id', $this->property->id)
                    ->update(['currency' => 'EUR']);
            });
            $this->fail('Expected query exception for currency change.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $itemAfter = DB::table('inventory_items')->count();
        $movementsAfter = DB::table('inventory_stock_movements')->count();
        $poAfter = DB::table('purchase_orders')->count();

        $this->assertEquals($itemBefore, $itemAfter);
        $this->assertEquals($movementsBefore, $movementsAfter);
        $this->assertEquals($poBefore, $poAfter);
    }
}
