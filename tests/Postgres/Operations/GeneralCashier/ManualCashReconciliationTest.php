<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Services\CashCountAndBaselineService;
use Modules\Operations\GeneralCashier\Services\ManualCashReconciliationService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ManualCashReconciliationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $cashAccountId;
    private CashCountAndBaselineService $countService;
    private ManualCashReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        $this->cashAccountId = $this->makeAccount('CASH-' . $this->sequence++);

        foreach ([
            CashCountAndBaselineService::RECORD_COUNT_PERMISSION,
            CashCountAndBaselineService::CREATE_BASELINE_PERMISSION,
            ManualCashReconciliationService::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            CashCountAndBaselineService::RECORD_COUNT_PERMISSION,
            CashCountAndBaselineService::CREATE_BASELINE_PERMISSION,
            ManualCashReconciliationService::PERMISSION,
        ]);

        $this->countService = app(CashCountAndBaselineService::class);
        $this->reconciliationService = app(ManualCashReconciliationService::class);
    }

    public function test_manual_cash_reconciliation_reconciles_zero_difference_without_source_mutation(): void
    {
        $baseline = $this->makeBaseline('1000.00', '2026-07-01', 'BASELINE-COUNT');
        $this->makeCashbookTransaction('2026-07-02', 'OUTFLOW', '125.00');
        $ending = $this->recordCount('875.00', '2026-07-03', 'ENDING-COUNT');
        $before = $this->controlledSnapshot();

        $reconciliation = $this->reconciliationService->reconcile($baseline->id, $ending->id, $this->actor);

        $this->assertSame($this->property->id, $reconciliation->property_id);
        $this->assertSame($this->cashAccountId, $reconciliation->operational_gl_account_id);
        $this->assertSame('IDR', $reconciliation->currency_code);
        $this->assertSame('2026-07-01', $reconciliation->scope_start_exclusive_date->toDateString());
        $this->assertSame('2026-07-03', $reconciliation->scope_end_inclusive_date->toDateString());
        $this->assertSame('1000.00', (string) $reconciliation->baseline_amount);
        $this->assertSame('0.00', (string) $reconciliation->cashbook_inflow_amount);
        $this->assertSame('125.00', (string) $reconciliation->cashbook_outflow_amount);
        $this->assertSame('875.00', (string) $reconciliation->expected_amount);
        $this->assertSame('875.00', (string) $reconciliation->observed_amount);
        $this->assertSame('0.00', (string) $reconciliation->difference_amount);
        $this->assertSame('RECONCILED', $reconciliation->status->value);
        $this->assertSame($this->actor->id, $reconciliation->reconciled_by);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'cash_reconciliations' => 1,
        ]);

        $snapshot = $this->reconciliationSnapshot($reconciliation->id);
        $repeat = $this->reconciliationService->reconcile($baseline->id, $ending->id, $this->actor);

        $this->assertSame($reconciliation->id, $repeat->id);
        $this->assertSame($snapshot, $this->reconciliationSnapshot($reconciliation->id));
    }

    public function test_manual_cash_reconciliation_records_exception_and_blocks_invalid_or_overlapping_scope(): void
    {
        $baseline = $this->makeBaseline('1000.00', '2026-07-01', 'BASELINE-EXCEPTION');
        $this->makeCashbookTransaction('2026-07-02', 'OUTFLOW', '125.00');
        $ending = $this->recordCount('870.00', '2026-07-03', 'ENDING-EXCEPTION');

        $exception = $this->reconciliationService->reconcile($baseline->id, $ending->id, $this->actor);
        $this->assertSame('EXCEPTION', $exception->status->value);
        $this->assertSame('-5.00', (string) $exception->difference_amount);

        $overlapBaseline = $this->makeBaseline('870.00', '2026-07-02', 'OVERLAP-BASELINE');
        $overlapEnding = $this->recordCount('870.00', '2026-07-04', 'OVERLAP-ENDING');
        $beforeOverlap = $this->controlledSnapshot();

        try {
            $this->reconciliationService->reconcile($overlapBaseline->id, $overlapEnding->id, $this->actor);
            $this->fail('Overlapping reconciliation scope must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeOverlap);
        }

        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $futureBaseline = $this->makeBaseline('900.00', '2026-07-10', 'INVALID-ACTOR-BASELINE');
        $futureEnding = $this->recordCount('900.00', '2026-07-11', 'INVALID-ACTOR-ENDING');
        $beforeUnauthorized = $this->controlledSnapshot();

        try {
            $this->reconciliationService->reconcile($futureBaseline->id, $futureEnding->id, $unauthorized);
            $this->fail('Unauthorized reconciler must fail closed.');
        } catch (AuthorizationException) {
            $this->assertControlledSnapshotUnchanged($beforeUnauthorized);
        }
    }

    private function makeBaseline(string $amount, string $date, string $reference): object
    {
        $count = $this->recordCount($amount, $date, $reference);

        return $this->countService->createBaselineFromCount($count->id, $this->actor);
    }

    private function recordCount(string $amount, string $date, string $reference): object
    {
        return $this->countService->recordCashCount(
            $this->cashAccountId,
            'IDR',
            $amount,
            $date,
            $reference,
            $this->actor,
            $this->actor
        );
    }

    private function makeCashbookTransaction(string $date, string $direction, string $amount): void
    {
        $timestamp = now();

        DB::table('cashbook_transactions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $this->cashAccountId,
            'currency_code' => 'IDR',
            'amount' => $amount,
            'direction' => $direction,
            'posted_business_date' => $date,
            'journal_entry_id' => (string) Str::ulid(),
            'payment_execution_id' => (string) Str::ulid(),
            'source_module' => 'GeneralLedger',
            'source_type' => 'JournalEntry',
            'source_id' => (string) Str::ulid(),
            'source_event' => 'SupplierPaymentCashDisbursement',
            'source_identity_hash' => hash('sha256', $date . $direction . $amount . $this->sequence++),
            'source_snapshot' => json_encode(['test_scope' => 'manual_cash_reconciliation']),
            'projected_by' => $this->actor->id,
            'projected_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'cash_count_evidence',
            'cash_reconciliation_baselines',
            'cash_reconciliations',
            'cashbook_transactions',
            'payment_executions',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'financial_periods',
            'gl_financial_periods',
            'property_business_dates',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::getSchemaBuilder()->hasTable($table)
                ? DB::table($table)->count()
                : 0;
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $this->assertSame($before, $this->controlledSnapshot());
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
    }

    private function makeAccount(string $code): string
    {
        $account = Account::create([
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => 'Cash Control',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->actor?->id,
            'updated_by' => $this->actor?->id,
        ]);

        return $account->id;
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
            'name' => 'Cash Reconciliation Company ' . $suffix,
            'slug' => 'cash-reconciliation-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Cash Reconciliation Property ' . $suffix,
            'slug' => 'cash-reconciliation-property-' . $suffix,
            'code' => 'CR' . $suffix,
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
            'name' => 'Cash Reconciliation User ' . $suffix,
            'email' => 'cash-reconciliation-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function reconciliationSnapshot(string $reconciliationId): array
    {
        return (array) DB::table('cash_reconciliations')
            ->where('id', $reconciliationId)
            ->first([
                'cash_reconciliation_baseline_id',
                'ending_cash_count_evidence_id',
                'property_id',
                'operational_gl_account_id',
                'currency_code',
                'scope_start_exclusive_date',
                'scope_end_inclusive_date',
                'baseline_amount',
                'cashbook_inflow_amount',
                'cashbook_outflow_amount',
                'expected_amount',
                'observed_amount',
                'difference_amount',
                'status',
                'reconciled_by',
                'reconciled_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }
}
