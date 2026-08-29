import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Emit a self-contained server build (.next/standalone) for the Docker
  // runtime stage. Without this the runtime image would need the full
  // node_modules tree.
  output: "standalone",
};

export default nextConfig;
