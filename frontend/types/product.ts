// Mirrors CONTRACTS.md §6. No `price_minor` anywhere in this codebase; no
// `/ 100` anywhere in this codebase — ProductResource already divided.

export interface Product {
  id: number;
  title: string;
  price: number; // major units, already divided by 100 by ProductResource
  currency: string; // ISO 4217, e.g. "EGP"
  image_url: string;
  source_url: string;
  created_at: string; // ISO-8601 with microseconds, e.g. 2026-08-29T23:58:55.000000Z
}

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  // Laravel's PaginatedResourceResponse::meta() emits everything from the
  // paginator except `data` and the four page URLs — so also `from`, `to`,
  // `path` and its own `links` array. §6 names only four; the type must not
  // forbid the rest.
  [key: string]: unknown;
}

export interface ProductsResponse {
  data: Product[];
  links: PaginationLinks;
  meta: PaginationMeta;
}
