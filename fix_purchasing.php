<?php
$files = [
    'tests/Feature/Finance/Payables/ThreeWayMatchingEngineTest.php',
    'tests/archive/deprecated-tests/Purchasing/ReceivingModuleTest.php',
    'Modules/Operations/Purchasing/Services/PurchaseOrderService.php',
    'Modules/Operations/Purchasing/Services/ReceivingService.php',
    'Modules/Operations/Purchasing/Http/Resources/PurchaseOrderLineResource.php',
    'Modules/Operations/Purchasing/Http/Requests/UpdatePurchaseOrderRequest.php',
    'Modules/Finance/Payables/Services/ThreeWayMatchingEngine.php',
    'Modules/Operations/Purchasing/database/factories/PurchaseOrderLineFactory.php',
    'tests/Feature/Finance/Payables/AccountsPayableModuleTest.php',
    'tests/Feature/Finance/Payables/PaymentProcessingModuleTest.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        $orig = $c;
        $c = str_replace('quantity_ordered', 'ordered_quantity', $c);
        $c = str_replace('ApInvoiceStatusEnum::Submitted', 'ApInvoiceStatusEnum::PENDING_REVIEW', $c);
        $c = str_replace('ApInvoiceStatusEnum::Matched', 'ApInvoiceStatusEnum::APPROVED', $c);
        if ($orig !== $c) {
            file_put_contents($f, $c);
            echo 'Updated ' . $f . PHP_EOL;
        }
    }
}
echo 'Done';
