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
| 2026-08-10 | T8-T12 impl (gameplay, 2 rounds: slot semantics per quest type, approved_cost_coins snapshot column, gated notifications, atomic celebrations, decision-bool notify guard, journal wish merge; final MEDIUM: decision+sync+notify one transaction) | **clean** | this commit |
| 2026-08-10 | Themes/PWA/seeder impl (3 rounds: non-fatal demo seeding + identity-proved retry reuse, seed lock, scope-keyed SW caches, ThemeService-driven views) | **clean** | this commit |
| 2026-08-10 | T17-T19 ops DEEP review round 1 (1 CRITICAL 9H 8M 2L: swap-resume destroying rollback copy, removed[] arbitrary deletes, fail-open DB-restore failure, lock TOCTOU, token in URL, boot-gate suffix match, OFFSET dump pagination, binary corruption, blind release JSON) | changes-required → fixed | this commit |
| 2026-08-10 | T17-T19 ops round 2 (verified 14/20 fixed; 6 residual + 3 new: rollback ignored in-flight swapping entry, history idempotency crash window, reclaim race on recreated locks, uploads/DB mismatch on late restore failure, OFFSET-not-keyset, cached release bypass, unserialized start, no stale recovery on parent locks, unpinned staged VERSION) | changes-required → fixed | this commit |
| 2026-08-10 | T17-T19 ops rounds 3-8 (round 3: unchecked rollback renames, SELECT-INSERT race, graveyard mtime, upload revert gaps, journal-mismatch staleness, fencing; round 4: pre-004 schema after rollback, ownerless release, fence TOCTOU, re-read reclaim identity; round 5: fail-open fence, empty-graveyard rename; rounds 6-7: reclaim-only mutex still ABA → FULL mutex over every ops.lock mutation across both writers; round 8: **clean**, C=0 H=0 M=0 L=0) | **clean** | this commit |

Detailed plan-review findings live in `docs/CODEX_REVIEW_PLAN.md`; implementation-review
summaries are appended below as milestones complete.
