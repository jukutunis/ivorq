<?php

namespace Tests\Postgres\Finance\Banking;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Services\BankingSourceEvidenceService;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankingSourceEvidenceFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $bankControlAccountId;
    private BankingSourceEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        $this->bankControlAccountId = $this->makeAccount('BANK-' . $this->sequence++);

        foreach ([
            BankingSourceEvidenceService::REGISTER_ACCOUNT_PERMISSION,
            BankingSourceEvidenceService::REGISTER_STATEMENT_LINE_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            BankingSourceEvidenceService::REGISTER_ACCOUNT_PERMISSION,
            BankingSourceEvidenceService::REGISTER_STATEMENT_LINE_PERMISSION,
        ]);

        $this->service = app(BankingSourceEvidenceService::class);
    }

    public function test_controlled_banking_source_evidence_is_registered_without_legacy_banking_mutation(): void
    {
        $before = $this->controlledSnapshot();

        $bankAccount = $this->service->registerBankAccount(
            $this->bankControlAccountId,
            'Source Bank',
            'Operating Bank Account',
            'EXT-ACCOUNT-001',
            'IDR',
            'BOARD-AUTH-ACCOUNT-001',
            $this->actor
        );

        $this->assertSame($this->property->id, $bankAccount->property_id);
        $this->assertSame($this->bankControlAccountId, $bankAccount->operational_gl_account_id);
        $this->assertSame('IDR', $bankAccount->currency_code);
        $this->assertTrue($bankAccount->is_active);
        $this->assertSame($this->actor->id, $bankAccount->registered_by);

        $line = $this->service->registerStatementLine(
            $bankAccount->id,
            'EXTERNAL-STATEMENT-DOCUMENT-001',
            'BANK-LINE-001',
            '2026-07-01',
            'OUTFLOW',
            '1250.00',
            'IDR',
            $this->actor,
            'SUPPLIER-EXT-001'
        );

        $this->assertSame($bankAccount->id, $line->controlled_bank_account_id);
        $this->assertSame($this->property->id, $line->property_id);
        $this->assertSame('BANK-LINE-001', $line->external_reference);
        $this->assertSame('OUTFLOW', $line->direction->value);
        $this->assertSame('1250.00', (string) $line->amount);
        $this->assertSame('SUPPLIER-EXT-001', $line->vendor_reference);
        $this->assertSame($this->actor->id, $line->recorded_by);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'controlled_bank_accounts' => 1,
            'controlled_bank_statement_lines' => 1,
        ]);

        $accountSnapshot = $this->controlledBankAccountSnapshot($bankAccount->id);
        $lineSnapshot = $this->controlledBankStatementLineSnapshot($line->id);

        $replayedAccount = $this->service->registerBankAccount(
            $this->bankControlAccountId,
            'Source Bank',
            'Operating Bank Account',
            'EXT-ACCOUNT-001',
            'IDR',
            'BOARD-AUTH-ACCOUNT-001',
            $this->actor
        );
        $replayedLine = $this->service->registerStatementLine(
            $bankAccount->id,
            'EXTERNAL-STATEMENT-DOCUMENT-001',
            'BANK-LINE-001',
            '2026-07-01',
            'OUTFLOW',
            '1250.00',
            'IDR',
            $this->actor,
            'SUPPLIER-EXT-001'
        );

        $this->assertSame($bankAccount->id, $replayedAccount->id);
        $this->assertSame($line->id, $replayedLine->id);
        $this->assertSame($accountSnapshot, $this->controlledBankAccountSnapshot($bankAccount->id));
        $this->assertSame($lineSnapshot, $this->controlledBankStatementLineSnapshot($line->id));
    }

    public function test_controlled_banking_source_evidence_fails_closed_for_conflicts_and_unauthorized_actor(): void
    {
        $bankAccount = $this->service->registerBankAccount(
            $this->bankControlAccountId,
            'Source Bank',
            'Operating Bank Account',
            'EXT-ACCOUNT-CONFLICT',
            'IDR',
            'BOARD-AUTH-ACCOUNT-CONFLICT',
            $this->actor
        );

        $this->service->registerStatementLine(
            $bankAccount->id,
            'EXTERNAL-STATEMENT-CONFLICT',
            'BANK-LINE-CONFLICT',
            '2026-07-02',
            'OUTFLOW',
            '500.00',
            'IDR',
            $this->actor
        );

        $beforeConflict = $this->controlledSnapshot();

        try {
            $this->service->registerStatementLine(
                $bankAccount->id,
                'EXTERNAL-STATEMENT-CONFLICT',
                'BANK-LINE-CONFLICT',
                '2026-07-02',
                'OUTFLOW',
                '600.00',
                'IDR',
                $this->actor
            );
            $this->fail('Conflicting statement line replay must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeConflict);
        }

        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $beforeUnauthorized = $this->controlledSnapshot();

        try {
            $this->service->registerBankAccount(
                $this->bankControlAccountId,
                'Other Bank',
                'Other Account',
                'EXT-ACCOUNT-UNAUTHORIZED',
                'IDR',
                'BOARD-AUTH-ACCOUNT-UNAUTHORIZED',
                $unauthorized
            );
            $this->fail('Unauthorized Banking source account registration must fail closed.');
        } catch (AuthorizationException) {
            $this->assertControlledSnapshotUnchanged($beforeUnauthorized);
        }
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'bank_accounts',
            'bank_statements',
            'bank_statement_lines',
            'reconciliation_sessions',
            'reconciliation_matches',
            'payment_executions',
            'journal_candidates',
            'gl_journal_entries',
            'gl_ledger_balances',
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

    private function controlledBankAccountSnapshot(string $bankAccountId): array
    {
        return (array) DB::table('controlled_bank_accounts')
            ->where('id', $bankAccountId)
            ->first([
                'property_id',
                'operational_gl_account_id',
                'bank_name',
                'account_name',
                'external_account_reference',
                'currency_code',
                'is_active',
                'source_reference',
                'registered_by',
                'registered_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }

    private function controlledBankStatementLineSnapshot(string $statementLineId): array
    {
        return (array) DB::table('controlled_bank_statement_lines')
            ->where('id', $statementLineId)
            ->first([
                'controlled_bank_account_id',
                'property_id',
                'source_reference',
                'external_reference',
                'statement_date',
                'direction',
                'amount',
                'currency_code',
                'vendor_reference',
                'recorded_by',
                'recorded_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }

    private function makeAccount(string $code): string
    {
        $account = Account::create([
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => 'Bank Control',
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
            'name' => 'Controlled Banking Company ' . $suffix,
            'slug' => 'controlled-banking-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Controlled Banking Property ' . $suffix,
            'slug' => 'controlled-banking-property-' . $suffix,
            'code' => 'CB' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'Controlled Banking User ' . $suffix,
            'email' => 'controlled-banking-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
