<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PIVOTS = [
        'model_has_roles' => [
            'authority' => 'role_id',
            'primary' => 'model_has_roles_role_model_type_primary',
            'scoped_unique' => 'model_has_roles_role_model_type_property_unique',
            'global_unique' => 'model_has_roles_role_model_type_global_unique',
        ],
        'model_has_permissions' => [
            'authority' => 'permission_id',
            'primary' => 'model_has_permissions_permission_model_type_primary',
            'scoped_unique' => 'model_has_permissions_permission_model_type_property_unique',
            'global_unique' => 'model_has_permissions_permission_model_type_global_unique',
        ],
    ];

    public function up(): void
    {
        $this->requirePostgreSql();

        foreach (self::PIVOTS as $table => $definition) {
            $authority = $definition['authority'];
            $this->assertNoDuplicates($table, $authority, true);
            $this->assertNoDuplicates($table, $authority, false);

            $this->dropPrimaryConstraint($table);
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, model_id, model_type, property_id) WHERE property_id IS NOT NULL',
                $definition['scoped_unique'],
                $table,
                $authority,
            ));
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, model_id, model_type) WHERE property_id IS NULL',
                $definition['global_unique'],
                $table,
                $authority,
            ));
        }
    }

    public function down(): void
    {
        $this->requirePostgreSql();

        foreach (self::PIVOTS as $table => $definition) {
            $authority = $definition['authority'];
            $conflicts = DB::select(sprintf(
                'SELECT %s, model_id, model_type, COUNT(*) AS assignment_count
                 FROM %s
                 GROUP BY %s, model_id, model_type
                 HAVING COUNT(*) > 1
                 ORDER BY %s, model_id, model_type',
                $authority,
                $table,
                $authority,
                $authority,
            ));

            if ($conflicts !== []) {
                throw new RuntimeException(sprintf(
                    'SEC_P01_TEAM_PIVOT_ROLLBACK_CONFLICT:%s:%s',
                    $table,
                    json_encode($conflicts, JSON_THROW_ON_ERROR),
                ));
            }

            DB::statement(sprintf('DROP INDEX IF EXISTS %s', $definition['scoped_unique']));
            DB::statement(sprintf('DROP INDEX IF EXISTS %s', $definition['global_unique']));
            $this->dropPrimaryConstraint($table);
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (%s, model_id, model_type)',
                $table,
                $definition['primary'],
                $authority,
            ));
        }
    }

    private function assertNoDuplicates(string $table, string $authority, bool $scoped): void
    {
        $propertyProjection = $scoped ? ', property_id' : '';
        $propertyPredicate = $scoped ? 'IS NOT NULL' : 'IS NULL';
        $conflicts = DB::select(sprintf(
            'SELECT %s, model_id, model_type%s, COUNT(*) AS assignment_count
             FROM %s
             WHERE property_id %s
             GROUP BY %s, model_id, model_type%s
             HAVING COUNT(*) > 1
             ORDER BY %s, model_id, model_type%s',
            $authority,
            $propertyProjection,
            $table,
            $propertyPredicate,
            $authority,
            $propertyProjection,
            $authority,
            $propertyProjection,
        ));

        if ($conflicts !== []) {
            throw new RuntimeException(sprintf(
                'SEC_P01_TEAM_PIVOT_PREFLIGHT_CONFLICT:%s:%s:%s',
                $table,
                $scoped ? 'PROPERTY' : 'GLOBAL',
                json_encode($conflicts, JSON_THROW_ON_ERROR),
            ));
        }
    }

    private function requirePostgreSql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('SEC_P01_TEAM_PIVOT_REQUIRES_POSTGRESQL');
        }
    }

    private function dropPrimaryConstraint(string $table): void
    {
        $constraint = DB::selectOne(
            "SELECT conname
             FROM pg_constraint
             WHERE conrelid = ?::regclass
               AND contype = 'p'",
            [$table],
        );

        if ($constraint !== null) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $table,
                $constraint->conname,
            ));
        }
    }
};
