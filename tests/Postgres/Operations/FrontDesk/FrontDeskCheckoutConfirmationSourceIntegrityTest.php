<?php

namespace Tests\Postgres\Operations\FrontDesk;

use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationContext;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationSourceIntegrityTest extends PostgresTestCase
{
    public function test_package8_permission_intent_and_package9_runtime_markers_exist(): void
    {
        $permissionSeeder = file_get_contents(base_path('Modules/Foundation/Authorization/database/seeders/PermissionSeeder.php'));
        $confirmationService = file_get_contents(base_path('Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php'));
        $boundary = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php'));

        $this->assertStringContainsString('frontdesk.checkout-execution.execute', $permissionSeeder);
        $this->assertStringContainsString('frontdesk-checkout-execution', $confirmationService);
        $this->assertStringContainsString('Checkout confirmation requires authoritative checkout context.', $confirmationService);
        $this->assertStringContainsString('FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION', $boundary);
        $this->assertStringContainsString('FrontDeskCheckoutExecution::withoutGlobalScopes()', $boundary);
        $this->assertStringContainsString('$existingExecution === null', $boundary);
        $this->assertStringContainsString('empty($reviewReasons)', $boundary);
    }

    public function test_package9_checkout_execution_surface_is_controlled(): void
    {
        $this->assertFileExists(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));
        $this->assertFileDoesNotExist(base_path('Modules/Operations/FrontDesk/Commands/CheckoutStayCommand.php'));

        $controller = file_get_contents(base_path('app/Http/Controllers/Ivorq/FrontDeskController.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsString('checkOut(', $controller);
        $this->assertStringContainsString('prepareCheckoutConfirmation', $controller);
        $this->assertStringContainsString('executeCheckout', $controller);
        $this->assertStringContainsString("post('/stays/{stay}/checkout-confirmation", $routes);
        $this->assertStringContainsString("post('/stays/{stay}/checkout-execution", $routes);
        $this->assertStringNotContainsString("put('/stays/{stay}/checkout", $routes);
        $this->assertStringNotContainsString("patch('/stays/{stay}/checkout", $routes);
        $this->assertStringNotContainsString("delete('/stays/{stay}/checkout", $routes);
    }

    public function test_package8_claim_requires_postgresql_transaction_and_session_cleanup_is_separate(): void
    {
        $service = file_get_contents(base_path('Modules/Foundation/Authorization/Services/CheckoutSensitiveConfirmationService.php'));
        $authorization = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecuteAuthorizationService.php'));
        $reflection = new \ReflectionClass(CheckoutSensitiveConfirmationService::class);

        $this->assertStringContainsString('issueForCurrentSession', $service);
        $this->assertStringContainsString('validateCurrentSessionConfirmationFor', $service);
        $this->assertStringContainsString('claimCurrentSessionConfirmationFor', $service);
        $this->assertStringNotContainsString('public function claimCurrentSessionConfirmationFromPreflight', $service);
        $this->assertStringContainsString('resolveAuthorizedContext', $service);
        $this->assertStringContainsString('resolveAuthorizedContext', $authorization);
        $this->assertTrue($reflection->getMethod('issueForCurrentSession')->isPublic());
        $this->assertTrue($reflection->getMethod('validateCurrentSessionConfirmationFor')->isPublic());
        $this->assertTrue($reflection->getMethod('claimCurrentSessionConfirmationFor')->isPublic());
        $this->assertTrue($reflection->getMethod('issue')->isPrivate());
        $this->assertTrue($reflection->getMethod('claimCurrentSessionConfirmation')->isPrivate());

        $publicIssueOrClaimMethods = array_values(array_filter(
            array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC)),
            fn (string $name): bool => str_contains($name, 'issue') || str_contains($name, 'claim')
        ));
        sort($publicIssueOrClaimMethods);
        $this->assertSame([
            'claimCurrentSessionConfirmationFor',
            'issueForCurrentSession',
        ], $publicIssueOrClaimMethods);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertFalse(
                    $this->parameterAcceptsCheckoutContext($parameter),
                    "Public method {$method->getName()} must not accept checkout confirmation context."
                );
            }
        }

        $this->assertStringContainsString("getDriverName() !== 'pgsql'", $service);
        $this->assertStringContainsString('DB::transactionLevel() < 1', $service);
        $this->assertStringContainsString('$this->checkoutAuthorization->authorize($context->actor)', $service);
        $this->assertStringContainsString('fingerprintSession(session()->getId())', $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("clock_timestamp() AT TIME ZONE 'UTC'", $service);
        $this->assertStringContainsString('cleanupCurrentSessionReference', $service);
        $this->assertStringContainsString('CheckoutSensitiveConfirmationPreflightResult', $service);
    }

    public function test_package8_migrations_contain_source_relationship_guards(): void
    {
        $evidenceMigration = file_get_contents(base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000001_create_checkout_sensitive_confirmation_evidence_tables.php'));
        $fdC1Migration = file_get_contents(base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000002_add_package8_confirmation_evidence_to_front_desk_checkout_executions.php'));

        $this->assertStringContainsString('p8_csc_issue_source_guard', $evidenceMigration);
        $this->assertStringContainsString('P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH', $evidenceMigration);
        $this->assertStringContainsString('property_company_id IS DISTINCT FROM NEW.company_id', $evidenceMigration);
        $this->assertStringContainsString('stay_property_id IS DISTINCT FROM NEW.property_id', $evidenceMigration);
        $this->assertStringContainsString('P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH', $evidenceMigration);
        $this->assertStringContainsString('issue_idempotency_key IS DISTINCT FROM NEW.checkout_idempotency_key', $evidenceMigration);

        $this->assertStringContainsString('fd_ce_p8_confirmation_source_guard', $fdC1Migration);
        $this->assertStringContainsString('P8_CHECKOUT_EXECUTION_CONFIRMATION_SOURCE_MISMATCH', $fdC1Migration);
        $this->assertStringContainsString('NEW.created_by IS DISTINCT FROM consume_actor_id', $fdC1Migration);
        $this->assertStringContainsString('NEW.checkout_confirmation_consumed_at IS DISTINCT FROM consume_consumed_at', $fdC1Migration);
        $this->assertStringContainsString('NEW.checkout_confirmed_at IS DISTINCT FROM issue_confirmed_at', $fdC1Migration);
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

    private function parameterAcceptsCheckoutContext(ReflectionParameter $parameter): bool
    {
        return $this->typeContainsClass($parameter->getType(), CheckoutSensitiveConfirmationContext::class);
    }

    private function typeContainsClass(?ReflectionType $type, string $class): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === $class;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if ($this->typeContainsClass($nestedType, $class)) {
                    return true;
                }
            }
        }

        return false;
    }
}
