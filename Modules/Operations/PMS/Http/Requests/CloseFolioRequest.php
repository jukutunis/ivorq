<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\Folio;

class CloseFolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folio = Folio::find($this->route('folio'));

        return $folio && $this->user()->can('manage', $folio);
    }

    public function rules(): array
    {
        return [];
    }
}
