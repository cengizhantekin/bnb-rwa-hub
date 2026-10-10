# Data semantics and freshness

- **Provider representation:** bStocks, Ondo and xStocks may expose different token contracts, share ratios, reference prices and available metadata for a single underlying ticker.
- **Underlying vs token:** underlying market capitalization and underlying 24-hour volume are not the same as token market capitalization or on-chain traded volume. Never substitute one for the other.
- **Representative data:** Where records have verified compatible semantics, the application prefers bStocks, then Ondo, then xStocks. It does not average incompatible providers or synthesize a missing metric.
- **Snapshot ranking:** `rwa_underlying_market_latest` stores provider-specific verified market data and `rwa_underlying_rank_latest` stores a canonical, underlying-level provider-selected snapshot.
- **Price freshness:** source update time and collection/fetch time are separate. The freshness label should refer to the timestamp of the exact value displayed; a refreshed page does not make stale underlying data fresh.
- **Technical evidence:** daily technical analysis uses observed token candles, not adjusted historical shares. SMA/RSI and support/resistance or breakout-rule matches are deterministic observations, not promised outcomes. Unavailable or insufficient candle history remains unavailable.
- **Trading:** a persisted or indicative price is not an executable quote. Quotes, allowances and on-chain effects must be checked through the explicit user-controlled flow.

## Binance Wallet Skills holder ranking

- **Universe and method:** `crypto-market-rank` BSC Stock Tokens, `chainId=56`, `rankType=40`, Most Held `sortBy=30`, `period=50`. The rank denominator is the complete **received and validated ranking snapshot**, not the entire local token catalogue.
- **Observed coverage (2026-10-10):** 488 Binance-ranked contracts (46 bStocks, 442 Ondo) matched active local contracts; the active local catalogue contained 618 BSC RWA contracts. The 130 xStocks were not present in the returned Stock Rank. These counts can change with later provider responses/catalogue updates.
- **Identity:** ranking rows match **exact BSC contract addresses and token symbols**. A provider's token rank, total holders, KYC holders and Top 10 share belong to that contract only; do not sum holder counts across providers or interpret them as unique individuals or verified beneficial owners.
- **`holders`:** Binance-reported contract-specific holder count, not trading volume or number of underlying shareholders.
- **`kycHolders`:** separate Binance-reported field. A genuine zero is `0`; a missing value remains `NULL`/`N/A`. A verified 2026-10-10 response had 25 missing KYC values among 488 ranked contracts; missing is not evidence of zero or noncompliance.
- **`holdersTop10Percent`:** Binance-reported concentration of the top ten holders of one token contract. It is context, not a complete security, ownership or investment-risk score.
- **Security audit:** on-demand `query-token-audit` information is separate from holder distribution. Display an audit classification only if that contract returns a supported, valid result. Otherwise display **N/A**, never `LOW` by default. No blanket safety guarantee follows from an audit response.
- **Ondo information:** tokenized-securities status/fundamentals and report references are distinct from market prices, holder rankings and contract audits; show only fields supported for the selected token.

## Snapshot and request freshness

- The Wallet Stock Rank collector stores `fetched_at_utc`, full coverage totals, number of Binance HTTP requests and summed HTTP duration. The Dashboard's snapshot-only request has **0 Binance HTTP calls**; stored collector diagnostics describe a **prior collection**, not the user's page request.
- The Dashboard flags the holder snapshot as stale when more than 90 minutes old (`5400` seconds). A 30-minute timer interval is a schedule, not a guarantee of timely source data.
- The public Stock Rank BAPI request weight is **undocumented/unknown**, and must remain `NULL`/unknown rather than being treated as zero or inferred from other Binance endpoint families.
- Other market source times, collection times and technical candle close times remain separate. Never convert a refreshed page timestamp into a claimed fresh source price.
