# SaverBro Recommerce OS — Repair Service Architecture

## 1. Decision

Use one canonical device registry and one shared repair-job domain for:

- `INTERNAL_REFURBISHMENT`: SaverBro-owned stock prepared for resale; and
- `CUSTOMER_REPAIR`: customer-owned devices accepted for diagnosis/service.

The job type selects policies and allowed paths. It does not create two incompatible repair systems. Diagnostics, technician work, parts, QC, timeline, custody, and warranty evidence are shared.

SaverBro will own this repair domain in `Modules/Recommerce`; it is not an adaptation of the unavailable vendor `Modules/Repair` module. The existing provider status is now disabled in this checkout. Treat any provider artifacts as a legacy-containment issue only: recover its source only when a live deployment needs vendor-job migration, route takeover, or controlled retirement. No design here assumes unavailable code behavior, and the owned domain must not write vendor Repair tables or reuse vendor internal entities.

## 2. Bounded contexts

| Concern | System of record |
|---|---|
| Device identity, ownership periods, custody, lifecycle | Recommerce module |
| Repair job, diagnosis, quote versions, approvals, actions, QC | Recommerce module |
| Product/variation, part stock, sale invoice, payment, tax, accounting | Ultimate POS core |
| Customer/supplier identity | Ultimate POS contacts |
| Actual internal device/job cost detail | Recommerce append-only cost ledger, linked to POS sources |
| Activity/security log | permanent Recommerce events plus existing activity log as secondary evidence |

## 3. Job model

Every job has an immutable code, type, canonical `device_id`, business/location, intake contact where relevant, state, priority, assignments, custody context, policy/template versions, source links, expected version, and timestamps.

Customer devices may have no `product_id` or `variation_id`. A free-text make/model/category plus normalized identifiers is sufficient. If SaverBro later acquires that device, the same row gains business ownership and, before sellable stock, an appropriate catalog variation.

## 4. State machine

Core work states:

`RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → WAITING_PARTS → IN_REPAIR → QC → READY → CLOSED`

Cancellation, customer decline, and an unrepairable finding are outcome reasons, not parallel workflow states. Store a nullable `resolution_code` (`COMPLETED`, `CANCELLED`, `DECLINED`, `UNREPAIRABLE`) and move the physical job through `READY`/handover to `CLOSED`. This prevents a “declined” device from becoming stranded outside the collection workflow.

Allowed transitions:

| From | To | Notes |
|---|---|---|
| `RECEIVED` | `DIAGNOSIS`, `READY` | direct `READY` requires cancellation reason and resolved custody/parts |
| `DIAGNOSIS` | `AWAITING_APPROVAL`, `WAITING_PARTS`, `IN_REPAIR`, `READY` | internal policy may skip customer approval; `READY` records decline/cancel/unrepairable outcome |
| `AWAITING_APPROVAL` | `WAITING_PARTS`, `IN_REPAIR`, `READY` | approved quote required for work; decline/cancel goes to `READY` |
| `WAITING_PARTS` | `IN_REPAIR`, `AWAITING_APPROVAL`, `READY` | changed scope may require reapproval; parts must be resolved on exit |
| `IN_REPAIR` | `WAITING_PARTS`, `AWAITING_APPROVAL`, `QC`, `READY` | `READY` without QC requires authorized non-completion outcome |
| `QC` | `IN_REPAIR`, `READY` | pass to `READY`, fail to rework |
| `READY` | `CLOSED`, `IN_REPAIR` | collection/internal release closes; newly found fault reopens work |
| `CLOSED` | none | create a linked new job for later work |

All other direct transitions are forbidden. Examples: `RECEIVED → CLOSED`, `AWAITING_APPROVAL → QC`, `WAITING_PARTS → CLOSED`, `IN_REPAIR → CLOSED`, and any transition out of `CLOSED`.

Rules:

- internal refurbishment may skip customer approval and collection, but manager cost approval policies may insert a gate;
- customer repair requires active custody from intake until collection/return;
- no work beyond allowed diagnosis while customer quote approval is pending;
- `QC → IN_REPAIR` requires failure reasons;
- closure requires passed/waived QC, parts resolved, financial policy satisfied, custody ended or valid internal stock state, and required evidence;
- closed jobs are immutable; repeat visits create linked new jobs;
- transitions are commands with prerequisites, not editable status fields.

Quote status and payment status remain separate from job state.

## 5. Intake

### Shared

- scan/search registry before device creation;
- capture visible identifiers with provenance;
- record condition, evidence, accessories, reported symptoms, and intake user/location;
- establish custody and print safe labels;
- record terms/policy versions.

### Customer-specific

- link existing/new POS contact;
- record customer statements distinctly from staff observations;
- capture notification preference and collection authority;
- do not store device passcodes by default;
- if temporary secret storage is explicitly approved, use encrypted, separately permissioned, expiring storage with reveal audit and automatic destruction.

### Internal-specific

- link purchase/trade-in/return source;
- ensure SaverBro ownership is active;
- assign provisional catalog variation or quarantine until classification.

## 6. Diagnostics and grading

Versioned templates contain category/model applicability, checks, units, acceptable ranges, required evidence, and grading policy references.

Submitted diagnostic sessions are immutable snapshots. Observations distinguish `PASS`, `FAIL`, `NOT_TESTED`, and `NOT_APPLICABLE`; free text alone is insufficient for required checks. Faults are explicit records linked to observations/evidence.

Grade is a signed human conclusion with rubric version and override reason. Preserve provisional intake grade, post-repair grade, and sale grade rather than overwriting one field.

## 7. Quote and approval

A quote version includes diagnostic snapshot, parts, service/labor lines, tax/discount assumptions, estimated completion range, warranty terms, exclusions, currency, expiry, and terms version.

Once sent, a version is immutable. Changes create a new version. Approval records exact version, decision, identity/channel evidence, actor, time, and notes. Alpha may record approval evidence from existing channels, but should not claim cryptographic customer signing unless implemented and verified.

Estimates are not actual costs and are not posted to accounting until the POS transaction is finalized.

## 8. Work execution

- Assign one or more technicians with roles and intervals.
- Repair actions state fault, procedure, result, time, evidence, and performer.
- Labor time may inform cost but does not silently become payroll or accounting.
- External services require supplier/source evidence and approval where configured.
- Scope increase may block work and require a new quote.
- Technician submission to QC locks the work revision; further changes reopen repair.

## 9. Parts integration

Part master and quantities remain Ultimate POS products/variations and location stock.

Customer repair path:

1. reserve part in Recommerce;
2. record issue/install without aggregate consumption;
3. create projected ordinary POS product line;
4. on final invoice, POS decrements aggregate and maps purchase cost using existing logic;
5. atomically link sale line to usage and mark billed.

Internal refurbishment path:

1. reserve/issue/install;
2. post a standard POS stock adjustment at the location;
3. link adjustment line to usage;
4. append actual device cost using the resolved stock cost source.

Removed/replaced/unused/reversed parts have explicit dispositions. Do not write `qty_available` directly.

## 10. POS invoice, payment, and accounting integration

Customer repair billing uses a linked Ultimate POS sell transaction, preferably distinguished by an approved `sub_type`/module metadata mechanism:

- stock part lines are normal POS product variations;
- labor/service/diagnostic lines use configured non-stock/service products;
- POS remains authority for taxes, discounts, invoice numbering, payments, returns, and account transactions;
- Recommerce stores stable links and display snapshots, not a competing balance ledger.

Any core subtype/hook change must remain generic and module-safe. Recommerce must not fork payment or accounting calculations.

Internal refurbishment does not create customer revenue. Its cost ledger supports unit economics and inventory decision-making but integration with official accounting/inventory valuation is a separate approved policy decision.

## 11. QC and handover

QC is a versioned checklist bound to device category/job type. It records tester, results, evidence, exceptions, and final grade. Optional separation-of-duties prevents the lead technician from self-approving.

Customer handover requires job/collector verification, device/accessory checklist, financial-policy pass or recorded override, signature/evidence policy, custody closure, and warranty delivery. Internal handover to stock requires business ownership, sellable variation, location, passed QC, label, and successful reconciliation.

## 12. Warranty

Preserve Ultimate POS warranty definitions for catalog/sale presentation, but add service-instance coverage evidence:

- policy/version applied;
- coverage start/end and covered components/actions;
- originating sale/job;
- exclusions and customer acknowledgement;
- claims as new linked repair jobs;
- coverage decision and reason.

Warranty decisions never rewrite original job or invoice.

## 13. Cost model

The append-only cost ledger targets exactly one of device or repair job:

- internal parts, labor, external service, acquisition allocation, logistics, and approved overhead target the device;
- customer repair actual parts/labor/external cost target the job;
- entries link to POS lines/adjustments/purchases or approved manual source;
- corrections append reversals and replacements;
- estimated quote lines remain separate;
- revenue and payments stay in POS.

This prevents double-counting a customer repair cost on both job and device while retaining lifetime service history.

## 14. Privacy and sensitive data

- customer contact fields stay in the contact system;
- repair pages reveal only minimum necessary data by role;
- manufacturer identifiers are masked except for operational roles;
- intake images may contain personal information and require retention/access controls;
- passcodes are not general notes and not included in events, labels, exports, notifications, or search indexes;
- file downloads require authorization at request time;
- notification content uses job code and safe wording.

## 15. Notifications

Outbox-triggered notifications may cover quote ready, approval recorded, delay, ready for collection, and completion. Channel integrations are adapters outside the transaction. Delivery failure does not change job state; retry and manual follow-up appear in an exception queue.

Alpha can operate with recorded manual contact actions while messaging channels remain undecided.

## 16. Permissions

Repair permissions are action-specific: intake, view job, view customer, assign, diagnose, grade, prepare/send quote, record approval, start work, reserve/consume part, view cost, QC, close, and override collection. They intersect with business, location, assignment, job type/state, and segregation-of-duty rules. Technicians do not automatically receive customer contacts, costs, approvals, exports, or temporary secrets. Counter staff cannot alter diagnosis/QC, and possession of a job QR never grants access. The full permission catalog and threat controls are in `RECOMMERCE_SECURITY_AND_PERMISSIONS.md`.

## 17. Timeline

Every intake, custody change, assignment, diagnosis submission, fault, quote version/decision, work transition, part reservation/consumption/reversal, cost, QC, invoice/payment link, notification attempt, collection, warranty decision, and correction appends a structured Recommerce event. The repair view projects these events with POS source links. Existing general activity logs may mirror summaries but cannot replace this permanent repair/device timeline.

## 18. Future WhatsApp and customer portal integration

Future channels should consume minimal, versioned outbox messages and call the same authenticated quote/approval/status services; they must not write repair tables directly. A customer portal requires verified customer-to-job authorization, expiring single-purpose invitations, rate limits, safe status vocabulary, consent/retention, and no internal costs or technician notes. WhatsApp requires an approved provider, template/consent handling, delivery evidence, webhook signature/replay checks, and a decision on whether a reply is merely evidence or a legally sufficient approval. Neither integration belongs in Alpha.

## 19. Reporting

Operational measures: intake volume, turnaround by state, diagnosis-to-approval time, quote approval rate, repeat faults, parts wait, QC failure, warranty return, technician workload, installed-unbilled parts, and internal refurbishment cost-to-value.

Reports state whether values are operational snapshots, estimates, POS revenue, or actual cost. Authorization/location filters apply to aggregates and exports.

## 20. Required integration tests

- same device reused across sale, customer repair, and later trade-in;
- customer device without catalog product completes repair;
- internal and customer job policy divergence on one state engine;
- quote version cannot change after approval;
- unapproved scope cannot enter repair;
- customer part billed exactly once through POS;
- internal part consumed exactly once through adjustment;
- QC failure/rework and separation of duties;
- payment/return leaves POS and job summaries consistent;
- passcode/identifier/contact isolation by role and business;
- concurrent technicians cannot overwrite job transition;
- closure prerequisites and custody handover.
- cancellation before and after part issue/consumption, including reservation release and explicit reversal;
- invalid, revoked, and retired job/device QR behavior;

## 21. Alpha exclusions

No native mobile app, offline mutations, automated device telemetry, AI diagnosis/grading, component-level refurbishment manufacturing, multi-vendor marketplace, courier logistics engine, or replacement of POS accounting/payment. These require later evidence and explicit decisions.
