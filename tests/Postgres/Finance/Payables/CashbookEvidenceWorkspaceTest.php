<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class CashbookEvidenceWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $financeUser;

    public function test_unauthenticated_cannot_access(): void
    {
        $this->createFixtures();
        $this->get(route('finance.payables.cashbook-evidence.index'))->assertRedirect();
    }

    public function test_authenticated_user_can_load_workspace(): void
    {
        $this->createFixtures();
        $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/CashbookEvidenceWorkspace'));
    }

    public function test_workspace_shows_empty_state_when_no_data(): void
    {
        $this->createFixtures();
        $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('transactions')
                ->has('approved_proposals')
            );
    }

    public function test_workspace_view_does_not_mutate_finance_state(): void
    {
        $this->createFixtures();
        $tables = ['cashbook_transactions', 'payment_proposals', 'payment_executions',
            'gl_journal_entries', 'journal_candidates', 'ap_settlement_allocations',
            'gl_financial_periods', 'property_business_dates'];
        $before = [];
        foreach ($tables as $t) { $before[$t] = DB::table($t)->count(); }

        $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        foreach ($before as $t => $c) {
            $this->assertSame($c, DB::table($t)->count(), "Table {$t} mutated.");
        }
    }

    public function test_workspace_does_not_mutate_roles_or_permissions(): void
    {
        $this->createFixtures();
        $rc = DB::table('model_has_roles')->where('model_id', $this->financeUser->id)->count();
        $pc = DB::table('model_has_permissions')->where('model_id', $this->financeUser->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $this->assertSame($rc, DB::table('model_has_roles')->where('model_id', $this->financeUser->id)->count());
        $this->assertSame($pc, DB::table('model_has_permissions')->where('model_id', $this->financeUser->id)->count());
    }

    private function createFixtures(): void
    {
        $this->company = Company::create(['name' => 'CTW Test', 'slug' => 'ctw-test', 'is_active' => true]);
        $this->property = Property::create(['company_id' => $this->company->id, 'name' => 'CTW Property', 'slug' => 'ctw-prop', 'code' => 'CTWP', 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->financeUser = User::create(['name' => 'CTW User', 'email' => 'ctw@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
        $this->financeUser->properties()->attach($this->property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
    }

    private function propertySession(): array
    {
        return ['active_property_id' => $this->property->id, 'active_company_id' => $this->company->id, 'current_property_id' => $this->property->id];
    }
}
