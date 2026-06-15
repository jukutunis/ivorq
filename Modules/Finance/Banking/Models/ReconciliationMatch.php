<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class ReconciliationMatch extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, HasFactory;

    protected $fillable = [
        'property_id',
        'reconciliation_session_id',
        'bank_statement_line_id',
        'matchable_type',
        'matchable_id',
        'amount_matched',
        'is_locked',
        'matchable_reference',
        'matchable_amount',
        'statement_reference',
        'statement_amount',
        'bank_account_balance_before',
        'bank_account_balance_after',
        'match_method',
        'matched_by',
        'override_reason',
        'confidence_score',
        'matched_at',
    ];

    protected $casts = [
        'amount_matched' => 'decimal:2',
        'is_locked' => 'boolean',
        'matchable_amount' => 'decimal:2',
        'statement_amount' => 'decimal:2',
        'bank_account_balance_before' => 'decimal:2',
        'bank_account_balance_after' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public function reconciliationSession(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class);
    }

    public function bankStatementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class);
    }

    public function matchable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Banking\database\Factories\ReconciliationMatchFactory::new();
    }

    protected static function booted()
    {
        static::saving(function ($match) {
            $session = $match->reconciliationSession()->first();
            if ($session && $session->status === \Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum::Finalized) {
                throw new \Exception("Freeze Protection: Cannot modify matches of a finalized session.");
            }
        });

        static::deleting(function ($match) {
            $session = $match->reconciliationSession()->first();
            if ($session && $session->status === \Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum::Finalized) {
                throw new \Exception("Freeze Protection: Cannot delete matches of a finalized session.");
            }
        });
    }
}
