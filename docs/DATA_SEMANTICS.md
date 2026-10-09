# Data semantics and freshness

- **Provider representation:** bStocks, Ondo and xStocks may expose different token contracts, share ratios, reference prices and available metadata for a single underlying ticker.
- **Underlying vs token:** underlying market capitalization and underlying 24-hour volume are not the same as token market capitalization or on-chain traded volume. Never substitute one for the other.
- **Representative data:** Where records have verified compatible semantics, the application prefers bStocks, then Ondo, then xStocks. It does not average incompatible providers or synthesize a missing metric.
- **Snapshot ranking:** `rwa_underlying_market_latest` stores provider-specific verified market data and `rwa_underlying_rank_latest` stores a canonical, underlying-level provider-selected snapshot.
- **Price freshness:** source update time and collection/fetch time are separate. The freshness label should refer to the timestamp of the exact value displayed; a refreshed page does not make stale underlying data fresh.
- **Technical evidence:** daily technical analysis uses observed token candles, not adjusted historical shares. SMA/RSI and support/resistance or breakout-rule matches are deterministic observations, not promised outcomes. Unavailable or insufficient candle history remains unavailable.
- **Trading:** a persisted or indicative price is not an executable quote. Quotes, allowances and on-chain effects must be checked through the explicit user-controlled flow.
