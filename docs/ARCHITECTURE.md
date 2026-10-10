# Architecture

## Data pipeline

1. PHP catalogue and market collectors query the Binance Web3 RWA/market interfaces; a separate read-only Wallet Skills Stock Rank collector fetches BSC contract-specific holder data.
2. Private Python wrappers and systemd timers schedule bounded batches. A request-window coordinator limits collisions between collector requests and interactive Binance operations.
3. PHP writes verified provider observations and underlying-level ranking snapshots into MySQL. Stored data has its own source and fetch timestamps.
4. The Dashboard, Assets directory, Asset Detail and Hubble read persisted market and stock-rank projections instead of calling external providers for each ranking view. Asset Detail's optional audit/Ondo security adapter uses separate bounded, cached read-only requests.
5. Python/PHP technical tools process completed daily token candles and persist deterministic indicators and rule matches.

## Binance Wallet Skills paths

The ranking collector (`private/agent/sync-wallet-stock-rank.php`) uses the public `crypto-market-rank` Stock Tokens response on chain 56, Most Held ordering. It requests pages of up to 200 and validates response totals, order, uniqueness, chain, token symbol and contract identity against `rwa_tokens`. New rows and snapshot metadata are published together in an InnoDB transaction; a partial/failed fetch cannot replace the last valid snapshot.

The `rwa_wallet_stock_rank_rows` and `rwa_wallet_stock_rank_meta` tables are introduced by `docs/WALLET_STOCK_SNAPSHOT_MIGRATION.sql`. The timer (`bnb-rwa-wallet-stock-rank.timer`) schedules approximately every 30 minutes, with a 20-minute freshness guard against redundant manual execution. The Dashboard's `dashboard-stock-rank-api.php` reads this database snapshot without any live Binance request and exposes the stored collection time, coverage and historical request diagnostics. Asset Detail uses the same contract-keyed rankings.

The distinct `public_html/asset-skills-api.php` path supplies Asset Detail's contract audit and supported Ondo securities metadata using `private/agent/wallet-skills-asset.php`; this read-only path has an approximately 15-minute successful-response cache, shorter unavailable-response caching, bounded calls and restricted external hosts. Its data is not a substitute for the scheduled full-universe holder snapshot.

See [Wallet Skills](WALLET_SKILLS.md) for the supported skills, data meanings and known coverage boundaries.

## Research and AI

The Ask Hubble PHP endpoint handles conversation state and integrates the private TypeScript agent under `private/agent/RwaHubAgent/app/agent/`. The agent reads bounded research/snapshot/opportunity evidence, including a contract-level holder-research mode. Technical calculations, database-backed Most Held tabular answers and source freshness checks are deterministic; the language model interprets the available evidence, and must not invent absent prices, volumes, ranks or candle data.

## Trading boundary

Separate quote, build, preflight, approval and sale helpers prepare transactions for explicit wallet review. Never remove wallet checks, transaction identity/calldata validation, simulation gates or user approval requirements to simplify deployment. Tokenized-asset provider preference for representative data is **bStocks → Ondo → xStocks** where a compatible, verified observation exists; this is a data-quality selection, not an investment ranking.

Research prompts can compare provider contracts by holder count, available KYC holder count and Binance-reported Top 10 concentration, or combine the holder context with an underlying's saved technical indicators. The holder ranking does not combine several provider tokens into a single underlying holder count. No research operation can sign a wallet transaction.

## Runtime layout

- `public_html/includes/`, `css/` and `js/` are the active shared-layout and static asset folders.
- `private/agent/technical-data/`, `rwa-cache/` and `quote-runtime/` are runtime data and are not part of this source tree.
- Actual installed systemd units under `/etc/systemd/system/` may differ from the copies in `private/agent/`; verify live units before redeploying.

The two older experimental Pieverse probe units have been disabled and removed from the running service directory. TSLA experimental sync units were retained but their timer was disabled at the last verified inspection.
