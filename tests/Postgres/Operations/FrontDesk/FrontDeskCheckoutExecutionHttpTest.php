<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionHttpTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontDeskFdA2Fixture();
        $this->actingAs($this->frontDeskActor, 'web');
    }

    public function test_confirmation_rejects_browser_controlled_trusted_fields_before_execution_context_is_used(): void
    {
        $response = $this
            ->withSession($this->propertySession($this->property))
            ->postJson('/frontdesk/stays/not-a-real-stay/checkout-confirmation', [
                'idempotency_key' => 'p9-http-extra-confirm',
                'password' => 'password',
                'property_id' => $this->otherProperty->id,
                'business_date' => '2099-01-01',
                'confirmation_fingerprint' => str_repeat('a', 64),
                'night_audit_status' => 'NIGHT_AUDIT_LOCK_CLEAR',
                'handoff_id' => 'browser-handoff',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['checkout_confirmation']);
        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_issuances')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_executions')->count());
    }

    public function test_execution_rejects_browser_controlled_trusted_fields_before_execution_context_is_used(): void
    {
        $response = $this
            ->withSession($this->propertySession($this->property))
            ->postJson('/frontdesk/stays/not-a-real-stay/checkout-execution', [
                'idempotency_key' => 'p9-http-extra-execute',
                'property_id' => $this->otherProperty->id,
                'company_id' => $this->otherCompany->id,
                'actor_id' => $this->frontDeskViewOnlyActor->id,
                'reservation_id' => 'browser-reservation',
                'guest_id' => 'browser-guest',
                'room_id' => 'browser-room',
                'business_date' => '2099-01-01',
                'pms_terminal_financial_status' => 'PMS_TERMINAL_FINANCIAL_READY',
                'general_cashier_terminal_obligation_status' => 'GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR',
                'night_audit_status' => 'NIGHT_AUDIT_LOCK_CLEAR',
                'confirmation_identity' => 'browser-confirmation',
                'checkout_confirmation_consumption_id' => 'browser-consumption',
                'attestation' => 'browser-attestation',
                'source_fingerprint' => str_repeat('b', 64),
                'occurred_at' => '2099-01-01T00:00:00Z',
                'handoff_id' => 'browser-handoff',
                'handoff_delivery_status' => 'DELIVERED',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['checkout_execution']);
        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_executions')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_housekeeping_handoffs')->count());
    }
}
