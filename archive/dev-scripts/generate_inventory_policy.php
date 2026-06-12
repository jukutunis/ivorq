<?php
$basePath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/Inventory/Policies';
if (!is_dir($basePath)) mkdir($basePath, 0755, true);

$content = "<?php\n\nnamespace Modules\Operations\Inventory\Policies;\n\nuse App\Models\User;\n\nclass InventoryPolicy\n{\n    public function viewAny(User \$user): bool\n    {\n        return \$user->hasPermissionTo('view_inventory');\n    }\n}\n";

file_put_contents("$basePath/InventoryPolicy.php", $content);
echo "Policy created.";
