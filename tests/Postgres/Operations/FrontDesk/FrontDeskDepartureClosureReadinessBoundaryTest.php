<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessBoundaryTest extends PostgresTestCase
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

    // ── No UPDATED_AT column ──

    public function test_model_has_no_updated_at_column(): void
    {
        $model = new FrontDeskDepartureClosureReadiness();
        $this->assertNull($model->getUpdatedAtColumn());
    }

    // ── No financial fields ──

    public function test_no_financial_fields_on_model(): void
    {
        $fillable = (new FrontDeskDepartureClosureReadiness())->getFillable();

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
        [$stay] = $this->checkedInStay('4301');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness']->toArray();
        $this->assertArrayNotHasKey('checkout_status', $readiness);
        $this->assertArrayNotHasKey('checked_out_at', $readiness);
        $this->assertArrayNotHasKey('departed_at', $readiness);
        $this->assertArrayNotHasKey('settlement_status', $readiness);
        $this->assertArrayNotHasKey('paid_status', $readiness);
    }

    // ── Stay still IN_HOUSE ──

    public function test_closure_readiness_does_not_mutate_stay_status(): void
    {
        [$stay] = $this->checkedInStay('4302');

        $this->assertSame('IN_HOUSE', $stay->status->value);

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Waiting for confirmation.', 'dcr-' . Str::ulid()
        );

        $stay->refresh();
        $this->assertSame('IN_HOUSE', $stay->status->value);
    }

    // ── Note at boundary ──

    public function test_note_at_max_length_accepted(): void
    {
        [$stay] = $this->checkedInStay('4303');
        $note = str_repeat('x', 2000);

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', $note, 'dcr-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame(2000, mb_strlen($result['readiness']->readiness_note));
    }

    // ── Multiple readiness per stay allowed ──

    public function test_multiple_readiness_per_stay_allowed(): void
    {
        [$stay] = $this->checkedInStay('4304');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-a-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_BLOCKED', 'Blocker found.', 'dcr-b-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', null, 'dcr-c-' . Str::ulid()
        );

        $count = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)
            ->count();

        $this->assertSame(3, $count);
    }

    // ── Rejects empty idempotency key ──

    public function test_rejects_empty_idempotency_key_from_database(): void
    {
        [$stay] = $this->checkedInStay('4305');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-valid-' . Str::ulid()
        );

        $this->assertNotEmpty($result['readiness']->idempotency_key);
    }

    // ── Occurred_at is server-resolved ──

    public function test_occurred_at_is_server_resolved(): void
    {
        [$stay] = $this->checkedInStay('4306');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $this->assertNotNull($result['readiness']->occurred_at);
        $this->assertEquals(
            Carbon::parse('2026-07-10 09:00:00')->timestamp,
            $result['readiness']->occurred_at->timestamp
        );
    }

    // ── Does not mutate B3 handover evidence ──

    public function test_does_not_mutate_b3_handover_evidence(): void
    {
        [$stay] = $this->checkedInStay('4307');

        $b3Result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'Original B3 note.', 'doh-' . Str::ulid()
        );

        $b3Id = $b3Result['handover']->id;
        $b3StatusBefore = $b3Result['handover']->handover_status->value;
        $b3NoteBefore = $b3Result['handover']->handover_note;

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $b3After = \Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->whereKey($b3Id)->first();

        $this->assertSame($b3StatusBefore, $b3After->handover_status->value);
        $this->assertSame($b3NoteBefore, $b3After->handover_note);
    }
}
