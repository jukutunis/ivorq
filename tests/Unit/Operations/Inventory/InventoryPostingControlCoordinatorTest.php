<?php

namespace Tests\Unit\Operations\Inventory;

use PHPUnit\Framework\TestCase;
use PDOException;
use RuntimeException;
use Exception;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\Exceptions\InventoryPostingRetryableException;

class InventoryPostingControlCoordinatorTest extends TestCase
{
    public function test_successful_operation_executes_exactly_once(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $result = $coordinator->executeOnce(function () use (&$count) {
            $count++;
            return 'success_result';
        });

        $this->assertEquals(1, $count);
        $this->assertEquals('success_result', $result);
    }

    public function test_deadlock_is_controlled_retryable_failure(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $exception = new PDOException("Deadlock");
        $exception->errorInfo = ['40P01', 1213, 'Deadlock found'];

        $this->expectException(InventoryPostingRetryableException::class);
        
        try {
            $coordinator->executeOnce(function () use (&$count, $exception) {
                $count++;
                throw $exception;
            });
        } catch (InventoryPostingRetryableException $e) {
            $this->assertEquals(1, $count);
            $this->assertEquals('DEADLOCK_DETECTED', $e->getReasonCode());
            $this->assertSame($exception, $e->getPrevious());
            throw $e;
        }
    }

    public function test_serialization_failure_is_controlled_retryable_failure(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $exception = new PDOException("Serialization");
        $exception->errorInfo = ['40001', 1213, 'Serialization failure'];

        $this->expectException(InventoryPostingRetryableException::class);
        
        try {
            $coordinator->executeOnce(function () use (&$count, $exception) {
                $count++;
                throw $exception;
            });
        } catch (InventoryPostingRetryableException $e) {
            $this->assertEquals(1, $count);
            $this->assertEquals('SERIALIZATION_FAILURE', $e->getReasonCode());
            $this->assertSame($exception, $e->getPrevious());
            throw $e;
        }
    }

    public function test_lock_timeout_is_controlled_retryable_failure(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $exception = new PDOException("Lock Timeout");
        $exception->errorInfo = ['55P03', 1213, 'Lock timeout'];

        $this->expectException(InventoryPostingRetryableException::class);
        
        try {
            $coordinator->executeOnce(function () use (&$count, $exception) {
                $count++;
                throw $exception;
            });
        } catch (InventoryPostingRetryableException $e) {
            $this->assertEquals(1, $count);
            $this->assertEquals('LOCK_TIMEOUT', $e->getReasonCode());
            $this->assertSame($exception, $e->getPrevious());
            throw $e;
        }
    }

    public function test_statement_timeout_is_controlled_retryable_failure(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $exception = new PDOException("Statement Timeout");
        $exception->errorInfo = ['57014', 1213, 'Statement timeout'];

        $this->expectException(InventoryPostingRetryableException::class);
        
        try {
            $coordinator->executeOnce(function () use (&$count, $exception) {
                $count++;
                throw $exception;
            });
        } catch (InventoryPostingRetryableException $e) {
            $this->assertEquals(1, $count);
            $this->assertEquals('STATEMENT_TIMEOUT', $e->getReasonCode());
            $this->assertSame($exception, $e->getPrevious());
            throw $e;
        }
    }

    public function test_non_retryable_failure_is_rethrown_unchanged(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $exception = new RuntimeException("Standard runtime error");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Standard runtime error");
        
        try {
            $coordinator->executeOnce(function () use (&$count, $exception) {
                $count++;
                throw $exception;
            });
        } catch (RuntimeException $e) {
            $this->assertEquals(1, $count);
            $this->assertSame($exception, $e);
            throw $e;
        }
    }

    public function test_previous_cause_sqlstate_is_recognized(): void
    {
        $coordinator = new InventoryPostingControlCoordinator();
        
        $count = 0;
        $pdoException = new PDOException("Serialization");
        $pdoException->errorInfo = ['40001', 1213, 'Serialization failure'];
        
        $outerException = new Exception("Outer generic wrapper", 0, $pdoException);

        $this->expectException(InventoryPostingRetryableException::class);
        
        try {
            $coordinator->executeOnce(function () use (&$count, $outerException) {
                $count++;
                throw $outerException;
            });
        } catch (InventoryPostingRetryableException $e) {
            $this->assertEquals(1, $count);
            $this->assertEquals('SERIALIZATION_FAILURE', $e->getReasonCode());
            $this->assertSame($outerException, $e->getPrevious());
            $this->assertSame($pdoException, $e->getPrevious()->getPrevious());
            throw $e;
        }
    }

    public function test_coordinator_pure_boundary_and_isolation(): void
    {
        $path = realpath(__DIR__ . '/../../../../Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php');
        $content = file_get_contents($path);

        $forbidden = [
            'D' . 'B::', 'trans' . 'action(', 'lockFor' . 'Update', 're' . 'try(',
            'att' . 'empt(', '->' . 'save(', '->' . 'update(', '->' . 'create(',
            '->' . 'delete(', 'disp' . 'atch(', 'Ev' . 'ent::', 'Qu' . 'eue::',
            'BusinessDateClose' . 'Service', 'Inventory' . 'Transaction',
            'Inventory' . 'Stock', 'Cost' . 'Ledger', 'General' . 'Ledger',
            'Jour' . 'nal', 'Accounts' . 'Payable', 'Pay' . 'able', 'GR' . 'NI'
        ];

        foreach (explode("\n", $content) as $i => $line) {
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $line, "Forbidden keyword found on line " . ($i + 1) . ": $f");
            }
        }

        $basePath = realpath(__DIR__ . '/../../../../');
        $scanDirs = [
            $basePath . DIRECTORY_SEPARATOR . 'Modules',
            $basePath . DIRECTORY_SEPARATOR . 'app'
        ];

        $targetToFind = 'InventoryPostingControl' . 'Coordinator';

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    if ($file->getRealPath() === $path) {
                        continue; // Skip declaration file
                    }
                    $fileContent = file_get_contents($file->getRealPath());
                    if (strpos($fileContent, $targetToFind) !== false) {
                        $this->fail("Operational caller found in: " . $file->getRealPath());
                    }
                }
            }
        }
        
        $this->assertTrue(true);
    }
}
