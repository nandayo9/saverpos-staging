# SPV1-005 Integration Test Plan

Date: 11 September 2026
Owner: Grace Evelyn
Status: Planned - do not run against live services

## Objective

Confirm that SAVERPOS integrations preserve business authority, prevent duplicate records, protect private data and fail safely before any production activation.

## Mandatory isolation prerequisites

The tests remain BLOCKED until all applicable requirements are confirmed:

- Protected rehearsal POS application and database.
- Synthetic or sanitized test records only.
- Non-production credentials stored outside Git.
- Dedicated WooCommerce or website test destination.
- Payment-provider sandbox accounts.
- Email, Discord, n8n and customer notifications disabled or redirected.
- Outbound network destinations restricted to an approved allowlist.
- Queue and scheduler isolated from live jobs.
- Recommerce activation and write controls off by default.
- Cleanup and rollback procedures prepared.
- Technical, finance and business reviewers named.

## WooCommerce scenarios

1. Confirm whether the WooCommerce module source is installed and loadable.
2. Record the authoritative owner of product name, SKU, price and stock.
3. Map each WooCommerce product and variation ID to exactly one SAVERPOS record.
4. Confirm the allowed stock-sync direction.
5. Import a supported test order exactly once.
6. Process an updated test order without creating a duplicate transaction.
7. Confirm cancellation and refund stock rules.
8. Repeat the same webhook and confirm idempotent processing.
9. Simulate a timeout and retry without duplicating order, payment or stock.
10. Disable the integration and confirm that it fails safely.

## Customer projection scenarios

1. Confirm that the main Recommerce gate is off by default.
2. Confirm that missing or invalid authentication is rejected.
3. Confirm that only allowlisted public fields are returned.
4. Confirm that one business cannot access another business's records.
5. Confirm that unavailable, sold, reserved or draft devices are not published.
6. Confirm neutral handling for unknown devices and cases.
7. Confirm request throttling.
8. Confirm that projection requests cannot write to native POS records.

## Website trade-in scenarios

1. Confirm that indicative valuation does not approve a trade-in.
2. Confirm that indicative valuation does not create stock or purchase records.
3. Confirm that duplicate intake requests do not create duplicate native records.
4. Confirm that customer decisions require valid authentication.
5. Confirm that SAVERPOS remains authoritative for approval and completion.
6. Confirm that only allowlisted status fields leave SAVERPOS.
7. Confirm that host allowlists reject unauthorized destinations.
8. Confirm that disabled write controls fail closed.

## MyFatoorah certificate prerequisite

Current state:

- PHP configuration file: loaded
- `curl.cainfo`: empty
- `openssl.cafile`: empty
- Laravel bootstrap warning: unable to verify the local certificate issuer

Do not run a MyFatoorah sandbox request until:

1. An approved CA bundle is installed locally.
2. PHP is configured to use the approved CA bundle.
3. The configured file exists and is readable.
4. PHP is restarted where required.
5. The certificate settings are rechecked.
6. Laravel starts without the certificate warning.
7. A provider sandbox account is confirmed.

## Payment-provider scenarios

Run only against approved sandboxes:

1. Successful test payment.
2. Declined test payment.
3. Cancelled checkout.
4. Provider timeout.
5. Callback with an invalid signature.
6. Duplicate callback delivery.
7. Callback for an unknown transaction.
8. Amount or currency mismatch.
9. Bounded retry behavior.
10. No duplicate payment or ledger record.

Never use a real card, bank account or customer payment.

## n8n and Discord scenarios

1. Use a private test workflow and destination.
2. Send only synthetic, non-sensitive data.
3. Confirm one business event produces one notification.
4. Confirm retries do not produce duplicate notifications.
5. Confirm a failed destination does not block the POS transaction.
6. Confirm the integration cannot approve, modify or delete POS transactions.

## TikTok settlement scenarios

1. Use sanitized settlement fixtures.
2. Match each order ID to the intended POS transaction.
3. Confirm one settlement row updates at most one intended record.
4. Reconcile discount, paid amount and payment method.
5. Confirm missing-payment cases are reported without silent creation.
6. Repeat the process and confirm it is idempotent.
7. Confirm finance review before accepting the reconciliation.

## Queue and scheduler scenarios

1. Confirm test workers use only the rehearsal database.
2. Confirm scheduled jobs cannot contact live services.
3. Confirm retry counts and timeout limits.
4. Confirm failed jobs are visible and recoverable.
5. Confirm replay does not duplicate business records.
6. Confirm disabling a feature prevents related queued writes.

## Required evidence for every test

Record:

- Candidate commit SHA.
- Test case identifier.
- Date and tester.
- Synthetic fixture identifiers.
- Expected result.
- Actual result.
- Database effect.
- External request count.
- Screenshot or log reference.
- PASS, FAIL or BLOCKED.
- Cleanup result.
- Reviewer name.

## Stop conditions

Stop immediately if:

- A destination may be live.
- A credential may belong to production.
- Customer or production data appears.
- A test creates an unexpected stock, payment or transaction record.
- An external request cannot be counted or traced.
- Recommerce activates unexpectedly.
- The working tree or candidate commit changes unexpectedly.

Production activation is not authorized by this document.
