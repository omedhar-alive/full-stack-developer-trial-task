# frontend — Next.js products UI

The `/products` page. A Server Component renders the first batch so first paint
has data; a Client Component then polls the Laravel API and re-renders on new
results.

Replaces the `README.md` that `create-next-app` generates.

## Checks

```bash
npm run lint
npm run build
```

No unit suite — `next build` is the type-check and compile gate, and CI runs
exactly these two commands.

## Everything else

The architecture diagram, the design decisions (including `images.unoptimized`
and why pagination is client state) and one-command setup are in the
[root README](../README.md). The API response shape this UI reads is frozen in
[CONTRACTS.md](../CONTRACTS.md) §6.
