<?php

use PHPUnit\Framework\TestCase;

/**
 * Internal/external classification.
 *
 * This decides `type` and `target_post_id` for every indexed link, and the
 * orphan report is built entirely from those two columns: a page with no row
 * where `target_post_id = <id> AND type = 'internal'` is called an orphan.
 *
 * So a URL form this misreads does not produce an error — it produces a
 * well-linked page sitting on the orphan list, which is what happened on a
 * live site. Every form below was found there or is an obvious neighbour of
 * one that was.
 */
class LinkClassifyTest extends TestCase
{
    /** @dataProvider sameSiteUrls */
    public function test_urls_on_this_site_are_internal($url)
    {
        $this->assertSame('internal', $this->typeOf($url), $url . ' should be internal');
    }

    public function sameSiteUrls()
    {
        return [
            'canonical'          => ['https://example.test/a-post/'],
            'www prefix'         => ['https://www.example.test/a-post/'],
            'other scheme'       => ['http://example.test/a-post/'],
            'www + other scheme' => ['http://www.example.test/a-post/'],
            'uppercase host'     => ['https://EXAMPLE.TEST/a-post/'],
            'mixed case host'    => ['https://Example.Test/a-post/'],
            'protocol relative'  => ['//example.test/a-post/'],
            'relative path'      => ['/a-post/'],
            'leading space'      => [' https://example.test/a-post/'],
            'with query'         => ['https://example.test/a-post/?utm_source=x'],
            'with fragment'      => ['https://example.test/a-post/#section'],
        ];
    }

    /** @dataProvider foreignUrls */
    public function test_other_sites_stay_external($url)
    {
        $this->assertSame('external', $this->typeOf($url), $url . ' should be external');
    }

    public function foreignUrls()
    {
        return [
            'plain other site'   => ['https://google.com/'],
            'www other site'     => ['https://www.wikipedia.org/'],
            // The one that matters: stripping "www." must not make a
            // lookalike domain read as our own.
            'suffix lookalike'   => ['https://example.test.evil.com/a-post/'],
            'prefix lookalike'   => ['https://notexample.test/a-post/'],
            'subdomain'          => ['https://shop.example.test/a-post/'],
        ];
    }

    /**
     * Host comparison, isolated from url_to_postid() so it can be tested
     * without WordPress's rewrite rules.
     */
    private function typeOf($url)
    {
        $m = new ReflectionMethod('Slk_Link', 'normalise_host');
        $m->setAccessible(true);

        $home = $m->invoke(null, 'example.test');
        $theirs = $m->invoke(null, parse_url(trim($url), PHP_URL_HOST));

        return ($theirs === '' || $theirs === $home) ? 'internal' : 'external';
    }

    public function test_normalise_host_folds_case_and_www()
    {
        $m = new ReflectionMethod('Slk_Link', 'normalise_host');
        $m->setAccessible(true);

        foreach (['example.test', 'www.example.test', 'WWW.Example.Test', ' example.test '] as $h) {
            $this->assertSame('example.test', $m->invoke(null, $h), $h);
        }
    }

    public function test_normalise_host_only_strips_a_leading_www()
    {
        $m = new ReflectionMethod('Slk_Link', 'normalise_host');
        $m->setAccessible(true);

        // "www" inside the name is part of the name.
        $this->assertSame('wwwexample.test', $m->invoke(null, 'wwwexample.test'));
        $this->assertSame('my.www.test', $m->invoke(null, 'my.www.test'));
    }
}
