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
            // The API is on localhost / the compose network, not the internet.
            // The default 'online' mode pauses a failed query until the
            // browser reports connectivity — so with the backend down the grid
            // would sit on the skeleton forever instead of showing the error
            // and a Retry button.
            networkMode: "always",
          },
        },
      }),
  );

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
