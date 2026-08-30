#!/usr/bin/env bash
# cPanel Git deployment entry point for the isolated SAVERPOS staging estate.
# The Git checkout is kept separate from the live document root so cPanel can
# deploy a clean branch while the runtime .env and ACME files remain private.
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
DEPLOY_PATH="${SAVERPOS_DEPLOY_PATH:-"$ROOT_DIR/../saverpos-staging"}"
cd "$ROOT_DIR"

if [[ ! -f "$DEPLOY_PATH/.env" ]]; then
    echo "Missing $DEPLOY_PATH/.env. Create the server-only staging .env before deploying."
    exit 1
fi
export SAVERPOS_ENV_PATH="$DEPLOY_PATH"

php_version_is_82() {
    "$1" -r 'exit((PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300) ? 0 : 1);' >/dev/null 2>&1
}

PHP_BIN=""
for candidate in /opt/alt/php82/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php php; do
    if command -v "$candidate" >/dev/null 2>&1 && php_version_is_82 "$candidate"; then
        PHP_BIN="$(command -v "$candidate")"
        break
    fi
done

if [[ -z "$PHP_BIN" ]]; then
    echo "PHP 8.2 CLI was not found. Select PHP 8.2 in cPanel, then deploy again."
    exit 1
fi

COMPOSER_BIN=""
for candidate in /usr/local/bin/composer "${HOME:-}/bin/composer" composer; do
    if command -v "$candidate" >/dev/null 2>&1; then
        COMPOSER_BIN="$(command -v "$candidate")"
        break
    fi
done

if [[ -z "$COMPOSER_BIN" ]]; then
    # cPanel hosting plans do not always expose a global Composer command.
    # Bootstrap a local copy only after verifying the current installer checksum
    # published by Composer itself. storage/ is ignored by Git and outside the
    # Laravel web root, so this does not dirty the managed repository.
    COMPOSER_DIR="$ROOT_DIR/storage/composer"
    COMPOSER_BIN="$COMPOSER_DIR/composer.phar"
    mkdir -p "$COMPOSER_DIR"

    if [[ ! -f "$COMPOSER_BIN" ]]; then
        INSTALLER="$COMPOSER_DIR/composer-setup.php"
        if command -v curl >/dev/null 2>&1; then
            EXPECTED_CHECKSUM="$(curl --fail --location --silent --show-error https://composer.github.io/installer.sig)"
            curl --fail --location --silent --show-error https://getcomposer.org/installer --output "$INSTALLER"
        else
            EXPECTED_CHECKSUM="$("$PHP_BIN" -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
            "$PHP_BIN" -r 'copy($argv[1], $argv[2]);' https://getcomposer.org/installer "$INSTALLER"
        fi

        ACTUAL_CHECKSUM="$("$PHP_BIN" -r 'echo hash_file("sha384", $argv[1]);' "$INSTALLER")"
        if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
            rm -f "$INSTALLER"
            echo "Composer installer checksum verification failed."
            exit 1
        fi

        "$PHP_BIN" "$INSTALLER" --quiet --install-dir="$COMPOSER_DIR" --filename=composer.phar --2
        rm -f "$INSTALLER"
    fi
fi

"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
    storage/framework/testing storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

if ! grep -Eq '^APP_KEY=base64:' "$DEPLOY_PATH/.env"; then
    "$PHP_BIN" artisan key:generate --force --no-interaction
fi

if [[ ! -e public/storage ]]; then
    "$PHP_BIN" artisan storage:link --no-interaction
fi

"$PHP_BIN" scripts/cpanel-staging-bootstrap.php
"$PHP_BIN" artisan config:clear --no-interaction
"$PHP_BIN" artisan view:clear --no-interaction

# Publish the built application into the stable live directory. Preserve
# cPanel-managed files such as public/.well-known, while linking the runtime
# directories back to the checkout so one deployment has one source of truth.
mkdir -p "$DEPLOY_PATH/public"
cp -a "$ROOT_DIR/public/." "$DEPLOY_PATH/public/"
for link in vendor bootstrap storage; do
    destination="$DEPLOY_PATH/$link"
    if [[ -e "$destination" && ! -L "$destination" ]]; then
        echo "Refusing to replace non-symlink live path: $destination"
        exit 1
    fi
    ln -sfn "$ROOT_DIR/$link" "$destination"
done

echo "SAVERPOS cPanel staging deployment completed."
