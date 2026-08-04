/* global jQuery, SLK, ajaxurl, wp */
(function ($) {
    'use strict';

    /*
     * Translation helpers.
     *
     * wp-i18n is a declared dependency; the fallbacks keep the admin working
     * rather than throwing if it is ever absent, because an untranslated
     * screen beats a dead one. Anything with a value in it goes through
     * sprintf: a sentence built by concatenating fragments cannot be
     * translated, since other languages do not order the number, the noun and
     * the verb the way English does.
     */
    var i18n = (window.wp && wp.i18n) ? wp.i18n : {};
    var __ = i18n.__ || function (s) { return s; };
    var _n = i18n._n || function (single, plural, n) { return n === 1 ? single : plural; };
    var sprintf = i18n.sprintf || function (fmt) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(fmt).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
            return pos ? args[parseInt(pos, 10) - 1] : args[i++];
        }).replace(/%%/g, '%');
    };
    /*
     * The text domain is written out in full at every call site, never held
     * in a constant. wp i18n make-pot resolves the domain STATICALLY: given a
     * variable it cannot tell which domain the call belongs to, so it skips
     * the string silently. The result is code that reads correctly, runs
     * correctly, and produces a POT with none of these strings in it — which
     * is how a translated plugin ends up still showing English.
     */

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // Shimmering placeholder rows while a scan runs — feels faster than a spinner.
    function skeleton(n) {
        var row = '<div class="slk-skel-row">' +
            '<div class="slk-skel slk-skel-check"></div>' +
            '<div class="slk-skel-col"><div class="slk-skel slk-skel-line"></div>' +
                '<div class="slk-skel slk-skel-line short"></div></div>' +
            '<div class="slk-skel-col"><div class="slk-skel slk-skel-line mid"></div>' +
                '<div class="slk-skel slk-skel-line tiny"></div></div>' +
            '<div class="slk-skel slk-skel-btn"></div>' +
        '</div>';
        return '<div class="slk-skeleton">' + new Array(n || 3).fill(row).join('') + '</div>';
    }

    // Relevance as a 0–1 strength, for the little segmented meter.
    function strengthOf(s) {
        if (s.match !== undefined && s.match !== null && s.match > 0) {
            return Math.max(0, Math.min(1, s.match / 100));
        }
        if (s.score !== undefined && s.score !== null) {
            return Math.max(0, Math.min(1, Number(s.score) / 10));
        }
        return 0.5;
    }

    // Header progress: how many of this batch you've already added.
    // (translation helpers are defined at the top of this file)
    function refreshProgress($wrap) {
        var total = $wrap.find('.slk-sugg-card').length;
        var done = $wrap.find('.slk-sugg-card.slk-inserted').length;
        if (!total) { return; }
        var pct = Math.round((done / total) * 100);
        $wrap.find('.slk-progress-fill').css('width', pct + '%');
        $wrap.find('.slk-progress-text').text(sprintf(
            /* translators: 1: suggestions added, 2: suggestions in this batch */
            __('%1$d of %2$d added', 'smartlinker'), done, total));
        $wrap.find('.slk-sugg-bar').toggleClass('slk-all-done', done === total);
        if (done === total) {
            $wrap.find('.slk-done-note').remove();
            $wrap.append('<div class="slk-done-note">🎉 ' +
                esc(__('Every suggestion in this batch is linked. Remember to update the post.', 'smartlinker')) + '</div>');
        }
    }

    // Card layout: target title + match score, anchor pill, path, Add Link.
    // opts: { ai: bool, sourceId: int|null }  — sourceId is used when the
    // cards are rendered outside the editor (the standalone AI page), so the
    // insert knows which post to write into.
    /**
     * One card per suggestion: destination first, then how confident we are,
     * then the anchor and why it was chosen. The old layout led with the
     * sentence being edited, which buried the thing you actually decide on —
     * whether this link belongs.
     */
    var SLK_PAGE = 3; // cards revealed per "Load More"

    // Heroicons arrow-right. Inline rather than a font glyph so it inherits
    // currentColor and scales with the card, and so it renders identically
    // wherever the panel appears.
    function slkArrow(cls) {
        return '<svg class="' + cls + '" viewBox="0 0 37 15" aria-hidden="true" focusable="false">' +
            '<path fill="currentColor" d="M28.48 14.01L27.71 13.55L28.05 12.55L30.79 8.55L30.48 8.19' +
            'L0.48 7.98L0 6.55L0.48 6.04L1.48 6.01L30.48 5.89L30.82 5.55L27.76 0.55L28.48 0L36.86 6.55' +
            'L36.48 7.62L32.48 10.26L28.48 14.01Z"></path></svg>';
    }

    var SLK_ARROW = '<svg class="slk-sg-arrow" viewBox="0 0 37 15" ' +
        'aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="' + 'M28.48 14.01L27.71 13.55L28.05 12.55L30.79 8.55L30.48 8.19L0.48 7.98L0 6.55L0.48 6.04L1.48 6.01L30.48 5.89L30.82 5.55L27.76 0.55L28.48 0L36.86 6.55L36.48 7.62L32.48 10.26L28.48 14.01Z' + '"></path></svg>';

    function confidenceOf(s) {
        if (s.match !== undefined && s.match !== null) { return parseInt(s.match, 10) || 0; }
        return Math.round(Math.min(1, strengthOf(s)) * 100);
    }

    function suggestionCard(s, idx) {
        var pct = confidenceOf(s);
        var tone = pct >= 75 ? 'slk-conf-high' : (pct >= 50 ? 'slk-conf-mid' : 'slk-conf-low');
        var src = s.source_id || null;
        var btnClass = src ? 'slk-insert-inbound' : 'slk-insert';
        var srcAttr = src ? ' data-source="' + esc(src) + '"' : '';
        var path = s.path || s.url || '';
        // Inbound rows are read from the other end: the headline is the post
        // that will gain the link, not the one it points at.
        var headline = src ? (s.source_title || s.target_title || '') : (s.target_title || '');

        return '<div class="slk-sg slk-suggestion" data-index="' + idx + '"' +
                ' data-target="' + esc(s.target_id || 0) + '"' +
                ' data-phrase="' + esc(s.phrase) + '" data-url="' + esc(s.url) + '"' + srcAttr + '>' +
            '<div class="slk-sg-head">' +
                '<span class="slk-sg-title" title="' + esc(headline) + '">' +
                    SLK_ARROW +
                    '<span class="slk-sg-name">' + esc(headline) + '</span></span>' +
                '<span class="slk-conf ' + tone + '">' +
                    /* translators: %d: match confidence as a percentage */
                    esc(sprintf(__('Confidence: %d%%', 'smartlinker'), pct)) + '</span>' +
            '</div>' +
            '<div class="slk-sg-anchor"><strong>' + esc(__('Anchor:', 'smartlinker')) + '</strong> ' +
                '“<a class="slk-anchor-text" href="' + esc(s.url) + '" target="_blank" rel="noopener">' +
                esc(s.phrase) + '</a>”' +
                // where the link actually lands, so the pair reads
                // "this text" → "this page"
                (path
                    ? slkArrow('slk-sg-arrow-sm') +
                      '<a class="slk-sg-dest" href="' + esc(s.url) + '" target="_blank" ' +
                      'rel="noopener" title="' + esc(s.url) + '">' + esc(path) + '</a>'
                    : '') +
            '</div>' +
            (s.reason ? '<div class="slk-sg-reason">' + esc(s.reason) + '</div>' : '') +
            '<div class="slk-sg-actions">' +
                '<button type="button" class="slk-btn-apply ' + btnClass + '">' + esc(__('Apply Link', 'smartlinker')) + '</button>' +
                '<button type="button" class="slk-btn-reject">' + esc(__('Reject', 'smartlinker')) + '</button>' +
                '<span class="slk-sugg-msg" role="status"></span>' +
            '</div>' +
        '</div>';
    }

    function paintCards($wrap) {
        var items = $wrap.data('slkItems') || [];
        var shown = $wrap.data('slkShown') || SLK_PAGE;
        var visible = items.slice(0, shown);

        var html = '<div class="slk-sg-bar">' +
            /* translators: 1: suggestions shown, 2: suggestions found */
            esc(sprintf(__('Suggested links (%1$d of %2$d)', 'smartlinker'), visible.length, items.length)) +
            '</div>';
        html += visible.map(suggestionCard).join('');
        if (items.length > shown) {
            html += '<button type="button" class="slk-load-more">' + esc(__('Load More', 'smartlinker')) + '</button>';
        }
        $wrap.html(html);
    }

    function renderSuggestions($wrap, items, opts) {
        opts = opts || {};

        if (!items || !items.length) {
            $wrap.html('<p class="description">' + esc(SLK.i18n.none) + '</p>');
            $wrap.removeData('slkItems');
            return;
        }
        if (opts.sourceId) {
            items = items.map(function (s) {
                if (!s.source_id) { s.source_id = opts.sourceId; }
                return s;
            });
        }
        $wrap.data('slkItems', items).data('slkShown', SLK_PAGE);
        paintCards($wrap);
    }

    $(document).on('click', '.slk-load-more', function () {
        var $wrap = $(this).closest('.slk-suggestions, .slk-inbound-results');
        $wrap.data('slkShown', ($wrap.data('slkShown') || SLK_PAGE) + SLK_PAGE);
        paintCards($wrap);
    });

    // Rejecting is remembered server-side, so the same wrong link is not
    // offered again on the next scan.
    $(document).on('click', '.slk-btn-reject', function () {
        var $card = $(this).closest('.slk-suggestion');
        var $wrap = $card.closest('.slk-suggestions, .slk-inbound-results');
        var $box = $card.closest('.slk-metabox');
        var postId = $box.data('post-id') || $card.data('source');
        var targetId = $card.data('target');

        $card.addClass('slk-sg-rejected');
        if (postId && targetId) {
            $.post(SLK.ajaxUrl, {
                action: 'slk_reject_suggestion',
                nonce: SLK.nonce,
                post_id: postId,
                target_id: targetId,
                // The anchor matters as much as the destination: often the
                // destination is fine and the WORDS were wrong.
                phrase: cardValues($card).phrase || $card.data('phrase') || ''
            }).done(function (res) {
                // Say so when a rejection has just retired something
                // site-wide, so the effect is never invisible.
                if (res && res.success && res.data && res.data.message) {
                    $card.closest('.slk-metabox, .slk-wrap')
                         .find('.slk-sugg-status, .slk-inbound-status')
                         .first().text(res.data.message);
                }
            });
        }
        var items = ($wrap.data('slkItems') || []).filter(function (s) {
            return String(s.target_id) !== String(targetId);
        });
        setTimeout(function () {
            $wrap.data('slkItems', items);
            if (!items.length) {
                $wrap.html('<p class="description">' + esc(SLK.i18n.none) + '</p>');
            } else {
                paintCards($wrap);
            }
        }, 180);
    });


    /* ---------------------------------------------------------------------
     * Target keywords, edited in place next to the suggestions.
     * ------------------------------------------------------------------- */

    function paintKeywords($panel, keywords) {
        var html = (keywords || []).map(function (k) {
            return '<span class="slk-kw-chip' + (k.source === 'seo' ? ' is-seo' : '') + '" data-id="' + esc(k.id) + '">' +
                esc(k.keyword) +
                (k.source === 'seo'
                    ? '<em class="slk-kw-src">' + esc(__('SEO', 'smartlinker')) + '</em>'
                    : '<button type="button" class="slk-kw-del" aria-label="' + esc(__('Remove keyword', 'smartlinker')) + '">&times;</button>') +
                '</span>';
        }).join('');
        $panel.find('.slk-kw-chips').html(html);
    }

    function keywordRequest($panel, data, $msg) {
        data.action = 'slk_post_keywords';
        data.nonce = SLK.nonce;
        data.post_id = $panel.data('post-id');
        return $.post(SLK.ajaxUrl, data).done(function (res) {
            if (res && res.success) {
                paintKeywords($panel, res.data.keywords);
                if ($msg) { $msg.text(''); }
            } else if ($msg) {
                $msg.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        });
    }

    $(document).on('click', '.slk-kw-del', function () {
        var $panel = $(this).closest('.slk-kw-panel');
        keywordRequest($panel, { op: 'remove', keyword_id: $(this).closest('.slk-kw-chip').data('id') });
    });

    $(document).on('click', '.slk-kw-save', function () {
        var $panel = $(this).closest('.slk-kw-panel');
        var $input = $panel.find('.slk-kw-input');
        var val = $.trim($input.val());
        if (!val) { return; }
        keywordRequest($panel, { op: 'add', keyword: val }, $panel.find('.slk-kw-msg'))
            .done(function () { $input.val(''); });
    });

    $(document).on('keydown', '.slk-kw-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('.slk-kw-panel').find('.slk-kw-save').trigger('click');
        }
    });

    $(document).on('click', '.slk-kw-extract', function () {
        var $panel = $(this).closest('.slk-kw-panel');
        var $btn = $(this);
        var $msg = $panel.find('.slk-kw-msg');
        $btn.prop('disabled', true);
        $msg.text(__('Asking the AI…', 'smartlinker'));

        $.post(SLK.ajaxUrl, {
            action: 'slk_extract_keywords',
            nonce: SLK.nonce,
            post_id: $panel.data('post-id')
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                paintKeywords($panel, res.data.keywords);
                /* translators: %d: number of keywords added */
                var addedMsg = __('Added %d.', 'smartlinker');
                $msg.text(res.data.added
                    ? sprintf(addedMsg, res.data.added)
                    : __('Nothing new to add.', 'smartlinker'));
            } else {
                $msg.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $msg.text(SLK.i18n.error);
        });
    });

    // Lift the scan buttons up into the meta box's own title bar, so the
    // panel opens straight onto the suggestions with no wasted space.
    $(function () {
        var $box = $('.slk-metabox');
        if (!$box.length) { return; }
        var $header = $box.closest('.postbox').find('.postbox-header').first();
        var $bar = $box.find('.slk-scan-bar');
        if (!$header.length || !$bar.length) { return; }

        $bar.addClass('slk-scan-bar-inline');
        $header.find('.hndle, h2').first().after($bar);
        // No stopPropagation here: WP binds collapse to .hndle/.handlediv, not
        // the whole header, and swallowing the event would break the delegated
        // click handlers below.
    });

    // The scan buttons get hoisted into the postbox header, so they are no
    // longer inside .slk-metabox — resolve the panel via the postbox instead.
    function metaboxFor($el) {
        var $box = $el.closest('.slk-metabox');
        if (!$box.length) {
            $box = $el.closest('.postbox').find('.slk-metabox').first();
        }
        return $box;
    }

    $(document).on('click', '.slk-scan', function () {
        var $box = metaboxFor($(this));
        var postId = $box.data('post-id');
        var $status = $box.find('.slk-status');
        var $wrap = $box.find('.slk-suggestions');

        $status.text(SLK.i18n.loading);
        $wrap.html(skeleton(3));

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_suggestions',
            nonce: SLK.nonce,
            post_id: postId
        }).done(function (res) {
            $status.text('');
            if (res && res.success) {
                renderSuggestions($wrap, res.data.suggestions, { ai: false });
            } else {
                $status.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $status.text(SLK.i18n.error);
        });
    });

    /**
     * Meta box: which posts should link TO the one being edited.
     *
     * Renders through the same `renderSuggestions` as the outbound scan, so the
     * rows keep everything the meta box adds over the sidebar — editable anchor
     * and destination, the reason line, and Reject. `suggestionCard` already
     * branches on `source_id`, so an inbound row automatically headlines the
     * SOURCE post and applies via `.slk-insert-inbound` (a server-side write to
     * that other post) rather than into the open editor.
     */
    function runInboundHere($box, engine) {
        var postId = $box.data('post-id');
        var $status = $box.find('.slk-status');
        var $wrap = $box.find('.slk-suggestions');

        $box.data('slkInboundEngine', engine || 'standard');
        $status.text(SLK.i18n.loading);
        $wrap.html(skeleton(3));

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_inbound',
            nonce: SLK.nonce,
            target_id: postId,
            engine: engine || 'standard'
        }).done(function (res) {
            $status.text('');
            if (!res || !res.success) {
                $status.text((res && res.data && res.data.message) || SLK.i18n.error);
                return;
            }
            renderSuggestions($wrap, res.data.suggestions, { ai: engine === 'ai' });

            // Applying these edits OTHER posts and saves them immediately, so
            // say so above the rows and offer the engine switch the reports use.
            var aiReady = !!(SLK.ai && (SLK.ai.enabled === true || SLK.ai.enabled === 1 || SLK.ai.enabled === '1'));
            $wrap.prepend(
                '<div class="slk-inbound-head slk-inbound-engine">' +
                    engineSwitch(engine || 'standard', aiReady) +
                    '<p class="slk-inbound-caveat">' +
                        esc(__('These posts should link here. Adding one edits and saves that post straight away — it is not part of this post\u2019s next save. Undo it under SmartLinker \u2192 Activity.', 'smartlinker')) +
                    '</p>' +
                '</div>'
            );
        }).fail(function () {
            $status.text(SLK.i18n.error);
        });
    }

    $(document).on('click', '.slk-inbound-here', function () {
        // metaboxFor() because this button has been hoisted into the header.
        runInboundHere(metaboxFor($(this)), 'standard');
    });

    $(document).on('click', '.slk-inbound-engine .slk-engine-btn', function () {
        var $b = $(this);
        if ($b.hasClass('is-on') || $b.prop('disabled')) { return; }
        // The switch IS inside the box, so closest() is right here.
        runInboundHere($b.closest('.slk-metabox'), $b.data('engine'));
    });

    $(document).on('click', '.slk-inbound-scan', function () {
        var targetId = $('#slk-target').val();
        var $status = $('.slk-inbound-status');
        var $wrap = $('.slk-inbound-results');
        if (!targetId) {
            $status.text(__('Select a post first.', 'smartlinker'));
            return;
        }
        $status.text(SLK.i18n.loading);
        $wrap.html(skeleton(3));

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_inbound',
            nonce: SLK.nonce,
            target_id: targetId
        }).done(function (res) {
            $status.text('');
            if (res && res.success) {
                renderSuggestions($wrap, res.data.suggestions, { ai: false });
            } else {
                $status.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $status.text(SLK.i18n.error);
        });
    });

    $(document).on('click', '.slk-insert-inbound', function () {
        var $sugg = $(this).closest('.slk-suggestion');
        var $btn = $(this);
        var vals = cardValues($sugg);
        if (!vals.phrase || !vals.url) {
            $sugg.find('.slk-sugg-msg').text(__('Anchor text and URL are both required.', 'smartlinker'));
            return;
        }
        $btn.prop('disabled', true).addClass('slk-btn-loading');

        $.post(SLK.ajaxUrl, {
            action: 'slk_insert_link',
            nonce: SLK.nonce,
            post_id: $sugg.data('source'),
            phrase: vals.phrase,
            url: vals.url
        }).done(function (res) {
            if (res && res.success) {
                $sugg.addClass('slk-inserted');
                $btn.text('✓ ' + __('Inserted', 'smartlinker'));
            } else {
                $btn.prop('disabled', false).text(__('Insert link', 'smartlinker'));
                $('.slk-inbound-status').text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(__('Insert link', 'smartlinker'));
            $('.slk-inbound-status').text(SLK.i18n.error);
        });
    });

    /* -----------------------------------------------------------------
     * Link Opportunities — bulk apply.
     *
     * Applied one at a time rather than in a single server call: each apply
     * saves a post, so a batch of fifty in one request would risk the PHP time
     * limit and leave you unable to tell which half went through. Sequential
     * requests are slower but every row reports its own outcome, and the run
     * can be stopped mid-way without leaving anything half-written.
     * ----------------------------------------------------------------- */
    var slkBulk = { running: false, stop: false };

    function bulkSelected() {
        return $('.slk-opp-table .slk-check-row:checked').closest('.slk-suggestion')
            .filter(function () { return !$(this).hasClass('slk-inserted'); });
    }

    function bulkRefresh() {
        var $bar = $('.slk-bulk-bar');
        if (!$bar.length) { return; }
        var n = bulkSelected().length;
        var $rows = $('.slk-opp-table .slk-check-row').not(':disabled');
        var checked = $rows.filter(':checked').length;

        $bar.prop('hidden', n === 0 && !slkBulk.running);
        /* translators: %d: number of rows ticked */
        $bar.find('.slk-bulk-count').text(sprintf(_n('%d selected', '%d selected', n, 'smartlinker'), n));
        $bar.find('.slk-bulk-apply').prop('disabled', n === 0 || slkBulk.running);

        // Header box reflects the rows, including the indeterminate middle state.
        var $all = $('.slk-check-all');
        $all.prop('checked', $rows.length > 0 && checked === $rows.length);
        $all.prop('indeterminate', checked > 0 && checked < $rows.length);
    }

    $(document).on('change', '.slk-check-all', function () {
        $('.slk-opp-table .slk-check-row').not(':disabled').prop('checked', $(this).prop('checked'));
        bulkRefresh();
    });

    $(document).on('change', '.slk-check-row', bulkRefresh);

    // The admin shell swaps page content without a reload, so the bar has to
    // re-read its state each time a page lands.
    $(bulkRefresh);
    $(document).on('slk:loaded', function () {
        slkBulk.running = false;
        slkBulk.stop = false;
        bulkRefresh();
    });

    $(document).on('click', '.slk-bulk-stop', function () {
        slkBulk.stop = true;
        $(this).prop('disabled', true).text(__('Stopping…', 'smartlinker'));
    });

    $(document).on('click', '.slk-bulk-apply', function () {
        var $rows = bulkSelected();
        if (!$rows.length || slkBulk.running) { return; }

        var $bar = $('.slk-bulk-bar');
        var $status = $bar.find('.slk-bulk-status');
        var total = $rows.length;
        var done = 0, ok = 0, failed = 0;

        slkBulk.running = true;
        slkBulk.stop = false;
        $bar.find('.slk-bulk-apply').prop('disabled', true);
        $bar.find('.slk-bulk-stop').prop('hidden', false).prop('disabled', false).text(__('Stop', 'smartlinker'));

        function finish() {
            slkBulk.running = false;
            $bar.find('.slk-bulk-stop').prop('hidden', true);
            /* translators: %d: number of links successfully added */
            var parts = [sprintf(_n('%d link added', '%d links added', ok, 'smartlinker'), ok)];
            /* translators: %d: number of rows that could not be applied */
            if (failed) { parts.push(sprintf(_n('%d skipped', '%d skipped', failed, 'smartlinker'), failed)); }
            if (slkBulk.stop && done < total) {
                /* translators: 1: rows done, 2: rows selected */
                parts.push(sprintf(__('stopped at %1$d of %2$d', 'smartlinker'), done, total));
            }
            $status.text(parts.join(', ') + '.').removeClass('slk-bulk-busy');
            bulkRefresh();
        }

        function step(i) {
            if (slkBulk.stop || i >= total) { finish(); return; }

            var $row = $rows.eq(i);
            var vals = cardValues($row);
            var $msg = $row.find('.slk-sugg-msg');
            var $btn = $row.find('.slk-btn-apply');

            /* translators: 1: current row, 2: rows selected */
            $status.addClass('slk-bulk-busy').text(sprintf(__('Applying %1$d of %2$d…', 'smartlinker'), i + 1, total));
            $btn.prop('disabled', true);
            $msg.text('');

            $.post(SLK.ajaxUrl, {
                action: 'slk_insert_link',
                nonce: SLK.nonce,
                post_id: $row.data('source'),
                phrase: vals.phrase,
                url: vals.url
            }).done(function (res) {
                done++;
                if (res && res.success) {
                    ok++;
                    $row.addClass('slk-inserted');
                    $btn.text('✓ ' + __('Inserted', 'smartlinker'));
                    $row.find('.slk-check-row').prop('checked', false).prop('disabled', true);
                } else {
                    failed++;
                    $btn.prop('disabled', false);
                    // Left ticked on purpose, so a retry after fixing the cause
                    // does not mean re-selecting everything.
                    $msg.text((res && res.data && res.data.message) || SLK.i18n.error);
                }
            }).fail(function () {
                done++;
                failed++;
                $btn.prop('disabled', false);
                $msg.text(SLK.i18n.error);
            }).always(function () {
                step(i + 1);
            });
        }

        step(0);
    });

    /* -----------------------------------------------------------------
     * Anchor report — expand a row into the posts that use that anchor.
     * Fetched on demand: the evidence is only wanted for the handful of
     * rows you actually question, and loading it for every row would mean
     * a query per anchor on page load.
     * ----------------------------------------------------------------- */
    $(document).on('click', '.slk-anchor-show', function () {
        var $btn = $(this);
        var $row = $btn.closest('.slk-anchor-row');
        var $next = $row.next('.slk-anchor-detail');

        if ($next.length) {          // already fetched — just toggle it
            $next.toggle();
            $btn.text($next.is(':visible') ? __('Hide', 'smartlinker') : __('Where?', 'smartlinker'));
            return;
        }

        $btn.prop('disabled', true);
        $.post(SLK.ajaxUrl, {
            action: 'slk_anchor_usages',
            nonce: SLK.nonce,
            anchor: $row.data('anchor')
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.success) {
                $btn.text(__('Error', 'smartlinker'));
                return;
            }
            var html = '<tr class="slk-anchor-detail"><td colspan="4"><div class="slk-anchor-uses">';
            res.data.rows.forEach(function (u) {
                html += '<div class="slk-anchor-use">' +
                    '<a href="' + esc(u.edit_url || '#') + '">' + esc(u.post_title) + '</a>' +
                    ' <span class="slk-title-meta">' + esc('“' + u.anchor + '”') + '</span>' +
                    slkArrow('slk-sg-arrow-sm') +
                    '<a href="' + esc(u.url) + '" target="_blank" rel="noopener">' + esc(u.target_title) + '</a>' +
                '</div>';
            });
            html += '</div></td></tr>';
            $row.after(html);
            $btn.text(__('Hide', 'smartlinker'));
        }).fail(function () {
            $btn.prop('disabled', false).text(__('Error', 'smartlinker'));
        });
    });

    /* -----------------------------------------------------------------
     * "Why not?" — replay the pipeline for one pair and show the verdict.
     * ----------------------------------------------------------------- */
    function diagMetrics(m) {
        if (!m) { return ''; }
        var rows = [];
        var num = function (v, d) { return v === null || v === undefined ? null : Number(v).toFixed(d); };

        if (m.anchor) { rows.push([__('Anchor found', 'smartlinker'), '“' + m.anchor + '”' + (m.tier ? ' (' + m.tier + ')' : '')]); }
        if (m.focus_keyword) { rows.push([__('Target focus keyword', 'smartlinker'), m.focus_keyword]); }
        if (m.similarity !== null && m.similarity !== undefined) {
            rows.push([__('Similarity', 'smartlinker'), sprintf(
                /* translators: 1: similarity score, 2: the minimum it had to beat */
                __('%1$s (floor %2$s)', 'smartlinker'), num(m.similarity, 3), num(m.floor, 3)) +
                (m.semantic ? __(' — by meaning', 'smartlinker') : __(' — by shared vocabulary', 'smartlinker'))]);
        }
        if (m.keyword_overlap !== undefined) { rows.push([__('Keyword overlap', 'smartlinker'), num(m.keyword_overlap, 2)]); }
        if (m.cluster_note) { rows.push([__('Topic cluster', 'smartlinker'), m.cluster_note]); }
        if (m.anchor_share !== undefined) { rows.push([__('Anchor appears in', 'smartlinker'),
            /* translators: %d: percentage of posts containing the anchor */
            sprintf(__('%d%% of posts', 'smartlinker'), Math.round(m.anchor_share * 100))]); }
        if (m.confidence !== null && m.confidence !== undefined) {
            rows.push([__('Confidence', 'smartlinker'), sprintf(
                /* translators: 1: confidence score, 2: the minimum required */
                __('%1$s (minimum %2$s)', 'smartlinker'), num(m.confidence, 2), num(m.min_confidence, 2))]);
        }
        if (!rows.length) { return ''; }

        return '<table class="slk-table slk-diag-metrics"><tbody>' + rows.map(function (r) {
            return '<tr class="slk-tr"><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }

    $(document).on('click', '.slk-diag-run', function () {
        var $btn = $(this);
        var source = $('.slk-diag-source').val();
        var target = $('.slk-diag-target').val();
        var $out = $('.slk-diag-result');

        if (!source || !target) {
            $out.html('<div class="slk-callout slk-callout-warn">' + esc(__('Pick both a post and a destination.', 'smartlinker')) + '</div>');
            return;
        }

        $btn.prop('disabled', true);
        $out.html('<p class="description">' + esc(__('Replaying the pipeline…', 'smartlinker')) + '</p>');

        $.post(SLK.ajaxUrl, {
            action: 'slk_diagnose',
            nonce: SLK.nonce,
            source_id: source,
            target_id: target
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.success) {
                $out.html('<div class="slk-callout slk-callout-warn">' +
                    esc((res && res.data && res.data.message) || SLK.i18n.error) + '</div>');
                return;
            }
            var d = res.data;
            var fixes = (d.fixes || []).map(function (f) {
                return '<li>' + esc(f) + '</li>';
            }).join('');

            $out.html(
                '<div class="slk-diag-verdict ' + (d.ok ? 'is-ok' : 'is-blocked') + '">' +
                    '<div class="slk-diag-headline">' + esc(d.headline) + '</div>' +
                    (d.detail ? '<p class="slk-diag-detail">' + esc(d.detail) + '</p>' : '') +
                    diagMetrics(d.metrics) +
                    (fixes ? '<div class="slk-diag-fixes"><strong>' +
                        esc(d.ok ? __('Details', 'smartlinker') : __('What would change it', 'smartlinker')) + '</strong><ul>' + fixes + '</ul></div>' : '') +
                '</div>'
            );
        }).fail(function () {
            $btn.prop('disabled', false);
            $out.html('<div class="slk-callout slk-callout-warn">' + esc(SLK.i18n.error) + '</div>');
        });
    });

    /* -----------------------------------------------------------------
     * Scan progress.
     *
     * Attaches to any link marked data-slk-scan and runs the scan in slices,
     * drawing a bar between them. The link's own href still points at the
     * old redirect-chain scan, so with JavaScript off the button keeps
     * working — it just does not report progress.
     * ----------------------------------------------------------------- */
    /**
     * Where to put the bar.
     *
     * Not simply "after the button's parent": several of these buttons sit in
     * a flex toolbar, and a bar dropped in there becomes a flex ITEM — a 240px
     * sliver wedged beside the button instead of a full-width bar under it.
     * So climb until the element's own parent lays out as a block, and insert
     * after that.
     */
    function scanBarAnchor($btn) {
        var el = $btn[0];
        while (el && el.parentElement) {
            var d = getComputedStyle(el.parentElement).display;
            if (d !== 'flex' && d !== 'inline-flex' && d !== 'grid' && d !== 'inline-grid') {
                return $(el);
            }
            el = el.parentElement;
        }
        return $btn;
    }

    function scanBar($btn) {
        var $bar = $(
            '<div class="slk-scanbar" role="status" aria-live="polite">' +
                '<div class="slk-scanbar-head">' +
                    '<span class="slk-scanbar-label"></span>' +
                    '<span class="slk-scanbar-count"></span>' +
                '</div>' +
                '<div class="slk-scanbar-track"><span></span></div>' +
                '<div class="slk-scanbar-note"></div>' +
            '</div>'
        );
        scanBarAnchor($btn).after($bar);
        return $bar;
    }

    function runScan(job, $btn) {
        var $bar = $btn.data('slkBar');
        if (!$bar) { $bar = scanBar($btn); $btn.data('slkBar', $bar); }

        var startedAt = Date.now();
        var label = '';

        function step(offset) {
            $.post(SLK.ajaxUrl, {
                action: 'slk_scan_batch', nonce: SLK.nonce, job: job, offset: offset
            }).done(function (res) {
                if (!res || !res.success) {
                    $bar.find('.slk-scanbar-note').text((res && res.data && res.data.message) || SLK.i18n.error);
                    $bar.addClass('is-error');
                    $btn.prop('disabled', false);
                    return;
                }
                var d = res.data;
                label = d.label || label;
                var pct = d.total ? Math.min(100, Math.round((d.scanned / d.total) * 100)) : 100;

                $bar.find('.slk-scanbar-label').text(label);
                /* translators: 1: items scanned, 2: items total, 3: percentage complete */
                var countFmt = __('%1$d of %2$d · %3$d%%', 'smartlinker');
                $bar.find('.slk-scanbar-count').text(
                    d.total
                        ? sprintf(countFmt, d.scanned, d.total, pct)
                        : __('nothing to scan', 'smartlinker')
                );
                $bar.find('.slk-scanbar-track span').css('width', pct + '%');

                if (!d.done) {
                    // A rough estimate is more use than none: it tells you
                    // whether to wait or come back later.
                    var elapsed = (Date.now() - startedAt) / 1000;
                    var rate = d.scanned > 0 ? elapsed / d.scanned : 0;
                    var left = Math.round(rate * (d.total - d.scanned));
                    /* translators: %d: number of results found so far */
                    var foundFmt = _n('%d found so far', '%d found so far', d.found, 'smartlinker');
                    /* translators: %d: estimated seconds remaining */
                    var tailFmt = __(' · about %ds left', 'smartlinker');
                    /* translators: %d: estimated seconds remaining */
                    var aloneFmt = __('about %ds left', 'smartlinker');
                    $bar.find('.slk-scanbar-note').text(
                        d.found
                            ? sprintf(foundFmt, d.found) +
                              (left > 4 ? sprintf(tailFmt, left) : '')
                            : (left > 4 ? sprintf(aloneFmt, left) : __('working…', 'smartlinker'))
                    );
                    step(d.scanned);
                    return;
                }

                $bar.addClass('is-done');
                $bar.find('.slk-scanbar-track span').css('width', '100%');
                $bar.find('.slk-scanbar-note').text(
                    /* translators: %d: number of results the scan found */
                    sprintf(_n('Finished — %d found. Reloading…', 'Finished — %d found. Reloading…', d.found, 'smartlinker'), d.found)
                );
                // The page's tables are rendered server-side, so it has to
                // reload to show what the scan produced.
                setTimeout(function () { window.location.href = $btn.data('slk-done') || window.location.href.split('#')[0]; }, 900);
            }).fail(function () {
                $bar.addClass('is-error');
                $bar.find('.slk-scanbar-note').text(SLK.i18n.error);
                $btn.prop('disabled', false);
            });
        }

        // Say something before the first batch answers. On a slow site that
        // round trip can take seconds, and a bar with nothing in it reads as
        // broken rather than busy.
        $bar.removeClass('is-done is-error');
        $bar.find('.slk-scanbar-label').text($btn.data('slk-label') || __('Starting…', 'smartlinker'));
        $bar.find('.slk-scanbar-count').text('');
        $bar.find('.slk-scanbar-note').text(__('Counting what needs doing…', 'smartlinker'));
        $bar.find('.slk-scanbar-track span').css('width', '0%');

        $btn.prop('disabled', true).addClass('slk-btn-loading');
        step(0);
    }

    $(document).on('click', '[data-slk-scan]', function (e) {
        e.preventDefault();
        runScan($(this).data('slk-scan'), $(this));
    });

    // Target Keywords — find keyword-anchored inbound opportunities.
    $(document).on('click', '.slk-tk-find', function () {
        var $btn = $(this);
        var id = $btn.data('keyword-id');
        var $wrap = $('.slk-tk-results[data-for="' + id + '"]');
        $btn.prop('disabled', true);
        $wrap.html('<span class="description">' + esc(SLK.i18n.loading) + '</span>');

        $.post(SLK.ajaxUrl, {
            action: 'slk_find_keyword_opportunities',
            nonce: SLK.nonce,
            keyword_id: id
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.success) {
                $wrap.html('<span class="description">' + esc((res && res.data && res.data.message) || SLK.i18n.error) + '</span>');
                return;
            }
            var items = res.data.opportunities || [];
            if (!items.length) {
                $wrap.html('<span class="description">' + esc(__('No opportunities right now — every mention already links here.', 'smartlinker')) + '</span>');
                return;
            }
            var html = items.map(function (s) {
                return '' +
                    '<div class="slk-suggestion" data-url="' + esc(s.url) + '" data-phrase="' + esc(s.phrase) + '" data-source="' + esc(s.source_id) + '">' +
                        '<div class="slk-sugg-main">' +
                            '<span class="slk-phrase">' + esc(s.phrase) + '</span>' +
                            '<span class="slk-target">' +
                            /* translators: %s: the post the mention appears in */
                            esc(sprintf(__('in: %s', 'smartlinker'), s.source_title)) + '</span>' +
                        '</div>' +
                        '<button type="button" class="button slk-insert-inbound">' + esc(__('Insert link', 'smartlinker')) + '</button>' +
                    '</div>';
            }).join('');
            $wrap.html(html);
        }).fail(function () {
            $btn.prop('disabled', false);
            $wrap.html('<span class="description">' + esc(SLK.i18n.error) + '</span>');
        });
    });

    // AI-powered suggestions (OpenAI). Renders into the same list as keyword ones.
    $(document).on('click', '.slk-ai-scan', function () {
        var $box = metaboxFor($(this));
        var postId = $box.data('post-id');
        var $status = $box.find('.slk-status');
        var $wrap = $box.find('.slk-suggestions');
        var $btn = $(this);

        $status.text(__('Asking the AI…', 'smartlinker'));
        $wrap.html(skeleton(3));
        $btn.prop('disabled', true);

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_ai_suggestions',
            nonce: SLK.nonce,
            post_id: postId
        }).done(function (res) {
            $btn.prop('disabled', false);
            $status.text('');
            if (res && res.success) {
                renderSuggestions($wrap, res.data.suggestions, { ai: true });
            } else {
                $status.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text(SLK.i18n.error);
        });
    });

    // Build an anchor <a> string matching the server-side format.
    function buildAnchor(phrase, url) {
        var rel = [];
        var d = SLK.linkDefaults || {};
        var target = d.new_tab ? ' target="_blank"' : '';
        if (d.nofollow) { rel.push('nofollow'); }
        if (d.new_tab) { rel.push('noopener'); }
        var relAttr = rel.length ? ' rel="' + rel.join(' ') + '"' : '';
        return '<a href="' + esc(url) + '"' + target + relAttr + ' data-slk="1">' + esc(phrase) + '</a>';
    }

    // Replace the first case-insensitive match of phrase that sits in plain
    // text (not inside a tag or an existing anchor). Mirrors the PHP logic.
    function replaceFirstOutsideTags(content, phrase, replacement) {
        var parts = content.split(/(<[^>]+>)/);
        var insideAnchor = false, done = false;
        var re = new RegExp('\\b' + phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i');
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (!part) { continue; }
            if (part.charAt(0) === '<') {
                if (/^<a[\s>]/i.test(part)) { insideAnchor = true; }
                else if (/^<\/a\s*>/i.test(part)) { insideAnchor = false; }
                continue;
            }
            if (done || insideAnchor) { continue; }
            if (re.test(part)) {
                parts[i] = part.replace(re, replacement);
                done = true;
            }
        }
        return done ? parts.join('') : null;
    }

    // Is the block editor active on this screen?
    function blockEditor() {
        return (window.wp && wp.data && wp.blocks &&
            wp.data.select('core/block-editor') &&
            typeof wp.data.select('core/editor').getEditedPostContent === 'function')
            ? wp.data : null;
    }

    $(document).on('click', '.slk-insert', function () {
        var $sugg = $(this).closest('.slk-suggestion');
        var $box = $(this).closest('.slk-metabox');
        var $btn = $(this);
        var vals = cardValues($sugg);
        var phrase = String(vals.phrase);
        var url = String(vals.url);
        if (!phrase || !url) {
            $sugg.find('.slk-sugg-msg').text(__('Anchor text and URL are both required.', 'smartlinker'));
            return;
        }

        var data = blockEditor();
        if (data) {
            // Block-native: edit the editor's own content so it saves with the
            // post and shows immediately — no conflict with unsaved changes.
            try {
                var content = data.select('core/editor').getEditedPostContent();
                var updated = replaceFirstOutsideTags(content, phrase, buildAnchor(phrase, url));
                if (!updated) {
                    $sugg.find('.slk-sugg-msg')
                        .text(__('That exact text isn\'t in the post — adjust the anchor and try again.', 'smartlinker'));
                    return;
                }
                data.dispatch('core/editor').resetEditorBlocks(wp.blocks.parse(updated));
                $sugg.addClass('slk-inserted slk-just-added').removeClass('slk-picked');
                $sugg.find('.slk-sugg-check').prop('checked', false).prop('disabled', true);
                refreshProgress($sugg.parent());
                $btn.prop('disabled', true).removeClass('slk-btn-loading').text('✓ ' + __('Added', 'smartlinker'));
                $box.find('.slk-status').text(SLK.i18n.inserted + ' ' + __('Remember to Update the post to save.', 'smartlinker'));
            } catch (e) {
                $box.find('.slk-status').text(SLK.i18n.error);
            }
            return;
        }

        // Classic editor / no block data: fall back to a server-side insert.
        $btn.prop('disabled', true).text('…');
        $.post(SLK.ajaxUrl, {
            action: 'slk_insert_link',
            nonce: SLK.nonce,
            post_id: $box.data('post-id'),
            phrase: phrase,
            url: url
        }).done(function (res) {
            if (res && res.success) {
                $sugg.addClass('slk-inserted slk-just-added').removeClass('slk-picked');
                $sugg.find('.slk-sugg-check').prop('checked', false).prop('disabled', true);
                refreshProgress($sugg.parent());
                $btn.prop('disabled', true).removeClass('slk-btn-loading').text('✓ ' + __('Added', 'smartlinker'));
                $box.find('.slk-status').text(SLK.i18n.inserted +
                    ' ' + __('(reload the editor to see it in the content)', 'smartlinker'));
            } else {
                $btn.prop('disabled', false).text(__('Add Link', 'smartlinker'));
                $box.find('.slk-status').text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(__('Insert', 'smartlinker'));
            $box.find('.slk-status').text(SLK.i18n.error);
        });
    });

    // Help panels: flip to the left when there isn't room on the right,
    // and nudge vertically so a tall panel stays inside the viewport.
    $(document).on('mouseenter focusin', '.slk-help', function () {
        var el = this;
        var $bubble = $(el).children('.slk-help-bubble');
        if (!$bubble.length) { return; }

        $(el).removeClass('slk-help-flip');
        $bubble.css('marginTop', '');

        var iconRect = el.getBoundingClientRect();
        var width = $bubble.outerWidth();
        // Not enough room to the right? Flip it.
        if (iconRect.right + 14 + width > window.innerWidth - 16) {
            $(el).addClass('slk-help-flip');
        }

        // Keep a tall panel on screen vertically.
        var height = $bubble.outerHeight();
        var centre = iconRect.top + iconRect.height / 2;
        var overflowTop = (centre - height / 2) - 16;
        var overflowBottom = (centre + height / 2) - (window.innerHeight - 16);
        if (overflowTop < 0) {
            $bubble.css('marginTop', (-overflowTop) + 'px');
        } else if (overflowBottom > 0) {
            $bubble.css('marginTop', (-overflowBottom) + 'px');
        }
    });

    // Links Report: expand a row to show that post's actual links.
    $(document).on('click', '.slk-expand', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var postId = $row.data('post');
        var $detail = $('tr.slk-detail-row[data-detail="' + postId + '"]');

        if ($btn.hasClass('open')) {
            $btn.removeClass('open').text('+');
            $detail.attr('hidden', true);
            return;
        }
        $btn.addClass('open').text('−');
        $detail.removeAttr('hidden');

        if ($detail.data('loaded')) {
            return;
        }

        $.post(SLK.ajaxUrl, {
            action: 'slk_post_links',
            nonce: SLK.nonce,
            post_id: postId
        }).done(function (res) {
            if (!res || !res.success) {
                $detail.find('.slk-detail').html('<span class="slk-detail-empty">' + esc(SLK.i18n.error) + '</span>');
                return;
            }
            var d = res.data;

            var inbound = (d.inbound && d.inbound.length)
                ? '<ul class="slk-detail-list">' + d.inbound.map(function (l) {
                    return '<li><span class="slk-detail-anchor">' + esc(l.anchor || '—') + '</span>' +
                        '<span class="slk-detail-sub">' + /* translators: %s: the post an inbound link comes from */
                        esc(sprintf(__('from: %s', 'smartlinker'), l.post_title || __('(no title)', 'smartlinker'))) + '</span></li>';
                }).join('') + '</ul>'
                : '<span class="slk-detail-empty">' + esc(__('No inbound internal links yet.', 'smartlinker')) + '</span>';

            var outbound = (d.outbound && d.outbound.length)
                ? '<ul class="slk-detail-list">' + d.outbound.map(function (l) {
                    var flag = '';
                    if (Number(l.broken) === 1) {
                        flag = ' <span class="slk-badge slk-badge-bad">' + esc(l.status_code > 0 ? l.status_code : __('broken', 'smartlinker')) + '</span>';
                    } else if (l.type === 'external') {
                        flag = ' <span class="slk-chip">' + esc(__('external', 'smartlinker')) + '</span>';
                    }
                    return '<li><span class="slk-detail-anchor">' + esc(l.anchor || '—') + flag + '</span>' +
                        '<span class="slk-detail-sub">' + esc(l.url) + '</span></li>';
                }).join('') + '</ul>'
                : '<span class="slk-detail-empty">' + esc(__('This post has no links.', 'smartlinker')) + '</span>';

            $detail.find('.slk-detail').html(
                '<div class="slk-detail-cols">' +
                    '<div class="slk-detail-col"><h4>' + esc(__('Inbound internal links', 'smartlinker')) + '</h4>' + inbound + '</div>' +
                    '<div class="slk-detail-col"><h4>' + esc(__('Links in this post', 'smartlinker')) + '</h4>' + outbound + '</div>' +
                '</div>'
            );
            $detail.data('loaded', true);
        }).fail(function () {
            $detail.find('.slk-detail').html('<span class="slk-detail-empty">' + esc(SLK.i18n.error) + '</span>');
        });
    });


    // Read the (possibly edited) anchor and URL out of a suggestion card.
    function cardValues($card) {
        var phrase = $card.find('.slk-anchor-input').val();
        var url = $card.find('.slk-url-input').val();
        return {
            phrase: (phrase !== undefined ? phrase : $card.data('phrase')) || '',
            url: (url !== undefined ? url : $card.data('url')) || ''
        };
    }

    // Pencil: reveal the input for just that side of the row.
    $(document).on('click', '.slk-edit-btn', function () {
        var $cell = $(this).closest('.slk-cell');
        $cell.addClass('slk-editing');
        $cell.find('.slk-view').attr('hidden', true);
        $cell.find('.slk-edit').removeAttr('hidden');
        $cell.find('input').trigger('focus').trigger('select');
    });

    // Leave edit mode, optionally throwing the edit away.
    function closeEditing($card, revert) {
        $card.find('.slk-cell.slk-editing').each(function () {
            var $cell = $(this);
            if (revert) {
                var $input = $cell.find('input');
                var original = $input.hasClass('slk-anchor-input')
                    ? $card.data('phrase') : $card.data('url');
                $input.val(original);
            }
            $cell.removeClass('slk-editing');
            $cell.find('.slk-edit').attr('hidden', true);
            $cell.find('.slk-view').removeAttr('hidden');
        });
    }

    // Enter confirms an edit; Escape cancels it.
    $(document).on('keydown', '.slk-anchor-input, .slk-url-input', function (e) {
        var $card = $(this).closest('.slk-sugg-card');
        if (e.key === 'Enter') {
            e.preventDefault();
            closeEditing($card, false);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeEditing($card, true);
        }
    });

    // × lives inside the edit field and only discards that edit.
    $(document).on('click', '.slk-cancel-edit', function () {
        var $card = $(this).closest('.slk-sugg-card');
        closeEditing($card, true);
        $card.find('.slk-sugg-msg').text('');
    });

    /* ---- Bulk selection ------------------------------------------------ */

    function refreshBulk($wrap) {
        var $checks = $wrap.find('.slk-sugg-check');
        var n = $checks.filter(':checked').length;
        var $btn = $wrap.find('.slk-add-selected');
        /* translators: %d: number of suggestions ticked */
        var selFmt = __('Add selected (%d)', 'smartlinker');
        $btn.prop('disabled', n === 0)
            .text(n ? sprintf(selFmt, n) : __('Add selected', 'smartlinker'));
        $wrap.find('.slk-check-all').prop('checked', n > 0 && n === $checks.length);
    }

    $(document).on('change', '.slk-sugg-check', function () {
        var $card = $(this).closest('.slk-sugg-card');
        $card.toggleClass('slk-picked', this.checked);
        refreshBulk($(this).closest('.slk-suggestions, .slk-inbound-results, .slk-ai-page-results'));
    });

    $(document).on('change', '.slk-check-all', function () {
        var checked = this.checked;
        var $wrap = $(this).closest('.slk-suggestions, .slk-inbound-results, .slk-ai-page-results');
        $wrap.find('.slk-sugg-card').not('.slk-inserted').each(function () {
            $(this).toggleClass('slk-picked', checked)
                .find('.slk-sugg-check').prop('checked', checked);
        });
        refreshBulk($wrap);
    });

    // Insert every selected suggestion, one after another.
    $(document).on('click', '.slk-add-selected', function () {
        var $btn = $(this);
        var $wrap = $btn.closest('.slk-suggestions, .slk-inbound-results, .slk-ai-page-results');
        var queue = $wrap.find('.slk-sugg-check:checked').closest('.slk-sugg-card').toArray();
        if (!queue.length) { return; }

        $btn.prop('disabled', true).addClass('slk-btn-loading');

        (function next() {
            var el = queue.shift();
            if (!el) {
                $btn.removeClass('slk-btn-loading');
                refreshBulk($wrap);
                return;
            }
            var $card = $(el);
            $card.find('.slk-sugg-check').prop('checked', false);
            $card.removeClass('slk-picked');
            $card.find('.slk-insert, .slk-insert-inbound').trigger('click');
            // Give each insert a moment to settle before the next one.
            setTimeout(next, 450);
        })();
    });

    // Standalone AI Suggestions page: analyse any post without the editor.
    $(document).on('click', '.slk-ai-page-scan', function () {
        var $btn = $(this);
        var postId = $('#slk-ai-post').val();
        var $status = $('.slk-ai-page-status');
        var $wrap = $('.slk-ai-page-results');

        if (!postId) {
            $status.text(__('Select a post first.', 'smartlinker'));
            return;
        }
        $btn.prop('disabled', true);
        $status.text(__('Asking the AI…', 'smartlinker'));
        $wrap.html(skeleton(3));

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_ai_suggestions',
            nonce: SLK.nonce,
            post_id: postId
        }).done(function (res) {
            $btn.prop('disabled', false);
            $status.text('');
            if (res && res.success) {
                // Insert into the post we analysed.
                renderSuggestions($wrap, res.data.suggestions, { ai: true, sourceId: postId });
            } else {
                $status.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text(SLK.i18n.error);
        });
    });

    // URL Changer: preview how many links a change would affect.
    $(document).on('click', '.slk-url-preview-btn', function () {
        var $btn = $(this);
        var $out = $('.slk-url-preview');
        var oldUrl = $('#slk-old-url').val();
        if (!oldUrl) {
            $out.show().html('<span class="description">' + esc(__('Enter the old URL first.', 'smartlinker')) + '</span>');
            return;
        }
        $btn.prop('disabled', true);
        $out.show().html('<span class="description">' + esc(__('Checking…', 'smartlinker')) + '</span>');

        $.post(SLK.ajaxUrl, {
            action: 'slk_url_preview',
            nonce: SLK.nonce,
            old_url: oldUrl
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                var d = res.data;
                if (!d.occurrences) {
                    $out.html('<div class="slk-callout">' + esc(__('No links found for that URL.', 'smartlinker')) + '</div>');
                } else {
                    $out.html('<div class="slk-callout slk-callout-good">' +
                        esc(sprintf(
                            /* translators: 1: number of links, 2: number of posts */
                            _n('This will update %1$d internal link across %2$d post.',
                               'This will update %1$d internal links across %2$d posts.',
                               d.occurrences, 'smartlinker'),
                            d.occurrences, d.posts)) + '</div>');
                }
            } else {
                $out.html('<span class="description">' + esc(SLK.i18n.error) + '</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $out.html('<span class="description">' + esc(SLK.i18n.error) + '</span>');
        });
    });

    /* =========================================================
       SPA layer: navigate sections and submit forms without a
       full page reload. The only full load is opening the plugin.
       ========================================================= */
    (function () {
        if (!document.querySelector('.slk-app')) {
            return; // only on SmartLinker admin pages
        }

        /*
         * The spinner is deliberately DELAYED.
         *
         * Most navigations finish in well under 150ms. Showing a spinner for
         * that long does not tell you anything you did not know — it just puts
         * a flash of movement on screen and makes a fast page feel like it
         * buffered. Below the threshold you see nothing at all and the new
         * panel simply appears; above it, the wait is real and worth marking.
         */
        var LOADING_DELAY = 250;
        var loadingTimer = null;

        function setLoading(on) {
            var main = document.querySelector('.slk-main');
            if (!main) { return; }

            if (loadingTimer) {
                clearTimeout(loadingTimer);
                loadingTimer = null;
            }
            if (!on) {
                main.classList.remove('slk-loading');
                return;
            }
            loadingTimer = setTimeout(function () {
                loadingTimer = null;
                main.classList.add('slk-loading');
            }, LOADING_DELAY);
        }

        // Floating toast, so saving doesn't yank you to the top of the page.
        function toast(message, type) {
            var icon = type === 'error' ? 'dashicons-warning' : 'dashicons-yes-alt';
            var $t = $('<div class="slk-toast slk-toast-' + (type || 'success') + '">' +
                '<span class="dashicons ' + icon + '"></span><span class="slk-toast-msg"></span></div>');
            $t.find('.slk-toast-msg').text(message);
            $('body').append($t);
            // Next frame so the transition runs.
            requestAnimationFrame(function () { $t.addClass('show'); });
            setTimeout(function () {
                $t.removeClass('show');
                setTimeout(function () { $t.remove(); }, 300);
            }, 3400);
        }

        /**
         * Pull the fetched page's main column + sidebar into the current DOM.
         * opts: { push, scroll, notify }
         */
        function swap(html, url, opts) {
            opts = opts || {};
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newMain = doc.querySelector('.slk-main');
            var curMain = document.querySelector('.slk-main');
            if (!newMain || !curMain) { window.location.href = url; return; }

            curMain.innerHTML = newMain.innerHTML;

            var newNav = doc.querySelector('.slk-nav');
            var curNav = document.querySelector('.slk-nav');
            if (newNav && curNav) { curNav.innerHTML = newNav.innerHTML; }

            if (doc.title) { document.title = doc.title; }
            if (opts.push) { history.pushState({ slk: 1 }, '', url); }
            setLoading(false);

            // After a save, report the result and drop the inline notice — no
            // scrolling, so you stay where you were working.
            //
            // Where the form offers a status slot the message goes there, beside
            // the button that was just pressed: that is where attention already
            // is, and it needs no panel of its own to be read. The floating
            // toast stays for everything that has nowhere better to put it.
            if (opts.notify) {
                var $notice = $(curMain).find('.notice').first();
                if ($notice.length) {
                    var msg = $notice.find('p').first().text().trim() || $notice.text().trim();
                    var isError = $notice.hasClass('notice-error');
                    $notice.remove();

                    if (msg) {
                        var $slot = $(curMain).find('.slk-save-status').filter(function () {
                            return $(this).closest('[data-slk-panel]').length === 0 ||
                                   !$(this).closest('[data-slk-panel]').prop('hidden');
                        }).first();

                        if ($slot.length) {
                            $slot.attr('class', 'slk-save-status is-' + (isError ? 'error' : 'done'))
                                 .text(msg)
                                 .addClass('show');
                            clearTimeout($slot.data('slkTimer'));
                            $slot.data('slkTimer', setTimeout(function () {
                                $slot.removeClass('show');
                            }, 4000));
                        } else {
                            toast(msg, isError ? 'error' : 'success');
                        }
                    }
                }
            }

            if (opts.scroll !== false) {
                window.scrollTo({ top: 0, behavior: 'auto' });
            }
            $(document).trigger('slk:loaded');
        }

        /**
         * Plain navigation goes through admin-ajax, which renders the section
         * alone. Fetching admin.php instead means building the whole admin
         * menu and running every other plugin's admin bootstrap to produce
         * markup that is then discarded.
         *
         * A nonce in the URL means this is an ACTION, not navigation — a scan,
         * an undo, a rebuild — and those keep the full admin path so their
         * admin_init handlers and redirects behave exactly as before.
         */
        function isPlainNavigation(url) {
            return url.indexOf('_wpnonce') === -1;
        }

        function slugOf(url) {
            var m = /[?&]page=([a-z0-9_\-]+)/i.exec(url);
            return m ? m[1] : '';
        }

        function navigateFull(url, push) {
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    var finalUrl = r.url || url; // follow redirects (e.g. &deleted=1)
                    return r.text().then(function (html) { return { finalUrl: finalUrl, html: html }; });
                })
                .then(function (o) { swap(o.html, o.finalUrl, { push: push, scroll: true }); })
                .catch(function () { window.location.href = url; });
        }

        function navigate(url, push) {
            setLoading(true);

            var slug = slugOf(url);
            if (!slug || !isPlainNavigation(url)) {
                navigateFull(url, push);
                return;
            }

            var body = new URLSearchParams();
            body.set('action', 'slk_section');
            body.set('nonce', SLK.nonce);
            body.set('slug', slug);
            body.set('query', (url.split('?')[1] || ''));

            fetch(SLK.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.data || !res.data.html) {
                        throw new Error('section render failed');
                    }
                    swap(res.data.html, url, { push: push, scroll: true });
                })
                // Any doubt at all falls back to the full page, which always
                // works. A fast path is only worth having if it cannot strand you.
                .catch(function () { navigateFull(url, push); });
        }

        function isSectionLink(a) {
            if (!a || !a.href || a.target === '_blank') { return false; }
            // Settings tabs are switched in the browser; see the tab toggle below.
            if (a.hasAttribute('data-slk-tab')) { return false; }
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#') { return false; }
            if (a.href.indexOf('page=smartlinker') === -1) { return false; } // our pages only
            if (a.href.indexOf('slk_export') !== -1) { return false; }        // let CSV downloads through
            return !!a.closest('.slk-app');
        }

        /**
         * Settings tabs: instant, because every panel is already in the page.
         *
         * The URL is still updated so the tab is linkable and the back button
         * behaves — it just costs nothing to get there.
         */
        function showSettingsTab(key, push) {
            var panels = document.querySelectorAll('[data-slk-panel]');
            if (!panels.length) { return false; }
            var found = false;

            Array.prototype.forEach.call(panels, function (p) {
                var mine = p.getAttribute('data-slk-panel') === key;
                if (mine) { found = true; }
                p.hidden = !mine;
            });
            if (!found) { return false; }

            Array.prototype.forEach.call(document.querySelectorAll('[data-slk-tab]'), function (a) {
                a.classList.toggle('active', a.getAttribute('data-slk-tab') === key);
            });

            if (push) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', key);
                history.pushState({ slk: 1, slkTab: key }, '', url.toString());
            }
            // Comboboxes and anything else that decorates a panel need to run
            // against whichever panel just became visible.
            $(document).trigger('slk:loaded');
            return true;
        }

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
            var a = e.target.closest ? e.target.closest('[data-slk-tab]') : null;
            if (!a) { return; }
            if (showSettingsTab(a.getAttribute('data-slk-tab'), true)) {
                e.preventDefault();
            }
        }, false);

        // Back/forward across tabs, and after a full section swap lands on a
        // settings page carrying ?tab=.
        window.addEventListener('popstate', function () {
            var key = new URL(window.location.href).searchParams.get('tab');
            if (key) { showSettingsTab(key, false); }
        });

        $(document).on('slk:loaded', function () {
            var key = new URL(window.location.href).searchParams.get('tab');
            var panels = document.querySelectorAll('[data-slk-panel]');
            if (!key || !panels.length) { return; }
            var active = document.querySelector('[data-slk-panel="' + key + '"]');
            if (active && active.hidden) {
                Array.prototype.forEach.call(panels, function (p) {
                    p.hidden = p.getAttribute('data-slk-panel') !== key;
                });
                Array.prototype.forEach.call(document.querySelectorAll('[data-slk-tab]'), function (a) {
                    a.classList.toggle('active', a.getAttribute('data-slk-tab') === key);
                });
            }
        });

        // Intercept section + in-panel action links.
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
            var a = e.target.closest ? e.target.closest('a') : null;
            if (!isSectionLink(a)) { return; }
            e.preventDefault();
            navigate(a.href, true);
        }, false);

        // Intercept form submissions inside the panel (settings, add-rule,
        // imports, url changer, etc.) and post them via fetch.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (e.defaultPrevented || !form || !form.closest || !form.closest('.slk-panel')) { return; }
            e.preventDefault();

            var method = (form.getAttribute('method') || 'get').toUpperCase();
            var action = form.getAttribute('action') || window.location.href;
            if (!action) { action = window.location.href; }
            setLoading(true);

            var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
            var $submitBtn = null;
            if (method === 'POST') {
                var fd = new FormData(form);
                // Include the clicked submit button's name/value (handlers rely on it).
                var submitter = e.submitter || document.activeElement;
                if (submitter && submitter.name && form.contains(submitter)) {
                    fd.append(submitter.name, submitter.value || '1');
                }
                // Spinner on the button that was pressed.
                $submitBtn = $(submitter && form.contains(submitter) ? submitter : form.querySelector('[type="submit"]'));
                if ($submitBtn.length) {
                    $submitBtn.addClass('slk-btn-loading').prop('disabled', true);
                }
                // Clear the previous result before starting, so the slot never
                // holds a stale "saved" from the last time — even hidden, it
                // would be read out by a screen reader on the next focus.
                $(form).find('.slk-save-status').removeClass('show').text('');
                opts.body = fd;
            } else {
                var params = new URLSearchParams(new FormData(form)).toString();
                action += (action.indexOf('?') === -1 ? '?' : '&') + params;
            }

            fetch(action, opts)
                .then(function (r) { return r.text().then(function (t) { return { url: r.url || action, html: t }; }); })
                .then(function (res) {
                    // Saving keeps your scroll position and reports via a toast.
                    swap(res.html, res.url, { push: true, scroll: false, notify: true });
                })
                .catch(function () { form.submit(); });
        }, false);

        window.addEventListener('popstate', function () {
            if (document.querySelector('.slk-app')) { navigate(location.href, false); }
        });
    })();

    /* ---------------------------------------------------------------------
     * Topic clusters treemap.
     *
     * Squarified treemap (Bruls, Huizing & van Wijk): lay each row along the
     * shorter side and only keep adding to it while the worst aspect ratio
     * improves. Naive slice-and-dice produces unreadable slivers once one
     * cluster dwarfs the rest, which is exactly the shape a real site has.
     *
     * Colour lives in CSS classes, never here — re-theming stays a CSS job.
     * ------------------------------------------------------------------- */

    function worstRatio(row, len) {
        var s = 0, mx = -Infinity, mn = Infinity, i;
        for (i = 0; i < row.length; i++) {
            s += row[i].area;
            if (row[i].area > mx) { mx = row[i].area; }
            if (row[i].area < mn) { mn = row[i].area; }
        }
        if (s <= 0 || len <= 0) { return Infinity; }
        return Math.max((len * len * mx) / (s * s), (s * s) / (len * len * mn));
    }

    function squarify(items, rect, out) {
        while (items.length) {
            var shortest = Math.min(rect.w, rect.h);
            if (shortest <= 0) { return; }

            var row = [];
            while (items.length) {
                var candidate = row.concat([items[0]]);
                if (row.length === 0 || worstRatio(candidate, shortest) <= worstRatio(row, shortest)) {
                    row = candidate;
                    items.shift();
                } else {
                    break;
                }
            }

            var rowArea = 0;
            for (var k = 0; k < row.length; k++) { rowArea += row[k].area; }
            var thickness = rowArea / shortest;
            var pos, j;

            if (rect.w >= rect.h) {
                pos = rect.y;
                for (j = 0; j < row.length; j++) {
                    var hh = row[j].area / thickness;
                    out.push({ data: row[j].data, x: rect.x, y: pos, w: thickness, h: hh });
                    pos += hh;
                }
                rect = { x: rect.x + thickness, y: rect.y, w: rect.w - thickness, h: rect.h };
            } else {
                pos = rect.x;
                for (j = 0; j < row.length; j++) {
                    var ww = row[j].area / thickness;
                    out.push({ data: row[j].data, x: pos, y: rect.y, w: ww, h: thickness });
                    pos += ww;
                }
                rect = { x: rect.x, y: rect.y + thickness, w: rect.w, h: rect.h - thickness };
            }
        }
    }

    function renderTreemap() {
        var wrap = document.querySelector('.slk-treemap');
        if (!wrap) { return; }
        var canvas = wrap.querySelector('.slk-treemap-canvas');
        if (!canvas) { return; }

        var clusters;
        try { clusters = JSON.parse(wrap.getAttribute('data-clusters') || '[]'); }
        catch (e) { return; }
        if (!clusters.length) { return; }

        var view = wrap.getAttribute('data-view') || 'size';
        var width = canvas.clientWidth;
        // Keep the map a readable shape on every screen without going taller
        // than a screenful on a site with one dominant cluster.
        var height = Math.max(320, Math.min(560, Math.round(width * 0.42)));
        if (width <= 0) { return; }
        canvas.style.height = height + 'px';

        var total = 0, i;
        for (i = 0; i < clusters.length; i++) { total += Math.max(0, clusters[i].count); }
        if (total <= 0) { return; }

        var scale = (width * height) / total;
        var items = clusters.map(function (c) {
            return { data: c, area: Math.max(0, c.count) * scale };
        }).filter(function (it) { return it.area > 0; })
          .sort(function (a, b) { return b.area - a.area; });

        var placed = [];
        squarify(items.slice(), { x: 0, y: 0, w: width, h: height }, placed);

        canvas.innerHTML = placed.map(function (p, idx) {
            var c = p.data;
            var pct = Math.round((c.health || 0) * 100);
            var tone = view === 'health'
                ? (pct >= 70 ? 'slk-tm-good' : (pct >= 35 ? 'slk-tm-mid' : 'slk-tm-bad'))
                : 'slk-tm-c' + ((idx % 8) + 1);

            // Below roughly this size the label is noise, so drop it and let
            // the tooltip carry the detail.
            var roomy = p.w > 110 && p.h > 54;
            var tiny = p.w < 58 || p.h < 34;

            var title = sprintf(
                /* translators: 1: cluster name, 2: post count, 3: percentage linked */
                _n('%1$s — %2$d post, %3$d%% linked up', '%1$s — %2$d posts, %3$d%% linked up', c.count, 'smartlinker'),
                c.name, c.count, pct) +
                /* translators: %d: number of orphaned posts in this cluster */
                (c.orphans ? ', ' + sprintf(_n('%d orphan', '%d orphans', c.orphans, 'smartlinker'), c.orphans) : '') +
                /* translators: %s: the cluster's pillar post */
                (c.pillar ? sprintf(__(' · pillar: %s', 'smartlinker'), c.pillar) : '');

            var label = tiny ? '' :
                '<span class="slk-tm-name">' + esc(c.name) + '</span>' +
                (roomy ? '<span class="slk-tm-sub">' +
                    /* translators: %d: number of posts in this cluster */
                    esc(sprintf(_n('%d post', '%d posts', c.count, 'smartlinker'), c.count)) +
                    /* translators: %d: percentage of the cluster that is linked */
                    (view === 'health' ? esc(sprintf(__(' · %d%% linked', 'smartlinker'), pct)) : '') + '</span>' : '') +
                (roomy && c.stage ? '<span class="slk-tm-stage slk-stage-' + esc(String(c.stage).toLowerCase()) + '">' +
                    esc(c.stage) + '</span>' : '');

            return '<div class="slk-tm-tile ' + tone + '" title="' + esc(title) + '"' +
                ' style="left:' + p.x + 'px;top:' + p.y + 'px;width:' + Math.max(0, p.w - 4) +
                'px;height:' + Math.max(0, p.h - 4) + 'px;">' + label + '</div>';
        }).join('');
    }

    $(document).on('click', '.slk-view-btn', function () {
        var wrap = document.querySelector('.slk-treemap');
        if (!wrap) { return; }
        $('.slk-view-btn').removeClass('is-on');
        $(this).addClass('is-on');
        wrap.setAttribute('data-view', $(this).data('view'));
        renderTreemap();
    });

    var tmTimer = null;
    $(window).on('resize', function () {
        clearTimeout(tmTimer);
        tmTimer = setTimeout(renderTreemap, 150);
    });

    // Runs on first load and again after every SPA swap, since swap() sets
    // innerHTML and inline scripts would never fire.
    $(renderTreemap);
    $(document).on('slk:loaded', renderTreemap);

    /* ---------------------------------------------------------------------
     * Searchable post picker.
     *
     * A native <select> is unusable past a few dozen posts — you cannot scan
     * a hundred titles, and typing only jumps to first-letter matches. This
     * upgrades any select.slk-search-select into a type-to-filter combobox.
     *
     * The original <select> stays in the DOM, hidden, and is kept in sync:
     * every existing caller reads it with .val() and forms still submit it,
     * so nothing else had to change.
     * ------------------------------------------------------------------- */

    function enhanceSelect(sel) {
        if (sel.dataset.slkCombo) { return; }
        sel.dataset.slkCombo = '1';

        var options = [].slice.call(sel.options).map(function (o) {
            return { value: o.value, label: o.textContent.trim() };
        });
        var placeholder = (options.length && options[0].value === '') ? options[0].label : __('Search…', 'smartlinker');
        var choices = options.filter(function (o) { return o.value !== ''; });

        var wrap = document.createElement('div');
        wrap.className = 'slk-combo';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.classList.add('slk-combo-native');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'slk-combo-input';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.placeholder = placeholder;
        if (sel.value) {
            var cur = choices.filter(function (o) { return o.value === sel.value; })[0];
            if (cur) { input.value = cur.label; }
        }
        wrap.appendChild(input);

        var list = document.createElement('ul');
        list.className = 'slk-combo-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        wrap.appendChild(list);

        var active = -1;
        var shown = [];

        function paint(filter) {
            var q = (filter || '').toLowerCase().trim();
            shown = q === '' ? choices.slice(0, 200) : choices.filter(function (o) {
                return o.label.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 200);

            if (!shown.length) {
                list.innerHTML = '<li class="slk-combo-empty">' + /* translators: %s: what was typed into the search box */
                    esc(sprintf(__('Nothing matches “%s”', 'smartlinker'), filter)) + '</li>';
            } else {
                list.innerHTML = shown.map(function (o, i) {
                    return '<li role="option" class="slk-combo-opt' + (i === active ? ' is-active' : '') +
                        '" data-value="' + esc(o.value) + '">' + esc(o.label) + '</li>';
                }).join('');
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            list.hidden = true;
            active = -1;
            input.setAttribute('aria-expanded', 'false');
        }

        function choose(i) {
            var o = shown[i];
            if (!o) { return; }
            sel.value = o.value;
            input.value = o.label;
            // Native event, so anything listening for change still fires.
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        }

        // Re-opening with a post already chosen must start a NEW search, not
        // resume editing the old label. Selecting the text means the first
        // keystroke replaces it; without this, typing appends to the previous
        // title and nothing ever matches.
        function reopen() {
            input.select();
            active = -1;
            paint('');
        }
        input.addEventListener('focus', reopen);
        input.addEventListener('click', function () {
            if (list.hidden) { reopen(); }
        });
        input.addEventListener('input', function () { active = -1; paint(input.value); });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) { paint(input.value); }
                active += (e.key === 'ArrowDown' ? 1 : -1);
                if (active < 0) { active = shown.length - 1; }
                if (active >= shown.length) { active = 0; }
                paint(input.value);
                var el = list.children[active];
                if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'Enter') {
                if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        list.addEventListener('mousedown', function (e) {
            // mousedown, not click: blur would close the list first.
            var li = e.target.closest('.slk-combo-opt');
            if (!li) { return; }
            e.preventDefault();
            choose(shown.map(function (o) { return o.value; }).indexOf(li.dataset.value));
        });

        // Leaving mid-search must not strand a half-typed query in the field:
        // put back the label of whatever is actually selected.
        input.addEventListener('blur', function () {
            setTimeout(function () {
                close();
                var cur = choices.filter(function (o) { return o.value === sel.value; })[0];
                input.value = cur ? cur.label : '';
            }, 120);
        });

        // Explicit way out, for when you want no selection at all.
        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'slk-combo-clear';
        clear.setAttribute('aria-label', __('Clear selection', 'smartlinker'));
        clear.innerHTML = '&times;';
        clear.addEventListener('mousedown', function (e) {
            e.preventDefault();
            sel.value = '';
            input.value = '';
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
            paint('');
        });
        wrap.appendChild(clear);
    }

    function enhanceAllSelects() {
        [].slice.call(document.querySelectorAll('select.slk-search-select')).forEach(enhanceSelect);
    }
    $(enhanceAllSelects);
    $(document).on('slk:loaded', enhanceAllSelects);

    /* ---------------------------------------------------------------------
     * Broken links: repair in place.
     *
     * Three repairs, because a broken link has three honest outcomes: it
     * should point somewhere else, the words should stay but stop being a
     * link, or the anchor text itself is wrong.
     * ------------------------------------------------------------------- */

    function renderFixPanel($panel, d, engine) {
        var sugg = (d.suggestions || []).map(function (s) {
            return '<li><button type="button" class="slk-fix-pick" data-url="' + esc(s.url) + '">' +
                esc(s.label) + '</button>' +
                '<span class="slk-fix-why">' + esc(s.why) + '</span>' +
                '<span class="slk-fix-url">' + esc(s.url) + '</span></li>';
        }).join('');

        $panel.html(
            engineSwitch(engine || 'standard', aiReady()) +
            '<div class="slk-fix-head">' +
                /* translators: 1: the link's anchor text, 2: the post it sits in */
                esc(sprintf(__('“%1$s” in %2$s', 'smartlinker'), d.anchor || __('(no text)', 'smartlinker'), d.post_title)) +
            '</div>' +
            (sugg
                ? '<div class="slk-fix-sub">' + esc(__('Suggested replacements', 'smartlinker')) + '</div>' +
                  '<ul class="slk-fix-list">' + sugg + '</ul>'
                : '<p class="description">' + esc(engine === 'ai'
                    ? __('The AI found no page on this site that fits — enter a URL below, or remove the link.', 'smartlinker')
                    : __('No confident replacement found — enter one below, or remove the link.', 'smartlinker')) + '</p>') +
            '<div class="slk-fix-actions">' +
                '<label class="slk-fix-field">' + esc(__('Point it at', 'smartlinker')) +
                    '<input type="url" class="slk-fix-url-input" placeholder="https://…" />' +
                '</label>' +
                '<button type="button" class="slk-btn-apply slk-fix-apply" data-op="replace">' + esc(__('Replace URL', 'smartlinker')) + '</button>' +
            '</div>' +
            '<div class="slk-fix-actions">' +
                '<label class="slk-fix-field">' + esc(__('Anchor text', 'smartlinker')) +
                    '<input type="text" class="slk-fix-anchor-input" value="' + esc(d.anchor || '') + '" />' +
                '</label>' +
                '<button type="button" class="slk-btn-reject slk-fix-apply" data-op="anchor">' + esc(__('Update text', 'smartlinker')) + '</button>' +
                '<button type="button" class="slk-btn-reject slk-fix-apply" data-op="remove">' + esc(__('Remove link, keep text', 'smartlinker')) + '</button>' +
                '<a class="slk-fix-edit" href="' + esc(d.edit || '#') + '">' + esc(__('Open in editor', 'smartlinker')) + '</a>' +
                '<span class="slk-fix-msg" role="status"></span>' +
            '</div>'
        );
    }

    $(document).on('click', '.slk-fix-link', function () {
        var $btn = $(this);
        var id = $btn.data('link');
        var $row = $('.slk-fix-row[data-for="' + id + '"]');

        if ($row.attr('hidden') === undefined) {
            $row.attr('hidden', true);
            $btn.attr('aria-expanded', 'false').text(__('Fix', 'smartlinker'));
            return;
        }
        $row.removeAttr('hidden');
        $btn.attr('aria-expanded', 'true').text(__('Close', 'smartlinker'));

        var $panel = $row.find('.slk-fixlink-panel');
        if ($panel.data('loaded')) { return; }
        $panel.data('loaded', true);
        loadFixOptions($panel, 'standard');
    });

    function loadFixOptions($panel, engine) {
        engine = engine || 'standard';
        $panel.data('engine', engine).html(skeleton(1));
        $.post(SLK.ajaxUrl, {
            action: 'slk_fix_options', nonce: SLK.nonce,
            engine: engine, link_id: $panel.data('link')
        })
            .done(function (res) {
                if (res && res.success) { renderFixPanel($panel, res.data, engine); }
                else { $panel.html(engineSwitch(engine, aiReady()) + '<p class="description">' +
                    esc((res && res.data && res.data.message) || SLK.i18n.error) + '</p>'); }
            })
            .fail(function () { $panel.html('<p class="description">' + esc(SLK.i18n.error) + '</p>'); });
    }

    // Clicking a suggestion just fills the box — you still confirm.
    $(document).on('click', '.slk-fix-pick', function () {
        $(this).closest('.slk-fixlink-panel').find('.slk-fix-url-input').val($(this).data('url')).focus();
    });

    $(document).on('click', '.slk-fix-apply', function () {
        var $btn = $(this);
        var $panel = $btn.closest('.slk-fixlink-panel');
        var op = $btn.data('op');
        var $msg = $panel.find('.slk-fix-msg');
        var value = op === 'replace' ? $panel.find('.slk-fix-url-input').val()
                  : (op === 'anchor' ? $panel.find('.slk-fix-anchor-input').val() : '');

        if (op === 'remove' && !window.confirm(__('Remove this link? The words stay, the link goes.', 'smartlinker'))) { return; }

        $panel.find('.slk-fix-apply').prop('disabled', true);
        $msg.text(__('Working…', 'smartlinker'));

        $.post(SLK.ajaxUrl, {
            action: 'slk_apply_fix', nonce: SLK.nonce,
            link_id: $panel.data('link'), op: op, value: value
        }).done(function (res) {
            if (res && res.success) {
                $msg.text(res.data.message);
                var $tr = $('.slk-fix-row[data-for="' + $panel.data('link') + '"]').prev('tr');
                $tr.addClass('slk-fixed');
                $tr.find('.slk-fix-link').prop('disabled', true).text(__('Fixed', 'smartlinker'));
                toast(res.data.message, 'success');
            } else {
                $panel.find('.slk-fix-apply').prop('disabled', false);
                $msg.text((res && res.data && res.data.message) || SLK.i18n.error);
            }
        }).fail(function () {
            $panel.find('.slk-fix-apply').prop('disabled', false);
            $msg.text(SLK.i18n.error);
        });
    });

    /* ---------------------------------------------------------------------
     * Orphans: fix in place.
     *
     * The suggestions here are INBOUND — other posts that should link to this
     * orphan — so applying one edits the SOURCE post, not the one you are
     * looking at. That goes through the server-side insert, because there is
     * no editor open to write into.
     * ------------------------------------------------------------------- */

    /** Standard / AI switch shown at the top of a fix panel. */
    function engineSwitch(engine, aiReady) {
        return '<div class="slk-engine">' +
            '<button type="button" class="slk-engine-btn' + (engine !== 'ai' ? ' is-on' : '') +
                '" data-engine="standard">' + esc(__('Standard', 'smartlinker')) + '</button>' +
            '<button type="button" class="slk-engine-btn' + (engine === 'ai' ? ' is-on' : '') +
                (aiReady ? '' : ' is-locked') + '" data-engine="ai"' + (aiReady ? '' : ' disabled') + '>' +
                esc(__('AI', 'smartlinker')) + '</button>' +
            (aiReady ? '' : '<span class="slk-engine-note">' + esc(__('Add an API key to use AI', 'smartlinker')) + '</span>') +
            '</div>';
    }

    function aiReady() {
        return !!(SLK.ai && (SLK.ai.enabled === true || SLK.ai.enabled === 1 || SLK.ai.enabled === '1'));
    }

    function loadOrphanFixes($panel, engine) {
        var targetId = $panel.data('target');
        engine = engine || 'standard';
        $panel.data('engine', engine).html(skeleton(2));

        $.post(SLK.ajaxUrl, {
            action: 'slk_get_inbound',
            nonce: SLK.nonce,
            engine: engine,
            target_id: targetId
        }).done(function (res) {
            if (!res || !res.success) {
                $panel.html('<p class="description">' +
                    esc((res && res.data && res.data.message) || SLK.i18n.error) + '</p>');
                return;
            }
            var items = res.data.suggestions || [];
            $panel.empty().append(engineSwitch(engine, aiReady()));
            if (!items.length) {
                $panel.append('<p class="description">' +
                    esc(engine === 'ai'
                        ? __('The AI found nothing on this site worth linking here.', 'smartlinker')
                        : __('Nothing on the site mentions this page yet. Add a sentence to a related post that names it, then try again.', 'smartlinker')) +
                    '</p>');
                return;
            }
            $panel.append('<div class="slk-fix-head">' +
                /* translators: %s: the post other pages could link to */
                esc(sprintf(__('Posts that could link to “%s”', 'smartlinker'), $panel.data('title'))) + '</div>');
            var $wrap = $('<div class="slk-suggestions"></div>').appendTo($panel);
            renderSuggestions($wrap, items, { sourceId: null });
        }).fail(function () {
            $panel.html('<p class="description">' + esc(SLK.i18n.error) + '</p>');
        });
    }

    $(document).on('click', '.slk-engine-btn', function () {
        var $btn = $(this);
        if ($btn.hasClass('is-on') || $btn.prop('disabled')) { return; }
        var engine = $btn.data('engine');
        var $orphan = $btn.closest('.slk-fix-panel');
        if ($orphan.length) { loadOrphanFixes($orphan, engine); return; }
        var $fix = $btn.closest('.slk-fixlink-panel');
        if ($fix.length) { loadFixOptions($fix, engine); }
    });

    $(document).on('click', '.slk-fix-orphan', function () {
        var $btn = $(this);
        var id = $btn.data('target');
        var $row = $('.slk-fix-row[data-for="' + id + '"]');
        var open = $row.attr('hidden') === undefined;

        if (open) {
            $row.attr('hidden', true);
            $btn.attr('aria-expanded', 'false').text(__('Fix', 'smartlinker'));
            return;
        }
        $row.removeAttr('hidden');
        $btn.attr('aria-expanded', 'true').text(__('Close', 'smartlinker'));

        var $panel = $row.find('.slk-fix-panel');
        if (!$panel.data('loaded')) {
            $panel.data('loaded', true);
            loadOrphanFixes($panel);
        }
    });

    /* ---------------------------------------------------------------------
     * Bridge for the block-editor sidebar (js/editor-sidebar.js).
     *
     * The anchor format and the boundary-safe replacement are subtle — a
     * naive str-replace corrupts URLs, and anchors must never be nested.
     * The sidebar therefore calls into these rather than reimplementing them,
     * so both surfaces stay identical by construction.
     * ------------------------------------------------------------------- */
    window.SlkBridge = {
        isBlockEditor: function () { return !!blockEditor(); },
        strengthOf: strengthOf,

        /**
         * Insert phrase -> url into the editor's own content.
         * @return {string|null} null on success, else a human-readable error.
         */
        insert: function (phrase, url) {
            var data = blockEditor();
            if (!data) { return SLK.i18n.error; }
            try {
                var content = data.select('core/editor').getEditedPostContent();
                var updated = replaceFirstOutsideTags(content, phrase, buildAnchor(phrase, url));
                if (!updated) {
                    return __('That exact text isn’t in the post — adjust the anchor and try again.', 'smartlinker');
                }
                data.dispatch('core/editor').resetEditorBlocks(wp.blocks.parse(updated));
                return null;
            } catch (e) {
                return SLK.i18n.error;
            }
        }
    };
})(jQuery);
