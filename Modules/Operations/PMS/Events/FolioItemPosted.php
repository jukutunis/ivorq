<?php

namespace Modules\Operations\PMS\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\PMS\Models\FolioItem;

class FolioItemPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FolioItem $folioItem
    ) {}
}
