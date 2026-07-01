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
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashCountAndBaselineFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private User $counter;
    private string $cashAccountId;
    private CashCountAndBaselineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->counter = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        $this->attachActorToProperty($this->counter, $this->property);
        $this->cashAccountId = $this->makeAccount('CASH-' . $this->sequence++, true);

        foreach ([CashCountAndBaselineService::RECORD_COUNT_PERMISSION, CashCountAndBaselineService::CREATE_BASELINE_PERMISSION] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            CashCountAndBaselineService::RECORD_COUNT_PERMISSION,
            CashCountAndBaselineService::CREATE_BASELINE_PERMISSION,
        ]);

        $this->service = app(CashCountAndBaselineService::class);
    }

    public function test_cash_count_and_baseline_create_immutable_source_evidence_without_mutating_cashbook_or_ledger(): void
    {
        $before = $this->controlledSnapshot();

        $count = $this->service->recordCashCount(
            $this->cashAccountId,
            'IDR',
            '500.00',
            '2026-07-01',
            'SAFE-COUNT-2026-07-01',
            $this->counter,
            $this->actor
        );

        $this->assertSame($this->property->id, $count->property_id);
        $this->assertSame($this->cashAccountId, $count->operational_gl_account_id);
        $this->assertSame('IDR', $count->currency_code);
        $this->assertSame('500.00', (string) $count->observed_amount);
        $this->assertSame('2026-07-01', $count->observed_count_date->toDateString());
        $this->assertSame('SAFE-COUNT-2026-07-01', $count->source_reference);
        $this->assertSame($this->counter->id, $count->counted_by);
        $this->assertSame($this->actor->id, $count->recorded_by);
        $this->assertNotNull($count->recorded_at);

        $baseline = $this->service->createBaselineFromCount($count->id, $this->actor);

        $this->assertSame($count->id, $baseline->cash_count_evidence_id);
        $this->assertSame($this->property->id, $baseline->property_id);
        $this->assertSame($this->cashAccountId, $baseline->operational_gl_account_id);
        $this->assertSame('IDR', $baseline->currency_code);
        $this->assertSame('500.00', (string) $baseline->baseline_amount);
        $this->assertSame('2026-07-01', $baseline->cashbook_boundary_posted_business_date->toDateString());
        $this->assertSame($this->actor->id, $baseline->baseline_by);
        $this->assertNotNull($baseline->baseline_at);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'cash_count_evidence' => 1,
            'cash_reconciliation_baselines' => 1,
        ]);

        $countSnapshot = $this->cashCountSnapshot($count->id);
        $baselineSnapshot = $this->baselineSnapshot($baseline->id);

        $repeatCount = $this->service->recordCashCount(
            $this->cashAccountId,
            'IDR',
            '500.00',
            '2026-07-01',
            'SAFE-COUNT-2026-07-01',
            $this->counter,
            $this->actor
        );
        $repeatBaseline = $this->service->createBaselineFromCount($count->id, $this->actor);

        $this->assertSame($count->id, $repeatCount->id);
        $this->assertSame($baseline->id, $repeatBaseline->id);
        $this->assertSame($countSnapshot, $this->cashCountSnapshot($count->id));
        $this->assertSame($baselineSnapshot, $this->baselineSnapshot($baseline->id));
    }

    public function test_cash_count_and_baseline_fail_closed_for_invalid_actor_account_and_conflicting_replay(): void
    {
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $inactive = $this->makeUser(false);
        $this->attachActorToProperty($inactive, $this->property);
        $crossPropertyUser = $this->makeUser();
        $this->attachActorToProperty($crossPropertyUser, $this->makeProperty());
        $nonCashAccountId = $this->makeAccount('NONCASH-' . $this->sequence++, false);

        foreach ([$unauthorized, $inactive, $crossPropertyUser, null] as $invalidActor) {
            $before = $this->controlledSnapshot();

            try {
                $this->service->recordCashCount(
                    $this->cashAccountId,
                    'IDR',
                    '500.00',
                    '2026-07-01',
                    'INVALID-ACTOR-' . $this->sequence++,
                    $this->counter,
                    $invalidActor
                );
                $this->fail('Invalid Cash Count recorder must fail closed.');
            } catch (AuthorizationException) {
                $this->assertControlledSnapshotUnchanged($before);
            }
        }

        $before = $this->controlledSnapshot();
        try {
            $this->service->recordCashCount(
                $nonCashAccountId,
                'IDR',
                '500.00',
                '2026-07-01',
                'NON-CASH-ACCOUNT',
                $this->counter,
                $this->actor
            );
            $this->fail('Non-cash account must fail closed.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($before);
        }

        $count = $this->service->recordCashCount(
            $this->cashAccountId,
            'IDR',
            '500.00',
            '2026-07-01',
            'CONFLICT-SOURCE',
            $this->counter,
            $this->actor
        );
        $beforeConflict = $this->controlledSnapshot();

        try {
            $this->service->recordCashCount(
                $this->cashAccountId,
                'IDR',
                '501.00',
                '2026-07-01',
                'CONFLICT-SOURCE',
                $this->counter,
                $this->actor
            );
            $this->fail('Conflicting Cash Count replay must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeConflict);
        }

        $this->service->createBaselineFromCount($count->id, $this->actor);
        $otherCount = $this->service->recordCashCount(
            $this->cashAccountId,
            'IDR',
            '500.00',
            '2026-07-01',
            'SECOND-SAME-BOUNDARY',
            $this->counter,
            $this->actor
        );
        $beforeBaselineConflict = $this->controlledSnapshot();

        try {
            $this->service->createBaselineFromCount($otherCount->id, $this->actor);
            $this->fail('Second same-boundary baseline must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeBaselineConflict);
        }
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'cash_count_evidence',
            'cash_reconciliation_baselines',
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

    private function makeAccount(string $code, bool $cashEquivalent): string
    {
        $account = Account::create([
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => $cashEquivalent ? 'Cash Control' : 'Expense Control',
            'normal_balance' => 'Debit',
            'account_type' => $cashEquivalent ? 'Asset' : 'Expense',
            'account_category' => $cashEquivalent ? 'CurrentAsset' : 'Expense',
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
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
            'name' => 'Cash Count Company ' . $suffix,
            'slug' => 'cash-count-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Cash Count Property ' . $suffix,
            'slug' => 'cash-count-property-' . $suffix,
            'code' => 'CC' . $suffix,
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
            'name' => 'Cash Count User ' . $suffix,
            'email' => 'cash-count-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function cashCountSnapshot(string $countId): array
    {
        return (array) DB::table('cash_count_evidence')
            ->where('id', $countId)
            ->first([
                'property_id',
                'operational_gl_account_id',
                'currency_code',
                'observed_amount',
                'observed_count_date',
                'source_reference',
                'counted_by',
                'recorded_by',
                'recorded_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }

    private function baselineSnapshot(string $baselineId): array
    {
        return (array) DB::table('cash_reconciliation_baselines')
            ->where('id', $baselineId)
            ->first([
                'cash_count_evidence_id',
                'property_id',
                'operational_gl_account_id',
                'currency_code',
                'baseline_amount',
                'cashbook_boundary_posted_business_date',
                'baseline_by',
                'baseline_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }
}
