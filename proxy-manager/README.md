# proxy-manager

The proxy and user-agent identity manager — the task's "Golang script for proxy
management", built as a long-lived service, not a script. Proxy health (which
entry failed, how many times, how long it stays benched) has to survive across
requests, and a per-run script cannot hold it. Laravel leases one identity
before each live scrape and reports the outcome back.

## Files

- `main.go` — config from env, server wiring, JSON logging, graceful shutdown,
  idle lease reaper.
- `pool.go` — the pool and circuit-breaker state machine. Read this first; every
  policy decision lives here.
- `handlers.go` — the HTTP layer: decode, call the pool, encode. No policy.
- `proxies.json` — the seed pool: six user-agent entries, `proxy_url` null.
- `pool_test.go`, `handlers_test.go`, `main_test.go` — the state machine on a
  fake clock, the endpoints over the real mux, and the env defaults.

## Endpoints

**`GET /lease`** — no parameters; returns the least-recently-used entry not in
cooldown.

```
200  {"lease_id":"9f1c...","proxy_url":null,"user_agent":"Mozilla/5.0 ..."}
503  {"error":"no_healthy_entries","retry_after_seconds":42}   + Retry-After header
```

`proxy_url` is `null` for a direct entry, never `""`. The `503` (same seconds in
a `Retry-After` header) means every entry is benched or the pool is empty.

**`POST /report`** — body `{"lease_id","ok","status_code","latency_ms"}`;
`lease_id` and `ok` required, `status_code` is `0` when no response arrived.

```
204  (no body) — recorded
400  {"error":"malformed body"}                     bad JSON, or lease_id/ok missing
404  {"error":"unknown or already-reported lease"}   a lease is single-use
```

**`GET /healthz`** — `200`, body exactly `{"status":"ok"}`. Liveness only.

**`GET /metrics`** — `200`, timestamps RFC 3339, `cooldown_until` / `last_used`
`null` when absent:

```
{"pool_size":6,"healthy":5,"benched":1,"leases_issued":128,"reports_received":126,
 "entries":[{"id":"ua-chrome-mac","healthy":true,"failures":0,
             "cooldown_until":null,"last_used":"2026-08-30T10:14:02Z"}]}
```

## Circuit breaker

Three consecutive proxy-attributable failures (`FAILURE_THRESHOLD`, default 3)
bench an entry. Cooldown is `COOLDOWN_BASE_SECONDS` (30), doubling per further
bench, capped at `COOLDOWN_MAX_SECONDS` (600). After it elapses the entry returns
on probation: one more proxy-attributable failure re-benches it at once. A clean
success resets failure count, bench count and probation — consecutive, not
cumulative.

Failure attribution lives in `isProxyFailure` in `pool.go`, and only there: it
counts `ok:false` with a status of `0`, `403`, `407`, `408`, `429`, `502`, `503`
or `504`. A `404` (or `410`) does not count — a bad URL is not a bad proxy — and
no `2xx`/`3xx` counts.

## Running it, and the tests

```bash
# PROXY_POOL_FILE otherwise defaults to the in-container /app/proxies.json
PROXY_POOL_FILE=./proxies.json go run .
curl localhost:8080/lease

go test ./...
go test -race ./...    # CI runs this plus `go vet ./...`
```

Startup also reads `PORT` (8080), `FAILURE_THRESHOLD`, `COOLDOWN_BASE_SECONDS`,
`COOLDOWN_MAX_SECONDS`, `LEASE_TTL_SECONDS`, `LOG_LEVEL` — defaults in
`.env.example`.

`../CONTRACTS.md` is the frozen interface; `../README.md` covers the whole system.
