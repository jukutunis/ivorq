<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Operations\Receiving\Enums\DiscrepancyTypeEnum;

class ReceivingDiscrepancy extends Model
{
    use HasUlid, HasAuditColumns, LogsActivity;

    protected $table = 'receiving_discrepancies';

    protected $fillable = [
        'receiving_line_id',
        'discrepancy_type',
        'reported_quantity',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'discrepancy_type' => DiscrepancyTypeEnum::class,
        'reported_quantity' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function receivingLine(): BelongsTo
    {
        return $this->belongsTo(ReceivingLine::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
