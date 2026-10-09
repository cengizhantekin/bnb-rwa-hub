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

This source export includes some service/timer templates under `private/agent/`, but **not the full current `/etc/systemd/system/` set**. In particular, verify the live `bnb-rwa-agent`, `bnb-rwa-catalog-sync` and `bnb-rwa-chat-cleanup` definitions before constructing a fresh deployment. Installed `systemd` units and timer status, not just repository copies, determine the active execution paths.

### Deployment verification

1. Lint PHP/JS, compile the TypeScript workspace, and run offline unit tests.
2. Confirm the PHP configuration cannot be fetched over HTTP.
3. Verify database schema and source-freshness semantics without inventing missing metrics.
4. Check worker timers, quotas and request weights (including 429/backoff behavior).
5. Test page rendering and asset links.
6. Run wallet quote/approval/sale verification only with deliberate manual review; never auto-send approvals or transactions.

This repository intentionally does not include database exports, live API secrets, or deployment key material.
