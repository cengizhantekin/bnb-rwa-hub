# Daily and weekly technical analysis coverage test

Prepared 2026-10-02, Istanbul. Software output is English. Two new private CLI files, no changes to the current comparison UI or agent service.

## Scope

Daily: SMA20, SMA50, SMA200 and Wilder RSI14.
Optional weekly: SMA20, SMA50 and Wilder RSI14.
No hourly, minute or monthly indicator suite. No new Python dependencies.

Horizontal zones use confirmed two-sided swing pivots within 120 prior closed bars, clustered within 0.5%, with at least two separated pivot touches. A separate prior 20-bar high/low range supports experimental breakout and next-bar retest checks. Horizontal levels and moving averages are reported separately. The 0.5% zone and 0.3% breakout tolerances are provisional engineering defaults, not optimized trading rules.

The diagnostic does not declare an actionable stock opportunity. It labels token candles as on-chain token history, with historical share-ratio adjustment unavailable. Current ratio must not retroactively convert past prices after dividends or corporate actions. Unsupported history, stale data and unvalidated session gaps cannot justify a stock-level thesis. RSI below 30 alone is not a rebound; RSI above 70 alone is not a sell instruction. A retest requires a later observed candle; its absence cannot be filled by a forecast.

## Data and costs

Uses the documented Binance Web3 GET /api/v1/dex/market/candles endpoint, chain 56. It reads existing active provider tokens from the database and never changes them. Required API credentials stay in the existing private config.php.

Default TSLA run: maximum three providers, three 100-row daily pages each, maximum nine HTTP requests. --weekly adds one 100-row weekly page per provider, maximum twelve requests total. Empty or unavailable responses may stop earlier. AAPL and NVDA are separate runs to avoid one large request burst. No model calls, payments or transaction signatures. All signed requests use the existing GET signature format including /build and the exact query string.

Per-call UTC timestamp, HTTP status, latency, observed weight and remaining quota are printed. Unknown headers stay null; no fixed weight is assumed. A partial observed-weight sum is explicitly labelled incomplete. Stop on quota exhaustion, HTTP 429/401/403; no automated retries. Calls are sequential with 1.3-second spacing. Response size is limited to 1 MiB. Shared all-sync lock prevents overlap with scheduled price or catalogue refresh; sync_busy_try_again means rerun once the other refresh ends.

Daily data is bounded at 300 fetched rows, weekly at 100. Required closed-bar counts: 20, 50, 200 for the corresponding SMA and at least 15 for RSI14. Limited RSI warmup is disclosed for fewer than 100 closed bars. Missing values remain null. These are observation periods, not a promise of 200 US trading days of adjusted stock data.

The parser uses the current documented seven-column schema: open, high, low, close, volume, timestamp milliseconds, tradeCount. It does not guess another layout. Pagination uses after as an exclusive upper time bound. Data is sorted, exact duplicates removed and conflicting duplicates rejected. OHLC, nonnegative volume/count, timestamps and period gaps are checked. Closure conservatively assumes candle timestamp is interval start; this convention must be verified against actual API responses before production integration. Unfinished bars are excluded. Unexplained gaps or non-UTC boundaries block indicators pending calendar/timestamp validation. No artificial candles are filled.

## Installation and first run

Upload test-rwa-candles.php and test-rwa-technicals.py into:
/home/bhub.tm.biz.tr/private/agent/

Run:

```bash
cd /home/bhub.tm.biz.tr/private/agent
php -l test-rwa-candles.php
python3 test-rwa-technicals.py --self-test
python3 test-rwa-technicals.py --ticker=TSLA --weekly --summary
```

Once TSLA coverage and source semantics are understood, run AAPL and NVDA separately. An unavailable weekly metric does not block the daily MVP. The script uses the CLI PHP executable and does not depend on bag dev or a model being available.

## Delivery schedule impact

Low additional implementation effort: SMA/RSI arithmetic, already implemented here and locally checked using synthetic data. The main uncertainty is data coverage, calendar alignment and historical adjustments, not computation.

Moderate effort: reliable horizontal zones and breakout/retest evidence, now experimental in the diagnostic. Production promotion requires actual chart spot checks and historical replay without future evidence.

Potentially substantial extra work: independent stock history, corporate-action adjustment, alternate vendor licensing or full backtesting if token history proves short or inconsistent. Keep these outside the first MVP's critical path.

Suggested sequence: validate TSLA daily/weekly, then AAPL/NVDA; promote daily indicators that pass; expose weekly context only where history passes; add horizontal-zone evidence after spot checks. Keep the first opportunity screener and bounded agent evidence tools as the main deliverable. Defer extra timeframes, parameter tuning and provider expansion. No calendar delivery date has been promised because hackathon deadline and actual source coverage still need grounding.

## References

https://web3.binance.com/en/dev-docs/catalog/web3-wallet/api/rest-api/general-data
https://academy.binance.com/en/articles/what-is-the-rsi-indicator
https://academy.binance.com/en/articles/moving-averages-explained

## Local verification

PHP 8.3 syntax check passed. Mocked transport checks passed for the exact signed GET path, request metrics, response parsing, redirect restrictions, auth/quota stops and invalid JSON. Python self-tests passed for hand-checked SMA/Wilder RSI values, incomplete/flat histories, unfinished candles, sorting, duplicate conflicts, gaps, weekly scope, horizontal pivot clusters and breakout/retest examples. No real Binance requests were made locally; live response schema and historical availability remain pending VPS test. --summary keeps output compact; omit it for full diagnostic JSON.
