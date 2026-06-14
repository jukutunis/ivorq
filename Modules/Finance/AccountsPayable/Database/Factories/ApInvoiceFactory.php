<?php

namespace Modules\Finance\AccountsPayable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;

class ApInvoiceFactory extends Factory
{
    protected $model = ApInvoice::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::first()->id ?? null,
            'vendor_id' => Vendor::first()->id ?? null,
            'invoice_type' => ApInvoiceTypeEnum::GRNI_MATCHED->value,
            'status' => ApInvoiceStatusEnum::DRAFT->value,
            'vendor_invoice_number' => 'INV-' . $this->faker->unique()->numberBetween(1000, 9999),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal_amount' => 100.00,
            'tax_amount' => 10.00,
            'grand_total_amount' => 110.00,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
