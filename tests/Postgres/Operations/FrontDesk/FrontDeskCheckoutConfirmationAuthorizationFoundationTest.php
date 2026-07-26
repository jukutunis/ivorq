<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationContext;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Audit\Models\AuditLog;
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

    public function test_context_level_issue_and_claim_are_private_and_not_public_context_apis(): void
    {
        $reflection = new ReflectionClass(CheckoutSensitiveConfirmationService::class);

        $this->assertTrue($reflection->getMethod('issueForCurrentSession')->isPublic());
        $this->assertTrue($reflection->getMethod('claimCurrentSessionConfirmationFor')->isPublic());
        $this->assertTrue($reflection->getMethod('issue')->isPrivate());
        $this->assertTrue($reflection->getMethod('claimCurrentSessionConfirmation')->isPrivate());

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotSame(
                    CheckoutSensitiveConfirmationContext::class,
                    $parameter->getType()?->getName(),
                    "Public method {$method->getName()} must not accept caller-built checkout confirmation context."
                );
            }
        }
    }

    public function test_authorization_matrix_denies_before_stay_query(): void
    {
        foreach ([
            'inactive_actor' => fn () => $this->actor->forceFill(['is_active' => false])->save(),
            'missing_company' => fn () => session(['active_company_id' => (string) Str::ulid()]),
            'inactive_company' => fn () => $this->company->forceFill(['is_active' => false])->save(),
            'missing_property' => fn () => app(CurrentPropertyService::class)->setPropertyId((string) Str::ulid()),
            'inactive_property' => fn () => $this->property->forceFill(['is_active' => false])->save(),
            'property_other_company' => function (): void {
                $otherCompany = Company::create(['name' => 'Other Co', 'slug' => 'other-co-' . Str::lower(Str::random(6)), 'is_active' => true]);
                $this->property->forceFill(['company_id' => $otherCompany->id])->save();
            },
            'missing_membership' => fn () => $this->actor->properties()->detach($this->property->id),
            'inactive_membership' => fn () => DB::table('property_user')->where('user_id', $this->actor->id)->where('property_id', $this->property->id)->update(['status' => 'inactive']),
            'boundary_view_only' => fn () => $this->actor->givePermissionTo('frontdesk.checkout-execution-boundary.view'),
            'unrelated_permission' => fn () => $this->actor->givePermissionTo('frontdesk.arrival.view'),
        ] as $case => $mutate) {
            $this->refreshScenario();
            $mutate();
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'matrix-' . $case, 'password');
                $this->fail("{$case} must fail authorization.");
            } catch (AuthorizationException|DomainException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }

            $this->assertSame(0, $this->frontDeskStayQueryCount(), "{$case} must not query front_desk_stays.");
        }
    }

    public function test_public_issue_uses_current_property_and_non_discloses_other_member_property_stay(): void
    {
        $this->allowExecute();
        $this->attachProperty($this->actor, $this->otherProperty);
        $otherStay = $this->stay($this->otherProperty);

        foreach ([(string) Str::ulid(), $otherStay->id] as $stayId) {
            try {
                $this->confirmation->issueForCurrentSession($this->actor, $stayId, 'property-isolation-' . Str::lower((string) Str::ulid()), 'password');
                $this->fail('Unknown and other-current-property stay confirmations must fail closed.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
                $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_STAY_NOT_FOUND, $exception->getMessage());
            }
        }

        $this->assertSame(
            0,
            DB::table('checkout_sensitive_confirmation_issuances')->where('property_id', $this->otherProperty->id)->count(),
            'Public issue API must not create Property B issuance while current Property is A.'
        );

        $issuance = $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'property-a-valid', 'password');
        $this->assertSame($this->property->id, $issuance->property_id);
        $this->assertSame($this->stay->id, $issuance->front_desk_stay_id);

        $result = DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'property-a-valid'));
        $this->assertSame($this->property->id, $result->propertyId);
        $this->assertSame($this->stay->id, $result->frontDeskStayId);
    }

    public function test_claim_authorization_denies_before_stay_query(): void
    {
        $this->issue('claim-auth-key');

        foreach ([
            'missing_execute' => null,
            'boundary_view_only' => 'frontdesk.checkout-execution-boundary.view',
            'unrelated_permission' => 'frontdesk.arrival.view',
        ] as $case => $permission) {
            DB::table('model_has_permissions')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->actor->refresh();
            if ($permission !== null) {
                $this->actor->givePermissionTo($permission);
            }
            $this->restoreSessionReference('claim-auth-key');
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'claim-auth-key'));
                $this->fail("{$case} must fail before stay resolution.");
            } catch (AuthorizationException $exception) {
                $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_EXECUTE_PERMISSION_MISSING, $exception->getMessage());
            }

            $this->assertSame(0, $this->frontDeskStayQueryCount(), "{$case} claim must not query front_desk_stays.");
        }
    }

    public function test_current_company_mismatch_fails_before_stay_resolution(): void
    {
        $this->issue('company-mismatch-key');
        $otherCompany = Company::create(['name' => 'Wrong Session Co', 'slug' => 'wrong-session-' . Str::lower(Str::random(6)), 'is_active' => true]);
        session(['active_company_id' => $otherCompany->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'company-mismatch-issue', 'password');
            $this->fail('Company mismatch must fail before issuing.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT, $exception->getMessage());
        }

        $this->assertSame(0, $this->frontDeskStayQueryCount(), 'Company mismatch issue must not query front_desk_stays.');

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'company-mismatch-key'));
            $this->fail('Company mismatch must fail before claim stay resolution.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FrontDeskCheckoutExecuteAuthorizationService::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT, $exception->getMessage());
        }

        $this->assertSame(0, $this->frontDeskStayQueryCount(), 'Company mismatch claim must not query front_desk_stays.');
    }

    public function test_exact_execute_permission_is_required_and_ignores_forged_context_values(): void
    {
        $this->allowExecute();
        $otherCompany = Company::create(['name' => 'Forged Co', 'slug' => 'forged-co-' . Str::lower(Str::random(6)), 'is_active' => true]);
        $forgedProperty = $this->property('Forged Property', $otherCompany);
        $forgedStay = $this->stay($forgedProperty);

        $issuance = $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'server-owned-key', 'password');

        $this->assertSame($this->company->id, $issuance->company_id);
        $this->assertSame($this->property->id, $issuance->property_id);
        $this->assertSame($this->stay->id, $issuance->front_desk_stay_id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_CONTEXT_CONFLICT);

        $this->invokePrivateIssue(new CheckoutSensitiveConfirmationContext(
            actor: $this->actor,
            company: $otherCompany,
            property: $forgedProperty,
            stay: $forgedStay,
            checkoutIdempotencyKey: 'forged-context',
            sessionFingerprint: CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId()),
        ), 'password');
    }

    public function test_wrong_password_creates_no_issuance(): void
    {
        try {
            $this->allowExecute();
            $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'checkout-key-2', 'wrong-password');
            $this->fail('Wrong password must fail.');
        } catch (DomainException $exception) {
            $this->assertSame('The password is incorrect.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_issuances')->count());
    }

    public function test_reissue_same_context_returns_same_unconsumed_unexpired_issuance(): void
    {
        $first = $this->issue('checkout-key-3');
        $second = $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, 'checkout-key-3', 'password');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_issuances')->count());
    }

    public function test_claim_requires_postgresql_and_active_transaction(): void
    {
        $this->issue('checkout-key-4');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'checkout-key-4');
            $this->fail('Claim must require caller-owned active transaction.');
        } catch (DomainException $exception) {
            $this->assertSame(CheckoutSensitiveConfirmationService::ERROR_ACTIVE_TRANSACTION_REQUIRED, $exception->getMessage());
        }

        $this->assertSame(0, $this->frontDeskStayQueryCount(), 'No-transaction claim gate must not query front_desk_stays.');
    }

    public function test_valid_claim_consumes_once_inside_active_transaction(): void
    {
        $this->issue('checkout-key-5');

        $result = DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'checkout-key-5'));

        $this->assertDatabaseHas('checkout_sensitive_confirmation_consumptions', [
            'id' => $result->consumptionId,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $this->stay->id,
            'checkout_idempotency_key' => 'checkout-key-5',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_ALREADY_CONSUMED);

        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'checkout-key-5'));
    }

    public function test_consumption_rolls_back_with_caller_transaction_and_can_be_reclaimed_before_expiry(): void
    {
        $this->issue('checkout-key-6');

        try {
            DB::transaction(function (): void {
                $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'checkout-key-6');
                $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
                throw new DomainException('ROLLBACK_TEST');
            });
        } catch (DomainException $exception) {
            $this->assertSame('ROLLBACK_TEST', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_consumptions')->count());
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'checkout-key-6'));
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_changed_context_and_expired_confirmation_fail_closed(): void
    {
        $this->issue('checkout-key-7');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_CONTEXT_CONFLICT);
        DB::transaction(fn () => $this->invokePrivateClaim($this->context('changed-key')));
    }

    public function test_session_actor_company_property_stay_and_idempotency_bindings_fail_closed(): void
    {
        $this->issue('binding-key');

        foreach ([
            'actor_id' => ['actor', User::create(['name' => 'Other Actor', 'email' => 'other-' . Str::lower(Str::random(6)) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true])],
            'company_id' => ['company', Company::create(['name' => 'Other Binding Co', 'slug' => 'other-binding-' . Str::lower(Str::random(6)), 'is_active' => true])],
            'property_id' => ['property', $this->otherProperty],
            'front_desk_stay_id' => ['stay', $this->stay($this->otherProperty)],
            'checkout_idempotency_key' => ['idempotency', 'binding-key-changed'],
            'session_fingerprint' => ['session', hash('sha256', 'forged-session')],
        ] as $field => [$kind, $value]) {
            $this->restoreSessionReference('binding-key');
            $context = $this->context('binding-key');
            $context = match ($kind) {
                'actor' => new CheckoutSensitiveConfirmationContext($value, $this->company, $this->property, $this->stay, 'binding-key', CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId())),
                'company' => new CheckoutSensitiveConfirmationContext($this->actor, $value, $this->property, $this->stay, 'binding-key', CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId())),
                'property' => new CheckoutSensitiveConfirmationContext($this->actor, $this->company, $value, $this->stay, 'binding-key', CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId())),
                'stay' => new CheckoutSensitiveConfirmationContext($this->actor, $this->company, $this->property, $value, 'binding-key', CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId())),
                'idempotency' => $this->context($value),
                'session' => new CheckoutSensitiveConfirmationContext($this->actor, $this->company, $this->property, $this->stay, 'binding-key', $value),
            };

            try {
                DB::transaction(fn () => $this->invokePrivateClaim($context));
                $this->fail("Binding {$field} must fail.");
            } catch (AuthorizationException|DomainException $exception) {
                $this->assertContains($exception->getMessage(), [
                    CheckoutSensitiveConfirmationService::ERROR_CONTEXT_CONFLICT,
                    CheckoutSensitiveConfirmationService::ERROR_SESSION_MISMATCH,
                    FrontDeskCheckoutExecuteAuthorizationService::ERROR_INVALID_AUTHENTICATED_CONTEXT,
                    FrontDeskCheckoutExecuteAuthorizationService::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT,
                ]);
            }
        }
    }

    public function test_malformed_session_metadata_and_expiry_fail_closed(): void
    {
        $issuance = $this->issue('expiry-key');

        session([CheckoutSensitiveConfirmationService::SESSION_KEY => [CheckoutSensitiveConfirmationService::INTENT => ['issuance_id' => $issuance->id]]]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_MALFORMED_CONFIRMATION);
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'expiry-key'));
    }

    public function test_expired_confirmation_fails_closed(): void
    {
        $this->allowExecute();
        $this->insertRawIssuance('expired-key', now()->subMinutes(20), now()->subMinute());

        $this->restoreSessionReference('expired-key');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CheckoutSensitiveConfirmationService::ERROR_EXPIRED);
        DB::transaction(fn () => $this->confirmation->claimCurrentSessionConfirmationFor($this->actor, $this->stay->id, 'expired-key'));
    }

    public function test_audit_and_persistence_exclude_password_and_raw_session_id(): void
    {
        $sessionId = session()->getId();
        $this->issue('privacy-key');

        $dbText = json_encode(DB::table('checkout_sensitive_confirmation_issuances')->get(), JSON_THROW_ON_ERROR);
        $auditText = json_encode(AuditLog::query()->where('event', 'checkout_sensitive_action_confirmed')->get()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('password', $dbText);
        $this->assertStringNotContainsString('password', $auditText);
        $this->assertStringNotContainsString($sessionId, $dbText);
        $this->assertStringNotContainsString($sessionId, $auditText);
        $this->assertStringContainsString('checkout_idempotency_fingerprint', $auditText);
        $this->assertStringNotContainsString('privacy-key', $auditText);
    }

    public function test_cleanup_is_separate_idempotent_and_non_authoritative(): void
    {
        $this->issue('checkout-key-8');
        DB::transaction(fn () => $this->invokePrivateClaim($this->context('checkout-key-8')));

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
        $this->allowExecute();

        return $this->confirmation->issueForCurrentSession($this->actor, $this->stay->id, $idempotencyKey, 'password');
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

    private function invokePrivateIssue(CheckoutSensitiveConfirmationContext $context, string $password)
    {
        $method = (new ReflectionClass($this->confirmation))->getMethod('issue');
        $method->setAccessible(true);

        return $method->invoke($this->confirmation, $context, $password);
    }

    private function invokePrivateClaim(CheckoutSensitiveConfirmationContext $context)
    {
        $method = (new ReflectionClass($this->confirmation))->getMethod('claimCurrentSessionConfirmation');
        $method->setAccessible(true);

        return $method->invoke($this->confirmation, $context);
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

    private function allowExecute(): void
    {
        if (! $this->actor->hasPermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION)) {
            $this->actor->givePermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);
        }
    }

    private function refreshScenario(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::logout();
        DB::table('model_has_permissions')->delete();
        DB::table('property_user')->delete();
        $this->company->refresh()->forceFill(['is_active' => true])->save();
        $this->property->refresh()->forceFill(['company_id' => $this->company->id, 'is_active' => true])->save();
        $this->actor->refresh()->forceFill(['is_active' => true])->save();
        $this->attachProperty($this->actor, $this->property);
        Auth::login($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
        ]);
    }

    private function restoreSessionReference(string $idempotencyKey): void
    {
        $issuance = DB::table('checkout_sensitive_confirmation_issuances')
            ->where('checkout_idempotency_key', $idempotencyKey)
            ->first();
        $this->assertNotNull($issuance);

        session([
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id' => $issuance->actor_id,
                    'intent' => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id' => $issuance->company_id,
                    'property_id' => $issuance->property_id,
                    'front_desk_stay_id' => $issuance->front_desk_stay_id,
                    'checkout_idempotency_key' => $issuance->checkout_idempotency_key,
                    'issuance_id' => $issuance->id,
                    'confirmation_identity' => $issuance->confirmation_identity,
                    'confirmation_fingerprint' => $issuance->confirmation_fingerprint,
                    'session_fingerprint' => $issuance->session_fingerprint,
                    'confirmed_at' => $issuance->confirmed_at,
                    'expires_at' => $issuance->expires_at,
                ],
            ],
        ]);
    }

    private function insertRawIssuance(string $idempotencyKey, $confirmedAt, $expiresAt): string
    {
        $id = (string) Str::ulid();
        $identity = (string) Str::ulid();

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $id,
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $this->actor->id,
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $this->stay->id,
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => CheckoutSensitiveConfirmationService::fingerprintSession(session()->getId()),
            'confirmation_fingerprint' => hash('sha256', 'raw-confirmation-' . $id),
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        return $id;
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
