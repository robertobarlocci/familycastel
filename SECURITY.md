# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Instead use
GitHub's private *Report a vulnerability* feature (Security tab) on this
repository. You will get an acknowledgement within a few days.

Please include: affected version, reproduction steps, and impact assessment.

## Design notes for researchers

- All Coin/XP mutations flow through an append-only ledger inside database
  transactions guarded by a global write gate (`ops_state`).
- The updater only accepts HTTPS downloads from GitHub, verifies the package
  SHA256 against the released sidecar, verifies every staged file against the
  `release.json` manifest, and never removes paths outside the swapped code
  entries. Maintenance operations are serialized through a single lock with an
  flock-fenced protocol.
- The installer refuses to run once `config/installed.lock` exists and its
  bootstrap requires the `config/CAN_INSTALL` marker plus a setup token.
- `storage/`, `config/`, `app/`, `views/`, `lang/` are denied over HTTP; the
  installer actively probes this rather than assuming it.
