<?php
$file = 'tests/Feature/Operations/Concerns/CreatesInventoryData.php';
$content = file_get_contents($file);
if (strpos($content, "'inventory_type'") === false) {
    $content = str_replace("'name'          => \"Policy Item {\$seq}\",", "'name'          => \"Policy Item {\$seq}\",\n            'inventory_type'  => 'stock',\n            'criticality'     => 'low',", $content);
    file_put_contents($file, $content);
}
echo "Done\n";
