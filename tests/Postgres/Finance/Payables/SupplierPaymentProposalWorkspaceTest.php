<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SupplierPaymentProposalWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $payablesUser;
    private User $otherPropertyUser;

    public function test_unauthenticated_cannot_access_workspace(): void
    {
        $this->createFixtures();

        $this->get(route('finance.payables.payment-proposals.index'))
            ->assertRedirect();
    }

    public function test_authenticated_user_can_access_workspace(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->payablesUser, 'web')
            ->get(route('finance.payables.payment-proposals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ivorq/Finance/PaymentProposalControlWorkspace')
            );
    }

    public function test_workspace_shows_only_current_property_proposals(): void
    {
        $this->createFixtures();

        $thisProposal = $this->makeProposal($this->property, 'OUR-PROP-001');
        $otherProposal = $this->makeProposal($this->otherProperty, 'OTHER-PROP-001');

        $this->otherPropertyUser->properties()->syncWithoutDetaching([
            $this->otherProperty->id => ['is_default' => true, 'status' => 'active', 'joined_at' => now()],
        ]);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->payablesUser, 'web')
            ->get(route('finance.payables.payment-proposals.index'));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $ids = collect($props['proposals'])->pluck('id')->all();

        $this->assertContains($thisProposal->id, $ids);
        $this->assertNotContains($otherProposal->id, $ids);
    }

    public function test_capability_projection_includes_permissions(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->payablesUser, 'web')
            ->get(route('finance.payables.payment-proposals.index'));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];

        $this->assertIsBool($props['permissions']['can_create']);
        $this->assertIsBool($props['permissions']['can_cancel']);
    }

    public function test_workspace_view_does_not_mutate_finance_state(): void
    {
        $this->createFixtures();

        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_ledger_balances',
            'gl_financial_periods',
            'property_business_dates',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->withSession($this->propertySession())
            ->actingAs($this->payablesUser, 'web')
            ->get(route('finance.payables.payment-proposals.index'))
            ->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    public function test_workspace_does_not_mutate_roles_or_permissions(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->payablesUser->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->payablesUser->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->payablesUser, 'web')
            ->get(route('finance.payables.payment-proposals.index'))
            ->assertOk();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->payablesUser->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->payablesUser->id)->count());
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'PPW Test Company',
            'slug' => 'ppw-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PPW Test Property',
            'slug' => 'ppw-test-property',
            'code' => 'PPWP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PPW Other Property',
            'slug' => 'ppw-other-property',
            'code' => 'PPWO',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->payablesUser = $this->user('PPW User', 'ppw-user@example.test');
        $this->payablesUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->otherPropertyUser = $this->user('PPW Other User', 'ppw-other@example.test');
        $this->otherPropertyUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
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

    private function makeProposal(Property $property, string $proposalNumber): PaymentProposal
    {
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'category_code' => 'VEND-' . $proposalNumber,
            'name' => 'Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'company_id' => $property->company_id,
            'vendor_category_id' => DB::table('vendor_categories')->first()->id,
            'vendor_code' => 'V-' . $proposalNumber,
            'name' => 'Vendor ' . $proposalNumber,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return PaymentProposal::create([
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => $proposalNumber,
            'currency_code' => 'USD',
            'status' => 'DRAFT',
            'total_amount' => '100.00',
            'source_fingerprint' => hash('sha256', 'test-' . $proposalNumber),
            'submitted_by' => $this->payablesUser->id,
            'submitted_at' => now(),
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
}
