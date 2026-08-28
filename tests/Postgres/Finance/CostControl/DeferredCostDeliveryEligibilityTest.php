<?php

namespace Tests\Postgres\Finance\CostControl;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\CostControl\Services\DeferredCostDeliveryEligibilityService;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryFailure;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class DeferredCostDeliveryEligibilityTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private Property $otherProperty;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $location;

    private InventoryLocation $partnerLocation;

    private InventoryUnit $unit;

    private FinancialPeriod $period;

    private PropertyBusinessDate $businessDate;

    private CostAuthorityEnrollmentGroup $group;

    private InventoryTransaction $source;

    private OutboxMessage $outbox;

    private CostDeliveryOutboxDisposition $disposition;

    /** @var array{ownership_id:string,ownership_version:int,cutover_id:string} */
    private array $evidence;

    private DeferredCostDeliveryEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->otherProperty = Property::where('id', '<>', $this->property->id)->firstOrFail();
        $this->actor = User::firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01C Deferred Eligibility',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01C-EL-'.Str::upper(Str::random(8)),
            'name' => 'CC-P01C Deferred Eligibility Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01C Deferred Location '.Str::random(6),
            'type' => 'internal',
        ]);
        $this->partnerLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01C Deferred Partner Location '.Str::random(6),
            'type' => 'internal',
        ]);
        $this->unit = InventoryUnit::create([
            'property_id' => $this->property->id,
            'code' => 'C'.Str::upper(Str::random(5)),
            'name' => 'CC-P01C Unit',
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        $this->businessDate = PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => '2026-08-25'],
            [
                'timezone_snapshot' => $this->property->timezone,
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_by' => $this->actor->id,
                'opened_at' => now(),
            ],
        );
        [$this->group, $this->evidence] = $this->makeDeferredEvidence();
        $this->source = $this->makeSource();
        $this->outbox = $this->makeOutbox($this->source);
        $this->disposition = $this->makeDisposition($this->source, $this->outbox);
        $this->service = app(DeferredCostDeliveryEligibilityService::class);
    }

    public function test_exact_deferred_source_at_watermark_is_eligible_and_proving_boundary_mutates_nothing(): void
    {
        $before = $this->controlledFingerprint();

        $result = $this->service->evaluate($this->outbox->id);

        $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $result);
        $this->assertSame($this->source->id, $result->sourceInventoryTransactionId);
        $this->assertSame(1, $result->expectedSequence);
        $this->assertFalse($result->alreadySatisfied);
        $this->assertFalse($result->requiresPairedApplication);
        $this->assertSame(CostLedgerSourceEquivalence::NO_EXISTING_EFFECT, $result->sourceEquivalence->status);
        $this->assertSame($before, $this->controlledFingerprint());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
    }

    public function test_eligibility_can_be_revalidated_under_the_same_outer_transaction(): void
    {
        DB::transaction(function (): void {
            $first = $this->service->evaluateWithinTransaction($this->outbox->id);
            $second = $this->service->evaluateWithinTransaction($this->outbox->id);

            $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $first);
            $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $second);
            $this->assertSame($first->sourceInventoryTransactionId, $second->sourceInventoryTransactionId);
        });
    }

    #[DataProvider('sourceStampFailureProvider')]
    public function test_historical_synchronous_and_incomplete_deferred_stamps_fail_closed(
        array $changes,
        string $expectedCode,
    ): void {
        $this->rawUpdate('inventory_transactions', $this->source->id, $changes);

        $this->assertFailure($expectedCode);
    }

    public static function sourceStampFailureProvider(): array
    {
        return [
            'historical null stamp' => [[
                'cost_delivery_mode' => null,
                'cost_delivery_ownership_id' => null,
                'cost_delivery_ownership_version' => null,
                'cost_delivery_cutover_id' => null,
            ], 'HISTORICAL_NULL_STAMP_NOT_DEFERRED_ELIGIBLE'],
            'synchronous stamp' => [[
                'cost_delivery_mode' => 'SYNCHRONOUS',
                'cost_delivery_cutover_id' => null,
            ], 'SYNCHRONOUS_SOURCE_NOT_DEFERRED_ELIGIBLE'],
            'missing ownership' => [[
                'cost_delivery_ownership_id' => (string) Str::ulid(),
            ], 'OWNERSHIP_NOT_FOUND'],
            'ownership version mismatch' => [[
                'cost_delivery_ownership_version' => 99,
            ], 'OWNERSHIP_VERSION_MISMATCH'],
            'cutover mismatch' => [[
                'cost_delivery_cutover_id' => (string) Str::ulid(),
            ], 'OWNERSHIP_CUTOVER_MISMATCH'],
        ];
    }

    public function test_cross_property_ownership_identity_fails_closed(): void
    {
        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'property_id' => $this->otherProperty->id,
        ]);

        $this->assertFailure('OWNERSHIP_IDENTITY_MISMATCH');
    }

    public function test_missing_enrollment_fails_closed(): void
    {
        $missing = (string) Str::ulid();
        $this->withoutTriggers(['cost_delivery_mode_ownerships', 'cost_delivery_cutovers'], function () use ($missing): void {
            DB::table('cost_delivery_mode_ownerships')->where('id', $this->evidence['ownership_id'])
                ->update(['enrollment_group_id' => $missing]);
            DB::table('cost_delivery_cutovers')->where('id', $this->evidence['cutover_id'])
                ->update(['enrollment_group_id' => $missing]);
        });

        $this->assertFailure('ENROLLMENT_NOT_FOUND');
    }

    public function test_non_enrolled_group_fails_closed(): void
    {
        $this->withoutTriggers(['cost_authority_enrollment_groups'], function (): void {
            DB::table('cost_authority_enrollment_groups')->where('id', $this->group->id)
                ->update(['status' => CostAuthorityEnrollmentStatusEnum::Approved->value]);
        });

        $this->assertFailure('ENROLLMENT_NOT_ENROLLED');
    }

    public function test_missing_scope_snapshot_fails_closed(): void
    {
        $this->withoutTriggers(['cost_delivery_cutover_scopes'], function (): void {
            DB::table('cost_delivery_cutover_scopes')
                ->where('cutover_id', $this->evidence['cutover_id'])
                ->where('location_id', $this->location->id)
                ->update(['enrollment_scope_snapshot_id' => (string) Str::ulid()]);
        });

        $this->assertFailure('SCOPE_SNAPSHOT_NOT_FOUND');
    }

    public function test_wrong_source_location_and_scope_fail_closed(): void
    {
        $otherLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01C Wrong Source Location',
            'type' => 'internal',
        ]);
        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'location_id' => $otherLocation->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$otherLocation->id}:item:{$this->item->id}",
        ]);

        $this->assertFailure('SCOPE_SNAPSHOT_NOT_FOUND');
    }

    public function test_missing_and_wrong_property_pilot_authorization_fail_closed(): void
    {
        $this->withoutTriggers(['cost_delivery_pilot_properties'], function (): void {
            DB::table('cost_delivery_pilot_properties')->where('pilot_slot', 1)->delete();
        });
        $this->assertFailure('PILOT_NOT_AUTHORIZED');

        DB::table('cost_delivery_pilot_properties')->insert([
            'id' => (string) Str::ulid(),
            'pilot_slot' => 1,
            'property_id' => $this->otherProperty->id,
            'owner_approval_reference' => 'CC-P01C-WRONG-PROPERTY-TEST-ONLY',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
            'created_at' => now(),
        ]);
        $this->assertFailure('PILOT_NOT_AUTHORIZED');
    }

    public function test_watermark_and_expected_sequence_boundaries_are_fail_closed(): void
    {
        DB::statement('ALTER TABLE cost_delivery_cutover_scopes DROP CONSTRAINT chk_cdcs_n_plus_one');
        DB::statement('ALTER TABLE cost_delivery_cutover_scopes DROP CONSTRAINT chk_cdcs_state_shape');
        $this->withoutTriggers(['cost_delivery_cutover_scopes'], function (): void {
            DB::table('cost_delivery_cutover_scopes')
                ->where('cutover_id', $this->evidence['cutover_id'])
                ->where('location_id', $this->location->id)
                ->update(['first_deferred_owned_sequence' => 2]);
        });
        $this->assertFailure('WATERMARK_NOT_REACHED');

        $this->withoutTriggers(['cost_delivery_cutover_scopes'], function (): void {
            DB::table('cost_delivery_cutover_scopes')
                ->where('cutover_id', $this->evidence['cutover_id'])
                ->where('location_id', $this->location->id)
                ->update(['first_deferred_owned_sequence' => 1]);
        });
        $this->rawUpdate('inventory_transactions', $this->source->id, ['valuation_sequence' => 2]);
        $this->rawUpdate('cost_delivery_outbox_dispositions', $this->disposition->id, ['valuation_sequence' => 2]);
        CostAvcoState::where('property_id', $this->property->id)
            ->where('location_id', $this->location->id)
            ->where('item_id', $this->item->id)
            ->update(['last_valuation_sequence' => 1]);

        $result = $this->service->evaluate($this->outbox->id);
        $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $result);
        $this->assertSame(2, $result->expectedSequence);
    }

    public function test_historical_and_delivered_dispositions_are_ineligible(): void
    {
        $this->rawUpdate('cost_delivery_outbox_dispositions', $this->disposition->id, [
            'classification' => CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory->value,
            'processing_state' => CostDeliveryProcessingState::HistoricalExcluded->value,
            'cost_delivery_ownership_id' => null,
            'cost_delivery_ownership_version' => null,
            'cost_delivery_cutover_id' => null,
            'historical_excluded_at' => now(),
        ]);
        $this->assertFailure('DISPOSITION_IDENTITY_MISMATCH');

        $this->rawUpdate('cost_delivery_outbox_dispositions', $this->disposition->id, [
            'classification' => CostDeliveryDispositionClass::DeferredOwnedAfterCutover->value,
            'processing_state' => CostDeliveryProcessingState::Delivered->value,
            'cost_delivery_ownership_id' => $this->evidence['ownership_id'],
            'cost_delivery_ownership_version' => $this->evidence['ownership_version'],
            'cost_delivery_cutover_id' => $this->evidence['cutover_id'],
            'historical_excluded_at' => null,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'delivered_at' => now(),
        ]);
        $this->assertFailure('DISPOSITION_STATE_INELIGIBLE');
    }

    #[DataProvider('outboxFailureProvider')]
    public function test_outbox_contract_fails_closed(array $changes, string $expectedCode): void
    {
        DB::table('outbox_messages')->where('id', $this->outbox->id)->update($changes);

        $this->assertFailure($expectedCode);
    }

    public static function outboxFailureProvider(): array
    {
        return [
            'wrong topic' => [['topic' => 'inventory.transaction.changed'], 'OUTBOX_TOPIC_INVALID'],
            'malformed payload' => [['payload' => json_encode(['transactionId' => 'wrong', 'extra' => true])], 'OUTBOX_PAYLOAD_SOURCE_MISMATCH'],
            'identity mismatch' => [['idempotency_key' => 'wrong-source-identity'], 'OUTBOX_SOURCE_IDENTITY_MISMATCH'],
        ];
    }

    public function test_missing_inventory_transaction_and_stock_movement_ids_never_become_eligible(): void
    {
        $missing = (string) Str::ulid();
        $missingOutbox = $this->makeOutboxForSourceId($missing);
        $this->assertFailure('INVENTORY_SOURCE_NOT_FOUND', $missingOutbox);

        $movementId = (string) Str::ulid();
        DB::table('inventory_stock_movements')->insert([
            'id' => $movementId,
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => 'GOODS_RECEIPT',
            'direction' => 'IN',
            'source_leg' => 'PRIMARY',
            'quantity' => '1.000',
            'source_domain' => 'inventory',
            'source_type' => 'CCP01CEligibilityTest',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'created_at' => now(),
        ]);
        $movementOutbox = $this->makeOutboxForSourceId($movementId);
        $this->assertFailure('INVENTORY_SOURCE_NOT_FOUND', $movementOutbox);
    }

    public function test_closed_business_date_fails_closed(): void
    {
        $this->businessDate->update([
            'status' => PropertyBusinessDateStatusEnum::Closed,
            'is_open' => null,
            'closed_by' => $this->actor->id,
            'closed_at' => now(),
        ]);

        $this->assertFailure('BUSINESS_DATE_CLOSED');
    }

    public function test_missing_closing_and_closed_periods_fail_while_open_and_reopened_pass(): void
    {
        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'financial_period_id' => (string) Str::ulid(),
        ]);
        $this->assertFailure('FINANCIAL_PERIOD_MISSING');

        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'financial_period_id' => $this->period->id,
        ]);
        foreach ([FinancialPeriodStatusEnum::Closing, FinancialPeriodStatusEnum::Closed] as $status) {
            $this->period->update(['status' => $status]);
            $this->assertFailure('FINANCIAL_PERIOD_STATE_INELIGIBLE');
        }
        foreach ([FinancialPeriodStatusEnum::Open, FinancialPeriodStatusEnum::Reopened] as $status) {
            $this->period->update(['status' => $status]);
            $this->assertInstanceOf(
                DeferredCostDeliveryEligibleContext::class,
                $this->service->evaluate($this->outbox->id),
            );
        }
    }

    public function test_cross_calendar_business_date_uses_occurred_at_period_authority_and_locks_both_rows(): void
    {
        $this->rawUpdate('property_business_dates', $this->businessDate->id, [
            'business_date' => '2026-08-31',
        ]);
        $september = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 9],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'business_date' => '2026-08-31',
            'occurred_at' => '2026-09-01 00:30:00+08',
            'financial_period_id' => $september->id,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = $this->service->evaluate($this->outbox->id);

        $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $result);
        $this->assertTrue($this->containsForUpdateQuery($queries, 'property_business_dates'));
        $this->assertTrue($this->containsForUpdateQuery($queries, 'gl_financial_periods'));

        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'financial_period_id' => $this->period->id,
        ]);
        $this->assertFailure('FINANCIAL_PERIOD_DATE_MISMATCH');
    }

    public function test_sequence_gap_and_behind_state_return_stable_evidence_without_mutation(): void
    {
        $this->rawUpdate('inventory_transactions', $this->source->id, ['valuation_sequence' => 3]);
        $this->rawUpdate('cost_delivery_outbox_dispositions', $this->disposition->id, ['valuation_sequence' => 3]);
        $before = $this->controlledFingerprint();
        $gap = $this->assertFailure('BLOCKED_SEQUENCE');
        $this->assertSame(1, $gap->evidence['expected_sequence']);
        $this->assertSame(3, $gap->evidence['source_valuation_sequence']);
        $this->assertSame($before, $this->controlledFingerprint());

        $this->rawUpdate('inventory_transactions', $this->source->id, ['valuation_sequence' => 1]);
        $this->rawUpdate('cost_delivery_outbox_dispositions', $this->disposition->id, ['valuation_sequence' => 1]);
        CostAvcoState::where('property_id', $this->property->id)
            ->where('location_id', $this->location->id)
            ->where('item_id', $this->item->id)
            ->update(['last_valuation_sequence' => 2]);
        $this->assertFailure('SOURCE_SEQUENCE_BEHIND_CONTRADICTION');

        $this->makeLedger($this->source);
        $satisfied = $this->service->evaluate($this->outbox->id);
        $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $satisfied);
        $this->assertTrue($satisfied->alreadySatisfied);
        $this->assertSame(CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT, $satisfied->sourceEquivalence->status);
    }

    #[DataProvider('unsupportedTypeProvider')]
    public function test_unsupported_monetary_handlers_fail_closed(string $type, string $expectedCode): void
    {
        $this->rawUpdate('inventory_transactions', $this->source->id, ['transaction_type' => $type]);

        $this->assertFailure($expectedCode);
    }

    public static function unsupportedTypeProvider(): array
    {
        return [
            'opening balance' => [TransactionTypeEnum::OpeningBalance->value, 'OPENING_BALANCE_UNSUPPORTED'],
            'return' => [TransactionTypeEnum::Return->value, 'RETURN_UNSUPPORTED'],
            'reversal' => [TransactionTypeEnum::Reversal->value, 'REVERSAL_HANDLER_NOT_AVAILABLE'],
        ];
    }

    public function test_transfer_partner_outside_enrollment_snapshot_fails_closed(): void
    {
        $outsideLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01C Outside Enrollment Location',
            'type' => 'internal',
        ]);
        $this->makeTransferPairEvidence(false, false, $outsideLocation, true);

        $this->assertFailure('TRANSFER_PAIR_SCOPE_SNAPSHOT_MISSING');
    }

    public function test_transfer_partner_enrolled_but_missing_cutover_scope_fails_closed(): void
    {
        $this->makeTransferPairEvidence();
        $this->withoutTriggers(['cost_delivery_cutover_scopes'], function (): void {
            DB::table('cost_delivery_cutover_scopes')
                ->where('cutover_id', $this->evidence['cutover_id'])
                ->where('location_id', $this->partnerLocation->id)
                ->delete();
        });

        $this->assertFailure('TRANSFER_PAIR_CUTOVER_SCOPE_MISSING');
    }

    public function test_transfer_partner_below_its_cutover_watermark_fails_closed(): void
    {
        $this->makeTransferPairEvidence();
        DB::statement('ALTER TABLE cost_delivery_cutover_scopes DROP CONSTRAINT chk_cdcs_n_plus_one');
        DB::statement('ALTER TABLE cost_delivery_cutover_scopes DROP CONSTRAINT chk_cdcs_state_shape');
        $this->withoutTriggers(['cost_delivery_cutover_scopes'], function (): void {
            DB::table('cost_delivery_cutover_scopes')
                ->where('cutover_id', $this->evidence['cutover_id'])
                ->where('location_id', $this->partnerLocation->id)
                ->update(['first_deferred_owned_sequence' => 2]);
        });

        $this->assertFailure('TRANSFER_PAIR_WATERMARK_NOT_REACHED');
    }

    public function test_transfer_partner_missing_outbox_fails_closed(): void
    {
        $this->makeTransferPairEvidence(false, false);

        $this->assertFailure('TRANSFER_PAIR_OUTBOX_MISSING');
    }

    public function test_transfer_partner_malformed_outbox_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        DB::table('outbox_messages')->where('id', $pair['outbox']->id)->update([
            'payload' => json_encode(['transactionId' => 'wrong', 'extra' => true]),
        ]);

        $this->assertFailure('TRANSFER_PAIR_OUTBOX_PAYLOAD_SOURCE_MISMATCH');
    }

    public function test_transfer_partner_missing_disposition_fails_closed(): void
    {
        $this->makeTransferPairEvidence(true, false);

        $this->assertFailure('TRANSFER_PAIR_DISPOSITION_MISSING');
    }

    public function test_transfer_partner_historical_disposition_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->rawUpdate('cost_delivery_outbox_dispositions', $pair['disposition']->id, [
            'classification' => CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory->value,
            'processing_state' => CostDeliveryProcessingState::HistoricalExcluded->value,
            'cost_delivery_ownership_id' => null,
            'cost_delivery_ownership_version' => null,
            'cost_delivery_cutover_id' => null,
            'historical_excluded_at' => now(),
        ]);

        $this->assertFailure('TRANSFER_PAIR_DISPOSITION_IDENTITY_MISMATCH');
    }

    public function test_transfer_partner_delivered_disposition_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->rawUpdate('cost_delivery_outbox_dispositions', $pair['disposition']->id, [
            'processing_state' => CostDeliveryProcessingState::Delivered->value,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'delivered_at' => now(),
        ]);

        $this->assertFailure('TRANSFER_PAIR_DISPOSITION_STATE_INELIGIBLE');
    }

    public function test_transfer_partner_ownership_version_mismatch_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->rawUpdate('inventory_transactions', $pair['source']->id, [
            'cost_delivery_ownership_version' => 99,
        ]);

        $this->assertFailure('TRANSFER_PAIR_OWNERSHIP_VERSION_MISMATCH');
    }

    public function test_transfer_partner_cutover_mismatch_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->rawUpdate('inventory_transactions', $pair['source']->id, [
            'cost_delivery_cutover_id' => (string) Str::ulid(),
        ]);

        $this->assertFailure('TRANSFER_PAIR_CUTOVER_MISMATCH');
    }

    public function test_transfer_partner_missing_avco_state_fails_closed(): void
    {
        $this->makeTransferPairEvidence();
        DB::table('cost_avco_states')
            ->where('property_id', $this->property->id)
            ->where('location_id', $this->partnerLocation->id)
            ->where('item_id', $this->item->id)
            ->delete();

        $this->assertFailure('TRANSFER_PAIR_AVCO_STATE_MISSING');
    }

    public function test_transfer_partner_sequence_gap_returns_scope_evidence_and_mutates_nothing(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->rawUpdate('inventory_transactions', $pair['source']->id, ['valuation_sequence' => 2]);
        $this->rawUpdate('cost_delivery_outbox_dispositions', $pair['disposition']->id, ['valuation_sequence' => 2]);
        $before = $this->controlledFingerprint();

        $failure = $this->assertFailure('BLOCKED_SEQUENCE');

        $this->assertSame('partner', $failure->evidence['affected_leg']);
        $this->assertSame($pair['source']->valuation_scope, $failure->evidence['affected_scope']);
        $this->assertSame(1, $failure->evidence['expected_sequence']);
        $this->assertSame(2, $failure->evidence['source_valuation_sequence']);
        $this->assertSame($before, $this->controlledFingerprint());
    }

    public function test_transfer_partner_cost_ledger_conflict_fails_closed(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $this->makeLedger($pair['source'], ['entry_type' => 'receipt']);

        $this->assertFailure('TRANSFER_PAIR_COST_LEDGER_CONFLICTING_EFFECT');
    }

    public function test_transfer_partner_duplicate_source_contradiction_fails_closed_defensively(): void
    {
        $pair = $this->makeTransferPairEvidence();
        DB::statement('ALTER TABLE cost_ledger_entries DROP CONSTRAINT uk_cost_ledger_source_inventory_transaction');
        $this->makeLedger($pair['source'], ['idempotency_key' => 'partner-duplicate-a', 'entry_sequence' => 1]);
        $this->makeLedger($pair['source'], ['idempotency_key' => 'partner-duplicate-b', 'entry_sequence' => 2]);

        $failure = $this->assertFailure('TRANSFER_PAIR_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION');

        $this->assertSame(2, $failure->evidence['source_row_count']);
    }

    public function test_complete_transfer_pair_returns_two_leg_eligible_context_without_mutation(): void
    {
        $pair = $this->makeTransferPairEvidence();
        $before = $this->controlledFingerprint();

        $result = $this->service->evaluate($this->outbox->id);

        $this->assertInstanceOf(DeferredCostDeliveryEligibleContext::class, $result);
        $this->assertTrue($result->requiresPairedApplication);
        $this->assertSame($pair['source']->id, $result->pairedInventoryTransactionId);
        $this->assertSame($this->source->id, $result->sourceLeg->sourceInventoryTransactionId);
        $this->assertSame($pair['source']->id, $result->pairedLeg->sourceInventoryTransactionId);
        $this->assertSame($this->location->id, $result->sourceLeg->locationId);
        $this->assertSame($this->partnerLocation->id, $result->pairedLeg->locationId);
        $this->assertSame(1, $result->sourceLeg->firstDeferredOwnedSequence);
        $this->assertSame(1, $result->pairedLeg->firstDeferredOwnedSequence);
        $this->assertSame(1, $result->sourceLeg->expectedSequence);
        $this->assertSame(1, $result->pairedLeg->expectedSequence);
        $this->assertSame(CostLedgerSourceEquivalence::NO_EXISTING_EFFECT, $result->sourceLeg->sourceEquivalence->status);
        $this->assertSame(CostLedgerSourceEquivalence::NO_EXISTING_EFFECT, $result->pairedLeg->sourceEquivalence->status);
        $this->assertNotSame($result->sourceLeg->cutoverScopeId, $result->pairedLeg->cutoverScopeId);
        $this->assertNotSame(
            $result->sourceLeg->enrollmentScopeSnapshotId,
            $result->pairedLeg->enrollmentScopeSnapshotId,
        );
        $this->assertSame($before, $this->controlledFingerprint());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
    }

    /**
     * @return array{source:InventoryTransaction,outbox:?OutboxMessage,disposition:?CostDeliveryOutboxDisposition}
     */
    private function makeTransferPairEvidence(
        bool $withOutbox = true,
        bool $withDisposition = true,
        ?InventoryLocation $partnerLocation = null,
        bool $disablePartnerSourceTriggers = false,
    ): array {
        $partnerLocation ??= $this->partnerLocation;
        $documentId = (string) Str::ulid();
        $lineId = (string) Str::ulid();
        $this->rawUpdate('inventory_transactions', $this->source->id, [
            'transaction_type' => TransactionTypeEnum::TransferOut->value,
            'source_document_type' => 'inventory_transfer',
            'source_document_id' => $documentId,
            'source_line_type' => 'inventory_transfer_line',
            'source_line_id' => $lineId,
            'movement_role' => TransactionTypeEnum::TransferOut->value,
            'quantity_before' => '10.0000',
            'quantity_change' => '-2.0000',
            'quantity_after' => '8.0000',
            'total_cost' => '-15.0000',
        ]);
        $this->source = $this->source->fresh();
        $partner = $this->makeSource([
            'location_id' => $partnerLocation->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$partnerLocation->id}:item:{$this->item->id}",
            'transaction_type' => TransactionTypeEnum::TransferIn,
            'source_document_type' => 'inventory_transfer',
            'source_document_id' => $documentId,
            'source_line_type' => 'inventory_transfer_line',
            'source_line_id' => $lineId,
            'movement_role' => TransactionTypeEnum::TransferIn->value,
            'quantity_before' => '0.0000',
            'quantity_change' => '2.0000',
            'quantity_after' => '2.0000',
            'total_cost' => '15.0000',
        ], $disablePartnerSourceTriggers);
        $outbox = $withOutbox ? $this->makeOutbox($partner) : null;
        $disposition = $withOutbox && $withDisposition
            ? $this->makeDisposition($partner, $outbox)
            : null;

        return [
            'source' => $partner,
            'outbox' => $outbox,
            'disposition' => $disposition,
        ];
    }

    private function assertFailure(
        string $expectedCode,
        ?OutboxMessage $outbox = null,
    ): DeferredCostDeliveryFailure {
        $result = $this->service->evaluate(($outbox ?? $this->outbox)->id);

        $this->assertInstanceOf(DeferredCostDeliveryFailure::class, $result);
        $this->assertSame($expectedCode, $result->code);

        return $result;
    }

    /** @return array{CostAuthorityEnrollmentGroup,array{ownership_id:string,ownership_version:int,cutover_id:string}} */
    private function makeDeferredEvidence(): array
    {
        $scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        $partnerScope = "property:{$this->property->id}:location:{$this->partnerLocation->id}:item:{$this->item->id}";
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $group = $repository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [
                [
                    'location_id' => $this->location->id,
                    'valuation_scope' => $scope,
                    'opening_quantity' => '0.0000',
                    'opening_carrying_value' => '0.0000',
                    'currency_code' => 'USD',
                    'business_date' => '2026-08-25',
                    'financial_period_id' => $this->period->id,
                    'source_reference' => 'CC-P01C-TEST-ONLY',
                    'evidence_timestamp' => now(),
                ],
                [
                    'location_id' => $this->partnerLocation->id,
                    'valuation_scope' => $partnerScope,
                    'opening_quantity' => '0.0000',
                    'opening_carrying_value' => '0.0000',
                    'currency_code' => 'USD',
                    'business_date' => '2026-08-25',
                    'financial_period_id' => $this->period->id,
                    'source_reference' => 'CC-P01C-TEST-ONLY-PARTNER',
                    'evidence_timestamp' => now(),
                ],
            ],
        );
        DB::transaction(fn () => $repository->approve($group->id, $this->actor->id, now()));
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $this->actor->id);
        $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $this->actor->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('cost_delivery_pilot_properties')->insert([
            'id' => (string) Str::ulid(),
            'pilot_slot' => 1,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'CC-P01C-TEST-ONLY',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
            'created_at' => now(),
        ]);
        $snapshots = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $group->id)
            ->orderBy('valuation_scope')
            ->get();
        $cutoverId = (string) Str::ulid();

        DB::transaction(function () use ($ownership, $group, $snapshots, $cutoverId): void {
            DB::table('cost_delivery_cutovers')->insert([
                'id' => $cutoverId,
                'ownership_id' => $ownership->id,
                'enrollment_group_id' => $group->id,
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'financial_period_id' => $this->period->id,
                'boundary_business_date' => '2026-08-25',
                'owner_approval_reference' => 'CC-P01C-TEST-ONLY',
                'requested_by' => $this->actor->id,
                'requested_at' => now()->subMinutes(2),
                'approved_by' => $this->actor->id,
                'approved_at' => now()->subMinute(),
                'activated_by' => $this->actor->id,
                'activated_at' => now(),
                'created_at' => now(),
            ]);
            foreach ($snapshots as $snapshot) {
                DB::table('cost_delivery_cutover_scopes')->insert([
                    'id' => (string) Str::ulid(),
                    'cutover_id' => $cutoverId,
                    'enrollment_scope_snapshot_id' => $snapshot->id,
                    'property_id' => $this->property->id,
                    'location_id' => $snapshot->location_id,
                    'item_id' => $this->item->id,
                    'valuation_scope' => $snapshot->valuation_scope,
                    'inventory_sequence_source' => 'ALLOCATOR_ABSENT',
                    'inventory_valuation_sequence_id' => null,
                    'inventory_allocator_last_sequence' => 0,
                    'cost_avco_last_valuation_sequence' => null,
                    'sequence_state_classification' => 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
                    'last_synchronously_owned_sequence' => 0,
                    'first_deferred_owned_sequence' => 1,
                    'created_at' => now(),
                ]);
            }
            DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)->update([
                'delivery_mode' => 'DEFERRED',
                'ownership_version' => 2,
                'activated_cutover_id' => $cutoverId,
                'changed_by' => $this->actor->id,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });

        return [$group->fresh(), [
            'ownership_id' => $ownership->id,
            'ownership_version' => 2,
            'cutover_id' => $cutoverId,
        ]];
    }

    private function makeSource(array $overrides = [], bool $disableTriggers = false): InventoryTransaction
    {
        $id = (string) Str::ulid();
        $attributes = array_merge([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'currency_code' => 'USD',
            'financial_period_id' => $this->period->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            'valuation_sequence' => 1,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => 'CC-P01C-APPROVED',
            'cost_delivery_mode' => 'DEFERRED',
            'cost_delivery_ownership_id' => $this->evidence['ownership_id'],
            'cost_delivery_ownership_version' => $this->evidence['ownership_version'],
            'cost_delivery_cutover_id' => $this->evidence['cutover_id'],
            'business_date' => '2026-08-25',
            'occurred_at' => now()->startOfSecond(),
            'source_document_type' => 'inventory_receipt',
            'source_document_id' => (string) Str::ulid(),
            'source_line_type' => 'inventory_receipt_line',
            'source_line_id' => (string) Str::ulid(),
            'movement_role' => TransactionTypeEnum::PurchaseReceipt->value,
            'idempotency_key' => 'ccp01c-'.$id,
            'transaction_type' => TransactionTypeEnum::PurchaseReceipt,
            'quantity_before' => '0.0000',
            'quantity_change' => '2.0000',
            'quantity_after' => '2.0000',
            'unit_cost' => '7.5000',
            'total_cost' => '15.0000',
            'posted_by' => $this->actor->id,
            'posted_at' => now(),
            'created_at' => now(),
        ], $overrides);

        if ($disableTriggers) {
            $this->withoutTriggers(['inventory_transactions'], fn () => DB::table('inventory_transactions')->insert($attributes));

            return InventoryTransaction::findOrFail($id);
        }

        return InventoryTransaction::create($attributes)->fresh();
    }

    private function makeOutbox(InventoryTransaction $source): OutboxMessage
    {
        return $this->makeOutboxForSourceId($source->id);
    }

    private function makeOutboxForSourceId(string $sourceId): OutboxMessage
    {
        return app(OutboxRepository::class)->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceId,
            'payload' => ['transactionId' => $sourceId],
            'idempotency_key' => "inventory_transaction:{$sourceId}:cost_ledger",
        ]);
    }

    private function makeDisposition(
        InventoryTransaction $source,
        OutboxMessage $outbox,
    ): CostDeliveryOutboxDisposition {
        return CostDeliveryOutboxDisposition::create([
            'outbox_message_id' => $outbox->id,
            'source_inventory_transaction_id' => $source->id,
            'property_id' => $source->property_id,
            'location_id' => $source->location_id,
            'item_id' => $source->item_id,
            'valuation_scope' => $source->valuation_scope,
            'valuation_sequence' => $source->valuation_sequence,
            'classification' => CostDeliveryDispositionClass::DeferredOwnedAfterCutover,
            'processing_state' => CostDeliveryProcessingState::Pending,
            'cost_delivery_ownership_id' => $this->evidence['ownership_id'],
            'cost_delivery_ownership_version' => $this->evidence['ownership_version'],
            'cost_delivery_cutover_id' => $this->evidence['cutover_id'],
            'classified_by' => $this->actor->id,
            'classification_provenance' => 'DEFERRED_SOURCE_CUTOVER_WATERMARK',
            'classified_at' => now(),
            'attempt_count' => 0,
        ]);
    }

    private function makeLedger(InventoryTransaction $source, array $overrides = []): CostLedgerEntry
    {
        $entryType = match ($source->transaction_type) {
            TransactionTypeEnum::TransferIn, TransactionTypeEnum::TransferOut => 'transfer',
            TransactionTypeEnum::Issue => 'issue',
            TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
            default => 'receipt',
        };

        return CostLedgerEntry::create(array_merge([
            'property_id' => $source->property_id,
            'source_inventory_transaction_id' => $source->id,
            'prior_cost_ledger_entry_id' => null,
            'entry_type' => $entryType,
            'idempotency_key' => $source->idempotency_key,
            'entry_sequence' => $source->valuation_sequence,
            'currency_code' => $source->currency_code,
            'quantity_delta' => $source->quantity_change,
            'unit_cost' => $source->unit_cost,
            'value_delta' => $source->total_cost,
            'business_date' => $source->business_date,
            'occurred_at' => $source->occurred_at,
        ], $overrides));
    }

    /** @param list<string> $queries */
    private function containsForUpdateQuery(array $queries, string $table): bool
    {
        foreach ($queries as $query) {
            if (str_contains($query, $table) && str_contains($query, 'for update')) {
                return true;
            }
        }

        return false;
    }

    private function rawUpdate(
        string $table,
        ?string $id,
        array $changes,
        string $key = 'id',
        ?string $value = null,
    ): void {
        $this->withoutTriggers([$table], function () use ($table, $id, $changes, $key, $value): void {
            DB::table($table)->where($key, $value ?? $id)->update($changes);
        });
    }

    /** @param list<string> $tables */
    private function withoutTriggers(array $tables, Closure $callback): void
    {
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER ALL");
        }
        try {
            $callback();
        } finally {
            foreach (array_reverse($tables) as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER ALL");
            }
        }
    }

    private function controlledFingerprint(): string
    {
        $tables = [
            'inventory_transactions',
            'outbox_messages',
            'cost_delivery_outbox_dispositions',
            'cost_avco_states',
            'cost_ledger_entries',
            'cost_delivery_mode_ownerships',
            'cost_delivery_cutovers',
            'cost_delivery_cutover_scopes',
        ];
        $parts = [];
        foreach ($tables as $table) {
            $parts[] = $table.':'.DB::scalar("SELECT md5(COALESCE(string_agg(row_to_json(t)::text, chr(31) ORDER BY id), '')) FROM {$table} t");
        }

        return hash('sha256', implode('|', $parts));
    }
}
