# SaverBro Recommerce OS — UI Design

## 1. Design direction

Alpha is a responsive browser application inside Ultimate POS, optimized for counter staff using a handheld scanner and technicians using a laptop/tablet. It keeps the existing navigation and visual system where practical, while the Recommerce module owns its task screens.

Design principles:

- scan first, searchable second, manual identifier entry last;
- show one primary action based on permission and state;
- separate physical identity, owner, custodian, condition, and financial status;
- make irreversible posting visibly different from editable preparation;
- show source links to POS purchase, sale, adjustment, transfer, and contact records;
- never use color alone for status or exceptions;
- do not expose customer data in labels, URLs, or unauthorized lookup results.

## 2. Navigation

Recommerce navigation:

1. **Scan**
2. **Receive devices**
3. **Device registry**
4. **Repair jobs**
5. **Diagnostics & grading**
6. **Transfers**
7. **Exceptions & reconciliation**
8. **Labels**
9. **Templates & policies** (authorized administrators)

Counters see Scan, Receive, Jobs, and Labels. Technicians land on My Workbench. Managers see queues, costs, approvals, and exceptions. Permission checks apply server-side even when navigation is hidden.

## 3. Global scan surface

A persistent **Scan** action opens a focused capture drawer:

- large active input with “Scanner ready — scan and press Enter”;
- camera button only when supported and permitted;
- recent scans kept locally for the session, without sensitive details;
- exact result card with device/job code, safe summary, current state, location, and valid actions;
- neutral unknown/unauthorized response;
- duplicate-read suppression indicator;
- keyboard shortcut, for example `/`, when it does not conflict with POS.

The scan router may open a device, job, receive/transfer confirmation, sale assignment, or collection action according to context. It never executes a stock mutation from the scan alone.

## 4. Home and queue views

### 4.1 Counter dashboard

- devices awaiting intake completion;
- customer jobs awaiting collection;
- quotes awaiting customer response;
- labels pending print;
- transfer units to send/receive;
- clearly scoped location selector.

### 4.2 Technician workbench

- assigned jobs grouped by `Diagnosis`, `Waiting parts`, `Repair`, and `QC`;
- age/SLA, priority, device code, model/free-text description, and blocking reason;
- “Scan next device” opens the matching assigned job;
- batch status changes are not allowed for repair work.

### 4.3 Manager control view

- approvals, QC exceptions, cost overruns, stale jobs, stock mismatches, and outbox failures;
- totals are operational indicators, not accounting reports;
- every card links to underlying records.

## 5. Device registry

Filters: exact code/identifier, lifecycle, owner type, custody/location, product/variation, grade, job status, acquired/sold date, and exception state.

Default columns:

- device code;
- product/model or customer-device description;
- ownership badge;
- custody/location;
- lifecycle;
- grade;
- active job/reservation;
- last event time.

Identifier values are masked by default and fully revealed only with permission. Export is a separate audited permission.

## 6. Device detail

Header: permanent device code, human-readable description, lifecycle, owner, custody, and scan/print actions. It must not imply that product variation equals physical identity.

Tabs:

- **Overview** — product/model, identifiers, ownership, custody, grade, warranty, active flags;
- **Timeline** — immutable events with source record links;
- **Diagnostics** — observations, evidence, grades, and templates used;
- **Repairs** — all historical and active jobs;
- **Parts & cost** — internal actual costs or authorized customer-job cost summaries;
- **Stock history** — movements and linked POS records;
- **Labels & tokens** — print status and token rotation for authorized users;
- **Attachments** — malware-scanned evidence with access controls.

Sensitive customer repair secrets are never displayed on this general page.

## 7. Receiving screen

Layout:

- purchase/line context at top;
- progress counters: ordered, received, serialized, exceptions;
- continuously focused scan field;
- prepared-unit table with manufacturer identifier, new device code, label status, and validation;
- right-side exception panel;
- sticky **Post receipt** button with an impact summary.

Posting confirmation states exact POS quantity change and number of device records. Duplicate identifiers are resolved inline. Large batches paginate virtually but preserve scanner focus.

Trade-in intake uses a guided sequence: customer → device search/create → condition evidence → diagnosis/grade → offer → acceptance → acquisition posting → label.

## 8. Customer repair intake

A short, counter-friendly stepper:

1. Customer
2. Device lookup/identity
3. Reported issue and condition
4. Accessories and evidence
5. Privacy/consent and passcode choice
6. Review, create, print receipt/label

The review clearly separates customer statements from staff observations. Passcodes are preferably not stored; if an approved temporary secret workflow is enabled, intake explains the controlled handoff and expiry.

## 9. Repair job detail

Header includes job code, type, state, priority, device, customer (if permitted), location, assignee, elapsed time, and blocking reason.

Sections:

- reported issue and intake evidence;
- diagnostic template and observations;
- faults/actions with responsible technician;
- quote versions and approval evidence;
- parts reservation/installation/billing state;
- actual labor/external costs according to role;
- QC checklist and outcome;
- linked POS estimate/sale/payment summary;
- event timeline.

State transitions are explicit buttons such as **Submit diagnosis**, **Send quote**, **Start repair**, **Submit to QC**, and **Ready for collection**. A transition dialog lists prerequisites and missing evidence. There is no free-form status dropdown.

## 10. Diagnostic and grading UI

- template selector by category/model with version shown;
- sections with pass/fail/not-tested/not-applicable and notes;
- measured values with units and acceptable ranges;
- image/file capture;
- faults derived by a human action, not silently inferred;
- provisional and final grade shown separately;
- grade override requires reason and permission;
- autosave drafts, but signed submission is versioned and immutable.

Templates already used by submitted diagnostics cannot be edited in place; administrators publish a new version.

## 11. Parts and billing UI

Part picker searches current location stock and shows available versus reserved quantity. Each row shows intended action, quantity, quote inclusion, install state, POS billing link, and reversal history.

For customer repair, **Finalize bill** previews product/service lines that will be posted to POS. For internal work, **Consume parts** previews the stock adjustment and device cost. UI never offers both paths for the same usage.

## 12. Sale assignment

When a POS sale contains a tracked variation, show an assignment panel:

- required count versus assigned count;
- scan field and removable prepared assignments;
- state/location/variation validation per device;
- blocking message if any unit is not eligible;
- no silent auto-selection in Alpha.

The ordinary POS finalization control remains the final action.

## 13. Transfer UI

Sender scans exact units into a prepared manifest. Receiver uses a separate receive screen showing expected, received, missing, and unexpected units. An exception requires resolution rather than allowing an inaccurate “complete” state.

## 14. Labels and printing

### Single label

Preview displays human device/job code, Code128, QR, short product description, and optional location/price fields. Sensitive identifiers and customer data are off by default.

### Batch print

Filters/selects prepared units, validates one unique token per label, shows page/label geometry, and generates a server-side printable artifact. Reprinting preserves identity and records reason/count. Token rotation generates a new QR but preserves the permanent human code.

## 15. Exceptions and reconciliation

Each exception card must answer:

- what is inconsistent;
- which records disagree;
- whether stock/customer service is blocked;
- safe resolution choices;
- permission and evidence required.

The comparison view places POS aggregate, tracked devices, and legacy-unserialized balance side by side. Resolution creates ledger entries/events; it does not expose raw database editing.

## 16. Feedback, errors, and concurrency

- Prepared edits show draft/saved/posting/posted states.
- A stale version produces “record changed by X at time Y” and refreshes before retry.
- Network failure after posting prompts status check using idempotency key; it must not invite blind resubmission.
- Validation errors attach to the exact unit/field and keep scanner focus where safe.
- Long work uses post-commit queue status with retry visibility.
- Destructive corrections use impact summaries and, where configured, a second approver.

## 17. Accessibility and device support

- keyboard-complete primary flows;
- visible focus and scanner-focus indicator;
- labels plus icons/text for states;
- minimum touch targets for tablets;
- camera view has text/manual alternative;
- dialogs trap focus and return it correctly;
- tables provide compact mobile cards below desktop width;
- browser support matrix is tested against the browsers actually used at SaverBro sites.

Alpha is online-only. The UI must display connectivity state and disable prepared stock posting when the server cannot confirm the transaction.

## 18. Alpha screen sequence

The first complete vertical slice is:

`Purchase line → Receive tracked unit → Allocate device/QR → Print label → Scan label → View device → Reconcile aggregate stock`

Only after that slice is proven with actual scanners, printers, roles, and concurrent users should repair, transfer, and POS sale assignment broaden the UI.

