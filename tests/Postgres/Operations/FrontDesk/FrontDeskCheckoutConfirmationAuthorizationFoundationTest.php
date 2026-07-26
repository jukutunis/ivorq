<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationContext;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationAuthorizationFoundationTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private FrontDeskStay $stay;
    private CheckoutSensitiveConfirmationService $confirmation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'P8 Company',
            'slug' => 'p8-company-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = $this->property('P8 Property', $this->company);
        $this->otherProperty = $this->property('P8 Other Property', $this->company);

        $this->actor = User::create([
            'name' => 'P8 Actor',
            'email' => 'p8-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->attachProperty($this->actor, $this->property);
        $this->stay = $this->stay($this->property);
        $this->confirmation = app(CheckoutSensitiveConfirmationService::class);

        Permission::firstOrCreate(['name' => FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'frontdesk.checkout-execution-boundary.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'frontdesk.arrival.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Auth::login($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        Auth::logout();
        parent::tearDown();
    }

    public function test_execute_permission_is_registered_but_not_inferred_from_boundary_view(): void
    {
        $this->assertContains(
            SensitiveActionConfirmationService::CHECKOUT_EXECUTION_INTENT,
            app(SensitiveActionConfirmationService::class)->registeredIntents()
        );

        $this->actor->givePermissionTo('frontdesk.checkout-execution-boundary.view');

        DB::enableQueryLog();
        try {
            app(FrontDeskCheckoutExecuteAuthorizationService::class)->resolveAuthorizedStay($this->actor, $this->stay->id);
            $this->fail('Boundary-view permission must not imply execute permission.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_EXECUTE_PERMISSION_MISSING, $exception->getMessage());
        }

        $this->assertSame(0, $this->frontDeskStayQueryCount(), 'Unauthorized actors must cause zero front_desk_stays queries.');
    }

    public function test_exact_execute_permission_resolves_same_property_stay_after_authorization(): void
    {
        $this->actor->givePermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);

        DB::enableQueryLog();
        $resolved = app(FrontDeskCheckoutExecuteAuthorizationService::class)
            ->resolveAuthorizedStay($this->actor, $this->stay->id);

        $this->assertSame($this->stay->id, $resolved->id);
        $this->assertGreaterThanOrEqual(1, $this->frontDeskStayQueryCount());
    }

    public function test_unknown_and_cross_property_stays_are_non_disclosing_after_authorization(): void
    {
        $this->actor->givePermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);
        $otherStay = $this->stay($this->otherProperty);

        foreach ([(string) Str::ulid(), $otherStay->id] as $stayId) {
            try {
                app(FrontDeskCheckoutExecuteAuthorizationService::class)->resolveAuthorizedStay($this->actor, $stayId);
                $this->fail('Unknown and cross-property stays must fail closed.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
                $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_STAY_NOT_FOUND, $exception->getMessage());
            }
        }
    }

    public function test_generic_checkout_confirmation_fails_closed_without_authoritative_context(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Checkout confirmation requires authoritative checkout context.');

        app(SensitiveActionConfirmationService::class)->confirm(
            $this->actor,
            SensitiveActionConfirmationService::CHECKOUT_EXECUTION_INTENT,
            'password',
            $this->company->id,
            $this->property->id
        );
    }

    public function test_dedicated_checkout_confirmation_issues_context_bound_durable_evidence(): void
    {
        $issuance = $this->issue('checkout-key-1');

        $this->assertDatabaseHas('checkout_sensitive_confirmation_issuances', [
            'id' => $issuance->id,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $this->actor->id,
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $this->stay->id,
            'checkout_idempotency_key' => 'checkout-key-1',
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issuance->session_fingerprint);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issuance->confirmation_fingerprint);
        $this->assertStringNotContainsString(session()->getId(), $issuance->session_fingerprint);
        $this->assertSame(0, DB::table('front_desk_checkout_executions')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_housekeeping_handoffs')->count());
    }

    public function test_wrong_password_creates_no_issuance(): void
    {
        try {
            $this->confirmation->issue($this->context('checkout-key-2'), 'wrong-password');
            $this->fail('Wrong password must fail.');
        } catch (DomainException $exception) {
            $this->assertSame('The password is incorrect.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_issuances')->count());
    }

    public function test_reissue_same_context_returns_same_unconsumed_unexpired_issuance(): void
    {
        $first = $this->issue('checkout-key-3');
        $second = $this->confirmation->issue($this->context('checkout-key-3'), 'password');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_issuances')->count());
    }

    public function test_claim_requires_postgresql_and_active_transaction(): void
    {
        $this->issue('checkout-key-4');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_ACTIVE_TRANSACTION_REQUIRED);

        $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-4'));
    }

    public function test_valid_claim_consumes_once_inside_active_transaction(): void
    {
        $this->issue('checkout-key-5');

        $result = DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-5')));

        $this->assertDatabaseHas('checkout_sensitive_confirmation_consumptions', [
            'id' => $result->consumptionId,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $this->stay->id,
            'checkout_idempotency_key' => 'checkout-key-5',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_ALREADY_CONSUMED);

        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-5')));
    }

    public function test_consumption_rolls_back_with_caller_transaction_and_can_be_reclaimed_before_expiry(): void
    {
        $this->issue('checkout-key-6');

        try {
            DB::transaction(function (): void {
                $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-6'));
                $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
                throw new DomainException('ROLLBACK_TEST');
            });
        } catch (DomainException $exception) {
            $this->assertSame('ROLLBACK_TEST', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_consumptions')->count());
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-6')));
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_changed_context_and_expired_confirmation_fail_closed(): void
    {
        $this->issue('checkout-key-7');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_CONTEXT_CONFLICT);
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmation($this->context('changed-key')));
    }

    public function test_cleanup_is_separate_idempotent_and_non_authoritative(): void
    {
        $this->issue('checkout-key-8');
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmation($this->context('checkout-key-8')));

        $this->confirmation->cleanupCurrentSessionReference();
        $this->confirmation->cleanupCurrentSessionReference();

        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertArrayNotHasKey(
            CheckoutSensitiveConfirmationService::INTENT,
            session()->get(CheckoutSensitiveConfirmationService::SESSION_KEY, [])
        );
    }

    private function issue(string $idempotencyKey)
    {
        return $this->confirmation->issue($this->context($idempotencyKey), 'password');
    }

    private function context(string $idempotencyKey): CheckoutSensitiveConfirmationContext
    {
        return new CheckoutSensitiveConfirmationContext(
            actor: $this->actor,
            company: $this->company,
            property: $this->property,
            stay: $this->stay,
            checkoutIdempotencyKey: $idempotencyKey,
            sessionFingerprint: CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId()),
        );
    }

    private function property(string $name, Company $company): Property
    {
        return Property::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'code' => Str::upper(Str::random(4)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function attachProperty(User $user, Property $property, string $status = 'active'): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    private function stay(Property $property): FrontDeskStay
    {
        $guest = Guest::create([
            'property_id' => $property->id,
            'guest_code' => 'P8G-' . Str::upper(Str::random(5)),
            'full_name' => 'P8 Guest',
            'guest_type' => 'individual',
        ]);

        $reservation = Reservation::create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'P8R-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);

        return FrontDeskStay::create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function frontDeskStayQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'front_desk_stays'))
            ->count();
    }
}
