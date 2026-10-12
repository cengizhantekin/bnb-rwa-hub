# BNB RWA Hub

BNB RWA Hub is a hackathon prototype for exploring tokenized stocks and other real-world assets (RWAs) on BNB Smart Chain. It combines persisted market observations, technical indicators, the read-only **Ask Hubble** AI research agent, and wallet-controlled quote/trading flows.

**Live demonstration:** https://bhub.tm.biz.tr

> Experimental software. Data may be delayed, incomplete, or unavailable. Nothing in this repository is financial advice. The application must not initiate wallet transactions or token approvals without explicit user review and wallet confirmation.

## Product surfaces

- **Dashboard:** Overview, Market Pulse, Opportunities, Providers, Data Health, and the **Most Held Stock Tokens** ranking sourced from saved Binance Wallet Skills snapshots.
- **Assets and Asset Detail:** underlying-based directory, distinct provider-token representations, token/share ratios, saved market and technical evidence, plus contract-specific holder rank, KYC/total holders, Top 10 concentration and supported security audits.
- **Ask Hubble:** read-only research and comparison using stored market, technical and holder-distribution evidence; model-assisted interpretation, deterministic data-backed ranking answers, and separate wallet-reviewed trading controls.
- **Buy, Sell, Swap:** quote preparation and explicit wallet-mediated flows, with token/chain/approval verification safeguards.
- **About and policies:** product documentation, security notes, risk, privacy, terms, and cookies.

The supported tokenized-asset provider groupings used by the application are bStocks, Ondo, and xStocks. **Underlying prices/volume/market cap and on-chain token prices are distinct data types** and must not be conflated.

## Binance Wallet Skills integration

The application uses documented **read-only Binance Wallet Skills data** alongside its existing Binance Web3/RWA market sources:

- **`crypto-market-rank` (Most Held):** a scheduled PHP collector fetches the BNB Chain stock-token ranking, validates contract identity against the active catalogue, and atomically publishes a MySQL snapshot about every 30 minutes. The Dashboard, Asset Detail and Hubble consume stored holder-rank evidence.
- **`query-token-audit`:** Asset Detail requests bounded, contract-specific audit results through a cached read-only PHP adapter. Unsupported or missing results are shown as **N/A**, never as an inferred low risk.
- **`binance-tokenized-securities-info`:** where supported, Asset Detail retrieves additional Ondo tokenized-security information, with separate availability and freshness.

A verified collection on **2026-10-10** contained **488 ranked BSC contracts** (46 bStocks and 442 Ondo), compared with **618 active local RWA contracts**; the remaining 130 xStocks were not ranked by that response. These are historical coverage observations, **not** a guarantee of future totals. Holder counts and concentrations apply to **individual contracts**, not unique people or combined underlying ownership. Missing KYC counts remain unknown rather than zero.

Wallet Skills market research does not grant Hubble wallet signing, approval, or transaction powers. See [Wallet Skills](docs/WALLET_SKILLS.md), [Data semantics](docs/DATA_SEMANTICS.md) and [Security](docs/SECURITY.md) for scope and safeguards.

## Repository layout

```text
public_html/                   PHP pages/APIs, CSS, JS, assets, shared includes
private/config.example.php     Placeholder credentials configuration (no real secrets)
private/agent/                 Data collectors, research/trading PHP tools, Python scans
private/agent/tests/           Unit/integration tests
private/agent/RwaHubAgent/     TypeScript agent and package/workspace manifests
docs/WALLET_STOCK_SNAPSHOT_MIGRATION.sql  Additive SQL for saved stock rank
docs/                          Architecture, security and deployment notes
```

Runtime state, production database contents, credentials, the private Studio keystore, generated build artifacts, and VPS archives are intentionally **not** included.

## Running and deployment

The current code reflects an operational VPS setup; it is **not yet a one-command, portable installer**. Some CLI/PHP service paths are absolute and need adapting when moving to another host. A correctly provisioned MySQL schema and third-party credentials are required. Read [Deployment](docs/DEPLOYMENT.md) before attempting to run the project.

- **PHP:** pages, JSON APIs, background Binance Web3 API collectors, trading preflight and quote preparation.
- **Python:** timed catalogue/market coordination and technical scans.
- **TypeScript/Node:** Hubble agent workspace; see `private/agent/RwaHubAgent/` (requires Node >=22 and pnpm).
- **MySQL:** persisted underlying/token snapshots and user-conversation state.

See [Architecture](docs/ARCHITECTURE.md), [Data semantics](docs/DATA_SEMANTICS.md), [Wallet Skills](docs/WALLET_SKILLS.md), [Deployment](docs/DEPLOYMENT.md), and [Security](docs/SECURITY.md).

## Checks

The provided unit tests can be run independently of live wallet settlement where supported, for example:

```bash
cd private/agent
php tests/unit/test-hubble-sale-selection.php
php tests/unit/test-rwa-sale-validator.php
php tests/unit/test-rwa-swap-validation.php
node tests/unit/test-hubble-buy-pending.js
php tests/unit/test-wallet-skills-asset.php
php tests/unit/test-wallet-skills-rank.php
php tests/unit/test-wallet-stock-snapshot.php
```

Do **not** run the integration scripts against a live API/wallet without reviewing their options and side effects. In particular, `test-rwa-fallback.py` manipulates operational files and should only run in an isolated environment.

Live operations should be distinguished from these offline tests; the new stock-rank collector must not run without its database migration and an explicitly reviewed schedule. See [Deployment](docs/DEPLOYMENT.md).

## License

No software license has been selected yet. All rights are reserved unless a license is added.
