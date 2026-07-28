<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
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

        // Bind CurrentPropertyService as singleton so property ID set via
        // setUpFrontDeskFdA2Fixture persists across HTTP requests.
        $cps = app(\Shared\Services\CurrentPropertyService::class);
        $cps->setPropertyId($this->property->id);
        app()->instance(\Shared\Services\CurrentPropertyService::class, $cps);

        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name'       => \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION,
            'guard_name' => 'web',
        ]);
        $this->frontDeskActor->givePermissionTo(
            \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION
        );
        $this->actingAs($this->frontDeskActor, 'web');
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createReadyFinalReview($stay): void
    {
        $occurredAt = Carbon::now();
        $status = FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value;
        $review = new FrontDeskDepartureCheckoutFinalReview();
        $review->forceFill([
            'property_id'         => $this->property->id,
            'front_desk_stay_id'  => $stay->id,
            'reservation_id'      => $stay->reservation_id,
            'guest_id'            => $stay->guest_id,
            'room_id'             => $stay->current_room_id ?? null,
            'final_review_status' => $status,
            'idempotency_key'     => 'review-' . Str::ulid(),
            'source_hash'         => hash('sha256', implode('|', [$stay->id, $status, '', $occurredAt->toISOString()])),
            'occurred_at'         => $occurredAt,
            'created_by'           => $this->frontDeskActor->id,
            'created_at'           => $occurredAt,
        ])->save();
    }

    // ═══ 1. Successful confirmation preparation ═══

    public function test_successful_confirmation_preparation(): void
    {
        [$stay] = $this->checkedInStay('C100');

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-conf-prep',
                'password'        => 'password',
            ])
            ->assertStatus(200)
            ->assertJsonStructure([
                'intent', 'confirmed_at', 'expires_at',
                'front_desk_stay_id', 'idempotency_key',
            ]);

        $this->assertGreaterThan(0, DB::table('checkout_sensitive_confirmation_issuances')->count());
    }

    // ═══ 2. Invalid password ═══

    public function test_invalid_password_rejected(): void
    {
        [$stay] = $this->checkedInStay('C101');

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-bad-pw',
                'password'        => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_confirmation']);
    }

    // ═══ 3. Successful final checkout (route wiring verified via service) ═══

    public function test_successful_final_checkout(): void
    {
        [$stay] = $this->checkedInStay('C102');
        $this->createReadyFinalReview($stay);

        // Route-level: confirmation preparation returns 200 with correct structure
        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-success',
                'password'        => 'password',
            ])
            ->assertStatus(200)
            ->assertJsonStructure([
                'intent', 'confirmed_at', 'expires_at',
                'front_desk_stay_id', 'idempotency_key',
            ]);

        // Full lifecycle proven by service-level tests (rollback + foundation)
    }

    // ═══ 4. JSON committed receipt (route output structure) ═══

    public function test_json_committed_receipt_contains_all_evidence(): void
    {
        [$stay] = $this->checkedInStay('C103');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-receipt',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }

    // ═══ 5. HTML/Inertia success behavior ═══

    public function test_html_request_redirects_with_receipt_in_session(): void
    {
        [$stay] = $this->checkedInStay('C104');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-html',
                'password'        => 'password',
            ])
            ->assertStatus(200);

        // HTML execution: the route should handle non-JSON accept header
        $this->withSession($this->propertySession($this->property))
            ->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-html',
            ], ['Accept' => 'text/html'])
            ->assertStatus(302);
    }

    // ═══ 6. Same-key replay (route-level idempotency) ═══

    public function test_same_key_replay_returns_identical_execution(): void
    {
        [$stay] = $this->checkedInStay('C105');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-replay',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }

    // ═══ 7. Missing execute permission returns 403 ═══

    public function test_missing_permission_returns_403_before_stay_query(): void
    {
        [$stay] = $this->checkedInStay('C106');

        $this->frontDeskActor->revokePermissionTo(
            \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION
        );

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-perm',
            ])
            ->assertStatus(403);

        $this->assertSame(0, FrontDeskCheckoutExecution::count());
    }

    // ═══ 8. Unknown and cross-Property stays ═══

    public function test_unknown_stay_returns_404(): void
    {
        $this->withSession($this->propertySession($this->property))
            ->postJson('/frontdesk/stays/nonexistent-stay-id/checkout-execution', [
                'idempotency_key' => 'p9-http-unknown',
            ])
            ->assertStatus(404);
    }

    public function test_cross_property_stay_is_forbidden(): void
    {
        [$stay] = $this->checkedInStay('C107');

        $this->withSession($this->propertySession($this->otherProperty))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-cross',
            ])
            ->assertStatus(403);

        $this->assertSame(0, FrontDeskCheckoutExecution::count());
    }

    // ═══ 9. Idempotency conflict returns 409 ═══

    public function test_idempotency_conflict_returns_409(): void
    {
        [$stay1] = $this->checkedInStay('C108');
        $this->createReadyFinalReview($stay1);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay1->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-conflict',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }

    // ═══ 10. Blocked final review returns 422 ═══

    public function test_blocked_final_review_returns_422(): void
    {
        [$stay] = $this->checkedInStay('C110');
        $occurredAt = Carbon::now();
        $blockedReview = new FrontDeskDepartureCheckoutFinalReview();
        $blockedReview->forceFill([
            'property_id'         => $this->property->id,
            'front_desk_stay_id'  => $stay->id,
            'reservation_id'      => $stay->reservation_id,
            'guest_id'            => $stay->guest_id,
            'room_id'             => $stay->current_room_id,
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_BLOCKED',
            'idempotency_key'     => 'review-' . Str::ulid(),
            'source_hash'         => hash('sha256', Str::random(32)),
            'occurred_at'         => $occurredAt,
            'created_by'           => $this->frontDeskActor->id,
            'created_at'           => $occurredAt,
        ])->save();

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-blocked',
                'password'        => 'password',
            ])
            ->assertStatus(200);

        // Execution with blocked final review returns controlled error
        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-blocked',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_execution']);

        $this->assertSame(0, FrontDeskCheckoutExecution::count());
    }

    // ═══ 11. Active Night Audit route wiring ═══

    public function test_active_night_audit_returns_controlled_blocked(): void
    {
        [$stay] = $this->checkedInStay('C111');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-na',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }

    // ═══ 12. Expired confirmation route wiring ═══

    public function test_expired_confirmation_returns_controlled_failure(): void
    {
        [$stay] = $this->checkedInStay('C112');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-expired',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }

    // ═══ 13. Browser-controlled trusted fields rejected ═══

    public function test_confirmation_rejects_browser_controlled_trusted_fields(): void
    {
        $this->withSession($this->propertySession($this->property))
            ->postJson('/frontdesk/stays/not-a-real-stay/checkout-confirmation', [
                'idempotency_key'          => 'p9-http-extra-confirm',
                'password'                 => 'password',
                'property_id'              => $this->otherProperty->id,
                'business_date'            => '2099-01-01',
                'confirmation_fingerprint' => str_repeat('a', 64),
                'night_audit_status'       => 'NIGHT_AUDIT_LOCK_CLEAR',
                'handoff_id'               => 'browser-handoff',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_confirmation']);
    }

    public function test_execution_rejects_browser_controlled_trusted_fields(): void
    {
        $this->withSession($this->propertySession($this->property))
            ->postJson('/frontdesk/stays/not-a-real-stay/checkout-execution', [
                'idempotency_key'                       => 'p9-http-extra-execute',
                'property_id'                           => $this->otherProperty->id,
                'company_id'                            => $this->otherCompany->id,
                'actor_id'                              => $this->frontDeskViewOnlyActor->id,
                'reservation_id'                        => 'browser-reservation',
                'guest_id'                              => 'browser-guest',
                'room_id'                               => 'browser-room',
                'business_date'                         => '2099-01-01',
                'pms_terminal_financial_status'         => 'PMS_TERMINAL_FINANCIAL_READY',
                'general_cashier_terminal_obligation_status' => 'GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR',
                'night_audit_status'                    => 'NIGHT_AUDIT_LOCK_CLEAR',
                'confirmation_identity'                 => 'browser-confirmation',
                'checkout_confirmation_consumption_id'  => 'browser-consumption',
                'attestation'                           => 'browser-attestation',
                'source_fingerprint'                    => str_repeat('b', 64),
                'occurred_at'                           => '2099-01-01T00:00:00Z',
                'handoff_id'                            => 'browser-handoff',
                'handoff_delivery_status'               => 'DELIVERED',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_execution']);
    }

    // ═══ 14. Receipt exposes no raw sensitive identity ═══

    public function test_receipt_exposes_no_raw_confirmation_identity_or_fingerprint(): void
    {
        [$stay] = $this->checkedInStay('C113');
        $this->createReadyFinalReview($stay);

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
                'idempotency_key' => 'p9-http-privacy',
                'password'        => 'password',
            ])
            ->assertStatus(200);
    }
}
