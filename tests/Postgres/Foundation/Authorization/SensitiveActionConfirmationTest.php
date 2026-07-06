<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SensitiveActionConfirmationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $actor;

    private function intent(): string
    {
        return 'finance-role-assignment';
    }

    private function cashIntent(): string
    {
        return 'cash-payment-execution';
    }

    public function test_unauthenticated_actor_cannot_open_confirmation_page(): void
    {
        $this->createFixtures();

        $this->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertRedirect();
    }

    public function test_unauthenticated_actor_cannot_confirm(): void
    {
        $this->createFixtures();

        $this->post(route('system.sensitive-action-confirmation.store'), [
            'intent' => $this->intent(),
            'password' => 'password',
        ])->assertRedirect();
    }

    public function test_unauthenticated_actor_cannot_invalidate(): void
    {
        $this->createFixtures();

        $this->delete(route('system.sensitive-action-confirmation.destroy'), [
            'intent' => $this->intent(),
        ])->assertRedirect();
    }

    public function test_authenticated_actor_can_open_confirmation_page(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ivorq/System/SensitiveActionConfirmation')
                ->where('intent', $this->intent())
                ->where('propertyName', $this->property->name)
                ->where('isConfirmed', false)
            );
    }

    public function test_invalid_intent_is_rejected(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => 'nonexistent-intent']))
            ->assertStatus(400);
    }

    public function test_invalid_intent_rejected_on_post_validation(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => 'nonexistent-intent',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('intent');
    }

    public function test_valid_password_creates_confirmation(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('success', 'Sensitive action confirmed.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', true)
            );
    }

    public function test_wrong_password_is_rejected_without_confirmation(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'wrong-password',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'The password is incorrect.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_browser_cannot_supply_actor_company_property(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
                'actor_id' => 'fake-actor',
                'company_id' => 'fake-company',
                'property_id' => 'fake-property',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Sensitive action confirmed.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', true)
            );
    }

    public function test_confirmation_bound_to_current_actor(): void
    {
        $this->createFixtures();

        $otherActor = $this->user('Other Actor', 'other-actor@example.test');
        $this->attachProperty($otherActor, $this->property);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->flushSession();

        $this->withSession($this->propertySession())
            ->actingAs($otherActor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_confirmation_bound_to_intent(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_confirmation_bound_to_property(): void
    {
        $this->createFixtures();

        $otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Other Property',
            'slug' => 'other-property',
            'code' => 'OTPR',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->attachProperty($this->actor, $otherProperty);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $otherSession = [
            'active_property_id' => $otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $otherProperty->id,
        ];

        $this->withSession($otherSession)
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_confirmation_expires_after_ttl(): void
    {
        $this->createFixtures();

        Carbon::setTestNow(Carbon::now());

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', true)
            );

        Carbon::setTestNow(Carbon::now()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES + 1));

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );

        Carbon::setTestNow();
    }

    public function test_malformed_session_metadata_fails_closed(): void
    {
        $this->createFixtures();

        session()->put('sensitive_action_confirmation', ['not-an-array']);

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => ['not-a-confirmation'],
        ]))
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_explicit_invalidation_removes_confirmation(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', true)
            );

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->delete(route('system.sensitive-action-confirmation.destroy'), [
                'intent' => $this->intent(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Sensitive action confirmation ended.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_confirmation_creates_audit_evidence(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'sensitive_action_confirmed')
            ->where('user_id', $this->actor->id)
            ->count());
    }

    public function test_invalidation_creates_audit_evidence(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->delete(route('system.sensitive-action-confirmation.destroy'), [
                'intent' => $this->intent(),
            ])
            ->assertSessionHas('success');

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'sensitive_action_invalidated')
            ->where('user_id', $this->actor->id)
            ->count());
    }

    public function test_confirmation_grants_no_role_or_permission(): void
    {
        $this->createFixtures();

        $this->actor->refresh();
        $this->actor->syncRoles([]);
        $this->actor->syncPermissions([]);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->actor->refresh();

        $this->assertSame(0, DB::table('model_has_roles')
            ->where('model_id', $this->actor->id)
            ->count());
        $this->assertSame(0, DB::table('model_has_permissions')
            ->where('model_id', $this->actor->id)
            ->count());
    }

    public function test_no_domain_tables_mutated(): void
    {
        $this->createFixtures();

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->delete(route('system.sensitive-action-confirmation.destroy'), [
                'intent' => $this->intent(),
            ])
            ->assertSessionHas('success');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    public function test_repeated_confirmation_is_idempotent(): void
    {
        $this->createFixtures();

        $confirmationCountBefore = AuditLog::query()
            ->where('event', 'sensitive_action_confirmed')
            ->where('user_id', $this->actor->id)
            ->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->intent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $confirmationCountAfter = AuditLog::query()
            ->where('event', 'sensitive_action_confirmed')
            ->where('user_id', $this->actor->id)
            ->count();

        $this->assertSame($confirmationCountBefore + 2, $confirmationCountAfter);
    }

    public function test_controller_never_returns_raw_password_or_hash(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->intent()]));

        $content = $response->getContent();
        $this->assertStringNotContainsString((string) $this->actor->password, $content);
        $this->assertStringNotContainsString('password', $content);
    }

    public function test_cash_payment_execution_intent_is_accepted(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ivorq/System/SensitiveActionConfirmation')
                ->where('intent', $this->cashIntent())
                ->where('isConfirmed', false)
            );
    }

    public function test_cash_payment_execution_valid_password_creates_confirmation(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Sensitive action confirmed.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', true)
            );
    }

    public function test_cash_payment_execution_wrong_password_is_rejected(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'wrong-password',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'The password is incorrect.');
    }

    public function test_cash_payment_execution_confirmation_bound_to_actor(): void
    {
        $this->createFixtures();

        $otherActor = $this->user('Cash Exec Other', 'cash-exec-other@example.test');
        $this->attachProperty($otherActor, $this->property);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->flushSession();

        $this->withSession($this->propertySession())
            ->actingAs($otherActor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_cash_payment_execution_confirmation_bound_to_property(): void
    {
        $this->createFixtures();

        $otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Exec Other Property',
            'slug' => 'cash-exec-other-property',
            'code' => 'CEOP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->attachProperty($this->actor, $otherProperty);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $otherSession = [
            'active_property_id' => $otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $otherProperty->id,
        ];

        $this->withSession($otherSession)
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_cash_payment_execution_confirmation_expires_after_ttl(): void
    {
        $this->createFixtures();

        Carbon::setTestNow(Carbon::now());

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        Carbon::setTestNow(Carbon::now()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES + 1));

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );

        Carbon::setTestNow();
    }

    public function test_cash_payment_execution_invalidation_works(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->delete(route('system.sensitive-action-confirmation.destroy'), [
                'intent' => $this->cashIntent(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Sensitive action confirmation ended.');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => $this->cashIntent()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isConfirmed', false)
            );
    }

    public function test_cash_payment_execution_confirmation_creates_audit_evidence(): void
    {
        $this->createFixtures();

        $countBefore = AuditLog::query()
            ->where('event', 'sensitive_action_confirmed')
            ->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $countAfter = AuditLog::query()
            ->where('event', 'sensitive_action_confirmed')
            ->count();

        $this->assertGreaterThan($countBefore, $countAfter);
    }

    public function test_cash_payment_execution_confirmation_grants_no_role_or_permission(): void
    {
        $this->createFixtures();

        $this->actor->refresh();
        $this->actor->syncRoles([]);
        $this->actor->syncPermissions([]);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->actor->refresh();

        $this->assertSame(0, DB::table('model_has_roles')
            ->where('model_id', $this->actor->id)
            ->count());
        $this->assertSame(0, DB::table('model_has_permissions')
            ->where('model_id', $this->actor->id)
            ->count());
    }

    public function test_cash_payment_execution_no_domain_tables_mutated(): void
    {
        $this->createFixtures();

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => $this->cashIntent(),
                'password' => 'password',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->delete(route('system.sensitive-action-confirmation.destroy'), [
                'intent' => $this->cashIntent(),
            ])
            ->assertSessionHas('success');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    public function test_existing_finance_approval_intent_unchanged(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intent', 'finance-approval')
                ->where('isConfirmed', false)
            );

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('system.sensitive-action-confirmation.store'), [
                'intent' => 'finance-approval',
                'password' => 'password',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_existing_fx_break_glass_intent_unchanged(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('system.sensitive-action-confirmation.index', ['intent' => 'fx-break-glass']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intent', 'fx-break-glass')
                ->where('isConfirmed', false)
            );
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'SAC Test Company',
            'slug' => 'sac-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SAC Test Property',
            'slug' => 'sac-test-property',
            'code' => 'SACP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->actor = $this->user('SAC Actor', 'sac-actor@example.test');
        $this->attachProperty($this->actor, $this->property);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function attachProperty(User $user, Property $property): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function domainTableCounts(): array
    {
        $tables = [
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'exchange_rate_evidences',
            'payment_adjustment_configuration_evidences',
            'gl_financial_periods',
            'property_business_dates',
        ];

        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
