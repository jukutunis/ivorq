<?php

namespace Modules\Finance\FxReference\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class ExchangeRateEvidence extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'exchange_rate_evidences';

    protected $fillable = [
        'property_id',
        'base_currency',
        'quote_currency',
        'rate',
        'quote_convention',
        'effective_date',
        'source_reference',
        'status',
        'recorded_by',
        'recorded_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'effective_date' => 'date',
        'status' => ExchangeRateEvidenceStatusEnum::class,
        'recorded_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (ExchangeRateEvidence $evidence): void {
            if (in_array($evidence->getRawOriginal('status'), [
                ExchangeRateEvidenceStatusEnum::APPROVED->value,
                ExchangeRateEvidenceStatusEnum::REJECTED->value,
            ], true)) {
                throw new DomainException('Approved or rejected Exchange Rate evidence is immutable.');
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
