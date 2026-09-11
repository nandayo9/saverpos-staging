# SAVERPOS V1 Integration Register

Updated: 11 September 2026
Owner: Grace Evelyn
Task: SPV1-005
Status: Assessment documented - runtime validation pending

## Evidence basis

This register is based on read-only source, route, module-name and environment-key-name inspection.

No secret values, customer data, private payloads or live integration requests were used.

| Integration | Current verified evidence | Authority and direction | Required safe test environment | Status |
|---|---|---|---|---|
| WooCommerce / saverbro.com | `modules_statuses.json` reports WooCommerce enabled and a product-sync toggle route is registered. No local `Modules/Woocommerce` directory or WooCommerce environment-key name was found. | Product ownership, variation mapping, stock direction and order authority require management and technical confirmation. | Dedicated WooCommerce test store or network-blocked rehearsal. | BLOCKED - implementation and runtime configuration unverified |
| SAVERPOS customer projection | Versioned `customer-projection/v1` source routes exist with token and throttle middleware. Recommerce route registration is controlled by the main activation gate. | SAVERPOS is authoritative for approved public device information. | Isolated website consumer using a non-production token. | Disabled locally; runtime testing pending |
| Website trade-in command | Versioned `trade-in/v2` source routes exist for catalogue, valuation, intake, projection and decisions. Activation and write controls are off. | SAVERPOS remains authoritative for approval, native records and completion. | Isolated website and POS rehearsal using synthetic records. | Disabled locally; runtime testing pending |
| Trade-in outbox | Source configuration includes destination, authentication, host allowlist, timeout and replay controls. Outbox delivery defaults off. | SAVERPOS may publish allowlisted status information only. | Local capture service or blocked staging destination. | Not run |
| n8n / Discord | No matching environment-key name or registered application route was found in the local inventory. Current workflow configuration was not inspected. | Notification and automation only; must not become transaction authority. | Disabled credentials and a private test destination. | Evidence pending |
| TikTok settlement | No matching local integration route or environment-key name was found. Existing settlement work is handled separately using reconciled records. | SAVERPOS transaction and payment records remain authoritative. | Sanitized settlement fixtures only. | Compatibility review pending |
| MyFatoorah | Checkout, callback, process and webhook routes plus configuration key names are present. Laravel bootstrap produced a certificate-issuer warning. PHP reports `curl.cainfo=EMPTY` and `openssl.cafile=EMPTY`. | SAVERPOS and the payment-provider contract define payment authority. | Provider sandbox after local CA trust is configured and reviewed. | BLOCKED - CA configuration and sandbox proof required |
| PesaPal | Callback/IPN routes and configuration key names are present. Activation and runtime success are unverified. | SAVERPOS and the payment-provider contract define payment authority. | Provider sandbox only; never make a real charge. | Assessment pending |
| PayPal / Paystack / Razorpay / Stripe | Configuration key names and application support were found. The route inventory does not prove that any provider is enabled. | SAVERPOS and the applicable payment-provider contract define payment authority. | Provider sandbox only; never make a real charge. | Assessment pending |
| Mail / AWS / queue processing | Related configuration key names exist. Destinations, credentials, workers and runtime status were not inspected. | SAVERPOS owns queued business records; external systems receive approved outputs only. | Isolated queue with redirected mail and storage destinations. | Assessment pending |

## Confirmed safety state

- `RECOMMERCE_ENABLED=false`
- `RECOMMERCE_WRITES_ENABLED=false`
- Recommerce HTTP routes are not currently registered.
- The local working tree was clean at assessment time.
- No live integration request was deliberately sent.
- No production database, staging branch or live POS was changed.

## Decisions required

- Confirm the real WooCommerce module location and production owner.
- Define product, variation, stock and order authority between SAVERPOS and WooCommerce.
- Identify every integration actually enabled in production.
- Approve non-production credentials and test destinations.
- Assign an owner to repair and verify PHP certificate trust.
- Confirm technical, finance and business reviewers.

## Rules

- Never store passwords, tokens, secrets or customer payloads in this register.
- Never test payment providers using real charges.
- Never send test notifications to live customers or public channels.
- Every write integration must prove authentication, authorization, idempotency, retry limits and audit logging.
- Source presence or route registration alone does not prove runtime readiness.
