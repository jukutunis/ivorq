<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Modules\Foundation\User\Models\User;

class StockCountAttachment extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns;

    protected $table = 'stock_count_attachments';

    protected $fillable = [
        'property_id',
        'stock_count_session_id',
        'filename',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockCountSession::class, 'stock_count_session_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
