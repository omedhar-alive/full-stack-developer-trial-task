# backend — Laravel API and scraper

The Laravel half of the system: the public `GET /api/products` endpoint and the
scraper behind it. Runs as two containers from one image — `backend` serves
HTTP, `worker` runs the queue.

Replaces the `README.md` that `laravel new` generates.

## Running the tests

```bash
php artisan test
```

Uses SQLite `:memory:` (pinned in `phpunit.xml` and `tests/bootstrap.php`), so
no database service is needed. Against the running stack:

```bash
docker compose exec backend php artisan test
```

## Everything else

The architecture diagram, the design decisions, the `price_minor` deviation and
one-command setup are in the [root README](../README.md). The interfaces this
service implements are frozen in [CONTRACTS.md](../CONTRACTS.md).
