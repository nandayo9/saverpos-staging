# AI Handoff

Current milestone: Recommerce — tracked transfer exception workflow
Last completed task: Landed RC-039 (warranty coverage and claim jobs) as commit `7d5adbc`, holding the blocked RC-041 lines out of the shared config and route files
Last commit: `2a4d24e` on `staging`. Ten commits are committed locally and **still unpushed** — the push cannot authenticate from an agent session; see "Push status" below to `https://github.com/nandayo9/saverpos-staging.git`. NOTE: the working tree is still dirty by design — the RC-039 warranty work and the blocked, undocumented RC-041 legacy repair archive remain uncommitted and were deliberately left untouched (see "Incoming-agent verification" below).
Tests passing: **167 tests / 1056 assertions, all green** — verified 2026-08-30 (154/1013 inherited; +3 gate-invariant, +4 deny-by-default, +2 warranty-route, +2 warranty-boundary and +1 warranty-determinism test added this session; the previously recorded "150 / 981" was stale). `recommerce-static-check` passes.
Known failures: none in the focused/full PHPUnit or static checks
Browser evidence: fresh disposable MySQL fixture; rendered browser flow passed for receive, pending/completed A→B transfer, Branch B POS sale, exact-device customer return, Branch B reconciliation (`PASS · core 1 · tracked 1 · legacy 0`), complete Device timeline, and RC-037 receiving exceptions (`MISSING` + `EXTRA` recorded, one manager resolution)
P0/P1 issues: P0 closure passed; partial-return exact-device semantics and RC-037 receiving exceptions are defined and covered
Blocked tasks: RC-038 trade-in (needs acquisition-accounting decision); RC-022 camera scan (asset/dependency decision + real hardware matrix); RC-040+ ops/data tasks need approved environments/data
Hardware preflight: macOS exposes enabled printers `HP_Deskjet_2520_series` and `HP_DeskJet_2600_series` (default); no scanner/USB device was visible. This is inventory only, not physical validation.
Next safe task: confirm the target printer and scanner/browser matrix, then run physical label print and keyboard-wedge scan checks; camera scanning remains blocked on the dependency decision
Files/areas currently sensitive: `app/Http/Controllers/SellPosController.php` (single delete hook), `app/Http/Controllers/StockTransferController.php` (transfer seam), `Modules/Recommerce/**`, `.env`, `scripts/*demo-runtime*` (disposable demo DB only — never production)
Architecture decisions required: acquisition accounting (RC-038), camera-scan dependency sourcing (RC-022), notification channel (RC-043)
Hosting prep: iCore cPanel now has `pos.kkcctv.com.my` mapped to the cloned `staging` repository's `public/` directory, PHP 8.2 enabled account-wide, staging MySQL database/user created, and Let's Encrypt SSL installed. cPanel Git pull is verified at commit `bd8f49f`. The repository now contains a cPanel Git deployment task (`.cpanel.yml` plus `scripts/cpanel-staging-deploy.sh`) so shell/Terminal support is not required: after the untracked server `.env` is created, **Update from Remote** then **Deploy HEAD Commit** installs dependencies, creates a one-time key, migrates, and conditionally seeds the fictional fixture. No cPanel credentials or database password are present in this checkout.

## Incoming-agent verification (2026-08-29) — STOPPED, no code changed

Verified the reported state against the source. Two corrections and one blocker:

1. **Handoff commit pointer was stale.** HEAD is `4e68994`, not `5776c03`. Corrected above.
2. **Uncommitted work is present and partly undocumented.** RC-039 (warranty claims) is uncommitted but recorded in `RECOMMERCE_TASKS.md`; its authorization is correct (`WarrantyClaimService::assertClaimAccess` uses `AuthorizationGate::allowsWriteLocation`). An entire RC-041 legacy repair archive feature is untracked and recorded nowhere: `2026_08_30_000026/000027` migrations, `Entities/RepairArchive.php`, `Services/LegacyRepairArchiveService.php`, `Http/Controllers/LegacyRepairArchiveController.php`, the `recommerce.repair.archive` permission, the `POST /recommerce/repair/legacy-archive` route, and `tests/Feature/RecommerceLegacyRepairArchiveTest.php`.
3. **BLOCKER — RC-041 must not be landed as written.** Three independent reasons, detailed below.

### Why RC-041 is blocked

**a. It contradicts the governing RCR-001 disposition.** `RCR_001_BASELINE_REPORT.md` §5 records the Repair disposition as **UNAVAILABLE / INSUFFICIENT EVIDENCE** and states verbatim: "No Repair source, route, migration, permission, version, attachment, or production-data migration decision should be implemented under RCR-001." The uncommitted work implements a Repair route, two migrations, and a permission, and reads `transactions.sub_type='repair'`. RC-041's own Scope is "Implement the RC-001 disposition" — that disposition has not been made.

**b. The archive cannot satisfy RC-041's acceptance criteria in this checkout.** `Modules/` contains only `Recommerce`; there is no `Modules/Repair` source, and `modules_statuses.json` has `"Repair": false`. `LegacyRepairArchiveService` therefore snapshots only the POS `transactions` row. RC-041 requires "status/financial/attachment sampling" and "every in-scope historical job accounted for"; job state, devices/serials, quotes, parts, technicians, warranties and attachments live in Repair module tables that are absent here. Archiving the transaction shell alone would create a false record of completeness. RCR-001 §8 items 1–4 (licensed Repair source, provenance, sanitized snapshot, production counts) remain outstanding human/deployment actions.

**c. Authorization defect in the uncommitted code (would be a privilege escalation if landed).** `LegacyRepairArchiveService::assertArchiveAccess()` injects `AuthorizationGate` but never calls it. It checks only that the permission *string is listed in `config('recommerce.permissions')`* — a static config constant — plus `CohortPolicy::allowsBusiness()`. It never calls `$user->can('recommerce.repair.archive')`. Every sibling service (WarrantyClaim, RepairQuote, RepairPart, CustomerRepairDevice, DeviceLifecycle, ScanTokenIssuance, …) goes through `AuthorizationGate::allowsWrite*`, which does check `$user->can()`. The route carries only `auth` + `throttle:20,1` — no `can:` middleware. Net effect: **any authenticated user in the cohort business could archive every repair transaction in the business.**
   - The existing test does not catch this. `test_a_missing_archive_permission_is_denied` empties `recommerce.permissions`, and the test double's `can()` is defined as `in_array($ability, config('recommerce.permissions'))` — so the config check and the user check are indistinguishable in the test. A user with the permission *configured* but *not granted* is never exercised.
   - Secondary: `?CohortPolicy $cohortPolicy = null` is nullable but dereferenced without a null check; the service also ignores per-location scoping (it sweeps every location in the business).

### Decisions required before RC-041 resumes

- The RCR-001 Repair disposition itself: mapped import with source keys, vs. authorized read-only deep link/archive, vs. defer. This needs the licensed `Modules/Repair` source and a sanitized production snapshot (RCR-001 §8.1–8.4).
- Whether the uncommitted RC-041 code should be reverted, held on a feature branch, or reworked once the disposition exists.
- If it is ever landed: the authorization path must go through `AuthorizationGate`, and the permission test must separate "not configured" from "not granted".

### Environment note for the next agent

PHPUnit could not be run this session. The device workspace is an Ubuntu 22.04 VM with **no PHP** and **no network egress** (proxy returns 403), and the cloud container has PHP 8.4 against a Laravel 9 lockfile. The node-based `scripts/recommerce-static-check.mjs` was run and **passes**. The "150 tests / 981 assertions" figure above is therefore **unverified this session** — it is the previous agent's claim, not a reproduced result.

Next safe task: unchanged from above (printer/scanner matrix), plus resolving the RC-041 disposition question. RC-041 remains blocked.

## RC-006 authorization regression sweep (2026-08-29) — PASSED for committed code

Ran after the RC-041 finding, to test whether the missing-`$user->can()` defect was systemic. It is not.

**Scope:** all 51 Recommerce endpoints in `Modules/Recommerce/Routes/web.php`, their 18 controllers and 23 services.

**Result:**

- **49 authenticated endpoints** all sit inside the `Route::middleware('auth')` group and reach `AuthorizationGate::allowsRead/allowsWrite/allowsWriteLocation` — which is the only code path that calls `$user->can()` — either in the controller or in the service it delegates to. No route relies on `can:` middleware (there is none in the file), so the gate is the single enforcement point and it is consistently used.
- **`LegacyRepairArchiveService` is the only outlier in the entire module** (`gate=0`, config-only check). It is uncommitted; committed code is clean. This confirms the RC-041 defect is a one-off in unlanded work, not an erosion of RC-006.
- Services with no gate calls were checked individually and are legitimately non-authorizing: `LabelRenderer`, `DeviceEventRecorder`, `DeviceEventTimelineService`, `DiagnosticTemplateService`, `RepairJobTransitionService`, `UltimatePosPurchaseWriter`, `UltimatePosStockAdjustmentWriter` (helpers/writers whose callers authorize), and `RepairPublicLookupService` (public by design). `LabelController` and `DeviceCertificationController` carry no gate call themselves but delegate to `ScanTokenIssuanceService` / `DeviceCertificationService`, and additionally scope the device by `business_id`.
- **2 public endpoints** (`/s/d/{token}`, `/recommerce/repair/status/{jobCode}/{token}`) reviewed and sound: 64-hex opaque token enforced by route constraint *and* re-validated in the service, hashed lookup with the raw token never persisted, throttled (60/min and 30/min), `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`. `ScanController@device` escalates to the internal device page only after `allowsRead` plus `User::can_access_this_location`. Public payloads are minimal (job code, state, due date, customer-facing update, category/brand/model).

**One defect found and fixed:** `DataController::user_permissions()` — which feeds Ultimate POS's native role editor — had no human label for `recommerce.warranty.manage` (added by the uncommitted RC-039 work). It falls back to `$labels[$permission] ?? $permission`, so the permission was assignable but displayed to admins as the raw string. Added the label `Manage repair warranty claims`. No label was added for `recommerce.repair.archive`, because RC-041 is blocked and that permission should not exist yet.

**Test-coverage gap (not fixed, deliberate):** `RecommerceBoundaryTest::test_native_role_editor_metadata_exposes_every_catalogued_recommerce_permission` stubs `recommerce.permissions` with its own 3-item list, so it can never detect real config/label drift. A parity assertion over the actual config would catch this class of bug — but it would fail today on the unlabelled `recommerce.repair.archive`. **Add that parity test once the RC-041 disposition is settled** (it will pass as soon as the archive permission is either reverted or properly labelled). Left unwritten rather than committing a red test.

## Test baseline verified (2026-08-29) — and the bypass reproduced

**The suite now runs again, and it is green: `OK (154 tests, 1013 assertions)`** — full suite, including the uncommitted RC-039 warranty tests, the uncommitted RC-041 archive tests, and the `DataController` label fix. The previously recorded 150/981 was stale by 4 tests / 32 assertions.

### How it was run (reproducible without the Mac)

The previous baseline depended on `/opt/homebrew/bin/php` on macOS, which is not reachable from an agent session. Two dead ends worth recording so nobody re-walks them: the desktop Linux workspace has **no PHP and no root** (`sudo` is blocked by `no_new_privs`), and its apt sources point at `ports.ubuntu.com`, which egress policy **403s** (only `archive.ubuntu.com`, the amd64 archive, is allowed — useless on this arm64 host). Packagist is 403 from both sides, so `composer install` is not an option either.

What does work — the whole suite in the cloud container, in about a minute:

1. On the device: `tar -czf ./_bundle.tar.gz --exclude=.git --exclude=public/uploads vendor app config database Modules resources routes tests bootstrap lang composer.json composer.lock phpunit.xml artisan .env.example` (79 MB; `vendor/` must be included because Packagist is blocked).
2. Stage that single file into the container and extract it, then **delete the tarball from the repo folder** (it was removed; do not commit it).
3. In the container: `mkdir -p storage/framework/{views,cache/data,sessions,testing} storage/logs storage/app/public bootstrap/cache && chmod -R 777 storage bootstrap/cache` — `storage/` is excluded from the bundle, and without it every test errors with "Please provide a valid cache path".
4. `cp .env.example .env && php artisan key:generate --force` — without `APP_KEY`, `StrongIdentifierHasher` throws `RuntimeException: Application key is required for identifier hashing` and 2 boundary tests fail misleadingly.
5. `php vendor/bin/phpunit --no-coverage`.

**Caveat:** the container runs **PHP 8.4**, not the 8.2 of the Mac and the cPanel staging target. Laravel 9 emits many `Implicitly marking parameter ... as nullable` deprecations there, but **zero failures or errors**. Treat the result as a strong regression signal, not as platform certification for 8.2.

### The RC-041 bypass is confirmed by execution, not just by reading

A throwaway probe (run in the container, never added to this repo) constructed a user whose `can()` returns `false` for everything — the realistic case of a role that was never granted `recommerce.repair.archive` — while leaving the permission catalogued in `recommerce.permissions` exactly as `Config/config.php` has it. Result:

> `BYPASS CONFIRMED: unpermitted user archived 2 repair transaction(s).`

`LegacyRepairArchiveService::archive()` ran to completion and wrote 2 archive rows. This is the concrete form of the defect described above.

**The most important thing about that result: the full suite is green at the same time.** 154 passing tests do not detect a live authorization bypass, because the only permission test in that file defines its user's `can()` as `in_array($ability, config('recommerce.permissions'))` — the same condition the buggy service checks. Any future permission test in this module must use a user whose `can()` is independent of the config catalogue, or it proves nothing.

## Authorization gate invariants pinned (2026-08-29)

Added 3 tests to `tests/Unit/RecommerceBoundaryTest.php`. Suite: **157 tests / 1023 assertions, green**.

`AuthorizationGate` is the single enforcement point for all 49 authenticated Recommerce endpoints, and it decides on two independent facts: the permission is **catalogued** in `recommerce.permissions`, and the permission is **granted** to the user (`$user->can()`). The existing coverage tested a catalogued+granted permission against an uncatalogued+ungranted one — so it never separated the two facts, and the whole "catalogued but not granted" quadrant was untested. That is precisely the quadrant RC-041 falls into.

- `test_catalogued_permission_is_not_granted_permission` — a user whose `can()` returns false is denied read, write, and location-write even though the permission is catalogued.
- `test_granted_permission_is_still_refused_when_not_catalogued` — the mirror: a user that `can()` everything is still refused an uncatalogued permission, so no ad-hoc permission string can widen the module's surface without appearing in config.
- `test_granted_and_catalogued_permission_is_still_cohort_scoped` — with both facts true, cohort business/location/variation scope is still applied.

**These were mutation-checked, not just observed green.** Removing the `$user->can($permission) === true` clause from `AuthorizationGate::hasPermission()` — the exact defect shape RC-041 exhibits — makes `test_catalogued_permission_is_not_granted_permission` fail. Under that same mutation the other 42 unit tests all still passed, which independently confirms the previous suite was blind to this class of bug. The gate was restored and the suite re-run green.

Note what this does and does not do: it protects every service that *routes through* the gate. It cannot protect a service that bypasses the gate entirely, which is what `LegacyRepairArchiveService` does. Catching that needs the structural guard test below.

### Test debt still owed (both currently blocked by RC-041)

1. **Config/label parity** — assert every entry in `recommerce.permissions` has a human label in `DataController::user_permissions()`. Would fail today on the unlabelled `recommerce.repair.archive`.
2. **Structural gate guard** — assert that no service in `Modules/Recommerce/Services` makes a permission decision without referencing `AuthorizationGate` (e.g. no service reads `config('recommerce.permissions')` for an access decision on its own). This is the test that would have caught RC-041 at authoring time. Would fail today on `LegacyRepairArchiveService`.

Both become green the moment the RC-041 disposition is settled in either direction — reverted, or reworked onto the gate. Neither was committed red.

## Mutation sweep of the cohort/gate guards (2026-08-29)

Rather than assume the rest of the boundary was covered, ten targeted mutations were applied to the two security-critical classes (`CohortPolicy`, `AuthorizationGate`), running the full suite against each. A mutant that *survives* marks an invariant nothing tests.

**First pass: 8 killed, 2 survived.** Both survivors were deny-by-default promises:

- `matchesConfiguredId()` made to return true for unconfigured/blank ids — survived, suite fully green. This is the "unset `RECOMMERCE_COHORT_BUSINESS_ID` reads as matches-anything" failure, exactly the shape that would open a fresh or misconfigured deployment.
- `variationIsConfigured()` made to return true for an empty `variation_ids` list — survived. An unconfigured variation scope would read as an open one.

`CohortPolicy`'s own docblock promises "empty or incomplete cohort configuration always denies access", and RC-006 requires deny-by-default. The current code is correct on both counts; nothing was verifying it, so a refactor could have flipped either silently with a green suite.

Four tests were added to close this (`test_unconfigured_cohort_denies_every_scope`, `test_blank_cohort_configuration_denies_every_scope`, `test_missing_subject_id_is_denied_against_a_configured_cohort`, `test_empty_variation_list_denies_variation_scope_but_not_location_scope`). The third also pins the reverse direction: a null or blank *subject* id passed against a fully configured cohort must deny, so a missing tenant id can never act as a wildcard.

**Second pass: 10 killed, 0 survived.** Suite green at 161 tests / 1038 assertions.

The mutation harness is not committed — it is ~60 lines of Python that copies each class aside, applies one string substitution, runs PHPUnit, records KILLED/SURVIVED and restores. Worth re-running whenever `CohortPolicy` or `AuthorizationGate` changes; it is the cheapest available check that the module's deny-by-default contract is still actually enforced rather than merely intended.

Not covered by this sweep, and still the open risk: a service that bypasses `AuthorizationGate` altogether. Mutation testing the gate cannot detect a caller that never asks it. That is `LegacyRepairArchiveService`, and it needs the structural guard test listed above.

## Repair UI review and fixes (2026-08-30)

The two repair Blade views carried uncommitted, unreviewed UI edits. They were rendered with real stub data against the deployment's own `vendor.css`/`app.css` and screenshotted in Chromium at 1440px and 390px. That found a regression the whole test suite could not see.

**Layout regression (introduced by the uncommitted edit, now fixed).** `new.blade.php` had 43 opening `<div>` tags and 44 closing ones; HEAD was balanced at 43/43. The edit removed a `<div class="well well-sm">` wrapper around the device-lookup block and left its `</div>` behind. The surplus tag closed the outer `.row` early, so the `col-md-5` column holding Work plan, Pre-repair checklist and the submit buttons fell outside the grid entirely and rendered as a narrow left column with ~45% of the page empty. The fix reinstates the wrapper as `<div class="sb-search">`, which rebalances the document and simultaneously activates the `.sb-search` rules the same edit had added without ever applying them.

**Status and priority now carry meaning.** Every state previously rendered `label label-primary`; RECEIVED, IN PROGRESS, READY and AWAITING PARTS were the same blue pill, and priority was undifferentiated plain text. There is now a state-to-tone map (intake / active / blocked / done / closed) with colour pairs chosen for AA contrast rather than inherited from Bootstrap defaults (`label-warning` is white-on-orange and fails). Overdue and due-today jobs get red/amber dates with a flag, excluding terminal states so a finished job never nags.

**Summary counters are operational.** `with due date` — a number nobody acts on — was replaced by `overdue` and `due today`, which turn red and amber when non-zero.

**Mobile.** Rows stack into labelled cards, so Status, Priority and Due stay on screen instead of scrolling out of a 390px viewport; previously only Repair code, Customer and part of Device were reachable. The action buttons no longer render above the page heading (the earlier `float:none` rule exposed source order); flex ordering keeps title, subtitle, actions.

**Dead CSS eliminated.** The uncommitted edit had added rules that no markup used: `.empty-state`, `.actions` (index) and `.sb-search`, `.card-lead`, `.sb-required` (new). All five now style their evident targets — including a real empty state replacing the bare muted table row, and `.sb-required` replacing nine `text-danger` asterisks. An audit of all 16 Recommerce views found no other div imbalance and no other dead CSS.

**Removed:** the permanent green "Counter intake is ready" banner, which was onboarding copy occupying the most prominent slot on every page load. The read-only notice still shows when the write gate is closed. Restore it if the cohort still needs the reminder.

**Not fixable in markup:** the due-date field renders `mm/dd/yyyy` because `<input type="date">` follows browser locale; the rest of the UI uses `d M Y`. Forcing the format needs a JS datepicker — a product decision, not a tidy-up.

~~**Left alone:** `parts/show.blade.php`...~~ **DONE (2026-08-30)** — rendered, screenshotted and fixed; see below.

Verification: full suite green (161 tests / 1038 assertions), `recommerce-static-check` passes, no page-level horizontal overflow and no JS console errors at either width.

## RC-039 warranty claim review (2026-08-30) — one confirmed defect, fixed

RC-039 is uncommitted but unblocked (its disposition is not in question, unlike RC-041), so it was reviewed before it lands. Its authorization is correct — `WarrantyClaimService::assertClaimAccess` goes through `AuthorizationGate::allowsWriteLocation` and the controller re-checks scope with `User::can_access_this_location`. One real defect was found, reproduced, and fixed.

**`WarrantyClaimController` referenced two exception classes it never imported.** `AuthorizationException` and `ValidationException` were both used — thrown and caught — but absent from the `use` block, so each resolved to `Modules\Recommerce\Http\Controllers\<Name>`, which does not exist. `LegacyRepairArchiveController` imports both correctly; this file did not. Reproduced through the real route:

- **A denied caller received HTTP 500**, not the intended 404: `Error: Class "Modules\Recommerce\Http\Controllers\AuthorizationException" not found` at `WarrantyClaimController.php:67`, because `scopedJob()` throws that unqualified name.
- **Invalid input leaked Laravel's field-level validation payload** (`{"errors":{"command_uuid":[...]}}`) instead of the masked `A warranty claim could not be created from this job.`, because the `catch (ValidationException|LogicException ...)` clause could never match.

Fixed by adding the two imports (and using the already-imported `RepairJob` instead of an inline fully-qualified name). After the fix the same probe returns 404 and the masked 422.

**Why it shipped:** the only HTTP test for this route asserted the happy path. Two regression tests now cover the failure paths — `test_route_denies_an_unpermitted_user_with_not_found` and `test_route_masks_invalid_input` (which also asserts no `errors` key is exposed). Both were mutation-checked: removing the two imports again fails exactly those two tests and nothing else.

**Reviewed and found sound:** command-uuid idempotency (guarded by a `lockForUpdate` on the business row plus a business-scoped `command_uuid` lookup), tenant scoping on the sale and warranty lookups, and the not-covered decision branches.

**Noted, not changed** — worth a decision rather than a silent edit:

1. ~~**Same-day claims may be rejected.**~~ **CONFIRMED AND FIXED (2026-08-30).** See the section below. The earlier note that "the end boundary has the mirror issue" was wrong and is retracted.
2. ~~**Money is handled in floats.**~~ **RETRACTED (2026-08-30)** — tested and did not reproduce; see below.
3. ~~**`saleWarranty()` takes the first matching sell line**~~ **CONFIRMED AND MADE DETERMINISTIC (2026-08-30)** — see below.

## Warranty coverage start boundary — confirmed bug, fixed (2026-08-30)

The same-day rejection hypothesised in the RC-039 review was tested rather than assumed, and it reproduces.

A first probe appeared to disprove it, but the probe was wrong: it updated transaction `501` while the fixture job's `source_id` is `9001`, so the service never read the row under test. Retargeted at `9001`:

- sale `2026-07-30 14:30`, `claimed_on` `2026-07-30` → **NOT_COVERED**, "The claimed_on date is outside the recorded warranty term."
- sale `2026-03-02 09:00`, claim on the term's final day → IN_COVERAGE.

So the start boundary was strict and the **end boundary is lenient, not mirrored** — a date-only `claimed_on` parses to midnight, which is at or before any same-day end timestamp. The earlier handoff note claiming a mirror issue was wrong and has been retracted above.

Customer impact: a device sold and brought back the same day for a warranty repair was refused coverage.

**Fix:** compare `$claimedOn` against `$start->copy()->startOfDay()` rather than the raw sale timestamp. A first attempt set `$start = ...->startOfDay()` outright, which was withdrawn — that also changed the recorded `coverage_start_at` evidence and shifted the derived end date up to a day earlier. The committed form leaves `$start` exact, so stored policy evidence and the computed term end are untouched; only the comparison is day-granular.

Two regression tests: `test_a_claim_on_the_day_of_sale_is_covered` (which also asserts `coverage_start_at` still records the exact sale timestamp) and `test_a_claim_before_the_sale_day_is_not_covered`, so the fix cannot widen into accepting genuinely pre-sale claims. Mutation-checked: restoring the raw-timestamp comparison fails the first and nothing else.

**Still open from that review, unverified and untouched:** float money handling, and `saleWarranty()` selecting the first matching sell line with no ordering. Neither has been reproduced; treat both as hypotheses, not findings, until they are.

Like the controller import fix, this lives in still-untracked RC-039 files and travels with that work whenever it lands.

## The two remaining RC-039 hypotheses, tested (2026-08-30)

Both were recorded last session as hypotheses rather than findings. Both have now been probed.

**Float money — RETRACTED, no defect.** Covered and chargeable were reconciled against the sale total across five cases: `100.0000`, `100.0500`, `0.0300`, `99999999.9999` and `12345678901234.5678`. The delta was exactly `0.0000000000` in every case. This holds by construction: `covered` is clamped to `[0, total]` and `chargeable` is `total - covered`, so the pair sums to the total whatever the rounding. There is a genuine but purely theoretical precision limit — a total beyond about 15 significant digits does not survive the `(float)` cast intact (`12345678901234.5678` becomes `…568359`) — which is far outside any real currency amount and is not a reconciliation defect. The earlier concern was wrong and is withdrawn.

**Warranty line selection — CONFIRMED, now deterministic.** `saleWarranty()` queried `transaction_sell_lines` with no `ORDER BY`, so with two lines on one sale for the same variation the database was free to return either policy. Probed with a 6-month and a 24-month warranty on one sale: the 6-month policy was returned, but nothing in the query guaranteed it, so the recorded coverage term could differ between runs, engines or query plans — on a table whose whole purpose is durable policy evidence.

Fixed by ordering on `transaction_sell_lines.id`, which locks in the behaviour that already happened in practice rather than choosing a new winner. `test_warranty_selection_is_deterministic_across_duplicate_sell_lines` calls `decision()` twice and asserts both agree and resolve to the earliest line.

**Still a product decision, deliberately not made:** *which* line should supply the policy when a sale carries several for one variation — earliest, latest, longest term, or most favourable to the customer. The fix removes irreproducibility; it does not answer that question, and the test says so in its docblock.

Both changes live in the still-untracked RC-039 files and travel with that work.

## parts/show status legibility (2026-08-30) — the view contradicted its own legend

The last outstanding item from the UI review. `parts/show.blade.php` was rendered with stub data and screenshotted before changing it, same pipeline as the repair views.

The defect was sharper than "status is monochrome". The view carries a **"Parts boundary" legend** that teaches the reader a colour vocabulary — blue *Held*, amber *Pending*, green *Audited* — while the data rows beside it rendered every reservation as `label-default` (grey) and every usage as `label-info` (blue) regardless of state. The legend promised meaning the data never delivered, so a technician reading the legend and then the rows would be misled rather than merely uninformed.

Reservation states are `RESERVED / ISSUED / RELEASED / CONSUMED` and usage states `INSTALLED_PENDING_BILLING / CONSUMED` (read from `RepairPartService`, not guessed). Mapped onto the same tone system as the repair list: RESERVED→intake, ISSUED→active, INSTALLED_PENDING_BILLING→blocked, CONSUMED→done, RELEASED→closed. **The legend itself was converted to the same classes**, so both halves of the screen now speak one language, and the mapping matches what the legend already said.

The tone CSS is duplicated into this view's own `<style>`, scoped under `#recommerce-parts`, because each Recommerce view carries its own inline styles. ~~**That duplication is now in two places...**~~ **DONE (2026-08-30)** — extracted to `recommerce::partials.status-tones`; see below.

Observed while rendering, pre-existing and not changed: the stock dropdown prints raw 4-decimal quantities ("4.0000 available"), and currency placement is inconsistent between the projected invoice ("RM 85.00") and recorded costs ("85.00 RM"). Also `@forelse` at line 111 is closed with `@endforeach` and has no `@empty` branch — harmless, since the surrounding `@if` handles the empty case and the compiled PHP is valid, but it should be a plain `@foreach`.

Suite green at 166 tests / 1053 assertions; static check passes; div balance 19/19; no hardcoded status labels remain in the view.

## RC-039 landed (2026-08-30) — commit `7d5adbc`

Committed after review. Everything found during that review is folded in: the controller's missing `AuthorizationException`/`ValidationException` imports (500 instead of 404, and leaked validation detail), the same-day coverage boundary, deterministic warranty-line selection, and two table columns the service never assigned.

**Columns fixed at commit time.** `claimed_on` and `policy_name` are dedicated columns on `recommerce_warranty_claims` that the service only ever wrote into the JSON evidence, so every row stored NULL and reporting could not filter by claim date or policy without unpacking JSON. Both are now persisted, covered by `test_claim_persists_queryable_date_and_policy_columns` and mutation-checked.

**`policy_version` is deliberately left NULL.** The snapshot hardcodes `version_number => 1`, so writing that column would record a version that is not real. RC-039's objective calls for *versioned* policy evidence; what exists is a snapshot with a constant version. **Policy versioning is nominal, not implemented** — that gap is unresolved and should be decided before anyone relies on the version field.

### How the RC-041 entanglement was handled

`Modules/Recommerce/Config/config.php` and `Modules/Recommerce/Routes/web.php` carried both RC-039 and blocked RC-041 lines, so committing either file wholesale would have silently landed the archive permission and route. Before staging, the RC-041 permission (`recommerce.repair.archive`) and the `POST /repair/legacy-archive` route were stripped, RC-039 was verified to stand alone (its 12 tests and the 47 unit tests pass with RC-041 absent), the staged diff of both files was inspected to confirm only warranty lines were present, and the RC-041 lines were restored to the working tree immediately after the commit.

The working tree is now exactly RC-041 and nothing else: two modified lines in the shared files plus six untracked files. **RC-041 remains blocked on the RCR-001 disposition and the authorization bypass documented above — nothing in `7d5adbc` depends on or enables it.**

## Status tones extracted to a shared partial (2026-08-30)

The tone system had been copied into two views with different scoping (`.sb-repair-list .sb-status*` in `repair/index`, `#recommerce-parts .sb-status*` in `parts/show`). It is now one file, `Modules/Recommerce/Resources/views/partials/status-tones.blade.php`, included by both with `@include('recommerce::partials.status-tones')`. The partial documents what each tone means (intake / active / blocked / done / closed) so a third view maps its own states onto the vocabulary instead of inventing colours.

Verified as a pure extraction rather than assumed: both views were rendered before and after, and the body markup compares byte-identical while all five tone colour pairs are preserved.

**One deliberate visual change, not a no-op:** `parts/show` previously used `padding:3px 9px` on its pills against `repair/index`'s `4px 10px`. The shared rule is `4px 10px`, so the parts pills are 1px larger each way — the point of the extraction being that the two screens now agree. Re-screenshotted to confirm the view still reads correctly.

Suite green at 167 tests / 1056 assertions; static check passes.

## Push status (2026-08-30) — blocked on credentials, not on the work

`git push origin staging` fails from the desktop Linux workspace:

```
fatal: could not read Username for 'https://github.com': No such device or address
```

No credential helper is configured in the repo or globally there, and no token is present in the environment. The previous session's push came from macOS, where the credential lives in the keychain — which `device_bash` cannot reach, since it runs in an isolated Linux VM rather than on macOS itself. **This is an environment limit, not a repository problem, and no attempt was made to extract or solicit a credential.**

State is a clean fast-forward and ready to go: local `2a4d24e`, `origin/staging` still at `4e68994`, ten commits ahead, zero behind. To push, run on the Mac:

```
cd ~/Downloads/UltimatePOS-V7.3/UltimatePOS-CodeBase-V7.3 && git push origin staging
```

**Note for whoever does:** the cPanel staging deployment pulls from this remote, so pushing changes what **Update from Remote** would bring to `pos.kkcctv.com.my`. Deployment still requires the manual cPanel steps; pushing alone deploys nothing.

### Repository visibility

While checking the remote, `git ls-remote` succeeded **anonymously** — the GitHub repository `nandayo9/saverpos-staging` is **public**, not private. It was audited on that basis and no live secret is exposed: `.env` is untracked and matched by `.gitignore` line 12, only `.env.example` and `.env.cpanel-staging.example` are tracked, and a scan of tracked files for app keys, database passwords, GitHub tokens and AWS keys returned only validation rules and translation strings. Still worth confirming the public setting is intentional for a POS codebase carrying deployment runbooks and the staging hostname.

