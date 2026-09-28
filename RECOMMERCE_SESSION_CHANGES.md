# Recommerce Session Changes — 2026-09-23

A compiled record of everything done in this session, grouped by initiative. All changes are scoped to the Recommerce module (`Modules/Recommerce/`) plus a handful of site-wide shared stylesheets/views that surfaced bugs affecting every page, not just Recommerce.

---

## 1. ECC plugin `hooks.json` fix

**Problem:** Claude Code rejected the ECC plugin's `hooks.json` with "unknown keys 'description', 'id' ... and 51 more ignored."

**Root cause:** Claude Code's hook schema only allows `matcher` and `hooks` per entry; the plugin added `description`/`id` to every entry across all lifecycle events.

**Fix:** Stripped `description`/`id` from every hook-group entry in:
- `~/.claude/plugins/cache/ecc/ecc/2.0.0/hooks/hooks.json`
- `~/.claude/plugins/marketplaces/ecc/hooks/hooks.json`

Local-only fix; will need to be reapplied if the plugin updates from upstream.

---

## 2. Recommerce CTA buttons brought in line with the rest of the app

**Problem:** All 11 Recommerce pages still used legacy Bootstrap `btn btn-primary` + Font Awesome icons, while the rest of the app (40+ core pages) had migrated primary "Add new X" buttons to a Tailwind gradient (indigo→blue) pattern, and form-submit buttons to a DaisyUI `tw-dw-btn` pattern.

**Fix — page-header gradient CTA buttons:**
- `dashboard/index.blade.php` — "New stock purchase"
- `repair/index.blade.php` — "New customer repair" (both header + empty-state copies)
- `stock-count/index.blade.php` — "Create stock count"
- `tradein/index.blade.php` — "New Acquisition"

**Fix — form-submit DaisyUI buttons:**
- `repair/new.blade.php` — "Find device" / "Create customer repair"
- `repair/internal-new.blade.php` — "Create internal refurbishment"

Other buttons (Search, Assign, Resolve scan, etc.) were left untouched — they use `.btn-default`/`.btn-primary`, already globally themed consistently.

---

## 3. Table bugs (DataTables scrollX + scrollY) — site-wide

Three related, sequentially-discovered bugs, all traced back to `purchase_table.blade.php` / `purchase.js` / `saverbro-layout.css`:

1. **Duplicate header row** — DataTables keeps a hidden clone `<thead>` inside `.dataTables_scrollBody` for column-width sync; `vendor.css` only strips its sort-icon pseudo-elements, not its box, so it rendered as a second visible header. Fixed in `saverbro-layout.css` by zeroing `font-size`/`line-height` on that clone (kept padding intact — removing padding broke column-width math, see #3 below).
2. **Purchases page infinite spinner** — `purchase_table.blade.php`'s footer `colspan` double-counted the "Receiving progress" column (colspan absorbed it *and* a separate `<td>` represented it again), producing a 16-vs-15 column mismatch that crashed DataTables' init (`TypeError ... 'nTf'`) before it ever fired the data request. Fixed the colspan to a fixed `5`.
3. **Column misalignment** — caused by fix #1's first pass removing padding from the hidden clone row, which (since it's `table-layout: fixed`'s first row) shrank the whole body table ~184px narrower than the header/footer. Corrected by restoring the padding and only zeroing font-size/line-height instead.

Also added `purchase_table.columns.adjust()` to `purchase.js`'s `fnDrawCallback` (didn't fully fix #3 alone, but keeps columns resynced on every redraw going forward).

All fixes are in shared, global files (`saverbro-layout.css`, `purchase_table.blade.php`, `purchase.js`) — they apply to every `scrollX`+`scrollY` DataTable site-wide (Purchases, Purchase Return, Reports), not just Recommerce.

---

## 4. Small, targeted UI fixes (Recommerce pages)

- Removed the `→` arrow separator between Trade-In funnel steps (`.sb-ti-funnel-step::after`) per request.
- Dashboard "Operator workflow" buttons: left-aligned + vertically centered text (`display:flex`); the whole card narrowed to shrink-wrap the button width instead of filling its grid column; "Internal refurbishment queue" widened and rebalanced (`col-md-9` / `col-md-3` split) to use the freed space.
- Dashboard header row ("Device Overview" title + action buttons): added top margin so it isn't flush against the navbar; the two header buttons now align on the same center line (`display:flex; align-items:center`) instead of their mismatched baselines.
- Trade-In Reports tables ("Staff performance", "QC aging"): headers and relevant data columns centered + middle-aligned.
- Site-wide spacing: added `margin-top:24px` to the outer `<section>` of all 9 remaining Recommerce pages that previously sat flush against the top navbar (Device Registry, Find Device, Inspection Queue, Stock Check, Stock Count, Customer Repairs, New Customer Repair, New Internal Refurbishment, Trade-In Acquisition).

---

## 5. Design audit — full compilation

Published as a [Claude Docs document](https://claude.ai/artifact/WE4ut9Jueyha1HUJbwK9ZR) covering, for all 11 Recommerce pages: framework/typography construction, the site's dark/light theme mechanism (`data-theme` attribute + `--sb-*` CSS custom properties), a page-by-page construction table, and — the key finding — five specific components with hardcoded colors that don't adapt between themes.

## 6. Dark/light theme fixes (from the audit's findings)

Following the codebase's own established conventions (structural surfaces → `var(--sb-*)` tokens; solid-fill badges → hand-picked per-theme hex pairs, matching the existing `.label-warning`/`.label-danger` precedent):

- **`tradein/partials/styles.blade.php`** — rewrote ~90 hardcoded color declarations to `var(--sb-*)` tokens (table, stepper, form controls, callouts, timeline, catalogue cards); removed 5 dead/redundant button-color overrides already shadowed by global `!important` rules.
- **`device/index.blade.php`** — retheme'd 5 hardcoded spots (registry chip, state badge, selected-row highlight, bulk-toolbar, active-tab indicator).
- **`partials/status-tones.blade.php`** — added a `html[data-theme="light"]` variant for the status pills, reusing the same pairs the file already had validated for print output.
- **`repair/index.blade.php`**, **`repair/new.blade.php`** — same light-mode variant treatment for the two solid-fill pills that were missed (`.sb-prio-urgent`, `.sb-status-info`).
- **`saverbro-light.css`** (site-wide) — fixed a specificity conflict where the global "force all plain links to dark-blue text" safety rule was overriding the Trade-In nav/filter active pills' white text, making them nearly invisible against their own blue background. Added ID-anchored overrides for `.sb-ti-nav a.active` and `.sb-ti-filters a.active`.

## 7. Footer text-wrap bug (site-wide)

`layouts/partials/footer.blade.php` — the copyright line ("SAVERPOS - V7.3 | Copyright...") was splitting into two visually disconnected fragments for a reason that couldn't be isolated despite checking floats, positioning, bidi/direction, and `::first-line` rules. Fixed by forcing `white-space: nowrap` with `text-overflow: ellipsis` as a fallback — the text always fits on one line, so this eliminates the wrap regardless of its cause. Also resolved the visual collision with the fixed "scroll to top" button that sits in the same corner.

---

## 8. Walk-In Trade-In Intake + Pricing Calculator (new feature)

Full plan at `~/.claude/plans/proud-squishing-balloon.md`. Adds a second, in-branch intake channel alongside the existing Website-sourced trade-in requests.

### Data model
New migration `2026_09_23_000001_extend_trade_in_quick_quotes_for_walk_in.php` adds to `recommerce_trade_in_quick_quotes`: `category_code` (default `LAPTOP`), `channel` (default `STAFF`, new value `WALK_IN`), `market_price_source`, `market_price_fetched_at`. Existing rows/behavior unaffected by the defaults.

### Pricing engine — `Modules/Recommerce/Services/TradeInWalkInPricingService.php`
Encodes the exact deduction formula supplied: base = market price ÷ 2, then category- and brand-family-aware deductions (screen/body condition, biometrics, core functions, camera, extra issues for Phone/Tablet; LCD/body/input-devices/camera + auto-reject triggers for Laptop), then a margin layer (30% store profit, 10–20% warranty adjustment, 10–20% appearance), with an RM30 floor and auto-reject handling for disqualifying conditions (bloated battery, jailbroken/rooted, LCD not working).

Flagged assumptions (noted in the plan for review): range deductions (e.g. "-RM100 to -RM300") mapped to a 3-tier Minor/Moderate/Severe severity control; auto-rejects create an audit record rather than blocking the form outright; RM30 floor applied to Phone/Tablet by extension, not just the explicitly-stated Laptop case.

### Market-price API — `Modules/Recommerce/Services/TradeInMarketPriceApiClient.php`
Pluggable, config-driven (`recommerce.tradein_market_price_api.*`), returns `null` until a real provider is configured — the form falls back to manual price entry in that case. Wiring in a real provider later is a config change plus filling in one HTTP call, no controller/view changes needed.

### Routes + controller
`GET`/`POST /recommerce/trade-ins/walk-in` → `TradeInController@walkIn` / `@storeWalkIn`. New `TradeInQuickQuoteService::createWalkIn()` method added as a sibling to the existing `create()` (which stays untouched — zero regression risk to the existing laptop wizard's Quick Quote panel).

### View
`tradein/partials/walk-in.blade.php` — Device (dropdown: Phone/Tablet/Laptop) → Brand/Model → RAM/Storage type/Storage size (dropdowns) → CPU/GPU → condition fields that swap per device type via JS → warranty/appearance/market price → submit.

### Overview tab
Renamed "Website requests" → "Device Trade-In Calculations"; added a "+ Walk-In" button; the table now merges Website-sourced (`recommerce_trade_in_intakes`) and Walk-In-sourced (`recommerce_trade_in_quick_quotes` where `channel='WALK_IN'`) rows, each tagged with its own Source badge.

### Verified
- All new PHP files pass `php -l`.
- Migration ran cleanly against the dev database.
- Full pipeline tested live: form → validation → pricing calculation → `createWalkIn()` — confirmed working end-to-end (failed only on a pre-existing, unrelated data gap: this dev environment has no default/fallback `TradeInRuleSet` for Phone/Tablet categories, which is an existing system requirement, not a bug introduced by this feature).
- Device type field converted from a card-style picker to a native `<select>` dropdown per follow-up request.

**Outstanding for a real go-live:** a default `TradeInRuleSet` needs to be configured for Phone/Tablet (existing admin feature, out of scope for this change), and a real market-price API provider needs to be wired into `TradeInMarketPriceApiClient` once you have one.

### Follow-up fixes (Walk-In form polish)

- **Device type field** — changed from a card-style picker to a plain dropdown, per request.
- **"Extra issue" severity** — removed the separate "Extra issue severity" label/column; the severity dropdown now sits directly under the "Extra issue" checkbox and only appears once that checkbox is ticked. (First attempt used the HTML `hidden` attribute, which the site's own CSS was silently overriding — switched to directly setting the field's display style, which always wins.)
- **IMEI vs Serial number** — IMEI is now required for Phone/Tablet walk-ins; the Laptop serial number field is optional, matching how a customer trading in a laptop shouldn't be blocked on providing one. Enforced both in the form and in the backend validation, and confirmed the disabled/hidden field for whichever device type isn't selected is correctly excluded from validation either way.

---

## 9. Live market price from Carousell Malaysia (Apify)

### Choosing a price source
No official API gives used-device prices for Malaysia. The options considered:

| Option | Result |
|---|---|
| Own POS sell prices (`Variation.sell_price_inc_tax`) | Free and reliable, but only covers models already sold |
| eBay Marketplace Insights API | Official, but global prices and needs eBay partner approval |
| SellCell / BankMyCell / PriceCharting | No usable public API, or the wrong product niche |
| Carousell / Mudah / Lelong | No official API |
| **Apify Carousell scrapers** | **Chosen.** A paid vendor that returns live Carousell Malaysia listings. It scrapes public listings, so Carousell's terms of service are the business's call |

Two Apify Actors are used:
- **`parseforge/carousell-scraper`** is tried first. It supports Malaysia and a "used only" filter. About US$22.66 per 1,000 listings.
- **`devcake/carousell-scraper`** is the fallback. It supports Malaysia. About US$2.50 per 1,000 listings.

### How a lookup works (`TradeInMarketPriceApiClient.php`)
1. It searches Carousell Malaysia for brand + model + storage size, for example "Apple iPhone 13 128GB".
2. It runs the first Actor. Each Actor gets its own input format.
3. It drops "Brand new" listings and any price that isn't in RM.
4. It takes the **median** price, so outlier and fake listings don't skew it.
5. If there are fewer than 3 usable prices, it tries the next Actor.
6. If nothing works, the form uses the manually entered price.

If a provider returns a price range, the range is reduced to its midpoint, so every source gives one number.

### Configuration
Everything is controlled through `.env` and read by the `tradein_market_price_api` block in `Modules/Recommerce/Config/config.php`:

| Setting | Default | Purpose |
|---|---|---|
| `RECOMMERCE_TRADEIN_APIFY_TOKEN` | *(empty)* | Your Apify API token (**required**) |
| `RECOMMERCE_TRADEIN_APIFY_ACTORS` | `parseforge~carousell-scraper,devcake~carousell-scraper` | Order to try the Actors. Put devcake first to save money |
| `RECOMMERCE_TRADEIN_APIFY_COUNTRY` | `my` | Carousell region |
| `RECOMMERCE_TRADEIN_APIFY_MAX_ITEMS` | `20` | Listings pulled per lookup |
| `RECOMMERCE_TRADEIN_APIFY_TIMEOUT` | `120` | Seconds to wait per Actor |
| `RECOMMERCE_TRADEIN_APIFY_MIN_SAMPLES` | `3` | Minimum prices needed before a median is trusted |

After changing `.env`, run `php artisan config:clear`.

### `.env` repair
While the token line was being added, a Claude Code hook wrote two log lines (`[INFO] Recording command outcome: ...` and `[OK] Command outcome recorded`) into `.env`, which broke `php artisan config:clear`. Both lines were removed. The token line was not affected, and Laravel confirms it loads the token.

### Known limits
- **Speed:** a live lookup can take 30 seconds to 2 minutes while the scraper runs.
- **Cost:** about US$0.45 per estimate with parseforge first, or about US$0.05 with devcake first.
- **Local SSL:** this machine's PHP is missing its CA certificate bundle, so HTTPS calls to Apify may fail locally until `curl.cainfo` and `openssl.cafile` in `php.ini` point to a `cacert.pem` file. Production servers normally have this already.
- **Not yet tested live:** the code has passed a syntax check, and it correctly returns nothing when no token is set. The first real lookup will confirm the parseforge output field names.
