# Architecture

## Data pipeline

1. PHP catalogue and market collectors query the Binance Web3 RWA/market interfaces.
2. Private Python wrappers and systemd timers schedule bounded batches. A request-window coordinator limits collisions between collector requests and interactive Binance operations.
3. PHP writes verified provider observations and underlying-level ranking snapshots into MySQL. Stored data has its own source and fetch timestamps.
4. The Dashboard, Assets directory and Asset Detail APIs read the persisted tables, instead of calling external providers for every page load.
5. Python/PHP technical tools process completed daily token candles and persist deterministic indicators and rule matches.

## Research and AI

The Ask Hubble PHP endpoint handles conversation state and integrates the private TypeScript agent under `private/agent/RwaHubAgent/app/agent/`. The agent reads bounded research/snapshot/opportunity evidence. Technical calculations and source freshness checks are deterministic; the language model interprets the available evidence, and must not invent absent prices, volumes, ranks or candle data.

## Trading boundary

Separate quote, build, preflight, approval and sale helpers prepare transactions for explicit wallet review. Never remove wallet checks, transaction identity/calldata validation, simulation gates or user approval requirements to simplify deployment. Tokenized-asset provider preference for representative data is **bStocks → Ondo → xStocks** where a compatible, verified observation exists; this is a data-quality selection, not an investment ranking.

## Runtime layout

- `public_html/includes/`, `css/` and `js/` are the active shared-layout and static asset folders.
- `private/agent/technical-data/`, `rwa-cache/` and `quote-runtime/` are runtime data and are not part of this source tree.
- Actual installed systemd units under `/etc/systemd/system/` may differ from the copies in `private/agent/`; verify live units before redeploying.

The two older experimental Pieverse probe units have been disabled and removed from the running service directory. TSLA experimental sync units were retained but their timer was disabled at the last verified inspection.
