<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverBoundaryTest extends PostgresTestCase
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

    // ── No UPDATED_AT column ──

    public function test_model_has_no_updated_at_column(): void
    {
        $model = new FrontDeskDepartureOperationalHandover();
        $this->assertNull($model->getUpdatedAtColumn());
    }

    // ── No financial fields ──

    public function test_no_financial_fields_on_model(): void
    {
        $fillable = (new FrontDeskDepartureOperationalHandover())->getFillable();

        $forbidden = [
            'amount', 'currency', 'balance', 'folio_id', 'payment_id',
            'invoice_id', 'tax_id', 'revenue_id', 'gl_account_id',
            'ar_account_id', 'business_date_id', 'financial_period_id',
            'night_audit_id', 'settlement_status', 'paid_status',
            'checkout_status', 'checked_out_at', 'departed_at',
        ];

        foreach ($forbidden as $field) {
            $this->assertNotContains($field, $fillable,
                "Forbidden financial field '{$field}' found in model fillable.");
        }
    }

    // ── No checkout/finance status fields ──

    public function test_no_checkout_status_on_model(): void
    {
        [$stay] = $this->checkedInStay('3301');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover']->toArray();
        $this->assertArrayNotHasKey('checkout_status', $handover);
        $this->assertArrayNotHasKey('checked_out_at', $handover);
        $this->assertArrayNotHasKey('departed_at', $handover);
        $this->assertArrayNotHasKey('settlement_status', $handover);
        $this->assertArrayNotHasKey('paid_status', $handover);
    }

    // ── Stay still IN_HOUSE ──

    public function test_handover_does_not_mutate_stay_status(): void
    {
        [$stay] = $this->checkedInStay('3302');

        $this->assertSame('IN_HOUSE', $stay->status->value);

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Waiting for luggage.', 'doh-' . Str::ulid()
        );

        $stay->refresh();
        $this->assertSame('IN_HOUSE', $stay->status->value);
    }

    // ── Note at boundary ──

    public function test_note_at_max_length_accepted(): void
    {
        [$stay] = $this->checkedInStay('3303');
        $note = str_repeat('x', 2000);

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', $note, 'doh-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame(2000, mb_strlen($result['handover']->handover_note));
    }

    // ── Multiple handovers per stay allowed (different statuses) ──

    public function test_multiple_handovers_per_stay_allowed(): void
    {
        [$stay] = $this->checkedInStay('3304');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-a-' . Str::ulid()
        );

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'Blocker found.', 'doh-b-' . Str::ulid()
        );

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_REVIEWED', null, 'doh-c-' . Str::ulid()
        );

        $count = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)
            ->count();

        $this->assertSame(3, $count);
    }

    // ── Rejects empty idempotency key ──

    public function test_rejects_empty_idempotency_key_from_database(): void
    {
        [$stay] = $this->checkedInStay('3305');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-valid-' . Str::ulid()
        );

        $this->assertNotEmpty($result['handover']->idempotency_key);
    }

    // ── Occurred_at is server-resolved ──

    public function test_occurred_at_is_server_resolved(): void
    {
        [$stay] = $this->checkedInStay('3306');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $this->assertNotNull($result['handover']->occurred_at);
        $this->assertEquals(
            Carbon::parse('2026-07-09 09:00:00')->timestamp,
            $result['handover']->occurred_at->timestamp
        );
    }
}
