<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationPlan extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use HasAuditColumns;

    protected $table = 'banking_migration_plans';

    protected $fillable = [
        'property_id',
        'source_domain',
        'target_domain',
        'status',
        'correlation_id',
        'idempotency_key',
        'dry_run_metadata',
        'execution_authority',
        'cutover_authority',
        'created_actor_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingMigrationPlanStatusEnum::class,
            'dry_run_metadata' => 'array',
        ];
    }
}
