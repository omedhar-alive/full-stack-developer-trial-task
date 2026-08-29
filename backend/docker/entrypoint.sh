#!/bin/sh
set -e

cd /app

# No .env is baked into the image (no committed secrets), so seed one on first
# start from the example.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# APP_KEY: use an explicitly provided one; otherwise share a single generated
# key between the web and worker containers via the mounted /keys volume. The
# web container starts first (worker depends_on it), so it wins the generate.
KEY_FILE=/keys/app_key
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f "$KEY_FILE" ]; then
        mkdir -p /keys
        echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# Drop any stale compiled bootstrap caches (e.g. a packages.php that references
# dev-only providers absent from a --no-dev vendor tree), then rebuild the
# package manifest, which the image build skips (--no-scripts).
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php
php artisan package:discover --quiet

# Run framework migrations only for the web-server container (its CMD is the
# FrankenPHP flag set, which starts with "-"). The worker skips this so the two
# containers don't race on the schema.
case "${1:-}" in
    -*)
        php artisan migrate --force --no-interaction
        ;;
esac

# Hand off to the base image entrypoint (rewritten by the FrankenPHP image to
# launch `frankenphp run` when the first arg starts with "-").
exec docker-php-entrypoint "$@"
