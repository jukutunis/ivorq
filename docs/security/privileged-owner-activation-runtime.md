# Privileged Owner Activation Runtime

Status: implementation record for CC-P02B5A1B1. ADR-090 remains the governing approved architecture decision.

The single-property `installation-owner` activation endpoint returns ten recovery codes only in the successful response that commits the final activation transaction. IVORQ stores only domain-separated SHA-256 digests of those codes.

If that response is lost after commit, the activation remains `ACTIVE`. Replaying the completion endpoint fails and cannot redisplay or silently regenerate the original codes. The owner can authenticate normally with the confirmed TOTP factor, then explicitly regenerate recovery codes using fresh password confirmation and a current TOTP. Regeneration revokes every unused prior code, increments `users.auth_epoch`, revokes all existing web and API credentials, and returns the replacement codes once.

This runtime does not provide public, email-only, recovery-question, or support-bypass MFA reset. High-assurance owner recovery remains a separately authorized future package. It also does not implement AWS authority integration, B5A2 first trust, multi-property owner authority, pilot, or cutover.
