const GRID = "grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4";

export function ProductGridSkeleton() {
  return (
    <div className={`mt-8 ${GRID}`} aria-hidden="true">
      {Array.from({ length: 8 }).map((_, i) => (
        <div
          key={i}
          className="flex flex-col overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800"
        >
          <div className="aspect-square animate-pulse bg-zinc-100 dark:bg-zinc-900" />
          <div className="flex flex-col gap-2 p-4">
            <div className="h-4 w-3/4 animate-pulse rounded bg-zinc-100 dark:bg-zinc-900" />
            <div className="h-4 w-1/2 animate-pulse rounded bg-zinc-100 dark:bg-zinc-900" />
            <div className="mt-2 h-4 w-1/3 animate-pulse rounded bg-zinc-100 dark:bg-zinc-900" />
          </div>
        </div>
      ))}
    </div>
  );
}
