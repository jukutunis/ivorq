<?php

namespace Tests\Postgres\Finance\GeneralCashier;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Services\BankReturnEvidenceService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class GeneralCashierOperationsWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'FIN P01 Company', 'slug' => 'fin-p01-company', 'is_active' => true]);
        $this->property = Property::create([
            'company_id' => $this->company->id, 'name' => 'FIN P01 Property', 'slug' => 'fin-p01-property',
            'code' => 'FP01', 'timezone' => 'UTC', 'currency' => 'IDR', 'is_active' => true,
        ]);
        $this->actor = User::create([
            'name' => 'FIN P01 Operator', 'email' => 'fin-p01@example.test',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $this->actor->properties()->attach($this->property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        Permission::firstOrCreate(['name' => BankReturnEvidenceService::PERMISSION, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_workspace_is_property_scoped_and_projects_server_capabilities(): void
    {
        $response = $this->withSession($this->propertySession())->actingAs($this->actor)->get(route('finance.general-cashier.operations.index'));
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Ivorq/Finance/GeneralCashierOperationsWorkspace')
            ->has('sessions')->has('instruments')->has('cash_counts')->has('baselines')
            ->where('permissions.can_record_bank_return', false));
    }

    public function test_bank_return_action_requires_permission_then_sensitive_confirmation_without_mutation(): void
    {
        $payload = [
            'posted_journal_entry_id' => (string) Str::ulid(),
            'return_statement_line_id' => (string) Str::ulid(),
            'reason_code' => 'BANK_RETURN',
            'idempotency_key' => 'bank-return-web-001',
        ];
        $this->withSession($this->propertySession())->actingAs($this->actor)
            ->post(route('finance.general-cashier.corrections.bank-return'), $payload)->assertForbidden();

        $this->actor->givePermissionTo(BankReturnEvidenceService::PERMISSION);
        $this->withSession($this->propertySession())->actingAs($this->actor)
            ->post(route('finance.general-cashier.corrections.bank-return'), $payload)
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'supplier-payment-correction']));

        $this->assertSame(0, DB::table('bank_return_evidence')->count());
        $this->assertSame(0, DB::table('bank_supplier_payment_reversal_executions')->count());
        $this->assertSame(0, DB::table('journal_candidates')->count());
    }

    public function test_broken_legacy_vendor_invoice_routes_are_not_registered(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();
        $this->assertNotContains('api/v1/payables/vendor-invoices', $uris);
        $this->assertNotContains('api/v1/payables/vendor-invoices/{vendorInvoice}/match', $uris);
        $this->assertNotContains('api/v1/payables/vendor-invoices/{vendorInvoice}/generate-ap', $uris);
    }

    private function propertySession(): array
    {
        return ['active_company_id' => $this->company->id, 'active_property_id' => $this->property->id, 'current_property_id' => $this->property->id];
    }
}
