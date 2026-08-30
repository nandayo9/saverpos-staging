# AI Handoff

Current milestone: Recommerce — staging Cash flow verified; server-local cPanel Cron deployment is configured to avoid the protected external API
Last completed task: **Configure the server-local staging deploy Cron Job** — cPanel accepted the exact five-minute `scripts/cpanel-staging-poll.sh` command on 2026-08-30. The first Cron-driven deployment remains to be observed.
Latest implementation commits: `263f81b` adds the fast-forward-only Cron polling helper and makes the former GitHub API workflow manual-dispatch diagnostic only; `71cb6fd` ignores the cPanel-generated root `error_log`; `c1679b4` normalizes the cPanel host and fails safely on non-JSON responses. All are pushed to `staging`. The working tree is dirty by design: the untracked files and the two modified shared files are the blocked RC-041 archive and must not be committed.
Tests passing: **305 tests / 1311 assertions, all green** on PHP 8.2.33, zero deprecations, notices, warnings, skipped, incomplete or risky tests. `recommerce-static-check` passes.
Known failures: none in the focused/full PHPUnit or static checks
Browser evidence: all 13 in-app screens rendered from real Blade against the real CSS cascade and audited at 375/768/1280 — 0 below AA, 0 unlabelled controls, 0 light surfaces, 0 horizontal overflow. Staging interaction also passed: currency displayed RM; receipt transaction 8 created `SB-DV-00000019-1`; transfer `CASH-SMOKE-TRANSFER-20260830` completed; Cash sale `INV-0002` posted for RM 1,200.00; the exact device was returned; Branch B reconciliation reported `PASS · core 2 · tracked 2 · legacy 0`. The currency correction was performed through the visible Business Settings UI, so this is live-flow evidence, not proof that the current local commits are deployed.
P0/P1 issues: P0 closure passed; partial-return exact-device semantics and RC-037 receiving exceptions are defined and covered
Blocked tasks: RC-038 trade-in (needs acquisition-accounting decision); RC-022 camera scan (asset/dependency decision + real hardware matrix); RC-040+ ops/data tasks need approved environments/data
Hardware preflight: macOS exposes enabled printers `HP_Deskjet_2520_series` and `HP_DeskJet_2600_series` (default); no scanner/USB device was visible. This is inventory only, not physical validation.
Next safe task: push one documentation-only trigger commit to `staging`, wait for the five-minute Cron interval, and verify the Cron log and served dark stylesheet. The Cron Job fetches and fast-forward merges `origin/staging` from inside the cPanel account, then invokes the existing deployment script only when the branch advances. Do not bypass, disable globally, or automate the JavaScript anti-bot challenge. Do not advance RC-045/RC-046 from this flow evidence alone.
Files/areas currently sensitive: `app/Http/Controllers/SellPosController.php` (single delete hook), `app/Http/Controllers/StockTransferController.php` (transfer seam), `Modules/Recommerce/**`, `.env`, `scripts/*demo-runtime*` (disposable demo DB only — never production)
Architecture decisions required: acquisition accounting (RC-038), camera-scan dependency sourcing (RC-022), notification channel (RC-043)
Hosting prep: iCore cPanel has PHP 8.2, MySQL, and Let's Encrypt SSL. Browser inspection confirmed the managed repository path `/home/kkcctv93/repositories/saverpos-staging-repo`; a manual **Update from Remote** successfully advanced it to `263f81b`. cPanel keeps its GUI deployment action disabled while it sees a generic dirty-check failure, so it is not the normal path. cPanel now confirms the server-local Cron entry `*/5 * * * * /bin/bash /home/kkcctv93/repositories/saverpos-staging-repo/scripts/cpanel-staging-poll.sh >> /home/kkcctv93/repositories/saverpos-staging-repo/storage/logs/cpanel-staging-cron.log 2>&1`. GitHub API-triggered deployment remains unusable: workflow run `33300932113` (four attempts) and a read-only local request receive an HTTP 200 JavaScript "One moment, please…" anti-bot page rather than UAPI JSON. The Cron helper polls the public `staging` branch internally with a lock and fast-forward-only guard. The served `public/css/saverbro-dark-pos.css` hash remains `3c2ab4f7…` while the local published checkout is `798306ec…`; the dark UI is not live until a Cron-driven deployment is verified. No cPanel credentials or database password are present in this checkout.

## START HERE — handover (2026-08-30)

The current status block above supersedes older deployment and interaction notes below. Those entries remain as historical evidence of how the blockers were diagnosed.

Read this section before touching anything. The rest of the file is a reverse-chronological session log; the traps below
are the ones that will cost you time or make you ship something wrong.

### Four things that will mislead you

1. **A push does not deploy.** `.github/workflows/deploy-staging.yml` calls only cPanel's
   `VersionControlDeployment/create`, which deploys the HEAD of the **server's** checkout and never pulls from GitHub.
   Three workflow runs have reported success while shipping stale code. Proof: the dark stylesheet served from
   `pos.kkcctv.com.my` is byte-identical (sha256 `3c2ab4f7…`, 25482 bytes) to its state at `03d49f2`, so the live site
   predates `e69b8dd`. **12 local commits are unpushed, and pushing them would still not reach the site.** Deploying
   needs the operator's manual cPanel *Update from Remote* → *Deploy HEAD Commit*.
2. **The working tree is dirty on purpose.** Six untracked files plus two modified (`Config/config.php`,
   `Routes/web.php`) are the **blocked RC-041** legacy repair archive. Do not commit them — they carry a confirmed
   privilege-escalation defect and contradict the RCR-001 disposition. Every commit in this session staged files
   explicitly for that reason.
3. **`php artisan` is broken in this checkout.** `.env` has `DB_CONNECTION=sqlite` pointing at
   `/private/tmp/saverbro_recommerce_demo.sqlite`, which does not exist, so `route:list`, `migrate` and friends fail.
   The **served** app is fine: `scripts/saverpos-demo-router.php` overrides the connection to MySQL per request. Prefix
   artisan calls with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` or point them at a `saverpos_demo_*` database.
4. **Never trust a source-only assertion about a view.** Two bugs this session were invisible to them: a Blade fatal I
   introduced myself (uncompiled `@endif@if`), and a checklist class the CSS never defined. Render the thing.

### What is verified, and what is not

| | State |
| --- | --- |
| Suite | **305 tests / 1311 assertions green**, PHP 8.2.33, zero deprecations/warnings/skipped/risky |
| Static check | `node scripts/recommerce-static-check.mjs` green |
| Views | All 17 compile-guarded; 13 in-app screens rendered and audited at 375/768/1280 — 0 below AA, 0 unlabelled, 0 light surfaces, 0 overflow |
| **Never exercised** | **Any interaction** — no click, submit, or modal open anywhere |
| **Never seen** | POS chrome (sidebar/navbar); the authenticated app at all |
| Local fixture | `saverpos_demo_p0`: 1 business, 2 branches, 17 devices, 4 customers, **0 repair jobs** |
| Staging | Runs code from before `e69b8dd`; the Cash smoke remains unverified there |

Local app is served by `scripts/serve-saverpos-demo-runtime.sh` on `127.0.0.1:8010`.

### Why interaction is unverified

Logging in means typing a password, which this session does not do. Everything needing a session — the repair flow, the
Quick create modal actually opening, the Cash smoke — is blocked on a human signing in, then handing the browser over.
That is the single highest-value thing the next session can unblock.

### Decisions the operator owes, not you

Do not invent any of these; each would put fabricated business rules into a POS.

- **RC-038** trade-in acquisition accounting · **RC-042** retention periods and secret policy · **RC-043** notification
  channel · **RC-044** metric definitions · **RC-040/045/046** approved environments, data, and real people
- **RC-041** disposition: revert, park on a branch, or rework onto `AuthorizationGate`. Until settled, two owed tests
  stay unwritten (config/label parity; a structural guard that no service decides permissions without the gate) — both
  would go red purely because those untracked files exist.
- **Repository visibility** — `nandayo9/saverpos-staging` is public.
- **The duplicate `logout` route name** blocks `route:cache`; stock POS code, needs someone to choose which route keeps
  the name.
- **`public/js/pos.js:3037`** throws on a partial payment-account map. The seeder fix removed the trigger for the demo
  estate; any location with a partial map still hits it.

### Methods established here — reuse them, don't reinvent

- **Render harness**: `Tests\Fixtures\RendersRecommerceViews` + `tests/Fixtures/views/layouts/app.blade.php`. Needs
  `recommerce.enabled => true` (routes) and a `system` table (a global composer reads settings on every render).
- **Mutation-check every guard.** Several tests written this session passed while proving nothing until mutated; two
  survivors turned out to be findings about the code rather than gaps in the test.
- **Measure contrast, don't eyeball it** — and composite alpha up the ancestor chain. Treating `rgba(255,255,255,0.02)`
  as opaque reported a perfectly readable heading at 1.11:1.
- **A uniform anomaly across unrelated pages is your harness**, not the code. An exact 6px overflow on 8 pages was the
  preview's body padding.

### Suggested order

1. Get a signed-in session and do an interaction pass (Quick create, repair intake, the flows).
2. Add repair-job fixtures to `SaverposDemoRuntimeSeeder` so the repair flow can be walked at all.
3. Fix the CD gap, or accept manual cPanel deploys and say so in the runbook.
4. Reconcile `.env` with the MySQL the demo router actually uses.

## Full UI/UX audit of all 13 in-app screens (2026-08-30)

Every in-app Recommerce screen was rendered from the real Blade against the real CSS cascade (`vendor.css` +
`init.css` + `app.css` + `saverbro-dark-pos.css`) and audited in a same-origin iframe runner, at **three viewports**
(375x812, 768x1024, 1280x900). Checks: composited contrast per text style against the WCAG floor for its size and
weight, form controls without an accessible name, buttons and links without discernible text, light surfaces on the dark
ground, and horizontal overflow.

### Result: 39 page-renders (13 screens x 3 viewports), all clean

Nothing below AA at any width, no unlabelled control, no nameless control, no light surface, no horizontal overflow.
That is after fixing what the audit found.

### Defect class 1 — stock components the dark pass never covered

The first dark pass covered `alert-info/warning/danger`, `btn-primary`, `btn-success` and their `label-` twins. Every
other stock variant stayed on Bootstrap's light defaults and measured **1.86-2.04:1** on the dark ground: `.btn-warning`
("Post receipt", "Reverse billed state" - both consequential actions), `.btn-info`, `.btn-danger`, `.label-warning`,
`.label-info`, `.label-default`, and **`.alert-success`**, which the earlier pass simply missed while adding its three
siblings. `.help-block` - the guidance text under form fields - sat at **3.37:1**, and `pre`/`code` kept a light grey
block background.

Fixed in `public/css/saverbro-dark-pos.css`, the correct home since these are app-wide classes. Grounds were chosen by
measurement, not by eye: warning 6.15, info 6.05, danger 6.68, default 8.40, alert-success 11.7-14.5, help-block
6.67-8.11, pre/code 16.06. `RecommerceDarkStockComponentsTest` asserts all 18 selectors stay covered.

### Defect class 2 — placeholders used as accessible names

**17 form controls** had no accessible name. A placeholder is not one: it disappears on first keystroke and is not
reliably announced. Fixed across five views - `parts/show` (7), `diagnostics/show` (4), `device/show` (4, where visible
labels existed but carried no `for`, so nothing associated them), `repair/new` (1, the customer search box, whose
visible label pointed at the select beside it), and `transfers/exceptions` (1).

`RecommerceFormLabellingTest` guards every module view. Two parsing details it has to get right, both of which produced
phantom findings first time: Blade expressions contain `->`, which ends an `[^>]*` attribute match early, so they are
neutralised before scanning; and a control wrapped in its own `<label>` is associated implicitly and must not be
flagged.

### The static guard caught what the rendered audit could not

The iframe audit found 6 unlabelled controls; the static guard found **17**. The other 11 sit behind conditions the
fixtures never satisfied - an exception row, a numeric diagnostic check, a reservation in a particular state. Rendering
proves what a fixture reaches; the source scan reaches the rest. Both are worth having.

### One false positive, recorded so nobody re-chases it

The tablet pass first reported 8 of 13 pages overflowing by exactly 6px. That was the harness: Bootstrap's `.container`
is a fixed 750px at >=768px, and the preview wrapper added 24px of body padding. The real views do not carry it. A
uniform excess across unrelated pages is the tell - a genuine responsive break is not the same number everywhere.

### Still not covered

The POS chrome itself (sidebar, navbar, top bar) is outside these renders, and no interaction was exercised - no click,
submit, or modal open. Those need a signed-in session.

## Quick create opened a bare HTML fragment (2026-08-30)

Reported as "the flow seems not working" on the customer repair intake screen. Confirmed and fixed.

The intake page offered:

```html
<a class="btn btn-default" href="{{ route('contacts.create', ['type' => 'customer']) }}" target="_blank" rel="noopener">Quick create</a>
```

`ContactController@create` returns `resources/views/contact/create.blade.php`, which **begins at
`<div class="modal-dialog modal-lg">` and extends no layout**. It is a modal body, not a page. Opening it in a new tab
therefore produced a bare, unstyled form fragment: no stylesheet, no jQuery validation, no select2, no CSRF-aware submit
wiring, and no way back to the intake screen. There was no way for the flow to work.

### The fix is the app's own idiom

`resources/views/contact/index.blade.php` shows the canonical usage: a `btn-modal` trigger carrying `data-href` and
`data-container=".contact_modal"`, with the container div present in the page. The intake screen now does the same,
using a `<button type="button">` rather than an anchor so the control is focusable without a dummy `href`.

**The container has to ship in the initial markup.** `public/js/app.js:525` binds
`$('.contact_modal').on('shown.bs.modal', …)` — a **direct** binding, not delegated — and that handler is what
initialises select2 inside the modal and attaches the `#contact_add_form` validation. A container injected later would
never receive it.

### The help text was also wrong, and is now obsolete

It said "use Quick create and then refresh this page", which was written when the customer select was a fixed list of
200 rendered server-side. Since the search box now queries `/recommerce/repair/customers` live, a customer created in
the modal is findable immediately — no refresh. The text now says so.

### Verified how far it can be without a session

`RecommerceCustomerRepairContractTest` asserts the endpoint really does return a layout-less fragment (so the test fails
if stock POS ever changes that), that the view uses `btn-modal` + `data-container`, that the `.contact_modal` container
is in the markup, and that no `target="_blank"` remains. Suite **270 tests / 1276 assertions**.

**Not verified:** clicking it. That needs a signed-in session on the local fixture. The modal's own behaviour is stock
POS code used unchanged elsewhere, so the risk is in the wiring, which is what the test pins.

### Two things found while diagnosing, not fixed

1. **The local demo fixture has 0 repair jobs.** `SaverposDemoRuntimeSeeder` creates devices and purchases but never a
   repair job, so the repair queue renders its empty state and there is no record to open. Anyone walking the repair
   flow locally has to create a job first — which is exactly the intake screen that Quick create was blocking.
2. **`.env` points at a database that does not exist**: `DB_CONNECTION=sqlite`,
   `DB_DATABASE=/private/tmp/saverbro_recommerce_demo.sqlite`. The served app is unaffected because
   `scripts/saverpos-demo-router.php` overrides the connection to MySQL per request, but every `php artisan` command run
   against this checkout fails on the missing file — including `route:list`. That is the source of the
   "Database file at path … does not exist" errors in `storage/logs/laravel.log`.

## Browser check of the dark conversion — one real defect found (2026-08-30)

The dark work was committed on source evidence alone, so it was rendered and measured. The screens were served as
standalone pages built from the **real** Blade templates and the **real** `saverbro-dark-pos.css` plus `app.css`,
inlined, on a throwaway `php -S` (since the authenticated app needs a login this session does not perform). That covers
the module's own `<style>` blocks and the shared stylesheet — the two things the conversion touched — but not the POS
layout chrome around them.

### The defect: an N/A checklist row was the brightest thing on the card

`repair/show` builds its class as `outcome-{{ strtolower(str_replace('_','-', $item->outcome)) }}`, and the controller
restricts that column to `PASS`, `FAIL`, `NOT_APPLICABLE` — so the generated class is **`outcome-not-applicable`**. The
view only ever defined `.outcome-pass`, `.outcome-fail` and an `.outcome-na` that the checklist never emits. An N/A row
therefore fell through to the card's default colour.

This is **pre-existing**, not from the conversion, but the conversion made it worse: on the old white card it inherited
`#172033` and merely looked unemphasised; on the dark card it inherits `--sb-text`, the brightest colour on the screen,
so "NOT APPLICABLE" outranked PASS and FAIL. Fixed by styling both selectors (`.outcome-na` is still used by the
warranty card). `RecommerceDeviceLifecycleUiContractTest` now reads the allowed outcomes **out of the controller's
validation rule** and asserts each generated class has a rule — mutation-checked by removing the new selector.

### Measured, not eyeballed

Contrast was computed from `getComputedStyle` with proper alpha compositing up the ancestor chain, against the real
rendered backgrounds:

| Screen | Distinct text styles | Below AA (4.5:1) | Worst |
| --- | --- | --- | --- |
| `repair/show` | 20 | **0** | 5.94 (`outcome-fail`) |
| `repair/index` | 13 | **0** | 6.60 (`sb-prio-high`) |

The variables resolve to the dark palette, not the fallbacks — the card measured `rgb(22, 34, 53)`, which is
`--sb-surface-raised`.

**A first measurement pass was wrong and is worth recording.** Treating any `background-color` other than
`rgba(0, 0, 0, 0)` as opaque made `.box-header`'s `rgba(255, 255, 255, 0.02)` read as solid white, which reported the
page heading at 1.11:1 — apparently unreadable, while the screenshot plainly showed it. Alpha has to be composited over
the ancestors or the numbers are fiction.

### What is still unverified

The full authenticated app: sidebar, navbar and the POS chrome around these screens, and `repair/new`, `parts/show` and
`diagnostics/show`, which were converted or reviewed but not rendered here. Those need a signed-in session on the
disposable local fixture.

## The Recommerce screens were never actually dark (2026-08-30)

The recorded dark-UI pass covered **stock POS** surfaces — utility classes, Highcharts, DataTables, date widgets,
breadcrumbs, legacy alerts. It did not touch the module's own screens, and `public/css/saverbro-dark-pos.css` contains
**zero** rules for `sb-record`, `sb-repair`, `sb-ops`, `record-card` or `checklist-item`. Those screens ship their own
`<style>` blocks, all written for a white card: `background:#fff`, `color:#172033`, pale borders. They rendered as light
slabs inside the dark chrome, and nothing tested it.

Converted the five in-app screens onto the `--sb-*` palette: `repair/show` (12 hardcoded values), `repair/index` (16),
`repair/new` (14), `partials/status-tones`, and the one remaining value in `dashboard/index`.

### The three standalone documents were deliberately left light

`device/public-certification`, `repair/public-status` and `labels/device` carry no layout and never load the shared
stylesheet. The label in particular **must** stay light — it prints on white stock. They are exempt in the guard test
with that reason recorded.

### Status tones were re-derived, not recoloured by eye

The five tone pairs were pale grounds with dark type, correct for a white card. On the dark surface they measured
13–14:1 against the background — bright blobs. They are now deep grounds with light type. Measured text-on-pill
contrast: intake 7.66, active 7.29, blocked 7.28, done 7.58, closed 8.40, all above the 7:1 AAA threshold; each pill now
sits at 1.4–1.8:1 against the surface, which reads as a chip rather than a flare. A print block restores the original
light pairs.

One token was measured and rejected: `--sb-faint` is **3.70:1** on `--sb-surface-raised`, below the 4.5:1 AA floor for
normal text. It had been used for the low-priority label; that now uses `--sb-muted` (6.67:1). `--sb-faint` remains
defined and is fine for decoration, not for type.

### The fallbacks are the old light values, not dark ones

First pass wrote dark fallbacks — `var(--sb-text,#edf4ff)`. That is wrong for the only case a fallback fires: if the
shared stylesheet fails to load, the variables are undefined **and** the page is the stock light theme, so a near-white
fallback paints white-on-white. Every fallback is now the value that rule had before the conversion, so a missing
stylesheet degrades to exactly the previous light design. `dashboard/index` already used this pattern
(`var(--sb-text, #1f2937)`) and was the precedent worth following.

### The guard

`RecommerceDarkPaletteTest` asserts, per in-app view, that a screen styling its own surfaces references the `--sb-*`
palette and paints no light background outside a print block. Print blocks are stripped before the check because they
are supposed to be light. Mutation-checked: putting `background:#fff` back on the record card fails it.
`partials/status-tones` is exempt from the palette rule with its reason documented — its hues are not in the palette
and inventing tokens for them would be worse.

### Not verified in a browser

This is source and test evidence. The dark stylesheet's own effect was never rendered here, and the previous session's
rendered check covered the Dashboard, device registry and POS register — not these repair screens. Suite is **268 tests
/ 1265 assertions**.

## Four operations screens rendered, and the harness factored out (2026-08-30)

Continuing the rendering push. `device/index`, `dashboard/index`, `reconciliation/index` and `transfers/exceptions` now
render in tests instead of being asserted as source, taking rendered coverage from 4 of 17 views to 8.

The per-test boilerplate moved into `Tests\Fixtures\RendersRecommerceViews`, which sets up everything a Recommerce
screen needs outside a request: the module view namespace, the layout stub, the module routes, the `system` table for
the global composer, **and `recommerce.enabled => true`** — without that last one `RouteServiceProvider::map()` returns
early, no module route exists, and every screen dies on its first `route()` call. `RecommercePublicViewRenderTest` was
moved onto the trait as well.

What the assertions check, beyond "it rendered":

- **Permission gating actually gates.** The device registry hides its Tracked receiving button without
  `$canReceive`; the dashboard drops the device and reconciliation cards for a role that cannot see them. Both
  mutation-checked — un-gating either one fails a test.
- **Empty and degraded states.** A device with no product row shows "Product unavailable"; a refurbishment row with no
  device shows "Unavailable"; a transfer manifest entry whose device is missing from the collection still renders. These
  are the paths a fixture usually skips and production hits first.
- **Conditional chrome.** Reconciliation offers its location switch only when more than one location is configured.

Each screen is fed plain `stdClass`/collections in the shape its controller passes, so the tests do not depend on the
module's tables.

### Remaining

9 of 17 views are still compilation-only: `device/show`, `diagnostics/show`, `parts/show`, `receiving/index` (417
lines, the largest), `repair/index`, `repair/internal-new`, `repair/new` (295 lines), `scans/index`, and the
`partials/status-tones` include. Suite is **240 tests / 1237 assertions**.

## The three public documents are rendered now too (2026-08-30)

Extending the rendering harness, starting with the views where a broken template is worst: the ones reachable **without
a session**. `device/public-certification`, `repair/public-status` and `labels/device` carry no layout, so they were the
cheapest to add and the highest blast radius — a customer or a printer sees the failure, not a signed-in operator.

`RecommercePublicViewRenderTest` renders all three and asserts the disclosure limits each page's own copy promises,
rather than just "it did not throw":

- the certification page shows the **masked** serial and drops the whole Serial row when there is nothing safe to show;
- the public status page renders the limited summary, and **escapes** `customer_facing_update` — that field is operator
  free text shown to a customer over an unauthenticated link, so it is the obvious injection seam;
- the label prints the device code and the opaque QR, with the scan target never rendered as text.

Both privacy assertions were mutation-checked: switching `{{ }}` to `{!! !!}` on the customer-facing update, and
removing the `@if` around the serial row, each fail a test.

### One thing worth knowing for the next view

A global view composer (`ModuleAssetServiceProvider` → `System.php:58`) reads app settings on **every** render, so even a
layout-less document needs a `system` table present. That is why these tests create one in an in-memory sqlite database
despite touching no data of their own.

### Remaining

13 of 17 views are still compilation-only: `dashboard/index`, `device/index`, `device/show`, `diagnostics/show`,
`parts/show`, `receiving/index`, `reconciliation/index`, `repair/index`, `repair/internal-new`, `repair/new`,
`scans/index`, `transfers/exceptions`, and the `partials/status-tones` include. Each needs its controller's data shape
built in a fixture; the harness and the layout stub are in place, so they are additive from here. Suite is **235 tests /
1212 assertions**.

## Views are rendered in tests now — and doing it caught a fatal I had shipped (2026-08-30)

The three previous fixes were all covered the same way this module has always covered views: by asserting against the
Blade **source**. That cannot catch a template that throws when it runs, which is exactly how the missing datetime casts
got as far as they did. So the repair record is now rendered for real in tests, with only the Ultimate POS layout
stubbed (`tests/Fixtures/views/layouts/app.blade.php` prepended to the view finder).

**It failed on the first run, and the bug was mine.**

### What was wrong

Blade's `compileStatements` matches `\B@directive`. A directive that immediately follows a **word character** — including
the `f` of a preceding `@endif` — is not compiled; it is emitted as literal text. On these dense, single-line templates
that is very easy to write and completely invisible to a source-string assertion.

`repair/show.blade.php` had five such directives, three of them pre-existing:

| Written as | Introduced |
| --- | --- |
| `@endif@else` (Collection card) | pre-existing |
| `@endif@if($canCollect …)` | pre-existing, edited by `a80eaf7` |
| `</form>@endif@if($canStartRepeat)` | `a80eaf7` |
| `@endif@if($claim->coverage_end_at)` | `843192e` |
| `…this claim@elseif(…)` | `843192e` |

Before my work the three leftovers happened to **balance** — an uncompiled `@else` and its uncompiled `@endif` cancel —
so the page still parsed and merely leaked literal `@else…@endif` text into the HTML. My two additions broke the
balance, and the compiled template became a **PHP parse error**: `syntax error, unexpected token "endif"`. In other
words, `843192e` and `a80eaf7` shipped a repair record screen that would have fatalled for every job. Both commits were
green, mutation-checked, and reviewed.

Fixed by separating each directive from the preceding word character. All 17 module views now compile with zero
leftovers.

### The guard

`tests/Unit/RecommerceBladeCompilesTest.php` compiles **every** Blade file in the module and asserts two things per
view: the compiled PHP passes `php -l`, and no Blade directive survives uncompiled. 34 tests, one pair per view.

Verified against the actual history rather than assumed: restoring the view as it stood at `a80eaf7` fails 2 of them,
and restoring it as it stood at `7d5adbc` — before any of my work, when it still parsed — fails 1, catching the literal
`@else` leak that had been in the page all along.

### Where this leaves the view idiom

Source assertions are still fine for *intent* (is the form gated, does it post to the right route). They are not
sufficient on their own, and this module had nothing else. Suite is now **230 tests / 1192 assertions**. The repair
record is the only screen rendered so far; the other 16 views are covered for compilation only. Rendering the rest needs
their controller data shapes and is worth doing.

## The Repeat visit button never worked, in any state (2026-08-30)

Chased the loose end flagged at the end of the intake-search work. It was worse than a missing idempotency key: the
button could not be used at all, and had **three independent defects** stacked on top of each other.

1. **It rendered in the wrong state.** The form sat inside `@if($canCollect && $job->state !== 'CLOSED')`, and
   `$canCollect` requires `STATE_READY`. `startRepeat()` accepts **only** a `CLOSED` job. So the button appeared
   exclusively in a state where the action is invalid, and never in the one state where it works.
2. **It was always disabled.** The button carried `@if($job->state !== 'CLOSED')disabled@endif`. Inside a block that
   only renders while the job is `READY`, that condition is always true — the button was unconditionally disabled.
3. **Its idempotency key was never sent.** The form posts `<input type="hidden" name="command_uuid" value="">`, and the
   shared `.collection-form` handler drops empty values (`String(value).trim() !== ''`) before building the payload.
   `RepairCollectionController::repeat()` validates `command_uuid` as `required|uuid`, so the request could only ever
   return 422 with the masked message.

Net effect: the only working path to a repeat visit was the one `WarrantyClaimService::createClaim()` creates
internally — which, until the previous commit, also had no UI.

### The fix

Gated on `$canStartRepeat` — customer repair, `state === CLOSED`, and `allowsWriteLocation` on
**`recommerce.repair.intake`**, which is the permission `assertRepeatAccess()` actually checks for a closed job (not the
collection permission the surrounding block uses). The dead `disabled` attribute is gone, the collect and repeat forms
are now gated independently, and the submit handler fills `command_uuid` before serialising. The v4 generator is hoisted
to one shared `sbCommandUuid()` rather than duplicated per form.

### Coverage

Four gate tests plus a view contract test; suite **193 tests / 1148 assertions green**. Three mutations applied and all
killed: accepting any state, substituting the collection permission for the intake one, and dropping the
customer-repair guard each fail a test.

### Still not verified in a browser

Every UI fix in this run — the warranty claim card, the intake customer search, and this — is covered by controller and
source-contract tests, not by a rendered page. The house idiom for views here is source assertions, and a rendered
smoke needs an authenticated session. Worth doing on the disposable local fixture when someone can log in.

## Repair intake could not reach customer 201 (2026-08-30)

RC-039's "service and route with no caller" turned out not to be a one-off, so all 51 Recommerce routes were checked for
a reference in any Blade view or JS file. **Three had none:** `recommerce.repair.legacy_archive.store` (the blocked
RC-041 work, expected), `recommerce.repair.public_status` (a false positive — `RepairPublicLookupService:64` builds that
URL server-side), and `recommerce.repair.customers`, which was genuinely dead.

### The bug that hid behind it

`RepairJobController::createPage()` seeds the intake customer `<select>` with `->limit(200)` contacts ordered by name.
The "Search by name, reference, or mobile" box then filtered **only those 200 options in the browser**
(`option.hidden = index > 0 && …`). So in any business with more than 200 customers, everyone sorted after the 200th was
invisible and unselectable, and the search box could never find them. The page's own help text — "If the customer is
new, use Quick create and then refresh this page" — did not help either: a new contact named "Zainab" is still not in
the first 200.

Meanwhile `GET /recommerce/repair/customers` already did the right thing — authorized, throttled 60/min, 2-character
minimum, searches `name`/`mobile`/`contact_id` across the whole business, limit 20 — and nothing called it.

### The fix

The search box now queries that endpoint (250 ms debounce, a token so a stale response cannot overwrite a newer one,
falling back to the seeded list if the request fails). Both render paths re-append the currently selected customer when
it is absent from the list they build — without that, picking a customer found by search and then clearing the search
box silently dropped the selection, which was a bug in the first version of this change.

### Coverage

Six behavioural tests for the endpoint (it had none) plus a view contract test. Suite **188 tests / 1135 assertions
green**. Four mutations applied: dropping the type filter, the 2-character guard, and the business scope each fail a
test.

**One mutation survived, and it is a finding rather than a gap:** removing `->whereNull('deleted_at')` changes nothing,
because `App\Contact` uses `SoftDeletes` and its global scope already excludes deleted rows. The explicit filter is
redundant in both `customers()` and `createPage()`. It is harmless and pre-existing, so it was left alone — worth
knowing before someone "fixes" a test to cover it.

### Worth a look later

`repair/show.blade.php`'s repeat-visit form posts `command_uuid` from an empty hidden input, and its JS drops empty
values before sending — so that request appears to carry no idempotency key at all. Not investigated here; it is a
different task from this one.

## RC-039 warranty claims reached the UI (2026-08-30)

The ledger recorded RC-039's remaining gap as "UI smoke pending". It was not a smoke gap — **there was no UI**.
`WarrantyClaimService` and `POST /recommerce/repair/{jobCode}/warranty/claim` were implemented and tested, but nothing
in the application referenced the route and no screen listed a claim, so the feature could only be exercised by a
hand-made request. `grep -rn 'warranty' Modules/Recommerce/Resources/views/` returns only unrelated device/intake
fields; there is no warranty view.

### What landed

`repair/show.blade.php` gains a **Warranty claims** card:

- lists each claim with number, coverage status, decision reason, policy name, cover end date and claim lines;
- labels the repeat job a claim produced, and shows a repeat job the claim it came from;
- carries the claim form (claim date + optional covered amount) only when `$canClaimWarranty` holds.

`RepairJobController::show()` supplies both, through two extracted private methods so the decisions are testable:
`warrantyClaims()` (claims where the job is either the source or the repeat, business-scoped, lines eager-loaded) and
`canClaimWarranty()` (customer repair **and** `allowsWriteLocation` on `WarrantyClaimService::PERMISSION_MANAGE`). The
form posts a browser-generated v4 `command_uuid`, so a resubmitted claim is returned rather than duplicated — the
service already deduplicates on `(business_id, command_uuid)`.

### A latent defect found while building it

`WarrantyClaim` cast **none** of its datetime columns. The service assigns Carbon, so the in-memory model formats fine
and every existing test passed — but a claim **re-read from the database** returned raw strings, and the card's
`$claim->coverage_end_at->format('d M Y')` would have fatalled on any repair record listing a claim. Added
`coverage_start_at`, `coverage_end_at`, `claim_requested_at` (datetime) and `claimed_on` (date) casts, with a test that
re-reads a saved claim rather than trusting the instance the service returned.

### Coverage

Six tests added (five in `RecommerceWarrantyClaimTest`, one view/controller contract test), suite **181 tests / 1117
assertions green**, `recommerce-static-check` green. **Five mutations applied and all killed:** dropping the
`isCustomerRepair` guard, dropping the permission check, dropping the repeat-job clause, dropping the job filter
entirely, and removing the datetime casts each fail exactly one new test. The permission test deliberately uses a user
double whose `can()` is independent of `recommerce.permissions`, per the rule this handoff records after RC-041 — a
config-driven double could not tell "catalogued" from "granted".

### Not done

No rendered browser smoke: that needs an authenticated session, and entering a password to log in is outside what this
session does. The production-policy review is also still open, and `policy_version` stays null until policy versioning
is real (the snapshot's `version_number` is still hardcoded, as the service's own comment says).

## The auto-deploy has never shipped a commit (2026-08-30) — proved by byte comparison

Chased the failed Cash smoke to its cause. **`pos.kkcctv.com.my` is running a checkout from before `e69b8dd`.**
Three workflow runs have reported success while deploying stale code.

### The proof

`e69b8dd` is the only recent commit that touches a web-served file (`public/css/saverbro-dark-pos.css`; `3c939c8`,
`28f129f`, `45e30c8`, `bfd0bf4`, `e9b82f3` and `ba7b90f` touch none). Fetching that file from the live site and hashing
it in the browser:

```text
live    25482 bytes  sha256 3c2ab4f70cadbcef475bfcc4681d3cf065ed5d614fc20439877e5a3cf6df32fe
HEAD    30238 bytes  sha256 549513caf3ba07641443bbffea4d6960d10399f7b67f39ada5419ced4c9c1f39
```

The live hash is byte-identical to that file at `03d49f2` — its state from the root commit until `e69b8dd` changed it.
So `e69b8dd`, `bfd0bf4`, `e9b82f3` and `ba7b90f` are all absent from the server, which is why the payment map is still
empty. The currency repair is not live either; the dashboard's `RM` was never evidence of a deployment.

### Why

`.github/workflows/deploy-staging.yml` calls **only** `VersionControlDeployment/create`, which deploys the HEAD of the
**server's** checkout. Nothing in it performs cPanel's **Update from Remote** — the `git pull` that would bring the new
commit down. `ICORE_CPANEL_STAGING.md` states the required sequence verbatim: "use **Update from Remote** followed by
**Deploy HEAD Commit**." The workflow implements only the second half, so every push redeploys the same stale checkout,
succeeds, and changes nothing. The site staying healthy across all three runs is consistent with exactly that.

A second defect compounds it: `curl --fail` only catches HTTP-level failures, and cPanel UAPI returns **HTTP 200 with an
`errors` array** on failure. Even with the correct sequence, a failed deployment would still report a green run.

### This corrects the previous handoff

"A push now deploys — this changes the visibility question" (below) was an inference from reading the workflow file, not
an observed end-to-end deployment. It is wrong: **a push to `staging` publishes to GitHub and does not reach the site.**
The site's working state came from the operator's manual cPanel Update-from-Remote + Deploy steps, not from CD. The
repository-visibility question stands on its own merits, but not on the CD argument given there.

### Not fixed here — needs a decision

The repair is to call cPanel's update-from-remote before the deploy, and to parse the UAPI response body instead of
trusting the exit code. Both were left undone deliberately: this changes a credentialed, outward-facing CD pipeline, the
exact UAPI module/function for the update step cannot be verified from this session, and a wrong guess would be pushed
straight into the deploy path. Confirm the deployed commit in **cPanel → Git Version Control → Manage** before changing
anything — it should read a commit at or before `03d49f2`.

## Cash smoke attempted on staging (2026-08-30) — the deploy did not take effect

`ba7b90f` was pushed with the operator's approval (`e9b82f3..ba7b90f`), which should have triggered
`.github/workflows/deploy-staging.yml` and reseeded the estate. The operator signed in and the smoke was driven from
there. **The fix is not live.** On `pos.kkcctv.com.my`, both branches still report a stored map of exactly
`{"advance":{"is_enabled":1,"account":null}}` — and `advance` is injected by `app/BusinessLocation.php` on top of an
**empty** decode, so the column itself is still NULL. `SaverposDemoExpansionSeeder` has not run against that database.

**Do not read the dashboard's `RM` as proof of deployment.** The currency repair shipped in `e9b82f3`, which was already
live before this session; it says nothing about `ba7b90f`. Nor does the payment-method dropdown in the payment modal:
`payment[0][method]` listed all twelve types, but that select is rendered from the **global** `payment_types()` list, not
the location-scoped one. The only trustworthy signal is the per-location `data-default_payment_accounts` attribute on
`select#select_location_id`, read above.

Whether the GitHub Actions run failed, is queued, or the cPanel deploy errored is **unknown** — `gh` and `curl` were both
blocked by this session's permission guard, so the Actions run and the cPanel deploy log were never read. That is the
next thing to check.

### Root cause of the "Cash button does nothing" symptom, now pinned exactly

Clicking Cash (`.pos-express-finalize`) throws in stock POS code:

```text
TypeError: Cannot read properties of undefined (reading 'account')  —  public/js/pos.js:3037
```

`default_accounts && default_accounts[payment_type]['account']` guards the *map* but not the *key*. With a map holding
only `advance`, any other payment type dereferences `undefined` and the handler dies before it can build a payment row —
so the button appears inert with no user-visible error. Populating the map (the seeder fix) removes the trigger for the
demo estate. The missing key-existence guard in `public/js/pos.js` is **pre-existing stock Ultimate POS code**, is out of
RC-002 scope, and was deliberately left alone; it is worth a decision separately, because any location with a partial
map hits it.

Nothing was written on the server: the tracked line (`SB-DV-00000019-1`, AVAILABLE/ON_HAND at Branch B) was assembled in
the cart but never finalized, so no sale, payment, or device movement was created.

## Demo payment accounts repaired for the already-seeded estate (2026-08-30)

Verified the incoming state first: full suite reproduced at **170 tests / 1083 assertions green** on PHP 8.2.33, `recommerce-static-check` green, and the dirty tree exactly as described (RC-041 only). Two handoff claims did not survive checking:

1. **The commit pointer was stale.** HEAD and `origin/staging` are both `e9b82f3` — same subject as the recorded `5b29b4c` but a different hash, so history was rewritten and pushed. The currency and dark-UI work is published, not pending.
2. **"The staging deployment must rerun that expansion before the Cash-specific smoke" was wrong.** Rerunning it would not have fixed anything. `03d49f2` added the `default_payment_accounts` map to `SaverposDemoRuntimeSeeder::location()`, which only ever runs on a **fresh** database (`scripts/cpanel-staging-bootstrap.php` picks the expansion path whenever a business already exists). `SaverposDemoExpansionSeeder` repaired the currency and nothing else — it never referenced the column. The deployed branches, seeded before `03d49f2`, still had `default_payment_accounts = NULL`.

### The blocker was wider than "Cash"

`Util::payment_types($location)` unsets every payment type not marked enabled in the location's map, and a NULL map decodes to `[]` — so **no** payment type survives, not just cash. The recorded symptom ("the Cash button could not open its payment path") was the visible corner of a branch with no payment methods at all.

### The fix

- `SaverposDemoRuntimeSeeder::demoPaymentAccounts()` is now the single source of the shape, used by the fresh-estate `location()` builder and by the repair, so the two seeders cannot drift again.
- `SaverposDemoExpansionSeeder::syncDemoPaymentAccounts()` fills in only the types a demo branch is **missing** (`$configured + $defaults`), so a disabled type or a bound account is preserved rather than reset; it skips soft-deleted locations, scopes to the demo business, and writes nothing when the map is already complete.

### Evidence

Disposable MySQL fixture (`saverpos_demo_cashfix`, dropped afterwards), with both branches reset to `NULL` to reproduce the deployed estate:

```text
before repair   location 1: raw=NULL  types=[]
before repair   location 2: raw=NULL  types=[]
after repair    location 1: raw=json  types=[cash,card,cheque,bank_transfer,other,custom_pay_1..7]
after repair    location 2: raw=json  types=[cash,card,cheque,bank_transfer,other,custom_pay_1..7]
rerun idempotent: yes (updated_at unchanged)
```

Four tests added to `tests/Unit/SaverposDemoRuntimeSeederTest.php` (repair, preservation of configured entries, no-op on a complete location, scoping). **Mutation-checked, not just observed green:** dropping the `deleted_at` filter, replacing the merge with an overwrite, and removing the idempotence short-circuit each fail exactly one of the new tests. Suite: **174 tests / 1092 assertions**.

### What this does not do

Nothing was deployed. `pos.kkcctv.com.my` still runs the old fixture, so the Cash-specific smoke is still unverified there and stays that way until an approved deployment reruns the seeder. This is demo-fixture code only — it touches no production path, and no RC task advances.

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

## RC-002 certified on the deployment platform (2026-08-30) — PHP 8.2 + MySQL

Every previous baseline run in this handoff was either the Mac run nobody could reproduce, or the cloud container on **PHP 8.4**, which was recorded honestly as "a strong regression signal, not platform certification for 8.2". This session ran on the Mac itself, where `/opt/homebrew/bin/php` is **8.2.33** — the same major/minor as the cPanel staging target — with MySQL 8 already running locally.

### Run against a clean HEAD export, not the dirty tree

The working tree still carries the blocked RC-041 code, including two migrations. Certifying the *committed* baseline while that sits on disk needed isolation, so the run used `git archive HEAD | tar -x` into the scratchpad, with `vendor/` symlinked in (Packagist reachability is irrelevant then) and a fresh `storage/` + `.env` from `.env.example` with a generated key. The export contains **0** files matching `*repair_archive*`, confirmed before migrating. The real working tree was never touched and is still exactly RC-041 and nothing else.

### Results — all PASS except one pre-existing blocker

| Check | Result |
| --- | --- |
| `composer check-platform-reqs` | php 8.2.33 + all 19 extensions **success** |
| Migrations on fresh disposable MySQL | **327/327 applied, 0 pending**; 108 tables, 37 `recommerce_*` |
| PHPUnit (full suite) | **OK (167 tests, 1056 assertions)** in ~8s |
| PHP diagnostics during the suite | **zero** deprecations/notices/warnings (captured to a log via `-d error_reporting=E_ALL -d log_errors=1`; file came back empty) |
| Skipped / incomplete / risky tests | **zero** — progress output is 167 dots, no `S`/`I`/`R`/`W` |
| `php -l` over `Modules/Recommerce` | clean across all **134** files |
| `route:list` | resolves **666** routes, **51** of them Recommerce — matches the RC-006 sweep's count exactly |
| `config:cache` | succeeds |
| Frontend assets | present and prebuilt — `public/css/app.css`, `css/vendor.css`, `js/app.js`, `js/vendor.js`, `mix-manifest.json`. **No asset build is needed to deploy.** |
| `route:cache` / `optimize` | **FAIL** — see below |

The disposable database (`saverpos_baseline_20260830`) was dropped afterwards; the other demo databases were not touched. The scratchpad export was removed, unlinking the symlink first so `rm -rf` could not reach the real `vendor/`.

### The one blocker: `route:cache` fails on a duplicate route name

```
LogicException: Unable to prepare route [logout] for serialization.
Another route has already been assigned name [logout].
```

`routes/web.php` line 87 calls `Auth::routes(['register' => false])`, which registers `POST /logout` named `logout`; line 538 then registers `GET /logout` **also** named `logout`. Laravel refuses to serialise the collection, so `route:cache` and `php artisan optimize` (which runs config then routes) both abort.

**This is stock Ultimate POS, not SAVERPOS work.** `git diff <root-commit> HEAD -- routes/web.php` is empty — the file is byte-identical to the vendor import.

**It does not break the current deployment.** `scripts/cpanel-staging-deploy.sh` runs `config:clear` and `view:clear` and never caches routes, so `pos.kkcctv.com.my` is unaffected. What it does block is standard Laravel production route-cache optimisation, which belongs to RC-045's performance gate.

**Deliberately not patched here.** RC-002 puts stock POS code out of scope, and the fix is not obvious enough to make silently: someone has to decide which route keeps the name. Useful facts for whoever takes it — nothing in `resources/views` or `Modules/` calls `route('logout')` (grep count: 0), and the header links via `action([\App\Http\Controllers\Auth\LoginController::class, 'logout'])` at `resources/views/layouts/partials/header.blade.php:255`, which is itself ambiguous while two routes point at the same action. Dropping `->name('logout')` from line 538 looks safe on that evidence, but it is a change to vendor auth routing and deserves its own task and a rendered logout check.

### What this does and does not certify

It certifies that the committed baseline installs, migrates, boots, routes and tests cleanly on the staging platform's PHP and database engine. It is not a load test, not a security gate, and not a browser smoke test — RC-045 still owns those. It also says nothing about the cPanel host itself, only about the PHP/MySQL versions it targets.

### Reproducing it

Everything above runs from the Mac in a couple of minutes:

```
cd ~/Downloads/UltimatePOS-V7.3/UltimatePOS-CodeBase-V7.3 && php vendor/bin/phpunit --no-coverage
```

For the migration half, export HEAD to a scratch directory as described above and point artisan at a throwaway database with environment variables — Laravel's dotenv is immutable, so `DB_CONNECTION=mysql DB_DATABASE=<throwaway> ... php artisan migrate --force` overrides `.env` without editing it. Verified before use: `config('database.default')` reads back `mysql`. **Never point this at a database you care about.**

### RC-041 was re-verified, and is still blocked

Read before doing anything else, per the incoming-agent instructions. `LegacyRepairArchiveService::assertArchiveAccess()` still injects `AuthorizationGate` and still never calls it — it checks `in_array('recommerce.repair.archive', config('recommerce.permissions'))` and `$this->cohortPolicy->allowsBusiness()`, with no `$user->can()` anywhere. The nullable-but-dereferenced `$cohortPolicy` is also still there. Every other detail in the handoff matched the source. **No RC-041 code was touched.**

## cPanel staging inspected in the browser (2026-08-30) — the deploy was never runnable

The operator logged into iCore cPanel and I walked the estate. Everything the previous sessions
described as infrastructure is in place; the deployment itself has **never run**, and the reason
turned out not to be the one recorded.

### Verified present and correctly wired

- **Git Version Control is enabled** on this plan (shell access is not — cPanel warns clone URLs are hidden, which is exactly why the `.cpanel.yml` route was built).
- Repository path is `/home/kkcctv93/repositories/saverpos-staging-repo` — note the **`-repo` suffix**. The runbook had assumed `.../saverpos-staging/`. Corrected in `ICORE_CPANEL_STAGING.md`.
- `pos.kkcctv.com.my` document root is `/home/kkcctv93/repositories/saverpos-staging-repo/public` — correct. The main domain is separate on `public_html`.
- The staging MySQL database exists, is **0 bytes**, and its dedicated user is listed as a privileged user on it. The WordPress database and its user are separate, so the isolation the runbook required holds.
- The server `.env` exists (556 bytes, created 2026-08-30 00:07).

### Two corrections to the previous handoff

1. **cPanel's HEAD is `4e68994`, not `bd8f49f`.** The server is already level with `origin/staging`, so **Update from Remote** would fetch nothing today. The deploy machinery is already on the server — `4e68994` is the commit that added it.
2. **The missing `.env` was not the blocker.** It was already created. The checkout has no `vendor/` and no `storage/`, which confirms the deploy task has never executed.

### The real blocker: cPanel disables Deploy on a dirty branch

**Deploy HEAD Commit is disabled** (confirmed programmatically: `disabled: true`; *Update from Remote* is enabled). cPanel requires a valid `.cpanel.yml` — present — **and** no uncommitted changes on the checked-out branch. Its dirty test counts **untracked** files.

The untracked path is `public/.well-known/acme-challenge/`, timestamped 23:49 — the moment the Let's Encrypt certificate was installed. It was not covered by `.gitignore`, so the server's tree reads dirty and the button stays greyed out behind a generic message that never names the path.

**Fixed by ignoring it**, not deleting it: the directory is recreated at every certificate renewal, so deleting it on the server would re-block the next deployment. `/public/.well-known/` is now in `.gitignore`. Verified by reproducing the server condition locally — created `public/.well-known/acme-challenge/probe`, confirmed `git check-ignore` matches it at `.gitignore:34` and that `git status` reports zero `well-known` entries, then removed the probe.

### What still has to happen, in order

1. **Push.** The fix only reaches the server through GitHub. This is the same push that has been outstanding for two sessions.
2. cPanel → Git Version Control → **Update from Remote** (enabled today).
3. **Deploy HEAD Commit** should then be clickable. It runs Composer, generates `APP_KEY` into the server-only `.env`, migrates, and seeds the fictional estate only if `business` is empty.
4. Smoke test per `ICORE_CPANEL_STAGING.md` §6.

Nothing was changed on the server. No cPanel setting was modified, no file was created or deleted there, and the login was performed by the operator.

### The server `.env` was deliberately not read

It holds a live database password. A scripted read that would have returned it redacted was refused by the session's permission guard, and reading it via the File Manager editor was declined rather than worked around, since that would have put the password into this transcript. Its non-secret contents are therefore **unverified** — `APP_ENV`, `DB_DATABASE` and `DB_USERNAME` have not been checked against the database that actually exists. The deploy fails loudly and harmlessly if any are wrong (the bootstrap refuses any `APP_ENV` other than `staging`, and migrations abort on bad credentials against an empty database), so the cheapest confirmation is the deployment log itself.

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

### Update 2026-08-30 — the credential blocker is gone, the push is not done

This session runs on the Mac, where `credential.helper=osxkeychain` is configured globally, so the environment limit described above no longer applies. **The push was still not performed**, because it publishes twelve commits to a public GitHub repository and changes what cPanel's **Update from Remote** would bring to `pos.kkcctv.com.my` — an outward-facing action that belongs to the operator, not to an agent acting on its own. It is queued for explicit approval.

State re-checked here: local `staging` is **12 commits ahead, 0 behind** `origin/staging` (still `4e68994`) — a clean fast-forward. `.env` remains untracked; the only tracked env files are `.env.example` and `.env.cpanel-staging.example`.

The repository-visibility question from the previous session is still **open and unanswered**: `nandayo9/saverpos-staging` is public. No live secret is exposed, but a public repo carrying deployment runbooks and the staging hostname for a POS product is a decision the operator should confirm rather than an agent assume.

## Staging is live, and verified independently (2026-08-30)

The push landed and eight further commits followed (`656d4ac` … `3c939c8`), carrying the cPanel deployment through to a working site. Local and `origin/staging` are now level at `3c939c8`. This section records what was checked rather than taken from the previous agent's report.

### The deployment claim holds

`https://pos.kkcctv.com.my/login` returns **HTTP 200**, with `curl`'s `ssl_verify_result=0` (valid certificate chain, so the Let's Encrypt install is good) and the title `Login - SAVERPOS`. That is the app serving, not a placeholder.

### Exposure checks on a publicly reachable POS site

Now that the site is reachable by anyone, the obvious surfaces were probed:

| Path | Result |
| --- | --- |
| `/.env` | **403** |
| `/.git/config` | **403** |
| `/storage/logs/laravel.log` | 404 |
| `/.cpanel.yml`, `/composer.json`, `/AI_HANDOFF.md`, `/scripts/cpanel-staging-deploy.sh`, `/vendor/composer/installed.json` | 404 |

A request for an unrouted path returns Laravel's plain **Not Found** page with **no stack trace, no environment dump and no file paths** — so `APP_DEBUG=false` is genuinely in effect on the server. Nothing sensitive was reachable. These were read-only GETs against the operator's own host.

### Regression check across the eight commits

Full suite still **green: 167 tests / 1056 assertions** on PHP 8.2.33, and `recommerce-static-check` passes. The new commits touched `bootstrap/app.php`, `app/Http/Middleware/IsInstalled.php`, both cPanel scripts, two seeders and a new GitHub Actions workflow; none of it moved the suite.

### A push now deploys — this changes the visibility question

`.github/workflows/deploy-staging.yml` was added, triggering on **push to `staging`** and calling cPanel's `VersionControlDeployment/create` UAPI with four repository secrets. The workflow itself is sound: it is `push`-only (never `pull_request`, so a fork cannot trigger it), holds `permissions: contents: read`, keeps every credential in `secrets.*`, and hard-fails on any unset secret before the `curl` runs.

The consequence is worth stating plainly: **`git push origin staging` is now a deploy to the live site**, not just a publish. Anyone with write access to the repository can ship to `pos.kkcctv.com.my` by pushing. That makes the still-unanswered repository-visibility question (below) more consequential than it was — a public repository with CD wired to a live POS instance deserves a deliberate decision, not a default.

### Observation on the external environment path, not a defect

`bootstrap/app.php` now resolves the `.env` location by preferring `SAVERPOS_ENV_PATH` and otherwise falling back to a hardcoded sibling directory, `dirname(__DIR__) . '/../saverpos-staging'`. On the server both branches resolve correctly (the checkout is `saverpos-staging-repo`, the live estate is `saverpos-staging`), and `IsInstalled` was correctly updated from `base_path('.env')` to `app()->environmentFilePath()` to match.

Two things to be aware of rather than to fix now: the fallback runs in **every** environment, not only cPanel, so if a directory named `saverpos-staging` ever appears beside a checkout the app silently switches its `.env` source; and `SAVERPOS_ENV_PATH` is set only by the deploy script and documented nowhere else — not in `.env.example`, `.env.cpanel-staging.example`, or the runbook. Locally the sibling path does not exist, so the fallback is a no-op and the suite is unaffected. Worth a line in the runbook when someone next touches deployment.

### Repository visibility

While checking the remote, `git ls-remote` succeeded **anonymously** — the GitHub repository `nandayo9/saverpos-staging` is **public**, not private. It was audited on that basis and no live secret is exposed: `.env` is untracked and matched by `.gitignore` line 12, only `.env.example` and `.env.cpanel-staging.example` are tracked, and a scan of tracked files for app keys, database passwords, GitHub tokens and AWS keys returned only validation rules and translation strings. Still worth confirming the public setting is intentional for a POS codebase carrying deployment runbooks and the staging hostname.
