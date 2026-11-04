# Pre-Orders testing

## Local checks without WordPress

```bash
python3 -m unittest discover -s tests/ci -p 'test_*.py' -v
php tests/smoke/source-contract.php
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
find js -name '*.js' -print0 | xargs -0 -n1 node --check
git diff --check
```

Source contracts check metadata/version consistency and selected implementation
markers. They do not prove behavior or independently establish compatibility.
CI contract tests exercise selection, negative gate results and workflow wiring.

## WordPress integration

Install WooCommerce and activate `pre-orders-wc` in a disposable WordPress site.
Set the required order-storage mode before running:

```bash
PO_TEST_WP_PATH=/path/to/disposable/wordpress bash tests/run-preorders-suite.sh
```

An explicit path is required so a worktree does not silently use a nearby store.
`PO_TEST_PHP_BIN` and `PO_TEST_WP_CLI` select PHP and the WP-CLI executable/PHAR.
The runner executes source contracts and every `tests/integration/*.php` file.
Fixtures write/delete products, orders, metadata and scheduled work: never use
production, shared or Human-owned store data as disposable test state.

The existing suite covers dual-written product dates, order-item snapshots, mixed
order detection, quantity-limit resolution, delay-email dispatch/deduplication and
automatic return to `instock`. Mail dispatch is intercepted; actual delivery is
not tested. It does not exercise a browser checkout flow, full authorization
boundaries, concurrency or all upgrade scenarios.

The optional Stock Manager bridge assertions run only when its migration class is
loaded. Hosted PO CI does not install the private premium plugin, so it does not
prove the bridge. Changes to that contract require separate disposable upgrade
evidence with an identified SM commit/artifact. Similarly, changes to classic or
Blocks UI behavior require targeted browser checks; JS syntax is insufficient.

## Hosted compatibility coverage

`tests/compatibility-matrix.json` declares PO's existing minimum support claims:
WordPress 6.5, WooCommerce 8.0 and PHP 7.4. Its four explicit test combinations
cover PHP 7.4/8.0/8.2/8.4, legacy order storage and HPOS. The oldest job uses
WordPress 6.5.5 rather than SM's below-minimum WordPress 6.3.5. These combinations
are a sampled matrix, not a claim that every version permutation is tested.

Every hosted integration job creates a fresh WordPress database with MySQL 8.0,
installs exact WP/WC versions, activates PO and verifies actual storage through
`OrderUtil::custom_orders_table_usage_is_enabled()` before the suite. HPOS sync
is disabled to avoid masking accidental dependence on legacy order records.
There is no SM license bypass and no release/publishing step.

See `docs/workflow.md` for Draft/Final selection. Record the candidate SHA, executed
checks and any `NOT RUN — reason`; a green gate proves only selected checks.
