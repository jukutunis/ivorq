<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverIntakeSourceIntegrityTest extends PostgresTestCase
{
    public function test_no_housekeeping_browser_route_executes_checkout_turnover_consumer(): void
    {
        $routes = file_get_contents(base_path('Modules/Operations/Housekeeping/routes/web.php'));
        $this->assertStringNotContainsString('HousekeepingCheckoutTurnoverIntakeService', $routes);
        $this->assertStringNotContainsString('consume-checkout-turnover', $routes);
        $this->assertStringNotContainsString('checkout-turnover-handoffs', $routes);
    }

    public function test_no_react_or_typescript_added_for_package_11(): void
    {
        $files = $this->scan(base_path('resources/js'), ['ts', 'tsx', 'js', 'jsx']);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('CheckoutTurnoverIntake', $source, $file);
            $this->assertStringNotContainsString('checkoutTurnoverIntake', $source, $file);
            $this->assertStringNotContainsString('consumeCheckoutTurnover', $source, $file);
        }
        $this->addToAssertionCount(1);
    }

    public function test_consumer_does_not_rerun_checkout_or_confirmation_consumption(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));

        $this->assertStringNotContainsString('FrontDeskCheckoutExecutionService::execute', $service);
        $this->assertStringNotContainsString('->execute(', $service);
        $this->assertStringNotContainsString('CheckoutSensitiveConfirmationService', $service);
        $this->assertStringNotContainsString('claimCurrentSessionConfirmationFor', $service);
        $this->assertStringContainsString('markDelivered', $service);
        $this->assertStringContainsString('markFailed', $service);
    }

    public function test_post_commit_testing_hook_is_private_testing_only_and_inert_by_default(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));

        $this->assertStringContainsString('private $postCommitTestingHook = null', $service);
        $this->assertStringContainsString('setPostCommitTestingHookForTesting', $service);
        $this->assertStringContainsString("app()->environment('testing')", $service);
        $this->assertStringContainsString('HK_P11_TESTING_HOOK_NOT_AVAILABLE', $service);
        $this->assertStringNotContainsString('public $testSeamPostCommitHook', $service);
    }

    public function test_phase_c_pending_delivery_is_limited_to_fd_c2_claim_state_domain_markers(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));

        $this->assertStringContainsString('catch (DomainException $exception)', $service);
        $this->assertStringNotContainsString('catch (\\Throwable $exception)', $service);
        $this->assertStringContainsString('isFdC2ClaimStateDomainException', $service);

        foreach ([
            'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM',
            'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN',
            'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE',
            'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION',
        ] as $marker) {
            $this->assertStringContainsString($marker, $service);
        }
    }

    public function test_no_scheduler_queue_broker_or_external_http_in_consumer(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));
        $provider = file_get_contents(base_path('Modules/Operations/Housekeeping/HousekeepingServiceProvider.php'));

        foreach (['Http::', 'Guzzle', 'dispatch(', 'ShouldQueue', 'Schedule', 'Redis::', 'Queue::'] as $pattern) {
            $this->assertStringNotContainsString($pattern, $service);
            $this->assertStringNotContainsString($pattern, $provider);
        }
    }

    public function test_room_mutation_is_local_to_package_11_consumer_service(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));

        $this->assertStringContainsString("DB::table('rooms')", $service);
        $this->assertStringContainsString("'readiness_state' => 'waiting_cleaning'", $service);
        $this->assertStringContainsString("'cleanliness_status' => 'dirty'", $service);
    }

    public function test_intake_is_privacy_minimized(): void
    {
        $migration = file_get_contents(base_path('Modules/Operations/Housekeeping/database/migrations/2026_07_30_000001_create_housekeeping_checkout_turnover_intakes_table.php'));

        foreach ([
            'guest_name', 'guest_email', 'guest_phone', 'email', 'phone',
            'payment_evidence', 'folio', 'cashier', 'raw_exception',
            'claim_token', 'session_id', 'password',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $migration);
        }
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    private function scan(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
