<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Models\Folio;

class PostFolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folio = Folio::find($this->route('folio'));

        return $folio && $this->user()->can('manage', $folio);
    }

    public function rules(): array
    {
        return [
            'item_type'   => ['required', Rule::enum(FolioItemTypeEnum::class)],
            'description' => ['required', 'string', 'max:255'],
            'quantity'    => ['nullable', 'numeric', 'min:0.01'],
            'amount'      => ['required', 'numeric'],
            'posted_at'   => ['nullable', 'date'],

            // Server-managed — not accepted from client
            'folio_id'   => ['prohibited'],
            'is_void'    => ['prohibited'],
            'posted_by'  => ['prohibited'],
        ];
    }
}
