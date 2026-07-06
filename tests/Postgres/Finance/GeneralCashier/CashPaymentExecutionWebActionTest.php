<?php

namespace Tests\Postgres\Finance\GeneralCashier;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashPaymentExecutionWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $executor;
    private User $otherActor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => PaymentExecutionService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_execute(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->post(route('finance.payables.cash-payment-execute.execute'), [
            'payment_proposal_item_id' => $context['item_id'],
            'cashier_session_id' => $context['session_id'],
            'cashier_payment_instrument_id' => $context['instrument_id'],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertStatus(403);

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_cross_property_payment_target_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_browser_injected_amount_is_ignored(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
                'amount' => '9999.99',
            ])->assertRedirect();

        $execution = DB::table('payment_executions')->first();
        $this->assertSame('125.00', $execution->source_amount);
    }

    public function test_browser_injected_currency_is_ignored(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
                'currency_code' => 'USD',
            ])->assertRedirect();

        $execution = DB::table('payment_executions')->first();
        $this->assertSame('IDR', $execution->currency_code);
    }

    public function test_no_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'cash-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_no_confirmation_denial_creates_no_domain_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ]);

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_wrong_intent_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->otherIntentSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'cash-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_expired_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $expiredAt = Carbon::now()->subMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES + 5);

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'cash-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'cash-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredAt->toISOString(),
                    'expires_at' => $expiredAt->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'cash-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_actor_mismatched_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'cash-payment-execution' => [
                    'actor_id' => $this->otherActor->id,
                    'intent' => 'cash-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'cash-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_property_mismatched_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'cash-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'cash-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'cash-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_valid_execution_succeeds(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('payment_executions')->count());

        $execution = DB::table('payment_executions')->first();
        $this->assertSame($this->property->id, $execution->property_id);
        $this->assertSame($context['item_id'], $execution->payment_proposal_item_id);
        $this->assertSame($context['session_id'], $execution->cashier_session_id);
        $this->assertSame($context['instrument_id'], $execution->cashier_payment_instrument_id);
        $this->assertSame('125.00', $execution->source_amount);
        $this->assertSame($this->executor->id, $execution->executed_by);
    }

    public function test_idempotent_replay_preserves_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect();

        $first = DB::table('payment_executions')->first();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect();

        $this->assertSame(1, DB::table('payment_executions')->count());
        $replay = DB::table('payment_executions')->first();
        $this->assertSame($first->id, $replay->id);
    }

    public function test_no_role_or_permission_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $roleCountBefore = DB::table('model_has_roles')->where('model_id', $this->executor->id)->count();
        $permCountBefore = DB::table('model_has_permissions')->where('model_id', $this->executor->id)->count();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.payables.cash-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'cashier_payment_instrument_id' => $context['instrument_id'],
            ])->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')->where('model_id', $this->executor->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')->where('model_id', $this->executor->id)->count());
    }

    private function createFixtures(): void
    {
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Cash Exec Web Company ' . $suffix,
            'slug' => 'cash-exec-web-company-' . $suffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Exec Web Property ' . $suffix,
            'slug' => 'cash-exec-web-property-' . $suffix,
            'code' => 'CEW' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Exec Web Other ' . $suffix,
            'slug' => 'cash-exec-web-other-' . $suffix,
            'code' => 'CEO' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->executor = $this->user('Cash Exec Web Actor ' . $suffix, 'cash-exec-web-actor-' . $suffix . '@example.test');
        $this->executor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->executor->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->executor->givePermissionTo(PaymentExecutionService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->otherActor = $this->user('Cash Exec Web Other ' . $suffix, 'cash-exec-web-other-' . $suffix . '@example.test');
        $this->otherActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->noAuthUser = $this->user('Cash Exec Web NoAuth ' . $suffix, 'cash-exec-web-noauth-' . $suffix . '@example.test');
        $this->noAuthUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function makeExecutionContext(): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        $instrumentId = (string) Str::ulid();
        $acctId = (string) Str::ulid();
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'category_code' => 'VC-' . $suffix,
            'name' => 'Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $this->property->id,
            'company_id' => $this->company->id,
            'vendor_category_id' => DB::table('vendor_categories')->first()->id,
            'vendor_code' => 'V-' . $suffix,
            'name' => 'Vendor ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_accounts')->insert([
            'id' => $acctId,
            'property_id' => $this->property->id,
            'code' => 'CASH-' . $suffix,
            'name' => 'Cash Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source for cash exec web',
            'approved_by' => $this->executor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'cash_exec_web']),
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $apJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-JRNL-' . $suffix,
            'description' => 'Posted AP liability for cash exec web',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $acctId,
                'debit_amount' => '0.00',
                'credit_amount' => '125.00',
                'memo' => 'Credit AP',
                'created_by' => $this->executor->id,
                'updated_by' => $this->executor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $acctId,
                'debit_amount' => '125.00',
                'credit_amount' => '0.00',
                'memo' => 'Debit inventory',
                'created_by' => $this->executor->id,
                'updated_by' => $this->executor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->executor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->executor->id,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'CEW-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => PaymentProposalStatusEnum::APPROVED->value,
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => '125.00',
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => '125.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'cash_exec_web']),
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_sessions')->insert([
            'id' => $sessionId,
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->executor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_by' => $this->executor->id,
            'opened_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'Cash Drawer ' . $suffix,
            'type' => CashierPaymentInstrumentTypeEnum::CASH->value,
            'operational_gl_account_id' => $acctId,
            'is_active' => true,
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'item_id' => $proposalItemId,
            'session_id' => $sessionId,
            'instrument_id' => $instrumentId,
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

    private function confirmedSession(): array
    {
        $now = Carbon::now();

        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'cash-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'cash-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]);
    }

    private function otherIntentSession(): array
    {
        $now = Carbon::now();

        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]);
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'payment_executions',
            'payment_proposals',
            'payment_proposal_items',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'cashier_sessions',
            'cashier_payment_instruments',
            'cashbook_transactions',
            'journal_candidates',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Table {$table} mutated.");
        }
    }
}
