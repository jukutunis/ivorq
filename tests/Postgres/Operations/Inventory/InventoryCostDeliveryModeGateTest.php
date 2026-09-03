<?php

namespace Tests\Postgres\Operations\Inventory;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryValuationSequence;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class InventoryCostDeliveryModeGateTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $location;

    private InventoryStock $stock;

    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $this->actingAs($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_at' => now(),
                'opened_by' => $this->actor->id,
            ],
        );

        $this->period = FinancialPeriod::updateOrCreate(
            [
                'property_id' => $this->property->id,
                'period_year' => now()->year,
                'period_month' => now()->month,
            ],
            [
                'status' => FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth(),
            ],
        );

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A A4 Gate',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01A-A4-'.Str::random(10),
            'name' => 'CC-P01A A4 Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A A4 Location '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->stock = InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '100.0000',
            'status' => ItemStatusEnum::InStock,
        ]);
    }

    public function test_new_not_enrolled_posting_succeeds_with_all_source_stamp_columns_null(): void
    {
        $transaction = app(InventoryPostingControlCoordinator::class)->post(
            $this->makeIntent('a4-not-enrolled'),
            $this->actor->id,
        );

        $this->assertNull($transaction->cost_delivery_mode);
        $this->assertNull($transaction->cost_delivery_ownership_id);
        $this->assertNull($transaction->cost_delivery_ownership_version);
        $this->assertNull($transaction->cost_delivery_cutover_id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame('110.0000', $this->stock->fresh()->physical_quantity);
    }

    public function test_new_enrolled_posting_stamps_exact_server_resolved_synchronous_ownership(): void
    {
        $ownership = $this->activateEnrollment($this->location);

        $transaction = app(InventoryPostingControlCoordinator::class)->post(
            $this->makeIntent('a4-synchronous'),
            $this->actor->id,
        );

        $this->assertSame(CostDeliveryPostingDecision::SYNCHRONOUS, $transaction->cost_delivery_mode);
        $this->assertSame($ownership->id, $transaction->cost_delivery_ownership_id);
        $this->assertSame($ownership->ownership_version, $transaction->cost_delivery_ownership_version);
        $this->assertNull($transaction->cost_delivery_cutover_id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_enrolled_missing_ownership_fails_closed_without_posting_side_effects(): void
    {
        $group = $this->makeApprovedGroup($this->location);
        DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(InventoryPostingControlCoordinator::class)->post(
                $this->makeIntent('a4-missing-owner'),
                $this->actor->id,
            );
            $this->fail('An enrolled Property + Item without ownership must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ENROLLED_DELIVERY_OWNERSHIP_MISSING', $exception->getMessage());
        }

        $this->assertNoPostingSideEffects();
    }

    public function test_enrolled_missing_exact_location_scope_fails_closed_without_posting_side_effects(): void
    {
        $otherLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A A4 Other Location '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->activateEnrollment($otherLocation);

        try {
            app(InventoryPostingControlCoordinator::class)->post(
                $this->makeIntent('a4-missing-scope'),
                $this->actor->id,
            );
            $this->fail('An enrolled posting without its exact canonical scope must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ENROLLED_DELIVERY_SCOPE_MISSING', $exception->getMessage());
        }

        $this->assertNoPostingSideEffects();
    }

    public function test_exact_existing_null_stamped_source_returns_before_mode_resolution_and_side_effects(): void
    {
        $intent = $this->makeIntent('a4-existing-null');
        $original = $this->coordinatorWith($this->fakePort(
            fn (string $propertyId, string $itemId) => CostDeliveryPostingDecision::notEnrolled(
                $propertyId,
                $itemId,
            ),
        ))->post($intent, $this->actor->id);
        $quantityAfterOriginal = $this->stock->fresh()->physical_quantity;
        $sequenceAfterOriginal = InventoryValuationSequence::firstOrFail()->last_sequence;

        $rejectingPort = $this->fakePort(fn () => throw new RuntimeException('MODE_PORT_MUST_NOT_BE_CALLED'));
        $replayed = $this->coordinatorWith($rejectingPort)->post($intent, $this->actor->id);

        $this->assertSame($original->id, $replayed->id);
        $this->assertSame(0, $rejectingPort->invocations);
        $this->assertNull($replayed->cost_delivery_mode);
        $this->assertNull($replayed->cost_delivery_ownership_id);
        $this->assertNull($replayed->cost_delivery_ownership_version);
        $this->assertNull($replayed->cost_delivery_cutover_id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame($sequenceAfterOriginal, InventoryValuationSequence::firstOrFail()->last_sequence);
        $this->assertSame($quantityAfterOriginal, $this->stock->fresh()->physical_quantity);
    }

    public function test_exact_existing_synchronous_source_preserves_original_ownership_stamp(): void
    {
        $intent = $this->makeIntent('a4-existing-sync');
        $ownershipId = (string) Str::ulid();
        $original = $this->coordinatorWith($this->fakePort(
            fn (string $propertyId, string $itemId, string $locationId) => CostDeliveryPostingDecision::synchronous(
                $propertyId,
                $itemId,
                $locationId,
                $this->scope($locationId),
                $ownershipId,
                7,
            ),
        ))->post($intent, $this->actor->id);

        $rejectingPort = $this->fakePort(fn () => throw new RuntimeException('MODE_PORT_MUST_NOT_BE_CALLED'));
        $replayed = $this->coordinatorWith($rejectingPort)->post($intent, $this->actor->id);

        $this->assertSame($original->id, $replayed->id);
        $this->assertSame(0, $rejectingPort->invocations);
        $this->assertSame(CostDeliveryPostingDecision::SYNCHRONOUS, $replayed->cost_delivery_mode);
        $this->assertSame($ownershipId, $replayed->cost_delivery_ownership_id);
        $this->assertSame(7, $replayed->cost_delivery_ownership_version);
        $this->assertNull($replayed->cost_delivery_cutover_id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_mismatched_existing_idempotency_key_fails_collision_before_mode_resolution(): void
    {
        $intent = $this->makeIntent('a4-existing-collision');
        $this->coordinatorWith($this->fakePort(
            fn (string $propertyId, string $itemId) => CostDeliveryPostingDecision::notEnrolled(
                $propertyId,
                $itemId,
            ),
        ))->post($intent, $this->actor->id);
        $quantityAfterOriginal = $this->stock->fresh()->physical_quantity;

        $rejectingPort = $this->fakePort(fn () => throw new RuntimeException('MODE_PORT_MUST_NOT_BE_CALLED'));

        try {
            $this->coordinatorWith($rejectingPort)->post(
                $this->copyIntent($intent, quantityChange: '20.0000', totalCost: '100.0000'),
                $this->actor->id,
            );
            $this->fail('A mismatched existing idempotency key must fail collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Idempotency collision: same key with different intent.', $exception->getMessage());
        }

        $this->assertSame(0, $rejectingPort->invocations);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame($quantityAfterOriginal, $this->stock->fresh()->physical_quantity);
    }

    public function test_deferred_decision_stamps_source_and_outbox_without_cost_application(): void
    {
        $decision = CostDeliveryPostingDecision::deferred(
            $this->property->id,
            $this->item->id,
            $this->location->id,
            $this->scope($this->location->id),
            (string) Str::ulid(),
            2,
            (string) Str::ulid(),
            0,
            1,
        );

        $transaction = $this->coordinatorWith($this->fakePort(fn () => $decision))->post(
            $this->makeIntent('p01f-deferred-owned'),
            $this->actor->id,
        );

        $this->assertSame('DEFERRED', $transaction->cost_delivery_mode);
        $this->assertSame($decision->ownershipId, $transaction->cost_delivery_ownership_id);
        $this->assertSame($decision->ownershipVersion, $transaction->cost_delivery_ownership_version);
        $this->assertSame($decision->cutoverId, $transaction->cost_delivery_cutover_id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertSame('110.0000', $this->stock->fresh()->physical_quantity);
    }

    public function test_mode_resolution_occurs_inside_outer_transaction_before_context_stock_and_sequence_locks(): void
    {
        $port = $this->fakePort(function (string $propertyId, string $itemId): CostDeliveryPostingDecision {
            $this->assertGreaterThanOrEqual(1, DB::transactionLevel());
            $this->assertDatabaseCount('inventory_valuation_sequences', 0);

            return CostDeliveryPostingDecision::notEnrolled($propertyId, $itemId);
        });

        $this->coordinatorWith($port)->post($this->makeIntent('a4-lock-order'), $this->actor->id);

        $this->assertSame(1, $port->invocations);
        $this->assertCount(1, $port->transactionLevels);
        $this->assertGreaterThanOrEqual(1, $port->transactionLevels[0]);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);

        $source = file_get_contents(base_path(
            'Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php',
        ));
        $existingPosition = strpos($source, '$existing = InventoryTransaction::where');
        $modePosition = strpos($source, '$this->costDeliveryModePort->resolveForPosting');
        $contextPosition = strpos($source, '$this->lockContext');
        $stockPosition = strpos($source, '$this->stockRepo->createOrLockControlled');
        $sequencePosition = strpos($source, '$this->sequenceRepo->allocateNext');

        $this->assertIsInt($existingPosition);
        $this->assertIsInt($modePosition);
        $this->assertIsInt($contextPosition);
        $this->assertIsInt($stockPosition);
        $this->assertIsInt($sequencePosition);
        $this->assertLessThan($modePosition, $existingPosition);
        $this->assertLessThan($contextPosition, $modePosition);
        $this->assertLessThan($stockPosition, $contextPosition);
        $this->assertLessThan($sequencePosition, $stockPosition);
    }

    public function test_server_decision_property_mismatch_fails_closed_and_rolls_back_all_side_effects(): void
    {
        $otherPropertyId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::synchronous(
            $otherPropertyId,
            $this->item->id,
            $this->location->id,
            "property:{$otherPropertyId}:location:{$this->location->id}:item:{$this->item->id}",
            (string) Str::ulid(),
            1,
        );

        $this->assertDecisionMismatchFailsClosed($decision, 'Property/Item identity');
    }

    public function test_server_decision_item_mismatch_fails_closed_and_rolls_back_all_side_effects(): void
    {
        $otherItemId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::synchronous(
            $this->property->id,
            $otherItemId,
            $this->location->id,
            "property:{$this->property->id}:location:{$this->location->id}:item:{$otherItemId}",
            (string) Str::ulid(),
            1,
        );

        $this->assertDecisionMismatchFailsClosed($decision, 'Property/Item identity');
    }

    private function activateEnrollment(InventoryLocation $snapshotLocation): CostDeliveryModeOwnership
    {
        $group = $this->makeApprovedGroup($snapshotLocation);
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup(
            $group->id,
            $this->actor->id,
        );

        return app(CostAuthorityEnrollmentActivationService::class)->activate(
            $group->id,
            $this->actor->id,
        );
    }

    private function makeApprovedGroup(InventoryLocation $snapshotLocation): CostAuthorityEnrollmentGroup
    {
        $group = app(CostAuthorityEnrollmentRepository::class)->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [[
                'location_id' => $snapshotLocation->id,
                'valuation_scope' => $this->scope($snapshotLocation->id),
                'opening_quantity' => '100.0000',
                'opening_carrying_value' => '1000.0000',
                'currency_code' => 'USD',
                'business_date' => now()->toDateString(),
                'financial_period_id' => $this->period->id,
                'source_reference' => 'CC-P01A-A4-TEST',
                'evidence_timestamp' => now(),
            ]],
        );
        DB::transaction(fn () => app(CostAuthorityEnrollmentRepository::class)->approve(
            $group->id,
            $this->actor->id,
            now(),
        ));

        return $group->fresh();
    }

    private function coordinatorWith(A4TestCostDeliveryModePort $port): InventoryPostingControlCoordinator
    {
        $this->app->instance(CostDeliveryModePort::class, $port);

        return app(InventoryPostingControlCoordinator::class);
    }

    private function fakePort(Closure $resolver): A4TestCostDeliveryModePort
    {
        return new A4TestCostDeliveryModePort($resolver);
    }

    private function makeIntent(string $key): InventoryLedgerPostingIntent
    {
        return new InventoryLedgerPostingIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            locationId: $this->location->id,
            businessDate: now()->toDateString(),
            occurredAt: now(),
            sourceDocumentType: 'inventory_adjustment',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'inventory_adjustment_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'adjustment_in',
            idempotencyKey: $key.'-'.Str::random(8),
            transactionType: TransactionTypeEnum::AdjustmentIn,
            quantityChange: '10.0000',
            unitCost: '5.0000',
            totalCost: '50.0000',
        );
    }

    private function copyIntent(
        InventoryLedgerPostingIntent $intent,
        ?string $quantityChange = null,
        ?string $totalCost = null,
    ): InventoryLedgerPostingIntent {
        return new InventoryLedgerPostingIntent(
            propertyId: $intent->propertyId,
            itemId: $intent->itemId,
            locationId: $intent->locationId,
            businessDate: $intent->businessDate,
            occurredAt: $intent->occurredAt,
            sourceDocumentType: $intent->sourceDocumentType,
            sourceDocumentId: $intent->sourceDocumentId,
            sourceLineType: $intent->sourceLineType,
            sourceLineId: $intent->sourceLineId,
            movementRole: $intent->movementRole,
            idempotencyKey: $intent->idempotencyKey,
            transactionType: $intent->transactionType,
            quantityChange: $quantityChange ?? $intent->quantityChange,
            unitCost: $intent->unitCost,
            totalCost: $totalCost ?? $intent->totalCost,
            reference: $intent->reference,
            notes: $intent->notes,
            reversesInventoryTransactionId: $intent->reversesInventoryTransactionId,
            correctsInventoryTransactionId: $intent->correctsInventoryTransactionId,
        );
    }

    private function scope(string $locationId): string
    {
        return "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
    }

    private function assertNoPostingSideEffects(): void
    {
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('inventory_valuation_sequences', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame('100.0000', $this->stock->fresh()->physical_quantity);
    }

    private function assertDecisionMismatchFailsClosed(
        CostDeliveryPostingDecision $decision,
        string $expectedMessage,
    ): void {
        try {
            $this->coordinatorWith($this->fakePort(fn () => $decision))->post(
                $this->makeIntent('a4-decision-mismatch'),
                $this->actor->id,
            );
            $this->fail('A source decision outside the exact Inventory intent must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        $this->assertNoPostingSideEffects();
    }
}

final class A4TestCostDeliveryModePort implements CostDeliveryModePort
{
    public int $invocations = 0;

    /** @var list<int> */
    public array $transactionLevels = [];

    public function __construct(private readonly Closure $resolver) {}

    public function isEnrolled(string $propertyId, string $itemId): bool
    {
        return true;
    }

    public function lockForDocumentMutation(string $propertyId, string $itemId): void {}

    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId,
    ): CostDeliveryPostingDecision {
        $this->invocations++;
        $this->transactionLevels[] = DB::transactionLevel();

        return ($this->resolver)($propertyId, $itemId, $locationId);
    }
}
