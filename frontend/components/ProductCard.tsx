"use client";

import Image from "next/image";
import { useState } from "react";

import type { Product } from "@/types/product";

export function ProductCard({ product }: { product: Product }) {
  const [imageFailed, setImageFailed] = useState(false);

  const price = new Intl.NumberFormat("en", {
    style: "currency",
    currency: product.currency, // from the row, never hardcoded
    // The code, not a symbol: Node's ICU and the browser's ICU can choose
    // different glyphs for the same currency, and that shows up as a hydration
    // mismatch on a server-rendered card. Renders "EGP 39.00" / "EGP 149,000.00".
    currencyDisplay: "code",
  }).format(product.price);

  return (
    <a
      href={product.source_url}
      target="_blank"
      rel="noopener noreferrer"
      className="group flex flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white transition-shadow hover:shadow-md dark:border-zinc-800 dark:bg-zinc-950"
    >
      {/* CDN pads every image to 680x680 on white, so object-contain matches
          the file and object-cover would crop padding. A dead CDN URL swaps in
          a neutral block rather than punching a hole in the grid. */}
      <div className="relative aspect-square bg-white">
        {imageFailed ? (
          <div className="flex h-full w-full items-center justify-center bg-zinc-100 text-xs text-zinc-400 dark:bg-zinc-900">
            no image
          </div>
        ) : (
          <Image
            src={product.image_url}
            alt={product.title}
            fill
            sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
            className="object-contain"
            onError={() => setImageFailed(true)}
          />
        )}
      </div>

      <div className="flex flex-1 flex-col p-4">
        {/* Titles run 20–133 chars; clamp so one long title can't misalign the
            whole row, keep the full text on hover. */}
        <h2 className="line-clamp-2 text-sm font-medium leading-snug" title={product.title}>
          {product.title}
        </h2>
        <p className="mt-auto pt-3 text-sm font-semibold tabular-nums">{price}</p>
      </div>
    </a>
  );
}
