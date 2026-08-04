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
