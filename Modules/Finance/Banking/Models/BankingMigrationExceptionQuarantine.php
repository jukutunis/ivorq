<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionCodeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionSeverityEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationExceptionQuarantine extends Model
{
    use HasUlid;
    use HasAuditColumns;

    protected $table = 'banking_migration_exception_quarantines';

    protected $fillable = [
        'migration_plan_id',
        'manifest_entry_id',
        'exception_code',
        'severity',
        'source_domain',
        'source_model',
        'source_ulid',
        'source_property_id',
        'correlation_id',
        'is_resolved',
    ];

    protected function casts(): array
    {
        return [
            'exception_code' => BankingMigrationExceptionCodeEnum::class,
            'severity' => BankingMigrationExceptionSeverityEnum::class,
            'is_resolved' => 'boolean',
        ];
    }

    public function migrationPlan()
    {
        return $this->belongsTo(BankingMigrationPlan::class, 'migration_plan_id');
    }

    public function manifestEntry()
    {
        return $this->belongsTo(BankingMigrationManifestEntry::class, 'manifest_entry_id');
    }
}
