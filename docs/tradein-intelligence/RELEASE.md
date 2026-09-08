# Integrated Trade-In staging increment — 8 September 2026

This increment implements shared exact-variant quoting, governed market evidence import and processing, native inventory signals, bounded policy adjustments, and PHP resale-model training/inference inside SAVERPOS. It does not activate market, demand or model customer pricing. Production is unchanged.

## Authority and eligibility

The existing Trade-In Acquisition permissions protect the evidence workspace. It versions reference/policy/evidence records; it creates no catalogue variation, acquired Device, purchase, payment or stock movement. Final offers and acquisition remain in the existing native workflow. Website intake alone continues to preserve customer ownership.

Customer phone/tablet quotes require a verified exact variant mapping to an existing native variation in the connector scope, an approved and applicable category policy, a fresh approved reference or explicitly enabled eligible alternative, and complete acceptable declarations. Galaxy S22 RM1,500 and iPad 9 RM1,050 remain incomplete model-level resale references. No storage/connectivity scope has been inferred. An unsupported result uses team review.

## Internal contracts

All JSON imports use `{kind,target,data}` and an authenticated approver's reason. Supported kinds: VARIANT, REFERENCE, POLICY, SOURCE, OBSERVATIONS. Imports are versioned, tenant scoped, and capped at 250 observations / 1MB upload. VARIANT mappings additionally require native cohort write authority. Source URLs are validated against an explicit HTTPS host allowlist and never fetched.

VARIANT: variant_id, model_id, category, brand, model_label, specification, native_variation_id, identity_provenance, status. Required specifications: laptop processor/RAM/storage; phone storage; tablet storage/connectivity.

REFERENCE: MYR amount_minor, condition_basis, source, approval_reference, policy_id, observed_at, expires_at, status. No model-level reference is silently expanded to a variant.

POLICY: version, approval_reference, status, categories, explicit reference_order, commercial rules, condition_basis, market_target, market_min_confidence, demand rules. Commercial amounts use integer sen; the existing calculator applies them. A reference condition with a nonzero duplicate condition deduction is rejected. No commercial defaults are auto-approved for phones/tablets.

SOURCE: upstream_source, permission_status, permission_reference, derived_estimates_allowed, retention_days, valid_until, allowed_hosts, owner, access_method. Register actual licence scope before importing any observations. No marketplace connector is claimed merely because an import adapter exists.

OBSERVATIONS: listing_id, variant_id, source, upstream_source, seller_id or null, duplicate_group or null, title, URL, observed_at, evidence_reference, segment, region, condition, warranty, price_type, decimal-string price, availability, verification, specification, defects, lock_status. Shipping and mandatory fees remain null when unknown. Original observation times are normalized to UTC without becoming newly observed. Private raw evidence is not sent to the website.

## Market processing

Exact specification and segment matching precede deduplication. Latest observation per listing/variant, explicit duplicate groups, and one eligible listing per known independent seller prevent repeated observations from inflating the sample. Unknown seller independence is excluded. Own listings, fixtures, parts, deposits, instalments, conditional prices, unavailable variants and uncertain lock/defect declarations are excluded with reasons. Prices are unconditional item asking prices excluding shipping/fees, not completed sales or landed prices.

P25 / median / P75 are distribution summaries. Minimum five independent eligible listings; HIGH additionally requires ten sellers/listings, two contributing upstream platforms, seven-day freshness, acceptable IQR dispersion and no unresolved extreme-price flags. Single-source cannot be HIGH. Fourteen-day eligibility and licence retention expiry apply. Legitimate extreme prices are flagged and retained, not silently deleted. These are initial engineering heuristics, not calibrated accuracy claims.

Snapshots preserve previous version ID and central change. A customer uses stored references only. Permission revocation, expiry, insufficient confidence, changed segment or disabled market pricing retains the approved baseline where valid. Expired raw observations are removed by scheduled retention work.

## Native demand signals

Read-only projection uses native final, unreversed sales and owned AVAILABLE / ON_HAND / LOCATION devices with no transfer. Demo/QA estates are excluded. Unknown commitments and unavailable stock-exposure history stay unknown, keeping commercial effects neutral. Approved policies can apply exactly one overstock, low-stock-with-demand or low-demand delta; freshness, magnitude and commercial ceilings bound it. No adjustment policy has been approved by this release.

## Resale model

The PHP model fits log resale-price residuals against the actual stored deterministic resale reference. Version `log-residual-condition-storage-2` uses known storage and pre-valuation physical condition. It deliberately excludes unknown age/RAM rather than filling them with invented values. Immutable valuation snapshots capture these features for future legally available native completed-sale outcomes.

Target: net item sale amount excluding tax/shipping, with unsupported discounts, returns/cancellations/reversals and QA outcomes excluded. Device-level separation, chronological train/calibration/test splits and same-time boundary exclusion prevent leakage. Dataset SHA, fixed algorithm, fixed epochs and fixed criteria make training reproducible.

Initial predeclared criteria: at least 60 eligible rows, 30 train / 8 calibration / 8 test after splitting; eight training records for an inferred variant; held-out MAE <=98% of baseline; p90 overvaluation risk <=baseline. An insufficient dataset produces no eligible model. Candidate is SHADOW; only the latest eligible unexpired candidate may be promoted, with reason and audit event. Rollback clears promotion; model pricing also needs its independent configuration flag and explicit approved policy order. Residual intervals are empirical held-out error summaries, not a guaranteed accuracy percentage. Tests use isolated synthetic outcomes solely to validate mechanics and are not model acceptance evidence.

## Hosting and deployment

- Existing cPanel staging deployment paths only; deploy native backend before website use of the optional canonical catalogue capability.
- New migration creates only `recommerce_trade_in_intelligence`.
- Bootstrap requires a successful private mysqldump archive with verified compression hash before the table is introduced. Backup is outside public/ under storage/app/private/staging-backups with restrictive permissions. A missing utility/permission aborts before migration. Host restore verification is still required; archive integrity alone is not a restore test.
- Deployment writes storage/app/tradein-release.json. Authenticated workspace checks actual runtime file hashes against this commit manifest.
- `recommerce:tradein-intelligence --limit=3` is scheduled every five minutes by Laravel when staging jobs are enabled. It needs the existing server scheduler to invoke `schedule:run`. Requests coalesce, attempts are capped at three, failures are classified without raw private diagnostics, and customer requests never run training/collection jobs.
- Independent flags: RECOMMERCE_MARKET_PRICING_ENABLED, RECOMMERCE_DEMAND_PRICING_ENABLED, RECOMMERCE_MODEL_PRICING_ENABLED; all default false. Existing Photo AI configuration and public access remain separately controlled.

## Verification status at preparation

Local: 14 engine tests / 61 assertions; 12 integration tests / 45 assertions; 32 existing native Trade-In tests / 320 assertions; 5 workspace contracts / 64 assertions passed. SQLite migration down/up plus record restoration passed. New PHP files passed syntax checks. Website: 62 Trade-In checks passed, including exact phone/tablet authority handoff, shortened reference validity and no exposed review-only estimate. Actual hosted deployment, MySQL restoration, scheduler operation and the staff Photo AI panel still require verification after publication.

## Activation dependencies and production decision

NO-GO for the complete five-build production release. Zero verified operational market sources; no real evidence-to-reference-to-estimate acceptance. No approved exact phone/tablet variant policies have been supplied or inferred. No validated commercial stock-exposure dataset/adjustment policy. No eligible real ML dataset/evaluation or promoted production model. Engineering tests are not a substitute for these inputs. Continue staging integration and runtime verification; do not ask for another milestone approval.
