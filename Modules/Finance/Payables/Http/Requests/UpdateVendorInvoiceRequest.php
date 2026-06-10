<?php

namespace Modules\Finance\Payables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Payables\Models\VendorInvoice;

class UpdateVendorInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var VendorInvoice $invoice */
        $invoice = $this->route('vendor_invoice') ?? $this->route('id');
        $invoiceId = is_string($invoice) ? $invoice : $invoice?->id;

        return [
            'invoice_number' => [
                'nullable',
                'string',
                Rule::unique('vendor_invoices')->ignore($invoiceId)->where(function ($query) use ($invoice) {
                    $vendorId = is_string($invoice) ? VendorInvoice::find($invoice)->vendor_id : $invoice?->vendor_id;
                    return $query->where('vendor_id', $vendorId)
                        ->where('property_id', request()->user()->property_id);
                })
            ],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'remarks' => ['nullable', 'string'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'string', 'ulid', 'exists:purchase_order_lines,id'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'string', 'ulid', 'exists:goods_receipt_lines,id'],
            'lines.*.inventory_item_id' => ['nullable', 'string', 'ulid', 'exists:inventory_items,id'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
        ];
    }
}
