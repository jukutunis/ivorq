<?php

namespace Modules\Finance\Payables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;

class StoreVendorInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'string', 'ulid', 'exists:vendors,id'],
            'purchase_order_id' => ['nullable', 'string', 'ulid', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'string', 'ulid', 'exists:goods_receipts,id'],
            'invoice_number' => [
                'required',
                'string',
                Rule::unique('vendor_invoices')->where(function ($query) {
                    return $query->where('vendor_id', $this->vendor_id)
                        ->where('property_id', app(\Shared\Services\CurrentPropertyService::class)->getPropertyId());
                })
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'status' => ['nullable', 'string', Rule::enum(ApInvoiceStatusEnum::class)],
            'remarks' => ['nullable', 'string'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'string', 'ulid', 'exists:purchase_order_lines,id'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'string', 'ulid', 'exists:goods_receipt_lines,id'],
            'lines.*.inventory_item_id' => ['nullable', 'string', 'ulid', 'exists:inventory_items,id'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
