# SaverBro Recommerce OS — Delivery Roadmap

## 1. Delivery policy

Build and prove vertical slices. A milestone is complete only when code, database effects, permissions, runtime UI, hardware where applicable, reconciliation, documentation, and rollback evidence all pass in the stated environment.

Statuses used later should be: `PROPOSED`, `IN PROGRESS`, `BLOCKED`, `PASSED LOCALLY`, `ALPHA READY`, or `RELEASED`. A design document or successful build is not an implemented feature.

## 2. Gate 0 — provider containment and runtime baseline

**Outcome:** a trustworthy baseline on which architecture can be implemented.

Scope:

- contain the unavailable provider Repair integration and prevent stale provider/cache loading;
- verify all enabled module sources/assets and version provenance;
- establish PHP/Composer/database/frontend runtime matching deployment;
- run migration, smoke, dependency, and current automated checks;
- profile sanitized real data and hardware/deployment constraints;
- record the separate legacy Repair preserve/migrate/archive decision if production data exists.

Exit evidence:

- source inventory with hashes and licenses;
- runnable clean-environment build/test record;
- database/version and deployment topology record;
- missing/dead code and vulnerability register;
- approved provider-containment and legacy-data disposition.

No Recommerce production enablement should start before provider containment, the runtime baseline, and the approved data/hardware profile. SaverBro-owned Repair implementation does not depend on recovering provider source.

## 3. Milestone 0 — module foundation and safety rails

**Outcome:** inactive Recommerce module with secure, testable foundations.

Scope:

- module scaffold, configuration, feature flags, permissions;
- business/location scoping helpers and policy tests;
- permanent events/outbox, idempotent command shell, expected-version convention;
- migration conventions, indexes, test factories/builders;
- operational health and audit surfaces.

Exit evidence:

- module can be enabled with no behavior change to POS;
- cross-business/location negative tests pass;
- retry and outbox transaction tests pass;
- deployment/disable procedure rehearsed.

## 4. Milestone 1 — first vertical slice: receive, label, scan, reconcile

**Outcome:** one new tracked unit can enter stock with a permanent identity and remain equal to POS aggregate stock.

Scope:

- canonical device, identifiers, business ownership/custody;
- concurrency-safe device-code and opaque-token issuance;
- serialization profile and legacy-unserialized baseline;
- purchase-line receiving assignment;
- single/batch device labels using existing print geometry patterns;
- keyboard-wedge scan resolver and device detail;
- device movement ledger and reconciliation view;
- one approved category/location behind feature flag.

Exit evidence:

- actual purchase receipt through rendered browser UI;
- distinct QR/Code128 label per physical unit, scanned with approved hardware;
- duplicate identifier blocked without losing valid prepared units;
- concurrent/retry tests show one receipt only;
- POS aggregate = tracked devices + legacy balance before and after;
- rollback/disable rehearsal passes.

This is the recommended Alpha foundation.

## 5. Milestone 2 — registry and scan hardening

**Outcome:** staff can safely find and act on physical devices across approved contexts.

Scope:

- registry, masked identifiers, ownership/custody/timeline;
- public QR login resolver, token rotation, rate limiting;
- camera scanning with capability/fallback matrix;
- contextual action resolver and batch-scan ergonomics;
- print manifests/reprint audit;
- exception queues for identity and location conflicts.

Exit evidence:

- unauthorized/cross-business scans disclose nothing;
- scanner/camera/browser/printer matrix passes;
- token rotation invalidates old QR without changing device code;
- registry results reconcile to source records.

## 6. Milestone 3 — internal refurbishment slice

**Outcome:** an acquired SaverBro device progresses from diagnosis through parts, cost, QC, and sellable stock.

Scope:

- shared repair job/state foundation;
- diagnostic templates, observations, faults, grades;
- technician assignments/actions and manager approval threshold;
- parts reservation and POS stock-adjustment consumption;
- append-only device cost ledger;
- QC, warranty evidence, and return to `IN_STOCK`;
- technician/manager workbench.

Exit evidence:

- one real-like internal job completes end to end;
- part quantity decrements once through POS adjustment;
- actual device cost ties to source records;
- QC failure/rework and concurrency cases pass;
- final sellable device reconciles.

## 7. Milestone 4 — tracked POS sale and return

**Outcome:** checkout cannot sell the wrong or unidentified tracked unit.

Scope:

- POS line device-assignment UI and preflight;
- transactional sale movement and ownership period;
- invoice device/warranty reference;
- reservation handling;
- return/cancellation and condition/disposition flow;
- sale/receive concurrency tests.

Exit evidence:

- finalization blocks missing/wrong-location/wrong-variation device;
- one sale decrements aggregate and sells exactly one device;
- retry cannot double-sell;
- return creates inverse history without rewriting original records;
- ordinary non-tracked POS regression remains green.

## 8. Milestone 5 — customer repair service

**Outcome:** a customer-owned device completes intake, quote approval, repair, POS billing, QC, and collection on the shared repair model.

Scope:

- contact-linked intake/custody/accessories/evidence;
- customer device without catalog product;
- quote versions and approval evidence;
- customer parts installed-pending-billing;
- POS service/part invoice and payment links;
- privacy controls, safe notification outbox, collection;
- repeat/warranty visit linkage.

Exit evidence:

- rendered browser flow passes for counter, technician, QC, manager, cashier;
- unapproved work and unauthorized data access are blocked;
- customer part posts once through POS sale and job closes with none pending;
- payment/return behavior ties to POS;
- same canonical device is reused on a repeat visit.

## 9. Milestone 6 — tracked transfers and multi-location custody

**Outcome:** exact units move between locations with sender/receiver proof.

Scope:

- tracked manifest, dispatch, receive, discrepancy workflow;
- minimal core transfer status event/guard;
- in-transit custody and wrong-location exception;
- cancellation/return transfer;
- multi-location permissions and reconciliation.

Exit evidence:

- aggregate transfer legs and device movements match;
- missing/extra/substituted unit blocks inaccurate completion;
- concurrent sale cannot claim an in-transfer unit;
- existing non-tracked transfers regress cleanly.

## 10. Milestone 7 — trade-in and acquisition

**Outcome:** an existing or new customer-owned device can be acquired without losing its identity/history.

Scope:

- intake evidence and registry reuse;
- offer versions/approval thresholds;
- approved acquisition/accounting integration decision;
- ownership transition to SaverBro;
- quarantine/refurbishment routing;
- rejection/return and privacy retention.

Exit evidence:

- previous SaverBro sale/repair device is reused, not duplicated;
- accepted offer has POS/accounting source and ownership transition;
- rejected device returns with custody closed;
- no estimated offer is represented as booked cost.

## 11. Milestone 8 — warranty, controls, and operational scale

**Outcome:** Alpha controls mature for broader controlled release.

Scope:

- warranty claims/coverage decisions;
- advanced reconciliation and exception approvals;
- operational reporting, retention jobs, exports;
- performance/lock tuning, queue monitoring, backup/restore;
- security review, accessibility, disaster and incident exercises;
- cohort expansion tooling/training.

Exit evidence:

- load/concurrency objectives pass on production-like topology;
- retention/security/privacy approvals documented;
- backup/key/token incident rehearsals pass;
- cohort metrics and severity-one exception rate meet approved thresholds.

## 12. Deferred horizons

Only after controlled release evidence:

- offline-capable trusted scanning;
- native mobile apps;
- automated device telemetry/data erasure integrations;
- customer self-service portal and digital signatures;
- courier logistics and external marketplace channels;
- predictive grading/pricing/AI diagnosis;
- component-level serialized parts genealogy;
- accounting inventory-valuation redesign.

Each needs a separate business case, security/privacy assessment, and source-of-truth decision.

## 13. Cross-milestone gates

Every milestone requires:

- exact in/out scope and feature flags;
- source-reviewed design and migration;
- unit, feature, concurrency, authorization, and regression tests;
- browser proof for each role;
- hardware proof when scanning/printing is affected;
- stock/financial reconciliation where relevant;
- observability, support procedure, training, rollback;
- named owner and acceptance record with date/build/environment.

## 14. Suggested success measures

Measures must be baselined before targets are approved:

- percentage of tracked receipts reconciled without exception;
- duplicate identity prevention rate and unresolved conflicts;
- scan-to-record latency and first-scan success by hardware;
- stock mismatch count/age;
- repair turnaround by state and job type;
- installed-unbilled part count/age;
- QC failure/rework and warranty-return rates;
- checkout/transfer blocks by valid versus false-positive reason;
- unauthorized access test pass rate;
- rollback/recovery time in rehearsal.

Do not claim production readiness from local checks or synthetic data alone.
