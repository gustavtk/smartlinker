/* global wp, jQuery, SLK, SlkBridge */
/**
 * SmartLinker — block-editor sidebar.
 *
 * Its own toolbar tab (not a panel inside the Post sidebar), so suggestions
 * are reachable without hunting through the collapsed meta-box drawer.
 *
 * Written against wp.element.createElement directly: the plugin has no npm /
 * JSX build step, and adding one for a single panel is not worth the weight.
 */
(function () {
    'use strict';

    if (!window.wp || !wp.plugins || !wp.element || !wp.data) { return; }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useSelect = wp.data.useSelect;
    var registerPlugin = wp.plugins.registerPlugin;

    // PluginSidebar moved from @wordpress/edit-post to @wordpress/editor in
    // WP 6.6. Prefer the modern home, fall back so older installs still work.
    var pkg = (wp.editor && wp.editor.PluginSidebar) ? wp.editor : wp.editPost;
    if (!pkg || !pkg.PluginSidebar) { return; }

    /**
     * How long ago a scan ran, in words. Deliberately vague past a minute:
     * the point is "is this current?", not the exact second.
     */
    function ageLabel(when) {
        var secs = Math.round((Date.now() - when) / 1000);
        if (secs < 45) { return 'just now'; }
        var mins = Math.round(secs / 60);
        if (mins < 60) { return mins + ' min ago'; }
        return Math.round(mins / 60) + 'h ago';
    }

    var PluginSidebar = pkg.PluginSidebar;
    var PluginSidebarMoreMenuItem = pkg.PluginSidebarMoreMenuItem;

    var PREVIEW_COUNT = 3;   // matches the design: show 3, then "View all N"

    /* --------------------------------------------------------------------- */

    /** Small inline icons so the short labels still say what they do. */
    function icon(name) {
        var d;
        if (name === 'ai') {
            // sparkles
            d = 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z';
        } else if (name === 'inbound') {
            // arrow pointing IN, the mirror of the outbound chain
            d = 'M11 16l-4-4m0 0l4-4m-4 4h14M3 4v16';
        } else {
            // chain link
            d = 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1';
        }
        return el('svg', {
            className: 'slk-sb-ico', viewBox: '0 0 24 24', fill: 'none',
            stroke: 'currentColor', 'aria-hidden': 'true', focusable: 'false'
        }, el('path', {
            strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: '2', d: d
        }));
    }

    function confidenceOf(s) {
        if (s.match !== undefined && s.match !== null) { return parseInt(s.match, 10) || 0; }
        var strength = (window.SlkBridge && SlkBridge.strengthOf) ? SlkBridge.strengthOf(s) : 0;
        return Math.round(Math.min(1, strength) * 100);
    }

    /**
     * One suggestion. Emits the SAME markup as the meta box (.slk-sg*) so
     * both surfaces inherit one stylesheet — the sidebar only adds narrow
     * -column overrides in CSS. A parallel design here would drift.
     */
    function Card(props) {
        var s = props.item;
        var state = props.state;   // 'idle' | 'added'
        var pct = confidenceOf(s);
        var tone = pct >= 75 ? 'slk-conf-high' : (pct >= 50 ? 'slk-conf-mid' : 'slk-conf-low');
        /*
         * Inbound rows are read from the other end: the headline is the post
         * that will GAIN the link, not the one it points at — that is always
         * the post being edited, so repeating it in every card says nothing.
         * Same rule the meta box uses.
         */
        var title = s.source_id
            ? (s.source_title || s.target_title || '')
            : (s.target_title || s.title || '');

        return el('div', { className: 'slk-sg' + (state === 'added' ? ' slk-inserted' : '') },
            el('div', { className: 'slk-sg-head' },
                el('span', { className: 'slk-sg-title', title: title },
                    el('svg', {
                        className: 'slk-sg-arrow',
                        viewBox: '0 0 37 15',
                        'aria-hidden': 'true',
                        focusable: 'false'
                    }, el('path', {
                        fill: 'currentColor',
                        d: 'M28.48 14.01L27.71 13.55L28.05 12.55L30.79 8.55L30.48 8.19L0.48 7.98L0 6.55L0.48 6.04L1.48 6.01L30.48 5.89L30.82 5.55L27.76 0.55L28.48 0L36.86 6.55L36.48 7.62L32.48 10.26L28.48 14.01Z'
                    }))
                    ,
                    el('span', { className: 'slk-sg-name' }, title)
                )
            ),

            // Where the destination actually goes — this replaces the
            // confidence badge, which now sits next to the Apply button.
            el('a', {
                className: 'slk-sb-dest',
                href: s.url,
                target: '_blank',
                rel: 'noopener',
                title: s.url
            }, s.path || s.url || ''),
            el('div', { className: 'slk-sg-anchor' },
                el('strong', null, 'Anchor:'), ' “',
                el('a', { className: 'slk-anchor-text', href: s.url, target: '_blank', rel: 'noopener' }, s.phrase || ''),
                '”'
            ),
            props.error ? el('div', { className: 'slk-sg-err' }, props.error) : null,
            el('div', { className: 'slk-sg-actions' },
                el('span', { className: 'slk-conf ' + tone }, 'Confidence: ' + pct + '%'),
                el('button', {
                    type: 'button',
                    className: 'slk-btn-apply',
                    disabled: state === 'added',
                    onClick: props.onAdd
                }, state === 'added'
                    ? (s.source_id ? '✓ Added there' : '✓ Added')
                    // Applying an inbound suggestion saves ANOTHER post right
                    // away. The label has to admit that; "Apply Link" would
                    // read as "queue it up in what I am editing".
                    : (s.source_id ? 'Add to that post' : 'Apply Link'))
            )
        );
    }

    /* --------------------------------------------------------------------- */

    function Panel() {
        var postId = useSelect(function (select) {
            return select('core/editor').getCurrentPostId();
        }, []);

        var st = useState([]);        var items = st[0],   setItems = st[1];
        var lo = useState(false);     var loading = lo[0], setLoading = lo[1];
        var er = useState('');        var error = er[0],   setError = er[1];
        var md = useState('');        var mode = md[0],    setMode = md[1];
        var ad = useState({});        var added = ad[0],   setAdded = ad[1];
        var ce = useState({});        var cardErr = ce[0], setCardErr = ce[1];
        var sa = useState(PREVIEW_COUNT); var shown = sa[0], setShown = sa[1];
        // When these results were produced. Settings changed elsewhere do not
        // invalidate what is already on screen, and a stale list that looks
        // current is worse than no list — so the panel says when it last ran.
        var ts = useState(null);      var scannedAt = ts[0], setScannedAt = ts[1];
        // Inbound can be answered by either engine, like the Orphans and
        // Broken Links reports. Outbound already has its own AI button.
        var eg = useState('standard'); var engine = eg[0],  setEngine = eg[1];

        var ai = (SLK && SLK.ai) || { enabled: false, reason: [] };
        var aiEnabled = !!(ai.enabled === true || ai.enabled === 1 || ai.enabled === '1');

        function scan(which, engineArg) {
            var eng = engineArg || (which === 'inbound' ? engine : 'standard');
            setLoading(true); setError(''); setItems([]);
            setAdded({}); setCardErr({}); setShown(PREVIEW_COUNT); setMode(which);
            if (which === 'inbound') { setEngine(eng); }

            var payload = { nonce: SLK.nonce };
            if (which === 'inbound') {
                // The other direction: which existing posts should point HERE.
                payload.action = 'slk_get_inbound';
                payload.target_id = postId;
                payload.engine = eng;
            } else {
                payload.action = which === 'ai' ? 'slk_get_ai_suggestions' : 'slk_get_suggestions';
                payload.post_id = postId;
            }

            jQuery.post(SLK.ajaxUrl, payload).done(function (res) {
                setLoading(false);
                if (res && res.success) {
                    setItems((res.data && res.data.suggestions) || []);
                    setScannedAt(Date.now());
                } else {
                    setError((res && res.data && res.data.message) || SLK.i18n.error);
                }
            }).fail(function () {
                setLoading(false);
                setError(SLK.i18n.error);
            });
        }

        function add(idx, s) {
            /*
             * An INBOUND suggestion edits another post — the one that will link
             * here — so it cannot go through the editor bridge, which only
             * writes into the post currently open. It is a server-side write,
             * saved the moment it is applied, and it lands in the Activity log
             * where it can be undone. The button says "Add to that post" so
             * nobody expects it to be part of this post's next save.
             */
            if (mode === 'inbound') {
                var busy = {}; for (var b in cardErr) { busy[b] = cardErr[b]; }
                delete busy[idx]; setCardErr(busy);

                jQuery.post(SLK.ajaxUrl, {
                    action: 'slk_insert_link',
                    nonce: SLK.nonce,
                    post_id: s.source_id,
                    phrase: s.phrase,
                    url: s.url
                }).done(function (res) {
                    if (res && res.success) {
                        var ok = {}; for (var o in added) { ok[o] = added[o]; }
                        ok[idx] = true; setAdded(ok);
                    } else {
                        var bad = {}; for (var e in cardErr) { bad[e] = cardErr[e]; }
                        bad[idx] = (res && res.data && res.data.message) || SLK.i18n.error;
                        setCardErr(bad);
                    }
                }).fail(function () {
                    var f = {}; for (var g in cardErr) { f[g] = cardErr[g]; }
                    f[idx] = SLK.i18n.error; setCardErr(f);
                });
                return;
            }

            // Reuse the meta box's insertion so both surfaces behave identically.
            var err = window.SlkBridge ? SlkBridge.insert(s.phrase, s.url) : SLK.i18n.error;
            if (err) {
                var ne = {}; for (var k in cardErr) { ne[k] = cardErr[k]; }
                ne[idx] = err; setCardErr(ne);
                return;
            }
            var na = {}; for (var j in added) { na[j] = added[j]; }
            na[idx] = true; setAdded(na);

            var ce2 = {}; for (var m in cardErr) { if (String(m) !== String(idx)) { ce2[m] = cardErr[m]; } }
            setCardErr(ce2);
        }

        var addedCount = Object.keys(added).length;
        var visible = items.slice(0, shown);

        return el('div', { className: 'slk-sb' },

            /* Scan actions — one row. Labels are short enough that the icon
               carries the meaning; the title attribute spells it out. */
            el('div', { className: 'slk-sb-actions' },
                el('button', {
                    type: 'button',
                    className: 'slk-sb-btn' + (loading && mode !== 'ai' ? ' is-busy' : ''),
                    disabled: loading,
                    title: 'Find link suggestions',
                    onClick: function () { scan('keyword'); }
                }, icon('link'), el('span', null, 'Link')),

                el('button', {
                    type: 'button',
                    className: 'slk-sb-btn' + (aiEnabled ? '' : ' is-locked') +
                        (loading && mode === 'ai' ? ' is-busy' : ''),
                    disabled: loading || !aiEnabled,
                    title: aiEnabled ? 'AI suggestions' : 'AI suggestions need an OpenAI API key',
                    onClick: function () { if (aiEnabled) { scan('ai'); } }
                }, icon('ai'), el('span', null, 'AI')),

                el('button', {
                    type: 'button',
                    className: 'slk-sb-btn' + (loading && mode === 'inbound' ? ' is-busy' : ''),
                    disabled: loading,
                    title: 'Which of your posts should link TO this one',
                    onClick: function () { scan('inbound'); }
                }, icon('inbound'), el('span', null, 'Links in'))
            ),

            /* Locked explanation — same copy as the meta box, from SLK.ai */
            !aiEnabled && ai.reason && ai.reason.length
                ? el('div', { className: 'slk-sb-locked' },
                    ai.reason.map(function (line, i) {
                        return el('span', { key: 'r' + i, className: 'slk-sb-locked-line' }, line);
                    }),
                    SLK.settingsUrl
                        ? el('a', { className: 'slk-sb-locked-link', href: SLK.settingsUrl }, 'Open AI settings')
                        : null
                )
                : null,

            error ? el('div', { className: 'slk-sb-error' }, error) : null,

            loading
                ? el('div', { className: 'slk-sb-skel' },
                    el('div', { className: 'slk-sb-skel-row' }),
                    el('div', { className: 'slk-sb-skel-row' }),
                    el('div', { className: 'slk-sb-skel-row' }))
                : null,

            /* AI banner, mirroring the meta box's wording */
            !loading && mode === 'ai' && items.length
                ? el('div', { className: 'slk-sb-banner' },
                    el('span', { className: 'dashicons dashicons-superhero' }),
                    el('span', null, 'AI found ' + items.length + ' relevant suggestion' +
                        (items.length === 1 ? '' : 's') + ' for this post'))
                : null,

            !loading && !error && mode && !items.length
                ? el('p', { className: 'slk-sb-empty' }, SLK.i18n.none)
                : null,

            mode === 'inbound' && !error
                ? el('div', { className: 'slk-sb-engine' },
                    el('button', {
                        type: 'button',
                        className: 'slk-engine-btn' + (engine !== 'ai' ? ' is-on' : ''),
                        disabled: loading,
                        onClick: function () { if (engine !== 'standard') { scan('inbound', 'standard'); } }
                    }, 'Standard'),
                    el('button', {
                        type: 'button',
                        className: 'slk-engine-btn' + (engine === 'ai' ? ' is-on' : '') +
                            (aiEnabled ? '' : ' is-locked'),
                        disabled: loading || !aiEnabled,
                        title: aiEnabled ? 'Ask AI which posts should link here'
                                         : 'AI needs an OpenAI API key',
                        onClick: function () { if (aiEnabled && engine !== 'ai') { scan('inbound', 'ai'); } }
                    }, 'AI'))
                : null,

            !loading && mode === 'inbound' && items.length
                ? el('div', { className: 'slk-sb-note' },
                    'These posts should link here. Adding one edits and saves that post straight away — it is not part of this post\u2019s next save. Undo it under SmartLinker \u2192 Activity.')
                : null,

            !loading && items.length
                ? el('div', { className: 'slk-sg-bar' },
                    el('span', null, 'Suggested links (' + visible.length + ' of ' + items.length + ')'),
                    scannedAt
                        ? el('span', {
                            className: 'slk-sg-bar-age',
                            title: 'These are the results of the last scan. Change a setting and they will not update until you scan again.'
                        }, ageLabel(scannedAt))
                        : null)
                : null,

            visible.map(function (s, i) {
                return el(Card, {
                    key: 'c' + i,
                    item: s,
                    state: added[i] ? 'added' : 'idle',
                    error: cardErr[i],
                    onAdd: function () { add(i, s); }
                });
            }),

            items.length > visible.length
                ? el('button', {
                    type: 'button',
                    className: 'slk-load-more',
                    onClick: function () { setShown(shown + PREVIEW_COUNT); }
                }, 'Load More')
                : null,

            addedCount
                ? el('p', { className: 'slk-sb-note' },
                    mode === 'inbound'
                        // Already written and saved elsewhere — telling someone
                        // to save THIS post would be plainly wrong.
                        ? addedCount + ' link' + (addedCount === 1 ? '' : 's') +
                          ' added to ' + (addedCount === 1 ? 'that post' : 'those posts') +
                          ' and saved. Undo under SmartLinker \u2192 Activity.'
                        : addedCount + ' link' + (addedCount === 1 ? '' : 's') +
                          ' added. Remember to update the post to save.')
                : null
        );
    }

    /* --------------------------------------------------------------------- */

    registerPlugin('smartlinker-sidebar', {
        icon: 'admin-links',
        render: function () {
            return el(Fragment, null,
                PluginSidebarMoreMenuItem
                    ? el(PluginSidebarMoreMenuItem, { target: 'smartlinker-sidebar', icon: 'admin-links' },
                        'SmartLinker')
                    : null,
                el(PluginSidebar, {
                    name: 'smartlinker-sidebar',
                    icon: 'admin-links',
                    title: 'SmartLinker'
                }, el(Panel, null))
            );
        }
    });
}());
