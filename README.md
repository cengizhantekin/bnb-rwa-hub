# BNB RWA Hub

BNB RWA Hub is a hackathon prototype for exploring tokenized stocks and other real-world assets (RWAs) on BNB Smart Chain. It combines persisted market observations, technical indicators, the read-only **Ask Hubble** AI research agent, and wallet-controlled quote/trading flows.

**Live demonstration:** https://bhub.tm.biz.tr

> Experimental software. Data may be delayed, incomplete, or unavailable. Nothing in this repository is financial advice. The application must not initiate wallet transactions or token approvals without explicit user review and wallet confirmation.

## Product surfaces

- **Dashboard:** Overview, Market Pulse, Opportunities, Providers, and Data Health.
- **Assets and Asset Detail:** underlying-based directory, token representations and provider information, saved market data, technical snapshot, and data freshness.
- **Ask Hubble:** research and comparison using stored evidence and model-assisted interpretation; deterministic actions and explicit, separate trading controls.
- **Buy, Sell, Swap:** quote preparation and explicit wallet-mediated flows, with token/chain/approval verification safeguards.
- **About and policies:** product documentation, security notes, risk, privacy, terms, and cookies.

The supported tokenized-asset provider groupings used by the application are bStocks, Ondo, and xStocks. **Underlying prices/volume/market cap and on-chain token prices are distinct data types** and must not be conflated.

## Repository layout

```text
public_html/                   PHP pages/APIs, CSS, JS, assets, shared includes
private/config.example.php     Placeholder credentials configuration (no real secrets)
private/agent/                 Data collectors, research/trading PHP tools, Python scans
private/agent/tests/           Unit/integration tests
private/agent/RwaHubAgent/     TypeScript agent and package/workspace manifests
docs/                          Architecture, security and deployment notes
```

Runtime state, production database contents, credentials, the private Studio keystore, generated build artifacts, and VPS archives are intentionally **not** included.

## Running and deployment

The current code reflects an operational VPS setup; it is **not yet a one-command, portable installer**. Some CLI/PHP service paths are absolute and need adapting when moving to another host. A correctly provisioned MySQL schema and third-party credentials are required. Read [Deployment](docs/DEPLOYMENT.md) before attempting to run the project.

- **PHP:** pages, JSON APIs, background Binance Web3 API collectors, trading preflight and quote preparation.
- **Python:** timed catalogue/market coordination and technical scans.
- **TypeScript/Node:** Hubble agent workspace; see `private/agent/RwaHubAgent/` (requires Node >=22 and pnpm).
- **MySQL:** persisted underlying/token snapshots and user-conversation state.

See [Architecture](docs/ARCHITECTURE.md), [Data semantics](docs/DATA_SEMANTICS.md), and [Security](docs/SECURITY.md).

## Checks

The provided unit tests can be run independently of live wallet settlement where supported, for example:

```bash
cd private/agent
php tests/unit/test-hubble-sale-selection.php
php tests/unit/test-rwa-sale-validator.php
php tests/unit/test-rwa-swap-validation.php
node tests/unit/test-hubble-buy-pending.js
```

Do **not** run the integration scripts against a live API/wallet without reviewing their options and side effects. In particular, `test-rwa-fallback.py` manipulates operational files and should only run in an isolated environment.

## Development history

Engineering decisions and the chronological development log are maintained separately in [bnb-rwa-hub-devex](https://github.com/cengizhantekin/bnb-rwa-hub-devex).

## License

No software license has been selected yet. All rights are reserved unless a license is added.
