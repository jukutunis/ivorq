<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityBoundaryTest extends PostgresTestCase
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

    public function test_no_updated_at_column(): void
    {
        $model = new FrontDeskDepartureCheckoutEligibility();
        $this->assertNull($model->getUpdatedAtColumn());
    }

    public function test_no_financial_fields(): void
    {
        $fillable = (new FrontDeskDepartureCheckoutEligibility())->getFillable();

        $forbidden = ['amount', 'currency', 'balance', 'folio_id', 'payment_id',
            'invoice_id', 'tax_id', 'revenue_id', 'gl_account_id', 'night_audit_id',
            'settlement_status', 'paid_status', 'checkout_status', 'checked_out_at'];

        foreach ($forbidden as $field) {
            $this->assertNotContains($field, $fillable);
        }
    }

    public function test_stay_remains_in_house(): void
    {
        $stay = $this->checkedInStay('5301');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_BLOCKED', 'Waiting.', 'dce-' . Str::ulid()
        );

        $stay[0]->refresh();
        $this->assertSame('IN_HOUSE', $stay[0]->status->value);
    }

    public function test_note_at_max_length_accepted(): void
    {
        $stay = $this->checkedInStay('5302');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $note = str_repeat('x', 2000);
        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', $note, 'dce-' . Str::ulid()
        );
        $this->assertFalse($result['replayed']);
        $this->assertSame(2000, mb_strlen($result['eligibility']->eligibility_note));
    }

    public function test_does_not_mutate_b4_evidence(): void
    {
        $stay = $this->checkedInStay('5303');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        $b4 = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', 'Original B4.', 'dcr-' . Str::ulid()
        );

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $b4After = \Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->whereKey($b4['readiness']->id)->first();
        $this->assertSame('CLOSURE_READY', $b4After->readiness_status->value);
        $this->assertSame('Original B4.', $b4After->readiness_note);
    }
}
