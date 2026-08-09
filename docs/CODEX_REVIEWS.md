# Codex Reviews — Family Castel

Concise record of major Codex reviews and the decisions taken. Codex acts as second
senior developer throughout the project (model: gpt-5.6-sol, always fresh threads).

| Date | Subject | Verdict | Record |
|---|---|---|---|
| 2026-08-09 | Development plan (12 rounds, v1.0→v1.11, 36 findings fixed) | **APPROVED** (C=0 H=0 M=0) | docs/CODEX_REVIEW_PLAN.md |
| 2026-08-09 | T1+T2 impl (bootstrap+schema, 3 rounds: htaccess coverage, GET_LOCK migrations, base-path boundary, test false positive) | **clean** | commit 3735932 |
| 2026-08-09 | T3 impl (installer, 3 rounds: wizard session-binding, nonce control probe, GET probing, re-binding path, flock semantics, fail-closed chmod, token throttling) | **clean** | this commit |
| 2026-08-09 | T4 impl (auth, 3 rounds: IP-window semantics, subject+IP locks, no-PIN ordering, live guard revalidation) | **clean** | commit 4fec42e |
| 2026-08-10 | T5-T7 impl (core domain, 2 rounds: WriteGate for ALL mutators, reversal forging blocked, atomic controller flows, apply() TOCTOU, level-curve settings + caps, archived read-only, QR canonical base_url) | **clean** | this commit |

Detailed plan-review findings live in `docs/CODEX_REVIEW_PLAN.md`; implementation-review
summaries are appended below as milestones complete.
