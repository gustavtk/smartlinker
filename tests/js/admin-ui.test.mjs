/**
 * The two functions in admin-ui.js that rewrite a user's post content.
 *
 * Everything else in that file paints a screen; if it is wrong you can see
 * that it is wrong. These two edit what gets published, and their failure
 * modes are quiet: a corrupted URL, a nested anchor, the wrong occurrence
 * replaced. None of it looks like an error — it looks like the post.
 *
 * Run with:  node --test tests/js/
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { loadAdminUi } from './harness.mjs';

const slk = loadAdminUi();
const A = '[[LINK]]';   // stand-in replacement, easy to spot

describe('replaceFirstOutsideTags', () => {
    test('replaces a plain-text match', () => {
        assert.equal(
            slk.replaceFirstOutsideTags('<p>Open a spaza shop today.</p>', 'spaza shop', A),
            '<p>Open a [[LINK]] today.</p>'
        );
    });

    test('replaces only the FIRST occurrence', () => {
        const out = slk.replaceFirstOutsideTags(
            '<p>A spaza shop and another spaza shop.</p>', 'spaza shop', A
        );
        assert.equal(out, '<p>A [[LINK]] and another spaza shop.</p>');
        assert.equal(out.match(/\[\[LINK\]\]/g).length, 1);
    });

    test('returns null when the phrase is absent, rather than a mangled string', () => {
        assert.equal(slk.replaceFirstOutsideTags('<p>Nothing here.</p>', 'spaza shop', A), null);
    });

    /**
     * The one that matters most. Linking inside an existing anchor produces
     * nested <a> tags, which browsers silently un-nest into broken markup.
     */
    test('never matches inside an existing anchor', () => {
        const html = '<p><a href="/x">a spaza shop</a> is a shop.</p>';
        assert.equal(slk.replaceFirstOutsideTags(html, 'spaza shop', A), null);
    });

    test('skips an anchor but still links a later plain-text match', () => {
        const html = '<p><a href="/x">spaza shop</a> — every spaza shop needs stock.</p>';
        assert.equal(
            slk.replaceFirstOutsideTags(html, 'spaza shop', A),
            '<p><a href="/x">spaza shop</a> — every [[LINK]] needs stock.</p>'
        );
    });

    /**
     * A phrase appearing inside an attribute must not be touched: replacing
     * it would rewrite a URL, an alt text or a class and break the markup.
     */
    test('never matches inside a tag attribute', () => {
        const html = '<img src="/spaza shop.png" alt="spaza shop" /><p>Buy stock.</p>';
        assert.equal(slk.replaceFirstOutsideTags(html, 'spaza shop', A), null);
    });

    test('leaves a URL containing the phrase intact', () => {
        const html = '<p><a href="/guide/spaza-shop">Guide</a> and a spaza shop.</p>';
        const out = slk.replaceFirstOutsideTags(html, 'spaza shop', A);
        assert.ok(out.includes('href="/guide/spaza-shop"'), 'the URL must be untouched');
        assert.ok(out.includes('[[LINK]]'));
    });

    test('is case-insensitive but preserves the surrounding text', () => {
        assert.equal(
            slk.replaceFirstOutsideTags('<p>Spaza Shop owners.</p>', 'spaza shop', A),
            '<p>[[LINK]] owners.</p>'
        );
    });

    /**
     * Word boundaries: "shop" must not match inside "shopping", or the plugin
     * would insert links into the middle of unrelated words.
     */
    test('respects word boundaries', () => {
        assert.equal(slk.replaceFirstOutsideTags('<p>Go shopping today.</p>', 'shop', A), null);
        assert.equal(
            slk.replaceFirstOutsideTags('<p>Go shopping, then shop.</p>', 'shop', A),
            '<p>Go shopping, then [[LINK]].</p>'
        );
    });

    /**
     * Regex metacharacters in a phrase must be treated as literal text.
     * Unescaped, "a (b)" or "[a-z]+(" would either throw or match wildly.
     */
    test('treats regex metacharacters in the phrase as literal', () => {
        assert.equal(
            slk.replaceFirstOutsideTags('<p>Costs $100 today.</p>', '100', A),
            '<p>Costs $[[LINK]] today.</p>'
        );
        assert.doesNotThrow(() => slk.replaceFirstOutsideTags('<p>x</p>', '[a-z]+(', A));
        assert.doesNotThrow(() => slk.replaceFirstOutsideTags('<p>x</p>', '((((', A));
    });

    /**
     * A documented limitation, not a defect here: a phrase that begins or ends
     * with a non-word character can never match, because \b needs a word /
     * non-word transition and there is none between "+" and a following
     * space. So "C++" is unlinkable.
     *
     * This is asserted rather than fixed because the PHP side uses the same
     * `\b…\b` construction (Slk_Link, Slk_AI, Slk_TargetKeyword) and behaves
     * identically — verified directly. The two engines agreeing matters more
     * than the edge case, and a link the editor inserts must be one the
     * server would also have inserted. If this is ever changed, change both.
     */
    test('phrases bounded by non-word characters do not match — and PHP agrees', () => {
        assert.equal(slk.replaceFirstOutsideTags('<p>We sell C++ books.</p>', 'C++', A), null);
        assert.equal(slk.replaceFirstOutsideTags('<p>Tagged #wordpress here.</p>', '#wordpress', A), null);
    });

    test('handles content with no tags at all', () => {
        assert.equal(slk.replaceFirstOutsideTags('Just a spaza shop.', 'spaza shop', A),
            'Just a [[LINK]].');
    });

    test('closing anchor re-enables matching', () => {
        const html = '<a href="/a">spaza shop</a><a href="/b">x</a><p>a spaza shop</p>';
        const out = slk.replaceFirstOutsideTags(html, 'spaza shop', A);
        assert.ok(out.endsWith('<p>a [[LINK]]</p>'));
    });
});

describe('buildAnchor', () => {
    test('produces a plain anchor with the tracking attribute', () => {
        assert.equal(
            slk.buildAnchor('spaza shop', 'http://x.test/a'),
            '<a href="http://x.test/a" data-slk="1">spaza shop</a>'
        );
    });

    /**
     * Anchor text is content and can contain anything. Unescaped, a phrase
     * with a quote in it would break out of the attribute — or out of the
     * anchor entirely.
     */
    test('escapes the phrase and the url', () => {
        const out = slk.buildAnchor('a "quoted" <b>bold</b>', 'http://x.test/?a=1&b=2');
        assert.ok(!out.includes('<b>bold</b>'), 'raw HTML must not survive');
        assert.ok(out.includes('&amp;'), 'the ampersand in the url must be escaped');
        assert.ok(out.includes('&quot;') || out.includes('&#39;'), 'quotes must be escaped');
    });

    test('honours the new-tab and nofollow settings', () => {
        const both = loadAdminUi({ linkDefaults: { new_tab: 1, nofollow: 1 } })
            .buildAnchor('x', 'http://x.test/');
        assert.ok(both.includes('target="_blank"'));
        assert.ok(both.includes('nofollow'));
        assert.ok(both.includes('noopener'), 'new tab without noopener is a security hole');

        const neither = loadAdminUi({ linkDefaults: { new_tab: 0, nofollow: 0 } })
            .buildAnchor('x', 'http://x.test/');
        assert.ok(!neither.includes('target='));
        assert.ok(!neither.includes('rel='));
    });
});

describe('the two together — what insert() actually does', () => {
    test('an inserted link is well-formed and lands in the right place', () => {
        const content = '<p>Read about a spaza shop, or a spaza shop elsewhere.</p>';
        const out = slk.replaceFirstOutsideTags(
            content, 'spaza shop', slk.buildAnchor('spaza shop', 'http://x.test/guide')
        );
        assert.equal(
            out,
            '<p>Read about a <a href="http://x.test/guide" data-slk="1">spaza shop</a>, ' +
            'or a spaza shop elsewhere.</p>'
        );
        assert.equal((out.match(/<a /g) || []).length, 1, 'exactly one anchor added');
        assert.equal((out.match(/<\/a>/g) || []).length, 1);
    });

    test('running twice does not nest anchors', () => {
        let out = slk.replaceFirstOutsideTags(
            '<p>A spaza shop.</p>', 'spaza shop', slk.buildAnchor('spaza shop', 'http://x.test/1')
        );
        const again = slk.replaceFirstOutsideTags(
            out, 'spaza shop', slk.buildAnchor('spaza shop', 'http://x.test/2')
        );
        assert.equal(again, null, 'the only occurrence is already linked, so nothing should change');
    });
});

describe('strengthOf', () => {
    test('prefers an explicit match percentage', () => {
        assert.equal(slk.strengthOf({ match: 75 }), 0.75);
        assert.equal(slk.strengthOf({ match: 100 }), 1);
    });

    test('clamps to 0–1 rather than overflowing the meter', () => {
        assert.equal(slk.strengthOf({ match: 250 }), 1);
        assert.equal(slk.strengthOf({ score: 99 }), 1);
        assert.equal(slk.strengthOf({ score: -5 }), 0);
    });

    test('falls back to score, then to a neutral half', () => {
        assert.equal(slk.strengthOf({ score: 5 }), 0.5);
        assert.equal(slk.strengthOf({}), 0.5);
        assert.equal(slk.strengthOf({ match: null }), 0.5);
    });
});

describe('the wp.i18n fallback', () => {
    /**
     * The harness deliberately provides no wp.i18n. The file must still load
     * and work — an untranslated admin is a far better failure than a dead
     * one, and this asserts the fallback is real rather than assumed.
     */
    test('the file loads and works without wp.i18n present', () => {
        assert.equal(typeof slk.buildAnchor, 'function');
        assert.ok(slk.buildAnchor('x', 'http://x.test/').includes('data-slk="1"'));
    });
});
