<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Services\InventoryReversalCandidateGuard;
use Modules\Operations\Inventory\Exceptions\InventoryReversalCandidateRejectedException;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

class InventoryReversalCandidateGuardTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryReversalCandidateGuard $guard;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(InventoryReversalCandidateGuard::class);
        $this->property = Property::first();
        $this->user = User::first();
        $this->actingAs($this->user);

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'General',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $invCategory->id,
            'sku'                   => 'ITM-REV-001',
            'name'                  => 'Reversal Test Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Reversal Store',
            'type'        => 'internal',
        ]);
    }

    private function createValidTransaction(TransactionTypeEnum $type): InventoryTransaction
    {
        $tx = new InventoryTransaction();
        $tx->id = (string) Str::ulid();
        $tx->property_id = $this->property->id;
        $tx->item_id = $this->item->id;
        $tx->location_id = $this->location->id;
        $tx->transaction_type = $type;
        $tx->quantity_before = '10.0000';
        $tx->quantity_change = '5.0000';
        $tx->quantity_after = '15.0000';
        $tx->unit_cost = '10.0000';
        $tx->total_cost = '50.0000';
        $tx->posted_at = now();
        $tx->business_date = now()->toDateString();
        $tx->occurred_at = now();
        $tx->currency_code = 'USD';
        $tx->financial_period_id = '2026-06';
        $tx->valuation_scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        $tx->valuation_sequence = 1;
        $tx->save();

        return $tx;
    }

    public function test_eligible_purchase_receipt_is_accepted_in_transaction(): void
    {
        $tx = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);

        DB::transaction(function () use ($tx) {
            $result = $this->guard->guard($tx->id);
            $this->assertEquals($tx->id, $result->id);
        });
    }

    public function test_eligible_issue_is_accepted_in_transaction(): void
    {
        $tx = $this->createValidTransaction(TransactionTypeEnum::Issue);

        DB::transaction(function () use ($tx) {
            $result = $this->guard->guard($tx->id);
            $this->assertEquals($tx->id, $result->id);
        });
    }

    public function test_missing_candidate_fails_with_candidate_not_found(): void
    {
        $this->expectException(InventoryReversalCandidateRejectedException::class);
        $this->expectExceptionMessage('Candidate original transaction not found.');

        DB::transaction(function () {
            try {
                $this->guard->guard((string) Str::ulid());
            } catch (InventoryReversalCandidateRejectedException $e) {
                $this->assertEquals('candidate_not_found', $e->getReason());
                throw $e;
            }
        });
    }

    public function test_candidate_is_already_a_reversal_fails(): void
    {
        $original = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);
        $reversal = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);
        $reversal->reverses_inventory_transaction_id = $original->id;
        $reversal->save();

        $this->expectException(InventoryReversalCandidateRejectedException::class);
        $this->expectExceptionMessage('Candidate transaction is itself already a reversal.');

        DB::transaction(function () use ($reversal) {
            try {
                $this->guard->guard($reversal->id);
            } catch (InventoryReversalCandidateRejectedException $e) {
                $this->assertEquals('candidate_is_already_a_reversal', $e->getReason());
                throw $e;
            }
        });
    }

    public function test_candidate_already_has_reversal_fails(): void
    {
        $original = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);
        $reversal = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);
        $reversal->reverses_inventory_transaction_id = $original->id;
        $reversal->save();

        $this->expectException(InventoryReversalCandidateRejectedException::class);
        $this->expectExceptionMessage('An existing reversal already references the candidate.');

        DB::transaction(function () use ($original) {
            try {
                $this->guard->guard($original->id);
            } catch (InventoryReversalCandidateRejectedException $e) {
                $this->assertEquals('candidate_already_has_reversal', $e->getReason());
                throw $e;
            }
        });
    }

    public function test_ineligible_types_fail(): void
    {
        $ineligibleTypes = [
            TransactionTypeEnum::AdjustmentIn,
            TransactionTypeEnum::AdjustmentOut,
            TransactionTypeEnum::TransferIn,
            TransactionTypeEnum::TransferOut,
            TransactionTypeEnum::OpeningBalance,
            TransactionTypeEnum::Return,
        ];

        foreach ($ineligibleTypes as $type) {
            $tx = $this->createValidTransaction($type);

            $failed = false;
            try {
                DB::transaction(function () use ($tx) {
                    $this->guard->guard($tx->id);
                });
            } catch (InventoryReversalCandidateRejectedException $e) {
                $this->assertEquals('candidate_type_not_eligible', $e->getReason());
                $failed = true;
            }

            $this->assertTrue($failed, "Transaction of type {$type->value} should have failed validation.");
        }
    }

    public function test_missing_controlled_evidence_fails(): void
    {
        $requiredFields = [
            'property_id',
            'location_id',
            'item_id',
            'valuation_scope',
            'valuation_sequence',
        ];

        foreach ($requiredFields as $field) {
            $tx = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);

            if ($field === 'valuation_sequence') {
                $tx->valuation_sequence = null;
            } else {
                $tx->$field = null;
            }
            $tx->save();

            $failed = false;
            try {
                DB::transaction(function () use ($tx) {
                    $this->guard->guard($tx->id);
                });
            } catch (InventoryReversalCandidateRejectedException $e) {
                $this->assertEquals('candidate_missing_controlled_evidence', $e->getReason());
                $failed = true;
            }

            $this->assertTrue($failed, "Transaction with missing {$field} should have failed validation.");
        }
    }

    public function test_invocation_outside_transaction_fails(): void
    {
        $tx = $this->createValidTransaction(TransactionTypeEnum::PurchaseReceipt);

        $this->expectException(InventoryReversalCandidateRejectedException::class);
        $this->expectExceptionMessage('No active outer database transaction.');

        try {
            $this->guard->guard($tx->id);
        } catch (InventoryReversalCandidateRejectedException $e) {
            $this->assertEquals('missing_outer_transaction', $e->getReason());
            throw $e;
        }
    }
}
