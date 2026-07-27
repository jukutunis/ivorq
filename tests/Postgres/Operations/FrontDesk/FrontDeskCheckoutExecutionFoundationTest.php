<?php

namespace Tests\Postgres\Operations\FrontDesk;

use ReflectionMethod;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\ValueObjects\CheckoutSensitiveConfirmationPreflightResult;
use Modules\Operations\FrontDesk\ValueObjects\FrontDeskCheckoutExecutionResult;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionFoundationTest extends PostgresTestCase
{
    public function test_public_confirmation_authority_does_not_accept_preflight_result(): void
    {
        $reflection = new \ReflectionClass(CheckoutSensitiveConfirmationService::class);

        $this->assertTrue($reflection->getMethod('issueForCurrentSession')->isPublic());
        $this->assertTrue($reflection->getMethod('validateCurrentSessionConfirmationFor')->isPublic());
        $this->assertTrue($reflection->getMethod('claimCurrentSessionConfirmationFor')->isPublic());
        $this->assertTrue($reflection->getMethod('cleanupCurrentSessionReference')->isPublic());
        $this->assertFalse($reflection->hasMethod('claimCurrentSessionConfirmationFromPreflight'));

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $this->assertNotSame(
                    CheckoutSensitiveConfirmationPreflightResult::class,
                    $type instanceof \ReflectionNamedType ? $type->getName() : null,
                    "Public method {$method->getName()} must not accept checkout preflight as claim authority."
                );
            }
        }
    }

    public function test_checkout_receipt_exposes_minimized_committed_statuses_only(): void
    {
        $receipt = new FrontDeskCheckoutExecutionResult(
            propertyId: 'property',
            frontDeskStayId: 'stay',
            reservationId: 'reservation',
            checkoutExecutionId: 'execution',
            idempotencyKey: 'key',
            terminalStatus: 'CHECKED_OUT',
            businessDate: '2026-07-27',
            occurredAt: '2026-07-27T00:00:00Z',
            handoffId: 'handoff',
            handoffDeliveryStatus: 'PENDING',
            nightAuditStatus: 'NIGHT_AUDIT_LOCK_CLEAR',
            pmsTerminalFinancialStatus: 'PMS_TERMINAL_FINANCIAL_READY',
            generalCashierTerminalObligationStatus: 'GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR',
            replayed: false,
        );

        $payload = $receipt->toArray();

        $this->assertSame('NIGHT_AUDIT_LOCK_CLEAR', $payload['night_audit_status']);
        $this->assertSame('PMS_TERMINAL_FINANCIAL_READY', $payload['pms_terminal_financial_status']);
        $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $payload['general_cashier_terminal_obligation_status']);
        $this->assertArrayNotHasKey('night_audit_source_fingerprint', $payload);
        $this->assertArrayNotHasKey('pms_financial_attestation_fingerprint', $payload);
        $this->assertArrayNotHasKey('general_cashier_attestation_fingerprint', $payload);
    }
}
