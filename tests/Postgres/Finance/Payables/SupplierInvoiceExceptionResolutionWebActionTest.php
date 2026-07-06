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
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SupplierInvoiceExceptionResolutionWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $reviewer;
    private User $otherActor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => SupplierInvoiceExceptionReviewService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_resolve_exception(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
            'resolution_reason' => 'Exception resolved.',
        ])->assertRedirect();

        $this->assertNull($this->exceptionResolvedAt($invoice->id));
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertStatus(403);

        $this->assertNull($this->exceptionResolvedAt($invoice->id));
    }

    public function test_actor_with_authority_but_no_confirmation_cannot_resolve(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertNull($this->exceptionResolvedAt($invoice->id));
    }

    public function test_no_confirmation_creates_no_invoice_mutation(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();
        $snapshot = $this->invoiceSnapshot($invoice->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect();

        $this->assertSame($snapshot, $this->invoiceSnapshot($invoice->id));
    }

    public function test_wrong_intent_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-role-assignment' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-role-assignment',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_expired_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $expiredTime = Carbon::now()->subMinutes(20);
        $expiredSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredTime->toISOString(),
                    'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($expiredSession)
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_actor_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

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
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_property_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $propertyMismatchSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($propertyMismatchSession)
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_other_property_invoice_cannot_be_resolved(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        $otherInvoice = $this->makeExceptionInvoiceForProperty($this->otherProperty, 'OTHER-INV-001');
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->reviewer->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $otherInvoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
    }

    public function test_browser_injected_fields_are_ignored_during_resolution(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
                'amount' => '9999.99',
                'currency' => 'XYZ',
                'status' => 'POSTED',
                'property_id' => $this->otherProperty->id,
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($this->exceptionResolvedAt($invoice->id));
    }

    public function test_valid_confirmation_plus_authority_resolves_exception(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Price variance reviewed against PO evidence.',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($this->exceptionResolvedAt($invoice->id));
        $this->assertSame($this->reviewer->id, DB::table('vendor_invoices')->where('id', $invoice->id)->value('exception_resolved_by'));
    }

    public function test_resolution_requires_reason(): void
    {
        $this->createFixtures();
        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => '',
            ])->assertSessionHasErrors('resolution_reason');

        $this->assertNull($this->exceptionResolvedAt($invoice->id));
    }

    public function test_no_role_or_permission_mutation_on_denied_resolution(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count();

        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count());
    }

    public function test_no_role_or_permission_mutation_on_successful_resolution(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count();

        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count());
    }

    public function test_no_finance_boundary_mutation_during_exception_resolution(): void
    {
        $this->createFixtures();

        $tables = [
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

        $invoice = $this->makeExceptionInvoice();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.payables.supplier-invoices.resolve-exception', ['invoice' => $invoice->id]), [
                'resolution_reason' => 'Exception resolved.',
            ])->assertRedirect()
            ->assertSessionHas('success');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'SIER Test Company',
            'slug' => 'sier-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIER Test Property',
            'slug' => 'sier-test-property',
            'code' => 'SIER',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'SIER Other Property',
            'slug' => 'sier-other-property',
            'code' => 'SIEO',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->reviewer = $this->user('SIER Reviewer', 'sier-reviewer@example.test');
        $this->reviewer->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->reviewer->givePermissionTo(SupplierInvoiceExceptionReviewService::PERMISSION);

        $this->otherActor = $this->user('SIER Other', 'sier-other@example.test');
        $this->otherActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->noAuthUser = $this->user('SIER NoAuth', 'sier-noauth@example.test');
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

    private function makeExceptionInvoice(): SupplierInvoice
    {
        return $this->makeExceptionInvoiceForProperty($this->property, 'SIER-' . substr(hash('sha256', (string) microtime(true)), 0, 8));
    }

    private function makeExceptionInvoiceForProperty(Property $property, string $invoiceNumber): SupplierInvoice
    {
        $vendorId = (string) Str::ulid();
        $invoiceId = (string) Str::ulid();
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
            'id' => (string) Str::ulid(),
            'vendor_invoice_id' => $invoiceId,
            'property_id' => $property->id,
            'status' => MatchStatusEnum::Exception->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return SupplierInvoice::findOrFail($invoiceId);
    }

    private function exceptionResolvedAt(string $id): ?string
    {
        return DB::table('vendor_invoices')->where('id', $id)->value('exception_resolved_at');
    }

    private function invoiceSnapshot(string $id): array
    {
        return (array) DB::table('vendor_invoices')->where('id', $id)->first([
            'status', 'exception_resolved_by', 'exception_resolved_at', 'exception_resolution_reason',
            'approved_by', 'approved_at', 'updated_by', 'updated_at',
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
                    'actor_id' => $this->reviewer->id,
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
