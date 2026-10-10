# Deployment notes

This project mirrors the current VPS source tree and is **not yet a complete turn-key installation**. Do not deploy without reviewing host paths, database schema, secrets and systemd definitions.

## Prerequisites

- Linux web server with PHP and PDO MySQL, cURL, and required PHP extensions.
- MySQL/MariaDB database with the RWA catalogue, market snapshot, technical and chat tables expected by the code.
- Python 3 for collectors and technical analysis.
- Node.js >=22, pnpm, and the `bag` runtime for the TypeScript agent (see workspace `README.md`).
- Binance Web3 API credentials and a separately configured Groq/provider account where used.
- BNB Smart Chain wallet connectivity, with explicit end-user authorization for approvals and transactions.

## Files

```text
<site-root>/public_html/          publicly served PHP pages and static files
<site-root>/private/config.php    private runtime config, never in the web root or Git
<site-root>/private/agent/        CLI collectors and helper scripts
```

Start from `private/config.example.php` and supply real credentials only on the target host. `RwaHubAgent/app/agent/studio.example.toml` is an intentionally sanitized template and is **not** an active Studio configuration.

### Important portability constraints

Multiple PHP, Python and systemd scripts refer to the existing VPS installation path `/home/bhub.tm.biz.tr/...`. Those references must be parameterized or updated for a different server. Do not change paths on a running VPS without an audited migration.

### systemd definitions

The original 13 inspected `.service` / `.timer` source copies, plus **2 Wallet Stock Rank service/timer files** (15 total), are kept under `private/agent/`. The original 13 were compared with the VPS on 2026-10-10; the two new Wallet Rank definitions came from the separately verified collector delivery. All installed units and overrides must still be inspected before redeployment:

- The `bnb-rwa-agent.service` file is a **sanitized example**, not a complete drop-in unit. Its environment/credential directive was deliberately removed during export. Restore the correct values securely on the target host before using it; do not commit real API credentials.
- The checked-in `bnb-rwa-technical-sync.service` and `bnb-rwa-ondo-technical-sync.service` include `ExecStartPost` to run `sync-technical-db.php` after their scanner. They retain the non-sensitive `PYTHONUNBUFFERED=1` environment setting from the earlier repository version. The export omitted environment directives, so compare those with the installed units if exact parity is needed.
- `bnb-rwa-catalog-sync` runs `run-catalog-sync.py`, which executes the active `public_html/sync-tokens.php`. The older `private/agent/sync-tokens.php` is intentionally excluded.
- `bnb-rwa-tsla-sync.timer` was **disabled** on the VPS during the last status check. The presence of its unit file in Git does **not** mean it should be enabled.
- Experimental Pieverse probe units were archived and are intentionally not shipped in this repository.
- `bnb-rwa-wallet-stock-rank.service` is a read-only PHP CLI snapshot collector, paired with `bnb-rwa-wallet-stock-rank.timer` (UTC half-hour cadence plus jitter). Review its paths, effective user and privileges against the actual installed unit. Never assume the repository copy is an automatically deployable replacement.
- The deployed unit files under `/etc/systemd/system/`, their local overrides, and actual enabled/active status are authoritative. Source copies are for reference, review, and controlled deployment.

When setting up a new host, inspect unit contents and secrets, copy only selected reviewed units into `/etc/systemd/system/`, run `systemd-analyze verify` where available, and then `systemctl daemon-reload`. Enable or start services **only** according to the intended schedule. Never blindly enable all timers from this directory. For changes on the existing VPS, back up the live units and verify differences before replacing them.

### Wallet Stock Rank database migration and service verification

The Wallet Rank code expects **two additional InnoDB tables**: `rwa_wallet_stock_rank_rows` and `rwa_wallet_stock_rank_meta`. They are defined in [WALLET_STOCK_SNAPSHOT_MIGRATION.sql](WALLET_STOCK_SNAPSHOT_MIGRATION.sql). An existing installation must back up and inspect its database schema and apply that **additive SQL** using its normal controlled migration workflow **before** enabling the collector. Committing the SQL file does not apply it to a VPS.

The service source is at `private/agent/bnb-rwa-wallet-stock-rank.service`, and its timer at `private/agent/bnb-rwa-wallet-stock-rank.timer`. For the existing VPS, verify installed definitions and status without modifying them:

```bash
systemctl cat bnb-rwa-wallet-stock-rank.service bnb-rwa-wallet-stock-rank.timer
systemctl status bnb-rwa-wallet-stock-rank.service bnb-rwa-wallet-stock-rank.timer
systemctl list-timers bnb-rwa-wallet-stock-rank.timer
journalctl -u bnb-rwa-wallet-stock-rank.service -n 30 --no-pager
```

A successful collection is expected to publish a complete, internally validated snapshot. Failed, incomplete or inconsistent source responses must not overwrite the last successful snapshot. The CLI logs observed HTTP duration/count; **public BAPI request weight remains unknown** when the upstream does not document it.

Before a controlled enable/start on a new host, confirm the schema, PHP extensions, DNS/TLS access, CLI service paths, file permissions, locks, timers and source response structure. The Asset Detail's cached, on-demand `asset-skills-api.php` endpoint is separate from this scheduled stock-holder collection.

### Deployment verification

1. Lint PHP/JS, compile the TypeScript workspace, and run offline unit tests (including `test-wallet-skills-asset.php`, `test-wallet-skills-rank.php`, and `test-wallet-stock-snapshot.php`).
2. Confirm the PHP configuration cannot be fetched over HTTP.
3. Verify database schema and source-freshness semantics without inventing missing metrics.
4. Check worker timers, quotas and request weights (including 429/backoff behavior).
5. Test page rendering and asset links.
6. Run wallet quote/approval/sale verification only with deliberate manual review; never auto-send approvals or transactions.

This repository intentionally does not include database exports, live API secrets, or deployment key material.
