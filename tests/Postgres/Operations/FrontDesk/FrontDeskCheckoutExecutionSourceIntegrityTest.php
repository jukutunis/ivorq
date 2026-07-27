<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionSourceIntegrityTest extends PostgresTestCase
{
    public function test_react_checkout_hooks_are_declared_before_conditional_boundary_return(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx'));

        $component = strpos($source, 'function CheckoutExecutionBoundaryPanel');
        $firstHook = strpos($source, 'React.useState(false)', $component);
        $nullReturn = strpos($source, 'if (!boundary)', $component);

        $this->assertIsInt($component);
        $this->assertIsInt($firstHook);
        $this->assertIsInt($nullReturn);
        $this->assertLessThan($nullReturn, $firstHook, 'CheckoutExecutionBoundaryPanel hooks must run before conditional boundary returns.');
    }

    public function test_react_checkout_password_and_idempotency_lifecycle_is_bounded(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx'));

        $this->assertStringContainsString('finally {', $source);
        $this->assertStringContainsString("setPassword('');", $source);
        $this->assertStringContainsString("setIdempotencyKey(`p9-\${stayId}-\${Date.now()}-\${Math.random().toString(36).slice(2)}`);", $source);
        $this->assertStringNotContainsString('localStorage', $source);
        $this->assertStringNotContainsString('sessionStorage', $source);

        $catchPosition = strpos($source, "} catch (caught) {\n      setError(caught instanceof Error ? caught.message : 'Checkout execution failed.');");
        $receiptPosition = strpos($source, 'setReceipt(payload as CheckoutExecutionReceipt);');
        $resetPosition = strpos($source, 'setIdempotencyKey(`p9-${stayId}-${Date.now()}-${Math.random().toString(36).slice(2)}`);');
        $this->assertIsInt($catchPosition);
        $this->assertIsInt($receiptPosition);
        $this->assertIsInt($resetPosition);
        $this->assertGreaterThan($receiptPosition, $resetPosition, 'Execution idempotency key resets after the successful receipt path starts.');
        $this->assertLessThan($catchPosition, $resetPosition, 'Execution idempotency key must not reset inside the uncertain failure path.');
    }

    public function test_react_checkout_receipt_uses_server_returned_execution_evidence(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx'));

        $this->assertStringContainsString('receipt.night_audit_status', $source);
        $this->assertStringContainsString('receipt.pms_terminal_financial_status', $source);
        $this->assertStringContainsString('receipt.general_cashier_terminal_obligation_status', $source);
        $this->assertStringNotContainsString('Financial: {guestLedger.status', $source);
        $this->assertStringNotContainsString('Cashier: {cashierObligation.status', $source);
        $this->assertStringNotContainsString('room cleanliness', strtolower($source));
        $this->assertStringNotContainsString('housekeeping completion', strtolower($source));
    }
}
