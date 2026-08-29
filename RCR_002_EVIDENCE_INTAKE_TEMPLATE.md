# RCR-002 — Evidence Intake Template

**Status:** `TEMPLATE — DO NOT TREAT AS EVIDENCE`  
**Prepared:** 2026-08-27  
**Use:** Complete only from an approved read-only or sanitized production evidence pack.

## Handling rules

- Do not paste passwords, tokens, customer personal data, payment data, or unrestricted database dumps into this workspace.
- Prefer sanitized exports with stable surrogate IDs and preserved quantities, statuses, dates, and relationships.
- Record the source file hash, extraction timestamp, owner, and approval for every evidence item.
- Do not use dummy seed data as a production baseline.
- This template does not authorize access to a signed-in ePerolehan account or any production system.

## 1. Evidence register

| Evidence ID | Required item | File/link | Snapshot/extraction time | Owner | Approval | SHA-256 | Status |
|---|---|---|---|---|---|---|---|
| RCR2-E01 | Sanitized schema and migration history |  |  |  |  |  | Missing |
| RCR2-E02 | Business/location master extract |  |  |  |  |  | Missing |
| RCR2-E03 | Product/variation and identifier extract |  |  |  |  |  | Missing |
| RCR2-E04 | Current stock by location and variation |  |  |  |  |  | Missing |
| RCR2-E05 | Open transaction and return extract |  |  |  |  |  | Missing |
| RCR2-E06 | Repair job/device/status extract |  |  |  |  |  | Missing |
| RCR2-E07 | Site hardware/browser inventory |  |  |  |  |  | Missing |
| RCR2-E08 | Alpha users, location approval, and operating window |  |  |  |  |  | Missing |

## 2. Snapshot manifest

| Field | Value |
|---|---|
| Source environment |  |
| Database engine and version |  |
| Application/version |  |
| Schema/migration revision |  |
| Snapshot timestamp with timezone |  |
| Extraction method |  |
| Sanitization method |  |
| Business IDs included |  |
| Location IDs included |  |
| Tables/records excluded |  |
| Known extraction limitations |  |
| Read-only approval reference |  |

## 3. Alpha cohort

| Field | Value |
|---|---|
| Approved business |  |
| Approved location |  |
| Location code |  |
| Alpha owner |  |
| Operating dates/window |  |
| Category |  |
| Variation/SKU scope |  |
| Source-of-truth quantity date |  |
| Initial on-hand quantity |  |
| Quantity unit |  |
| Excluded locations/categories |  |
| Approval reference |  |

## 4. Stock reconciliation

Complete one row per scoped variation and retain the underlying extract separately.

| Variation surrogate ID | SKU/barcode | Description | Location | Opening qty | Purchases | Sales | Sales returns | Purchase returns | Adjustments | Transfers in | Transfers out | Current source qty | Calculated qty | Variance | Explanation |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
|  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |

**Reconciliation formula:**

`calculated qty = opening qty + purchases - sales + sales returns - purchase returns + adjustments + transfers in - transfers out`

The formula is a discovery control, not an assertion that every deployment applies the same posting semantics. Confirm each movement type against the approved source totals and transaction statuses.

## 5. Identifier and duplicate sample

| Sample ID | Variation surrogate ID | SKU/barcode | Serial/IMEI | Existing device/job reference | Identifier type | Normalized value | Duplicate/conflict group | Source record IDs | Resolution owner | Resolution/status |
|---|---|---|---|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |  |  |  |  |

Minimum sample: include normal matches, missing identifiers, duplicate identifiers, conflicting identifiers, repaired devices, and business-owned sellable stock where applicable.

## 6. Open transactions and Repair exceptions

| Class | Total | Included statuses | As-of time | Amount/quantity basis | Reconciled to source? | Exception IDs | Owner |
|---|---:|---|---|---|---|---|---|
| Open sales |  |  |  |  |  |  |  |
| Open purchases |  |  |  |  |  |  |  |
| Stock transfers |  |  |  |  |  |  |  |
| Sales returns |  |  |  |  |  |  |  |
| Purchase returns |  |  |  |  |  |  |  |
| Repair jobs |  |  |  |  |  |  |  |
| Repair deposits/payments |  |  |  |  |  |  |  |
| Repair attachments/notes |  |  |  |  |  |  |  |

## 7. Site hardware and browser matrix

| Site/workstation | Operator role | Browser/version | OS | Scanner/model | Connection | Scan terminator observed | Printer/model | Media/width | Driver/network path | Label test | Result/exception |
|---|---|---|---|---|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |  |  |  |  |  |

Record witnessed tests for: one valid scan, one invalid/unknown scan, a repeated scan, scanner terminator behavior, label readability, label quiet zone, printer failure/retry, and browser refresh/recovery.

## 8. RCR-002 acceptance decision

| Acceptance item | Evidence ID | Result | Reviewer/date |
|---|---|---|---|
| Snapshot date, scope, exclusions, and hash recorded |  | Pending |  |
| One Alpha location/category/variation selected |  | Pending |  |
| Current stock reconciles to source total |  | Pending |  |
| Identifier duplicate/conflict sample reviewed |  | Pending |  |
| Open transactions and Repair exceptions quantified |  | Pending |  |
| Scanner behavior witnessed |  | Pending |  |
| Printer/label behavior witnessed |  | Pending |  |
| User/location approval recorded |  | Pending |  |

**Decision:** `PENDING EVIDENCE`  
**RCR-002 may be marked ready only when every row above is `Pass` or has an explicitly approved exception.**

## 9. Current checkout baseline

This template was prepared against the source-only findings in `RCR_001_BASELINE_REPORT.md` and `RCR_002_PROVISIONAL_PROFILE.md`. Those reports establish no production quantities, Alpha cohort, Repair table inventory, or hardware facts. They remain the governing limitation until this template is completed from approved evidence.
