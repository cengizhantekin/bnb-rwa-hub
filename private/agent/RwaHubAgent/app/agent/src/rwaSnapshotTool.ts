/** Fixed-path, shell-free access to the private CLI database snapshot reader. */
import { execFile } from "node:child_process";

const READER = "/home/bhub.tm.biz.tr/private/agent/rwa-snapshot.php";
const TIMEOUT_MS = 5_000;
const MAX_BYTES = 65_536;

export type SnapshotResult = {
  ok: true;
  action: "rwa_snapshot";
  ticker: string;
  chain_id: "56";
  source: "database_snapshot";
  captured_at_utc: string;
  found: boolean;
  token_count: number;
  tokens: Record<string, unknown>[];
  quality_warnings: string[];
  db_time_ms: number;
  binance_http_requests: 0;
  binance_request_weight: 0;
  model_called: false;
};
type ToolFailure = { ok: false; error: string };

export function normalizeTicker(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const ticker = value.trim().toUpperCase();
  return /^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker) ? ticker : null;
}

export function validateSnapshot(value: unknown, ticker: string): SnapshotResult | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const v = value as Record<string, unknown>;
  if (v.ok !== true || v.action !== "rwa_snapshot" || v.ticker !== ticker
      || v.chain_id !== "56" || v.source !== "database_snapshot"
      || typeof v.captured_at_utc !== "string" || !Number.isFinite(Date.parse(v.captured_at_utc))
      || !Array.isArray(v.tokens) || v.tokens.length > 10
      || v.token_count !== v.tokens.length || v.found !== (v.tokens.length > 0)
      || v.binance_http_requests !== 0 || v.binance_request_weight !== 0 || v.model_called !== false
      || typeof v.db_time_ms !== "number" || !Number.isFinite(v.db_time_ms) || v.db_time_ms < 0
      || !Array.isArray(v.quality_warnings) || !v.quality_warnings.every(w => typeof w === "string")) return null;
  const identities = new Set<string>();
  for (const item of v.tokens) {
    if (!item || typeof item !== "object" || Array.isArray(item)) return null;
    const row = item as Record<string, unknown>;
    if (row.underlying_ticker !== ticker || String(row.chain_id) !== "56"
        || typeof row.symbol !== "string" || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/.test(row.symbol)
        || !["bstock", "ondo", "xstocks"].includes(String(row.provider))
        || typeof row.contract_address !== "string" || !/^0x[0-9a-fA-F]{40}$/.test(row.contract_address)) return null;
    const identity = row.contract_address.toLowerCase();
    if (identities.has(identity)) return null;
    identities.add(identity);
  }
  // Payload originates from a fixed local reader; it never includes config or raw API data.
  return v as SnapshotResult;
}

export async function readRwaSnapshot(value: string, abortSignal?: AbortSignal): Promise<SnapshotResult | ToolFailure> {
  const ticker = normalizeTicker(value);
  if (!ticker) return { ok: false, error: "invalid_ticker" };
  const started = performance.now();
  const outcome = await new Promise<SnapshotResult | ToolFailure>((resolve) => {
    execFile("php", [READER, ticker], {
      encoding: "utf8", timeout: TIMEOUT_MS, maxBuffer: MAX_BYTES,
      killSignal: "SIGKILL", windowsHide: true, signal: abortSignal,
    }, (error, stdout, stderr) => {
      if (error || stderr.trim() || Buffer.byteLength(stdout, "utf8") > MAX_BYTES) {
        resolve({ ok: false, error: "database_snapshot_unavailable" });
        return;
      }
      try {
        const result = validateSnapshot(JSON.parse(stdout), ticker);
        resolve(result ?? { ok: false, error: "invalid_snapshot_response" });
      } catch {
        resolve({ ok: false, error: "invalid_snapshot_response" });
      }
    });
  });
  console.info("[rwa-tool] " + JSON.stringify({
    tool: "rwa_snapshot", ticker, ok: outcome.ok,
    rows: outcome.ok ? outcome.token_count : 0,
    db_time_ms: outcome.ok ? outcome.db_time_ms : null,
    time_ms: Math.round((performance.now() - started) * 100) / 100,
    binance_http_requests: 0, binance_request_weight: 0,
    called_at_utc: new Date().toISOString(),
  }));
  return outcome;
}


export type CatalogResult = {
  ok: true; action: "rwa_catalog"; chain_id: "56"; source: "database_catalog";
  tickers: { ticker: string; variants: number; providers: number }[];
  ticker_count: number; captured_at_utc: string; db_time_ms: number;
  binance_http_requests: 0; binance_request_weight: 0; model_called: false;
};
export function validateCatalog(value: unknown): CatalogResult | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const v = value as Record<string, unknown>;
  if (v.ok !== true || v.action !== "rwa_catalog" || v.chain_id !== "56"
      || v.source !== "database_catalog" || !Array.isArray(v.tickers) || v.tickers.length > 2000
      || v.ticker_count !== v.tickers.length || v.binance_http_requests !== 0
      || v.binance_request_weight !== 0 || v.model_called !== false
      || typeof v.captured_at_utc !== "string" || !Number.isFinite(Date.parse(v.captured_at_utc))
      || typeof v.db_time_ms !== "number" || !Number.isFinite(v.db_time_ms) || v.db_time_ms < 0) return null;
  const seen = new Set<string>();
  for (const row of v.tickers) {
    if (!row || typeof row !== "object" || Array.isArray(row)) return null;
    const r = row as Record<string, unknown>;
    if (typeof r.ticker !== "string" || normalizeTicker(r.ticker) !== r.ticker
        || !Number.isInteger(r.variants) || Number(r.variants) < 2 || Number(r.variants) > 10
        || !Number.isInteger(r.providers) || Number(r.providers) < 2
        || Number(r.providers) > 3 || Number(r.providers) > Number(r.variants)
        || seen.has(r.ticker)) return null;
    seen.add(r.ticker);
  }
  return v as CatalogResult;
}
export async function readRwaCatalog(signal?: AbortSignal): Promise<CatalogResult | ToolFailure> {
  return new Promise((resolve) => {
    execFile("php", [READER, "--catalog"], { encoding: "utf8", timeout: TIMEOUT_MS,
      maxBuffer: 262_144, killSignal: "SIGKILL", windowsHide: true, signal }, (error, stdout, stderr) => {
      if (error || stderr.trim()) { resolve({ ok: false, error: "catalog_unavailable" }); return; }
      try { resolve(validateCatalog(JSON.parse(stdout)) ?? { ok: false, error: "invalid_catalog_response" }); }
      catch { resolve({ ok: false, error: "invalid_catalog_response" }); }
    });
  });
}
