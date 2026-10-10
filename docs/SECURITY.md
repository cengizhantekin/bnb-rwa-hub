# Security and disclosure boundaries

## Never commit

- Live `private/config.php`, `.env*`, `.studio/`, keystores, seeds, wallets' signing secrets or provider tokens.
- `studio.toml` from a running instance (it may contain a wallet address, key hash and budget consent metadata); use only `studio.example.toml` as a reference.
- MySQL dumps, runtime cache and quote budget files, persistent technical candle data, logs, backups or archives.

## Operational precautions

- Wallet approval and on-chain submission require explicit user review. The AI agent must not be given a route to sign or send transactions autonomously.
- Preserve quote identity, token/chain/spender checks and receipt verification. Fail closed when data is incomplete.
- Treat the private backend as non-public. Restrict direct HTTP execution of maintenance and test scripts, and review access controls on endpoints before exposing a host publicly.
- Rotate provider secrets if they are inadvertently exposed. `.gitignore` only prevents *untracked* files from being added; it cannot remove credentials already present in Git history.
- Keep VPS backups and full database exports outside any web document root.

## Read-only Binance Wallet Skills boundaries

- The Wallet Skills integrations used here retrieve contract-specific metadata, audit information and market rankings. Their PHP adapters cannot sign transactions, submit approvals or spend assets; Hubble consumes validated read-only database evidence for holder research.
- Ranking ingestion requires an active, exact BSC catalogue contract and matching token symbol. Partial pages, duplicate contracts, unsupported chain identities, malformed holder data and changing catalogue identity must not be published as a new full snapshot.
- Missing KYC values stay unavailable; holder counts do not identify unique humans or beneficial owners. A high/low concentration reading is **not** a security verdict or endorsement.
- Unsupported `query-token-audit` data must produce `N/A`, not a fabricated safe/default risk label. Audit support, RWA asset metadata and wallet-trade permission are three separate questions.
- Treat Asset Detail's external Skills lookups as bounded and cached. Preserve exact-contract validation, fixed remote hosts, response checks and UTC freshness information. Public Binance BAPI **request weight is unknown** unless a trustworthy per-request measure is supplied.
- The holder ranking's `request_weight = NULL` is not zero-cost evidence. The Dashboard's reported 0 Binance requests refers only to serving a saved database snapshot, not the scheduled collector.

## Release checklist

Before making this repository public, repeat automated and manual secret scanning on both the current tree **and Git history**. Validate Studio configuration, server units, integration test side effects, third-party licenses and documented prerequisites.
