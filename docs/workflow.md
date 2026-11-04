# Pre-Orders — Codex-owned engineering workflow

This is the canonical workflow for `yoohwz/pre-orders-wc`. It adapts Stock Manager's
`AGENTS.md`, `docs/workflow.md` and Iteration/Final CI from
`yoohwz/wc-extented-stock-status` at commit
`d21f40a595d4203aa789355c4d43bd4f5dd226fd`.
The two workflows are independent: later SM changes do not automatically update PO.
`AGENTS.md` contains the operating rules; `docs/testing.md` defines actual coverage.

## Execution and authority

`Human goal -> Codex brief/plan -> implementation -> tests/runtime evidence -> proportional review -> corrections -> applicable Final CI -> Human merge gate`.

Human owns product decisions, acceptance and merge/release/production authority.
One Codex Main owns the complete technical result. ChatGPT is not a required
participant or approval step. Use one existing Issue/PR for scope and decisions,
Git for candidate/diff, CI for checks and PR discussion/reviews for findings.
Do not mirror those records into another ledger or mandatory planning file.

Use `Fast` for bounded low-risk work and `Controlled` for material stock, data,
security, compatibility or release risk. Classification follows semantics rather
than filenames. A Fast brief records goal, scope, Acceptance and validation.
Controlled plans add affected invariants, risks, ambiguous scenarios and a
proportional complexity budget before runtime implementation. Promote the same
task if discovery reveals greater risk; ask Human only for decisions outside the
authorized engineering scope.

Fresh independent review is mandatory for safety-critical Controlled architecture
and implementation as specified in `AGENTS.md`. Bind review evidence to the exact
candidate and review correction deltas. Optional architecture/style preferences
do not become blockers without a material defect or risk.

## Iteration and Final CI

`.github/workflows/pre-orders-compatibility.yml` runs on PR lifecycle events,
including ready/draft transitions, `main` pushes and manual dispatch. PR jobs
check out the actual PR head, not the synthetic merge commit. Feature-branch
pushes do not duplicate PR runs.

| Mode | Always | When applicable | Aggregate signal |
| --- | --- | --- | --- |
| Draft / Iteration | CI/repository contracts, diff hygiene | PHP syntax/source contracts, JS syntax, one representative runtime environment | `Iteration Gate` |
| Ready PR / main / manual / Final | CI/repository contracts, diff hygiene | PHP syntax/source contracts, JS syntax, all compatibility environments | `CI Gate` |

`tests/compatibility-matrix.json` is the single source of environments. Draft runtime
work uses PHP 8.2 with HPOS; Final runtime work runs all four environments. Each
selected environment runs the entire existing preorder suite and checks its actual
order-storage mode. Ordinary governance-only edits stay cheap. Unknown paths and
empty change sets select runtime checks; Final CI-control changes and manual
dispatch exercise the complete supported graph.

Required failed, cancelled, skipped or missing jobs fail the gate. Irrelevant jobs
may skip. `Iteration Gate` never substitutes for Final CI. `CI Gate` is intended
for branch protection; adopting this workflow does not change GitHub protections.
No mutable WordPress/database test-state cache is used.

PO has no Composer/PHPCS/PHPCompatibility stack, JS behavior suite or automated
WordPress.org publisher in this adoption. Syntax and source checks are not claimed
as those tools or as browser evidence. No SM license stub, inventory suite or
premium-plugin checkout is imported.

## Handoff and release

Before `READY_FOR_HUMAN_MERGE`, finish local checks, Acceptance, required review,
corrections and applicable Final CI on the unchanged actual PR head. Convert the
PR to ready so Final CI executes, then post one canonical top-level handoff with
SHA and evidence. Changed heads invalidate readiness; reassess affected checks,
review and overlap with a moved base. Use `NOT RUN — reason` for unavailable checks.

Only `HUMAN_DECISION_REQUIRED` and `READY_FOR_HUMAN_MERGE` are Human handoff states.
Normal engineering continues inside Codex without Human relays or internal phase
approvals. Prior decisions/findings remain valid where applicable; do not invent
historical approvals or import SM's historical release discrepancies into PO.

Workflow adoption changes no runtime code, identity, version or release metadata.
Merge does not authorize packaging, tags, publication, deployment or rollback.
