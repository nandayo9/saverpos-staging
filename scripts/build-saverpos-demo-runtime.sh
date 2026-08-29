#!/usr/bin/env bash
set -euo pipefail

# This script only accepts a deliberately named disposable database. It never
# reads, modifies, or imports a production database.
database_name="${SAVERPOS_DEMO_DATABASE:-saverpos_demo_p0}"
socket_path="${SAVERPOS_DEMO_SOCKET:-/tmp/mysql.sock}"
mysql_user="${SAVERPOS_DEMO_DB_USER:-root}"
mysql_bin="${SAVERPOS_DEMO_MYSQL_BIN:-}"
php_bin="${SAVERPOS_DEMO_PHP_BIN:-}"

if [[ -z "$mysql_bin" ]]; then
  mysql_bin="$(command -v mysql 2>/dev/null || true)"
fi
if [[ -z "$php_bin" ]]; then
  php_bin="$(command -v php 2>/dev/null || true)"
fi
if [[ -z "$php_bin" && -x /opt/homebrew/bin/php ]]; then
  php_bin=/opt/homebrew/bin/php
fi
if [[ -z "$php_bin" ]]; then
  echo "PHP CLI not found. Set SAVERPOS_DEMO_PHP_BIN to its absolute path." >&2
  exit 2
fi
if [[ -z "$mysql_bin" && -x /opt/homebrew/bin/mysql ]]; then
  mysql_bin=/opt/homebrew/bin/mysql
fi
if [[ -z "$mysql_bin" ]]; then
  echo "MySQL client not found. Set SAVERPOS_DEMO_MYSQL_BIN to its absolute path." >&2
  exit 2
fi

if [[ ! "$database_name" =~ ^saverpos_demo_[a-z0-9_]+$ ]]; then
  echo "Refusing database name '$database_name'. Use a name beginning saverpos_demo_." >&2
  exit 2
fi

if [[ "${1:-}" != "--fresh" ]]; then
  echo "Usage: SAVERPOS_DEMO_DATABASE=saverpos_demo_name $0 --fresh" >&2
  echo "--fresh drops and recreates only that disposable database." >&2
  exit 2
fi

"$mysql_bin" --protocol=socket --socket="$socket_path" -u"$mysql_user" -e "DROP DATABASE IF EXISTS \`$database_name\`"
"$mysql_bin" --protocol=socket --socket="$socket_path" -u"$mysql_user" -e "CREATE DATABASE \`$database_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

export DB_CONNECTION=mysql
export DB_HOST=localhost
export DB_PORT=3306
export DB_DATABASE="$database_name"
export DB_USERNAME="$mysql_user"
export DB_PASSWORD=""
export DB_SOCKET="$socket_path"
export RECOMMERCE_ENABLED=true
export RECOMMERCE_WRITES_ENABLED=true

"$php_bin" artisan migrate --force
"$php_bin" artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
"$php_bin" artisan db:seed --class=Database\\Seeders\\SaverposDemoRuntimeSeeder --force

echo "Demo runtime ready: database=$database_name user=saverpos.demo password=demo-pass device=SB-DV-00000001-9"
echo "Before serving, set RECOMMERCE_COHORT_BUSINESS_ID=1, RECOMMERCE_COHORT_LOCATION_ID=1, RECOMMERCE_COHORT_LOCATION_IDS=1,2, and RECOMMERCE_COHORT_VARIATION_IDS=1."
