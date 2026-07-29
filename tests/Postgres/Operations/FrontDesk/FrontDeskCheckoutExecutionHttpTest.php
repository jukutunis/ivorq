<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationIssuance;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionHttpTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use DatabaseMigrations;

    private ?string $browserSessionId = null;

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
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name'       => NightAuditAuthorizationService::START_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->frontDeskActor->givePermissionTo(
            \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION
        );
        $this->frontDeskActor->givePermissionTo(NightAuditAuthorizationService::START_PERMISSION);
        $this->createValidAuthoritativeBusinessDate();
        $this->bindClearPmsParticipationPorts();
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

        $this->createZeroBalanceOpenFolio($stay);
    }

    private function createZeroBalanceOpenFolio($stay): void
    {
        if (Folio::withoutGlobalScope('property')
            ->where('property_id', $this->property->id)
            ->where('reservation_id', $stay->reservation_id)
            ->exists()) {
            return;
        }

        $folio = new Folio();
        $folio->forceFill([
            'property_id' => $this->property->id,
            'folio_number' => 'P9H-' . Str::upper(Str::random(8)),
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'status' => FolioStatusEnum::Open->value,
            'currency' => $this->property->currency ?? 'USD',
            'window_number' => 1,
            'opening_idempotency_key' => 'p9-http-folio-' . Str::ulid(),
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
            'created_by' => $this->frontDeskActor->id,
            'updated_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    private function checkoutMutationCounts(): array
    {
        return [
            'consumptions' => DB::table('checkout_sensitive_confirmation_consumptions')->count(),
            'executions' => FrontDeskCheckoutExecution::count(),
            'handoffs' => FrontDeskCheckoutHousekeepingHandoff::count(),
            'checked_out_stays' => DB::table('front_desk_stays')
                ->where('status', FrontDeskStayStatusEnum::CheckedOut->value)
                ->count(),
            'completion_audits' => DB::table('audit_logs')
                ->where('event', 'front_desk_checkout_completed')
                ->count(),
        ];
    }

    private function assertCheckoutMutationCounts(
        int $consumptions,
        int $executions,
        int $handoffs,
        int $checkedOutStays,
        int $completionAudits
    ): void {
        $this->assertSame([
            'consumptions' => $consumptions,
            'executions' => $executions,
            'handoffs' => $handoffs,
            'checked_out_stays' => $checkedOutStays,
            'completion_audits' => $completionAudits,
        ], $this->checkoutMutationCounts());
    }

    private function foreignDomainSnapshot($stay): array
    {
        return [
            'folio' => DB::table('folios')
                ->where('property_id', $stay->property_id)
                ->where('reservation_id', $stay->reservation_id)
                ->first(),
            'night_audit_runs' => DB::table('night_audit_runs')->count(),
            'cashier_sessions' => DB::table('cashier_sessions')->count(),
            'guest_payment_transactions' => DB::table('guest_payment_transactions')->count(),
            'guest_deposit_transactions' => DB::table('guest_deposit_transactions')->count(),
            'guest_refund_transactions' => DB::table('guest_refund_transactions')->count(),
        ];
    }

    private function assertNoForeignDomainLifecycleMutation(array $before, $stay): void
    {
        $folio = DB::table('folios')
            ->where('property_id', $stay->property_id)
            ->where('reservation_id', $stay->reservation_id)
            ->first();

        $this->assertNotNull($before['folio']);
        $this->assertNotNull($folio);
        $this->assertSame($before['folio']->id, $folio->id);
        $this->assertSame($before['folio']->status, $folio->status);
        $this->assertSame((string) $before['folio']->balance, (string) $folio->balance);
        $this->assertSame($before['night_audit_runs'], DB::table('night_audit_runs')->count());
        $this->assertSame($before['cashier_sessions'], DB::table('cashier_sessions')->count());
        $this->assertSame($before['guest_payment_transactions'], DB::table('guest_payment_transactions')->count());
        $this->assertSame($before['guest_deposit_transactions'], DB::table('guest_deposit_transactions')->count());
        $this->assertSame($before['guest_refund_transactions'], DB::table('guest_refund_transactions')->count());
    }

    private function bindClearPmsParticipationPorts(): void
    {
        $clearResult = static fn (string $fingerprint, string $reservationId, string $propertyId): array => [
            'status' => 'AVAILABLE_CLEAR',
            'code' => null,
            'source_fingerprint' => hash('sha256', $fingerprint . '|' . $propertyId . '|' . $reservationId),
            'source_identifiers' => [],
        ];

        app()->singleton(GuestLedgerPostingCompletenessParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerPostingCompletenessParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-http-posting-completeness', $reservationId, $propertyId); }
        });

        app()->singleton(GuestLedgerSettlementHoldParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerSettlementHoldParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-http-settlement-hold', $reservationId, $propertyId); }
        });

        app()->singleton(GuestLedgerCompletedSettlementConflictParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-http-completed-settlement', $reservationId, $propertyId); }
        });
    }

    private function usePropertySession($property): void
    {
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);
        session($this->propertySession($property));
        $this->browserSessionId ??= session()->getId();
        $this->withCookie((string) config('session.cookie'), $this->browserSessionId)
            ->withCredentials();
    }

    private function installCheckoutConfirmationSession($stay, string $idempotencyKey, ?Carbon $confirmedAt = null, ?Carbon $expiresAt = null): CheckoutSensitiveConfirmationIssuance
    {
        $this->usePropertySession($this->property);

        $confirmedAt ??= Carbon::parse(DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc")->wall_clock_utc);
        $expiresAt ??= $confirmedAt->copy()->addMinutes(15);
        $identity = (string) Str::ulid();
        $sessionFingerprint = CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId());
        $confirmationFingerprint = hash('sha256', implode('|', [
            CheckoutSensitiveConfirmationService::INTENT,
            $identity,
            $this->frontDeskActor->id,
            $this->property->company_id,
            $this->property->id,
            $stay->id,
            $idempotencyKey,
            $sessionFingerprint,
            $confirmedAt->toISOString(),
            $expiresAt->toISOString(),
        ]));

        $issuance = new CheckoutSensitiveConfirmationIssuance();
        $issuance->forceFill([
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $this->frontDeskActor->id,
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => $sessionFingerprint,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ])->save();

        session([
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id' => $this->frontDeskActor->id,
                    'intent' => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id' => $this->property->company_id,
                    'property_id' => $this->property->id,
                    'front_desk_stay_id' => $stay->id,
                    'checkout_idempotency_key' => $idempotencyKey,
                    'issuance_id' => $issuance->id,
                    'confirmation_identity' => $identity,
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'session_fingerprint' => $sessionFingerprint,
                    'confirmed_at' => $confirmedAt->toISOString(),
                    'expires_at' => $expiresAt->toISOString(),
                ],
            ],
        ]);

        return $issuance->fresh();
    }

    private function waitUntilDatabaseTimeAfter(Carbon $expiresAt): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $dbNow = Carbon::parse(DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc")->wall_clock_utc);
            if ($dbNow->greaterThan($expiresAt)) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Database clock did not pass checkout confirmation expiry.');
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmThenExecuteJson($stay, string $idempotencyKey): array
    {
        $this->usePropertySession($this->property);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
            'idempotency_key' => $idempotencyKey,
            'password'        => 'password',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonStructure([
                'intent', 'confirmed_at', 'expires_at',
                'front_desk_stay_id', 'idempotency_key',
            ]);

        $this->usePropertySession($this->property);

        $response = $this->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
            'idempotency_key' => $idempotencyKey,
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJson([
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'idempotency_key' => $idempotencyKey,
                'terminal_status' => FrontDeskStayStatusEnum::CheckedOut->value,
                'night_audit_status' => 'NIGHT_AUDIT_LOCK_CLEAR',
                'pms_terminal_financial_status' => 'PMS_TERMINAL_FINANCIAL_READY',
                'general_cashier_terminal_obligation_status' => 'GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR',
                'replayed' => false,
            ])
            ->assertJsonStructure([
                'property_id',
                'front_desk_stay_id',
                'reservation_id',
                'checkout_execution_id',
                'idempotency_key',
                'terminal_status',
                'business_date',
                'occurred_at',
                'handoff_id',
                'handoff_delivery_status',
                'night_audit_status',
                'pms_terminal_financial_status',
                'general_cashier_terminal_obligation_status',
                'replayed',
            ]);

        return $response->json();
    }

    private function insertExpiredCheckoutConfirmation($stay, string $idempotencyKey): void
    {
        $this->usePropertySession($this->property);

        $id = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $confirmedAt = Carbon::now()->subMinutes(20);
        $expiresAt = Carbon::now()->subMinute();
        $sessionFingerprint = CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId());
        $confirmationFingerprint = hash('sha256', implode('|', [
            CheckoutSensitiveConfirmationService::INTENT,
            $identity,
            $this->frontDeskActor->id,
            $this->property->company_id,
            $this->property->id,
            $stay->id,
            $idempotencyKey,
            $sessionFingerprint,
            $confirmedAt->toISOString(),
            $expiresAt->toISOString(),
        ]));

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $id,
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $this->frontDeskActor->id,
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => $sessionFingerprint,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        session([
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id' => $this->frontDeskActor->id,
                    'intent' => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id' => $this->property->company_id,
                    'property_id' => $this->property->id,
                    'front_desk_stay_id' => $stay->id,
                    'checkout_idempotency_key' => $idempotencyKey,
                    'issuance_id' => $id,
                    'confirmation_identity' => $identity,
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'session_fingerprint' => $sessionFingerprint,
                    'confirmed_at' => $confirmedAt->toISOString(),
                    'expires_at' => $expiresAt->toISOString(),
                ],
            ],
        ]);
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
        $foreignBefore = $this->foreignDomainSnapshot($stay);

        $receipt = $this->confirmThenExecuteJson($stay, 'p9-http-success');
        $execution = FrontDeskCheckoutExecution::firstOrFail();
        $handoff = FrontDeskCheckoutHousekeepingHandoff::firstOrFail();
        $consumption = CheckoutSensitiveConfirmationConsumption::firstOrFail();

        $this->assertCheckoutMutationCounts(1, 1, 1, 1, 1);
        $this->assertSame($receipt['checkout_execution_id'], $execution->id);
        $this->assertSame($receipt['handoff_id'], $handoff->id);
        $this->assertSame(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending->value, $handoff->delivery_status->value);
        $this->assertSame($execution->checkout_confirmation_consumption_id, $consumption->id);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $stay->fresh()->status);
        $this->assertNoForeignDomainLifecycleMutation($foreignBefore, $stay);
    }

    // ═══ 4. JSON committed receipt (route output structure) ═══

    public function test_json_committed_receipt_contains_all_evidence(): void
    {
        [$stay] = $this->checkedInStay('C103');
        $this->createReadyFinalReview($stay);

        $receipt = $this->confirmThenExecuteJson($stay, 'p9-http-receipt');

        $execution = FrontDeskCheckoutExecution::firstOrFail();
        $handoff = FrontDeskCheckoutHousekeepingHandoff::firstOrFail();

        $this->assertSame($execution->id, $receipt['checkout_execution_id']);
        $this->assertSame($execution->property_id, $receipt['property_id']);
        $this->assertSame($execution->business_date->format('Y-m-d'), $receipt['business_date']);
        $this->assertSame($execution->terminal_stay_status->value, $receipt['terminal_status']);
        $this->assertSame($execution->night_audit_source_status, $receipt['night_audit_status']);
        $this->assertSame($execution->pms_financial_attestation_status, $receipt['pms_terminal_financial_status']);
        $this->assertSame($execution->general_cashier_attestation_status, $receipt['general_cashier_terminal_obligation_status']);
        $this->assertSame($handoff->id, $receipt['handoff_id']);
        $this->assertSame(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending->value, $receipt['handoff_delivery_status']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
        $this->assertNotEmpty($receipt['occurred_at']);
    }

    // ═══ 5. HTML/Inertia success behavior ═══

    public function test_html_request_redirects_with_receipt_in_session(): void
    {
        [$stay] = $this->checkedInStay('C104');
        $this->createReadyFinalReview($stay);

        $this->usePropertySession($this->property);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
            'idempotency_key' => 'p9-http-html',
            'password'        => 'password',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $this->usePropertySession($this->property);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-html',
            ], ['Accept' => 'text/html'])
            ->assertStatus(302)
            ->assertSessionHas('checkoutExecutionReceipt')
            ->assertSessionDoesntHaveErrors(['checkout_execution']);

        $receipt = session('checkoutExecutionReceipt');
        $execution = FrontDeskCheckoutExecution::firstOrFail();
        $handoff = FrontDeskCheckoutHousekeepingHandoff::firstOrFail();
        $this->assertIsArray($receipt);
        $this->assertSame($execution->id, $receipt['checkout_execution_id'] ?? null);
        $this->assertSame($handoff->id, $receipt['handoff_id'] ?? null);
        $this->assertSame($stay->id, $receipt['front_desk_stay_id'] ?? null);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut->value, $receipt['terminal_status'] ?? null);
        $this->assertCheckoutMutationCounts(1, 1, 1, 1, 1);
    }

    // ═══ 6. Same-key replay (route-level idempotency) ═══

    public function test_same_key_replay_returns_identical_execution(): void
    {
        [$stay] = $this->checkedInStay('C105');
        $this->createReadyFinalReview($stay);

        $first = $this->confirmThenExecuteJson($stay, 'p9-http-replay');

        $this->usePropertySession($this->property);

        $second = $this->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
            'idempotency_key' => 'p9-http-replay',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJson([
                'checkout_execution_id' => $first['checkout_execution_id'],
                'handoff_id' => $first['handoff_id'],
                'replayed' => true,
            ]);

        $this->assertSame($first['checkout_execution_id'], $second->json('checkout_execution_id'));
        $this->assertSame($first['handoff_id'], $second->json('handoff_id'));
        $this->assertCheckoutMutationCounts(1, 1, 1, 1, 1);
    }

    // ═══ 7. Missing execute permission returns 403 ═══

    public function test_missing_permission_returns_403_before_stay_query(): void
    {
        [$stay] = $this->checkedInStay('C106');
        $requestedStayQueries = 0;

        $this->frontDeskActor->revokePermissionTo(
            \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::listen(function (QueryExecuted $query) use ($stay, &$requestedStayQueries): void {
            if (str_contains(strtolower($query->sql), 'front_desk_stays')
                && in_array($stay->id, array_map(static fn ($binding) => (string) $binding, $query->bindings), true)) {
                $requestedStayQueries++;
            }
        });

        $this->withSession($this->propertySession($this->property))
            ->postJson("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-perm',
            ])
            ->assertStatus(403);

        $this->assertSame(0, $requestedStayQueries);
        $this->assertCheckoutMutationCounts(0, 0, 0, 0, 0);
    }

    // ═══ 8. Unknown and cross-Property stays ═══

    public function test_unknown_and_authorized_cross_property_stays_are_non_disclosing_equivalent(): void
    {
        $guestId = $this->guest($this->otherProperty, 'Cross Property Guest');
        $roomId = $this->room($this->otherProperty, 'C107X');
        $reservationId = $this->reservation($this->otherProperty, $guestId, 'RES-CROSS-' . Str::upper(Str::random(4)), 'checked_in', $roomId);
        $stay = new \Modules\Operations\FrontDesk\Models\FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'current_room_id' => $roomId,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->frontDeskActor->id,
            'updated_by' => $this->frontDeskActor->id,
        ])->save();
        $stay = $stay->fresh();

        $unknownStayId = (string) Str::ulid();

        $this->usePropertySession($this->property);
        $unknown = $this->postJson("/frontdesk/stays/{$unknownStayId}/checkout-execution", [
                'idempotency_key' => 'p9-http-unknown',
            ]);

        $this->usePropertySession($this->property);
        $crossProperty = $this->postJson("/frontdesk/stays/{$stay->id}/checkout-execution", [
                'idempotency_key' => 'p9-http-cross',
            ]);

        $unknown->assertStatus(404);
        $crossProperty->assertStatus($unknown->getStatusCode());
        $this->assertSame(array_keys($unknown->json()), array_keys($crossProperty->json()));
        $this->assertSame($unknown->json('message'), $crossProperty->json('message'));

        $encoded = json_encode($crossProperty->json(), JSON_THROW_ON_ERROR);
        foreach ([$stay->id, $stay->reservation_id, $stay->guest_id, $stay->current_room_id] as $sensitiveId) {
            if (is_string($sensitiveId)) {
                $this->assertStringNotContainsString($sensitiveId, $encoded);
            }
        }
        $this->assertCheckoutMutationCounts(0, 0, 0, 0, 0);
    }

    // ═══ 9. Idempotency conflict returns 409 ═══

    public function test_idempotency_conflict_returns_409(): void
    {
        [$stay1] = $this->checkedInStay('C108');
        [$stay2] = $this->checkedInStay('C109');
        $this->createReadyFinalReview($stay1);
        $this->createReadyFinalReview($stay2);

        $firstReceipt = $this->confirmThenExecuteJson($stay1, 'p9-http-conflict');

        $this->installCheckoutConfirmationSession($stay2, 'p9-http-conflict');

        $response = $this->post("/frontdesk/stays/{$stay2->id}/checkout-execution", [
            'idempotency_key' => 'p9-http-conflict',
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJson([
                'message' => FrontDeskCheckoutExecutionService::ERROR_IDEMPOTENCY_CONFLICT,
            ]);

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach ([$stay1->id, $stay1->reservation_id, $stay1->guest_id, $stay1->current_room_id, $firstReceipt['checkout_execution_id']] as $sensitiveId) {
            if (is_string($sensitiveId)) {
                $this->assertStringNotContainsString($sensitiveId, $encoded);
            }
        }
        $this->assertCheckoutMutationCounts(1, 1, 1, 1, 1);
        $this->assertSame(0, FrontDeskCheckoutExecution::where('front_desk_stay_id', $stay2->id)->count());
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

        $run = app(NightAuditRunStartService::class)->start($this->frontDeskActor);
        $this->assertSame(NightAuditRunStatusEnum::InProgress, $run->status);

        $this->usePropertySession($this->property);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
            'idempotency_key' => 'p9-http-na',
            'password'        => 'password',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $this->usePropertySession($this->property);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
            'idempotency_key' => 'p9-http-na',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_execution'])
            ->assertJsonFragment([
                'checkout_execution' => [FrontDeskCheckoutExecutionService::ERROR_NIGHT_AUDIT_ACTIVE],
            ]);

        $this->assertSame(0, FrontDeskCheckoutExecution::count());
        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    // ═══ 12. Expired confirmation route wiring ═══

    public function test_route_issued_confirmation_expires_by_database_clock_before_execution(): void
    {
        [$stay] = $this->checkedInStay('C112');
        $this->createReadyFinalReview($stay);

        $this->usePropertySession($this->property);
        $confirmation = $this->post("/frontdesk/stays/{$stay->id}/checkout-confirmation", [
            'idempotency_key' => 'p9-http-expired',
            'password'        => 'password',
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->json();

        $routeIssued = CheckoutSensitiveConfirmationIssuance::where('front_desk_stay_id', $stay->id)
            ->where('checkout_idempotency_key', 'p9-http-expired')
            ->firstOrFail();
        $dbNow = Carbon::parse(DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc")->wall_clock_utc);
        $this->assertTrue($dbNow->lt($routeIssued->expires_at));
        $this->assertSame($routeIssued->expires_at->toISOString(), $confirmation['expires_at']);

        $shortExpiry = $dbNow->copy()->addSecond();
        DB::statement('ALTER TABLE checkout_sensitive_confirmation_issuances DISABLE TRIGGER p8_csc_issue_no_update');
        try {
            DB::table('checkout_sensitive_confirmation_issuances')
                ->where('id', $routeIssued->id)
                ->update(['expires_at' => $shortExpiry]);
        } finally {
            DB::statement('ALTER TABLE checkout_sensitive_confirmation_issuances ENABLE TRIGGER p8_csc_issue_no_update');
        }
        $this->waitUntilDatabaseTimeAfter($shortExpiry);

        $this->post("/frontdesk/stays/{$stay->id}/checkout-execution", [
            'idempotency_key' => 'p9-http-expired',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_execution'])
            ->assertJsonFragment([
                'checkout_execution' => [CheckoutSensitiveConfirmationService::ERROR_EXPIRED],
            ]);

        $this->assertCheckoutMutationCounts(0, 0, 0, 0, 0);
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, $stay->fresh()->status);
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

        $receipt = $this->confirmThenExecuteJson($stay, 'p9-http-privacy');
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('confirmation_identity', $receipt);
        $this->assertArrayNotHasKey('confirmation_fingerprint', $receipt);
        $this->assertArrayNotHasKey('checkout_confirmation_fingerprint', $receipt);
        $this->assertStringNotContainsString('confirmation_identity', $encoded);
        $this->assertStringNotContainsString('confirmation_fingerprint', $encoded);
        $this->assertStringNotContainsString(FrontDeskCheckoutExecution::firstOrFail()->checkout_confirmation_fingerprint, $encoded);
    }
}
