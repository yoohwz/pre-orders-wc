# Releasing Pre-Orders

This runbook describes the Human-gated GitHub-to-WordPress.org release pipeline. It does not authorize a release. Version selection, Git tag creation, WordPress.org publication, Release Confirmation, and GitHub Release publication remain explicit Human actions mediated by the protected workflows.

## One-time production setup

Before the first production use, a repository administrator and WordPress.org plugin committer must deliberately configure and verify:

1. GitHub Environment `wordpress-org-production`:
   - required Human reviewer(s), with administrator bypass disabled;
   - deployment branch policy allowing protected `main` only;
   - environment variable `WPORG_SVN_USERNAME` for a verified Pre-Orders committer;
   - environment secret `WPORG_SVN_PASSWORD` containing the WordPress.org SVN-specific password.
2. A repository tag ruleset that allows creating version tags and blocks updates, force-pushes and deletion of tags matching `refs/tags/*.*`.
3. `release/wporg-policy.json`:
   - registry slug is `pre-orders-wc` and the SVN root is `https://plugins.svn.wordpress.org/pre-orders-wc`;
   - `assets_mode` is `unchanged`;
   - a WordPress.org plugin committer inspects Release Management and records `release_confirmation.mode` as `enabled`, `disabled`, or `unknown`, with observation provenance. Use `unknown` when uncertain.

Do not store WordPress.org email/tokenized Release Confirmation links in GitHub. They are Human-only WordPress.org credentials.

## Boundary 1: prepare an immutable candidate

1. Create `release/<version>` from reviewed `main`.
2. Update only the release metadata deliberately selected for that release:
   - `pre-orders-wc.php` Version (the runtime version is read from this header);
   - `readme.txt` Stable tag and latest changelog entry, plus Upgrade Notice if supplied;
   - `changelog.txt` newest release;
   - POT `Project-Id-Version`;
   - `Tested up to` only when the exact stable WordPress version has supporting matrix evidence.
   Every active release field must use the selected exact numeric version string.
   Release 1.3.0 already exists in WordPress.org SVN and Git. Use a new version
   explicitly selected by Human for the first production run on this pipeline.
3. Open a normal reviewed PR. Merge only with the protected `CI Gate` green.
4. From the Actions UI on **main**, manually run `Prepare WordPress.org Release Candidate` with:
   - the exact 40-character merged candidate SHA;
   - the exact numeric version.
5. Review the `RC_PREPARED` summary and artifact evidence:
   - exact source SHA/version;
   - deterministic package SHA-256;
   - manifest and expanded-tree SHA-256;
   - release-control bundle SHA-256 covering the allowlist, Plugin Check baseline, builder/validators and preparation workflow;
   - manifest-expanded positive payload and the exact file count recorded in the release evidence;
   - Plugin Check result and reviewed warning-baseline delta;
   - successful exact-SHA `CI Gate` from the Pre-Orders CI workflow on `main`.

Release preparation is read-only. It cannot access WordPress.org credentials and cannot create a tag, Release, SVN commit, or publication.

## Boundary 2: publish after Human approval

From the Actions UI on **main**, manually run `Publish Pre-Orders to WordPress.org` with:

- `operation=publish`;
- the successful preparation run ID;
- the same exact candidate SHA and version, newer than existing numeric SVN tags;
- `dry_run=true` for rehearsal, or `dry_run=false` only for an explicitly authorized production release.

The workflow enforces this sequence:

```text
RC_PREPARED
  -> READ_ONLY_PUBLICATION_PREFLIGHT
  -> HUMAN_PUBLICATION_GATE
  -> FINAL_PRE_MUTATION_REMOTE_RECHECK
  -> TAG_SEALED
  -> SVN_COMMITTED (trunk + new numeric tag atomically)
  -> SVN_VERIFIED
  -> WORDPRESS_ORG_RELEASE_STATE
```

The preflight happens before the protected environment. Review its exact candidate, manifest, current SVN trunk/assets digests, target-tag absence, local SVN delta, and Release Confirmation mode. Approving the environment authorizes that snapshot. The workflow rereads SVN immediately before tag sealing. Competing SVN writers remain outside GitHub concurrency, so coordinate manual publication with the other committers.

The workflow definition and trusted helpers always execute from protected `main`. Candidate source/artifacts are treated as data and are never executed in the secret-bearing step. `WPORG_SVN_PASSWORD` is mapped only to the atomic SVN commit step.

`assets/` is always left unchanged. An existing Git or SVN version tag must match the exact recovery identity or the workflow fails closed; it never moves, deletes, overwrites, or recreates a released tag.

## WordPress.org Release Confirmation

After the SVN commit, the production path performs a fresh checkout and authenticates the exact SVN revision/log message, candidate SHA, manifest, trunk, tag and unchanged assets against the approved preflight. Only that verified result may enter WordPress.org confirmation/propagation handling or populate the durable publication record.

After authenticated SVN verification:

- `enabled` or `unknown` produces `WPORG_RELEASE_CONFIRMATION_PENDING`. The committer checks Release Management and confirms by tokenized email when requested. Once the public version is available, use verification-only.
- `disabled` permits bounded public propagation checks. A timeout produces `WPORG_PROPAGATION_PENDING`; this is not an SVN failure.
- GitHub Release publication is allowed only after `WPORG_PUBLIC_RELEASE_VERIFIED`.

After Human confirmation or propagation delay, run the same publisher from **main** with:

- `operation=verify-only`;
- the original preparation run ID;
- the same candidate SHA/version;
- `original_publish_run_id` set to the run that committed SVN;
- `dry_run=true` to verify public evidence only, or explicitly set `dry_run=false` to publish or reconcile the verified GitHub Release.

The verification-only path has no SVN password, tag-creation command, or SVN write command. It re-authenticates the preparation and original-publish artifacts, verifies the immutable Git tag, SVN revision/log/trunk/tag, unchanged assets, Plugins API version, and expanded public download tree. If the SVN commit response was lost before a publication record could be uploaded, it may reconstruct that record only from the authenticated preflight plus the exact SVN log/tree identity. With `dry_run=false`, the verified evidence authorizes the final job to publish or reconcile the GitHub Release and its exact asset set.

## Recovery table

| Durable state | Safe continuation |
| --- | --- |
| Preflight failed | Correct inputs/state and start a new read-only preflight; no tag exists. |
| Human gate rejected/timed out | Candidate remains `RC_PREPARED`; no mutation occurred. |
| Final recheck failed | Re-run preflight and obtain a new approval for the new snapshot. |
| `TAGGED_NOT_PUBLISHED` | Continue only when the annotated tag resolves to the exact SHA and target SVN tag is absent. Never move the Git tag. |
| SVN commit outcome ambiguous | Run `verify-only`. Exact SVN tag/log/tree/assets evidence reconstructs the missing record and continues; a missing SVN tag may use an explicitly approved publish recovery; any mismatch requires `HUMAN_DECISION_REQUIRED`. |
| `WPORG_RELEASE_CONFIRMATION_PENDING` | Human checks Release Management and confirms if requested, then runs `verify-only`. |
| `WPORG_PROPAGATION_PENDING` | Wait, then run `verify-only`; never recommit SVN. |
| SVN/public verified but GitHub Release missing | Run `verify-only`; exact existing Release is a no-op, mismatch requires Human review. |

When the public ZIP or version is pending, check WordPress.org Release Management before retrying verification. [Release Confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/) and [WordPress.org security review](https://developer.wordpress.org/plugins/wordpress-org/automated-security-review/) can delay public availability.

## Validation without publication

Run `python3 -m unittest discover -s tests/ci -p 'test_*.py' -v` from
the repository root. The release fixtures build the positive payload twice,
verify package and control digests, and exercise staging, drift detection and
post-commit recovery using a temporary SVN repository on disk. The Actions
dry-run requires a prepared candidate with a new version and reads the real SVN
state, then stages the proposed change in a disposable working copy.

Reference implementation: [Thumbnail Manager at b4b2de1](https://github.com/yoohwz/thumbnail-manager/tree/b4b2de10cd058fa2e63322b2a1a73d5245391209).
