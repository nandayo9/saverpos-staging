# AI Handoff

Current milestone: Recommerce — tracked transfer exception workflow
Last completed task: Added receiving evidence for missing, extra, and substituted Devices with an open-exception completion gate
Last commit: `f6a03fa` (`Prepare SAVERPOS staging build`) on local `staging` branch; GitHub push is pending account authentication
Tests passing: 145 tests / 966 assertions (`/opt/homebrew/bin/php vendor/bin/phpunit --no-coverage`); `recommerce-static-check` passes
Known failures: none in the focused/full PHPUnit or static checks
Browser evidence: fresh disposable MySQL fixture; rendered browser flow passed for receive, pending/completed A→B transfer, Branch B POS sale, exact-device customer return, Branch B reconciliation (`PASS · core 1 · tracked 1 · legacy 0`), complete Device timeline, and RC-037 receiving exceptions (`MISSING` + `EXTRA` recorded, one manager resolution)
P0/P1 issues: P0 closure passed; partial-return exact-device semantics and RC-037 receiving exceptions are defined and covered
Blocked tasks: RC-038 trade-in (needs acquisition-accounting decision); RC-022 camera scan (asset/dependency decision + real hardware matrix); RC-040+ ops/data tasks need approved environments/data
Hardware preflight: macOS exposes enabled printers `HP_Deskjet_2520_series` and `HP_DeskJet_2600_series` (default); no scanner/USB device was visible. This is inventory only, not physical validation.
Next safe task: confirm the target printer and scanner/browser matrix, then run physical label print and keyboard-wedge scan checks; camera scanning remains blocked on the dependency decision
Files/areas currently sensitive: `app/Http/Controllers/SellPosController.php` (single delete hook), `app/Http/Controllers/StockTransferController.php` (transfer seam), `Modules/Recommerce/**`, `.env`, `scripts/*demo-runtime*` (disposable demo DB only — never production)
Architecture decisions required: acquisition accounting (RC-038), camera-scan dependency sourcing (RC-022), notification channel (RC-043)
Hosting prep: iCore cPanel staging guide targets `https://pos.kkcctv.com.my`; local `staging` branch is connected to `https://github.com/nandayo9/saverpos-staging.git`. Use a new MySQL database and PHP 8.2; no cPanel credentials are present in this checkout.
