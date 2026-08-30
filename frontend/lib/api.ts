import type { ProductsResponse } from "@/types/product";

async function fetchProducts(
  page: number,
  baseUrl: string,
  init?: RequestInit,
): Promise<ProductsResponse> {
  const response = await fetch(`${baseUrl}/api/products?page=${page}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
    ...init,
  });

  if (!response.ok) {
    throw new Error(
      `GET /api/products?page=${page} failed: ${response.status} ${response.statusText}`,
    );
  }

  return response.json() as Promise<ProductsResponse>;
}

/**
 * Server Component fetch. Runs inside the `frontend` container and must reach
 * the backend by its compose service name (API_BASE_URL = http://backend:8000).
 *
 * `process.env.API_BASE_URL` is written out literally on purpose:
 * NEXT_PUBLIC_* inlining is a build-time string replacement, so `process.env`
 * accessed with a computed key silently yields undefined.
 */
export function fetchProductsOnServer(page: number): Promise<ProductsResponse> {
  return fetchProducts(page, process.env.API_BASE_URL as string, {
    // `frontend depends_on backend` has no health condition, so Laravel may
    // still be migrating and seeding when this runs. Bound the wait — an
    // unbounded fetch would hang the render instead of falling through to the
    // client.
    signal: AbortSignal.timeout(5000),
  });
}

/**
 * Browser fetch, used by the polling query. Runs in the visitor's browser,
 * which has no idea what `backend` means and must use the published host port
 * (NEXT_PUBLIC_API_BASE_URL = http://localhost:8000).
 */
export function fetchProductsInBrowser(page: number): Promise<ProductsResponse> {
  return fetchProducts(page, process.env.NEXT_PUBLIC_API_BASE_URL as string);
}
