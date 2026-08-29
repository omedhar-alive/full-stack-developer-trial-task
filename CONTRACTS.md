# CONTRACTS.md

Frozen interfaces. Any thread may implement these; no thread may change them without going back to the main thread first.

Everything here is a decision, not a suggestion. Where a choice had a real alternative, the reason is on the same line.

---

## 1. Service topology, ports, hostnames

| Service | Compose name | Container port | Host port |
|---|---|---|---|
| MySQL | `mysql` | 3306 | **3307** |
| Laravel API | `backend` | 8000 | 8000 |
| Queue worker | `worker` | — | — |
| Go proxy-manager | `proxy` | 8080 | 8080 |
| Next.js | `frontend` | 3000 | 3000 |

MySQL is published on host **3307**, not 3306 — reviewers frequently have a local MySQL already bound to 3306, and a port clash on first `docker compose up` is the exact failure that makes someone abandon a submission.

`worker` is the same image as `backend`, different command. No second Dockerfile.

**Inter-service URLs are service names.** `http://proxy:8080`, `http://backend:8000`, `mysql:3306`. Inside a container `localhost` is that container.

---

## 2. Environment variables

Names are frozen because they appear in `.env.example`, `docker-compose.yml`, config files and READMEs written by different threads.

### proxy-manager (Go)

| Var | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | listen port |
| `PROXY_POOL_FILE` | `/app/proxies.json` | seed file path |
| `FAILURE_THRESHOLD` | `3` | consecutive failures before benching |
| `COOLDOWN_BASE_SECONDS` | `30` | first cooldown |
| `COOLDOWN_MAX_SECONDS` | `600` | cooldown ceiling |
| `LEASE_TTL_SECONDS` | `120` | unreported leases reaped after this |
| `LOG_LEVEL` | `info` | slog level |

### backend / worker (Laravel)

| Var | Default | Meaning |
|---|---|---|
| `PROXY_SERVICE_URL` | `http://proxy:8080` | Go service base URL |
| `SCRAPER_MODE` | `fixture` | `fixture` \| `live` |
| `SCRAPER_CONNECT_TIMEOUT` | `15` | seconds |
| `SCRAPER_READ_TIMEOUT` | `15` | seconds |
| `SCRAPER_MAX_RETRIES` | `2` | Guzzle retry middleware |
| `SCRAPER_MAX_REDIRECTS` | `3` | redirect chain cap |
| `SCRAPER_THROTTLE_MS` | `2000` | delay between requests |
| `QUEUE_CONNECTION` | `database` | no Redis container |
| `DB_HOST` | `mysql` | service name |
| `DB_PORT` | `3306` | container port, not host port |

`SCRAPER_MODE` defaults to `fixture` so a clean clone produces products on first run.

### frontend (Next.js)

| Var | Default | Meaning |
|---|---|---|
| `API_BASE_URL` | `http://backend:8000` | **server-side** fetches (Server Component) |
| `NEXT_PUBLIC_API_BASE_URL` | `http://localhost:8000` | **browser** fetches (TanStack Query) |

Two variables, deliberately. The Server Component runs inside the `frontend` container and must reach `backend` by service name. The polling query runs in the visitor's browser, which has no idea what `backend` means and must use the published host port. Using one variable for both breaks one side or the other — this is the most common way this architecture fails on first run.

---

## 3. Go proxy-manager HTTP API

All responses `Content-Type: application/json`. All timestamps RFC 3339.

### `GET /lease`

No parameters. The pool is global, not per-host — per-host pools are a scaling concern this system doesn't have.

**200**
```json
{
  "lease_id": "9f1c...",
  "proxy_url": null,
  "user_agent": "Mozilla/5.0 (...)"
}
```

`lease_id` is an opaque string. Laravel never parses it, only echoes it back.

`proxy_url` is **`null`** when the entry is a direct connection, never `""`. Laravel passes it straight into Guzzle as `'proxy' => $lease->proxyUrl`, and Guzzle needs an absent value, not an empty string. This one field is the whole "real proxies drop in without a code change" claim.

**503** — every entry is cooling down.
```json
{ "error": "no_healthy_entries", "retry_after_seconds": 42 }
```

Laravel treats any non-200 the same way: fall back to the local user-agent list, log a warning, continue.

### `POST /report`

```json
{
  "lease_id": "9f1c...",
  "ok": false,
  "status_code": 429,
  "latency_ms": 812
}
```

`status_code` is `0` when no response was received (timeout, connection refused). `ok` is Laravel's verdict; Go applies its own rule on top of it — see below.

**204 No Content** on success. **400** on malformed body. **404** on unknown or already-reported `lease_id`.

Nothing to return, so nothing is returned.

### Failure attribution — Go decides, not Laravel

A report only increments the failure counter when it is **proxy-attributable**:

- counts: `ok: false` with `status_code` `0`, `403`, `407`, `408`, `429`, `502`, `503`, `504`
- does not count: `404`, `410`, and any 2xx/3xx

A 404 is a bad URL, not a bad proxy. Counting it benches healthy entries.

This rule lives in Go, not Laravel, so there is one place to change it.

### `GET /healthz`

**200** `{"status":"ok"}`. Liveness only — no pool state, no dependencies.

### `GET /metrics`

**200**, JSON, not Prometheus exposition format:

```json
{
  "pool_size": 5,
  "healthy": 4,
  "benched": 1,
  "leases_issued": 128,
  "reports_received": 126,
  "entries": [
    {
      "id": "ua-chrome-mac",
      "healthy": true,
      "failures": 0,
      "cooldown_until": null,
      "last_used": "2026-08-29T10:14:02Z"
    }
  ]
}
```

JSON because there is no Prometheus in the compose file, and a reviewer's only interaction with this endpoint is `curl`. A human-readable pool dump demonstrates the circuit breaker; a Prometheus text blob demonstrates a convention. Note the choice in the README — some reviewers will expect the exposition format.

---

## 4. Laravel internal — extractor contract

```php
interface SiteExtractor
{
    public function supports(string $host): bool;

    public function extract(Crawler $html, string $sourceUrl): ProductData;
}
```

`extract()` receives `$sourceUrl` as well as the DOM — the extractor is the only place that knows how to resolve a site's relative image URLs, and it needs the base URL to do it.

`ProductData` is a readonly value object, not an array. It is the boundary between "parsing" and "persistence", and a typed object means a missing field is a constructor error at the extractor, not a silent null three layers later.

Extractors throw `ExtractionFailedException` when a required selector matches nothing. They never return a half-populated object.

**Field list: pending.** See the open question at the bottom of this file.

---

## 5. Database — `products`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` PK | |
| `source_url` | `VARCHAR(512)` **UNIQUE** | key for `updateOrCreate` |
| `price_minor` | `BIGINT UNSIGNED` | 12999, never 129.99 |
| `currency` | `CHAR(3)` | ISO 4217 — `EGP`, `USD` |
| `created_at` / `updated_at` | `TIMESTAMP` | |

Remaining columns pending the field list.

`source_url` unique is what makes re-scraping idempotent. Without it, every run duplicates the catalogue.

`VARCHAR(512)` because a 767-byte index limit applies under some MySQL configurations; 512 chars of `utf8mb4` exceeds it, so the unique index is declared on a **prefix**: `$table->unique([DB::raw('source_url(191)')])` or an explicit `ALTER`. Confirm the exact syntax against Laravel 13 docs at build time — this is the kind of thing that only fails when the migration runs.

---

## 6. Public API — `GET /api/products`

Query params: `?page=1`. Nothing else.

**200** — Laravel's standard paginated resource collection:

```json
{
  "data": [
    {
      "id": 1,
      "source_url": "https://www.jumia.com.eg/...",
      "price": 129.99,
      "currency": "EGP",
      "created_at": "2026-08-29T10:14:02Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 4, "per_page": 20, "total": 73 }
}
```

The frontend reads `response.data`. Not `response`. Laravel emits `links` alongside `meta` — the frontend can ignore it, but the TypeScript type must not forbid it.

`price` is a **number in major units**, already divided by 100 in `ProductResource`. `price_minor` is never exposed. The frontend does no arithmetic on money and has no `/ 100` anywhere in it.

Rate limit: `throttle:60,1`.

---

## 7. Health endpoints (phase 1)

| Service | Path | Response |
|---|---|---|
| `proxy` | `GET /healthz` | `{"status":"ok"}` |
| `backend` | `GET /api/health` | `{"status":"ok"}` |
| `frontend` | `GET /api/health` | `{"status":"ok"}` |

Laravel 11+ ships a `/up` route already. Add `/api/health` anyway so all three services answer the same shape at a predictable path — the phase 1 exit check is three identical curls.

---

## Open question — blocks phase 3

The product field list is not in the project instructions. Part B says "required fields plus `source_url`, `price_minor`, `currency`" without enumerating the required ones.

Needed before the migration is written: the exact fields the original trial task asked for. Likely `title`, `price`, `image_url`, `description`, `rating` — but likely is not good enough for a schema, and adding a column later means editing a migration another thread already wrote.