<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Foundation\User\Models\User;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class JournalEntry extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'gl_journal_entries';

    protected $fillable = [
        'property_id',
        'transaction_date',
        'posting_date',
        'reference',
        'description',
        'status',
        'source_module',
        'source_type',
        'source_id',
        'reversal_of_id',
        'journal_candidate_id',
        'posting_event',
        'draft_finalization_authorized_by',
        'draft_finalization_authorized_at',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'posting_date' => 'date',
        'status' => JournalStatusEnum::class,
        'draft_finalization_authorized_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(JournalCandidate::class, 'journal_candidate_id');
    }

    public function draftFinalizationAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_finalization_authorized_by');
    }

    public function postingActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
