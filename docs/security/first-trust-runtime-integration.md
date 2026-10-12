# First-trust runtime integration

## Scope and trust boundary

IVORQ verifies protocol-v1 first-trust evidence locally and calls the separately governed authority only for reserve, state, and consume. It does not provision AWS resources, discover remote keys, or perform normal post-installation authorization through this adapter.

The protocol source of truth is `ivorq-first-trust-authority` commit `658a2d694891bf667150779e6af965dd28a5f24e`. Its signed-envelope wire representation is canonical JSON containing `protocol`, `kind`, immutable KMS `key_id`, `signature_encoding`, base64url canonical payload bytes, and a DER ECDSA signature. The runtime deliberately follows that accepted repository contract rather than the earlier dotted-envelope concept.

## Activation

The production adapter is activated only when all existing first-trust configuration values are nonempty: environment, installation ID, release SHA, authority reference, issuer, audience, HTTPS base URL, and verification-bundle path. Incomplete configuration resolves to the existing fail-closed unconfigured authority.

AWS credentials use the AWS SDK default temporary-credential provider chain. The signing region is externally resolved from `AWS_REGION`, `AWS_DEFAULT_REGION`, or the selected profile in the standard shared AWS config file. No credential or region secret is stored in IVORQ configuration. Container registration and resolution do not resolve credentials, load the bundle, or perform network traffic.

## Pinned verification bundle

The configured file is read as exact bytes, bounded to 256 KiB, and fingerprinted with lowercase SHA-256 without reserialization. A single accepted protocol-v1 bundle entry is the external schema. For controlled rotation, the deployment file may be a top-level JSON list of those unchanged accepted entries; no entry fields or key semantics are extended. Duplicate immutable KMS key ARNs are rejected.

Each entry must match the configured environment, issuer, and audience. Its key must be a public SPKI PEM for EC `prime256v1`/P-256, and its declared fingerprint must equal SHA-256 of the SPKI DER bytes. Private keys, aliases, response-supplied keys, other curves, RSA, Ed25519, and remote key discovery are rejected. Activation and retirement metadata bound which key may verify evidence generated at a given time.

## Verification and transport

The parser rejects non-UTF-8, duplicate or unknown fields, noncanonical base64url, noncanonical payload JSON, kind substitution, unsafe values, and oversized envelopes. Verification uses the external repository's exact per-kind domain prefix and `openssl_verify(..., OPENSSL_ALGO_SHA256)` over the exact canonical payload bytes. Expected binding, issuer, audience where defined, authorization lifetime, key window, and exact nested state evidence are checked before DTO construction. Nested fingerprints are SHA-256 of the exact serialized nested envelopes returned by the authority.

Reserve, state, and consume issue one HTTPS POST to the accepted protocol routes. Requests are signed with official AWS SDK Signature V4 for `execute-api`, use bounded connection and request timeouts, and disable redirects and automatic retry. Success and error bodies are bounded to 256 KiB. Only allowlisted stable protocol errors cross the application boundary; raw transport, AWS, header, credential, request, response, and signed-artifact details do not.

`verifyAuthorization()` is entirely offline: it neither resolves AWS credentials nor sends HTTP traffic.

## Deployment controls

Bundle replacement and key rotation are reviewed deployment operations. Deploy the old and new accepted public entries before the authority starts signing with the new key, retain the old public entry for the evidence-retention window, and never reuse Operational material in Rehearsal or vice versa. A bundle-file byte change also changes the request-canonicalization fingerprint and must be deployed coherently.
