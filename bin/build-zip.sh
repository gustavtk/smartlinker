#!/usr/bin/env bash
#
# Build the installable plugin zip.
#
# The development directory is ~30MB, almost all of it vendor/ — PHPUnit,
# PHPCS, WPCS and their dependencies. None of that belongs on a web server.
# Beyond being 25MB of dead weight, PHPUnit sitting in a web-accessible
# directory has been the subject of remote-code-execution advisories, so
# shipping it is a genuine risk rather than untidiness.
#
# This produces the runtime only: roughly 1.6MB unpacked, under 500KB zipped.
#
# The exclusion list is a DENY list on purpose. An allow list silently drops
# any new directory somebody adds — a missing template does not fail here, it
# fails on somebody's site.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG="smartlinker"
VERSION="$(grep -m1 "define('SLK_VERSION'" smartlinker.php | sed "s/.*'\([0-9.]*\)'.*/\1/")"
OUT="dist"
ZIP="${OUT}/${SLUG}-${VERSION}.zip"

echo "Building ${SLUG} ${VERSION}…"

# The minified assets must match their sources, or the zip ships a stale build.
if [ -f assets.json ]; then
    php -r '
        $m = json_decode(file_get_contents("assets.json"), true);
        foreach ($m as $f => $h) {
            if (hash_file("sha256", $f) !== $h) {
                fwrite(STDERR, "STALE: $f — run bin/build-assets.sh first\n");
                exit(1);
            }
        }
    '
    echo "  assets are current"
fi

rm -rf "${OUT:?}/${SLUG}" "$ZIP"
mkdir -p "${OUT}/${SLUG}"

rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitignore' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='bin' \
    --exclude='dist' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='phpunit.xml' \
    --exclude='phpcs.xml.dist' \
    --exclude='.phpcs.cache' \
    --exclude='.phpunit.result.cache' \
    --exclude='assets.json' \
    --exclude='HANDOFF.md' \
    --exclude='.DS_Store' \
    ./ "${OUT}/${SLUG}/"

# Fail loudly rather than shipping something that cannot run.
for required in \
    "smartlinker.php" "uninstall.php" "index.php" \
    "core/Slk/Base.php" "core/Slk/Init.php" "core/Slk/Query.php" \
    "css/admin.css" "css/admin.min.css" \
    "js/admin-ui.js" "js/admin-ui.min.js" \
    "js/editor-sidebar.js" "js/editor-sidebar.min.js" \
    "languages/smartlinker.pot" "readme.txt"
do
    if [ ! -f "${OUT}/${SLUG}/${required}" ]; then
        echo "MISSING from the build: ${required}" >&2
        exit 1
    fi
done

# Nothing developer-only may survive.
for forbidden in vendor tests bin .git composer.json phpunit.xml HANDOFF.md; do
    if [ -e "${OUT}/${SLUG}/${forbidden}" ]; then
        echo "LEAKED into the build: ${forbidden}" >&2
        exit 1
    fi
done

php_count=$(find "${OUT}/${SLUG}" -name '*.php' | wc -l | tr -d ' ')
font_count=$(find "${OUT}/${SLUG}/fonts" -name '*.woff2' 2>/dev/null | wc -l | tr -d ' ')

( cd "$OUT" && zip -qr "${SLUG}-${VERSION}.zip" "$SLUG" -x '*.DS_Store' )

echo
echo "  php files : ${php_count}"
echo "  fonts     : ${font_count}"
echo "  unpacked  : $(du -sh "${OUT}/${SLUG}" | cut -f1)"
echo "  zip       : $(du -h "$ZIP" | cut -f1)  →  ${ZIP}"
echo
echo "Install via Plugins → Add New → Upload Plugin."
