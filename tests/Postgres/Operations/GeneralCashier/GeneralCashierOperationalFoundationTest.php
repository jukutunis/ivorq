<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Services\GeneralCashierOperationalFoundationService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class GeneralCashierOperationalFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private User $actor;
    private GeneralCashierOperationalFoundationService $service;
    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        Permission::firstOrCreate([
            'name' => GeneralCashierOperationalFoundationService::OPEN_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(GeneralCashierOperationalFoundationService::OPEN_PERMISSION);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->service = app(GeneralCashierOperationalFoundationService::class);
    }

    public function test_authorized_active_actor_opens_one_property_scoped_cashier_session(): void
    {
        $before = $this->forbiddenMutationSnapshot();

        $session = $this->service->openSession($this->actor);

        $this->assertSame($this->property->id, $session->property_id);
        $this->assertSame($this->actor->id, $session->cashier_user_id);
        $this->assertSame(CashierSessionStatusEnum::OPEN, $session->status);
        $this->assertSame($this->actor->id, $session->opened_by);
        $this->assertNotNull($session->opened_at);
        $this->assertNull($session->closed_at);
        $this->assertNoForbiddenMutation($before);

        $repeat = $this->service->openSession($this->actor);

        $this->assertSame($session->id, $repeat->id);
        $this->assertSame(1, DB::table('cashier_sessions')
            ->where('property_id', $this->property->id)
            ->where('cashier_user_id', $this->actor->id)
            ->where('status', CashierSessionStatusEnum::OPEN->value)
            ->count());

        $this->assertSame(1, DB::table('cashier_sessions')
            ->where('property_id', $this->property->id)
            ->where('cashier_user_id', $this->actor->id)
            ->where('status', CashierSessionStatusEnum::OPEN->value)
            ->count());
    }

    public function test_missing_disabled_unauthorized_and_cross_property_actor_fail_closed_without_session(): void
    {
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $crossProperty = $this->makeAuthorizedActor($this->makeProperty());

        foreach ([null, $unauthorized, $disabled, $crossProperty] as $invalidActor) {
            $before = $this->forbiddenMutationSnapshot();

            try {
                $this->service->openSession($invalidActor);
                $this->fail('Invalid General Cashier actor must fail closed.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('cashier_sessions')->count());
                $this->assertNoForbiddenMutation($before);
            }
        }
    }

    public function test_session_owner_closes_open_session_with_immutable_evidence(): void
    {
        $session = $this->service->openSession($this->actor);
        $nonOwner = $this->makeAuthorizedActor($this->property);
        $before = $this->forbiddenMutationSnapshot();

        try {
            $this->service->closeSession($session->id, $nonOwner);
            $this->fail('Non-owner Cashier Session close must fail controlled.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('session owner', $exception->getMessage());
            $this->assertSame(CashierSessionStatusEnum::OPEN->value, DB::table('cashier_sessions')->where('id', $session->id)->value('status'));
        }

        $closed = $this->service->closeSession($session->id, $this->actor);

        $this->assertSame(CashierSessionStatusEnum::CLOSED, $closed->status);
        $this->assertSame($this->actor->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
        $this->assertNoForbiddenMutation($before);

        $closedAt = $closed->closed_at->toIso8601String();
        $repeat = $this->service->closeSession($session->id, $this->actor);

        $this->assertSame($session->id, $repeat->id);
        $this->assertSame($closedAt, $repeat->closed_at->toIso8601String());
    }

    public function test_payment_instrument_resolves_active_property_scoped_operational_context(): void
    {
        $session = $this->service->openSession($this->actor);
        $accountId = $this->makeGlAccount($this->property, '1000', true);
        $instrument = $this->makeInstrument($this->property, $accountId, true);
        $before = $this->forbiddenMutationSnapshot();

        $context = $this->service->resolveOperationalContext($session->id, $instrument->id, $this->actor);

        $this->assertSame([
            'property_id',
            'cashier_session_id',
            'cashier_payment_instrument_id',
            'instrument_type',
            'operational_gl_account_id',
        ], array_keys($context));
        $this->assertSame($this->property->id, $context['property_id']);
        $this->assertSame($session->id, $context['cashier_session_id']);
        $this->assertSame($instrument->id, $context['cashier_payment_instrument_id']);
        $this->assertSame(CashierPaymentInstrumentTypeEnum::CASH->value, $context['instrument_type']);
        $this->assertSame($accountId, $context['operational_gl_account_id']);
        $this->assertNoForbiddenMutation($before);
    }

    public function test_operational_context_fails_closed_for_invalid_session_instrument_actor_or_account(): void
    {
        $session = $this->service->openSession($this->actor);
        $accountId = $this->makeGlAccount($this->property, '1001', true);
        $instrument = $this->makeInstrument($this->property, $accountId, true);
        $inactiveInstrument = $this->makeInstrument($this->property, $this->makeGlAccount($this->property, '1002', true), false);
        $inactiveAccountInstrument = $this->makeInstrument($this->property, $this->makeGlAccount($this->property, '1003', false), true);
        $otherProperty = $this->makeProperty();
        $crossPropertyInstrument = $this->makeInstrument($otherProperty, $this->makeGlAccount($otherProperty, '1004', true), true);
        $otherActor = $this->makeAuthorizedActor($this->property);

        foreach ([
            fn () => $this->service->resolveOperationalContext($session->id, $inactiveInstrument->id, $this->actor),
            fn () => $this->service->resolveOperationalContext($session->id, $inactiveAccountInstrument->id, $this->actor),
            fn () => $this->service->resolveOperationalContext($session->id, $crossPropertyInstrument->id, $this->actor),
            fn () => $this->service->resolveOperationalContext($session->id, $instrument->id, $otherActor),
        ] as $invalidResolution) {
            $before = $this->forbiddenMutationSnapshot();

            try {
                $invalidResolution();
                $this->fail('Invalid General Cashier operational context must fail closed.');
            } catch (AuthorizationException|DomainException) {
                $this->assertNoForbiddenMutation($before);
            }
        }

        $closed = $this->service->closeSession($session->id, $this->actor);

        try {
            $this->service->resolveOperationalContext($closed->id, $instrument->id, $this->actor);
            $this->fail('Closed Cashier Session must not resolve operational context.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Open', $exception->getMessage());
        }
    }

    private function makeAuthorizedActor(Property $property, bool $active = true): User
    {
        $actor = $this->makeUser($active);
        $this->attachActorToProperty($actor, $property);

        if ($active) {
            $actor->givePermissionTo(GeneralCashierOperationalFoundationService::OPEN_PERMISSION);
        }

        return $actor;
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'General Cashier Company ' . $suffix,
            'slug' => 'general-cashier-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'General Cashier Property ' . $suffix,
            'slug' => 'general-cashier-property-' . $suffix,
            'code' => 'GCP' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(bool $active = true): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'General Cashier User ' . $suffix,
            'email' => 'general-cashier-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function makeGlAccount(Property $property, string $code, bool $active): string
    {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $property->id,
            'code' => $code,
            'name' => 'General Cashier Account ' . $code,
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => $active,
            'is_cash_equivalent' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeInstrument(Property $property, string $accountId, bool $active): CashierPaymentInstrument
    {
        return CashierPaymentInstrument::create([
            'property_id' => $property->id,
            'name' => 'Instrument ' . $this->sequence++,
            'type' => CashierPaymentInstrumentTypeEnum::CASH->value,
            'operational_gl_account_id' => $accountId,
            'is_active' => $active,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function forbiddenMutationSnapshot(): array
    {
        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'vendor_invoices',
            'vendor_invoice_lines',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'accounts_payables',
            'payment_vouchers',
            'payment_voucher_lines',
            'bank_transactions',
            'bank_statements',
            'bank_statement_lines',
            'reconciliation_matches',
            'reconciliation_sessions',
            'financial_periods',
            'gl_financial_periods',
            'property_business_dates',
            'purchase_orders',
            'purchase_order_lines',
            'receiving_documents',
            'receiving_lines',
            'inventory_transactions',
            'inventory_receipts',
            'inventory_receipt_lines',
            'cost_ledger_entries',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $snapshot;
    }

    private function assertNoForbiddenMutation(array $before): void
    {
        $this->assertSame($before, $this->forbiddenMutationSnapshot());
    }
}
