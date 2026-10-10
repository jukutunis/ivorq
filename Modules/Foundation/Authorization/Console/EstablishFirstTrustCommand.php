<?php

namespace Modules\Foundation\Authorization\Console;

use Illuminate\Console\Command;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

final class EstablishFirstTrustCommand extends Command
{
    private const MAXIMUM_AUTHORIZATION_BYTES = 65536;

    protected $signature = 'ivorq:first-trust:establish
        {--owner-name=}
        {--owner-email=}
        {--company-name=}
        {--company-slug=}
        {--property-name=}
        {--property-slug=}
        {--property-code=}
        {--property-timezone=}
        {--property-currency=}
        {--authorization-stdin}';

    protected $description = 'Establish the one-time IVORQ first-trust foundation.';

    public function handle(FirstTrustOrchestrator $orchestrator): int
    {
        try {
            $authorization = $this->authorization();
            $result = $orchestrator->establish(new FirstTrustRequest(
                $this->requiredOption('owner-name'),
                $this->requiredOption('owner-email'),
                $this->requiredOption('company-name'),
                $this->requiredOption('company-slug'),
                $this->requiredOption('property-name'),
                $this->requiredOption('property-slug'),
                $this->requiredOption('property-code'),
                $this->requiredOption('property-timezone'),
                $this->requiredOption('property-currency'),
            ), $authorization);

            foreach ([
                'run_id' => $result->runId,
                'execution_id' => $result->executionId,
                'state' => $result->state,
                'user_id' => $result->userId,
                'company_id' => $result->companyId,
                'property_id' => $result->propertyId,
                'owner_activation_id' => $result->ownerActivationId,
                'completion_evidence_fingerprint' => $result->completionEvidenceFingerprint ?? 'not_available',
                'activation_delivery' => $result->activationDeliveryState,
            ] as $field => $value) {
                $this->line($field.'='.$value);
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($this->sanitizedError($throwable));

            return self::FAILURE;
        }
    }

    private function authorization(): string
    {
        if ((bool) $this->option('authorization-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface
                ? ($this->input->getStream() ?? STDIN)
                : STDIN;

            return $this->readAuthorizationStream($stream);
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_STDIN_REQUIRED');
        }

        $authorization = $this->secret('Serialized first-trust authorization');
        if (! is_string($authorization) || $authorization === '' || str_contains($authorization, "\0")) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_INVALID');
        }
        if (strlen($authorization) > self::MAXIMUM_AUTHORIZATION_BYTES) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_TOO_LARGE');
        }

        return $authorization;
    }

    /** @param resource $stream */
    public function readAuthorizationStream($stream): string
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_INVALID');
        }

        $authorization = stream_get_contents($stream, self::MAXIMUM_AUTHORIZATION_BYTES + 3);
        if (! is_string($authorization)) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_INVALID');
        }
        if (str_ends_with($authorization, "\r\n")) {
            $authorization = substr($authorization, 0, -2);
        } elseif (str_ends_with($authorization, "\n")) {
            $authorization = substr($authorization, 0, -1);
        }

        if ($authorization === '' || str_contains($authorization, "\0")) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_INVALID');
        }
        if (strlen($authorization) > self::MAXIMUM_AUTHORIZATION_BYTES || ! feof($stream)) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_INPUT_TOO_LARGE');
        }

        return $authorization;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('FIRST_TRUST_COMMAND_INPUT_INVALID');
        }

        return $value;
    }

    private function sanitizedError(#[SensitiveParameter] Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        if (preg_match('/\A(?:FIRST_TRUST|AUTHORITY|ALREADY_RESERVED|REQUEST_FINGERPRINT)[A-Z0-9_]*\z/', $message) === 1) {
            return $message;
        }

        return 'FIRST_TRUST_ESTABLISH_FAILED';
    }
}
