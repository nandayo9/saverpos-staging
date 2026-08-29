# RCR-002 — Alpha Hardware and Browser Test Protocol

**Status:** `PREPARED — NOT EXECUTED`  
**Prepared:** 2026-08-27  
**Purpose:** Capture witnessed scanner, label-printer, browser, and operator evidence for one approved Alpha site.

## Safety and scope

Run only in an approved non-production or explicitly approved pilot environment with named users and location scope. Do not post stock, create devices, print live customer information, or access a signed-in account unless the test authorization explicitly covers that action. Use synthetic test identifiers and clearly marked test labels until live pilot approval exists.

The first operational slice is: `receive → create device identity → print label → scan → view authorized device → reconcile`. Camera scanning and offline mutation are not part of this protocol.

## 1. Test manifest

| Field | Value |
|---|---|
| Test date/time and timezone |  |
| Environment/build identifier |  |
| Business and location |  |
| Named operator(s) and role(s) |  |
| Approved variation/category |  |
| Scanner model/connection |  |
| Printer model/connection |  |
| Label stock/media dimensions |  |
| Browser/version and OS |  |
| Network conditions |  |
| Test authorization reference |  |
| Evidence folder/hash register |  |

## 2. Scanner setup record

Record the scanner's exact configuration before testing:

| Setting | Value |
|---|---|
| Model/firmware |  |
| USB or Bluetooth mode |  |
| Keyboard layout |  |
| Prefix configured |  |
| Suffix/terminator configured |  |
| Inter-character delay |  |
| Symbologies enabled |  |
| Host OS |  |
| Browser |  |
| Operator workstation |  |

Primary Alpha path is a keyboard-wedge scanner. The input must be handled as exact text with a visible focused field and must not depend on a camera or public CDN decoder.

## 3. Scanner tests

Use synthetic codes that cannot be mistaken for real devices. Record screen evidence and the exact observed result for each test.

| Test ID | Scenario | Expected result | Observed result | Pass/fail | Evidence |
|---|---|---|---|---|---|
| S-01 | Valid Code128 device code with configured terminator | Exact device resolves for authorized user/location |  |  |  |
| S-02 | Same valid code entered manually | Same resolver/result as scanner path |  |  |  |
| S-03 | Unknown but well-formed code | Safe no-match response; no data disclosure |  |  |  |
| S-04 | Malformed/short code | Validation error; no lookup side effect |  |  |  |
| S-05 | Code with control characters or unexpected URL text | Rejected or safely normalized; no script/navigation injection |  |  |  |
| S-06 | Rapid repeated scan | Focus remains usable; no duplicate mutation or duplicate navigation |  |  |  |
| S-07 | Scan with terminator removed | Document whether submit is intentionally deferred; manual fallback remains clear |  |  |  |
| S-08 | Scan with alternate terminator | No accidental second action or duplicate post |  |  |  |
| S-09 | Scanner disconnected/reconnected | Operator sees recoverable failure and can resume manually |  |  |  |
| S-10 | Unauthorized location/user scans known code | Existence/location is not disclosed; action is denied |  |  |  |

Pass requires exact matching, controlled focus behavior, safe unknown/unauthorized responses, and no stock mutation from scan-only actions.

## 4. Label and printer tests

The test label must contain only the approved safe fields: permanent human device code, Code128, opaque QR, safe model text if approved, and template/version marker. It must not contain customer data, passcodes, full IMEI, purchase cost, diagnosis, or repair issue.

| Test ID | Scenario | Expected result | Observed result | Pass/fail | Evidence |
|---|---|---|---|---|---|
| P-01 | Render one label with short safe model text | Correct geometry and readable Code128/QR |  |  |  |
| P-02 | Render long model text | No overflow into barcode/QR or unsafe truncation |  |  |  |
| P-03 | Print on exact approved media | Physical size and margins match manifest |  |  |  |
| P-04 | Decode printed Code128 | Decodes to exact human device code |  |  |  |
| P-05 | Decode printed QR | Resolves through approved safe route; does not embed sensitive data |  |  |  |
| P-06 | Quiet-zone inspection | Barcode/QR quiet zones remain clear after printing/cutting |  |  |  |
| P-07 | Reprint same label | Same device identity; reason and print attempt are recorded |  |  |  |
| P-08 | Printer unavailable or job fails | Failure is visible; no second device/token is created |  |  |  |
| P-09 | Damaged or partly obscured label | Operator can identify failure and use controlled replacement path |  |  |  |
| P-10 | Browser print/retry | Retry does not silently create a new identity or duplicate assignment |  |  |  |

Physical readability is required; a successful PDF or HTTP response alone is not a pass.

## 5. Browser and recovery tests

| Test ID | Scenario | Expected result | Observed result | Pass/fail | Evidence |
|---|---|---|---|---|---|
| B-01 | Supported browser loads receiving screen | Correct role/location scope and scanner focus visible |  |  |  |
| B-02 | Refresh before posting | No duplicate device or stock mutation |  |  |  |
| B-03 | Refresh after confirmed post | Stored outcome is clear and repeat-safe |  |  |  |
| B-04 | Network interruption before confirmation | No claim of success; operator can reconcile safely |  |  |  |
| B-05 | Network interruption after server commit | Retry returns the original outcome or a safe status |  |  |  |
| B-06 | Browser back/forward navigation | No duplicate submit or stale unsafe action |  |  |  |
| B-07 | Keyboard-only operation | Receive/scan/confirm path remains usable |  |  |  |
| B-08 | Session expiry or logout | Protected detail/action is denied without leaking data |  |  |  |

## 6. End-to-end witness

Run once with a synthetic or explicitly approved pilot unit and record the before/after evidence:

1. Confirm business, location, variation, and operator authorization.
2. Record core aggregate quantity before the receive.
3. Receive one controlled unit through the approved receiving path.
4. Confirm exactly one Device identity and one receipt assignment are created.
5. Print and physically inspect one label.
6. Scan the printed Code128 and open the exact authorized Device Detail.
7. Confirm scan-only navigation does not change stock.
8. Record core aggregate quantity after posting and reconcile the expected delta.
9. Re-scan/retry and confirm idempotent outcome.
10. Record exceptions, screenshots/recordings, operator, time, build, and approval.

## 7. Stop and rollback triggers

Stop the test and do not expand the cohort if any of these occur:

- wrong device or wrong location resolves;
- duplicate identity, duplicate stock post, or ambiguous retry outcome;
- printed code/QR does not decode exactly;
- label exposes customer, cost, passcode, diagnosis, or full manufacturer identifier;
- unauthorized user/location can disclose or operate on a device;
- network failure leaves POS and Device state uncertain;
- a non-pilot sale, transfer, adjustment, return, or import path can mutate tracked stock without a documented guard;
- the source quantity and tracked count cannot reconcile.

## 8. Acceptance record

| Acceptance item | Result | Reviewer/date | Evidence |
|---|---|---|---|
| Exact hardware/browser manifest recorded | Pending |  |  |
| Scanner valid/invalid/rapid/terminator cases passed | Pending |  |  |
| Printed Code128 and QR physically decoded | Pending |  |  |
| Label geometry and quiet zone passed | Pending |  |  |
| Unauthorized scope denied | Pending |  |  |
| Refresh/network/retry behavior passed | Pending |  |  |
| End-to-end quantity reconciliation passed | Pending |  |  |
| Named approval to proceed recorded | Pending |  |  |

**Current decision:** `NOT EXECUTED — RCR-002 REMAINS BLOCKED`.

This protocol is documentation only. It does not start a server, access production, print labels, create records, or modify application code.
