<?php

namespace Modules\Finance\Banking\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\User\Models\User;
use Throwable;

class BankingSourceEvidenceService
{
    public const REGISTER_ACCOUNT_PERMISSION = 'finance.banking.source-account.register';
    public const REGISTER_STATEMENT_LINE_PERMISSION = 'finance.banking.statement-line.register';
    public const ACCOUNT_CONTRACT = 'controlled_banking_account_v1';
    public const STATEMENT_LINE_CONTRACT = 'controlled_banking_statement_line_v1';

    public function registerBankAccount(
        string $operationalGlAccountId,
        string $bankName,
        string $accountName,
        string $externalAccountReference,
        string $currencyCode,
        string $sourceReference,
        ?User $actor
    ): ControlledBankAccount {
        return DB::transaction(function () use (
            $operationalGlAccountId,
            $bankName,
            $accountName,
            $externalAccountReference,
            $currencyCode,
            $sourceReference,
            $actor
        ): ControlledBankAccount {
            $actor = $this->resolveAuthorizedActor($actor, self::REGISTER_ACCOUNT_PERMISSION);
            $account = $this->resolveBankControlAccount($operationalGlAccountId);
            $this->assertActorCanAccessProperty($actor, $account->property_id, 'Banking source account registration requires active property access.');

            $identity = [
                'property_id' => $account->property_id,
                'operational_gl_account_id' => $account->id,
                'bank_name' => $this->requiredText($bankName, 'Banking source bank name is required.'),
                'account_name' => $this->requiredText($accountName, 'Banking source account name is required.'),
                'external_account_reference' => $this->requiredText($externalAccountReference, 'Banking source external account reference is required.'),
                'currency_code' => $this->currencyCode($currencyCode),
                'source_reference' => $this->requiredText($sourceReference, 'Banking source account source reference is required.'),
            ];

            $existing = ControlledBankAccount::where('property_id', $identity['property_id'])
                ->where('operational_gl_account_id', $identity['operational_gl_account_id'])
                ->lockForUpdate()
                ->first();

            $identityHash = $this->accountIdentityHash($identity, $actor->id);
            $snapshot = $this->accountSnapshot($identity, $actor->id);

            if ($existing) {
                $this->assertExistingAccountMatches($existing, $identity, $actor->id, $identityHash);

                return $existing->fresh();
            }

            $bankAccount = new ControlledBankAccount($identity + [
                'is_active' => true,
                'registered_by' => $actor->id,
                'registered_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $bankAccount->created_by = $actor->id;
            $bankAccount->updated_by = $actor->id;
            $bankAccount->save();

            return $bankAccount->fresh();
        });
    }

    public function registerStatementLine(
        string $controlledBankAccountId,
        string $sourceReference,
        string $externalReference,
        string $statementDate,
        string|ControlledBankStatementLineDirectionEnum $direction,
        mixed $amount,
        string $currencyCode,
        ?User $actor,
        ?string $vendorReference = null
    ): ControlledBankStatementLine {
        return DB::transaction(function () use (
            $controlledBankAccountId,
            $sourceReference,
            $externalReference,
            $statementDate,
            $direction,
            $amount,
            $currencyCode,
            $actor,
            $vendorReference
        ): ControlledBankStatementLine {
            $actor = $this->resolveAuthorizedActor($actor, self::REGISTER_STATEMENT_LINE_PERMISSION);

            $bankAccount = ControlledBankAccount::whereKey($controlledBankAccountId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$bankAccount) {
                throw new DomainException('Active controlled Banking account is unavailable.');
            }

            $this->assertActorCanAccessProperty($actor, $bankAccount->property_id, 'Banking statement-line registration requires active property access.');

            $currency = $this->currencyCode($currencyCode);
            if ($currency !== $bankAccount->currency_code) {
                throw new DomainException('Banking statement-line currency conflicts with controlled bank account.');
            }

            $amount = $this->amountString($amount);
            if ($this->amountToCents($amount) <= 0) {
                throw new DomainException('Banking statement-line amount must be positive.');
            }

            $direction = $this->direction($direction);
            $identity = [
                'controlled_bank_account_id' => $bankAccount->id,
                'property_id' => $bankAccount->property_id,
                'source_reference' => $this->requiredText($sourceReference, 'Banking statement-line source reference is required.'),
                'external_reference' => $this->requiredText($externalReference, 'Banking statement-line external reference is required.'),
                'statement_date' => Carbon::parse($statementDate)->toDateString(),
                'direction' => $direction->value,
                'amount' => $amount,
                'currency_code' => $currency,
                'vendor_reference' => $vendorReference !== null && trim($vendorReference) !== ''
                    ? trim($vendorReference)
                    : null,
            ];

            $existing = ControlledBankStatementLine::where('controlled_bank_account_id', $bankAccount->id)
                ->where('external_reference', $identity['external_reference'])
                ->lockForUpdate()
                ->first();

            $identityHash = $this->statementLineIdentityHash($identity, $actor->id);
            $snapshot = $this->statementLineSnapshot($identity, $actor->id);

            if ($existing) {
                $this->assertExistingStatementLineMatches($existing, $identity, $actor->id, $identityHash);

                return $existing->fresh();
            }

            $statementLine = new ControlledBankStatementLine($identity + [
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $statementLine->created_by = $actor->id;
            $statementLine->updated_by = $actor->id;
            $statementLine->save();

            return $statementLine->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor, string $permission): User
    {
        if (!$actor) {
            throw new AuthorizationException('Banking source evidence requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Banking source evidence requires an active actor.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Banking source evidence permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Banking source evidence permission is required.');
        }

        return $freshActor;
    }

    private function resolveBankControlAccount(string $accountId): Account
    {
        $account = Account::whereKey($accountId)
            ->where('is_active', true)
            ->where('is_cash_equivalent', true)
            ->lockForUpdate()
            ->first();

        if (!$account) {
            throw new DomainException('Active property-scoped bank control account is unavailable.');
        }

        return $account;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId, string $message): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException($message);
        }
    }

    private function assertExistingAccountMatches(
        ControlledBankAccount $existing,
        array $identity,
        string $actorId,
        string $identityHash
    ): void {
        if (
            $existing->is_active === true &&
            $existing->bank_name === $identity['bank_name'] &&
            $existing->account_name === $identity['account_name'] &&
            $existing->external_account_reference === $identity['external_account_reference'] &&
            $existing->currency_code === $identity['currency_code'] &&
            $existing->source_reference === $identity['source_reference'] &&
            $existing->registered_by === $actorId &&
            $existing->registered_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting controlled Banking account evidence already exists.');
    }

    private function assertExistingStatementLineMatches(
        ControlledBankStatementLine $existing,
        array $identity,
        string $actorId,
        string $identityHash
    ): void {
        if (
            $existing->property_id === $identity['property_id'] &&
            $existing->source_reference === $identity['source_reference'] &&
            $existing->statement_date->toDateString() === $identity['statement_date'] &&
            $existing->direction === ControlledBankStatementLineDirectionEnum::from($identity['direction']) &&
            $this->amountString($existing->amount) === $identity['amount'] &&
            $existing->currency_code === $identity['currency_code'] &&
            $existing->vendor_reference === $identity['vendor_reference'] &&
            $existing->recorded_by === $actorId &&
            $existing->recorded_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting controlled Banking statement-line evidence already exists.');
    }

    private function accountIdentityHash(array $identity, string $actorId): string
    {
        return hash('sha256', implode('|', [
            self::ACCOUNT_CONTRACT,
            $identity['property_id'],
            $identity['operational_gl_account_id'],
            $identity['bank_name'],
            $identity['account_name'],
            $identity['external_account_reference'],
            $identity['currency_code'],
            $identity['source_reference'],
            $actorId,
        ]));
    }

    private function statementLineIdentityHash(array $identity, string $actorId): string
    {
        return hash('sha256', implode('|', [
            self::STATEMENT_LINE_CONTRACT,
            $identity['controlled_bank_account_id'],
            $identity['property_id'],
            $identity['source_reference'],
            $identity['external_reference'],
            $identity['statement_date'],
            $identity['direction'],
            $identity['amount'],
            $identity['currency_code'],
            $identity['vendor_reference'] ?? '',
            $actorId,
        ]));
    }

    private function accountSnapshot(array $identity, string $actorId): array
    {
        return [
            'contract' => self::ACCOUNT_CONTRACT,
            'property_id' => $identity['property_id'],
            'operational_gl_account_id' => $identity['operational_gl_account_id'],
            'bank_name' => $identity['bank_name'],
            'account_name' => $identity['account_name'],
            'external_account_reference' => $identity['external_account_reference'],
            'currency_code' => $identity['currency_code'],
            'source_reference' => $identity['source_reference'],
            'registered_by' => $actorId,
        ];
    }

    private function statementLineSnapshot(array $identity, string $actorId): array
    {
        return [
            'contract' => self::STATEMENT_LINE_CONTRACT,
            'controlled_bank_account_id' => $identity['controlled_bank_account_id'],
            'property_id' => $identity['property_id'],
            'source_reference' => $identity['source_reference'],
            'external_reference' => $identity['external_reference'],
            'statement_date' => $identity['statement_date'],
            'direction' => $identity['direction'],
            'amount' => $identity['amount'],
            'currency_code' => $identity['currency_code'],
            'vendor_reference' => $identity['vendor_reference'],
            'recorded_by' => $actorId,
        ];
    }

    private function requiredText(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new DomainException($message);
        }

        return $value;
    }

    private function currencyCode(string $currencyCode): string
    {
        $currency = strtoupper(trim($currencyCode));
        if (strlen($currency) !== 3) {
            throw new DomainException('Banking source currency must be a three-character code.');
        }

        return $currency;
    }

    private function direction(string|ControlledBankStatementLineDirectionEnum $direction): ControlledBankStatementLineDirectionEnum
    {
        if ($direction instanceof ControlledBankStatementLineDirectionEnum) {
            return $direction;
        }

        return ControlledBankStatementLineDirectionEnum::from(strtoupper(trim($direction)));
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function amountString(mixed $amount): string
    {
        return number_format(((float) $amount), 2, '.', '');
    }
}
