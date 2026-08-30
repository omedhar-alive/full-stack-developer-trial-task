import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Emit a self-contained server build (.next/standalone) for the Docker
  // runtime stage. Without this the runtime image would need the full
  // node_modules tree.
  output: "standalone",

  images: {
    // Looks like a shortcut; it isn't. Three real reasons:
    //  1. `output: "standalone"` *requires* `sharp` for image optimization
    //     (not "recommends"), and on Alpine that also pulls in libc6-compat —
    //     a native dependency and a classic first-run Docker failure.
    //  2. Jumia's image URLs are already Thumbor-transformed to a fixed size
    //     (unsafe/fit-in/680x680/filters:fill(white)/...). Re-optimizing an
    //     already-resized CDN thumbnail through our own server buys nothing.
    //  3. With optimization on, every product image is proxied through the
    //     Next server out to Jumia's CDN — so a slow or unreachable Jumia
    //     fails at our server instead of in the visitor's browser.
    //
    // No `remotePatterns`: in Next 16.3.3, generateImgAttrs short-circuits
    // before the loader runs when `unoptimized` is set, so remotePatterns is
    // never consulted — it would be dead config.
    //
    // next/image still gives lazy loading and reserved dimensions, so there is
    // no layout shift. Stated as a deviation in the phase 7 README.
    unoptimized: true,
  },
};

export default nextConfig;
