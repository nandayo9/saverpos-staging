# SAVERPOS staging on iCore cPanel

This runbook publishes the SAVERPOS Laravel application at
`https://pos.kkcctv.com.my` for a fictional team-test estate. It is not a
production deployment and must use a new, isolated MySQL database.

## Deployment shape

```text
Private GitHub repository (staging branch)
        |
        v
iCore cPanel Git Version Control or cPanel upload
        |
        v
pos.kkcctv.com.my -> Laravel public/ document root -> iCore MySQL database
```

The repository root is the contents of
`UltimatePOS-CodeBase-V7.3`, not its parent Downloads folder. The `.env` file,
database exports, logs, and uploaded files must never be committed.

## 1. Create the private GitHub repository

Create a private repository (for example, `saverpos-staging`) and push the
contents of this directory to a `staging` branch. This checkout has no Git
metadata yet, so the initial local setup is:

```bash
cd /Users/nandayo/Downloads/UltimatePOS-V7.3/UltimatePOS-CodeBase-V7.3
git init
git add .
git status --short
git commit -m "Prepare SAVERPOS staging build"
git branch -M staging
git remote add origin https://github.com/nandayo9/saverpos-staging.git
git push -u origin staging
```

Before pushing, confirm that `.env`, `vendor/`, `storage/`, database dumps, and
credentials do not appear in `git status`. The shipped CSS, fonts, images,
module placeholders, and `public/mix-manifest.json` are intentionally included
because this checkout does not contain a front-end build package that can
recreate them during deployment.

## 2. Prepare cPanel

In iCore cPanel:

1. Create the subdomain `pos.kkcctv.com.my` and set its document root to the
   repository's `public/` directory, for example:
   `/home/CPANEL_USER/repositories/saverpos-staging/public`.
2. Select PHP 8.2 in **Select PHP Version**. Enable at least `ctype`, `curl`,
   `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`,
   `xml`, `zip`, and `bcmath` if available.
3. Create a new MySQL database and a new database user. Grant that user all
   privileges on this new database only.
4. Enable the free SSL certificate for `pos.kkcctv.com.my` and wait until HTTPS
   works before inviting testers.

iCore lists PHP 8.x, MySQL 8.x, cPanel/DirectAdmin, free SSL, and daily backups
for its web-hosting plans. Verify the exact PHP extensions and whether Git
Version Control/Terminal are enabled on your plan before deployment.

## 3. Deploy the code

If cPanel exposes **Git Version Control**, clone the private repository into a
directory outside the document root, select the `staging` branch, and use the
repository's deployment/pull action. If Git Version Control is not available,
download the repository ZIP from GitHub and upload/extract it in cPanel File
Manager; repeat that upload when the staging branch changes.

If cPanel Terminal/SSH is available, run from the repository root:

```bash
composer install --no-dev --optimize-autoloader
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache
php artisan storage:link
chmod -R ug+rwx storage bootstrap/cache
```

If Composer or `storage:link` is unavailable, ask iCore support to enable it;
do not commit `vendor/` as a workaround unless the hosting team explicitly
approves that artifact strategy.

## 4. Create the staging environment

Create `.env` on the server using cPanel's file editor. Use the actual cPanel
database values; never put them in GitHub:

```dotenv
APP_NAME=SAVERPOS
APP_ENV=staging
APP_KEY=GENERATE_A_NEW_KEY
APP_DEBUG=false
APP_URL=https://pos.kkcctv.com.my
APP_TIMEZONE=Asia/Kuching

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANEL_DATABASE_NAME
DB_USERNAME=CPANEL_DATABASE_USER
DB_PASSWORD=CPANEL_DATABASE_PASSWORD

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=daily
ALLOW_REGISTRATION=false

RECOMMERCE_ENABLED=true
RECOMMERCE_WRITES_ENABLED=true
RECOMMERCE_COHORT_BUSINESS_ID=DEMO_BUSINESS_ID
RECOMMERCE_COHORT_LOCATION_ID=DEMO_BRANCH_A_ID
RECOMMERCE_COHORT_LOCATION_IDS=DEMO_BRANCH_A_ID,DEMO_BRANCH_B_ID
RECOMMERCE_COHORT_VARIATION_IDS=DEMO_VARIATION_ID
```

Generate the key once on the server with `php artisan key:generate --show` and
paste the result into `APP_KEY`. Do not reuse the local `.env` key.

## 5. Build the fictional demo estate

Run these commands against the new staging database only:

```bash
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
php artisan db:seed --class=Database\\Seeders\\SaverposDemoRuntimeSeeder --force
```

The demo seeder creates a fictional account, business, two branches, one
tracked variation, and one Device. Set the Recommerce cohort IDs to the IDs
reported by the seeder. The current fixture normally reports business `1`,
branches `1,2`, and variation `1`, but use the values from the actual output.

## 6. Smoke test and team handoff

Open `https://pos.kkcctv.com.my/login` and verify:

- the login page loads without an HTTP 500;
- the fictional demo account can sign in;
- `/recommerce` is reachable;
- the tracked receiving, transfer-exception, POS sale, return, and
  reconciliation journeys work;
- the browser shows a valid HTTPS certificate and no mixed-content warnings.

The fictional test login is `saverpos.demo` / `demo-pass`. Treat this account as
public demo access only; do not use real customer, supplier, payment, or
production data on this site.

## Updating staging

Pull the approved `staging` commit in cPanel, then run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan view:clear
```

Take a cPanel/MySQL backup before migrations. This test site should remain
isolated from production and should be reset from a fresh fictional database
when the team needs a clean workflow run.
