<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── CLOSURE_READY after B3 OPERATIONAL_HANDOVER_READY ──

    public function test_can_record_closure_ready_when_b3_is_ready(): void
    {
        [$stay] = $this->checkedInStay('4101');

        // Record B3 handover as ready first
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'B3 ready.', 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'B4 closure ready.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertInstanceOf(FrontDeskDepartureClosureReadiness::class, $result['readiness']);
        $this->assertSame('CLOSURE_READY', $result['readiness']->readiness_status->value);
        $this->assertSame('B4 closure ready.', $result['readiness']->readiness_note);
        $this->assertSame($stay->id, $result['readiness']->front_desk_stay_id);
        $this->assertSame($stay->reservation_id, $result['readiness']->reservation_id);
        $this->assertSame($stay->guest_id, $result['readiness']->guest_id);
        $this->assertSame($stay->current_room_id, $result['readiness']->room_id);
        $this->assertSame($this->frontDeskActor->id, $result['readiness']->created_by);
        $this->assertNotEmpty($result['readiness']->source_hash);
        $this->assertNotNull($result['readiness']->occurred_at);
    }

    // ── CLOSURE_REVIEWED ──

    public function test_can_record_closure_reviewed(): void
    {
        [$stay] = $this->checkedInStay('4102');

        // Record B3 handover first
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_REVIEWED', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', 'Reviewed for closure.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_REVIEWED', $result['readiness']->readiness_status->value);
    }

    // ── CLOSURE_BLOCKED ──

    public function test_can_record_closure_blocked(): void
    {
        [$stay] = $this->checkedInStay('4103');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Blocked: awaiting luggage confirmation.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_BLOCKED', $result['readiness']->readiness_status->value);
    }

    // ── All three allowed statuses ──

    public function test_create_all_allowed_readiness_statuses(): void
    {
        [$stay] = $this->checkedInStay('4104');

        // B3 ready required for CLOSURE_READY
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $statuses = [
            'CLOSURE_READY',
            'CLOSURE_BLOCKED',
            'CLOSURE_REVIEWED',
        ];

        foreach ($statuses as $status) {
            $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
                $this->frontDeskActor, $stay->id, $status, null, 'dcr-' . Str::ulid()
            );

            $this->assertFalse($result['replayed']);
            $this->assertSame($status, $result['readiness']->readiness_status->value);
        }

        $this->assertSame(3, FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)->count());
    }

    // ── CLOSURE_BLOCKED allowed when B3 is blocked ──

    public function test_closure_blocked_allowed_when_b3_is_blocked(): void
    {
        [$stay] = $this->checkedInStay('4105');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Blocked by engineering.', 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Closure blocked: engineering issue.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_BLOCKED', $result['readiness']->readiness_status->value);
    }

    // ── CLOSURE_REVIEWED allowed when B3 is blocked ──

    public function test_closure_reviewed_allowed_when_b3_is_blocked(): void
    {
        [$stay] = $this->checkedInStay('4106');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Blocked.', 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', 'Reviewed despite B3 block.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_REVIEWED', $result['readiness']->readiness_status->value);
    }

    // ── CLOSURE_READY rejected when B3 is blocked ──

    public function test_closure_ready_rejected_when_b3_is_blocked(): void
    {
        [$stay] = $this->checkedInStay('4107');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Blocked.', 'doh-' . Str::ulid()
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not be blocked');

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'Should be rejected.', 'dcr-' . Str::ulid()
        );
    }

    // ── CLOSURE_READY rejected when no B3 evidence exists ──

    public function test_closure_ready_rejected_when_no_b3_evidence(): void
    {
        [$stay] = $this->checkedInStay('4108');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No handover evidence found');

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'Should be rejected.', 'dcr-' . Str::ulid()
        );
    }

    // ── CLOSURE_REVIEWED allowed without B3 evidence ──

    public function test_closure_reviewed_allowed_without_b3_evidence(): void
    {
        [$stay] = $this->checkedInStay('4109');

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', 'Reviewed, no B3 evidence yet.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_REVIEWED', $result['readiness']->readiness_status->value);
    }

    // ── CLOSURE_BLOCKED allowed without B3 evidence ──

    public function test_closure_blocked_allowed_without_b3_evidence(): void
    {
        [$stay] = $this->checkedInStay('4110');

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Blocked, no B3 evidence yet.', 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('CLOSURE_BLOCKED', $result['readiness']->readiness_status->value);
    }

    // ── Readiness without note ──

    public function test_readiness_without_note(): void
    {
        [$stay] = $this->checkedInStay('4111');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', null, 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertNull($result['readiness']->readiness_note);
    }

    // ── Idempotency ──

    public function test_duplicate_idempotency_key_returns_existing_readiness(): void
    {
        [$stay] = $this->checkedInStay('4112');
        $key = 'dcr-idem-' . Str::ulid();

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $first = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'First attempt.', $key
        );

        $second = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Second attempt.', $key
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['readiness']->id, $second['readiness']->id);
        $this->assertSame('CLOSURE_READY', $second['readiness']->readiness_status->value);

        $this->assertSame(1, FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('idempotency_key', $key)->count());
    }

    public function test_duplicate_source_hash_returns_existing_readiness(): void
    {
        [$stay] = $this->checkedInStay('4113');
        $key1 = 'dcr-sh-1-' . Str::ulid();
        $key2 = 'dcr-sh-2-' . Str::ulid();

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $first = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'Same source.', $key1
        );

        $second = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'Same source.', $key2
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['readiness']->id, $second['readiness']->id);
    }

    // ── IN_HOUSE stay required ──

    public function test_rejects_non_in_house_stay(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor,
            (string) Str::ulid(),
            'CLOSURE_REVIEWED',
            null,
            'dcr-' . Str::ulid()
        );
    }

    // ── Reject forbidden statuses ──

    public function test_rejects_forbidden_checkout_statuses(): void
    {
        [$stay] = $this->checkedInStay('4114');

        $forbidden = [
            'CHECKOUT_READY',
            'READY_FOR_CHECKOUT',
            'CHECKOUT_EXECUTED',
            'CHECKED_OUT',
            'SETTLED',
            'DEPARTED',
            'PAYMENT_READY',
            'PAYMENT_TAKEN',
            'FOLIO_CLOSED',
            'BALANCE_CLEARED',
            'INVOICE_GENERATED',
            'REVENUE_POSTED',
            'TAX_POSTED',
            'AR_CLEARED',
            'GL_POSTED',
            'NIGHT_AUDIT_READY',
        ];

        foreach ($forbidden as $status) {
            try {
                app(FrontDeskDepartureClosureReadinessService::class)->create(
                    $this->frontDeskActor, $stay->id, $status, null, 'dcr-' . Str::ulid()
                );
                $this->fail("Expected DomainException for forbidden status: {$status}");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Invalid readiness status', $e->getMessage());
            }
        }

        $this->assertSame(0, FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)->count());
    }

    // ── Note bounded ──

    public function test_rejects_note_exceeding_max_length(): void
    {
        [$stay] = $this->checkedInStay('4115');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Readiness note must not exceed');

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED',
            str_repeat('x', 2001),
            'dcr-' . Str::ulid()
        );
    }

    // ── Stay remains IN_HOUSE ──

    public function test_stay_remains_in_house_after_closure_readiness(): void
    {
        [$stay] = $this->checkedInStay('4116');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $stay->refresh();
        $this->assertSame('IN_HOUSE', $stay->status->value);
    }

    // ── Server-resolved fields ──

    public function test_server_resolved_fields_not_browser_controllable(): void
    {
        [$stay] = $this->checkedInStay('4117');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness'];

        $this->assertSame($this->property->id, $readiness->property_id);
        $this->assertSame($stay->reservation_id, $readiness->reservation_id);
        $this->assertSame($stay->guest_id, $readiness->guest_id);
        $this->assertSame($stay->current_room_id, $readiness->room_id);
        $this->assertSame($this->frontDeskActor->id, $readiness->created_by);
        $this->assertNotNull($readiness->occurred_at);
        $this->assertNotEmpty($readiness->source_hash);
    }

    // ── No source aggregate mutation ──

    public function test_no_source_aggregate_mutation(): void
    {
        [$stay] = $this->checkedInStay('4118');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $before = $this->domainTableCounts();

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
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
                    "Table {$table} was mutated during closure readiness creation.");
            }
        }
    }
}
