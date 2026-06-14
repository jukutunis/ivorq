<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchasing.create');
    }

    public function rules(): array
    {
        return [
            'rfq_id' => ['required', 'string', 'exists:rfqs,id'],
            'vendor_id' => ['required', 'string', 'exists:vendors,id'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'freight_amount' => ['required', 'numeric', 'min:0'],
            'lead_time_days' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_request_line_id' => ['required', 'string', 'exists:purchase_request_lines,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
