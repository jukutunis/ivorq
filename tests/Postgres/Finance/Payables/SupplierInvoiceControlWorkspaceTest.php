<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SupplierInvoiceControlWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $financeUser;
    private User $otherPropertyUser;

    public function test_unauthenticated_actor_cannot_access_workspace(): void
    {
        $this->createFixtures();

        $this->get(route('finance.payables.supplier-invoices.index'))
            ->assertRedirect();
    }

    public function test_authenticated_actor_can_load_workspace(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ivorq/Finance/SupplierInvoiceControlWorkspace')
                ->has('invoices')
            );
    }

    public function test_workspace_shows_only_current_property_invoices(): void
    {
        $this->createFixtures();

        $ourInvoice = $this->makeInvoice($this->property, 'INV-OUR-001', 'approved');
        $otherInvoice = $this->makeInvoice($this->otherProperty, 'INV-OTHER-001', 'draft');

        $this->otherPropertyUser->properties()->syncWithoutDetaching([
            $this->otherProperty->id => ['is_default' => true, 'status' => 'active', 'joined_at' => now()],
        ]);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $ids = collect($props['invoices'])->pluck('id')->all();

        $this->assertContains($ourInvoice->id, $ids);
        $this->assertNotContains($otherInvoice->id, $ids);
    }

    public function test_workspace_projects_source_proven_invoice_evidence(): void
    {
        $this->createFixtures();

        $invoice = $this->makeInvoice($this->property, 'INV-EVIDENCE-001', 'pending_review');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $displayed = collect($props['invoices'])->firstWhere('id', $invoice->id);

        $this->assertNotNull($displayed, 'Invoice should appear in workspace projection');
        $this->assertSame('INV-EVIDENCE-001', $displayed['vendor_invoice_number']);
        $this->assertNotNull($displayed['vendor_name']);
        $this->assertSame('pending_review', $displayed['status']);
        $this->assertSame('Pending Review', $displayed['status_label']);
        $this->assertGreaterThanOrEqual(0, (int) $displayed['line_count']);
    }

    public function test_workspace_read_does_not_mutate_finance_state(): void
    {
        $this->createFixtures();

        $tables = [
            'ap_invoices',
            'ap_invoice_lines',
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
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'))
            ->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    public function test_no_role_or_permission_mutation_occurs(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->financeUser->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->financeUser->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'))
            ->assertOk();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->financeUser->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->financeUser->id)->count());
    }

    public function test_workspace_exposes_only_read_actions(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk();

        $props = $response->original->getData()['page']['props'];
        $this->assertArrayHasKey('permissions', $props, 'Workspace should project server-computed capabilities');
        $this->assertIsBool($props['permissions']['can_approve']);
        $this->assertIsBool($props['permissions']['can_reject']);
    }

    public function test_controlled_empty_state_is_safe(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoices')
            );

        $props = $response->original->getData()['page']['props'];
        $this->assertIsArray($props['invoices']);
        $this->assertEmpty($props['invoices']);
    }

    public function test_existing_route_controller_contract_remains_unchanged(): void
    {
        $this->createFixtures();

        $this->makeInvoice($this->property, 'INV-CONTRACT-001', 'approved');
        $this->makeInvoice($this->property, 'INV-CONTRACT-002', 'rejected');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeUser, 'web')
            ->get(route('finance.payables.supplier-invoices.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ivorq/Finance/SupplierInvoiceControlWorkspace')
            );
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'SIW Test Company',
            'slug' => 'siw-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIW Test Property',
            'slug' => 'siw-test-property',
            'code' => 'SIWP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIW Other Property',
            'slug' => 'siw-other-property',
            'code' => 'SIWO',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->financeUser = $this->user('SIW Finance User', 'siw-finance@example.test');
        $this->financeUser->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->otherPropertyUser = $this->user('SIW Other User', 'siw-other@example.test');
        $this->otherPropertyUser->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
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

    private function makeInvoice(Property $property, string $invoiceNumber, string $status): ApInvoice
    {
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'category_code' => 'VCAT-' . Str::random(4),
            'name' => 'Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'company_id' => $property->company_id,
            'vendor_category_id' => DB::table('vendor_categories')
                ->where('property_id', $property->id)
                ->first()->id,
            'vendor_code' => 'V-' . Str::random(6),
            'name' => 'Vendor for ' . $invoiceNumber,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ApInvoice::create([
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'invoice_type' => 'grni_matched',
            'vendor_invoice_number' => $invoiceNumber,
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'subtotal_amount' => '80.00',
            'tax_amount' => '20.00',
            'grand_total_amount' => '100.00',
            'amount_paid' => '0.00',
            'amount_remaining' => '100.00',
            'status' => $status,
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
