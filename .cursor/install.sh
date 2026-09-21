#!/usr/bin/env bash
#
# Cloud Agent bootstrap for carljanzell/filament-page-builder.
#
# The PHP 8.4 toolchain (CI's version) and Composer are installed on top of Cursor's
# default image rather than in a custom Dockerfile, so the image's browser and
# computer-use tooling — used to demo the packaged canvas in a real Filament panel —
# stays available. Each step is guarded so the script is a fast no-op once its work is
# already present (for example when booting from a prebuilt snapshot).
set -euo pipefail

PHP_VERSION="8.4"

if ! command -v php >/dev/null 2>&1; then
    echo "Installing PHP ${PHP_VERSION} toolchain..."
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-sqlite3" \
        unzip
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "Installing Composer..."
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    php /tmp/composer-setup.php --quiet --install-dir=/tmp --filename=composer.phar
    sudo mv /tmp/composer.phar /usr/local/bin/composer
    rm -f /tmp/composer-setup.php
fi

echo "Installing PHP dependencies..."
composer install --prefer-dist --no-interaction --no-progress

echo "Bootstrap complete: $(php -v | head -n 1)"
