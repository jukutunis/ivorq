<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

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
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'posting_date' => 'date',
        'status' => JournalStatusEnum::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }
}
