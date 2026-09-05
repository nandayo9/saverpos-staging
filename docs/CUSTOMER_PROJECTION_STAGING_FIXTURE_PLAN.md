# Customer Projection V1 staging fixture plan

This is a staging-only data-preparation runbook. It creates no WordPress fixture fallback and must never be used against production or with customer/device identifiers.

## Preconditions

1. Apply migration `2026_09_05_000001_add_customer_listing_projection_fields.php` to the approved SAVERPOS staging database.
2. Select an existing synthetic staging business, branch, product and variation. Do not create parallel stock ledgers or use real customer data.
3. Configure the customer-projection cohort to that business, branch and variation; keep `RECOMMERCE_CUSTOMER_PROJECTION_ENABLED=false` until the data review is complete.
4. Use generated UUIDs for `public_device_id`, synthetic `SB-STAGING-PROJECTION-*` device codes, and no serial, IMEI, service tag, supplier, cost or customer values.

## Fixture matrix

Insert or update exact rows in `recommerce_devices` through the approved SAVERPOS staging receiving/device workflow, then set the listed projection fields:

| Case | lifecycle / custody / stock | publication | price | expected public result |
| --- | --- | --- | --- | --- |
| available | `AVAILABLE` / `LOCATION` / `ON_HAND` | `PUBLISHED` | positive MYR price | listed and Passport visible |
| inspection recorded | same as available, plus a real staging inspection `PASSED` row | `PUBLISHED` | positive | listed; inspection shown as recorded |
| evidence absent | same as available, no inspection row | `PUBLISHED` | positive | listed; inspection not recorded |
| draft | same as available | `DRAFT` | positive | never listed |
| no price | same as available | `PUBLISHED` | `NULL` | never listed |
| sold | `SOLD`, `sold_at` set | `PUBLISHED` | positive | never listed; old URL unavailable |
| transit | `IN_TRANSIT` custody/transfer state | `PUBLISHED` | positive | never listed |
| wrong branch | eligible state at an unapproved branch | `PUBLISHED` | positive | never listed |
| unavailable | any non-`AVAILABLE` lifecycle or non-`ON_HAND` stock participation | `PUBLISHED` | positive | never listed |

Every publishable row must have `listing_model_slug`, `listing_specification_id`, and `specifications_json` with the actually-known public brand/model and any CPU/RAM/storage values to be filtered. The listing projection does not synthesize missing evidence.

## Verification and cleanup

After explicit staging authorization and configuration, call the authenticated `/api/customer-projection/v1/listings?per_page=48&sort=newest` endpoint. Confirm only the three available cases are returned and that response JSON contains no `id`, `device_code`, serial, supplier, cost, notes, product ID or variation ID. Change the available row to `SOLD`, call the endpoint again, then confirm it disappears and its public URL returns the neutral unavailable result.

Remove or withdraw all synthetic records through the same staging workflow when the exercise ends. This plan intentionally does not contain a credential, host, production command, or direct WordPress database step.
