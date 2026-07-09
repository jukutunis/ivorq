<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedB3AndB4Ready(array $stay): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );
    }

    public function test_can_record_checkout_eligible_when_b3_and_b4_ready(): void
    {
        $stay = $this->checkedInStay('5101');
        $this->seedB3AndB4Ready($stay);

        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CHECKOUT_ELIGIBLE', 'B5 eligible.', 'dce-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CHECKOUT_ELIGIBLE', $result['eligibility']->eligibility_status->value);
        $this->assertSame($stay[0]->id, $result['eligibility']->front_desk_stay_id);
        $this->assertSame($this->frontDeskActor->id, $result['eligibility']->created_by);
        $this->assertNotEmpty($result['eligibility']->source_hash);
        $this->assertNotNull($result['eligibility']->occurred_at);
    }

    public function test_can_record_checkout_blocked(): void
    {
        $stay = $this->checkedInStay('5102');
        $this->seedB3AndB4Ready($stay);

        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CHECKOUT_BLOCKED', 'Blocked.', 'dce-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CHECKOUT_BLOCKED', $result['eligibility']->eligibility_status->value);
    }

    public function test_can_record_checkout_reviewed(): void
    {
        $stay = $this->checkedInStay('5103');
        $this->seedB3AndB4Ready($stay);

        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CHECKOUT_REVIEWED', 'Reviewed.', 'dce-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CHECKOUT_REVIEWED', $result['eligibility']->eligibility_status->value);
    }

    public function test_rejects_checkout_eligible_without_b4_evidence(): void
    {
        $stay = $this->checkedInStay('5104');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No closure readiness evidence found');

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CHECKOUT_ELIGIBLE', 'Should reject.', 'dce-' . Str::ulid()
        );
    }

    public function test_rejects_checkout_eligible_when_b4_blocked(): void
    {
        $stay = $this->checkedInStay('5105');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CLOSURE_BLOCKED', 'B4 blocked.', 'dcr-' . Str::ulid()
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not be blocked');

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id,
            'CHECKOUT_ELIGIBLE', 'Should reject.', 'dce-' . Str::ulid()
        );
    }

    public function test_duplicate_idempotency_key_returns_existing(): void
    {
        $stay = $this->checkedInStay('5106');
        $this->seedB3AndB4Ready($stay);
        $key = 'dce-idem-' . Str::ulid();

        $first = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', 'First.', $key
        );
        $second = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_BLOCKED', 'Second.', $key
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame('CHECKOUT_ELIGIBLE', $second['eligibility']->eligibility_status->value);
        $this->assertSame(1, FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->where('idempotency_key', $key)->count());
    }

    public function test_rejects_forbidden_statuses(): void
    {
        $stay = $this->checkedInStay('5107');

        $forbidden = ['CHECKOUT_READY', 'CHECKED_OUT', 'SETTLED', 'PAYMENT_READY', 'FOLIO_CLOSED'];

        foreach ($forbidden as $status) {
            try {
                app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
                    $this->frontDeskActor, $stay[0]->id, $status, null, 'dce-' . Str::ulid()
                );
                $this->fail("Expected DomainException for: {$status}");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Invalid eligibility status', $e->getMessage());
            }
        }
    }

    public function test_server_resolved_fields(): void
    {
        $stay = $this->checkedInStay('5108');
        $this->seedB3AndB4Ready($stay);

        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $e = $result['eligibility'];
        $this->assertSame($this->property->id, $e->property_id);
        $this->assertSame($stay[0]->reservation_id, $e->reservation_id);
        $this->assertSame($stay[0]->guest_id, $e->guest_id);
        $this->assertSame($this->frontDeskActor->id, $e->created_by);
        $this->assertNotNull($e->occurred_at);
        $this->assertNotEmpty($e->source_hash);
    }

    public function test_stay_remains_in_house(): void
    {
        $stay = $this->checkedInStay('5109');
        $this->seedB3AndB4Ready($stay);

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $stay[0]->refresh();
        $this->assertSame('IN_HOUSE', $stay[0]->status->value);
    }

    public function test_no_source_aggregate_mutation(): void
    {
        $stay = $this->checkedInStay('5110');
        $this->seedB3AndB4Ready($stay);

        $before = $this->domainTableCounts();

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $after = $this->domainTableCounts();

        $immutableTables = [
            'reservations', 'guests', 'rooms', 'room_blocks', 'work_orders',
            'engineering_room_availability_blocks', 'folios', 'folio_items',
            'journal_candidates', 'journal_candidate_lines', 'gl_journal_entries',
            'gl_journal_entry_lines', 'gl_ledger_balances', 'payment_proposals',
            'payment_proposal_items', 'payment_executions', 'cashbook_transactions',
            'controlled_bank_statement_lines', 'gl_financial_periods', 'property_business_dates',
        ];

        foreach ($immutableTables as $table) {
            if (isset($before[$table])) {
                $this->assertSame($before[$table], $after[$table],
                    "Table {$table} was mutated during checkout eligibility creation.");
            }
        }
    }
}
