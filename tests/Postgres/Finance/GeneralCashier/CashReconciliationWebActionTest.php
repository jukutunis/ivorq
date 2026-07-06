<?php

namespace Tests\Postgres\Finance\GeneralCashier;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Operations\GeneralCashier\Models\CashCountEvidence;
use Modules\Operations\GeneralCashier\Models\CashReconciliationBaseline;
use Modules\Operations\GeneralCashier\Services\ManualCashReconciliationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashReconciliationWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => ManualCashReconciliationService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_reconcile(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->post(route('finance.payables.cash-reconciliation.reconcile'), [
            'cash_reconciliation_baseline_id' => $context['baseline_id'],
            'ending_cash_count_evidence_id' => $context['count_id'],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('cash_reconciliations')->count());
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
            ])->assertStatus(403);

        $this->assertSame(0, DB::table('cash_reconciliations')->count());
    }

    public function test_cross_property_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('cash_reconciliations')->count());
    }

    public function test_browser_cannot_inject_amount(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
                'amount' => '999.99',
            ])->assertRedirect();

        $allocation = DB::table('cash_reconciliations')->first();
        $this->assertNotNull($allocation);
        $this->assertSame('100.00', $allocation->baseline_amount);
    }

    public function test_valid_reconciliation_succeeds(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('cash_reconciliations')->count());
    }

    public function test_idempotent_replay_returns_same_reconciliation(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
            ])->assertRedirect();

        $first = DB::table('cash_reconciliations')->first();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.payables.cash-reconciliation.reconcile'), [
                'cash_reconciliation_baseline_id' => $context['baseline_id'],
                'ending_cash_count_evidence_id' => $context['count_id'],
            ])->assertRedirect();

        $this->assertSame(1, DB::table('cash_reconciliations')->count());
        $replay = DB::table('cash_reconciliations')->first();
        $this->assertSame($first->id, $replay->id);
    }

    private function createFixtures(): void
    {
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Cash Recon Web Company ' . $suffix,
            'slug' => 'cash-recon-web-company-' . $suffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Recon Web Property ' . $suffix,
            'slug' => 'cash-recon-web-property-' . $suffix,
            'code' => 'CRW' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Recon Web Other ' . $suffix,
            'slug' => 'cash-recon-web-other-' . $suffix,
            'code' => 'CRO' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = $this->user('Cash Recon Web Actor ' . $suffix, 'cash-recon-web-actor-' . $suffix . '@example.test');
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->givePermissionTo(ManualCashReconciliationService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->noAuthUser = $this->user('Cash Recon Web NoAuth ' . $suffix, 'cash-recon-web-noauth-' . $suffix . '@example.test');
        $this->noAuthUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function makeReconciliationContext(): array
    {
        $timestamp = now();
        $baselineId = (string) Str::ulid();
        $countId = (string) Str::ulid();
        $acctId = (string) Str::ulid();
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        DB::table('gl_accounts')->insert([
            'id' => $acctId,
            'property_id' => $this->property->id,
            'code' => 'CASH-RECON-' . $suffix,
            'name' => 'Cash Recon Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cash_reconciliation_baselines')->insert([
            'id' => $baselineId,
            'cash_count_evidence_id' => $countId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $acctId,
            'currency_code' => 'IDR',
            'cashbook_boundary_posted_business_date' => '2026-07-01',
            'baseline_amount' => '100.00',
            'baseline_by' => $this->actor->id,
            'baseline_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'baseline-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'cash_recon_web']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cash_count_evidence')->insert([
            'id' => $countId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $acctId,
            'currency_code' => 'IDR',
            'observed_count_date' => '2026-07-02',
            'observed_amount' => '100.00',
            'source_reference' => 'COUNT-' . $suffix,
            'counted_by' => $this->actor->id,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'count-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'cash_recon_web']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'baseline_id' => $baselineId,
            'count_id' => $countId,
        ];
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

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function otherPropertySession(): array
    {
        return [
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ];
    }
}
