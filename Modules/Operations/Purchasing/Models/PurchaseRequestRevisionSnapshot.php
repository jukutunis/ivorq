<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseRequestRevisionSnapshot extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'revision_number' => 'integer',
        'rejected_at' => 'datetime',
        'commercial_header' => 'array',
        'commercial_lines' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase Request revision snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Purchase Request revision snapshots are immutable.'));
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }
}
