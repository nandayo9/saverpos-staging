# ADR-001: SAVERPOS V1 integration base

Date: 10 September 2026
Status: Proposed - technical reviewer and business sponsor approval required
Owner: Grace Evelyn

## Problem

The live SaverBro POS is the operational system and contains working data, integrations and business customizations. The staging repository contains new Recommerce capabilities, but the complete release is not production-approved.

## Decision

Preserve the live POS as the operational baseline. Integrate reviewed SAVERPOS changes through a separate integration branch and prove them on an isolated production-shaped rehearsal copy before any production decision.

## Evidence

- Customer/Member Added-On filter passed 6/6 browser scenarios.
- The latest staging code merged without file conflicts.
- The combined branch passed 29/29 targeted Recommerce tests.
- The remote integration branch is backed up at commit 836565edd1a6e56a6f1201281c8426e5276a09ab.
- Recommerce activation/write gates remain off.
- Hosted MySQL rehearsal, live integration compatibility, hardware checks and staff UAT remain unproven.

## Rules

- Do not overwrite the live application directory with staging code.
- Do not run cPanel staging bootstrap or demo seeders on live.
- Do not enable Recommerce or pricing flags without approval and evidence.
- Apply reviewed additive migrations only to a disposable protected rehearsal database first.
- Do not accept unexplained stock, payment or relationship differences.
- A GitHub push or pull request is not production approval.

## Required approval and validation

- Technical reviewer approves the integration approach.
- Business sponsor approves scope and pricing policy.
- Protected MySQL rehearsal and restoration succeed.
- Core sales, payment, stock, report and integration regression passes.
- Hardware/browser and staff UAT passes for the approved scope.

## Consequence

The integration branch is evidence for review only. Production remains NO-GO until the separate readiness decision is recorded.
