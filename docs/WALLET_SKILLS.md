# Binance Wallet Skills integration

This document describes the **implemented, read-only** Binance Wallet Skills data flows in BNB RWA Hub, as represented in the main repository at the October 10, 2026 source-sync checkpoint. It complements [Architecture](ARCHITECTURE.md), [Data semantics](DATA_SEMANTICS.md), [Deployment](DEPLOYMENT.md) and [Security](SECURITY.md).

## Where the features appear

| Product surface | Feature | Data path |
| --- | --- | --- |
| Dashboard | **Most Held Stock Tokens**, ranked by Binance-reported holders, with provider token symbols, logos, KYC holders and Top 10 holder concentration | Stored Stock Rank snapshot via `public_html/dashboard-stock-rank-api.php` |
| Asset Detail | **Token & Security Overview**: provider-token holder rank, KYC/total holders, concentration and supported contract security audit | Stock Rank snapshot plus `public_html/asset-skills-api.php` |
| Ask Hubble | Holder leaderboards, comparisons of provider contracts, and holder information alongside technical indicators | Bounded database research via `private/agent/rwa-research.php` and TypeScript `rwaResearchTool.ts` |

The existing **RWA representations** information (token prices, BSC contracts and token/share ratios) remains conceptually distinct. An on-chain token price is not an executable swap quote.

## Supported Binance Wallet Skills

1. **`crypto-market-rank`**, Stock Tokens on BNB Smart Chain, `rankType=40`, `chainId=56`, `sortBy=30` (Most Held), `period=50`. The collector requests up to 200 items per page and publishes the **complete** validated response.
2. **`query-token-audit`**, per active, validated BSC token contract. The interface shows a supported audit result when available and **N/A** otherwise.
3. **`binance-tokenized-securities-info`**, supported Ondo tokenized-security status, reference information and optional attestation links. These are not used to infer missing security scores or overwrite underlying prices.

These are **read-only public-data calls**; they do not grant wallet-control powers.

## Stock ranking storage and coverage

- **Collector:** `private/agent/sync-wallet-stock-rank.php`, run by `bnb-rwa-wallet-stock-rank.service` / `.timer` approximately every 30 minutes. A recent snapshot less than 20 minutes old is not refetched during an ordinary collector invocation.
- **Migration:** `docs/WALLET_STOCK_SNAPSHOT_MIGRATION.sql`, creating `rwa_wallet_stock_rank_rows` (contract-keyed snapshot rows) and `rwa_wallet_stock_rank_meta` (single active snapshot pointer, coverage and diagnostics).
- **Publishing:** a complete paginated response must match the active BSC contract catalogue by exact address and symbol, with stable total and valid rank ordering. Results and metadata are committed atomically. Errors retain the previous valid published snapshot.
- **Observed coverage, 2026-10-10:** 488 ranked contracts, made up of 46 bStocks and 442 Ondo, fully matched to locally active BSC contracts; the catalogue had 618 active BSC provider tokens. The other 130 xStocks were not returned in that Binance ranking snapshot. **488 is a dated observation, not a hard-coded guarantee or a denominator for later runs.**
- **Request accounting:** a verified 488-item run used 3 public Binance BAPI calls (pages 200 + 200 + 88). The runtime stores request count and summed HTTP duration. The public BAPI **request weight is unknown**, not 0.

## How to read the numbers

- **Holder Rank** (`#n / total`) is within the Binance-returned, verified Stock Tokens ranking universe; it is **not** a ranking of unique underlying companies or all local provider tokens.
- **Holders** is the Binance-reported count for one blockchain token contract; never add multiple provider tokens' counts as though they represented distinct people.
- **KYC Holders** is a separate Binance field. In the 2026-10-10 sample, 25 of 488 ranked contracts had missing/unusable KYC values. Missing is **N/A**, whereas a valid reported zero is **0**.
- **Top 10 Holder %** is the reported concentration for one contract's ten largest holders. It does not, by itself, diagnose overall security, beneficial ownership or trading suitability.
- **Security Audit** is contract specific and subject to source support. Unsupported audit classification means **N/A**, never a default `LOW` or a blanket safe/unsafe judgment.

All data is timestamped. The Dashboard reads the database snapshot without an outgoing Binance call, and labels rank data as stale once the saved fetch time exceeds 90 minutes. Asset Detail's supported live-read audit/Ondo data is cached (normally 15 minutes for available results, shorter when unavailable); this cache is **not** the stock-rank collector.

## Hubble examples

Examples exercised in the live app on 2026-10-10:

```text
What are the 5 most held stock tokens on BNB Chain?
Compare NVDAB and NVDAon by holders, KYC holders and concentration.
Analyze NVDA in detail, including holder distribution and technical indicators.
```

The first request can use saved contract ranking evidence; the comparison keeps provider identities separate. The mixed analysis combines deterministic technical evidence with validated holder context. Model responses depend on available data and model availability and are not investment advice.

## Offline tests and operational checks

Run the included offline fixture tests from the repository root (no Binance request or wallet action is required):

```bash
php private/agent/tests/unit/test-wallet-skills-asset.php
php private/agent/tests/unit/test-wallet-skills-rank.php
php private/agent/tests/unit/test-wallet-stock-snapshot.php
```

These **offline tests do not verify live VPS data or service health**. For deployment, apply the SQL migration in a controlled database procedure, inspect the installed systemd unit and timer, and read the collector journal. Do not equate a successful build or mock fixture with a live collection or wallet transaction.

## Scope limitations

- Binance's Stock Tokens ranking need not cover every token in the Hub's catalogue; absent contracts must show unavailable ranks and counts rather than zero or invented values.
- Holder ranking, audit reports, Ondo asset metadata and traditional-market fundamentals are different sources/meanings. Preserve their original labels and timestamps.
- The application exposes no automatic approval or swap execution authority to Hubble. Wallet permissions and trades remain individually reviewed and signed by the user.
