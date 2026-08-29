# SAVERPOS Walk-In Intelligence V1

## Status

### Implemented

- A physical store visit is a small `walk_ins` record, not a customer or lead record.
- One visit is captured with its business, branch, arrival time, recorder and `OPEN` status.
- A POS conversion can be created only by linking one open visit to one same-branch `transactions` row where `type = sell` and `status = final`.
- A visit can instead be closed as `NO_SALE` with one stable reason code.
- The management page reports visits, converted visits, no-sales, unresolved visits, raw conversion rate, attributed POS sale value and ranked no-sale reasons.
- The native POS screen has a one-tap **WALK-IN** action and an optional selector for today's open visits at its current register branch.
- Conversion is released back to `OPEN` before the existing sale delete path deletes a sale, and when an existing POS sale is changed from `final` to a non-final state.
- Audit events are written through the existing activity log for capture, no-sale closure, conversion and conversion release.

### Partially implemented / operational policy

- A return does not reverse a conversion: the visit did convert at the original completed sale. V1 displays that original final sale's `final_total` as attributed sale value. Existing POS return reports remain the authoritative place for net-return accounting; V1 does not invent a return-adjusted number.
- Open records deliberately remain unresolved until staff selects a no-sale reason or completes an attributed sale. There is no automatic end-of-day conversion or fabricated no-sale reason.
- The dashboard filters by branch and a custom date range, with quick **Today**, **Yesterday**, **Last 7 Days**, and **This Month** controls. The presets keep the selected branch and use the same date-range query as the manual inputs.

### Not implemented

- Online leads, CRM/follow-up, lead profiles, campaign attribution, AI recommendations, people counting, cameras and surveys.
- Historical outcome correction and destructive walk-in deletion. This prevents ordinary users from silently rewriting historical KPIs; any future correction workflow must be explicit and audited.
- Attributed gross-profit reporting. No approximation is shown.

## Architecture and schema

`walk_ins` owns operational visit data. `transactions` remains the sole source for product lines, price, payments, inventory, accounting and sale value.

The association lives on `walk_ins.transaction_id`. It is nullable and unique, enforcing at most one visit per POS transaction. The service also requires an `OPEN` visit, so a visit cannot be linked more than once or be both converted and no-sale. The foreign key is restrictive: the existing sale deletion flow first releases the conversion, preserving an unresolved visit rather than deleting KPI evidence.

Indexes support branch/date, status/date and no-sale-reason/date reporting. No sale values are copied into `walk_ins`.

## Stable no-sale taxonomy

Purchase opportunity: `PRICE_OVER_BUDGET`, `NO_SUITABLE_STOCK`, `SPEC_MODEL_UNAVAILABLE`, `FINANCING_PAYMENT_ISSUE`, `STILL_CONSIDERING`, `COMPARING_ELSEWHERE`.

Non-sales visit: `JUST_BROWSING`, `REPAIR_SERVICE_VISIT`, `COLLECTION_PICKUP`.

Fallback: `OTHER`.

## Permissions

- `walkin.create` — capture a visit at an allowed branch.
- `walkin.close` — close an open visit with a controlled no-sale reason.
- `walkin.assign` — attribute an open visit to a completed same-branch POS sale.
- `walkin.view` — analytics for permitted branches only.
- `walkin.view_all` — all-branch analytics.

Permissions are registered by migration and exposed in the existing role editor. Existing location permissions are checked for every mutation and branch-level report request.

## Verification evidence

The test build uses an in-memory SQLite database in `phpunit.xml`; it no longer depends on the optional local demo database file. Use `composer test:walkin` for the Walk-In slice, or `composer test:all` for the full PHP test suite. In an environment without Composer on `PATH`, run the equivalent `php vendor/bin/phpunit --filter WalkInServiceTest` or `php vendor/bin/phpunit` with the project's PHP binary.

Use `composer check:walkin` for the fast static UI regression guard. It checks route registration, zoom accessibility in the application layouts, labelled Walk-In controls, and the POS capture wiring. It does not replace rendered browser validation.

`tests/Feature/WalkInServiceTest.php` runs on isolated SQLite schema and proves capture attribution, final-sale enforcement, cross-branch rejection, duplicate protection, no-sale validation, conversion-rate/revenue calculations, and invalidation release.

Run `composer preflight:runtime` before launching the local UI. It is read-only and checks that the configured persistent database can be reached and has the minimum core schema for the Walk-In page. A failed preflight does not modify the database; restore or configure a disposable complete local fixture before browser testing.

The local HTTP application could be launched, but its configured SQLite database contains no SAVERPOS schema (`system` table missing). Browser operational testing is therefore **not verified** in this checkout. No production or seeded local business data was created to work around that blocker.
