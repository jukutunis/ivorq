<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Shared\Traits\HasUlid;

class GuestArTransferRequest extends Model
{
    use HasUlid;
    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = ['amount' => 'decimal:2', 'lifecycle_status' => GuestArTransferStatusEnum::class, 'requested_at' => 'datetime', 'source_snapshot' => 'array'];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function folio(): BelongsTo { return $this->belongsTo(Folio::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function decisions(): HasMany { return $this->hasMany(GuestArTransferDecision::class); }
    protected static function booted(): void { static::deleting(fn () => throw new DomainException('Guest AR transfer requests are immutable.')); }
}
