<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Carbon\Carbon;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingPlan;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryLocation;

class CostAvcoStatePersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private CostAvcoStateRepository $repo;
    private Property $property;
    private InventoryLocation $location1;
    private InventoryLocation $location2;
    private string $itemId;
    private string $valuationScope;
    private string $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo     = new CostAvcoStateRepository();
        $this->property = Property::first();

        $this->location1 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'AVCO State Loc A',
            'type'        => 'internal',
        ]);

        $this->location2 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'AVCO State Loc B',
            'type'        => 'internal',
        ]);

        $this->itemId         = (string) Str::ulid();
        $this->businessDate   = Carbon::today()->toDateString();
        $this->valuationScope = "property:{$this->property->id}:location:{$this->location1->id}:item:{$this->itemId}";
    }

    // -------------------------------------------------------------------------
    // Case 1 — Safe initial bootstrap
    // -------------------------------------------------------------------------

    public function test_safe_initial_bootstrap_creates_zero_state(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );

            // One row must exist
            $this->assertDatabaseCount('cost_avco_states', 1);

            // Scope identity
            $this->assertEquals($this->property->id, $row->property_id);
            $this->assertEquals($this->location1->id, $row->location_id);
            $this->assertEquals($this->itemId, $row->item_id);
            $this->assertEquals($this->valuationScope, $row->valuation_scope);

            // Initial numeric state — all zero / null
            $this->assertEquals('0.0000', $row->on_hand_quantity);
            $this->assertEquals('0.0000', $row->carrying_value);
            $this->assertNull($row->weighted_average_unit_cost);
            $this->assertNull($row->last_valuation_sequence);
            $this->assertNull($row->last_valuation_business_date);
            $this->assertEquals('0.0000', $row->unresolved_provisional_quantity);
        });
    }

    // -------------------------------------------------------------------------
    // Case 2 — Same scope reuses one row
    // -------------------------------------------------------------------------

    public function test_same_scope_reuses_single_row_across_separate_transactions(): void
    {
        // First transaction: bootstrap — creates the row
        DB::transaction(function () {
            $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );
        });

        $this->assertDatabaseCount('cost_avco_states', 1);
        $idAfterFirst = CostAvcoState::first()->id;

        // Second transaction: bootstrap again for the same scope
        DB::transaction(function () use ($idAfterFirst) {
            $row = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );

            // Still only one row — insertOrIgnore did not duplicate
            $this->assertDatabaseCount('cost_avco_states', 1);

            // Must be the same row (id unchanged)
            $this->assertEquals($idAfterFirst, $row->id);
        });

        // Final count assertion outside transactions
        $this->assertDatabaseCount('cost_avco_states', 1);
    }

    // -------------------------------------------------------------------------
    // Case 3 — Distinct scope is independent
    // -------------------------------------------------------------------------

    public function test_distinct_scope_creates_independent_row(): void
    {
        $scope2 = "property:{$this->property->id}:location:{$this->location2->id}:item:{$this->itemId}";

        // Bootstrap scope 1
        DB::transaction(function () {
            $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );
        });

        // Bootstrap scope 2 (different location, different scope)
        DB::transaction(function () use ($scope2) {
            $row2 = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location2->id,
                $this->itemId,
                $scope2
            );

            // Second row exists and has its own identity
            $this->assertEquals($this->location2->id, $row2->location_id);
            $this->assertEquals($scope2, $row2->valuation_scope);
        });

        // Two independent rows must exist
        $this->assertDatabaseCount('cost_avco_states', 2);

        $row1 = CostAvcoState::where('location_id', $this->location1->id)->first();
        $row2 = CostAvcoState::where('location_id', $this->location2->id)->first();

        // Each scope has its own zero initial state and is independent
        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        $this->assertNotEquals($row1->id, $row2->id);

        // Row 1 has not been mutated by row 2 bootstrap
        $this->assertEquals('0.0000', $row1->on_hand_quantity);
        $this->assertNull($row1->last_valuation_sequence);
    }

    // -------------------------------------------------------------------------
    // Case 4 — Allow plan persists resulting state
    // -------------------------------------------------------------------------

    public function test_allow_plan_persists_resulting_state_fields_exactly(): void
    {
        DB::transaction(function () {
            // Bootstrap and lock
            $lockedRow   = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );
            $priorState  = $this->repo->toValuationState($lockedRow);

            // Build a resulting AvcoValuationState that represents a first receipt:
            // qty=5.0000, carrying=60.0000, wauc=12.0000, provisional=0
            $newSeq = new ValuationSequence(
                propertyId:     $this->property->id,
                itemId:         $this->itemId,
                valuationScope: $this->valuationScope,
                businessDate:   $this->businessDate,
                ledgerSequence: 1,
            );
            $newState = new AvcoValuationState(
                propertyId:                    $this->property->id,
                itemId:                        $this->itemId,
                valuationScope:                $this->valuationScope,
                onHandQuantity:                new AvcoDecimal('5.0000'),
                weightedAverageUnitCost:       new AvcoDecimal('12.0000'),
                carryingValue:                 new AvcoDecimal('60.0000'),
                lastAppliedSequence:           $newSeq,
                unresolvedProvisionalQuantity: new AvcoDecimal('0.0000'),
            );

            // Build a minimal allow-path valuation result wrapping newState
            $valuationResult = new AvcoValuationResult(
                status:                   AvcoValuationResult::STATUS_FINAL,
                reasonCode:               'RECEIPT_AVCO_CALCULATED',
                newState:                 $newState,
                signedCarryingValueDelta: new AvcoDecimal('60.0000'),
            );

            // Build allow decision
            $decision = new CostLedgerPostingDecision(
                status:                    CostLedgerPostingDecision::STATUS_ALLOW,
                reasonCode:                'ELIGIBLE',
                historicalStateUnchanged:  false,
            );

            // Construct allow plan — intent is null only for neutral-transfer;
            // for a receipt we require an intent. Build a minimal one using
            // the AvcoValuationResult constructor. Since we test repository
            // persistence only, we build the plan using the no-intent neutral
            // transfer allow path, which does not require CostLedgerEntryIntent
            // or CostLedgerAppendService.
            // The simplest valid allow plan without intent is
            // SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL.  We use that path here
            // to avoid importing CostLedgerEntryIntent (out of this slice's scope).
            $neutralDecision = new CostLedgerPostingDecision(
                status:                   CostLedgerPostingDecision::STATUS_ALLOW,
                reasonCode:               'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL',
                historicalStateUnchanged: false,
            );
            $neutralResult = new AvcoValuationResult(
                status:                   AvcoValuationResult::STATUS_FINAL,
                reasonCode:               'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL',
                newState:                 $newState,
                signedCarryingValueDelta: new AvcoDecimal('0.0000'),
            );
            $plan = new CostLedgerPostingPlan(
                decision:                  $neutralDecision,
                priorState:                $priorState,
                resultingState:            $neutralResult->newState,
                valuationResult:           $neutralResult,
                intent:                    null,
                sourceTransactionReference: (string) Str::ulid(),
                idempotencyKey:            'idem-avco-allow-' . Str::uuid(),
            );

            // Persist via repository — this is the only database write
            $this->repo->persistAllowedResultingState($lockedRow, $plan);

            // Reload fresh from DB
            $persisted = CostAvcoState::where('property_id', $this->property->id)
                ->where('location_id', $this->location1->id)
                ->where('item_id', $this->itemId)
                ->first();

            $this->assertNotNull($persisted);

            // All resulting state fields must be persisted exactly
            $this->assertEquals('5.0000', $persisted->on_hand_quantity);
            $this->assertEquals('60.0000', $persisted->carrying_value);
            $this->assertEquals('12.0000', $persisted->weighted_average_unit_cost);
            $this->assertEquals(1, $persisted->last_valuation_sequence);
            $this->assertEquals($this->businessDate, $persisted->last_valuation_business_date->format('Y-m-d'));
            $this->assertEquals('0.0000', $persisted->unresolved_provisional_quantity);
        });
    }

    // -------------------------------------------------------------------------
    // Case 5 — Non-allow plans cannot mutate state
    // -------------------------------------------------------------------------

    public function test_pending_plan_throws_and_leaves_state_unchanged(): void
    {
        DB::transaction(function () {
            $lockedRow  = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );
            $priorState = $this->repo->toValuationState($lockedRow);

            $pendingDecision = new CostLedgerPostingDecision(
                status:                   CostLedgerPostingDecision::STATUS_PENDING,
                reasonCode:               'PROVISIONAL_VALUATION_REQUIRES_RESOLUTION',
                historicalStateUnchanged: true,
            );
            $plan = new CostLedgerPostingPlan(
                decision:                   $pendingDecision,
                priorState:                 $priorState,
                resultingState:             $priorState,   // must be same instance for non-allow
                valuationResult:            null,
                intent:                     null,
                sourceTransactionReference: (string) Str::ulid(),
                idempotencyKey:             'idem-avco-pending-' . Str::uuid(),
            );

            $thrown = false;
            try {
                $this->repo->persistAllowedResultingState($lockedRow, $plan);
            } catch (InvalidArgumentException $e) {
                $thrown = true;
                $this->assertStringContainsString('pending', $e->getMessage());
            }

            $this->assertTrue($thrown, 'Expected InvalidArgumentException for pending plan.');

            // State must remain at initial zero values
            $persisted = CostAvcoState::where('property_id', $this->property->id)
                ->where('location_id', $this->location1->id)
                ->where('item_id', $this->itemId)
                ->first();

            $this->assertNotNull($persisted);
            $this->assertEquals('0.0000', $persisted->on_hand_quantity);
            $this->assertEquals('0.0000', $persisted->carrying_value);
            $this->assertNull($persisted->weighted_average_unit_cost);
            $this->assertNull($persisted->last_valuation_sequence);
        });
    }

    public function test_rejected_plan_throws_and_leaves_state_unchanged(): void
    {
        DB::transaction(function () {
            $lockedRow  = $this->repo->bootstrapAndLock(
                $this->property->id,
                $this->location1->id,
                $this->itemId,
                $this->valuationScope
            );
            $priorState = $this->repo->toValuationState($lockedRow);

            $rejectedDecision = new CostLedgerPostingDecision(
                status:                   CostLedgerPostingDecision::STATUS_REJECTED,
                reasonCode:               'UNSUPPORTED_AVCO_CORRECTION_CONTEXT',
                historicalStateUnchanged: true,
            );
            $plan = new CostLedgerPostingPlan(
                decision:                   $rejectedDecision,
                priorState:                 $priorState,
                resultingState:             $priorState,  // same instance for non-allow
                valuationResult:            null,
                intent:                     null,
                sourceTransactionReference: (string) Str::ulid(),
                idempotencyKey:             'idem-avco-rejected-' . Str::uuid(),
            );

            $thrown = false;
            try {
                $this->repo->persistAllowedResultingState($lockedRow, $plan);
            } catch (InvalidArgumentException $e) {
                $thrown = true;
                $this->assertStringContainsString('rejected', $e->getMessage());
            }

            $this->assertTrue($thrown, 'Expected InvalidArgumentException for rejected plan.');

            // State must remain at initial zero values
            $persisted = CostAvcoState::where('property_id', $this->property->id)
                ->where('location_id', $this->location1->id)
                ->where('item_id', $this->itemId)
                ->first();

            $this->assertNotNull($persisted);
            $this->assertEquals('0.0000', $persisted->on_hand_quantity);
            $this->assertNull($persisted->last_valuation_sequence);

            // No Cost Ledger entries created in this slice
            $this->assertDatabaseCount('cost_ledger_entries', 0);
        });
    }
}
