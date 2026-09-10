# SAVERPOS V1 Compatibility Register

Updated: 10 September 2026
Owner: Grace Evelyn
Candidate branch: integration/grace-v1-20260908

| ID | Area | Verified result | Remaining release requirement | Status |
|---|---|---|---|---|
| CMP-008 | Customer/Member Added-On filter | 6/6 browser cases passed; syntax and diff checks passed; commit 0473137 | Human review before any staging merge | Browser verified |
| CMP-009 | Separate Member field | No separate membership database field was added; current work is the Customer/Member page date filter | Define a separate membership field only if the business later requests one | No separate change requested |
| CMP-010 | Canonical device catalogue | Latest staging integrated; 6/6 catalogue tests passed on isolated SQLite | Prove migrations and behaviour on protected MySQL rehearsal data | Tested locally |
| CMP-011 | Customer device projection | 10/10 focused tests passed; allowlisted projection and staging token controls verified | Test approved website consumer in an isolated environment | Tested locally |
| CMP-012 | Trade-in intelligence | 13/13 focused tests passed with pricing gates off | Approve data sources, exact variants, freshness and pricing policy | Blocked by business approval |
| CMP-013 | Automatic product registration | Catalogue maps to existing native variations and does not create a complete POS product | Define SKU, category, tax, pricing, stock and approval rules | Decision needed |
| CMP-014 | Local dependencies | Composer install passed; GD, Sodium, PDO SQLite and SQLite3 are available | Recheck PHP CLI/web parity on rehearsal hosting | Local gate passed |
| CMP-015 | Integration branch | Staging merged without conflict; 29/29 targeted tests passed; remote commit 836565e verified | Technical review and separate PR decision | In review |
| CMP-016 | Production activation | Recommerce activation/write gates are off; no production action taken | Migration, reconciliation, restore, integrations, browser/hardware and staff UAT | NO-GO |

## Rules

- UltimatePOS remains authoritative for products, stock, sales, purchases, payments and accounting.
- Never upload .env, credentials, customer records, databases, uploads or private modules to the public repository.
- Never run the demo/staging bootstrap on the live POS.
- No automatic pricing without approved evidence and management policy.
- No production activation until every required release gate has recorded evidence.
