<?php

namespace {
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\DB;
    use Modules\Finance\CostControl\Services\CostLedgerAppendService;
    use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
    use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;

    if (($argv[1] ?? null) === '--cc-p01c-ledger-worker') {
        $config = json_decode((string) file_get_contents($argv[2] ?? ''), true);
        if (! is_array($config)) {
            exit(2);
        }

        require $config['base_path'].'/vendor/autoload.php';
        $app = require $config['base_path'].'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['database.connections.pgsql.database' => $config['database']]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $result = [
            'pid' => getmypid(),
            'pg_backend_pid' => (int) DB::scalar('SELECT pg_backend_pid()'),
            'outcome' => 'ERROR',
            'message' => null,
        ];

        try {
            touch($config['barrier_dir'].'/ready-'.$config['worker']);
            $deadline = microtime(true) + 30;
            while (! is_file($config['barrier_dir'].'/start') && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $intent = new CostLedgerEntryIntent(
                propertyId: $config['property_id'],
                sourceInventoryTransactionId: $config['source_id'],
                priorCostLedgerEntryId: null,
                entryType: 'receipt',
                idempotencyKey: $config['idempotency_key'],
                entrySequence: 1,
                currencyCode: 'USD',
                quantityDelta: new AvcoDecimal('2.0000'),
                unitCost: new AvcoDecimal('7.5000'),
                valueDelta: new AvcoDecimal('15.0000'),
                businessDate: '2026-08-25',
                occurredAt: '2026-08-25 00:00:00',
            );

            app(CostLedgerAppendService::class)->append($intent);
            $result['outcome'] = 'SUCCESS';
        } catch (RuntimeException $exception) {
            $result['outcome'] = 'CONTROLLED';
            $result['message'] = $exception->getMessage();
        } catch (Throwable $exception) {
            $result['message'] = $exception::class.': '.$exception->getMessage();
        }

        file_put_contents(
            $config['barrier_dir'].'/result-'.$config['worker'].'.json',
            json_encode($result, JSON_PRETTY_PRINT),
            LOCK_EX,
        );
        DB::disconnect('pgsql');
        exit(in_array($result['outcome'], ['SUCCESS', 'CONTROLLED'], true) ? 0 : 1);
    }
}

namespace Tests\Postgres\Finance\CostControl {

    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use Modules\Finance\CostControl\Models\CostLedgerEntry;
    use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
    use Modules\Finance\CostControl\Services\CostLedgerAppendService;
    use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
    use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
    use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
    use Modules\Foundation\Property\Models\Property;
    use Modules\Operations\Inventory\Models\InventoryCategory;
    use Modules\Operations\Inventory\Models\InventoryItem;
    use Modules\Operations\Inventory\Models\InventoryLocation;
    use Modules\Operations\Inventory\Models\InventoryTransaction;
    use PDO;
    use PHPUnit\Framework\Attributes\DataProvider;
    use RuntimeException;
    use Symfony\Component\Process\Process;
    use Tests\PostgresTestCase;

    class CostLedgerSourceEquivalenceTest extends PostgresTestCase
    {
        use RefreshDatabase;

        protected $seed = true;

        private Property $property;

        private InventoryItem $item;

        private InventoryLocation $location;

        private CostLedgerRepository $repository;

        protected function setUp(): void
        {
            parent::setUp();

            $this->property = Property::where('currency', 'USD')->firstOrFail();
            $category = InventoryCategory::firstOrCreate([
                'property_id' => $this->property->id,
                'name' => 'CC-P01C Source Equivalence',
            ]);
            $this->item = InventoryItem::create([
                'property_id' => $this->property->id,
                'category_id' => $category->id,
                'sku' => 'CCP01C-EQ-'.Str::lower(Str::random(6)),
                'name' => 'CC-P01C Source Equivalence Item',
                'inventory_type' => 'goods',
                'weighted_average_cost' => 7.50,
                'is_active' => true,
            ]);
            $this->location = InventoryLocation::create([
                'property_id' => $this->property->id,
                'name' => 'CC-P01C Source Equivalence Location',
                'type' => 'internal',
            ]);
            $this->repository = app(CostLedgerRepository::class);
        }

        public function test_migration_creates_named_unique_source_constraint_on_clean_data(): void
        {
            $constraint = DB::selectOne(<<<'SQL'
                SELECT con.conname, pg_get_constraintdef(con.oid, true) AS definition
                  FROM pg_constraint con
                  JOIN pg_class rel ON rel.oid = con.conrelid
                 WHERE rel.relname = 'cost_ledger_entries'
                   AND con.conname = 'uk_cost_ledger_source_inventory_transaction'
                   AND con.contype = 'u'
                SQL);

            $this->assertNotNull($constraint);
            $this->assertSame('UNIQUE (source_inventory_transaction_id)', $constraint->definition);
            $this->assertNotNull($this->idempotencyConstraint());
        }

        public function test_duplicate_preflight_fails_closed_without_mutating_rows_or_creating_constraint(): void
        {
            DB::statement('ALTER TABLE cost_ledger_entries DROP CONSTRAINT uk_cost_ledger_source_inventory_transaction');
            $source = $this->makeSource();
            $this->makeLedger($source, ['idempotency_key' => 'legacy-a', 'entry_sequence' => 1]);
            $this->makeLedger($source, ['idempotency_key' => 'legacy-b', 'entry_sequence' => 2]);
            $before = $this->ledgerFingerprint($source->id);
            $migration = require base_path(
                'Modules/Finance/CostControl/database/migrations/2026_08_21_020100_enforce_cost_ledger_source_transaction_uniqueness.php'
            );

            try {
                $migration->up();
                $this->fail('Expected duplicate-source migration preflight to fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('CC_P01C_COST_LEDGER_SOURCE_DUPLICATES_EXIST', $exception->getMessage());
            }

            $this->assertSame($before, $this->ledgerFingerprint($source->id));
            $this->assertNull($this->sourceConstraint());
            $this->assertSame(2, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
        }

        public function test_no_existing_source_is_classified_no_existing_effect(): void
        {
            $result = $this->repository->resolveIntent($this->makeIntent($this->makeSource()));

            $this->assertSame(CostLedgerSourceEquivalence::NO_EXISTING_EFFECT, $result->status);
            $this->assertSame(0, $result->sourceRowCount);
            $this->assertNull($result->costLedgerEntryId);
        }

        public function test_legacy_duplicate_source_rows_are_a_defensive_contradiction(): void
        {
            DB::statement('ALTER TABLE cost_ledger_entries DROP CONSTRAINT uk_cost_ledger_source_inventory_transaction');
            $source = $this->makeSource();
            $this->makeLedger($source, ['idempotency_key' => 'legacy-duplicate-a', 'entry_sequence' => 1]);
            $this->makeLedger($source, ['idempotency_key' => 'legacy-duplicate-b', 'entry_sequence' => 2]);

            $result = $this->repository->resolveIntent($this->makeIntent($source));

            $this->assertSame(CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION, $result->status);
            $this->assertSame(2, $result->sourceRowCount);
            $this->assertNull($result->costLedgerEntryId);
        }

        public function test_exact_existing_source_is_classified_exact_equivalent_effect(): void
        {
            $source = $this->makeSource();
            $entry = $this->makeLedger($source);

            $result = $this->repository->resolveIntent($this->makeIntent($source));

            $this->assertSame(CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT, $result->status);
            $this->assertSame($entry->id, $result->costLedgerEntryId);
            $this->assertSame(1, $result->sourceRowCount);
        }

        #[DataProvider('conflictingFactProvider')]
        public function test_every_authoritative_intent_fact_participates_in_equivalence(
            string $field,
            mixed $value,
        ): void {
            $source = $this->makeSource();
            $this->makeLedger($source, [$field => $value]);

            $result = $this->repository->resolveIntent($this->makeIntent($source));

            $this->assertSame(CostLedgerSourceEquivalence::CONFLICTING_EFFECT, $result->status, $field);
        }

        public static function conflictingFactProvider(): array
        {
            return [
                'entry type' => ['entry_type', 'issue'],
                'idempotency key' => ['idempotency_key', 'different-key'],
                'entry sequence' => ['entry_sequence', 2],
                'currency' => ['currency_code', 'EUR'],
                'quantity' => ['quantity_delta', '2.0001'],
                'unit cost' => ['unit_cost', '7.5001'],
                'value' => ['value_delta', '15.0001'],
                'business date' => ['business_date', '2026-08-24'],
                'occurred at' => ['occurred_at', '2026-08-25 00:00:01'],
                'original date' => ['original_business_date', '2026-08-20'],
                'metadata' => ['metadata', ['unexpected' => 'provenance']],
            ];
        }

        public function test_cross_property_effect_is_conflicting(): void
        {
            $source = $this->makeSource();
            $other = Property::where('id', '<>', $this->property->id)->firstOrFail();
            $this->makeLedger($source, ['property_id' => $other->id]);

            $this->assertSame(
                CostLedgerSourceEquivalence::CONFLICTING_EFFECT,
                $this->repository->resolveIntent($this->makeIntent($source))->status,
            );
        }

        public function test_append_fails_closed_on_same_source_with_conflicting_idempotency(): void
        {
            $source = $this->makeSource();
            $this->makeLedger($source, ['idempotency_key' => 'conflicting-existing']);
            $before = CostLedgerEntry::count();

            try {
                app(CostLedgerAppendService::class)->append($this->makeIntent($source));
                $this->fail('Expected source conflict.');
            } catch (RuntimeException $exception) {
                $this->assertSame('CC_P01C_COST_LEDGER_SOURCE_CONFLICT', $exception->getMessage());
            }

            $this->assertSame($before, CostLedgerEntry::count());
        }

        public function test_unique_race_rearbitration_preserves_callers_outer_transaction(): void
        {
            $existingSource = $this->makeSource();
            $existing = $this->makeLedger($existingSource);
            $competingSource = $this->makeSource(['valuation_sequence' => 2]);
            $intent = $this->makeIntent($competingSource);
            $intent = new CostLedgerEntryIntent(
                propertyId: $intent->propertyId,
                sourceInventoryTransactionId: $intent->sourceInventoryTransactionId,
                priorCostLedgerEntryId: $intent->priorCostLedgerEntryId,
                entryType: $intent->entryType,
                idempotencyKey: $existing->idempotency_key,
                entrySequence: $existing->entry_sequence,
                currencyCode: $intent->currencyCode,
                quantityDelta: $intent->quantityDelta,
                unitCost: $intent->unitCost,
                valueDelta: $intent->valueDelta,
                businessDate: $intent->businessDate,
                occurredAt: $intent->occurredAt,
            );

            DB::transaction(function () use ($intent): void {
                try {
                    $this->repository->append($intent);
                    $this->fail('Expected legacy tuple uniqueness to fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Duplicate idempotency detected. Controlled failure.', $exception->getMessage());
                }

                $this->assertSame(1, (int) DB::scalar('SELECT 1'));
            });

            $this->assertSame(1, CostLedgerEntry::count());
        }

        public function test_reversal_source_equivalence_requires_exact_canonical_provenance(): void
        {
            $original = $this->makeSource();
            $reversal = $this->makeSource([
                'transaction_type' => 'reversal',
                'quantity_change' => '-2.0000',
                'quantity_before' => '2.0000',
                'quantity_after' => '0.0000',
                'total_cost' => '-15.0000',
                'valuation_sequence' => 2,
                'reverses_inventory_transaction_id' => $original->id,
                'valuation_approval_reference' => 'CC-P01C-REVERSAL-APPROVED',
            ]);
            $entry = $this->makeLedger($reversal, [
                'entry_type' => 'reversal',
                'idempotency_key' => "reversal_ledger:{$reversal->id}",
                'original_business_date' => $original->business_date,
                'metadata' => [
                    'original_transaction_id' => $original->id,
                    'reversal_reason' => 'Controlled reversal',
                    'approval_reference' => 'CC-P01C-REVERSAL-APPROVED',
                ],
            ]);

            $exact = $this->repository->resolveInventoryTransaction($reversal);
            $this->assertSame(CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT, $exact->status);

            DB::statement('ALTER TABLE cost_ledger_entries DISABLE TRIGGER USER');
            try {
                DB::table('cost_ledger_entries')->where('id', $entry->id)->update([
                    'metadata' => json_encode([
                        'original_transaction_id' => $original->id,
                        'reversal_reason' => 'Controlled reversal',
                        'approval_reference' => 'WRONG',
                    ]),
                ]);
            } finally {
                DB::statement('ALTER TABLE cost_ledger_entries ENABLE TRIGGER USER');
            }

            $this->assertSame(
                CostLedgerSourceEquivalence::CONFLICTING_EFFECT,
                $this->repository->resolveInventoryTransaction($reversal)->status,
            );
        }

        public function test_two_distinct_postgresql_contexts_create_one_durable_same_source_row(): void
        {
            $database = 'ivorq_concurrency_ccp01c_'.Str::lower(Str::random(8));
            $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
            mkdir($barrier, 0700, true);
            $connection = config('database.connections.pgsql');
            $host = (string) ($connection['host'] ?? '127.0.0.1');
            $port = (string) ($connection['port'] ?? '5432');
            $username = (string) ($connection['username'] ?? '');
            $password = (string) ($connection['password'] ?? '');
            $admin = new PDO(
                "pgsql:host={$host};port={$port};dbname=postgres",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $quotedDatabase = '"'.str_replace('"', '""', $database).'"';
            $processes = [];

            try {
                $admin->exec("CREATE DATABASE {$quotedDatabase}");
                $migration = new Process(
                    [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
                    base_path(),
                    ['APP_ENV' => 'testing', 'DB_DATABASE' => $database],
                );
                $migration->setTimeout(180);
                $migration->mustRun();

                $pdo = new PDO(
                    "pgsql:host={$host};port={$port};dbname={$database}",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $fixture = $this->seedConcurrencySource($pdo);

                foreach (['A', 'B'] as $worker) {
                    $configPath = $barrier.DIRECTORY_SEPARATOR."worker-{$worker}.json";
                    file_put_contents($configPath, json_encode([
                        'base_path' => base_path(),
                        'database' => $database,
                        'barrier_dir' => $barrier,
                        'worker' => $worker,
                        ...$fixture,
                    ], JSON_PRETTY_PRINT));
                    $processes[$worker] = new Process([
                        PHP_BINARY,
                        __FILE__,
                        '--cc-p01c-ledger-worker',
                        $configPath,
                    ], base_path());
                    $processes[$worker]->setTimeout(60);
                    $processes[$worker]->start();
                }

                $this->waitForBarrier($barrier, 'ready-A');
                $this->waitForBarrier($barrier, 'ready-B');
                touch($barrier.DIRECTORY_SEPARATOR.'start');
                foreach ($processes as $process) {
                    $process->wait();
                }

                $results = [
                    json_decode((string) file_get_contents($barrier.'/result-A.json'), true),
                    json_decode((string) file_get_contents($barrier.'/result-B.json'), true),
                ];
                $outcomes = array_values(array_unique(array_column($results, 'outcome')));
                sort($outcomes);
                $this->assertSame(['CONTROLLED', 'SUCCESS'], $outcomes);
                $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cost_ledger_entries')->fetchColumn());
                $pdo = null;
            } finally {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop(1);
                    }
                }
                $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()');
                $terminate->execute(['database' => $database]);
                $admin->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
                $this->removeTemporaryDirectory($barrier);
            }
        }

        private function makeSource(array $overrides = []): InventoryTransaction
        {
            $id = (string) Str::ulid();

            return InventoryTransaction::create(array_merge([
                'id' => $id,
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'location_id' => $this->location->id,
                'transaction_type' => 'purchase_receipt',
                'quantity_before' => '0.0000',
                'quantity_change' => '2.0000',
                'quantity_after' => '2.0000',
                'unit_cost' => '7.5000',
                'total_cost' => '15.0000',
                'business_date' => '2026-08-25',
                'occurred_at' => '2026-08-25 00:00:00',
                'source_document_type' => 'inventory_receipt',
                'source_document_id' => (string) Str::ulid(),
                'source_line_type' => 'inventory_receipt_line',
                'source_line_id' => (string) Str::ulid(),
                'movement_role' => 'purchase_receipt',
                'idempotency_key' => 'ccp01c-'.$id,
                'currency_code' => 'USD',
                'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
                'valuation_sequence' => 1,
                'valuation_approval_status' => 'approved',
                'valuation_approval_reference' => 'CC-P01C-APPROVED',
                'created_at' => now(),
            ], $overrides))->fresh();
        }

        private function makeIntent(InventoryTransaction $source): CostLedgerEntryIntent
        {
            return new CostLedgerEntryIntent(
                propertyId: $source->property_id,
                sourceInventoryTransactionId: $source->id,
                priorCostLedgerEntryId: null,
                entryType: 'receipt',
                idempotencyKey: $source->idempotency_key,
                entrySequence: $source->valuation_sequence,
                currencyCode: $source->currency_code,
                quantityDelta: new AvcoDecimal((string) $source->quantity_change),
                unitCost: new AvcoDecimal((string) $source->unit_cost),
                valueDelta: new AvcoDecimal((string) $source->total_cost),
                businessDate: $source->business_date->format('Y-m-d'),
                occurredAt: $source->occurred_at->format('Y-m-d H:i:s'),
            );
        }

        private function makeLedger(InventoryTransaction $source, array $overrides = []): CostLedgerEntry
        {
            return CostLedgerEntry::create(array_merge([
                'property_id' => $source->property_id,
                'source_inventory_transaction_id' => $source->id,
                'prior_cost_ledger_entry_id' => null,
                'entry_type' => 'receipt',
                'idempotency_key' => $source->idempotency_key,
                'entry_sequence' => $source->valuation_sequence,
                'currency_code' => $source->currency_code,
                'quantity_delta' => $source->quantity_change,
                'unit_cost' => $source->unit_cost,
                'value_delta' => $source->total_cost,
                'business_date' => $source->business_date,
                'occurred_at' => $source->occurred_at,
                'original_business_date' => null,
                'metadata' => null,
            ], $overrides));
        }

        private function sourceConstraint(): ?object
        {
            return DB::selectOne(<<<'SQL'
                SELECT con.conname
                  FROM pg_constraint con
                  JOIN pg_class rel ON rel.oid = con.conrelid
                 WHERE rel.relname = 'cost_ledger_entries'
                   AND con.conname = 'uk_cost_ledger_source_inventory_transaction'
                SQL);
        }

        private function idempotencyConstraint(): ?object
        {
            return DB::selectOne(<<<'SQL'
                SELECT con.conname
                  FROM pg_constraint con
                  JOIN pg_class rel ON rel.oid = con.conrelid
                 WHERE rel.relname = 'cost_ledger_entries'
                   AND con.contype = 'u'
                   AND pg_get_constraintdef(con.oid, true) LIKE '%property_id, idempotency_key, entry_sequence%'
                SQL);
        }

        private function ledgerFingerprint(string $sourceId): string
        {
            return (string) DB::scalar(<<<'SQL'
                SELECT md5(string_agg(row_to_json(e)::text, chr(31) ORDER BY id))
                  FROM cost_ledger_entries e
                 WHERE source_inventory_transaction_id = ?
                SQL, [$sourceId]);
        }

        /** @return array<string, string> */
        private function seedConcurrencySource(PDO $pdo): array
        {
            $now = '2026-08-25 00:00:00';
            $companyId = (string) Str::ulid();
            $propertyId = (string) Str::ulid();
            $categoryId = (string) Str::ulid();
            $itemId = (string) Str::ulid();
            $locationId = (string) Str::ulid();
            $sourceId = (string) Str::ulid();
            $idempotencyKey = 'ccp01c-concurrent-'.$sourceId;

            $this->pdoInsert($pdo, 'companies', [
                'id' => $companyId, 'name' => 'CC-P01C Concurrency', 'slug' => 'ccp01c-concurrency',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'properties', [
                'id' => $propertyId, 'company_id' => $companyId, 'name' => 'CC-P01C Property',
                'slug' => 'ccp01c-property', 'code' => 'CCP01C', 'timezone' => 'UTC',
                'currency' => 'USD', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_categories', [
                'id' => $categoryId, 'property_id' => $propertyId, 'name' => 'CC-P01C Category',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_items', [
                'id' => $itemId, 'property_id' => $propertyId, 'category_id' => $categoryId,
                'sku' => 'CCP01C-ITEM', 'name' => 'CC-P01C Item', 'inventory_type' => 'goods',
                'criticality' => 'low', 'is_batch_tracked' => 0, 'is_expiry_tracked' => 0,
                'weighted_average_cost' => '0.00', 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_locations', [
                'id' => $locationId, 'property_id' => $propertyId, 'name' => 'CC-P01C Location',
                'type' => 'internal', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $scope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
            $this->pdoInsert($pdo, 'inventory_transactions', [
                'id' => $sourceId, 'property_id' => $propertyId, 'item_id' => $itemId,
                'location_id' => $locationId, 'transaction_type' => 'purchase_receipt',
                'quantity_before' => '0.0000', 'quantity_change' => '2.0000', 'quantity_after' => '2.0000',
                'unit_cost' => '7.50', 'total_cost' => '15.00',
                'business_date' => '2026-08-25', 'occurred_at' => $now,
                'source_document_type' => 'inventory_receipt', 'source_document_id' => (string) Str::ulid(),
                'source_line_type' => 'inventory_receipt_line', 'source_line_id' => (string) Str::ulid(),
                'movement_role' => 'purchase_receipt', 'idempotency_key' => $idempotencyKey,
                'currency_code' => 'USD', 'valuation_scope' => $scope, 'valuation_sequence' => 1,
                'valuation_approval_status' => 'approved',
                'valuation_approval_reference' => 'CC-P01C-CONCURRENCY', 'created_at' => $now,
            ]);

            return [
                'property_id' => $propertyId,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        private function pdoInsert(PDO $pdo, string $table, array $attributes): void
        {
            $columns = array_keys($attributes);
            $sql = "INSERT INTO {$table} (".implode(', ', $columns).') VALUES ('.
                implode(', ', array_map(fn (string $column): string => ':'.$column, $columns)).')';
            $statement = $pdo->prepare($sql);
            $statement->execute($attributes);
        }

        private function waitForBarrier(string $directory, string $file): void
        {
            $deadline = microtime(true) + 30;
            while (! is_file($directory.DIRECTORY_SEPARATOR.$file) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (! is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                throw new RuntimeException("Concurrency barrier timed out: {$file}");
            }
        }

        private function removeTemporaryDirectory(string $directory): void
        {
            $resolved = realpath($directory);
            $temporaryRoot = realpath(sys_get_temp_dir());
            if ($resolved === false
                || $temporaryRoot === false
                || ! str_starts_with($resolved, $temporaryRoot.DIRECTORY_SEPARATOR.'ivorq_concurrency_ccp01c_')) {
                return;
            }
            foreach (glob($resolved.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($resolved);
        }
    }
}
