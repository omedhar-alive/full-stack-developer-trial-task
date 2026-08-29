import { NextResponse } from "next/server";

// Liveness probe. Mirrors the proxy (`GET /healthz`) and backend
// (`GET /api/health`) endpoints — see CONTRACTS.md section 7.
// force-dynamic so it is never statically prerendered or cached.
export const dynamic = "force-dynamic";

export function GET() {
  return NextResponse.json({ status: "ok" });
}
