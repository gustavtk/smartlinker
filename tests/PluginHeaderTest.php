<?php

use PHPUnit\Framework\TestCase;

/**
 * The plugin header.
 *
 * This exists because the header drifted 27 versions out of date without
 * anyone noticing. `SLK_VERSION` is what the code uses to cache-bust CSS and
 * JS, so bumping it is part of every visible change and never gets forgotten.
 * The header is what WordPress reads for the Plugins list and for update
 * checks — nothing in normal development reads it, so nothing complains when
 * it is wrong. Two sources of truth where only one is exercised is a drift
 * waiting to happen, and it duly happened.
 *
 * The header cannot be generated from the constant: WordPress parses it as
 * text before any PHP runs. So the duplication stays and this asserts it.
 */
class PluginHeaderTest extends TestCase
{
    protected static function header()
    {
        return file_get_contents(SLK_PLUGIN_DIR . 'smartlinker.php');
    }

    protected static function field($name)
    {
        if (preg_match('/^\s*\*\s*' . preg_quote($name, '/') . ':\s*(.+)$/mi', self::header(), $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** The whole reason this file exists. */
    public function test_header_version_matches_the_constant()
    {
        preg_match("/define\('SLK_VERSION',\s*'([^']+)'\)/", self::header(), $m);
        $this->assertNotEmpty($m, 'SLK_VERSION could not be read');

        $this->assertSame(
            $m[1],
            self::field('Version'),
            'The plugin header Version and SLK_VERSION have drifted. WordPress shows the '
            . 'header in the Plugins list and uses it for update checks; the constant only '
            . 'cache-busts assets. Both must be bumped together.'
        );
    }

    /**
     * The version now lives in THREE places: the plugin header, SLK_VERSION,
     * and readme.txt's Stable tag. WordPress.org reads the Stable tag to
     * decide which version to serve, so a stale one there does not look like
     * a mistake — it silently ships the wrong release.
     */
    public function test_readme_stable_tag_matches_the_version()
    {
        $readme = SLK_PLUGIN_DIR . 'readme.txt';
        $this->assertFileExists($readme);

        preg_match('/^Stable tag:\s*(.+)$/mi', file_get_contents($readme), $m);
        $this->assertNotEmpty($m, 'readme.txt has no Stable tag');

        $this->assertSame(
            self::field('Version'),
            trim($m[1]),
            'readme.txt Stable tag and the plugin header have drifted. WordPress.org '
            . 'serves whatever the Stable tag names, so this must be bumped with the rest.'
        );
    }

    /**
     * The support floors are stated in two places and read by different
     * things: WordPress reads the header, wordpress.org reads the readme.
     */
    public function test_readme_support_floors_match_the_header()
    {
        $readme = file_get_contents(SLK_PLUGIN_DIR . 'readme.txt');
        foreach (['Requires at least', 'Requires PHP'] as $field) {
            preg_match('/^' . preg_quote($field, '/') . ':\s*(.+)$/mi', $readme, $m);
            $this->assertNotEmpty($m, "readme.txt has no {$field}");
            $this->assertSame(
                self::field($field),
                trim($m[1]),
                "{$field} differs between readme.txt and the plugin header"
            );
        }
    }

    /**
     * No method may call itself as its first statement.
     *
     * This is not hypothetical. Adding Slk_Keyword::flush() meant replacing
     * every `delete_transient(self::COUNT_TRANSIENT);` with `self::flush();`
     * — and the replacement also rewrote that line INSIDE flush() itself,
     * making it infinitely recursive. It passed `php -l`, passed every unit
     * test, and would have been a stack overflow on every front-end page view
     * of a live site. It was caught only because a performance measurement
     * hung.
     *
     * The same shape of mistake destroyed seven methods earlier in this
     * project. Bulk find-and-replace does not know which line it is standing
     * on; this test does.
     */
    public function test_no_method_immediately_calls_itself()
    {
        $offenders = [];
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            $src = file_get_contents($file);
            preg_match_all(
                '/(?:public|protected|private) static function (\w+)\s*\([^)]*\)\s*\{(.*?)\n    \}/s',
                $src,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $name = $hit[1];
                // First executable line of the body.
                $lines = array_values(array_filter(array_map('trim', explode("\n", $hit[2])), function ($l) {
                    return $l !== '' && strpos($l, '//') !== 0 && strpos($l, '*') !== 0 && strpos($l, '/*') !== 0;
                }));
                if (empty($lines)) {
                    continue;
                }
                if (preg_match('/^(?:return\s+)?self::' . preg_quote($name, '/') . '\s*\(/', $lines[0])) {
                    $offenders[] = basename($file) . '::' . $name . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These call themselves as their first statement — infinite recursion:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * No asset may be loaded from a third party.
     *
     * WordPress.org requires plugins to serve their own CSS, JS and fonts; a
     * remote one is rejected at review. It is also a privacy cost — the admin
     * font used to come from Google, which meant every administrator viewing a
     * SmartLinker screen handed Google their IP for nothing.
     *
     * api.openai.com is exempt: it is a service the user opts into with their
     * own key, not an asset, and it is disclosed in the readme.
     */
    public function test_no_asset_is_loaded_from_a_third_party()
    {
        $allowed = ['api.openai.com'];

        $files = array_merge(
            glob(SLK_PLUGIN_DIR . 'core/Slk/*.php'),
            glob(SLK_PLUGIN_DIR . 'templates/*.php'),
            glob(SLK_PLUGIN_DIR . 'css/*.css'),
            glob(SLK_PLUGIN_DIR . 'js/*.js')
        );

        // Asserted so the test cannot pass by scanning nothing — a silent
        // zero-assertion pass is how a guard stops guarding.
        $this->assertGreaterThan(50, count($files), 'far fewer files than expected — has the layout changed?');

        $offenders = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            // Only flag hosts in a position that actually fetches something.
            if (!preg_match_all(
                '#(?:src|href|url\(|wp_enqueue_(?:style|script)\()[^\n]{0,80}?https://([a-z0-9.-]+\.[a-z]{2,})#i',
                $src,
                $m
            )) {
                continue;
            }
            foreach (array_unique($m[1]) as $host) {
                if (!in_array($host, $allowed, true)) {
                    $offenders[] = basename($file) . ' → ' . $host;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These load assets from a third party. Plugins must serve their own assets — "
            . "bundle them and reference them relatively:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * WordPress.org requires any third-party service a plugin contacts to be
     * disclosed. This plugin talks to two — Google Fonts on its admin screens,
     * and OpenAI when the user opts in with their own key.
     */
    public function test_readme_discloses_every_external_service()
    {
        $readme = file_get_contents(SLK_PLUGIN_DIR . 'readme.txt');

        $hosts = [];
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            if (preg_match_all('#https://([a-z0-9.-]+\.[a-z]{2,})#', file_get_contents($file), $m)) {
                $hosts = array_merge($hosts, $m[1]);
            }
        }
        // Policy/terms URLs in the readme itself are not services we call.
        $hosts = array_unique(array_filter($hosts, function ($h) {
            return !in_array($h, ['www.gnu.org', 'policies.google.com', 'openai.com', 'wordpress.org', 'example.com'], true);
        }));

        $this->assertNotEmpty($hosts, 'no external hosts parsed — has the check stopped working?');
        foreach ($hosts as $host) {
            $this->assertStringContainsString(
                $host,
                $readme,
                "{$host} is contacted by the plugin but not disclosed in readme.txt"
            );
        }
    }

    public function test_version_is_a_sane_number()
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', self::field('Version'));
    }

    /**
     * load_plugin_textdomain() points at /languages explicitly, but tools that
     * only read the header — WordPress.org, translation platforms — need
     * Domain Path to find the same directory.
     */
    public function test_domain_path_is_declared_and_matches_the_loader()
    {
        $this->assertSame('/languages', self::field('Domain Path'));
        $this->assertStringContainsString(
            "dirname(SLK_PLUGIN_BASENAME) . '/languages'",
            self::header(),
            'Domain Path and the load_plugin_textdomain() call must name the same directory'
        );
    }

    public function test_text_domain_matches_the_loader()
    {
        $this->assertSame('smartlinker', self::field('Text Domain'));
        $this->assertStringContainsString("load_plugin_textdomain('smartlinker'", self::header());
    }

    public function test_support_floors_are_declared()
    {
        $this->assertNotNull(self::field('Requires at least'), 'no minimum WordPress version declared');
        $this->assertNotNull(self::field('Requires PHP'), 'no minimum PHP version declared');
    }

    /** The languages directory has to exist for the loader to find anything. */
    public function test_languages_directory_exists()
    {
        $this->assertDirectoryExists(SLK_PLUGIN_DIR . 'languages');
    }

    public function test_a_pot_file_is_shipped()
    {
        $this->assertFileExists(
            SLK_PLUGIN_DIR . 'languages/smartlinker.pot',
            'without a POT there is nothing for a translator to start from'
        );
    }
}
