<?php

namespace Tests\Postgres\Finance\CostControl\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

trait CostDeliveryCutoverFixture
{
    /** @return array{CostDeliveryCutoverRequest,string,string} request, ownership id, actor id */
    protected function makeCutoverFixture(): array
    {
        $property = Property::where('currency', 'USD')->firstOrFail();
        $requester = User::firstOrFail();
        $approver = User::whereKeyNot($requester->id)->firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $property->id, 'name' => 'P01F Race '.Str::random(6),
        ]);
        $item = InventoryItem::create([
            'property_id' => $property->id, 'category_id' => $category->id,
            'sku' => 'P01F-C-'.Str::random(8), 'name' => 'P01F Concurrency',
            'inventory_type' => 'goods', 'weighted_average_cost' => 0, 'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'property_id' => $property->id, 'name' => 'P01F Race '.Str::random(8), 'type' => 'internal',
        ]);
        $prior = FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Closed, 'closed_at' => now(), 'closed_by' => $approver->id],
        );
        $target = FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 9],
            ['status' => FinancialPeriodStatusEnum::Open, 'opened_at' => now(), 'opened_by' => $requester->id],
        );
        DB::table('property_business_dates')->where('property_id', $property->id)->update([
            'status' => 'Closed', 'is_open' => null, 'closed_at' => now(), 'closed_by' => $approver->id,
        ]);
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $property->id, 'business_date' => '2026-09-01'],
            ['timezone_snapshot' => 'UTC', 'status' => 'Open', 'is_open' => true,
                'opened_by' => $requester->id, 'opened_at' => now(), 'closed_by' => null, 'closed_at' => null],
        );
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $group = $repository->createDraft(
            ['property_id' => $property->id, 'item_id' => $item->id],
            [[
                'location_id' => $location->id,
                'valuation_scope' => "property:{$property->id}:location:{$location->id}:item:{$item->id}",
                'opening_quantity' => '0.0000', 'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD', 'business_date' => '2026-08-31',
                'financial_period_id' => $prior->id, 'source_reference' => 'P01F-RACE',
                'evidence_timestamp' => now(),
            ]],
        );
        DB::transaction(fn () => $repository->approve($group->id, $approver->id, now()));
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $requester->id);
        $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $requester->id);
        CostDeliveryPilotProperty::create([
            'pilot_slot' => 1, 'property_id' => $property->id,
            'owner_approval_reference' => 'OWNER-P01F-RACE', 'authorized_by' => $approver->id,
            'authorized_at' => now(),
        ]);

        return [new CostDeliveryCutoverRequest(
            requestId: (string) Str::ulid(), propertyId: $property->id, itemId: $item->id,
            enrollmentGroupId: $group->id, targetFinancialPeriodId: $target->id,
            boundaryBusinessDate: '2026-09-01', requestedBy: $requester->id,
            approvedBy: $approver->id, ownerApprovalReference: 'OWNER-P01F-RACE',
        ), $ownership->id, $requester->id];
    }
}
