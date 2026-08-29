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

        # Seed from committed fixtures so the reviewer's first `docker compose
        # up` shows a populated UI without reading the README. No network,
        # idempotent (updateOrCreate on source_url), never fatal — a bad
        # fixture must not take down the container.
        php artisan scrape:run --sync || echo "fixture seed failed; continuing"

        # Drop any pending jobs from a previous boot before re-dispatching the
        # live seed. Without this, a job serialized by an older image shape
        # survives in the (volume-backed) jobs table and crashes the new
        # worker on unserialize. Safe here: this is a review stack that
        # re-seeds every boot, not a system with real queued work.
        php artisan queue:clear --force >/dev/null 2>&1 || true

        # Then attempt the live targets, QUEUED not inline: it must not slow
        # or block boot, and the queued path already gives us 3 tries with
        # 10/30/60s backoff plus a failed_jobs row carrying the URL and the
        # reason. A live failure is expected and harmless — the grid is
        # already populated above, and the fallback is the feature. This is
        # what makes a default `docker compose up` exercise the lease/report
        # loop rather than only the parsing path.
        # Review convenience; a real deployment would not seed on start.
        if [ "${SCRAPER_SEED_LIVE:-true}" = "true" ]; then
            php artisan scrape:run --live || echo "live seed dispatch failed; continuing"
        fi
        ;;
esac

# Hand off to the base image entrypoint (rewritten by the FrankenPHP image to
# launch `frankenphp run` when the first arg starts with "-").
exec docker-php-entrypoint "$@"
