<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Shared\Traits\HasUlid;

class GuestDepositReversal extends Model
{
    use HasUlid;
    public $timestamps = false;
    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = ['reversal_type' => GuestDepositReversalTypeEnum::class, 'amount' => 'decimal:2', 'reversed_at' => 'datetime', 'source_snapshot' => 'array', 'created_at' => 'datetime'];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function deposit(): BelongsTo { return $this->belongsTo(GuestDepositTransaction::class, 'guest_deposit_transaction_id'); }
    public function application(): BelongsTo { return $this->belongsTo(GuestDepositApplication::class, 'guest_deposit_application_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
    protected static function booted(): void { static::deleting(fn () => throw new DomainException('Guest deposit reversals are immutable.')); }
}
