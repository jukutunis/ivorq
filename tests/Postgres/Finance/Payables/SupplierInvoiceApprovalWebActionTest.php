<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SupplierInvoiceApprovalWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $approver;
    private User $otherActor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => SupplierInvoiceApprovalService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_approve(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect();

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_unauthenticated_cannot_reject(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
            'rejection_reason' => 'Reason for rejection.',
        ])->assertRedirect();

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertStatus(403);

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_actor_without_permission_reject_receives_403(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
                'rejection_reason' => 'Reason.',
            ])->assertStatus(403);

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_actor_with_authority_but_no_confirmation_cannot_approve(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_actor_with_authority_but_no_confirmation_cannot_reject(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
                'rejection_reason' => 'Invoice rejected by Finance.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_no_confirmation_creates_no_invoice_mutation(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();
        $snapshot = $this->invoiceSnapshot($invoice->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect();

        $this->assertSame($snapshot, $this->invoiceSnapshot($invoice->id));
    }

    public function test_wrong_intent_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-role-assignment' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-role-assignment',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_expired_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $expiredTime = Carbon::now()->subMinutes(20);
        $expiredSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredTime->toISOString(),
                    'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($expiredSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_actor_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $actorMismatchSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->otherActor->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($actorMismatchSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_property_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $propertyMismatchSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($propertyMismatchSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_other_property_invoice_cannot_be_approved(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        $otherInvoice = $this->makeInvoiceForProperty($this->otherProperty, 'OTHER-INV-001');
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->approver->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $otherInvoice->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
    }

    public function test_other_property_invoice_cannot_be_rejected(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        $otherInvoice = $this->makeInvoiceForProperty($this->otherProperty, 'OTHER-INV-002');
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->approver->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $otherInvoice->id]), [
                'rejection_reason' => 'Rejected by Finance.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
    }

    public function test_browser_injected_fields_are_ignored_during_approval(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]), [
                'amount' => '9999.99',
                'currency' => 'XYZ',
                'account' => 'HACKED',
                'status' => 'POSTED',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(SupplierInvoice::STATUS_APPROVED, $this->invoiceStatus($invoice->id));
    }

    public function test_browser_injected_fields_are_ignored_during_rejection(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
                'rejection_reason' => 'Rejected by Finance.',
                'amount' => '9999.99',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(SupplierInvoice::STATUS_REJECTED, $this->invoiceStatus($invoice->id));
    }

    public function test_valid_confirmation_plus_authority_rejects_invoice(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
                'rejection_reason' => 'Supplier invoice rejected by Finance.',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(SupplierInvoice::STATUS_REJECTED, $this->invoiceStatus($invoice->id));
    }

    public function test_rejection_requires_reason(): void
    {
        $this->createFixtures();
        $invoice = $this->makePendingInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.reject', ['invoice' => $invoice->id]), [
                'rejection_reason' => '',
            ])->assertSessionHasErrors('rejection_reason');

        $this->assertSame(SupplierInvoice::STATUS_REGISTERED, $this->invoiceStatus($invoice->id));
    }

    public function test_no_role_or_permission_mutation_on_denied_approval(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count();

        $invoice = $this->makePendingInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count());
    }

    public function test_no_role_or_permission_mutation_on_successful_approval(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count();

        $invoice = $this->makePendingInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.supplier-invoices.approve', ['invoice' => $invoice->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count());
    }

    public function test_workspace_remains_read_safe_after_route_activation(): void
    {
        $this->createFixtures();

        $tables = [
            'vendor_invoices',
            'vendor_invoice_lines',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'journal_candidates',
            'gl_ledger_balances',
            'gl_financial_periods',
            'property_business_dates',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->get(route('finance.payables.supplier-invoices.index'))
            ->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'SIAW Test Company',
            'slug' => 'siaw-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIAW Test Property',
            'slug' => 'siaw-test-property',
            'code' => 'SIAW',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIAW Other Property',
            'slug' => 'siaw-other-property',
            'code' => 'SIAO',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->approver = $this->user('SIAW Approver', 'siaw-approver@example.test');
        $this->approver->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->approver->givePermissionTo(SupplierInvoiceApprovalService::PERMISSION);

        $this->otherActor = $this->user('SIAW Other', 'siaw-other@example.test');
        $this->otherActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->noAuthUser = $this->user('SIAW NoAuth', 'siaw-noauth@example.test');
        $this->noAuthUser->properties()->attach($this->property->id, [
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

    private function makePendingInvoice(): SupplierInvoice
    {
        return $this->makeInvoiceForProperty($this->property, 'SIAW-' . substr(hash('sha256', (string) microtime(true)), 0, 8));
    }

    private function makeInvoiceForProperty(Property $property, string $invoiceNumber): SupplierInvoice
    {
        $vendorId = (string) Str::ulid();
        $invoiceId = (string) Str::ulid();
        $matchId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'category_code' => 'VCAT-' . $invoiceNumber,
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
            'vendor_code' => 'V-' . $invoiceNumber,
            'name' => 'Vendor ' . $invoiceNumber,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendor_invoices')->insert([
            'id' => $invoiceId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $timestamp,
            'due_date' => $timestamp->copy()->addDays(30),
            'currency_code' => 'USD',
            'status' => SupplierInvoice::STATUS_REGISTERED,
            'subtotal' => '100.00',
            'tax_amount' => '10.00',
            'discount_amount' => '0.00',
            'grand_total' => '110.00',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('three_way_matches')->insert([
            'id' => $matchId,
            'vendor_invoice_id' => $invoiceId,
            'property_id' => $property->id,
            'status' => 'Matched',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return SupplierInvoice::findOrFail($invoiceId);
    }

    private function invoiceStatus(string $id): string
    {
        return DB::table('vendor_invoices')->where('id', $id)->value('status');
    }

    private function invoiceSnapshot(string $id): array
    {
        return (array) DB::table('vendor_invoices')->where('id', $id)->first([
            'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
            'updated_by', 'updated_at',
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

    private function confirmedSession(): array
    {
        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);
    }
}
