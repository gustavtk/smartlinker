<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Broken-link detection. Scans indexed links and flags ones that don't
 * resolve (internal 404s, external 4xx/5xx or unreachable hosts).
 */
class Slk_Error
{
    /** How many links to check per scan run (keeps requests bounded). */
    const BATCH = 150;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_scan']);
    }

    /**
     * Kick off a scan when the button is used.
     */
    public static function handle_scan()
    {
        // Reachable both as its own slug and as a Reports tab, so the slug
        // alone is no longer a reliable test.
        if (!Slk_Reports::on_tab('broken')) {
            return;
        }
        if (empty($_GET['slk_scan']) || !check_admin_referer('slk_broken_scan')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
        $result = self::scan(self::BATCH, $offset);

        // If a full batch was processed, there may be more — continue.
        if ($result['checked'] >= self::BATCH) {
            $next = wp_nonce_url(
                Slk_Reports::url('broken', ['slk_scan' => 1, 'offset' => $offset + self::BATCH]),
                'slk_broken_scan'
            );
            wp_safe_redirect($next);
            exit;
        }

        wp_safe_redirect(Slk_Reports::url('broken', ['scanned' => 1]));
        exit;
    }

    /**
     * Check a batch of links and update their broken flag.
     *
     * @return array ['checked' => int, 'broken' => int]
     */
    public static function scan($limit = self::BATCH, $offset = 0)
    {
        global $wpdb;
        $table = Slk_Query::links_table();

        // phpcs:ignore WordPress.DB.PreparedSQL
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, type, target_post_id FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ));

        $checked = 0;
        $broken = 0;
        $cache = [];

        foreach ($links as $link) {
            $checked++;
            $key = md5($link->url);
            if (isset($cache[$key])) {
                $result = $cache[$key];
            } else {
                $result = self::check($link->url, $link->type, (int) $link->target_post_id);
                $cache[$key] = $result;
            }

            $wpdb->update($table, [
                'broken'      => $result['broken'] ? 1 : 0,
                'status_code' => (int) $result['code'],
                'broken_type' => (string) $result['type'],
            ], ['id' => $link->id], ['%d', '%d', '%s'], ['%d']);

            if ($result['broken']) {
                $broken++;
            }
        }

        update_option('slk_broken_last_scan', current_time('mysql'), false);
        return ['checked' => $checked, 'broken' => $broken];
    }

    /**
     * Check a single URL.
     *
     * @return array ['broken' => bool, 'code' => int, 'type' => string]
     *               type is one of: '', 'empty', 'missing', '404', '4xx',
     *               '5xx', 'redirect', 'timeout'
     */
    public static function check($url, $type, $target_post_id)
    {
        $url = trim((string) $url);
        if ($url === '' || $url === '#') {
            return ['broken' => true, 'code' => 0, 'type' => 'empty'];
        }
        // Ignore non-HTTP schemes (mailto:, tel:, javascript:, anchors).
        if (preg_match('#^(mailto:|tel:|javascript:|\#)#i', $url)) {
            return ['broken' => false, 'code' => 0, 'type' => ''];
        }

        if ($type === 'internal') {
            // Resolve to a post id. Note url_to_postid() happily returns the
            // numeric id from ?p=N even when that post does not exist, so we
            // must verify the resolved post's status rather than trust it.
            $resolved = $target_post_id > 0 ? $target_post_id : (int) url_to_postid($url);
            if ($resolved > 0) {
                if (get_post_status($resolved) === 'publish') {
                    return ['broken' => false, 'code' => 200, 'type' => ''];
                }
                // Resolves to a missing/draft/trashed post => broken public link.
                return ['broken' => true, 'code' => 404, 'type' => 'missing'];
            }
            // Didn't resolve to a post (archive/term/home) — fall through to HTTP.
        }

        return self::http_check($url);
    }

    /**
     * HTTP check. HEAD first, GET fallback for servers that reject HEAD.
     * Records the status code and classifies the failure. Redirects are
     * reported (they cost crawl budget) but are not counted as broken.
     */
    protected static function http_check($url)
    {
        $args = [
            'timeout'     => 7,
            'redirection' => 0, // don't follow: we want to see the 3xx itself
            'sslverify'   => false,
            'user-agent'  => 'SmartLinker/' . SLK_VERSION . '; ' . home_url(),
        ];

        $response = wp_remote_head($url, $args);
        $timed_out = false;
        if (is_wp_error($response)) {
            $timed_out = (stripos($response->get_error_message(), 'timed out') !== false);
        }
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        // Some servers return 403/405 for HEAD — retry with GET before judging.
        if ($code === 0 || $code === 403 || $code === 405) {
            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                $timed_out = $timed_out || (stripos($response->get_error_message(), 'timed out') !== false);
                return ['broken' => true, 'code' => 0, 'type' => $timed_out ? 'timeout' : 'error'];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
        }

        if ($code >= 300 && $code < 400) {
            // A working link, but it costs a hop — surface it separately.
            return ['broken' => false, 'code' => $code, 'type' => 'redirect'];
        }
        if ($code === 404 || $code === 410) {
            return ['broken' => true, 'code' => $code, 'type' => '404'];
        }
        if ($code >= 500) {
            return ['broken' => true, 'code' => $code, 'type' => '5xx'];
        }
        if ($code >= 400) {
            return ['broken' => true, 'code' => $code, 'type' => '4xx'];
        }
        return ['broken' => false, 'code' => $code, 'type' => ''];
    }

    /**
     * Counts by issue type for the stat tiles.
     */
    public static function issue_counts()
    {
        global $wpdb;
        $table = Slk_Query::links_table();
        $out = ['404' => 0, 'redirect' => 0, 'timeout' => 0, 'other' => 0, 'total' => 0];
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results("SELECT broken_type, COUNT(*) AS n FROM {$table} WHERE broken_type <> '' GROUP BY broken_type");
        foreach ($rows as $r) {
            $n = (int) $r->n;
            $out['total'] += $n;
            if ($r->broken_type === '404' || $r->broken_type === 'missing') {
                $out['404'] += $n;
            } elseif ($r->broken_type === 'redirect') {
                $out['redirect'] += $n;
            } elseif ($r->broken_type === 'timeout' || $r->broken_type === 'error') {
                // Must match the 'timeout' filter in broken_rows() and the badge
                // grouping in templates/broken.php, or the tile count disagrees
                // with the number of rows clicking it produces.
                $out['timeout'] += $n;
            } else {
                $out['other'] += $n;
            }
        }
        return $out;
    }

    /**
     * Broken links joined to their source post, for display.
     */
    public static function broken_rows($limit = 500, $filter = 'all')
    {
        global $wpdb;
        $table = Slk_Query::links_table();

        // 'all' = every detected issue (broken + redirects).
        $where = "l.broken_type <> ''";
        if ($filter === '404') {
            $where = "l.broken_type IN ('404','missing')";
        } elseif ($filter === 'redirect') {
            $where = "l.broken_type = 'redirect'";
        } elseif ($filter === 'timeout') {
            $where = "l.broken_type IN ('timeout','error')";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.post_id, l.url, l.anchor, l.type, l.status_code, l.broken_type, p.post_title
             FROM {$table} l
             LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
             WHERE {$where}
             ORDER BY p.post_title ASC
             LIMIT %d",
            (int) $limit
        ));
    }

    /* ---------------------------------------------------------------------
     * Fixing a broken link in place
     * ------------------------------------------------------------------- */

    /**
     * Rewrite one anchor inside a post.
     *
     * Operates on whole <a> elements via a callback, never a string replace of
     * the URL: "?p=99" is a substring of "?p=999", and a naive replace
     * silently corrupts the second. The element is matched on its exact href
     * plus, where given, its anchor text.
     *
     * @param string $op replace | remove | anchor
     * @return array{changed:int,content:string}
     */
    protected static function rewrite_anchor($content, $url, $anchor_text, $op, $value)
    {
        $changed = 0;
        $target = rtrim(strtolower(trim($url)), '/');

        $out = preg_replace_callback(
            '#<a\s([^>]*)>(.*?)</a>#is',
            function ($m) use ($target, $anchor_text, $op, $value, &$changed) {
                if (!preg_match('/href=("|\')(.*?)\1/i', $m[1], $h)) {
                    return $m[0];
                }
                $href = html_entity_decode($h[2], ENT_QUOTES, 'UTF-8');
                if (rtrim(strtolower(trim($href)), '/') !== $target) {
                    return $m[0];
                }
                // When several links share a URL, the anchor text disambiguates.
                $text = trim(wp_strip_all_tags($m[2]));
                if ($anchor_text !== '' && mb_strtolower($text) !== mb_strtolower($anchor_text)) {
                    return $m[0];
                }

                $changed++;
                if ($op === 'remove') {
                    return $m[2]; // unwrap: the words stay, the link goes
                }
                if ($op === 'anchor') {
                    return '<a ' . $m[1] . '>' . esc_html($value) . '</a>';
                }
                // replace: swap the href, leave every other attribute alone
                $attrs = preg_replace(
                    '/href=("|\')(.*?)\1/i',
                    'href="' . esc_url($value) . '"',
                    $m[1],
                    1
                );
                return '<a ' . $attrs . '>' . $m[2] . '</a>';
            },
            $content
        );

        return ['changed' => $changed, 'content' => $out === null ? $content : $out];
    }

    /**
     * What to do about this broken link. Ordered by how confident we are:
     * a recorded rename beats a guess from the slug.
     *
     * @return array<int,array{url:string,label:string,why:string}>
     */
    public static function fix_suggestions($link)
    {
        $out = [];
        $seen = [];
        $add = function ($url, $label, $why) use (&$out, &$seen) {
            $key = rtrim(strtolower($url), '/');
            if ($url === '' || isset($seen[$key]) || count($out) >= 6) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['url' => $url, 'label' => $label, 'why' => $why];
        };

        // 1. This exact URL was renamed through the URL Changer.
        foreach (Slk_URLChanger::redirects() as $r) {
            if (rtrim(strtolower($r->old_url), '/') === rtrim(strtolower($link->url), '/')) {
                $add($r->new_url, get_the_title(url_to_postid($r->new_url)) ?: $r->new_url,
                    __('You renamed this URL — this is where it went.', 'smartlinker'));
            }
        }

        // 2. A redirect: the fix is to point at where it already lands.
        if ($link->broken_type === 'redirect') {
            $r = wp_remote_head($link->url, ['timeout' => 6, 'redirection' => 4, 'sslverify' => false]);
            if (!is_wp_error($r)) {
                $obj = isset($r['http_response']) && method_exists($r['http_response'], 'get_response_object')
                    ? $r['http_response']->get_response_object() : null;
                if ($obj && !empty($obj->url) && rtrim($obj->url, '/') !== rtrim($link->url, '/')) {
                    $add($obj->url, $obj->url, __('Where this redirect currently lands — links straight there instead.', 'smartlinker'));
                }
            }
        }

        // 3. A published post whose slug looks like the dead URL's.
        $slug = trim((string) wp_parse_url($link->url, PHP_URL_PATH), '/');
        $slug = $slug !== '' ? sanitize_title(basename($slug)) : '';
        if ($slug !== '') {
            $match = get_page_by_path($slug, OBJECT, Slk_Settings::enabled_post_types());
            if ($match && $match->post_status === 'publish') {
                $add(get_permalink($match->ID), $match->post_title,
                    __('A published post with the same slug.', 'smartlinker'));
            }
        }

        // 4. Published posts whose TITLE contains the anchor text.
        //
        // Deliberately a title match, not WordPress's `s` search: `s` looks
        // through body content, so every post that merely mentions the phrase
        // comes back — including the post we are fixing. A title match means
        // the page is actually *about* the anchor.
        $anchor = trim((string) $link->anchor);
        $weak = Slk_Suggestion::weak_words();
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($anchor), -1, PREG_SPLIT_NO_EMPTY);
        $meaningful = false;
        foreach ($words as $w) {
            if (!isset($weak[$w]) && mb_strlen($w) >= 3) {
                $meaningful = true;
                break;
            }
        }

        if ($anchor !== '' && $meaningful) {
            global $wpdb;
            $types = Slk_Settings::enabled_post_types();
            $ph = implode(',', array_fill(0, count($types), '%s'));
            $args = array_merge($types, [(int) $link->post_id, '%' . $wpdb->esc_like($anchor) . '%']);
            // phpcs:ignore WordPress.DB.PreparedSQL
            $hits = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ($ph)
                   AND ID <> %d AND post_title LIKE %s
                 LIMIT 4",
                $args
            ));
            foreach ($hits as $h) {
                $add(get_permalink($h->ID), $h->post_title,
                    __('Its title contains the anchor text.', 'smartlinker'));
            }
        }

        return $out;
    }

    public static function ajax_fix_options()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        global $wpdb;
        $table = Slk_Query::links_table();
        $id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$link) {
            wp_send_json_error(['message' => __('Link not found — re-run the scan.', 'smartlinker')]);
        }
        $engine = isset($_POST['engine']) ? sanitize_key($_POST['engine']) : 'standard';
        if ($engine === 'ai') {
            $picks = Slk_AI::replacements_for($link);
            if (is_wp_error($picks)) {
                wp_send_json_error(['message' => $picks->get_error_message()]);
            }
            $suggestions = $picks;
        } else {
            $suggestions = self::fix_suggestions($link);
        }

        wp_send_json_success([
            'anchor'      => $link->anchor,
            'url'         => $link->url,
            'engine'      => $engine,
            'post_title'  => get_the_title($link->post_id),
            'edit'        => get_edit_post_link($link->post_id, 'raw'),
            'suggestions' => $suggestions,
        ]);
    }

    public static function ajax_apply_fix()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
        $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        global $wpdb;
        $table = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$link) {
            wp_send_json_error(['message' => __('Link not found — re-run the scan.', 'smartlinker')]);
        }
        if (!current_user_can('edit_post', $link->post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }

        if ($op === 'replace') {
            $value = esc_url_raw(trim($value));
            if ($value === '') {
                wp_send_json_error(['message' => __('Enter a URL to link to instead.', 'smartlinker')]);
            }
        } elseif ($op === 'anchor') {
            $value = sanitize_text_field($value);
            if ($value === '') {
                wp_send_json_error(['message' => __('Enter the anchor text you want.', 'smartlinker')]);
            }
        } elseif ($op !== 'remove') {
            wp_send_json_error(['message' => __('Unknown action.', 'smartlinker')]);
        }

        $post = get_post($link->post_id);
        $result = self::rewrite_anchor($post->post_content, $link->url, (string) $link->anchor, $op, $value);
        if ($result['changed'] === 0) {
            wp_send_json_error(['message' => __('That link is no longer in the post — re-run the scan.', 'smartlinker')]);
        }

        wp_update_post(['ID' => $link->post_id, 'post_content' => $result['content']]);
        // Re-index so the report reflects reality immediately.
        Slk_Link::index_post($link->post_id);

        Slk_Activity::record(
            $link->post_id,
            $op,
            $op === 'remove'
                ? sprintf(__('Removed the link on “%s”', 'smartlinker'), $link->anchor)
                : ($op === 'anchor'
                    ? sprintf(__('Renamed anchor “%1$s” to “%2$s”', 'smartlinker'), $link->anchor, $value)
                    : sprintf(__('Repointed “%1$s” to %2$s', 'smartlinker'), $link->anchor, $value)),
            $post->post_content,
            $result['content'],
            $op === 'replace' ? $value : $link->url
        );

        $message = $op === 'remove'
            ? __('Link removed, text kept.', 'smartlinker')
            : ($op === 'anchor' ? __('Anchor text updated.', 'smartlinker') : __('Link repointed.', 'smartlinker'));

        // Repointing at a page the post already links to creates a second link
        // to the same destination — the cannibalisation the suggestion engine
        // refuses to produce. Done, but said out loud.
        if ($op === 'replace') {
            $others = Slk_Link::parse($result['content']);
            $count = 0;
            foreach ($others as $o) {
                if (rtrim(strtolower($o['url']), '/') === rtrim(strtolower($value), '/')) {
                    $count++;
                }
            }
            if ($count > 1) {
                $message .= ' ' . sprintf(
                    /* translators: %d: how many links now point at the same page */
                    __('Note: this post now links to that page %d times.', 'smartlinker'),
                    $count
                );
            }
        }

        wp_send_json_success([
            'op'      => $op,
            'changed' => $result['changed'],
            'message' => $message,
        ]);
    }

    public static function render_page()
    {
        $filter = isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all';
        $counts = self::issue_counts();
        $rows = self::broken_rows(500, $filter);
        $last_scan = get_option('slk_broken_last_scan', '');
        include SLK_PLUGIN_DIR . 'templates/broken.php';
    }
}
