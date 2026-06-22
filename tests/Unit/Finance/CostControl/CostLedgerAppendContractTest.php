<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;

class CostLedgerAppendContractTest extends TestCase
{
    public function test_repository_contract_tidak_mendefinisikan_update_delete_upsert_recalculate_postToGl()
    {
        $reflection = new \ReflectionClass(CostLedgerRepository::class);

        $this->assertFalse($reflection->hasMethod('update'), 'Repository should not have update method');
        $this->assertFalse($reflection->hasMethod('delete'), 'Repository should not have delete method');
        $this->assertFalse($reflection->hasMethod('upsert'), 'Repository should not have upsert method');
        $this->assertFalse($reflection->hasMethod('recalculate'), 'Repository should not have recalculate method');
        $this->assertFalse($reflection->hasMethod('postToGl'), 'Repository should not have postToGl method');
    }

    public function test_cost_control_source_tidak_memiliki_dependency_inventory_stock()
    {
        $repoRoot = dirname(__DIR__, 4);
        $costControlDir = $repoRoot . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Finance' . DIRECTORY_SEPARATOR . 'CostControl';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($costControlDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if (strpos($file->getFilename(), 'FutureLockOrderContract') === false) {
                    $this->assertStringNotContainsString(
                        'InventoryStock',
                        $content,
                        "File {$file->getFilename()} contains forbidden InventoryStock reference."
                    );
                }
            }
        }
    }
}
