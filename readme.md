# SAVERPOS

SAVERPOS is a Laravel-based Point of Sale (POS) application by **SaverBro**, built on top of **Ultimate POS 7.3** and extended with a custom **Recommerce** module for device trade-ins, refurbishment, and repair operations.

This repository is the `staging` branch used for the SaverBro pilot deployment. It is a full POS system (sales, purchases, inventory, contacts, accounting, reporting) plus the SaverBro-specific device lifecycle capability layered on top.

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Core POS Features](#core-pos-features)
- [Recommerce Module](#recommerce-module)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Running Tests](#running-tests)
- [Project Structure](#project-structure)
- [Documentation](#documentation)
- [Deployment](#deployment)
- [Security Vulnerabilities](#security-vulnerabilities)
- [License](#license)

## Overview

SAVERPOS is a multi-business, multi-location retail POS platform. The base application (Ultimate POS) handles the standard retail workflow — products, purchases, sales, stock, contacts, payments, and reporting — while the `Modules/Recommerce` module adds a first-class **device domain** on top of it: serialised physical devices, ownership/custody history, trade-in acquisition, repair jobs (customer and internal), diagnostics/QC, parts consumption, and cost ledgers. Recommerce is designed to be an integrated capability inside the same POS (shared business, locations, users, products, purchases, sales, and accounting), not a separate application.

See [`CODEBASE_AUDIT.md`](CODEBASE_AUDIT.md) and [`RECOMMERCE_ARCHITECTURE.md`](RECOMMERCE_ARCHITECTURE.md) for the full architectural rationale.

## Tech Stack

| Layer | Technology |
|---|---|
| Language / Framework | PHP `^8.0` (PHP 8.2 recommended), Laravel `^9.51` |
| Modules | [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) `^9.0` |
| Database | MySQL |
| Auth | Laravel session auth + [`laravel/passport`](https://laravel.com/docs/9.x/passport) (API tokens) |
| Frontend | Blade templates, jQuery/Bootstrap-era UI, AdminLTE styling, Tailwind utilities, minimal Vue 2 |
| PDF / Documents | `barryvdh/laravel-dompdf`, `mpdf/mpdf` |
| Payments | Stripe, PayPal, Razorpay, Paystack, Flutterwave, MyFatoorah, Pesapal |
| Notifications | `aloha/twilio` (SMS), Pusher (broadcast) |
| Reporting / Export | `maatwebsite/excel`, `consoletvs/charts`, `milon/barcode` |
| Storage / Backup | `spatie/laravel-backup`, S3 (`league/flysystem-aws-s3-v3`), Dropbox |
| Permissions / Audit | `spatie/laravel-permission`, `spatie/laravel-activitylog` |
| AI | `openai-php/laravel` (AI Assistance module) |
| Testing | PHPUnit `^9.5` |
| API Docs | `knuckleswtf/scribe` |

## Core POS Features

Inherited from the Ultimate POS base:

- Multi-business, multi-location management with role-based permissions
- Product catalogue with variations, units, categories, brands, and barcode/QR labels
- Purchases, purchase returns, and supplier management
- Point of Sale (POS) screen with sales, sale returns, and quotations
- Stock management: transfers, adjustments, FIFO/LIFO cost mapping
- Contacts (customers/suppliers), CRM-style notes, and due tracking
- Multiple payment methods and payment gateways (Stripe, PayPal, Razorpay, Paystack, Flutterwave, MyFatoorah, Pesapal)
- Cash register / cash flow management
- Reporting (sales, purchases, stock, profit/loss, tax, and more)
- Activity logging and audit trail
- WooCommerce connector
- REST API via Laravel Passport, documented with Scribe

## Recommerce Module

`Modules/Recommerce` is SaverBro's owned extension for device-centric retail:

- **Device Registry** — one canonical, permanently identified record per physical device, with an opaque public token and QR resolver
- **Ownership & custody history** — explicit, historised `BUSINESS` vs `CUSTOMER` ownership, separate from physical custody/location
- **Trade-in acquisition** — versioned pricing, structured evidence capture, approval gates, and native POS purchase settlement (no shadow ledger)
- **Repair engine** — one job model for both `INTERNAL_REFURBISHMENT` and `CUSTOMER_REPAIR`, shared diagnostics/actions/parts/QC with type-specific billing
- **Stock integrity** — device subledger changes and POS aggregate stock changes commit in the same DB transaction, under locks and idempotency keys, with reconciliation tooling
- **Permanent lifecycle catalogue** — device certifications, public lifecycle projection, and archival of legacy/provider repair evidence
- **Structural authorization gate** — every Recommerce service/controller is guarded to route permission + cohort checks through one `AuthorizationGate` (enforced by `RecommercePermissionGatekeepingTest`)

The module is feature-flagged via `.env` (`RECOMMERCE_ENABLED`, `RECOMMERCE_WRITES_ENABLED`, plus cohort scoping — see [Configuration](#configuration)) so it can be safely piloted on a scoped business/location/variation cohort. Full design detail lives in the [`RECOMMERCE_*.md`](#documentation) and [`RCR_*.md`](#documentation) documents at the repo root.

## Requirements

- PHP `^8.0` (8.2 recommended) with `ctype`, `curl`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip`, and `bcmath` extensions
- Composer 2.x
- MySQL 5.7+ / MariaDB equivalent
- A web server (Apache/Nginx) or `php artisan serve` for local development

Front-end assets (`public/js`, `public/css`) are pre-built and committed to this repository, so no Node/npm build step is required to run the app.

## Installation

1. **Clone the repository**

   ```bash
   git clone https://github.com/nandayo9/saverpos-staging.git
   cd saverpos-staging
   ```

2. **Install PHP dependencies**

   ```bash
   composer install
   ```

3. **Configure the environment**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Edit `.env` and set at minimum `APP_URL` and the `DB_*` database credentials. See [Configuration](#configuration) for the full list of optional integrations.

4. **Create the database and run migrations**

   ```bash
   php artisan migrate
   ```

   (Use the application's installer/seeders as appropriate for a fresh business setup; see `routes/install_r.php` and `database/seeders`.)

5. **Serve the application**

   ```bash
   php artisan serve
   ```

   Visit the printed URL (default `http://localhost:8000`) in your browser.

## Configuration

Key `.env` groups (see `.env.example` for the complete, commented list):

| Group | Variables |
|---|---|
| App | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Cache / Queue / Broadcast | `CACHE_DRIVER`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `BROADCAST_DRIVER`, `PUSHER_*`, `REDIS_*` |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_*` |
| Payment gateways | `STRIPE_*`, `PAYPAL_*`, `RAZORPAY_*`, `PESAPAL_*`, `PAYSTACK_*`, `FLUTTERWAVE_*`, `MY_FATOORAH_*` |
| Backups / storage | `BACKUP_DISK`, `AWS_*`, `DROPBOX_ACCESS_TOKEN` |
| AI Assistance | `OPENAI_API_KEY`, `OPENAI_ORGANIZATION` |
| Security | `ENABLE_RECAPTCHA`, `GOOGLE_RECAPTCHA_KEY`, `GOOGLE_RECAPTCHA_SECRET`, `DO_NOT_ALLOW_DISPOSABLE_EMAIL` |
| Recommerce pilot | `RECOMMERCE_ENABLED`, `RECOMMERCE_WRITES_ENABLED`, `RECOMMERCE_RESOLVER_HOST`, `RECOMMERCE_COHORT_BUSINESS_ID`, `RECOMMERCE_COHORT_LOCATION_ID(S)`, `RECOMMERCE_COHORT_VARIATION_IDS` |

`RECOMMERCE_*` variables are disabled by default and must only be configured in staging/pilot environments, scoped to a specific business/location/variation cohort.

## Running Tests

The test suite is PHPUnit, with helper Composer scripts defined in `composer.json`:

```bash
# Run the full PHPUnit suite
composer test:all

# Run only the Walk-In service tests
composer test:walkin

# Static check for the walk-in flow (Node script)
composer check:walkin

# Runtime preflight check
composer preflight:runtime
```

Recommerce-specific tests live under `Modules/Recommerce/Tests`.

## Project Structure

```
app/                    Core Ultimate POS application (models, controllers, utils)
Modules/Recommerce/     SaverBro device/trade-in/repair module (nwidart/laravel-modules)
  ├─ Config/            Module configuration and permissions catalogue
  ├─ Database/          Migrations
  ├─ Entities/          Eloquent models
  ├─ Http/               Controllers and middleware
  ├─ Providers/          Module service providers
  ├─ Resources/views/    Blade views
  ├─ Routes/             Module routes
  ├─ Services/           Business logic (incl. Services/Intelligence)
  └─ Tests/              Module test suite
database/                Core migrations, factories, seeders
resources/               Core Blade views, JS, SCSS
routes/                  web.php, api.php, console.php, channels.php, install_r.php
scripts/                 Deployment, demo-data, and static-check scripts (bash/php/node)
tests/                   Core PHPUnit test suite
```

## Documentation

This repository carries extensive architecture and decision-record documentation at the repo root:

**Architecture & audit**
- [`CODEBASE_AUDIT.md`](CODEBASE_AUDIT.md) — reconnaissance of the base Ultimate POS codebase
- [`RECOMMERCE_ARCHITECTURE.md`](RECOMMERCE_ARCHITECTURE.md) — Recommerce module architecture and integration contract
- [`RECOMMERCE_DATA_MODEL.md`](RECOMMERCE_DATA_MODEL.md) — device/ownership/custody data model
- [`RECOMMERCE_WORKFLOWS.md`](RECOMMERCE_WORKFLOWS.md) — operator workflows
- [`RECOMMERCE_QR_SCAN_ARCHITECTURE.md`](RECOMMERCE_QR_SCAN_ARCHITECTURE.md) — QR/device resolver design
- [`RECOMMERCE_SECURITY_AND_PERMISSIONS.md`](RECOMMERCE_SECURITY_AND_PERMISSIONS.md) — authorization model
- [`RECOMMERCE_UI_DESIGN.md`](RECOMMERCE_UI_DESIGN.md) — UI/UX conventions
- [`REPAIR_SERVICE_ARCHITECTURE.md`](REPAIR_SERVICE_ARCHITECTURE.md) — repair engine design
- [`WALK_IN_INTELLIGENCE_V1.md`](WALK_IN_INTELLIGENCE_V1.md) — walk-in intake intelligence

**Planning & review**
- [`RECOMMERCE_ROADMAP.md`](RECOMMERCE_ROADMAP.md), [`RECOMMERCE_TASKS.md`](RECOMMERCE_TASKS.md), [`RECOMMERCE_MIGRATION_PLAN.md`](RECOMMERCE_MIGRATION_PLAN.md), [`RECOMMERCE_TERRA_REVIEW.md`](RECOMMERCE_TERRA_REVIEW.md)

**Decision records (RCR series)**
- `RCR_001` through `RCR_011` at the repo root capture individual scoped decisions (baseline report, evidence intake, receiving contract, device lifecycle assessment, trade-in acquisition, stock count, etc.). Browse the repo root for the full, numbered list.

**Operations**
- [`ICORE_CPANEL_STAGING.md`](ICORE_CPANEL_STAGING.md) — staging deployment runbook (cPanel)
- [`AI_HANDOFF.md`](AI_HANDOFF.md) — running session/handoff log for AI-assisted development on this branch

## Deployment

Staging is deployed to a private cPanel host (`pos.kkcctv.com.my`) from the `staging` branch:

```
GitHub (staging branch) → managed cPanel Git checkout → cPanel deployment task → live runtime (.env) → MySQL
```

The `staging` branch is polled and fast-forwarded server-side by a cron script (`scripts/cpanel-staging-poll.sh`); `.github/workflows/deploy-staging.yml` triggers the cPanel deployment task. Full setup steps, guardrails, and troubleshooting are documented in [`ICORE_CPANEL_STAGING.md`](ICORE_CPANEL_STAGING.md).

`.env`, database exports, logs, and uploaded files must never be committed.

## Security Vulnerabilities

If you discover a security vulnerability within SAVERPOS, please send an e-mail to **admin@saverbro.com**. All security vulnerabilities will be promptly addressed.

## License

The SAVERPOS software is licensed under the SAVERBRO brand. The underlying platform is built on Ultimate POS (© Ultimate Fosters); see [`config/author.php`](config/author.php) for base-platform licensing metadata.
