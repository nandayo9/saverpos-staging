# Recommerce Photo AI V1 boundary

The website submits only a customer-confirmed, immutable Photo AI evidence snapshot. `TradeInPhotoAiIntakeService` validates evidence linkage, sends textual device facts through the existing `TradeInCatalogueService`, and sends OCR-like identifiers through the existing `DeviceIdentityResolver`.

Photo AI never creates or authoritatively chooses a product, variation, Device, purchase, acquisition, price, offer, approval, stock movement, payment, or accounting entry. Exact/probable/ambiguous/no-match catalogue outcomes and unresolved/conflicting identity outcomes are advisory. OCR values remain `UNVERIFIED`.

The staff panel is part of the existing website-intake workspace and is controlled independently by `RECOMMERCE_PHOTO_AI_STAFF_ENABLED`, default `false`. An authorised technician can record an immutable comparison against each AI observation. Customer declaration, AI observation, customer confirmation, technician finding, and disagreement outcome remain separate. Existing native inspection, approval, Deal Desk, acquisition, stock and settlement workflows remain final authority.

Public staging access to the website customer feature does not make this workspace public. Website-origin evidence still enters through the authenticated connector, protected source evidence remains case-bound, and the existing SAVERPOS staff permissions continue to control technician review. No provider credential is stored or used by SAVERPOS; the website backend owns the external analysis call and submits only its validated immutable snapshot.

The two migrations create append-only analysis versions and one immutable technician review per analysis. They do not alter UltimatePOS inventory or accounting tables.
