#!/usr/bin/env bash
set -euo pipefail

database_name="${SAVERPOS_DEMO_DATABASE:-saverpos_demo_device_acceptance}"
if [[ ! "$database_name" =~ ^saverpos_demo_[a-z0-9_]+$ ]]; then
  echo "Refusing database name '$database_name'. Use a name beginning saverpos_demo_." >&2
  exit 2
fi
if [[ "${1:-}" != "--fresh" ]]; then
  echo "Usage: SAVERPOS_DEMO_DATABASE=saverpos_demo_name $0 --fresh" >&2
  exit 2
fi
mysql_bin="${SAVERPOS_DEMO_MYSQL_BIN:-/opt/homebrew/bin/mysql}"
php_bin="${SAVERPOS_DEMO_PHP_BIN:-/opt/homebrew/bin/php}"
socket_path="${SAVERPOS_DEMO_SOCKET:-/tmp/mysql.sock}"
"$mysql_bin" --protocol=socket --socket="$socket_path" -uroot -e "DROP DATABASE IF EXISTS \`$database_name\`"
"$mysql_bin" --protocol=socket --socket="$socket_path" -uroot -e "CREATE DATABASE \`$database_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
export DB_CONNECTION=mysql DB_HOST=localhost DB_PORT=3306 DB_DATABASE="$database_name" DB_USERNAME=root DB_PASSWORD= DB_SOCKET="$socket_path"
export RECOMMERCE_ENABLED=true RECOMMERCE_WRITES_ENABLED=true RECOMMERCE_ALLOW_APPROVED_PRODUCT_POLICIES=true
"$php_bin" artisan migrate --force
"$php_bin" artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
"$php_bin" artisan db:seed --class=Database\\Seeders\\SaverposDeviceAcceptanceSeeder --force
echo "Acceptance fixture ready: database=$database_name user=saverpos.acceptance password=demo-pass"
