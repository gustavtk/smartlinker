#!/usr/bin/env bash
#
# Minify the admin CSS and JS.
#
# The plugin has no build step for development on purpose — the source files
# are what runs when SCRIPT_DEBUG is on, and they are what you edit. This
# script only produces the shipped .min files, and it is a release step, not
# something you need in order to work on the plugin.
#
# It writes languages/../assets.json alongside them, recording a hash of each
# SOURCE file. A unit test compares those hashes against the current sources,
# so editing admin.css and forgetting to re-run this fails the suite instead of
# silently shipping stale minified assets — which would look like your change
# simply not working.
#
# Requires network access the first time (npx fetches terser and clean-css).
set -euo pipefail
cd "$(dirname "$0")/.."

TERSER="terser@5.36.0"
CLEANCSS="clean-css-cli@5.6.3"

echo "Minifying JavaScript…"
npx --yes "$TERSER" js/admin-ui.js        -c -m --comments false -o js/admin-ui.min.js
npx --yes "$TERSER" js/editor-sidebar.js  -c -m --comments false -o js/editor-sidebar.min.js

echo "Minifying CSS…"
npx --yes "$CLEANCSS" -O2 -o css/admin.min.css css/admin.css

echo "Writing assets.json…"
php -r '
$files = ["css/admin.css", "js/admin-ui.js", "js/editor-sidebar.js"];
$out = [];
foreach ($files as $f) { $out[$f] = hash_file("sha256", $f); }
file_put_contents("assets.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

echo
printf "%-26s %9s %9s\n" FILE SOURCE MINIFIED
for pair in "css/admin.css:css/admin.min.css" "js/admin-ui.js:js/admin-ui.min.js" "js/editor-sidebar.js:js/editor-sidebar.min.js"; do
    src="${pair%%:*}"; min="${pair##*:}"
    printf "%-26s %8.1fK %8.1fK\n" "$(basename "$src")" \
        "$(echo "$(wc -c < "$src")/1024" | bc -l)" \
        "$(echo "$(wc -c < "$min")/1024" | bc -l)"
done
echo
echo "Done. Commit the .min files and assets.json together with the sources."
