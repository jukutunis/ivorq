<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationInventoryStatusEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationManifestEntry extends Model
{
    use HasUlid;
    use HasAuditColumns;

    protected $table = 'banking_migration_manifest_entries';

    protected $fillable = [
        'migration_plan_id',
        'source_domain',
        'source_model',
        'source_ulid',
        'source_property_id',
        'source_identity_hash',
        'source_snapshot_hash',
        'dry_run_version',
        'inventory_status',
    ];

    protected function casts(): array
    {
        return [
            'inventory_status' => BankingMigrationInventoryStatusEnum::class,
        ];
    }

    public function migrationPlan()
    {
        return $this->belongsTo(BankingMigrationPlan::class, 'migration_plan_id');
    }
}
