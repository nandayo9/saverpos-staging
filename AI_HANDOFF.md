# AI Handoff

Current milestone: Recommerce — tracked transfer exception workflow
Last completed task: Added warranty sale-line coverage evidence and claim lines behind a dedicated Recommerce permission
Last commit: `561f360` (`Pin AuthorizationGate grant/catalogue invariants and record verification`) on `staging`, committed locally and **not yet pushed** to `https://github.com/nandayo9/saverpos-staging.git`. NOTE: the working tree is still dirty by design — the RC-039 warranty work and the blocked, undocumented RC-041 legacy repair archive remain uncommitted and were deliberately left untouched (see "Incoming-agent verification" below).
Tests passing: **157 tests / 1023 assertions, all green** — verified 2026-08-29 (154/1013 before this session added 3 gate-invariant tests; the previously recorded "150 / 981" was stale). `recommerce-static-check` passes.
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

