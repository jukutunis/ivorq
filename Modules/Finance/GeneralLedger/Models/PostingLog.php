<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\PostingLogStatusEnum;
use Modules\Finance\GeneralLedger\Enums\PostingEventEnum;

class PostingLog extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, HasFactory;

    protected $table = 'gl_posting_logs';

    protected $fillable = [
        'property_id',
        'source_module',
        'source_type',
        'source_id',
        'status',
        'error_message',
        'journal_entry_id',
    ];

    protected $casts = [
        'status' => PostingLogStatusEnum::class,
    ];
}
