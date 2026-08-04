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
node --test "tests/js/*.test.mjs" || fail=1

echo
if [ "$fail" -eq 0 ]; then
    echo "All suites passed."
else
    echo "FAILURES — see above."
fi
exit $fail
