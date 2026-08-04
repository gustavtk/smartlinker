#!/usr/bin/env bash
#
# Run every test: PHP and JavaScript.
#
# Two runners exist because the two languages are tested in the way each is
# cheapest to test — PHPUnit with stubbed WordPress functions, and node:test
# against the real shipped JS in a sandbox. Neither needs a database, a
# WordPress install, or a browser. One command so nobody has to remember both.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0

echo "── PHP ──────────────────────────────────────────────"
./vendor/bin/phpunit || fail=1

echo
echo "── JavaScript ───────────────────────────────────────"
node --test tests/js/*.test.mjs || fail=1

echo
echo "── WordPress security / PHP compatibility (advisory) ─"
# Advisory, not a gate — and deliberately so.
#
# Every finding it still reports was reviewed by hand and is a pattern PHPCS
# cannot follow: helper methods that escape internally, IN-clause placeholder
# strings built with array_fill(), and COUNT(*) against the plugin's own
# tables. Failing the build on those would make a red result meaningless.
#
# It stays in the run because it is worth reading when the count CHANGES —
# a new number here means new code did something the old code did not.
# phpcs.xml.dist documents every exclusion and why.
./vendor/bin/phpcs --report=summary || true

echo
if [ "$fail" -eq 0 ]; then
    echo "All suites passed."
else
    echo "FAILURES — see above."
fi
exit $fail
