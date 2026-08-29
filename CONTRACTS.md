# CONTRACTS.md

Frozen interfaces. Any thread may implement these; no thread may change them without going back to the main thread first.

Everything here is a decision, not a suggestion. Where a choice had a real alternative, the reason is on the same line.

**Precedence.** Where this file and the project instructions (Part B) disagree, this file wins. Part B was written before the interfaces were frozen and still shows some earlier shapes; the known divergences are listed in `CLAUDE.local.md` under "Corrections to Part B".

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

`LEASE_TTL_SECONDS` is 120, not 60, because the scraper's own budget is up to three attempts at 15s connect + 15s read plus backoff. A shorter TTL reaps leases that are still legitimately in flight, and their reports then arrive to a 404 — silently under-counting real proxy failures.

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

**503** — every entry is cooling down, or the pool is empty.
```json
{ "error": "no_healthy_entries", "retry_after_seconds": 42 }
```

`retry_after_seconds` is the time until the **soonest** cooldown expiry, rounded up, minimum 1 — not a constant. A `Retry-After` header carries the same value.

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

**204 No Content** on success. **400** on malformed body, which includes a missing `lease_id` or a missing `ok`. **404** on unknown, already-reported, or reaped `lease_id`.

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

### Behaviour settled during phase 2

Recorded here so the contract does not drift from the shipped implementation.

- **Cooldown length is driven by bench count, not failure count.** 30s, then 60, 120, 240, 480, capped at `COOLDOWN_MAX_SECONDS`. The bench count resets only on a successful trial; the failure counter resets on any success and is consumed by a bench.
- **Probation.** When a cooldown expires the entry is leaseable again but on trial: one proxy-attributable failure re-benches it immediately at the next backoff step, without needing three more. This is the spec's "one trial request".
- **Exclusivity of the trial comes from LRU, not a flag.** `GET /lease` stamps `LastUsed` under the write lock, so a recovering entry stops being the oldest the moment it is handed out and the next concurrent lease picks someone else.
- **`reports_received` counts only reports that matched a live lease.** A 404 does not increment it, which keeps `reports_received ≤ leases_issued`.
- **`cooldown_until` and `last_used` are JSON `null` when absent**, never the zero time `"0001-01-01T00:00:00Z"`.
- **A benched entry shows `"failures": 0`** because the bench consumes the streak; probation governs the re-bench from then on. Expected, not a bug.
- **Reaped leases are discarded silently and never count as a failure.** No report means no evidence about that entry. Reaping happens lazily on `/lease` and `/report`, plus a 30-second background ticker for the zero-traffic case.

---

## 4. Laravel internal — extractor contract

### Target sites

`JumiaExtractor` only. One file, resolved by URL host through the resolver.

The task named sites by example ("Amazon, Jumia, etc."), so the list is not fixed and a single working target is within scope. Every alternative was tested and rejected on evidence:

- **Amazon** and **eBay** return `403` to a plain HTTP request, from both the container and the host.
- **Noon** renders prices client-side; the fetched HTML contains no product price.
- **Shein** serves a captcha challenge.
- **Jumia** serves the page with the price in the HTML.

**The README must state this explicitly** — a reviewer scanning for the named sites will notice Amazon's absence, and an unexplained gap reads differently from a stated decision.

With Jumia as the only target, every row is `EGP`. The `currency` column still stands: a bare `12999` is not data without its unit, and a second site is one new file plus a resolver registration away. The "two currencies in one table" argument from Part C no longer applies and must not be used to justify it.

### Interface

```php
interface SiteExtractor
{
    public function supports(string $host): bool;

    public function extract(Crawler $html, string $sourceUrl): ?ProductData;
}
```

`extract()` returns `null` in exactly one case: the page is valid but the product is legitimately out of stock and carries no price. The caller logs at info level and skips it. Every other problem — malformed price text, missing title, markup that changed — throws. Null is not a general failure signal.

`extract()` receives `$sourceUrl` as well as the DOM — the extractor is the only place that knows how to resolve a site's relative image URLs, and it needs the base URL to do it.

`ProductData` is a readonly value object, not an array. It is the boundary between "parsing" and "persistence", and a typed object means a missing field is a constructor error at the extractor, not a silent null three layers later. Its fields are the section 5 columns minus `id` and the timestamps: `title`, `priceMinor` (int), `currency`, `imageUrl`, `sourceUrl`.

Extractors throw `App\Exceptions\ExtractionFailedException` for every failure that is not out-of-stock — a required selector matching nothing, price text that will not parse, a title that comes back empty. They never return a half-populated object. The exception message carries the source URL and which selector failed.

### Price parsing

`"EGP 12,999.00"` and `"12999"` both become the integer `1299900`. Round, never truncate.

Text that cannot be parsed **throws**. It never defaults to `0`. Because `updateOrCreate` matches on `source_url`, a zero would overwrite a previously correct price on the next run — a wrong value written silently, which is worse than a failed scrape.

### Resolver

Picks an extractor by URL host. An unsupported host throws a clear, typed exception. No silent null.

---

## 5. Database — `products`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` PK | |
| `title` | `VARCHAR(512)` NOT NULL | long product titles are common |
| `price_minor` | `BIGINT UNSIGNED` NOT NULL | 12999, never 129.99 |
| `currency` | `CHAR(3)` NOT NULL | ISO 4217 — `EGP` |
| `image_url` | `TEXT` NOT NULL | CDN URLs exceed 255 chars |
| `source_url` | `VARCHAR(768)` **UNIQUE** | key for `updateOrCreate` |
| `created_at` / `updated_at` | `TIMESTAMP` | |

Eight columns, no additions. The task asked for `id, title, price, image_url, created_at`. `price` becomes `price_minor` + `currency`; `source_url` is added to make re-scraping idempotent. Both deviations are documented in the README. No `description`, no `rating` — the task did not ask for them, and extra columns count against "followed instructions" the same way missing ones do.

`source_url` unique is what makes re-scraping idempotent. Without it, every run duplicates the catalogue.

`VARCHAR(768)` is the full-column unique index, no prefix. MySQL 8.4 defaults to the DYNAMIC row format, which allows a 3072-byte index key — 768 characters of `utf8mb4`. The 767-byte limit applies only to the legacy REDUNDANT and COMPACT formats.

A prefix index was considered and rejected: `source_url(191)` would enforce uniqueness on the first 191 characters only, so two different products whose URLs share a long prefix would collide and `updateOrCreate` would overwrite one with the other. Silent data loss.

`price_minor` is unsigned — a price can never be negative.

---

## 6. Public API — `GET /api/products`

Query params: `?page=1`. Nothing else.

**200** — Laravel's standard paginated resource collection:

```json
{
  "data": [
    {
      "id": 1,
      "title": "Infinix Hot 40i 256GB",
      "price": 129.99,
      "currency": "EGP",
      "image_url": "https://eg.jumia.is/...",
      "source_url": "https://www.jumia.com.eg/...",
      "created_at": "2026-08-29T10:14:02.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 4, "per_page": 20, "total": 73 }
}
```

The frontend reads `response.data`. Not `response`. Laravel emits `links` alongside `meta` — the frontend can ignore it, but the TypeScript type must not forbid it.

`created_at` is Laravel's default ISO-8601 with microseconds. Match it exactly in the TS type and in any test asserting on it.

`price` is a **number in major units**, already divided by 100 in `ProductResource`. `price_minor` is never exposed. The frontend does no arithmetic on money and has no `/ 100` anywhere in it — that is what makes a float safe here.

Ordering: `orderByDesc('created_at')`, 20 per page.

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

## Changelog

- **Phase 2.** Added `LEASE_TTL_SECONDS` to section 2. Recorded the settled circuit-breaker behaviour in section 3.
- **Phase 3.** Product field list resolved from the task description (`id, title, price, image_url, created_at`); the open question that previously sat at the bottom of this file is closed. Section 4 gained the target-site list, the price-parsing rule and the `?ProductData` return type. Section 5 gained `title` and `image_url` and moved `source_url` from `VARCHAR(512)` with a prefix index to `VARCHAR(768)` with a full-column unique index. Section 6 pinned. Final scope is Jumia only: Amazon/eBay return 403 to a plain HTTP request, Noon renders its price client-side, Shein serves a captcha; Jumia serves the price in the HTML. The interface and resolver stay — a second site is a new file plus a registration.