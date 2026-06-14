<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;

class ReceivingAttachment extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'receiving_attachments';

    protected $fillable = [
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
