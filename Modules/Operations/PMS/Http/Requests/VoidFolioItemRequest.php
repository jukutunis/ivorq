<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;

class VoidFolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = FolioItem::find($this->route('folio_item'));

        if (! $item) {
            return false;
        }

        $folio = Folio::find($item->folio_id);

        return $folio && $this->user()->can('manage', $folio);
    }

    public function rules(): array
    {
        return [];
    }
}
