/** Read-only, fixed-path projection of saved technical evidence. No subprocess/API/model calls. */
import { constants } from "node:fs";
import { open } from "node:fs/promises";

const WATCHLIST = "/home/bhub.tm.biz.tr/private/agent/technical-data/technical-watchlist.json";
const MAX_BYTES = 8_388_608;
const DAY_MS = 86_400_000;
const POINTS: Record<string, number> = {
  trend_alignment: 2, ma_pullback_recovery: 3, support_recovery: 3,
  possible_range_breakout: 3, possible_breakout_retest: 4,
};
type Row = Record<string, any>;
type Failure = { ok: false; error: string; binance_http_requests: 0; model_called: false };
const failure = (error: string): Failure => ({ ok: false, error, binance_http_requests: 0, model_called: false });
const object = (v: unknown): v is Row => !!v && typeof v === "object" && !Array.isArray(v);
const decimal = (v: unknown): v is string => typeof v === "string" && /^\d{1,30}(?:\.\d{1,18})?$/.test(v) && Number.isFinite(Number(v));
const ticker = (v: unknown): v is string => typeof v === "string" && /^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(v);
const timestamp = (v: unknown): v is string => typeof v === "string" && /^\d{4}-\d\d-\d\dT/.test(v) && Number.isFinite(Date.parse(v));

/** Exported for offline validation against a report, never accepts a caller-selected file path. */
export function validateTechnicalWatchlist(value: unknown): Row | null {
  if (!object(value) || value.ok !== true || value.schema_version !== 1
      || !["bstock_technical_watchlist", "primary_provider_technical_watchlist"].includes(value.scope) || typeof value.scope_complete !== "boolean"
      || !timestamp(value.generated_at_utc) || !Array.isArray(value.records)
      || value.records.length < 1 || value.records.length > 1000 || value.scanned_count !== value.records.length) return null;
  const seen = new Set<string>();
  let candidates = 0, unavailable = 0;
  for (const r of value.records) {
    if (!object(r) || !ticker(r.ticker) || seen.has(r.ticker) || !["bstock", "ondo"].includes(r.provider) || (value.scope === "bstock_technical_watchlist" && r.provider !== "bstock")
        || r.source !== "daily_token_candles" || r.timeframe !== "1d"
        || typeof r.symbol !== "string" || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/.test(r.symbol)
        || typeof r.contract_address !== "string" || !/^0x[0-9a-fA-F]{40}$/.test(r.contract_address)
        || !["technical_candidate", "not_selected", "technical_data_unavailable"].includes(r.status)
        || !Number.isInteger(r.attention_points) || r.attention_points < 0 || r.attention_points > 15
        || !object(r.indicators) || !Array.isArray(r.signals) || r.signals.length > 5
        || !object(r.availability) || !object(r.diagnostics)
        || !Number.isInteger(r.diagnostics.usable_close_bars) || r.diagnostics.usable_close_bars < 0 || r.diagnostics.usable_close_bars > 2500
        || !Number.isInteger(r.diagnostics.usable_range_bars) || r.diagnostics.usable_range_bars < 0 || r.diagnostics.usable_range_bars > 2500
        || !object(r.availability.support_resistance)
        || !["unavailable", "not_detected", "available_for_token_series"].includes(r.availability.support_resistance.status)
        || !Array.isArray(r.checked_features) || r.checked_features.length > 10
        || !r.checked_features.every((s: unknown) => typeof s === "string" && /^[a-z_]{1,40}$/.test(s))) return null;
    seen.add(r.ticker);
    if (r.source_candle_open_utc !== null && !timestamp(r.source_candle_open_utc)) return null;
    if (r.api_fetch_at_utc !== undefined && !timestamp(r.api_fetch_at_utc)) return null;
    for (const key of ["SMA20", "SMA50", "SMA200", "RSI14"]) {
      const n = r.indicators[key];
      if (n !== null && !decimal(n)) return null;
      if (key === "RSI14" && n !== null && Number(n) > 100) return null;
      const availability = r.availability[key];
      if (!object(availability) || !["unavailable", "available_for_token_series", "available_with_warning"].includes(availability.status)) return null;
    }
    if (r.close !== undefined && !decimal(r.close)) return null;
    if (r.levels !== null && (!Array.isArray(r.levels) || r.levels.length > 4)) return null;
    for (const level of r.levels ?? []) {
      if (!object(level) || !["support", "resistance"].includes(level.kind)
          || !["low", "high", "center", "distance_pct"].every(k => decimal(level[k]))
          || !Number.isInteger(level.confirmed_pivot_touches) || level.confirmed_pivot_touches < 2
          || Number(level.low) > Number(level.high)) return null;
    }
    const codes = new Set<string>();
    let points = 0;
    const evidenceKeys = new Set(["close", "sma20", "sma50", "rsi14", "previous_rsi14", "support_center", "distance_pct", "prior_range_resistance", "latest_low"]);
    for (const s of r.signals) {
      if (!object(s) || !Object.hasOwn(POINTS, s.code) || codes.has(s.code) || !object(s.evidence)
          || Object.keys(s.evidence).length > 9 || !Object.entries(s.evidence).every(([k, v]) => evidenceKeys.has(k) && decimal(v))) return null;
      if (s.evidence.close !== r.close || s.evidence.sma20 !== r.indicators.SMA20
          || s.evidence.sma50 !== r.indicators.SMA50 || s.evidence.rsi14 !== r.indicators.RSI14) return null;
      codes.add(s.code); points += POINTS[s.code]!;
    }
    if (points !== r.attention_points || (r.status === "technical_candidate") !== (points > 0)) return null;
    if (r.status === "technical_candidate") {
      candidates++;
      if (!decimal(r.close) || !timestamp(r.source_candle_open_utc)
          || ["SMA20", "SMA50", "RSI14"].some(k => r.indicators[k] === null)) return null;
    }
    if (r.status === "technical_data_unavailable") unavailable++;
  }
  if (value.scope === "primary_provider_technical_watchlist") {
    if (!Number.isInteger(value.eligible_count) || value.eligible_count < value.records.length || value.eligible_count > 1000
        || !Array.isArray(value.pending_tickers) || value.pending_tickers.length + value.records.length !== value.eligible_count
        || value.scope_complete !== (value.pending_tickers.length === 0) || !object(value.provider_counts)) return null;
    for (const t of value.pending_tickers) {
      if (!ticker(t) || seen.has(t)) return null;
      seen.add(t);
    }
    for (const p of ["bstock", "ondo"]) {
      if (value.provider_counts[p] !== value.records.filter((r: Row) => r.provider === p).length) return null;
    }
  }
  return value.candidate_count === candidates && value.data_unavailable_count === unavailable ? value : null;
}

function project(r: Row, now: number, reportGenerated: string): Row {
  const end = r.source_candle_open_utc === null ? NaN : Date.parse(r.source_candle_open_utc) + DAY_MS;
  const validSource = Number.isFinite(end) && end <= now + 60_000;
  const sourceAge = validSource ? Math.max(0, Math.floor((now-end)/1000)) : null;
  const fetch = r.api_fetch_at_utc === undefined ? NaN : Date.parse(r.api_fetch_at_utc);
  const fetchAge = Number.isFinite(fetch) ? Math.floor((now-fetch)/1000) : null;
  const reportAge = Math.floor((now-Date.parse(reportGenerated))/1000);
  const quality = {
    source_status: !validSource ? 'invalid_or_missing' : sourceAge! > 86400 ? 'older_than_24h' : 'recent',
    source_age_seconds: sourceAge,
    api_fetch_at_utc: r.api_fetch_at_utc ?? null,
    api_fetch_age_seconds: fetchAge,
    api_fetch_status: fetchAge === null ? 'unknown' : fetchAge < -60 ? 'invalid_timestamp' : fetchAge > 129600 ? 'delayed' : 'recent',
    report_age_seconds: Math.max(0,reportAge), report_status: reportAge > 129600 ? 'delayed' : 'recent',
  };
  const availability: Row = {};
  for (const key of ['SMA20','SMA50','SMA200','RSI14']) {
    const period = key === 'RSI14' ? 15 : Number(key.slice(3));
    const absent = !validSource || r.indicators[key] === null || r.availability[key].status === 'unavailable';
    availability[key] = {status: absent ? 'unavailable' : 'available', reasons: !absent ? []
      : !validSource ? ['The API candle has no valid completed source timestamp.']
      : r.diagnostics.usable_close_bars < period ? [`Requires ${period} usable closes; the collected API data has ${r.diagnostics.usable_close_bars}.`]
      : ['The collected API data did not pass the required field validation.']};
  }
  availability.support_resistance = {status: !validSource ? 'unavailable' : r.availability.support_resistance.status === 'available_for_token_series' ? 'available' : r.availability.support_resistance.status,
    reasons: !validSource ? ['The API candle has no valid completed source timestamp.']
      : r.availability.support_resistance.status === 'unavailable' ? [r.diagnostics.usable_range_bars < 30
        ? `Requires 30 usable range candles; the collected API data has ${r.diagnostics.usable_range_bars}.`
        : 'The collected API data did not pass range-field validation.']
      : r.availability.support_resistance.status === 'not_detected' ? ['No repeated pivot level met the configured conditions.'] : []};
  const indicators = Object.fromEntries(Object.keys(r.indicators).map(k=>[k,availability[k]?.status==='available'?r.indicators[k]:null]));
  const coreAvailable = validSource && ['SMA20','SMA50','RSI14'].every(k=>availability[k].status==='available');
  const signals = coreAvailable ? r.signals.map((s:Row)=>({...s,attention_points:POINTS[s.code]})) : [];
  const ruleChecks = Object.keys(POINTS).map(code=>({code,
    status: signals.some((s:Row)=>s.code===code) ? 'matched'
      : !coreAvailable || (code==='support_recovery' && r.levels===null)
        || (['possible_range_breakout','possible_breakout_retest'].includes(code) && r.diagnostics.usable_range_bars<22)
        ? 'unavailable' : 'not_matched'}));
  return {
    ticker:r.ticker,provider:r.provider,symbol:r.symbol,contract_address:r.contract_address,
    status:validSource?r.status:'technical_data_unavailable',
    attention_points:signals.reduce((n:number,s:Row)=>n+s.attention_points,0),
    close:validSource?r.close??null:null,indicators,
    levels:availability.support_resistance.status==='unavailable'?null:r.levels,
    signals,matched_rule_count:signals.length,rule_checks:ruleChecks,
    close_label:'Latest recorded daily close',previous_rsi_label:'Previous observed RSI reading',
    availability,data_quality:quality,
    source_candle_open_utc:r.source_candle_open_utc,
    source_candle_end_utc:Number.isFinite(end)?new Date(end).toISOString():null,
    execution_checked:false,stock_price_normalization_verified:false,
  };
}
// Discovery stays limited to recent evidence; historical evidence is available for explicit analysis.
const eligibleForDiscovery = (r:Row):boolean => r.data_quality.source_status==='recent'
  && r.data_quality.report_status==='recent' && !['delayed','invalid_timestamp'].includes(r.data_quality.api_fetch_status)
  && ['SMA20','SMA50','RSI14'].every(k=>r.availability[k].status==='available');

const DEFINITIONS: Record<string, string> = {
  trend_alignment: "Close > SMA20 > SMA50, with RSI14 between 45 and 70.",
  ma_pullback_recovery: "SMA20 > SMA50; close >= SMA50 and within 1.5% of SMA20; RSI14 between 40 and 65 and rising from its previous reading.",
  support_recovery: "Close rising near a repeated support level (within 2%), with RSI14 between 40 and 65.",
  possible_range_breakout: "Close above the earlier 20-bar range high by more than 0.3%, with RSI14 between 50 and 75.",
  possible_breakout_retest: "Previous close above the earlier range high by more than 0.3%; latest low retests within 0.5% and close remains above that level, with RSI14 between 45 and 75.",
};

/** Code owns the factual report. A UI can render this without trusting model-written facts. */
export function renderTechnicalEvidence(r: Row): string {
  const lines = [`**${r.ticker} (${r.symbol})**`, "Saved daily token-price evidence.", "",
    "| Metric | Value |", "| --- | --- |",
    `| Latest recorded daily close | ${r.close ?? "unavailable"} |`,
    ...["SMA20", "SMA50", "RSI14"].map(k => `| ${k} | ${r.indicators[k] ?? "unavailable"} |`), ""];
  if (r.signals.length) {
    lines.push(`Matched rules: ${r.signals.length}; attention points: ${r.attention_points}.`);
    for (const s of r.signals) {
      lines.push(`* **${s.code} (${s.attention_points} points):** ${DEFINITIONS[s.code]}`);
      if (s.evidence.previous_rsi14 !== undefined) lines.push(`  Previous observed RSI14: ${s.evidence.previous_rsi14}; latest RSI14: ${s.evidence.rsi14}.`);
      if (s.evidence.prior_range_resistance !== undefined) lines.push(`  Earlier range high: ${s.evidence.prior_range_resistance}.`);
      if (s.evidence.support_center !== undefined) lines.push(`  Support center: ${s.evidence.support_center}; distance: ${s.evidence.distance_pct}%.`);
    }
  } else lines.push("No currently eligible matched setup in this saved evidence.");
  const other = r.rule_checks.filter((c: Row) => c.status !== "matched");
  if (other.length) lines.push("", "Other checked rules: " + other.map((c: Row) => `${c.code}: ${c.status}`).join("; ") + ".");
  const absent = Object.entries(r.availability).filter(([, a]) => (a as Row).status === "unavailable");
  if (absent.length) {
    lines.push("", "Unavailable features:");
    for (const [key, a] of absent) lines.push(`* ${key}: ${(a as Row).reasons.join(" ")}`);
  }
  lines.push("", "Attention points count rule matches, not probabilities. Execution conditions and independent stock-price normalization have not been verified.");
  return lines.join("\n");
}

/** Bounded public-to-the-agent projection; internal diagnostics are deliberately omitted. */
export function projectTechnicalWatchlist(value: unknown, limit: number, selectedTicker?: string, now = Date.now(), page = 1): Row | Failure {
  const v = validateTechnicalWatchlist(value);
  if (!v) return failure("invalid_technical_snapshot");
  if (!Number.isInteger(limit) || limit < 1 || limit > 10) return failure("invalid_limit");
  if (!Number.isInteger(page) || page < 1 || page > 1000) return failure("invalid_page");
  if (selectedTicker !== undefined && !ticker(selectedTicker)) return failure("invalid_ticker");
  const age = now - Date.parse(v.generated_at_utc);
  if (age < -60_000) return failure("technical_snapshot_clock_inconsistency");
  if (age > 36 * 3_600_000 && selectedTicker === undefined) return failure("technical_snapshot_stale");
  const projected = v.records.map((r: Row) => project(r, now, v.generated_at_utc));
  const candidates = projected.filter((r: Row) => r.status === "technical_candidate" && eligibleForDiscovery(r))
    .sort((a: Row, b: Row) => b.attention_points - a.attention_points || a.ticker.localeCompare(b.ticker));
  const pageCount = Math.max(1, Math.ceil(candidates.length / limit));
  const currentPage = Math.min(page, pageCount);
  const selected = selectedTicker === undefined ? candidates.slice((currentPage - 1) * limit, currentPage * limit) : projected.filter((r: Row) => r.ticker === selectedTicker);
  if (selectedTicker !== undefined && !selected.length) {
    return { ...failure(v.pending_tickers?.includes(selectedTicker) ? "technical_scan_pending" : "ticker_not_in_technical_scope"),
      reason: v.pending_tickers?.includes(selectedTicker) ? "This ticker is in scope, but its technical source has not been collected yet." : "This ticker is outside the saved technical scan scope.",
      scope: v.scope, scope_complete: v.scope_complete, eligible_count: v.eligible_count ?? v.scanned_count };
  }
  if (selectedTicker !== undefined) selected[0].technical_report = renderTechnicalEvidence(selected[0]);
  return { ok: true, action: selectedTicker === undefined ? "rwa_opportunities" : "rwa_technical_evidence",
    source: "saved_technical_watchlist", generated_at_utc: v.generated_at_utc,
    checked_at_utc: new Date(now).toISOString(), scope: v.scope, scope_complete: v.scope_complete,
    eligible_count: v.eligible_count ?? v.scanned_count, pending_count: v.pending_tickers?.length ?? 0,
    provider_counts: v.provider_counts ?? { bstock: v.scanned_count, ondo: 0 },
    scanned_count: v.scanned_count, candidate_count: candidates.length, returned_count: selected.length,
    historical_count: projected.filter((r:Row)=>r.data_quality.source_status==='older_than_24h').length,
    collection_uncertain_count: projected.filter((r:Row)=>r.data_quality.api_fetch_status!=='recent').length,
    page: currentPage, page_count: pageCount, page_size: limit,
    unavailable_count: projected.filter((r: Row) => r.status === "technical_data_unavailable").length,
    records: selected, binance_http_requests: 0, binance_request_weight: 0, model_called: false,
    interpretation: "Technical candidates from token-price history. Attention points are rule matches, not probabilities. Fundamentals, participation and executable quotes have not yet been integrated." };
}

async function saved(): Promise<unknown> {
  const handle = await open(WATCHLIST, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    const stat = await handle.stat();
    if (!stat.isFile() || stat.size > MAX_BYTES) throw new Error("snapshot_size");
    const buffer = Buffer.alloc(MAX_BYTES + 1);
    let count = 0;
    while (count <= MAX_BYTES) {
      const read = await handle.read(buffer, count, buffer.length-count, count);
      if (!read.bytesRead) break;
      count += read.bytesRead;
    }
    if (count > MAX_BYTES) throw new Error("snapshot_size");
    return JSON.parse(buffer.subarray(0, count).toString("utf8"));
  } finally { await handle.close(); }
}

export async function readRwaOpportunities(limit = 5, selectedTicker?: string, page = 1): Promise<Row | Failure> {
  if (!Number.isInteger(limit) || limit < 1 || limit > 10) return failure("invalid_limit");
  if (!Number.isInteger(page) || page < 1 || page > 1000) return failure("invalid_page");
  if (selectedTicker !== undefined && !ticker(selectedTicker)) return failure("invalid_ticker");
  const start = performance.now();
  let result: Row | Failure;
  try { result = projectTechnicalWatchlist(await saved(), limit, selectedTicker, Date.now(), page); }
  catch { result = failure("technical_snapshot_unavailable"); }
  console.info("[rwa-tool] " + JSON.stringify({ tool: selectedTicker === undefined ? "rwa_opportunities" : "rwa_technical_evidence",
    ticker: selectedTicker ?? null, ok: result.ok, time_ms: Math.round((performance.now()-start)*100)/100,
    binance_http_requests: 0, binance_request_weight: 0, called_at_utc: new Date().toISOString() }));
  return result;
}

/** Score-free evidence for the conversation agent. No score order leaks into its input. */
export function projectRwaCandidatePool(value: unknown, now = Date.now()): Row | Failure {
  const checked = projectTechnicalWatchlist(value, 10, undefined, now);
  if (!checked.ok) return checked;
  const summary = checked as Row;
  const v = validateTechnicalWatchlist(value)!;
  const projected = v.records.map((r: Row) => project(r, now, v.generated_at_utc));
  let selected = projected.filter((r: Row) => r.status === "technical_candidate" && eligibleForDiscovery(r));
  // Only expand with recent, available core evidence; old API data is never promoted as current.
  const expanded = selected.length < 10;
  if (expanded) selected = selected.concat(projected.filter((r: Row) => r.status === "not_selected"
    && eligibleForDiscovery(r)));
  const exclusion_counts = {source_older_than_24h:0, source_timestamp_unavailable:0, collection_or_report_delayed:0, core_indicators_unavailable:0, no_matched_conditions:0, other:0};
  for (const r of projected) {
    if (selected.some((item:Row)=>item.ticker===r.ticker)) continue;
    if (r.data_quality.source_status==='older_than_24h') exclusion_counts.source_older_than_24h++;
    else if (r.data_quality.source_status!=='recent') exclusion_counts.source_timestamp_unavailable++;
    else if (r.data_quality.report_status!=='recent' || ['delayed','invalid_timestamp'].includes(r.data_quality.api_fetch_status)) exclusion_counts.collection_or_report_delayed++;
    else if (!['SMA20','SMA50','RSI14'].every(k=>r.availability[k].status==='available')) exclusion_counts.core_indicators_unavailable++;
    else if (r.status==='not_selected') exclusion_counts.no_matched_conditions++;
    else exclusion_counts.other++;
  }
  const discovery_summary = {
    matched_candidate_count:selected.filter((r:Row)=>r.status==='technical_candidate').length,
    additional_review_count:selected.filter((r:Row)=>r.status!=='technical_candidate').length,
    pool_count:selected.length, excluded_count:projected.length-selected.length, exclusion_counts,
  };
  const records = selected.sort((a: Row, b: Row) => a.ticker.localeCompare(b.ticker)).map((r: Row) => ({
    ticker: r.ticker, provider: r.provider, symbol: r.symbol,
    close: r.close, indicators: r.indicators, levels: r.levels,
    signals: r.signals.map((s: Row) => ({ code: s.code, evidence: s.evidence })),
    availability: r.availability, data_quality:r.data_quality, source_candle_end_utc: r.source_candle_end_utc,
    additional_review: r.status !== "technical_candidate",
    execution_checked: false, stock_price_normalization_verified: false,
  }));
  return { ok: true, generated_at_utc: v.generated_at_utc, checked_at_utc: new Date(now).toISOString(),
    eligible_count: summary.eligible_count, scanned_count: summary.scanned_count,
    pending_count: summary.pending_count, unavailable_count: summary.unavailable_count,
    historical_count:summary.historical_count,collection_uncertain_count:summary.collection_uncertain_count,
    scope_complete: summary.scope_complete, expanded, discovery_summary, records,
    binance_http_requests: 0, binance_request_weight: 0, model_called: false };
}
export async function readRwaCandidatePool(): Promise<Row | Failure> {
  try {
    const result = projectRwaCandidatePool(await saved());
    const info = result as Row;
    console.info('[rwa-discovery-pool] '+JSON.stringify({ok:result.ok,
      ...(result.ok ? {scanned_count:info.scanned_count,eligible_count:info.eligible_count,pending_count:info.pending_count,discovery_summary:info.discovery_summary} : {error:result.error}),
      checked_at_utc:new Date().toISOString(),binance_http_requests:0,model_called:false}));
    return result;
  }
  catch { return failure("technical_snapshot_unavailable"); }
}
