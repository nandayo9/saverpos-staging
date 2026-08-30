#!/usr/bin/env bash
# Poll the public staging branch from inside the cPanel account, then deploy
# only when it advances. This avoids calling the externally protected cPanel
# UAPI endpoint from GitHub Actions.
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
LOCK_DIR="$ROOT_DIR/storage/framework/saverpos-staging-deploy.lock"

mkdir -p "$(dirname -- "$LOCK_DIR")"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    echo "SAVERPOS staging deploy already running; skipping."
    exit 0
fi
trap 'rmdir "$LOCK_DIR"' EXIT

cd "$ROOT_DIR"
git fetch --quiet origin staging

local_commit="$(git rev-parse HEAD)"
remote_commit="$(git rev-parse origin/staging)"

if [[ "$local_commit" == "$remote_commit" ]]; then
    exit 0
fi

if ! git merge-base --is-ancestor HEAD origin/staging; then
    echo "Refusing non-fast-forward staging deployment; manual review required."
    exit 1
fi

git merge --ff-only origin/staging
/bin/bash "$ROOT_DIR/scripts/cpanel-staging-deploy.sh"
