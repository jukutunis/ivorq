<?php
$basePath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/Inventory/Contracts';
if (!is_dir($basePath)) mkdir($basePath, 0755, true);

$contracts = [
    'InventoryAvailabilityCheck.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Contracts;\n\ninterface InventoryAvailabilityCheck\n{\n    public function check(string \$propertyId, string \$itemId, string \$locationId, float \$requiredQuantity): bool;\n}\n",
    'InventoryReservationContract.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Contracts;\n\ninterface InventoryReservationContract\n{\n    public function reserve(string \$propertyId, string \$itemId, string \$locationId, float \$quantity, string \$workOrderId): string;\n}\n",
    'InventoryConsumptionContract.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Contracts;\n\ninterface InventoryConsumptionContract\n{\n    public function consume(string \$reservationId, float \$consumedQuantity): void;\n}\n",
    'InventoryCostTrackingContract.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Contracts;\n\ninterface InventoryCostTrackingContract\n{\n    public function trackCost(string \$transactionId): void;\n}\n",
];

foreach ($contracts as $name => $content) {
    file_put_contents("$basePath/$name", $content);
}
echo "Contracts created.";
