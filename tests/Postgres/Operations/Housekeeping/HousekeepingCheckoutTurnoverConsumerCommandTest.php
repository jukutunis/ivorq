<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverConsumerCommandTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingCheckoutTurnoverIntakeData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutTurnoverFixture();
    }

    public function test_command_returns_safe_zero_counts_when_no_handoff_is_available(): void
    {
        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
            '--limit' => 25,
            '--lease' => 60,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_command_consumes_one_handoff_and_does_not_output_tokens(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);

        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
            '--limit' => 1,
            '--lease' => 60,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('front_desk_checkout_housekeeping_handoffs', [
            'id' => $source['handoff']->id,
            'delivery_status' => 'DELIVERED',
        ]);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_command_hard_caps_limit_and_lease_inputs(): void
    {
        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
            '--limit' => 500,
            '--lease' => 999,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }
}
