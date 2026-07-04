<?php

namespace Tests\Postgres\Finance\FxReference;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\FxReference\Services\FxBreakGlassAccessService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FxBreakGlassAccessTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $superAdmin;
    private User $propertyAdmin;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web', 'property_id' => null]);
        Role::firstOrCreate(['name' => 'property-admin', 'guard_name' => 'web', 'property_id' => null]);
        Role::firstOrCreate(['name' => 'accounts-payable-officer', 'guard_name' => 'web', 'property_id' => null]);
    }

    public function test_normal_non_broad_user_accesses_break_glass_page_without_activation(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->normalUser, 'web')
            ->get(route('finance.fx-break-glass.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isBroadAdmin', false)
                ->where('isActive', false)
            );
    }

    public function test_normal_non_broad_user_can_access_fx_workspace_without_break_glass(): void
    {
        $this->createFixtures();

        $this->normalUser->givePermissionTo('finance.fx-adjustment.view');

        $this->withSession($this->propertySession())
            ->actingAs($this->normalUser, 'web')
            ->get(route('finance.fx-adjustments.index'))
            ->assertOk();
    }

    public function test_super_admin_cannot_access_fx_workspace_without_activation(): void
    {
        $this->createFixtures();

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
        $response->assertSessionHas('error');
    }

    public function test_property_admin_cannot_access_fx_workspace_without_activation(): void
    {
        $this->createFixtures();

        $this->propertyAdmin->givePermissionTo('finance.fx-adjustment.view');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->propertyAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
        $response->assertSessionHas('error');
    }

    public function test_broad_admin_cannot_create_candidate_without_activation(): void
    {
        $this->createFixtures();

        Permission::firstOrCreate([
            'name' => 'finance.fx-adjustment-candidate.create',
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => 'finance.payables.ap-settlement.allocate',
            'guard_name' => 'web',
        ]);

        $this->superAdmin->givePermissionTo([
            'finance.fx-adjustment.view',
            'finance.fx-adjustment-candidate.create',
            'finance.payables.ap-settlement.allocate',
        ]);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-adjustments.candidates.create'), [
                'allocation_id' => (string) \Illuminate\Support\Str::ulid(),
            ]);

        $response->assertRedirect(route('finance.fx-break-glass.index'));
        $response->assertSessionHas('error');
    }

    public function test_missing_fx_break_glass_confirmation_prevents_activation(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_wrong_intent_confirmation_prevents_break_glass_activation(): void
    {
        $this->createFixtures();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-role-assignment' => [
                    'actor_id' => $this->superAdmin->id,
                    'intent' => 'finance-role-assignment',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_valid_confirmation_plus_reason_activates_break_glass(): void
    {
        $this->createFixtures();

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review for quarterly close.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'))
            ->assertOk();
    }

    public function test_empty_reason_fails_controlled(): void
    {
        $this->createFixtures();

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_activation_binds_to_actor(): void
    {
        $this->createFixtures();

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertSessionHas('success');

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');
        $this->propertyAdmin->givePermissionTo('finance.fx-adjustment.view');

        $this->flushSession();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->propertyAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
    }

    public function test_activation_binds_to_property(): void
    {
        $this->createFixtures();

        $this->superAdmin->properties()->syncWithoutDetaching([
            $this->otherProperty->id => ['is_default' => true, 'status' => 'active', 'joined_at' => now()],
        ]);

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertSessionHas('success');

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');

        $this->flushSession();

        $otherSession = [
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ];

        $response = $this->withSession($otherSession)
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
    }

    public function test_expiration_fails_closed(): void
    {
        $this->createFixtures();

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');

        $expiredTime = Carbon::now()->subMinutes(20);
        $session = array_merge($this->fxBreakGlassConfirmedSession(), [
            'fx_break_glass_activation' => [
                'actor_id' => $this->superAdmin->id,
                'company_id' => $this->company->id,
                'property_id' => $this->property->id,
                'reason' => 'Emergency FX review.',
                'activated_at' => $expiredTime->toISOString(),
                'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
            ],
        ]);

        $response = $this->withSession($session)
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
    }

    public function test_explicit_deactivation_removes_access(): void
    {
        $this->createFixtures();

        $this->superAdmin->givePermissionTo('finance.fx-adjustment.view');

        $session = array_merge($this->fxBreakGlassConfirmedSession(), [
            'fx_break_glass_activation' => [
                'actor_id' => $this->superAdmin->id,
                'company_id' => $this->company->id,
                'property_id' => $this->property->id,
                'reason' => 'Emergency FX review.',
                'activated_at' => Carbon::now()->toISOString(),
                'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
            ],
        ]);

        $this->withSession($session)
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'))
            ->assertOk();

        $this->withSession($session)
            ->actingAs($this->superAdmin, 'web')
            ->delete(route('finance.fx-break-glass.destroy'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->flushSession();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->superAdmin, 'web')
            ->get(route('finance.fx-adjustments.index'));

        $response->assertRedirect(route('finance.fx-break-glass.index'));
    }

    public function test_activation_creates_audit_evidence(): void
    {
        $this->createFixtures();

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertSessionHas('success');

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'fx_break_glass_activated')
            ->where('user_id', $this->superAdmin->id)
            ->count());
    }

    public function test_deactivation_creates_audit_evidence(): void
    {
        $this->createFixtures();

        $session = array_merge($this->propertySession(), [
            'fx_break_glass_activation' => [
                'actor_id' => $this->superAdmin->id,
                'company_id' => $this->company->id,
                'property_id' => $this->property->id,
                'reason' => 'Emergency FX review.',
                'activated_at' => Carbon::now()->toISOString(),
                'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
            ],
        ]);

        $this->withSession($session)
            ->actingAs($this->superAdmin, 'web')
            ->delete(route('finance.fx-break-glass.destroy'))
            ->assertSessionHas('success');

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'fx_break_glass_deactivated')
            ->where('user_id', $this->superAdmin->id)
            ->count());
    }

    public function test_normal_user_never_gains_permissions_from_break_glass_paths(): void
    {
        $this->createFixtures();

        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->normalUser->id)
            ->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->normalUser, 'web')
            ->get(route('finance.fx-break-glass.index'))
            ->assertOk();

        $this->assertSame(
            $permCountBefore,
            DB::table('model_has_permissions')->where('model_id', $this->normalUser->id)->count()
        );
    }

    public function test_no_role_permission_mutation_on_activation(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->superAdmin->id)
            ->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->superAdmin->id)
            ->count();

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertSessionHas('success');

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->superAdmin->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->superAdmin->id)->count());
    }

    public function test_no_domain_tables_mutated_by_activation(): void
    {
        $this->createFixtures();

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

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->post(route('finance.fx-break-glass.store'), [
                'reason' => 'Emergency FX review.',
            ])
            ->assertSessionHas('success');

        $this->withSession($this->fxBreakGlassConfirmedSession())
            ->actingAs($this->superAdmin, 'web')
            ->delete(route('finance.fx-break-glass.destroy'))
            ->assertSessionHas('success');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'BG Test Company',
            'slug' => 'bg-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'BG Test Property',
            'slug' => 'bg-test-property',
            'code' => 'BGTP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'BG Other Property',
            'slug' => 'bg-other-property',
            'code' => 'BGOP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->superAdmin = $this->user('BG Super Admin', 'bg-super-admin@example.test');
        $this->propertyAdmin = $this->user('BG Property Admin', 'bg-property-admin@example.test');
        $this->normalUser = $this->user('BG Normal User', 'bg-normal-user@example.test');

        $this->attachProperty($this->superAdmin, $this->property);
        $this->attachProperty($this->propertyAdmin, $this->property);
        $this->attachProperty($this->normalUser, $this->property);

        setPermissionsTeamId($this->property->id);

        $this->superAdmin->assignRole('super-admin');
        $this->propertyAdmin->assignRole('property-admin');
        $this->normalUser->assignRole('accounts-payable-officer');
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

    private function fxBreakGlassConfirmedSession(): array
    {
        $now = Carbon::now();

        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'fx-break-glass' => [
                    'actor_id' => $this->superAdmin->id,
                    'intent' => 'fx-break-glass',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]);
    }
}
