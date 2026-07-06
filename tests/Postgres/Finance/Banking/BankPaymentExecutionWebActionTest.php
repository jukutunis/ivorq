<?php

namespace Tests\Postgres\Finance\Banking;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
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

class BankPaymentExecutionWebActionTest extends PostgresTestCase
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

        $this->post(route('finance.banking.bank-payment-execute.execute'), [
            'payment_proposal_item_id' => $context['item_id'],
            'cashier_session_id' => $context['session_id'],
            'bank_payment_instrument_id' => $context['instrument_id'],
            'controlled_bank_account_id' => $context['bank_account_id'],
            'controlled_bank_statement_line_id' => $context['statement_line_id'],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertStatus(403);

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_cross_property_payment_target_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_cross_property_bank_account_target_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $otherBankAccountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherProperty->id,
            'code' => 'OTHER-BA-' . $timestamp->timestamp,
            'name' => 'Other Bank GL',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_accounts')->insert([
            'id' => $otherBankAccountId,
            'property_id' => $this->otherProperty->id,
            'operational_gl_account_id' => DB::table('gl_accounts')->where('property_id', $this->otherProperty->id)->first()->id,
            'bank_name' => 'Cross Prop Bank',
            'account_name' => 'Cross Prop Account',
            'external_account_reference' => 'CROSS-BA',
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'cross-prop-test',
            'registered_by' => $this->executor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'cross-prop-ba'),
            'source_snapshot' => json_encode([]),
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $otherBankAccountId,
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_browser_injected_amount_is_ignored(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
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
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
                'currency_code' => 'USD',
            ])->assertRedirect();

        $execution = DB::table('payment_executions')->first();
        $this->assertSame('IDR', $execution->currency_code);
    }

    public function test_browser_injected_property_is_ignored(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
                'property_id' => $this->otherProperty->id,
                'company_id' => 'fake-company',
                'actor_id' => 'fake-actor',
            ])->assertRedirect();

        $execution = DB::table('payment_executions')->first();
        $this->assertSame($this->property->id, $execution->property_id);
    }

    public function test_no_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_no_confirmation_denial_creates_no_domain_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ]);

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_wrong_intent_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->otherIntentSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_expired_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $expiredAt = Carbon::now()->subMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES + 5);

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'bank-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'bank-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredAt->toISOString(),
                    'expires_at' => $expiredAt->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_actor_mismatched_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'bank-payment-execution' => [
                    'actor_id' => $this->otherActor->id,
                    'intent' => 'bank-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_property_mismatched_confirmation_denies_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession(array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'bank-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'bank-payment-execution',
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]))
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution']));

        $this->assertSame(0, DB::table('payment_executions')->count());
    }

    public function test_confirmation_denial_causes_no_payment_execution_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $beforeExec = DB::table('payment_executions')->count();
        $beforeRecon = DB::table('bank_payment_reconciliations')->count();
        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeSessions = DB::table('cashier_sessions')->count();
        $beforeInstruments = DB::table('cashier_payment_instruments')->count();

        $this->withSession($this->otherIntentSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ]);

        $this->assertSame($beforeExec, DB::table('payment_executions')->count());
        $this->assertSame($beforeRecon, DB::table('bank_payment_reconciliations')->count());
        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeSessions, DB::table('cashier_sessions')->count());
        $this->assertSame($beforeInstruments, DB::table('cashier_payment_instruments')->count());
    }

    public function test_valid_execution_succeeds(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('payment_executions')->count());

        $execution = DB::table('payment_executions')->first();
        $this->assertSame($this->property->id, $execution->property_id);
        $this->assertSame($context['item_id'], $execution->payment_proposal_item_id);
        $this->assertSame($context['session_id'], $execution->cashier_session_id);
        $this->assertSame($context['instrument_id'], $execution->cashier_payment_instrument_id);
        $this->assertSame($context['bank_account_id'], $execution->controlled_bank_account_id);
        $this->assertSame($context['statement_line_id'], $execution->controlled_bank_statement_line_id);
        $this->assertSame('125.00', $execution->source_amount);
        $this->assertSame($this->executor->id, $execution->executed_by);
    }

    public function test_idempotent_replay_preserves_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $first = DB::table('payment_executions')->first();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
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
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')->where('model_id', $this->executor->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')->where('model_id', $this->executor->id)->count());
    }

    public function test_banking_workspace_remains_property_isolated_after_execution(): void
    {
        $this->createFixtures();
        $context = $this->makeExecutionContext();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->executor, 'web')
            ->post(route('finance.banking.bank-payment-execute.execute'), [
                'payment_proposal_item_id' => $context['item_id'],
                'cashier_session_id' => $context['session_id'],
                'bank_payment_instrument_id' => $context['instrument_id'],
                'controlled_bank_account_id' => $context['bank_account_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->executor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertCount(0, $props['bank_execution_evidence'] ?? []);
    }

    private function createFixtures(): void
    {
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Bank Exec Web Company ' . $suffix,
            'slug' => 'bank-exec-web-company-' . $suffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Exec Web Property ' . $suffix,
            'slug' => 'bank-exec-web-property-' . $suffix,
            'code' => 'BEW' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Exec Web Other ' . $suffix,
            'slug' => 'bank-exec-web-other-' . $suffix,
            'code' => 'BEO' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->executor = $this->user('Bank Executor ' . $suffix, 'bank-executor-' . $suffix . '@example.test');
        $this->executor->givePermissionTo(PaymentExecutionService::PERMISSION);
        $this->attachProperty($this->executor, $this->property);
        $this->attachProperty($this->executor, $this->otherProperty);

        $this->otherActor = $this->user('Bank Other Actor ' . $suffix, 'bank-other-actor-' . $suffix . '@example.test');
        $this->attachProperty($this->otherActor, $this->property);

        $this->noAuthUser = $this->user('Bank NoAuth ' . $suffix, 'bank-noauth-' . $suffix . '@example.test');
        $this->attachProperty($this->noAuthUser, $this->property);
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
        $bankAccountId = (string) Str::ulid();
        $statementLineId = (string) Str::ulid();
        $bankGlAccountId = (string) Str::ulid();
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
            'code' => 'BANK-EXEC-' . $suffix,
            'name' => 'Bank Exec Account',
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

        DB::table('gl_accounts')->insert([
            'id' => $bankGlAccountId,
            'property_id' => $this->property->id,
            'code' => 'BANK-GL-' . $suffix,
            'name' => 'Bank GL Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
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
            'description' => 'AP source for bank exec web',
            'approved_by' => $this->executor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'bank_exec_web']),
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
            'description' => 'Posted AP liability for bank exec web',
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
            'proposal_number' => 'BEW-PROP-' . $suffix,
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
            'source_snapshot' => json_encode(['test_scope' => 'bank_exec_web']),
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
            'name' => 'Bank Transfer ' . $suffix,
            'type' => CashierPaymentInstrumentTypeEnum::BANK->value,
            'operational_gl_account_id' => $bankGlAccountId,
            'is_active' => true,
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_accounts')->insert([
            'id' => $bankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $bankGlAccountId,
            'bank_name' => 'Test Bank ' . $suffix,
            'account_name' => 'Operating Account ' . $suffix,
            'external_account_reference' => 'EXT-BA-' . $suffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'bank-exec-test',
            'registered_by' => $this->executor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'bank-exec-account-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_exec_web']),
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => $statementLineId,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'stmt-exec-' . $suffix,
            'external_reference' => 'EXT-STMT-' . $suffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => '125.00',
            'currency_code' => 'IDR',
            'vendor_reference' => $vendorId,
            'recorded_by' => $this->executor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'bank-exec-stmt-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_exec_web']),
            'created_by' => $this->executor->id,
            'updated_by' => $this->executor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'item_id' => $proposalItemId,
            'session_id' => $sessionId,
            'instrument_id' => $instrumentId,
            'bank_account_id' => $bankAccountId,
            'statement_line_id' => $statementLineId,
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

    private function attachProperty(User $user, Property $property): void
    {
        $user->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
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
                'bank-payment-execution' => [
                    'actor_id' => $this->executor->id,
                    'intent' => 'bank-payment-execution',
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
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'bank_payment_reconciliations',
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
