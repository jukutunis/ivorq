<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationSourceIntegrityTest extends PostgresTestCase
{
    public function test_package8_permission_intent_and_locked_runtime_markers_exist(): void
    {
        $permissionSeeder = file_get_contents(base_path('Modules/Foundation/Authorization/database/seeders/PermissionSeeder.php'));
        $confirmationService = file_get_contents(base_path('Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php'));
        $boundary = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php'));

        $this->assertStringContainsString('frontdesk.checkout-execution.execute', $permissionSeeder);
        $this->assertStringContainsString('frontdesk-checkout-execution', $confirmationService);
        $this->assertStringContainsString('Checkout confirmation requires authoritative checkout context.', $confirmationService);
        $this->assertStringContainsString('CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED', $boundary);
        $this->assertStringContainsString('$canExecute = false;', $boundary);
        $this->assertStringNotContainsString('$canExecute = true;', $boundary);
    }

    public function test_no_package9_checkout_execution_surface_exists(): void
    {
        $this->assertFileDoesNotExist(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));
        $this->assertFileDoesNotExist(base_path('Modules/Operations/FrontDesk/Commands/CheckoutStayCommand.php'));

        $controller = file_get_contents(base_path('app/Http/Controllers/Ivorq/FrontDeskController.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsString('executeCheckout', $controller);
        $this->assertStringNotContainsString('checkOut(', $controller);
        $this->assertStringNotContainsString("post('/stays/{stay}/checkout", $routes);
        $this->assertStringNotContainsString("put('/stays/{stay}/checkout", $routes);
        $this->assertStringNotContainsString("patch('/stays/{stay}/checkout", $routes);
        $this->assertStringNotContainsString("delete('/stays/{stay}/checkout", $routes);
    }

    public function test_package8_claim_requires_postgresql_transaction_and_session_cleanup_is_separate(): void
    {
        $service = file_get_contents(base_path('Modules/Foundation/Authorization/Services/CheckoutSensitiveConfirmationService.php'));

        $this->assertStringContainsString("getDriverName() !== 'pgsql'", $service);
        $this->assertStringContainsString('DB::transactionLevel() < 1', $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("clock_timestamp() AT TIME ZONE 'UTC'", $service);
        $this->assertStringContainsString('cleanupCurrentSessionReference', $service);
    }

    public function test_no_raw_password_session_or_foreign_domain_mutation_source_is_added(): void
    {
        $service = file_get_contents(base_path('Modules/Foundation/Authorization/Services/CheckoutSensitiveConfirmationService.php'));

        $this->assertStringNotContainsString('password_hash', $service);
        $this->assertStringNotContainsString('session_id', $service);
        $this->assertStringNotContainsString('FrontDeskCheckoutExecution::create', $service);
        $this->assertStringNotContainsString('FrontDeskCheckoutHousekeepingHandoff::create', $service);
        $this->assertStringNotContainsString('CHECKED_OUT', $service);
    }
}
