# First-Trust Runtime Integration

Status: B5A2B implementation record. Production remains fail-closed until B5A2C installs an independently reviewed real authority adapter.

## Boundary and deployment ownership

`FirstTrustOrchestrator` owns the complete security and state machine. The CLI only supplies nine nonsecret business fields and a serialized authorization through bounded standard input or a hidden interactive prompt. Environment, installation ID, release SHA, authority identity, audience, and verification-bundle path come only from `config/first-trust.php`; there are no CLI overrides.

The verification bundle is not parsed or cryptographically verified in B5A2B. Its exact nonempty bytes, bounded to 1 MiB, are SHA-256 fingerprinted for the canonical request binding. When Git metadata exists, the configured 40-character lowercase release SHA must equal `git rev-parse HEAD`.

The default `FirstTrustAuthority` binding remains `UnconfiguredFirstTrustAuthority`. Consequently, the production command fails with `FIRST_TRUST_AUTHORITY_UNCONFIGURED` before any local mutation. B5A2B provides no AWS adapter, HTTP authority adapter, signature verifier, or live-authority capability.

## Command contract and secret handling

Command: `ivorq:first-trust:establish`.

Accepted options are `--owner-name`, `--owner-email`, `--company-name`, `--company-slug`, `--property-name`, `--property-slug`, `--property-code`, `--property-timezone`, `--property-currency`, and `--authorization-stdin`.

Authorization stdin is limited to 65,536 bytes. Empty, oversized, or NUL-bearing input is rejected. Only one final LF or CRLF introduced by transport is removed; other signed bytes are unchanged. Without `--authorization-stdin`, only an interactive hidden prompt is accepted. Output contains only durable nonsecret identifiers, completion evidence, and delivery state. Expected failures render stable identifiers without stack traces or provider response bodies.

## State machine

1. Validate deployment configuration, release binding, bundle fingerprint, and canonical request.
2. Verify the serialized authorization before any local mutation.
3. Read authoritative state before reserve or local mutation.
4. `AVAILABLE` mints one server-side `ftx-<ULID>` and reserves it.
5. `RESERVED` recovers only the authoritative winning execution.
6. `CONSUMED` permits reconciliation only when the exact durable local foundation exists.
7. `REVOKED`, `EXPIRED`, invalid, or unavailable state fails closed.
8. After a valid reservation, create the foundation in one PostgreSQL transaction.
9. Consume externally after the local foundation commits.
10. Mark the local ledger `COMPLETED` only after verified consumption.
11. Attempt initial activation delivery only after the completion transaction commits.

Ambiguous reserve is resolved by authoritative `state()`. Ambiguous consume is resolved by `state()`; one same-execution consume retry is permitted only while the recovery deadline remains valid. The ledger is never completed optimistically.

## PostgreSQL serialization and empty-state guard

The foundation transaction first acquires:

`pg_advisory_xact_lock(hashtext('ivorq:first-trust:' || lowercase_environment), hashtext(installation_id))`.

It then locks the following tables in one `SHARE ROW EXCLUSIVE` statement and this exact order:

`activity_logs`, `audit_logs`, `companies`, `first_trust_runs`, `identity_challenges`, `identity_security_events`, `model_has_permissions`, `model_has_roles`, `owner_activation_tokens`, `owner_activations`, `owner_mfa_factors`, `owner_recovery_codes`, `personal_access_tokens`, `properties`, `property_bootstrap_provisioning_runs`, `property_user`, `sessions`, `user_devices`, `user_sessions`, `users`.

Missing tables fail closed. Raw, unscoped predicates include soft-deleted rows. Role and permission catalogue definitions are allowed; human assignments are not. After inserting the current `IN_PROGRESS` ledger row, every protected authority/history table must remain empty except that exact row.

## Atomic local transaction

The single transaction inserts the `IN_PROGRESS` ledger identity, verifies protected emptiness, creates exactly one active Company, one active Property, one inactive and unverified User, one active/default membership, and one `INVITED` OwnerActivation through `OwnerActivationService::inviteExistingOwner()`. It then atomically binds all ledger result fields and `foundation_committed_at`. Any exception rolls back the entire local universe; an external reservation may remain recoverable only under its original execution.

The membership fingerprint is SHA-256 of `IVORQ-FIRST-TRUST-MEMBERSHIP-V1\0` followed by canonical JSON of `is_default`, the exact stored UTC `joined_at`, `property_id`, `status`, and `user_id`.

## Completion and activation delivery

Verified consumption is rechecked against all immutable local identities. A short transaction takes the same advisory lock and the exact ledger row `FOR UPDATE`, then sets consumption evidence, external consumption time, completion evidence, completion time, and `COMPLETED` together.

The completion fingerprint is SHA-256 of `IVORQ-FIRST-TRUST-COMPLETION-V1\0` followed by canonical JSON of the persisted nonsecret run, reservation, foundation, membership, and consumption evidence. Raw authorization and activation tokens are excluded.

The frozen membership vector uses this exact canonical JSON:

```json
{"is_default":true,"joined_at":"2026-01-02T03:04:05Z","property_id":"01PROPERTY000000000000000000","status":"active","user_id":"01USER0000000000000000000000"}
```

Its fingerprint is `c8e09a3c27046f75afe8f8ebb4156e4223b09a7ec928b19c9c37e7aa2b78f74c`.

The frozen completion vector uses this exact canonical JSON:

```json
{"authority_issuer":"https://authority.example.test/rehearsal","authority_reference":"authority/rehearsal/v1","authorization_id":"authorization-001","canonical_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","company_id":"01COMPANY000000000000000000","consumption_fingerprint":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","consumption_reference":"consumption-001","environment":"rehearsal","execution_id":"ftx-01EXECUTION000000000000000","external_consumed_at":"2026-01-02T03:10:00Z","foundation_committed_at":"2026-01-02T03:05:00Z","installation_id":"installation-001","membership_fingerprint":"c8e09a3c27046f75afe8f8ebb4156e4223b09a7ec928b19c9c37e7aa2b78f74c","membership_property_id":"01PROPERTY000000000000000000","membership_user_id":"01USER0000000000000000000000","owner_activation_id":"01ACTIVATION0000000000000000","property_id":"01PROPERTY000000000000000000","request_fingerprint":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","reservation_commit_deadline":"2026-01-02T03:19:05Z","reservation_fingerprint":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","reservation_recovery_deadline":"2026-01-03T03:04:05Z","reservation_reference":"reservation-001","reservation_reserved_at":"2026-01-02T03:04:05Z","run_id":"01RUN0000000000000000000000","user_id":"01USER0000000000000000000000","verifier_bundle_fingerprint":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","verifier_key_id":"key-001"}
```

Its fingerprint is `8132e6fac073004d60eae67f5ffe255c0e7a759f19d23a35fdeeb6b13b87fea8`.

Only after commit does `FirstTrustActivationDeliveryService` issue a `verify_email` token and synchronously send `OwnerActivationNotification`. Mail failure leaves first trust `COMPLETED` and OwnerActivation `INVITED`, returning `failed_recovery_available`; the accepted `requestResume` flow is the recovery path. An exact retry of an already completed run is read-only and does not resend.

## Failure and recovery matrix

| Condition | Result |
|---|---|
| Invalid config, bundle, release, or authorization | Fail before authority mutation/local mutation |
| Reserve timeout, state proves same execution reserved | Continue same execution |
| Reserve state proves another execution | Reject takeover |
| Local foundation transaction fails | Roll back all local writes; same external execution may recover |
| Local complete foundation + external reserved | Consume/reconcile only; never recreate foundation |
| External consumed + matching local in-progress | Complete locally, then attempt initial delivery |
| External consumed without local foundation | Governance-required divergence failure |
| Consume ambiguous, state unavailable | Preserve local `IN_PROGRESS`; no activation delivery |
| Recovery deadline expired | Preserve evidence and domain rows; require governance recovery |
| Local completed + external consumed exact retry | Return durable result without mutation or redelivery |
| Changed security-relevant request | `REQUEST_FINGERPRINT_MISMATCH`, no mutation |
| Notification failure | Keep `COMPLETED`/`INVITED`; use `requestResume` |

B5A2C, AWS integration, real authorization parsing/signature verification, operational/rehearsal business bootstrap, B4C, Business Date, Finance, Inventory, CostControl, pilot, and cutover remain outside this implementation.
