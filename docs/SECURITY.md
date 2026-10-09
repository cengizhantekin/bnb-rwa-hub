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

## Release checklist

Before making this repository public, repeat automated and manual secret scanning on both the current tree **and Git history**. Validate Studio configuration, server units, integration test side effects, third-party licenses and documented prerequisites.
