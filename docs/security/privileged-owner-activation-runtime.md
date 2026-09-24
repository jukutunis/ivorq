# Privileged Owner Activation Runtime

Status: implementation record for CC-P02B5A1B1. ADR-090 remains the governing approved architecture decision.

The single-property `installation-owner` activation endpoint returns ten recovery codes only in the successful response that commits the final activation transaction. IVORQ stores only domain-separated SHA-256 digests of those codes.

If that response is lost after commit, the activation remains `ACTIVE`. Replaying the completion endpoint fails and cannot redisplay or silently regenerate the original codes. The owner can authenticate normally with the confirmed TOTP factor, then explicitly regenerate recovery codes using fresh password confirmation and a current TOTP. Regeneration revokes every unused prior code, increments `users.auth_epoch`, revokes all existing web and API credentials, and returns the replacement codes once.

Fresh-password authorization in the B5A1B1 owner runtime is serialized on the authoritative `users` row. Privileged owner login, active-owner password change, and recovery-code regeneration acquire the User `FOR UPDATE` lock before checking the current hash and hold that lock through the authorized mutation. Owner password reset uses the same User-first mutation lock. The identity-security ledger accepts only declared event types, outcomes, structural context, and event-specific typed metadata; unknown or nested arbitrary metadata is rejected before persistence, with PostgreSQL constraints enforcing JSON-object shape and the bounded global top-level key set.

Managed recoverability note: the unmerged B5A1B1 migration rollback does not restore `users.password` to `NOT NULL`, and it does not drop the `sessions` table when B5A1B1 originally created that table. These known rollback limitations are unchanged by the R1 security correction.

This runtime does not provide public, email-only, recovery-question, or support-bypass MFA reset. High-assurance owner recovery remains a separately authorized future package. It also does not implement AWS authority integration, B5A2 first trust, multi-property owner authority, pilot, or cutover.
