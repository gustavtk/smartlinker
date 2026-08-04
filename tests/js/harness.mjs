/**
 * Loads the real js/admin-ui.js and hands back its SlkBridge.
 *
 * The point is to test the FILE THAT SHIPS, not a copy of its functions.
 * Copying them into the test would pass forever while the real file drifted,
 * which is worse than no test at all.
 *
 * admin-ui.js is an IIFE that binds several dozen jQuery handlers as it
 * loads, so the sandbox below supplies just enough jQuery to let it finish.
 * Only one stub has real behaviour: `$('<div>').text(s).html()`, which is how
 * the file escapes HTML, so it must escape properly or every assertion about
 * generated markup would be meaningless.
 */
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** Minimal chainable no-op standing in for a jQuery result set. */
function chainable() {
    const api = new Proxy(function () {}, {
        get(_t, prop) {
            if (prop === 'length') return 0;
            if (prop === 'val') return () => '';
            if (prop === 'text') return () => api;
            if (prop === 'html') return () => '';
            if (prop === 'data') return () => undefined;
            if (prop === 'is') return () => false;
            if (prop === 'attr' || prop === 'prop') return () => undefined;
            if (prop === 'each' || prop === 'map') return () => api;
            if (prop === Symbol.toPrimitive) return () => '';
            return () => api;
        },
        apply: () => api,
    });
    return api;
}

/**
 * The one stub that matters. jQuery's .text() then .html() is an HTML-escape
 * idiom; reproducing it faithfully is what makes assertions about the
 * generated anchor markup mean anything.
 */
function escapingDiv() {
    let raw = '';
    const node = {
        text(s) { raw = s == null ? '' : String(s); return node; },
        html() {
            return raw
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },
    };
    return node;
}

export function loadAdminUi(options = {}) {
    const jQuery = function (arg) {
        if (arg === '<div>') return escapingDiv();
        return chainable();
    };
    jQuery.post = () => ({ done: () => ({ fail: () => {} }), fail: () => {} });
    jQuery.get = jQuery.post;
    jQuery.ajax = jQuery.post;
    jQuery.each = () => {};
    jQuery.extend = Object.assign;

    const sandbox = {
        console,
        setTimeout,
        clearTimeout,
        Date,
        Math,
        JSON,
        RegExp,
        Array,
        Object,
        String,
        Number,
        URLSearchParams,
        jQuery,
        $: jQuery,
        ajaxurl: '/wp-admin/admin-ajax.php',
        SLK: {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            nonce: 'test',
            // Defaults are overridable so the anchor-format tests can cover
            // the nofollow / new-tab combinations the settings expose.
            linkDefaults: options.linkDefaults || { new_tab: 0, nofollow: 0 },
            ai: { enabled: false },
            i18n: { loading: 'Loading', inserted: 'Inserted', error: 'Error', none: 'None' },
        },
        // No wp.i18n on purpose: this exercises the fallback path in the
        // shipped file, so a missing wp-i18n cannot silently break the admin.
        wp: undefined,
    };
    sandbox.window = sandbox;
    sandbox.document = {
        addEventListener() {},
        querySelector: () => null,
        querySelectorAll: () => [],
        createElement: () => ({ style: {}, setAttribute() {}, appendChild() {}, addEventListener() {} }),
    };
    sandbox.navigator = { sendBeacon: () => true };
    sandbox.location = { href: 'http://example.test/wp-admin/', search: '' };
    sandbox.history = { pushState() {}, replaceState() {} };
    sandbox.performance = { now: () => 0 };

    createContext(sandbox);
    const src = readFileSync(join(ROOT, 'js', 'admin-ui.js'), 'utf8');
    runInContext(src, sandbox, { filename: 'js/admin-ui.js' });

    if (!sandbox.window.SlkBridge) {
        throw new Error('admin-ui.js loaded but exposed no SlkBridge');
    }
    return sandbox.window.SlkBridge;
}
