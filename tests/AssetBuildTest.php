<?php

use PHPUnit\Framework\TestCase;

/**
 * The minified assets, and whether they are still current.
 *
 * Minified files are build output committed to the repo, which creates one
 * specific trap: edit `css/admin.css`, forget to run `bin/build-assets.sh`,
 * and the plugin serves the OLD minified file. Nothing errors. The change
 * simply does not appear, and the obvious suspects — caching, the wrong
 * selector, a version bump — are all innocent. That can burn an afternoon.
 *
 * `assets.json` records a hash of each SOURCE file at the moment it was last
 * built. If a source has changed since, these tests fail and name the script
 * to run.
 */
class AssetBuildTest extends TestCase
{
    /** Sources whose minified builds ship. */
    const SOURCES = ['css/admin.css', 'js/admin-ui.js', 'js/editor-sidebar.js'];

    protected static function manifest()
    {
        $path = SLK_PLUGIN_DIR . 'assets.json';
        if (!is_readable($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }

    protected static function minified($source)
    {
        return preg_replace('/\.(js|css)$/', '.min.$1', $source);
    }

    public function test_a_manifest_exists()
    {
        $this->assertNotNull(
            self::manifest(),
            'assets.json is missing — run bin/build-assets.sh'
        );
    }

    public function test_every_source_has_a_minified_build()
    {
        foreach (self::SOURCES as $src) {
            $this->assertFileExists(
                SLK_PLUGIN_DIR . self::minified($src),
                self::minified($src) . ' is missing — run bin/build-assets.sh'
            );
        }
    }

    /**
     * The one that matters: a source edited after the last build.
     */
    public function test_minified_assets_are_not_stale()
    {
        $manifest = self::manifest();
        $this->assertNotNull($manifest, 'assets.json is missing — run bin/build-assets.sh');

        foreach (self::SOURCES as $src) {
            $this->assertArrayHasKey($src, $manifest, "{$src} is not in assets.json");
            $this->assertSame(
                hash_file('sha256', SLK_PLUGIN_DIR . $src),
                $manifest[$src],
                "{$src} has changed since the minified build was made. "
                . 'Run bin/build-assets.sh, or the plugin will serve the old file '
                . 'and your change will appear to do nothing.'
            );
        }
    }

    /**
     * A minified file that is not actually smaller means the build did not
     * really run — an empty file, or the source copied over itself.
     */
    public function test_minified_assets_are_meaningfully_smaller()
    {
        foreach (self::SOURCES as $src) {
            $raw = filesize(SLK_PLUGIN_DIR . $src);
            $min = filesize(SLK_PLUGIN_DIR . self::minified($src));

            $this->assertGreaterThan(0, $min, self::minified($src) . ' is empty');
            $this->assertLessThan(
                $raw * 0.9,
                $min,
                self::minified($src) . ' is barely smaller than its source — did the build run?'
            );
        }
    }

    /**
     * Minified JavaScript must not end up named `*admin.min.js` or similar.
     *
     * WordPress strips a `.min.js` suffix before hashing a script path, so
     * `admin-ui.min.js` correctly resolves to the translations generated for
     * `admin-ui.js`. That only holds while the UNMINIFIED name does not itself
     * end in the letters `min.js` — see the note in Slk_Base for the whole
     * trap. This asserts the naming stays safe.
     */
    public function test_script_names_do_not_collide_with_the_min_suffix_rule()
    {
        foreach (self::SOURCES as $src) {
            if (substr($src, -3) !== '.js') {
                continue;
            }
            $base = basename($src, '.js');
            $this->assertStringEndsNotWith(
                'min',
                $base,
                "{$src}: a script whose name ends in \"min\" makes wp i18n make-json "
                . 'write its translations under the wrong hash, and every string in '
                . 'it silently stays untranslated.'
            );
        }
    }
}
