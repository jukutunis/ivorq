<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Operations\Receiving\Enums\InspectionResultEnum;

class ReceivingInspection extends Model
{
    use HasUlid, HasAuditColumns, LogsActivity;

    protected $table = 'receiving_inspections';

    protected $fillable = [
        'receiving_line_id',
        'inspection_result',
        'temperature',
        'visual_quality_score',
        'notes',
        'inspected_by',
        'inspected_at',
    ];

    protected $casts = [
        'inspection_result' => InspectionResultEnum::class,
        'temperature' => 'decimal:2',
        'inspected_at' => 'datetime',
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

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
