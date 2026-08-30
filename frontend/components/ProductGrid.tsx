"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useState, useSyncExternalStore } from "react";

import { ProductCard } from "@/components/ProductCard";
import { ProductGridSkeleton } from "@/components/ProductGridSkeleton";
import { fetchProductsInBrowser } from "@/lib/api";
import type { ProductsResponse } from "@/types/product";

const GRID = "grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4";

// false during SSR and the hydration render, true only after — so the update
// clock is formatted once, in the browser, with no server/client text
// mismatch. useSyncExternalStore rather than a useEffect-set flag: same
// effect, no cascading-render lint.
const noopSubscribe = () => () => {};
const useHasMounted = () =>
  useSyncExternalStore(
    noopSubscribe,
    () => true,
    () => false,
  );

export function ProductGrid({ initialData }: { initialData: ProductsResponse | null }) {
  // Page is component state, not a ?page= search param: reading the param in a
  // Server Component means `await searchParams` and a server round trip per
  // page change, which fights the client polling this component exists for.
  // Cost: a page is not bookmarkable — noted in the README.
  const [page, setPage] = useState(1);

  const mounted = useHasMounted();

  const { data, isPending, isError, error, isFetching, dataUpdatedAt, refetch } = useQuery({
    queryKey: ["products", page],
    queryFn: () => fetchProductsInBrowser(page),
    // Only page 1 was fetched on the server. Seeding another page's cache entry
    // with it would show page 1's products under that page's controls.
    initialData: page === 1 ? (initialData ?? undefined) : undefined,
    // Keep the current page on screen while the next loads, instead of flashing
    // empty.
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
    // Already the v5 default. Stated so it is explicit: a hidden tab must stop
    // polling — that is the reason this option exists.
    refetchIntervalInBackground: false,
  });

  if (isPending) {
    // Reached only when the server fetch failed (or on a fresh page with no
    // kept-previous data) — page 1 normally arrives with initialData.
    return <ProductGridSkeleton />;
  }

  if (isError) {
    return (
      <div className="mt-10 rounded-lg border border-red-200 bg-red-50 p-6 text-sm dark:border-red-900/50 dark:bg-red-950/30">
        <p className="font-medium text-red-800 dark:text-red-300">Couldn&apos;t load products</p>
        <p className="mt-1 break-words text-red-700 dark:text-red-400">{error.message}</p>
        <button
          type="button"
          onClick={() => refetch()}
          className="mt-4 rounded-md border border-red-300 px-3 py-1.5 font-medium text-red-800 hover:bg-red-100 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/40"
        >
          Retry
        </button>
      </div>
    );
  }

  const products = data.data;
  const meta = data.meta;

  return (
    <div className="mt-8">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
        <span>
          {meta.total} product{meta.total === 1 ? "" : "s"}
        </span>
        <span className="flex items-center gap-2">
          {isFetching && <span className="text-zinc-400">refreshing…</span>}
          {mounted && <span>updated {new Date(dataUpdatedAt).toLocaleTimeString()}</span>}
        </span>
      </div>

      {products.length === 0 ? (
        <p className="mt-10 text-sm text-zinc-500">
          No products yet — the scraper may still be seeding. This page refreshes itself every 30 seconds.
        </p>
      ) : (
        <div className={`mt-4 ${GRID}`}>
          {products.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      )}

      <div className="mt-10 flex items-center justify-between">
        <button
          type="button"
          onClick={() => setPage((p) => Math.max(1, p - 1))}
          disabled={meta.current_page <= 1}
          className="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700"
        >
          Previous
        </button>
        <span className="text-sm text-zinc-600 dark:text-zinc-400">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <button
          type="button"
          onClick={() => setPage((p) => p + 1)}
          disabled={meta.current_page >= meta.last_page}
          className="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700"
        >
          Next
        </button>
      </div>
    </div>
  );
}
