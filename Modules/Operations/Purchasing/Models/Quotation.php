<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Foundation\Notification\Models\AppNotification;

class Quotation extends Model
{
    use HasUlid, HasAuditColumns, LogsActivity;

    protected $table = 'quotations';
    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'freight_amount' => 'decimal:2',
        'lead_time_days' => 'integer',
        'is_winner' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted()
    {
        static::created(function ($quotation) {
            $rfq = $quotation->rfq;
            if ($rfq) {
                AppNotification::create([
                    'property_id' => $rfq->property_id,
                    'user_id' => $rfq->created_by,
                    'type' => 'purchasing.quotation_received',
                    'priority' => 'normal',
                    'title' => "Quotation Received",
                    'body' => "A new quotation has been received for RFQ {$rfq->rfq_number}.",
                ]);
            }
        });
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RFQ::class, 'rfq_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id');
    }
}
