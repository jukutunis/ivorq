<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\Stay;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $stay = Stay::find($this->route('stay'));

        return $stay && $this->user()->can('checkOut', $stay);
    }

    public function rules(): array
    {
        return [];
    }
}
