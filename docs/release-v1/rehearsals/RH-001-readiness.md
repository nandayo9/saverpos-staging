# RH-001 First Upgrade Rehearsal Readiness

Date: 11 September 2026
Owner: Grace Evelyn
Status: BLOCKED - mandatory prerequisites are not yet verified

## Purpose

This record defines the minimum safety requirements for the first SAVERPOS upgrade rehearsal.

Creating this document does not authorize a database restore, migration, deployment, external integration test or production change.

## Current candidate information

- Integration branch: `integration/grace-v1-20260908`
- Documentation base SHA: `5630542acb45f7d08d772f7476c0b68676bf1bb8`
- Difference from staging: four commits ahead and zero commits behind
- Recommerce activation: disabled
- Recommerce writes: disabled
- MyFatoorah CA status: blocked because PHP CA settings are empty
- Production deployment authorization: none

The final rehearsal candidate SHA must be recorded again immediately before an authorized rehearsal begins.

## Mandatory backup prerequisites

- [ ] Protected production database backup or snapshot reference recorded
- [ ] Backup creation time and authorized operator recorded
- [ ] Backup storage location confirmed without exposing credentials
- [ ] Backup retention period confirmed
- [ ] Backup integrity check completed
- [ ] Restore procedure documented
- [ ] Restore procedure proven on a non-production target
- [ ] Production application files and uploaded files are separately protected
- [ ] Current production `.env` recovery process is approved securely
- [ ] Current production `APP_KEY` recovery process is approved securely

## Mandatory isolated-environment prerequisites

- [ ] Separate rehearsal application directory created
- [ ] Separate rehearsal domain or local URL confirmed
- [ ] Separate rehearsal MySQL database created
- [ ] Separate least-privilege rehearsal database user created
- [ ] Database engine and version recorded
- [ ] Database character set and collation recorded
- [ ] Application and database timezones recorded
- [ ] Rehearsal storage and upload paths separated from production
- [ ] Rehearsal logs separated from production
- [ ] Production database hostname is not used by the rehearsal application

## Mandatory outbound-safety prerequisites

- [ ] Recommerce activation defaults to off
- [ ] Recommerce writes default to off
- [ ] WooCommerce writes are blocked or redirected
- [ ] Website and trade-in writes are blocked or redirected
- [ ] Payment providers use sandbox credentials only
- [ ] Email is blocked or redirected to a private test inbox
- [ ] Discord messages are redirected to a private test destination
- [ ] n8n uses private test workflows only
- [ ] Queue workers cannot process production jobs
- [ ] Scheduler cannot contact live external services
- [ ] Customer SMS and notifications are disabled
- [ ] Outbound destinations are restricted to an approved allowlist

## Mandatory review prerequisites

- [ ] Technical reviewer named
- [ ] Business sponsor named
- [ ] Inventory reviewer named
- [ ] Finance reviewer named
- [ ] Website or WooCommerce owner named
- [ ] Backup and restore operator named
- [ ] Rehearsal start time approved
- [ ] Rehearsal stop time approved
- [ ] Rollback decision owner named
- [ ] Evidence-storage location approved

## Mandatory reconciliation prerequisites

- [ ] Expected business and location counts recorded
- [ ] Expected user and permission counts recorded
- [ ] Expected customer and supplier counts recorded
- [ ] Expected product and variation counts recorded
- [ ] Expected stock counts and values recorded
- [ ] Expected sales and purchase counts recorded
- [ ] Expected payment totals recorded
- [ ] Expected stock-transfer counts recorded
- [ ] Expected stock-adjustment counts recorded
- [ ] Expected trade-in counts recorded
- [ ] Before-and-after reconciliation queries reviewed
- [ ] Allowed count differences documented

## Planned rehearsal sequence

These are planning steps only. Do not execute them until every applicable prerequisite is verified.

1. Freeze and record the exact candidate commit.
2. Record the protected source backup reference.
3. Verify the separate rehearsal application and database.
4. Verify outbound integrations are blocked or redirected.
5. Restore the protected copy into the rehearsal database.
6. Confirm that production connections are absent.
7. Run approved upgrade commands on the rehearsal environment only.
8. Run application smoke tests.
9. Run Customer Added-On filter acceptance tests.
10. Run approved Recommerce tests with feature gates off.
11. Run database and financial reconciliation checks.
12. Record all warnings, failures and unexpected differences.
13. Test the rehearsal rollback or cleanup procedure.
14. Obtain technical, inventory, finance and business review.

## Minimum smoke-test areas

- Login and logout
- User roles and permissions
- Business locations
- Customer and supplier lists
- Customer Added-On filter
- Product and variation records
- Purchases
- Sales and returns
- Payments
- Inventory balances
- Stock transfers
- Stock adjustments
- Reports
- Queues and scheduled jobs
- Integration routes with all live writes blocked
- Recommerce disabled-state behavior

## Immediate stop conditions

Stop the rehearsal immediately if:

- The source backup cannot be identified.
- The target database might be production.
- Production credentials appear in the rehearsal configuration.
- A live customer notification might be sent.
- A real payment might be attempted.
- A live WooCommerce, website, Discord or n8n destination might be contacted.
- Recommerce or integration writes activate unexpectedly.
- Database counts change outside the rehearsal target.
- The candidate commit changes unexpectedly.
- Required reviewers or rollback ownership are unavailable.
- Evidence cannot be stored safely.

## Current blockers

| Requirement | Current evidence | Status |
|---|---|---|
| Protected production backup | No approved backup reference is attached to this record | BLOCKED |
| Proven restore | No isolated restore evidence is attached | BLOCKED |
| Rehearsal application | Separate application destination is not confirmed | BLOCKED |
| Rehearsal database | Separate database and user are not confirmed | BLOCKED |
| Outbound isolation | Complete blocking or redirection evidence is not attached | BLOCKED |
| MyFatoorah certificate trust | PHP CA settings are empty | BLOCKED |
| WooCommerce ownership | Implementation, configuration and authority remain unverified | BLOCKED |
| Reviewers | Required reviewers are not yet recorded | BLOCKED |
| Reconciliation | Approved before-and-after queries are not attached | BLOCKED |
| Rollback | Approved rollback procedure and owner are not attached | BLOCKED |

## Approval record

- Technical reviewer: Not assigned
- Business sponsor: Not assigned
- Inventory reviewer: Not assigned
- Finance reviewer: Not assigned
- Backup operator: Not assigned
- Rollback owner: Not assigned
- Approved rehearsal date: Not approved
- Final candidate SHA: Not recorded
- Final decision: NO-GO

## Safety decision

RH-001 remains NO-GO.

Do not restore, migrate, deploy, enable integrations or contact live services until the required evidence and approvals are recorded.
