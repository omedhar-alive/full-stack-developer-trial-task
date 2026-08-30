"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

export function Providers({ children }: { children: ReactNode }) {
  // Created once per render on the server, once for the lifetime of the tab in
  // the browser. A module-scope client on the server would be shared across
  // every request and leak one visitor's data into another's render.
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // Matches the polling interval: initialData handed down from the
            // server is treated as fresh for 30s, so the grid doesn't fire a
            // duplicate fetch the moment it hydrates.
            staleTime: 30_000,
            retry: 1,
            // This API lives on localhost / the compose network, so the
            // browser's idea of internet connectivity is irrelevant to whether
            // it's reachable. Under the default 'online' mode, query-core's
            // canFetch() returns onlineManager.isOnline(), which is driven only
            // by the browser's online/offline window events — on a machine with
            // no internet it reports offline, canFetch() is false, and the
            // query is held at status: 'pending' / fetchStatus: 'paused'. That
            // keeps isPending true and the grid stuck on the skeleton even
            // though localhost:8000 is fine. 'always' opts out of that gate.
            // (A stopped backend, by contrast, fires no offline event: the
            // query runs, fails, and reaches the error state on its own.)
            networkMode: "always",
          },
        },
      }),
  );

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
