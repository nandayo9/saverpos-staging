# Website Trade-In integration V2

The former website-triggered `POST /api/trade-in-acquisition/v1/commit` contract is retired. It returned too much authority to the website and is no longer routed.

## Allowed server operations

The staging-only website credential is limited to:

```text
POST /api/trade-in/v2/intakes
GET  /api/trade-in/v2/intakes/{websiteCase}/projection
POST /api/trade-in/v2/intakes/{websiteCase}/decisions
```

All routes require the dedicated bearer middleware, explicit staging enablement, configured service actor, authenticated business, and location/Variation allowlists. Production and incomplete configurations return a neutral 404. The token is not shared with the read-only customer projection and is never returned or persisted.

Intake accepts an immutable customer submission and evidence references. It creates only `recommerce_trade_in_intakes`; it does not create a Product, Variation, Device, purchase, stock movement, accounting entry, or payment. `(business_id, source_system, external_case_reference)` and the submission fingerprint provide durable idempotency.

The sanitized projection is an explicit allowlist containing the POS case reference, monotonic projection version, current approved offer, authoritative native mapping when resolved, committed acquisition references, and native settlement state. It excludes pricing internals, margins, reserves, ceilings, evidence amounts, staff notes, credentials, and unrelated PII.

The decision command must bind the current published offer UUID and version, decision, UUID idempotency key, and—only for acceptance—the native mapping last projected by POS. The amount is never accepted from the caller. SAVERPOS resolves the offer and valuation, checks eligibility, and invokes its existing native `TradeInService::accept()` internally only after the exact customer decision is established.

## Native mapping and Device semantics

`location_id`, `product_id`, `variation_id`, and `device_id` must equal the native valuation and Device relationships. Missing or unequal fields return HTTP 422 with `native_mapping_mismatch`; SAVERPOS never substitutes corrected values. The rejection occurs before native acceptance, so purchase, Device, movement, accounting, payment, and case-transition deltas remain zero.

`device_id` is the already existing customer-owned Device inspected by the native valuation. Website intake does not invent or create it. Acquisition transfers that same Device through the existing atomic lifecycle.

Each mismatch produces a redacted `NATIVE_MAPPING_REJECTED` native timeline event and security log with case, POS case, command ID, field, expected/received IDs, actor, and timestamp. No bearer token, secret, customer details, or filesystem path is recorded.

## Replay and failure recovery

Identical decision replay returns the established decision and acquisition result without a second purchase or Device effect. Reuse of the key with any changed offer, decision, or mapping is a conflict and never returns cached success. Database locks, unique constraints, and the native acceptance transaction protect concurrent completion.

If the website loses a response, it preserves customer acceptance as pending confirmation and retries/reconciles with the same key. It never invents a new acquisition attempt. Native payment state remains separate: a received purchase with `payment_status=due` projects `PENDING`, and only native paid state may project `PAID`.

## Customer projection outbox contract

The standard website Trade-In lifecycle emits one redacted, immutable customer-projection event per authoritative transition, with strictly increasing aggregate versions:

```text
v1 INTAKE_ACKNOWLEDGED
v2 VALUATION_LINKED
v3 APPROVED_OFFER_PUBLISHED
v4 ACQUISITION_COMMITTED
v5 SETTLEMENT_UPDATED
```

`SETTLEMENT_UPDATED` is emitted once by the native Transaction `payment_status` observer while the payment update is transactional. Callers must not invoke `recordSettlementChange()` separately for that same saved Transaction. Redelivery retries the existing immutable outbox row; it never creates another customer-projection transition.

## Known incomplete integration

The durable POS outbox/update-delivery path is implemented for the protected staging integration. Commercial policy calibration remains provisional pending usable historical transaction data; that is a separate production/pilot gate and does not change the customer-projection contract above.

Nothing in this contract authorizes staging or production deployment.
