<?php
$file = 'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php';
$content = file_get_contents($file);
$content = str_replace("\$cats[\$data['category_name']] ?? null;", "\$cats[\$data['category_name'] ?? ''] ?? null;", $content);
file_put_contents($file, $content);
echo "Done\n";
