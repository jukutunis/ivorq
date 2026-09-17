<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class JournalEntryIntegrityConstraintTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private string $propertyId;

    private string $debitAccountId;

    private string $creditAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = (string) Str::ulid();
        $this->propertyId = (string) Str::ulid();
        $this->debitAccountId = (string) Str::ulid();
        $this->creditAccountId = (string) Str::ulid();
        $timestamp = now();
        $suffix = strtolower((string) Str::ulid());

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'GL Integrity Company',
            'slug' => 'gl-integrity-company-'.$suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $this->propertyId,
            'company_id' => $companyId,
            'name' => 'GL Integrity Property',
            'slug' => 'gl-integrity-property-'.$suffix,
            'code' => 'GLI'.substr($suffix, -6),
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->insertAccount($this->debitAccountId, 'GLI-1000', 'Integrity Asset', 'Asset', 'CurrentAsset', 'Debit');
        $this->insertAccount($this->creditAccountId, 'GLI-4000', 'Integrity Revenue', 'Revenue', 'Revenue', 'Credit');
    }

    public function test_posted_journal_header_is_immutable(): void
    {
        [$journalId] = $this->createPostedJournal();

        $this->assertQueryRejected('immutable and cannot be updated', function () use ($journalId): void {
            DB::table('gl_journal_entries')->where('id', $journalId)->update(['description' => 'Forced update']);
        });

        $this->assertQueryRejected('immutable and cannot be deleted', function () use ($journalId): void {
            DB::table('gl_journal_entries')->where('id', $journalId)->delete();
        });
    }

    public function test_posted_journal_lines_are_immutable(): void
    {
        [$journalId, $lineId] = $this->createPostedJournal();

        $this->assertQueryRejected('Cannot insert a line into a posted journal entry', function () use ($journalId): void {
            $this->insertLine((string) Str::ulid(), $journalId, $this->debitAccountId, '50.00', '0.00');
        });

        $this->assertQueryRejected('Cannot update a line of a posted journal entry', function () use ($lineId): void {
            DB::table('gl_journal_entry_lines')->where('id', $lineId)->update(['memo' => 'Forced update']);
        });

        $this->assertQueryRejected('Cannot delete a line of a posted journal entry', function () use ($lineId): void {
            DB::table('gl_journal_entry_lines')->where('id', $lineId)->delete();
        });
    }

    public function test_journal_line_amount_constraints_reject_invalid_sides(): void
    {
        $journalId = $this->createDraftJournal();

        foreach ([
            ['-10.00', '0.00', 'chk_gl_jel_debit_amount_non_negative'],
            ['0.00', '-10.00', 'chk_gl_jel_credit_amount_non_negative'],
            ['0.00', '0.00', 'chk_gl_jel_single_active_side'],
            ['10.00', '10.00', 'chk_gl_jel_single_active_side'],
        ] as [$debit, $credit, $constraint]) {
            $this->assertQueryRejected($constraint, function () use ($journalId, $debit, $credit): void {
                $this->insertLine((string) Str::ulid(), $journalId, $this->debitAccountId, $debit, $credit);
            });
        }
    }

    public function test_candidate_canonical_identity_remains_unique(): void
    {
        $sourceId = (string) Str::ulid();
        $this->insertCandidate((string) Str::ulid(), $sourceId);

        $this->assertQueryRejected('uk_journal_candidates_canonical_identity', function () use ($sourceId): void {
            $this->insertCandidate((string) Str::ulid(), $sourceId);
        });
    }

    public function test_candidate_origin_and_direct_subledger_identity_indexes_do_not_conflict(): void
    {
        $sourceId = (string) Str::ulid();
        $candidateId = (string) Str::ulid();
        $this->insertCandidate($candidateId, $sourceId);

        $candidateJournalId = $this->insertJournal($sourceId, $candidateId);
        $directJournalId = $this->insertJournal($sourceId);

        $this->assertDatabaseHas('gl_journal_entries', [
            'id' => $candidateJournalId,
            'journal_candidate_id' => $candidateId,
        ]);
        $this->assertDatabaseHas('gl_journal_entries', [
            'id' => $directJournalId,
            'journal_candidate_id' => null,
        ]);
    }

    private function createPostedJournal(): array
    {
        $journalId = $this->createDraftJournal();
        $debitLineId = (string) Str::ulid();

        $this->insertLine($debitLineId, $journalId, $this->debitAccountId, '100.00', '0.00');
        $this->insertLine((string) Str::ulid(), $journalId, $this->creditAccountId, '0.00', '100.00');

        DB::table('gl_journal_entries')->where('id', $journalId)->update([
            'status' => 'Posted',
            'posting_date' => '2026-07-01',
            'posted_at' => now(),
            'updated_at' => now(),
        ]);

        return [$journalId, $debitLineId];
    }

    private function createDraftJournal(): string
    {
        return $this->insertJournal();
    }

    private function insertJournal(?string $sourceId = null, ?string $candidateId = null): string
    {
        $journalId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_journal_entries')->insert([
            'id' => $journalId,
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'description' => 'GL integrity constraint fixture',
            'status' => 'Draft',
            'source_module' => $sourceId === null ? null : 'IntegrityFixture',
            'source_type' => $sourceId === null ? null : 'IntegrityFixture',
            'source_id' => $sourceId,
            'journal_candidate_id' => $candidateId,
            'posting_event' => $candidateId === null ? null : 'IntegrityConstraintProof',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $journalId;
    }

    private function insertLine(
        string $lineId,
        string $journalId,
        string $accountId,
        string $debit,
        string $credit,
    ): void {
        DB::table('gl_journal_entry_lines')->insert([
            'id' => $lineId,
            'property_id' => $this->propertyId,
            'journal_entry_id' => $journalId,
            'account_id' => $accountId,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAccount(
        string $accountId,
        string $code,
        string $name,
        string $type,
        string $category,
        string $normalBalance,
    ): void {
        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->propertyId,
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'account_category' => $category,
            'normal_balance' => $normalBalance,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCandidate(string $candidateId, string $sourceId): void
    {
        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $this->propertyId,
            'source_type' => 'IntegrityFixture',
            'source_id' => $sourceId,
            'posting_event' => 'IntegrityConstraintProof',
            'status' => 'DRAFT',
            'candidate_date' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertQueryRejected(string $expectedMessage, Closure $operation): void
    {
        try {
            $operation();
            $this->fail("Expected database rejection containing [{$expectedMessage}].");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }
}
