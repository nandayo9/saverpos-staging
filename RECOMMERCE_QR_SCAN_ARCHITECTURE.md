# SaverBro Recommerce OS — QR, Barcode, and Scan Architecture

## 1. Decision

Use a dual-label identity:

- a permanent, human-readable SaverBro code encoded as Code128 for fast scanner input; and
- a permanent public HTTPS QR URL containing a high-entropy opaque token.

The database primary key, manufacturer serial, IMEI, customer name, product cost, and job details must never appear in the QR payload. A repair job gets its own token; a device QR must not silently change meaning to “current job.”

## 2. Identifier classes

| Identifier | Example shape | Purpose | Mutability |
|---|---|---|---|
| Device UUID | UUID/ULID | internal integration identity | immutable, not printed |
| Device code | `SB-DV-00012345-C` | staff-visible permanent code | immutable; check digit recommended |
| Device QR token | random 128+ bit value | public resolver capability | token may rotate/revoke; identity remains |
| Job code | `SB-RP-00012345-C` | staff/customer reference | immutable |
| Job QR token | random 128+ bit value | job resolver capability | may rotate/revoke |
| Manufacturer identifiers | serial/IMEI/service tag | deduplication and lookup | corrected only with history |
| POS SKU/sub-SKU | existing values | catalog/variation lookup | not physical-unit identity |

Code allocation must be concurrency-safe, unique per business or globally according to the chosen prefix policy, and covered by a database unique constraint. Do not reuse the existing non-atomic reference-count helper without hardening it.

## 3. QR payload

Recommended payload:

`https://<approved-saverbro-domain>/s/d/<opaque-token>`

Job form:

`https://<approved-saverbro-domain>/s/r/<opaque-token>`

Rationale:

- ordinary phone cameras can open it;
- deployment routing may evolve without reprinting device identity;
- the opaque token prevents predictable enumeration;
- server authorization can select the right destination;
- token rotation is possible after suspected disclosure.

Store only a keyed hash or strong one-way hash of the token, plus short lookup prefix if needed. Compare in constant-time. Raw tokens appear only at issuance/print and must not enter logs, analytics URLs, error trackers, referrers, or exports.

The final production domain, TLS, redirect policy, and token lifetime/rotation policy remain deployment decisions.

## 4. Resolver behavior

### 4.1 Public GET

1. Apply rate limiting and generic error behavior.
2. Resolve token hash without returning object details.
3. If not authenticated, retain a short-lived, same-site intended destination and redirect to login.
4. After authentication, re-resolve and authorize business/location/object access.
5. Redirect to device or job page with a fresh internal URL that does not retain the raw token.

Unknown, revoked, cross-business, and unauthorized tokens receive indistinguishable safe responses. Public resolver pages do not expose model, owner, location, or repair status.

### 4.2 Authenticated scan API

`POST /recommerce/scans/resolve` accepts the captured string plus optional workflow context. It:

- normalizes Unicode/whitespace carefully;
- parses approved URLs without following arbitrary redirects;
- performs exact matching only;
- returns object type, safe summary, state/version, and authorized context actions;
- records rate/security telemetry without raw secret tokens;
- never performs the selected business action itself.

Mutation endpoints accept an independent idempotency key and expected version.

## 5. Code128 and existing barcode support

Ultimate POS already uses `milon/barcode` and has label page geometry and browser/PDF print paths. Reuse:

- installed rendering library;
- label dimensions, margins, row/column layout concepts;
- print preview and PDF delivery patterns;
- product and variation descriptive fields where safe.

Do not reuse the existing identity loop unchanged: current labels repeat catalog variation data for quantity and cannot generate one distinct physical-unit token per label. Recommerce needs its own device/job label service and endpoints while sharing low-level rendering/layout utilities.

Code128 should encode only the human SaverBro code. Manufacturer serial/IMEI barcodes may be captured during intake but are not the permanent internal label.

## 6. Label content

Default device label:

- SaverBro name/logo;
- short safe item description;
- human device code in text;
- Code128 representation of device code;
- QR resolver URL;
- optional “Property of SaverBro” only while policy permits and ownership warrants it.

Default repair tag:

- job code and Code128;
- job QR;
- short device code;
- intake date/location code if operationally needed.

Never include customer name/phone, passcode, full IMEI, purchase cost, diagnosis, or repair issue on the label.

## 7. Scanner compatibility

### 7.1 Keyboard-wedge scanners

Primary Alpha hardware path. Configure scanners to:

- output the approved character set;
- append Enter;
- use consistent keyboard layout;
- avoid vendor prefixes/suffixes unless captured and stripped explicitly.

The web UI must:

- keep a clearly visible focused field;
- submit on Enter immediately, not rely on the current POS autocomplete delay;
- debounce duplicate reads within a short window;
- allow rapid sequential batch scans;
- keep raw key bursts out of unrelated form fields;
- provide audible/visual success and error states without assuming sound is enabled.

Validation matrix must include each actual scanner model, USB/Bluetooth mode, macOS/Windows browser, QR/Code128 symbologies, damaged labels, and rapid batch input.

### 7.2 Camera scanning

Use the browser `BarcodeDetector` API only when capability detection confirms required formats. Provide a locally bundled, security-reviewed fallback decoder for supported browsers; do not load scanning code from a public CDN.

Camera requirements:

- HTTPS and explicit user permission;
- rear-camera preference on mobile;
- visible camera-use state and stop control;
- bounded frame rate and region of interest;
- pause after a successful decode;
- no image upload unless user explicitly captures approved evidence;
- manual/text alternative.

Camera scanning is convenience, not the sole Alpha path.

## 8. Batch label generation

1. Select a posted receiving batch or explicit device/job set.
2. Validate every object has one active token and unique human code.
3. Freeze the ordered print manifest with label template version.
4. Render server-side HTML/PDF using existing geometry concepts.
5. Record print attempt, printer/profile, actor, time, and result.
6. Reprints use the same identity unless an authorized token rotation is requested.

Large batches should render in bounded chunks. A failed print does not create new devices or tokens. “Printed” is operational evidence, not proof the label was physically attached; optional attachment confirmation may be scanned back.

## 9. Contextual scan actions

The same device scan may offer:

- view device;
- receive prepared unit;
- assign to POS sale line;
- dispatch/receive transfer;
- open assigned repair job;
- submit to QC;
- verify customer collection;
- report wrong-location exception.

The server derives actions from permission, location, current state, reservation, ownership, and requested workflow context. Client-provided context cannot override eligibility.

## 10. Duplicate and replay handling

- Reads: debounce identical decoded value for a short client window.
- Commands: unique `(business_id, command_type, idempotency_key)` with stored outcome.
- State: optimistic expected version plus row locks for critical mutation.
- Batch scans: unique device per prepared manifest; duplicates highlighted, not counted twice.
- QR replay: opening a token is harmless; mutations require authenticated permission and separate confirmation.
- Token compromise: revoke/rotate QR token, append event, reprint label. Human device code remains.

## 11. Security controls

- allowlist resolver host/scheme when parsing scanned URLs;
- reject `javascript:`, local-file, credential-bearing, and non-approved external URLs;
- strict output encoding and content security policy;
- rate-limit public and authenticated resolution separately;
- redact tokens from web/access/application logs;
- set referrer policy and no-store on resolver responses;
- keep QR tokens out of DOM analytics attributes;
- audit reveal/export of manufacturer identifiers;
- protect label PDF access with authorization and short-lived delivery.

Opaque QR tokens reduce enumeration risk but are not authentication credentials for business actions.

## 12. Offline behavior

Alpha is online-only. The browser may retain a non-sensitive prepared scan list briefly in memory, but cannot confirm ownership, state, or stock without the server. It must not queue offline stock, sale, transfer, or repair-state mutations.

If future offline support is approved, it requires a separately designed signed command journal, conflict policy, device trust model, and reconciliation UX; a service worker cache alone is insufficient.

## 13. Monitoring and tests

Track counts/latency for resolved, unknown, revoked, unauthorized, duplicate, and rate-limited scans without raw tokens. Alert on unusual token-probing patterns.

Required automated tests include token entropy/uniqueness, hash lookup, authorization non-disclosure, exact matching, invalid QR and URL parser attacks, idempotency, label uniqueness, token rotation, retired-device resolution, print-manifest repeatability, and cross-business isolation. A retired device remains an authorized historical record; its QR does not become a reusable identity. Required runtime tests include real scanners, cameras, printers, login redirect, location roles, and concurrent scan/post operations.

## 14. Future mobile-application compatibility

The resolver URLs, exact scan API contract, object/action response, and mutation idempotency contract are channel-neutral. A future SaverBro app can therefore reuse the same server services while presenting a native camera and device-trust layer. It must receive its own scoped authentication, secure local storage, remote revocation, and mobile threat review; it must not gain authority merely because it scanned a QR. Alpha should not add native-app or offline synchronization code in anticipation.
