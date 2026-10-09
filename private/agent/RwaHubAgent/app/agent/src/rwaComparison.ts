/** Local RWA report: fixed database reader -> Python report/cache -> bounded model selector. */
import { execFile } from "node:child_process";
import { randomUUID } from "node:crypto";
import { generateText } from "ai";
import { buildModel } from "./model.js";
import { normalizeTicker, readRwaSnapshot } from "./rwaSnapshotTool.js";

const WORKER = "/home/bhub.tm.biz.tr/private/agent/rwa-compare.py";
export const COMMENT_CODES = ["stale_prices", "stale_fetches", "source_fetch_gap",
  "unknown_liquidity", "zero_amm", "execution_checks"] as const;
const allowed = new Set<string>(COMMENT_CODES);
let model: ReturnType<typeof buildModel> | undefined;
let selecting = false;

export function eligibleInput(value: unknown): string[] | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const v = value as Record<string, unknown>;
  if (Object.keys(v).length !== 1 || !Array.isArray(v.eligible_codes)
      || v.eligible_codes.length < 1 || v.eligible_codes.length > COMMENT_CODES.length
      || !v.eligible_codes.every(code => typeof code === "string" && allowed.has(code))
      || new Set(v.eligible_codes).size !== v.eligible_codes.length) return null;
  return v.eligible_codes as string[];
}

export function validateSelection(value: unknown, eligible: string[]): { codes: string[] } | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const v = value as Record<string, unknown>;
  if (Object.keys(v).length !== 1 || !Array.isArray(v.codes)
      || v.codes.length < 1 || v.codes.length > 3
      || !v.codes.every(code => typeof code === "string" && eligible.includes(code))
      || new Set(v.codes).size !== v.codes.length) return null;
  return { codes: v.codes as string[] };
}

export async function selectRwaComment(eligible: string[], signal?: AbortSignal): Promise<{ codes: string[] }> {
  if (selecting) throw new Error("comment_busy");
  selecting = true;
  try {
    model ??= buildModel();
    const result = await generateText({
      model, maxRetries: 0, maxOutputTokens: 512,
      system: "Select the most useful evidence-backed RWA data-quality observations. " +
        "Return ONLY a JSON object with one key codes, containing one to three UNIQUE " +
        "codes from eligible_codes, in priority order. Do not write prose or numbers. " +
        "Prefer stale_prices, source_fetch_gap and execution_checks when eligible. " +
        "Do not invent or select unavailable codes. You have no tools and must not make payments.",
      prompt: JSON.stringify({ eligible_codes: eligible }),
      abortSignal: signal ? AbortSignal.any([signal, AbortSignal.timeout(15_000)]) : AbortSignal.timeout(15_000),
    });
    const selection = validateSelection(JSON.parse(result.text), eligible);
    if (!selection) throw new Error("comment_quality_rejected");
    return selection;
  } finally {
    selecting = false;
  }
}

function workerDiagnostic(error: unknown, stage: string, stderrPresent = false): void {
  const e = error && typeof error === "object" ? error as Record<string, unknown> : {};
  const safe = (value: unknown) => typeof value === "string" && /^[A-Za-z0-9_-]{1,64}$/.test(value) ? value : null;
  console.error("[rwa-worker-error] " + JSON.stringify({ stage,
    name: safe(e.name), code: typeof e.code === "number" ? e.code : safe(e.code),
    syscall: safe(e.syscall), stderr_present: stderrPresent,
    parent_uid: process.getuid?.() ?? null,
  }));
}

type Report = Record<string, unknown> & { ok: boolean };
export async function compareRwa(ticker: string, signal?: AbortSignal): Promise<Report> {
  if (normalizeTicker(ticker) !== ticker) return { ok: false, error: "invalid_ticker" };
  const started = performance.now();
  const snapshot = await readRwaSnapshot(ticker, signal);
  if (!snapshot.ok) return snapshot;
  if (!snapshot.found) return { ok: false, error: "ticker_not_found" };
  // Python inherits the service identity; the worker preserves cache-file ownership.
  const request = JSON.stringify({ action: "rwa_compare", request_id: randomUUID(), ticker, snapshot });
  const report = await new Promise<Report>((resolve) => {
    const child = execFile("python3", [WORKER], {
      encoding: "utf8", timeout: 30_000, maxBuffer: 131_072, killSignal: "SIGKILL",
      windowsHide: true, signal,
    }, (error, stdout, stderr) => {
      if (error || stderr.trim()) {
        workerDiagnostic(error, "worker_exit", !!stderr.trim());
        resolve({ ok: false, error: "comparison_worker_unavailable" });
        return;
      }
      try {
        const value = JSON.parse(stdout) as Report;
        if (!value || value.ok !== true || value.action !== "rwa_compare" || value.ticker !== ticker
            || typeof value.result !== "string" || typeof value.model_status !== "string") {
          resolve({ ok: false, error: "invalid_comparison_response" });
          return;
        }
        resolve(value);
      } catch { resolve({ ok: false, error: "invalid_comparison_response" }); }
    });
    child.stdin?.on("error", () => {});
    child.stdin?.end(request);
  }).catch((error: unknown): Report => {
    workerDiagnostic(error, "worker_spawn");
    return { ok: false, error: "comparison_worker_unavailable" };
  });
  return { ...report, db_time_ms: snapshot.db_time_ms, binance_http_requests: 0,
    binance_request_weight: 0, total_time_ms: Math.round((performance.now() - started) * 100) / 100 };
}
