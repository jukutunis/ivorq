<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-09 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Handover creation ──

    public function test_create_operational_handover_ready(): void
    {
        [$stay] = $this->checkedInStay('3101');
        $idempotencyKey = 'doh-' . Str::ulid();

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor,
            $stay->id,
            'OPERATIONAL_HANDOVER_READY',
            'All operational checks passed.',
            $idempotencyKey
        );

        $this->assertFalse($result['replayed']);
        $this->assertInstanceOf(FrontDeskDepartureOperationalHandover::class, $result['handover']);
        $this->assertSame('OPERATIONAL_HANDOVER_READY', $result['handover']->handover_status->value);
        $this->assertSame('All operational checks passed.', $result['handover']->handover_note);
        $this->assertSame($stay->id, $result['handover']->front_desk_stay_id);
        $this->assertSame($stay->reservation_id, $result['handover']->reservation_id);
        $this->assertSame($stay->guest_id, $result['handover']->guest_id);
        $this->assertSame($stay->current_room_id, $result['handover']->room_id);
        $this->assertSame($this->frontDeskActor->id, $result['handover']->created_by);
        $this->assertSame($idempotencyKey, $result['handover']->idempotency_key);
        $this->assertNotEmpty($result['handover']->source_hash);
        $this->assertNotNull($result['handover']->occurred_at);
    }

    public function test_create_all_allowed_handover_statuses(): void
    {
        [$stay] = $this->checkedInStay('3102');

        $statuses = [
            'OPERATIONAL_HANDOVER_READY',
            'OPERATIONAL_HANDOVER_BLOCKED',
            'OPERATIONAL_HANDOVER_REVIEWED',
        ];

        foreach ($statuses as $status) {
            $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
                $this->frontDeskActor,
                $stay->id,
                $status,
                null,
                'doh-' . Str::ulid()
            );

            $this->assertFalse($result['replayed']);
            $this->assertSame($status, $result['handover']->handover_status->value);
        }

        $this->assertSame(3, FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)
            ->count());
    }

    public function test_handover_without_note(): void
    {
        [$stay] = $this->checkedInStay('3103');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_REVIEWED', null, 'doh-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertNull($result['handover']->handover_note);
    }

    // ── Idempotency ──

    public function test_duplicate_idempotency_key_returns_existing_handover(): void
    {
        [$stay] = $this->checkedInStay('3104');
        $key = 'doh-idem-' . Str::ulid();

        $first = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'First attempt.', $key
        );

        $second = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Second attempt with different status.', $key
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['handover']->id, $second['handover']->id);
        $this->assertSame('OPERATIONAL_HANDOVER_READY', $second['handover']->handover_status->value);

        $this->assertSame(1, FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('idempotency_key', $key)->count());
    }

    public function test_duplicate_source_hash_returns_existing_handover(): void
    {
        [$stay] = $this->checkedInStay('3105');
        $key1 = 'doh-sh-1-' . Str::ulid();
        $key2 = 'doh-sh-2-' . Str::ulid();

        $first = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'Same source content.', $key1
        );

        $second = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'Same source content.', $key2
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['handover']->id, $second['handover']->id);
    }

    // ── IN_HOUSE stay required ──

    public function test_rejects_non_in_house_stay(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor,
            (string) Str::ulid(),
            'OPERATIONAL_HANDOVER_READY',
            null,
            'doh-' . Str::ulid()
        );
    }

    // ── Reject forbidden statuses ──

    public function test_rejects_forbidden_checkout_statuses(): void
    {
        [$stay] = $this->checkedInStay('3106');

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
                app(FrontDeskDepartureOperationalHandoverService::class)->create(
                    $this->frontDeskActor, $stay->id, $status, null, 'doh-' . Str::ulid()
                );
                $this->fail("Expected DomainException for forbidden status: {$status}");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Invalid handover status', $e->getMessage());
            }
        }

        $this->assertSame(0, FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)->count());
    }

    // ── Note bounded ──

    public function test_rejects_note_exceeding_max_length(): void
    {
        [$stay] = $this->checkedInStay('3107');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Handover note must not exceed');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY',
            str_repeat('x', 2001),
            'doh-' . Str::ulid()
        );
    }

    // ── Stay remains IN_HOUSE ──

    public function test_stay_remains_in_house_after_handover(): void
    {
        [$stay] = $this->checkedInStay('3108');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $stay->refresh();
        $this->assertSame('IN_HOUSE', $stay->status->value);
    }

    // ── Browser cannot control server-owned fields ──

    public function test_server_resolved_fields_not_browser_controllable(): void
    {
        [$stay] = $this->checkedInStay('3109');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover'];

        $this->assertSame($this->property->id, $handover->property_id);
        $this->assertSame($stay->reservation_id, $handover->reservation_id);
        $this->assertSame($stay->guest_id, $handover->guest_id);
        $this->assertSame($stay->current_room_id, $handover->room_id);
        $this->assertSame($this->frontDeskActor->id, $handover->created_by);
        $this->assertNotNull($handover->occurred_at);
        $this->assertNotEmpty($handover->source_hash);
    }

    // ── No Housekeeping/Engineering/Reservation/Room mutation ──

    public function test_no_source_aggregate_mutation(): void
    {
        [$stay] = $this->checkedInStay('3110');

        $before = $this->domainTableCounts();

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
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
                    "Table {$table} was mutated during handover creation.");
            }
        }
    }
}
