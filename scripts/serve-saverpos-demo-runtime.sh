#!/usr/bin/env bash
set -euo pipefail

database_name="${SAVERPOS_DEMO_DATABASE:-saverpos_demo_p0}"
php_bin="${SAVERPOS_DEMO_PHP_BIN:-}"
if [[ -z "$php_bin" ]]; then php_bin="$(command -v php 2>/dev/null || true)"; fi
if [[ -z "$php_bin" && -x /opt/homebrew/bin/php ]]; then php_bin=/opt/homebrew/bin/php; fi
if [[ -z "$php_bin" ]]; then echo "PHP CLI not found." >&2; exit 2; fi
if [[ ! "$database_name" =~ ^saverpos_demo_[a-z0-9_]+$ ]]; then
  echo "Refusing database name '$database_name'. Use a name beginning saverpos_demo_." >&2
  exit 2
fi

export SAVERPOS_DEMO_DATABASE="$database_name"
"$php_bin" -S "${SAVERPOS_DEMO_HOST:-127.0.0.1}:${SAVERPOS_DEMO_PORT:-8010}" -t public scripts/saverpos-demo-router.php
