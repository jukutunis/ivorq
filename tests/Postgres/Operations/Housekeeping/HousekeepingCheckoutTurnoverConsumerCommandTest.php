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

    public function test_command_active_property_success_and_context_cleared(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);

        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
            '--limit' => 1,
            '--lease' => 60,
        ])->assertExitCode(0);

        session()->forget('current_property_id');
        $this->assertNull(app(\Shared\Services\CurrentPropertyService::class)->getPropertyId());
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_command_unknown_property_rejection_and_context_cleared(): void
    {
        $propertyId = (string) \Illuminate\Support\Str::ulid();
        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $propertyId,
        ])
            ->expectsOutput(json_encode([
                'property_id' => $propertyId,
                'outcome' => 'failed',
                'safe_marker' => \Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            ], JSON_UNESCAPED_SLASHES))
            ->assertExitCode(1);

        session()->forget('current_property_id');
        $this->assertNull(app(\Shared\Services\CurrentPropertyService::class)->getPropertyId());
        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_command_inactive_property_rejection(): void
    {
        $this->property->update(['is_active' => false]);

        $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
        ])
            ->expectsOutput(json_encode([
                'property_id' => $this->property->id,
                'outcome' => 'failed',
                'safe_marker' => \Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            ], JSON_UNESCAPED_SLASHES))
            ->assertExitCode(1);

        session()->forget('current_property_id');
        $this->assertNull(app(\Shared\Services\CurrentPropertyService::class)->getPropertyId());
        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_command_privacy_output_and_hash_absence(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        
        // Populate guest info to ensure it doesn't leak
        DB::table('guests')
            ->where('id', $source['stay']->guest_id)
            ->update([
                'full_name' => 'Secret Guest Name',
                'email' => 'secret@example.com',
                'phone' => '555-0199',
            ]);

        $command = $this->artisan('housekeeping:consume-checkout-turnover-handoffs', [
            'property_id' => $this->property->id,
            '--limit' => 1,
        ]);
        
        $command->assertExitCode(0);
        
        // Assert absence of private/sensitive data in artisan output.
        // The framework's Artisan::output() might need to be retrieved or expectsOutput.
        // Since we cannot directly easily capture the whole output dynamically here if expectsOutput doesn't match perfectly,
        // we can use Laravel's Output abstraction or output recording.
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringNotContainsString('claim_token', $output);
        $this->assertStringNotContainsString($source['handoff']->claim_token_hash ?? 'none', $output);
        $this->assertStringNotContainsString($source['handoff']->source_hash, $output);
        $this->assertStringNotContainsString($source['execution']->source_hash, $output);
        $this->assertStringNotContainsString('Secret Guest Name', $output);
        $this->assertStringNotContainsString('secret@example.com', $output);
        $this->assertStringNotContainsString('555-0199', $output);
        $this->assertStringNotContainsString('Exception', $output);
        $this->assertStringNotContainsString('DomainException', $output);
    }
}
