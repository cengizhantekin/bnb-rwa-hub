/**
 * Model factory — emitted user code shared by every runtime entrypoint.
 *
 * This file is **your project's code**, scaffolded by `bag` and emitted at
 * `src/model.ts` for every project, whatever `[llm].provider` you chose.
 * It is yours to edit, fork, or replace — studio will not silently rewrite
 * it.
 *
 * What it does:
 *
 * - Exposes {@link buildModel}, the factory called by the sibling entrypoint
 *   to construct the right AI SDK `LanguageModel` for the project's `[llm]`
 *   config. For every provider it resolves a plain model via
 *   `@bnbagent/studio-runtime/llm` `resolveModel`; for `pieverse-llm` (with
 *   auto-renew on) it additionally wraps it with a credit-ensure middleware.
 * - The middleware awaits a Pieverse credit-ensure hook before every
 *   generate/stream call — inert unless the provider is `pieverse-llm`.
 *
 * The credit-refresh / auto-allocate / auto-topup logic itself lives in the
 * library at `@bnbagent/studio-runtime/pieverse` `PieverseCreditEnsurer` —
 * this shell just wires it into the AI SDK's generate-call path (via
 * `wrapLanguageModel`, the AI SDK's sanctioned middleware seam). That keeps
 * the adapter tiny: you can fork *this file* (e.g. to swap the AI SDK for
 * another stack) without forking studio itself.
 *
 * That top-up is the ONLY automatic signing path outside signing.ts — it is
 * budget-gated and is NOT an LLM tool. It rides transparently into the
 * `notify_funded` work step.
 */

import { loadStudioToml, type TomlTable } from "@bnbagent/studio-runtime/config";
import { resolveModel } from "@bnbagent/studio-runtime/llm";
import {
  BudgetPolicy,
  PieverseCreditEnsurer,
  PieversePolicy,
} from "@bnbagent/studio-runtime/pieverse";
import { getWallet } from "@bnbagent/studio-runtime/wallet";
import {
  type LanguageModel,
  type LanguageModelMiddleware,
  wrapLanguageModel,
} from "ai";

/** Log only selected metadata; never log request bodies or raw error text. */
export function safeLlmDiagnostic(error: unknown, stage: string): Record<string, unknown> {
  const e = error !== null && typeof error === "object"
    ? error as Record<string, unknown> : {};
  const out: Record<string, unknown> = { stage };
  if (typeof e.statusCode === "number") out.status = e.statusCode;
  if (typeof e.isRetryable === "boolean") out.retryable = e.isRetryable;
  const headers = e.responseHeaders;
  const selected: Record<string, string> = {};
  if (headers && typeof headers === "object") {
    for (const [name, value] of Object.entries(headers)) {
      const key = name.toLowerCase();
      if (/^(content-type|retry-after|x-request-id|request-id|x-ratelimit-(limit|remaining|reset)(-(requests|tokens))?|ratelimit-(limit|remaining|reset))$/.test(key)
          && typeof value === "string" && value.length <= 128
          && /^[a-zA-Z0-9 .,;:_/+\-=]+$/.test(value)
          && !/sk-|bearer/i.test(value)) selected[key] = value;
    }
  }
  if (Object.keys(selected).length) out.headers = selected;
  let detail: Record<string, unknown> = {};
  const texts: string[] = [];
  const fields = new Set<string>();
  const bodyText = typeof e.responseBody === "string" ? e.responseBody : null;
  out.body_format = bodyText === null ? "missing" : "empty";
  if (bodyText !== null) out.body_chars = bodyText.length;
  // Bound parsing and classification. Never include these text values in logs.
  const collect = (value: unknown, depth: number): void => {
    if (depth > 4 || texts.length >= 20) return;
    if (typeof value === "string") { texts.push(value.slice(0, 4096)); return; }
    if (Array.isArray(value)) {
      for (const item of value.slice(0, 5)) collect(item, depth + 1);
    } else if (value && typeof value === "object") {
      const obj = value as Record<string, unknown>;
      for (const key of ["error", "errors", "detail", "message", "code", "type", "reason"]) {
        if (Object.prototype.hasOwnProperty.call(obj, key)) {
          fields.add(key); collect(obj[key], depth + 1);
        }
      }
    }
  };
  if (bodyText && bodyText.length <= 65536) {
    try {
      const body: unknown = JSON.parse(bodyText);
      out.body_format = "json";
      collect(body, 0);
      if (body && typeof body === "object" && !Array.isArray(body)) {
        const obj = body as Record<string, unknown>;
        const candidate = obj.error ?? obj.detail ?? obj;
        if (candidate && typeof candidate === "object" && !Array.isArray(candidate))
          detail = candidate as Record<string, unknown>;
      }
    } catch {
      out.body_format = /^\s*</.test(bodyText) ? "html_or_markup" : "text";
      texts.push(bodyText.slice(0, 4096));
    }
  } else if (bodyText && bodyText.length > 65536) out.body_format = "oversized";
  if (fields.size) out.error_fields = [...fields].sort();
  for (const key of ["code", "type"]) {
    const value = detail[key];
    if (typeof value === "string" && /^[a-zA-Z][a-zA-Z0-9_\-.]{0,63}$/.test(value)
        && !/sk-|secret|password|token[._-]/i.test(value)) out[`provider_${key}`] = value;
  }
  if (typeof e.message === "string") texts.push(e.message.slice(0, 4096));
  const message = texts.join("\n");
  const hints: string[] = [];
  if (/insufficient.*(credit|balance)|credit.*exhaust|payment required/i.test(message)) hints.push("credit_or_balance");
  if (/quota|daily[ _-]?limit|monthly[ _-]?limit|per[ _-]?day|free.*(exhaust|limit|exceed)|(exhaust|exceed).*free/i.test(message)) hints.push("quota");
  if (/capacity|overload|no.*(available|healthy).*(model|provider)|temporarily unavailable/i.test(message)) hints.push("capacity");
  if (/rate[ _-]?limit|too many requests/i.test(message)) hints.push("rate_limit");
  if (/unauthorized|invalid.*(api[ _-]?key|credential)|authentication/i.test(message)) hints.push("authentication");
  out.reason_hints = hints;
  out.reason_hint = hints[0] ?? "unknown";
  return out;
}

async function diagnosedCall<T>(stage: string, call: () => PromiseLike<T>): Promise<T> {
  try { return await call(); }
  catch (error) {
    console.error("[llm-error] " + JSON.stringify(safeLlmDiagnostic(error, stage)));
    throw error;
  }
}

/**
 * Build the AI SDK model object for this project's `[llm]` config.
 *
 * Called by the sibling entrypoint. Reads `studio.toml` via
 * `loadStudioToml`, resolves the provider via
 * `@bnbagent/studio-runtime/llm` `resolveModel`, and (when the provider is
 * `pieverse-llm` and auto-renew is enabled) wraps it with the credit-ensure
 * middleware.
 *
 * For non-Pieverse providers — or when `[llm.auto_renew].enabled = false` —
 * returns the model with diagnostic logging only (no credit middleware).
 */
export function buildModel(): LanguageModel {
  const cfg = loadStudioToml();
  const llmCfg = (cfg.llm ?? {}) as TomlTable;
  if (String(llmCfg.provider ?? "openrouter") === "none") {
    throw new Error(
      "Protocol-only scaffold: no LLM provider is configured. " +
        "Implement the generated work hook before accepting funded jobs, " +
        "or set [llm].provider and [llm].model in studio.toml.",
    );
  }
  const resolved = resolveModel(llmCfg);
  const diagnostics: LanguageModelMiddleware = {
    wrapGenerate: ({ doGenerate }) => diagnosedCall("generate", doGenerate),
    wrapStream: ({ doStream }) => diagnosedCall("stream_start", doStream),
  };
  const inner = wrapLanguageModel({
    model: resolved as Parameters<typeof wrapLanguageModel>[0]["model"],
    middleware: diagnostics,
  });

  if (String(llmCfg.provider ?? "openrouter") !== "pieverse-llm") {
    return inner;
  }

  // Pieverse path — wrap with the credit-ensure middleware unless auto-renew
  // opted out.
  const autoRenewCfg = (llmCfg.auto_renew ?? {}) as TomlTable;
  const pieverseCfg = (llmCfg.pieverse ?? {}) as TomlTable;
  const budgetCfg = (cfg.budget ?? {}) as TomlTable;

  const policy = PieversePolicy.fromToml(autoRenewCfg);
  if (!policy.enabled) {
    return inner;
  }

  const keyHash = pieverseCfg.key_hash;
  if (!keyHash) {
    throw new Error(
      "[llm.pieverse].key_hash is missing in studio.toml. " +
        "Run `bag llm activate` to create a Pieverse key first. " +
        "(After activate, you may need to restart the agent process " +
        "for changes to take effect.)",
    );
  }
  const networkName = String(pieverseCfg.network ?? "bsc-mainnet");
  const budgetPolicy = BudgetPolicy.fromToml(budgetCfg);

  const ensurer = new PieverseCreditEnsurer({
    modelId: String(llmCfg.model ?? ""),
    wallet: getWallet(),
    keyHash: String(keyHash),
    networkName,
    policy,
    budgetPolicy,
  });

  // The AI SDK middleware seam: ensure credits BEFORE each generate/stream
  // call, then delegate untouched through the AI SDK middleware seam.
  const creditEnsure: LanguageModelMiddleware = {
    wrapGenerate: async ({ doGenerate }) => {
      await diagnosedCall("credit_ensure", () => ensurer.ensureCredits());
      return doGenerate();
    },
    wrapStream: async ({ doStream }) => {
      await diagnosedCall("credit_ensure", () => ensurer.ensureCredits());
      return doStream();
    },
  };

  // resolveModel always returns a provider model object (never a bare model
  // id string), so it satisfies wrapLanguageModel's model parameter.
  return wrapLanguageModel({
    model: inner as Parameters<typeof wrapLanguageModel>[0]["model"],
    middleware: creditEnsure,
  });
}
