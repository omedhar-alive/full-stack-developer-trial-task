import { ProductGrid } from "@/components/ProductGrid";
import { fetchProductsOnServer } from "@/lib/api";
import type { ProductsResponse } from "@/types/product";

// `next build` runs in the Docker build stage and in CI with no backend
// reachable. Without force-dynamic, Next prerenders this route at build time,
// the fetch fails, and the image build dies. This route is only ever rendered
// per-request.
export const dynamic = "force-dynamic";

export default async function ProductsPage() {
  let initialData: ProductsResponse | null = null;

  try {
    initialData = await fetchProductsOnServer(1);
  } catch (error) {
    // The backend may still be coming up. Fall through to the client, which
    // shows a skeleton and then recovers or shows the error state — never a
    // 500 page.
    console.error("[products] server fetch failed; deferring to client fetch:", error);
  }

  return (
    <main className="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
      <h1 className="text-2xl font-semibold tracking-tight">Scraped products</h1>
      <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
        Pulled from Jumia by the scraper, served by the Laravel API, refreshed every 30 seconds.
      </p>
      <ProductGrid initialData={initialData} />
    </main>
  );
}
