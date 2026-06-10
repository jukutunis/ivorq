<?php

namespace Modules\Finance\Payables\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Finance\Payables\Repositories\VendorInvoiceRepository;

class VendorInvoiceService
{
    public function __construct(
        protected VendorInvoiceRepository $repository
    ) {}

    public function create(array $data): VendorInvoice
    {
        return DB::transaction(function () use ($data) {
            $invoice = VendorInvoice::create([
                'vendor_id' => $data['vendor_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => $data['status'] ?? VendorInvoiceStatusEnum::Draft,
                'remarks' => $data['remarks'] ?? null,
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'grand_total' => 0,
            ]);

            $subtotal = 0;

            if (!empty($data['lines'])) {
                foreach ($data['lines'] as $lineData) {
                    $lineTotal = $lineData['quantity'] * $lineData['unit_price'];
                    $subtotal += $lineTotal;

                    $invoice->lines()->create([
                        'purchase_order_line_id' => $lineData['purchase_order_line_id'] ?? null,
                        'goods_receipt_line_id' => $lineData['goods_receipt_line_id'] ?? null,
                        'inventory_item_id' => $lineData['inventory_item_id'] ?? null,
                        'description' => $lineData['description'],
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            // Calculate totals
            $taxAmount = $data['tax_amount'] ?? 0;
            $discountAmount = $data['discount_amount'] ?? 0;
            $grandTotal = $subtotal + $taxAmount - $discountAmount;

            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
            ]);

            return $invoice;
        });
    }

    public function update(VendorInvoice $invoice, array $data): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'invoice_number' => $data['invoice_number'] ?? $invoice->invoice_number,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'remarks' => $data['remarks'] ?? $invoice->remarks,
            ]);

            // Assuming full replacement of lines for simplicity in foundation
            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $subtotal = 0;

                foreach ($data['lines'] as $lineData) {
                    $lineTotal = $lineData['quantity'] * $lineData['unit_price'];
                    $subtotal += $lineTotal;

                    $invoice->lines()->create([
                        'purchase_order_line_id' => $lineData['purchase_order_line_id'] ?? null,
                        'goods_receipt_line_id' => $lineData['goods_receipt_line_id'] ?? null,
                        'inventory_item_id' => $lineData['inventory_item_id'] ?? null,
                        'description' => $lineData['description'],
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }

                $taxAmount = $data['tax_amount'] ?? $invoice->tax_amount;
                $discountAmount = $data['discount_amount'] ?? $invoice->discount_amount;
                $grandTotal = $subtotal + $taxAmount - $discountAmount;

                $invoice->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'grand_total' => $grandTotal,
                ]);
            }

            return $invoice->fresh('lines');
        });
    }

    public function cancel(VendorInvoice $invoice): VendorInvoice
    {
        $invoice->update(['status' => VendorInvoiceStatusEnum::Cancelled]);
        return $invoice;
    }
}
