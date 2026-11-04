# Pre-Orders for WooCommerce — repository instructions

Read `docs/workflow.md` before writes or reviews and `docs/testing.md` before validation.
This repository maintains its own adaptation of Stock Manager's workflow; it does
not import live instructions or CI code from another repository.

## Ownership and execution

- Human owns product direction, unresolved scope/safety decisions, product acceptance,
  merge, version, release, publication, deployment and rollback.
- One Codex Main owns discovery, technical choices, planning, implementation,
  validation, review, corrections and PR maintenance through the complete result.
- ChatGPT handoffs or approvals are not required. Continue authorized engineering
  work without asking Human to relay plans or issue another Continue command.
- Prefer the smallest complete solution using WordPress/WooCommerce. Do not add
  speculative frameworks, approval gates, mandatory task documents or state ledgers.

## Git and sources of truth

- Inspect branch/base/diff; preserve unrelated work. Use `codex/<scope>` branches,
  stage only task files, and do not commit normal work directly to `main`.
- Never force-push, reset/clean destructively or weaken repository protections.
- Normally use one Draft PR per task. Reuse an Issue when useful; a new Issue or
  planning file is not mandatory. Scope/Acceptance and decisions belong in the
  existing Issue/PR, candidate in Git, checks in CI and findings in PR reviews.
- Keep installed identity `pre-orders-wc`, main file `pre-orders-wc.php`, text domain,
  public hooks, options, metadata, cron hooks and existing integration contracts.

## Risk and review

- `Fast`: isolated docs, CI/tooling/governance, presentation changes and understood
  fixes that preserve stock, persistence, security and public contracts. Record a
  compact goal, scope, Acceptance and validation brief in the existing PR.
- `Controlled`: material changes to preorder availability/stock, quantity limits,
  order snapshots, notifications/deduplication, persistence/migrations, HPOS,
  concurrency, authorization/privacy, public integrations or release safety.
- Before Controlled runtime implementation, record affected invariants, scenarios
  where ambiguous, risks, validation and a proportional complexity budget/stop-loss
  in the existing Issue/PR. Revise if new schema, locking, recovery or destructive
  authority becomes necessary. Ask Human only for a blocking business/scope/safety
  decision outside engineering authority.
- Structural, Functional, Safety-critical and Release profiles describe evidence
  intensity, not extra lanes or approval stages.
- Safety-critical Controlled work affecting stock/data integrity, schema,
  concurrency/replay, authorization or recovery requires fresh independent Codex
  review. Challenge new safety-critical architecture before implementation; review
  the implementation in a separate checkout on the exact candidate SHA.
- Supply bounded subagents with scope, inputs and required outputs when useful.
  Prefer one writer per file and isolated test state. An implementer cannot provide
  independent approval of its own work. Main integrates and verifies the result.
- Record reviewer, reviewed SHA, findings and corrections on the PR. Changed heads
  need affected checks and delta review. Optional cleanup/style does not block.
  If required independent review is unavailable, report the gap without claiming
  merge readiness.

## Product safety

- Preserve both `_pre_order_available_date` and `_extended_status_available_date`
  compatibility and `_wcess_stock_status` / `_wcess_available_date` order snapshots.
- Protect historical order data, preorder tracking, customer delivery preference,
  quantity limits, timezone/date handling, automatic stock transitions and email
  deduplication. Check HPOS/legacy and classic/Blocks paths when affected.
- Changes to capabilities/nonces, sanitization/escaping, SQL or external requests
  require relevant allowed/denied access and privacy evidence.
- Use disposable WordPress databases for fixtures. A shared, production or
  Human-owned store is never disposable test state, even if tests clean up rows.

## CI, handoff and release

- Follow the Iteration/Final model in `docs/workflow.md`. Draft emits `Iteration Gate`;
  only applicable Final CI emits `CI Gate`. Final checks must pass on the actual,
  unchanged PR head before merge readiness. Reassess overlap if the base moves.
- Keep evidence proportional. Record unavailable checks as `NOT RUN — reason`.
  Syntax/source contracts do not prove browser behavior, migrations or integrations
  that were not executed. Do not weaken tests/support boundaries to obtain green CI.
- Keep the PR current with goal/scope/Acceptance, risk/profile, relevant plan,
  changes, evidence, limitations and blocking findings. Use only
  `HUMAN_DECISION_REQUIRED` and `READY_FOR_HUMAN_MERGE` for Human handoffs.
- Before merge handoff, complete Acceptance, required review and exact-head Final
  CI; post one canonical SHA-bound top-level handoff comment. A commit invalidates
  readiness and requires affected checks/review and a refreshed handoff.
- `Continue` and `Review` recover the current Issue/PR, CI and review state. Read
  conversation comments and review submissions in time order; assess their
  applicability to the current head. Missing evidence means unfinished work.
- Human-facing communication is Vietnamese. Preserve source/docs language and
  identifiers. When a final response has a clear next action, use exactly one hint:
  `Next: Human - Merge PR #<N>`, `Next: Human - Decide <TASK-ID>` or
  `Next: Codex - Continue <TASK-ID>` as applicable. Do not stop ongoing engineering
  merely to emit a continuation hint.
- Merge is not release. Version changes, packaging, tags, publishing, deployment
  and rollback need explicit Human authority. Normal engineering preserves version
  and release metadata. Verify source/artifact and upgrade provenance for release.
