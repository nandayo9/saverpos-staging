# SaverBro Recommerce OS — Security and Permissions

## 1. Security objectives

1. Isolate every record and lookup by Ultimate POS `business_id`.
2. Enforce location custody and assigned-work boundaries independently from menu visibility.
3. Prevent stock, ownership, job, cost, and approval changes outside explicit commands.
4. Keep customer, device-identifier, secret, and financial data to the minimum role that needs it.
5. Make sensitive reveals, overrides, exports, and corrections attributable and reviewable.
6. Preserve stock and event integrity under retries and concurrent users.

Spatie permissions and existing business/location patterns are the starting point, not complete protection. Every query and command requires server-side scope enforcement.

## 2. Proposed roles

Roles are deployable templates, not hard-coded names. Businesses may compose permissions while protected combinations and segregation rules remain enforced.

| Role template | Primary scope |
|---|---|
| Counter intake | customer/device intake, scan, print, collection preparation |
| Receiver | purchase receiving, device identity, labels |
| POS cashier | sale assignment and ordinary POS completion |
| Technician | assigned diagnosis/actions/parts requests |
| QC inspector | QC and grading within assigned location |
| Repair manager | assignment, quote/work approval, exceptions |
| Inventory manager | transfer, correction, reconciliation, serialization policy |
| Finance/management | costs, POS financial links, reports, overrides |
| Recommerce administrator | templates, policy, permissions, token/security administration |
| Auditor | read-only evidence and export where separately granted |

## 3. Permission catalog

### Devices

- `recommerce.device.view`
- `recommerce.device.view_identifier`
- `recommerce.device.create`
- `recommerce.device.correct_identifier`
- `recommerce.device.change_classification`
- `recommerce.device.view_cost`
- `recommerce.device.export`
- `recommerce.device.rotate_token`
- `recommerce.device.print_label`

### Stock and ownership

- `recommerce.receiving.prepare`
- `recommerce.receiving.post`
- `recommerce.trade_in.prepare`
- `recommerce.trade_in.approve`
- `recommerce.sale.assign_device`
- `recommerce.transfer.dispatch`
- `recommerce.transfer.receive`
- `recommerce.stock.reconcile`
- `recommerce.stock.resolve_exception`
- `recommerce.ownership.correct`

### Repair

- `recommerce.repair.intake`
- `recommerce.repair.view`
- `recommerce.repair.assign`
- `recommerce.repair.diagnose`
- `recommerce.repair.grade`
- `recommerce.repair.quote_prepare`
- `recommerce.repair.quote_send`
- `recommerce.repair.approval_record`
- `recommerce.repair.start_work`
- `recommerce.repair.part_reserve`
- `recommerce.repair.part_consume`
- `recommerce.repair.qc`
- `recommerce.repair.close`
- `recommerce.repair.collection_override`
- `recommerce.repair.view_customer`
- `recommerce.repair.view_cost`

### Administration/audit

- `recommerce.template.manage`
- `recommerce.policy.manage`
- `recommerce.security.manage`
- `recommerce.audit.view`
- `recommerce.audit.export`
- `recommerce.outbox.retry`

Use separate `view`, prepare, post, approve, override, reveal, and export permissions. A broad module permission must not imply all of them.

## 4. Scope rules

Every domain table carries `business_id` directly or has an enforced unambiguous parent. Prefer direct columns for high-risk/large tables to make tenant filtering and constraints inspectable.

Authorization intersection:

`permission ∩ business ∩ allowed locations ∩ object assignment/policy ∩ current state`

- Location-scoped users see devices currently in allowed custody locations and jobs owned by those locations, subject to explicit transfer/central-repair policies.
- Technicians see assigned jobs plus permitted queue metadata; assignment does not grant financial or customer contact access.
- Cross-location transfer users see only the minimum expected manifest information.
- Customer collection lookup requires location/job permission, not merely possession of QR/job code.
- Background jobs carry business and initiating-user context and re-authorize sensitive effects.

## 5. Segregation of duties

Configurable controls:

- technician cannot approve own QC;
- trade-in offer preparer cannot approve above threshold;
- identifier/ownership correction requires a second approver above risk threshold;
- reconciliation writer is separate from reviewer;
- collection with outstanding balance needs management permission and reason;
- security administrator cannot silently read repair secrets.

The system records both actors where dual control applies.

## 6. Sensitive-data classification

| Class | Examples | Controls |
|---|---|---|
| Public-safe | opaque resolver URL, SaverBro code | still rate-limited; no record details |
| Internal operational | lifecycle, location, job state | authenticated, scoped |
| Device identifier | serial, full IMEI/service tag | masked, reveal permission/audit, encrypted where policy requires |
| Customer personal | contact, intake images, messages | need-to-know, retention/export controls |
| Repair secret | temporary passcode/unlock secret | avoid storage; otherwise field encryption, separate permission, expiry, reveal audit |
| Financial/confidential | acquisition cost, margins, internal labor/cost | restricted role/report/export |
| Security secret | raw QR token, encryption keys | token raw value not stored; keys outside database/source |

Do not put sensitive values in labels, route parameters after resolution, browser analytics, exception messages, activity descriptions, notifications, or exported default columns.

## 7. Authentication and sessions

Reuse Ultimate POS authenticated web sessions and CSRF protection for browser actions. Do not treat a QR token as authentication. Any future scanner/mobile API uses scoped first-party credentials with revocation, rotation, device trust, and rate limits; it must not reuse broad personal access tokens by convenience.

Sensitive actions may require recent authentication. Session invalidation, password/MFA policy, and production identity provider remain deployment-level decisions.

## 8. QR and scan security

- 128+ bits cryptographically secure random token entropy;
- token hash at rest and exact match only;
- revocation and rotation with event history;
- generic unauthorized/not-found behavior;
- separate public/authenticated rate limits;
- approved HTTPS domain allowlist;
- no raw tokens in logs/referrers;
- authenticated mutation endpoint with permission, state, expected version, and idempotency;
- scan input length/format bounds and output encoding.

## 9. Input, file, and output controls

- validate identifiers by type but preserve defensible original display value separately from normalized comparison value;
- parameterized ORM/query builder, allowlisted sort/filter fields, escaped output;
- sanitize user-generated rich text or use plain text;
- uploaded evidence uses MIME/content validation, size limits, randomized storage name, malware scanning/quarantine, and authorized download controller;
- never serve private repair files from predictable public paths;
- remove metadata from images where policy requires;
- spreadsheet exports defend against formula injection;
- print/PDF templates escape all variable content.

## 10. Audit and event integrity

The permanent Recommerce event stream records business, actor/service identity, action, entity, before/after-safe metadata, source command/idempotency, correlation, reason, time, and linked evidence. It is append-only to application roles.

Existing Spatie activity logging can mirror summaries, but its configured retention and general-purpose nature mean it is not the sole device timeline.

Never log passcodes, full access tokens, raw QR tokens, payment credentials, or unnecessary personal data. High-risk events include identifier reveal/correction, ownership correction, token rotation, cost export, overrides, stock exception resolution, quote approval evidence change, and secret reveal/destruction.

Database-superuser tamper resistance, event signing, external log retention, and SIEM are production-infrastructure decisions.

## 11. Concurrency and integrity controls

- database unique constraints on business-scoped codes, normalized identifier hashes, active token hashes, POS line assignments, and idempotency keys;
- foreign keys where legacy schema compatibility permits;
- explicit row locks for device, serialization balance, variation-location aggregate, reservation, and job transition;
- deterministic lock ordering;
- `expected_version` optimistic check for UI edits;
- idempotent command outcome storage;
- append-only movements/costs/events with reversals;
- after-commit processing for notifications and projections;
- scheduled plus on-demand reconciliation.

Authorization must be checked again after acquiring locks when state/scope can have changed.

## 12. Threat scenarios

| Threat | Primary controls |
|---|---|
| Enumerate devices from QR/code sequence | opaque token, generic response, rate limits |
| Cross-business identifier lookup leaks existence | tenant-filter first, indistinguishable result |
| Cashier sells wrong-location device | state/location/variation preflight plus locks |
| Double scan/retry consumes stock twice | idempotency, uniqueness, stored outcome |
| Technician inflates or hides parts usage | reservation/issue/bill links, immutable events, approval thresholds |
| Staff accesses customer photos/passcode | separate permissions, encryption/expiry, download/reveal audit |
| Label PDF shared externally | authorization, short-lived delivery, safe content |
| Concurrent transfer/sale claims same unit | device lock, eligible-state constraint, unique active movement/reservation |
| CSV injection or sensitive export | export permission, masking, formula neutralization, audit |
| Background notification leaks details | safe templates, minimal payload, adapter controls |

## 13. Security verification gates

Before Alpha:

- permission matrix feature tests for each role and cross-location case;
- cross-business negative tests on every endpoint and resolver;
- identifier/token non-disclosure tests;
- CSRF/session, mass-assignment, file upload/download, export, XSS, and URL parsing tests;
- concurrency tests for receive/sell/transfer/parts/job transition;
- dependency audit after the provider-containment and runtime baseline are complete;
- manual browser tests with real roles;
- production-like HTTPS camera and QR login tests;
- backup/restore and key/token rotation rehearsal.

Security review must include the SaverBro-owned Repair routes, controllers, uploads, and permissions. Any legacy provider Repair surface is reviewed only if a live deployment or preserved legacy data requires it.

## 14. Retention and privacy decisions still required

SaverBro must approve retention for intake images, diagnostic evidence, approval evidence, notifications, customer identifiers, temporary secrets, labels/manifests, audit events, and backups; lawful basis/notice; subject access/deletion exceptions; breach response; and third-party messaging/storage processors. Do not encode assumed legal retention periods in Alpha.
