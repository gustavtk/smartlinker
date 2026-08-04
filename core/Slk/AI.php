<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI-powered internal link suggestions via the OpenAI Chat Completions API.
 * Reads content + a candidate list of the site's posts and asks the model
 * which anchor phrases (that appear verbatim in the content) to link, and to
 * which page. Results are validated before being returned.
 */
class Slk_AI
{
    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const MAX_CONTENT_CHARS = 6000;
    const MAX_CANDIDATES = 40;
    /** Excerpt of each candidate page sent alongside its title. */
    const CANDIDATE_GIST_CHARS = 180;
    const CACHE_META = '_slk_ai_cache';
    const ERROR_LOG_OPTION = 'slk_ai_errors';
    const MAX_ERRORS = 25;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    /**
     * Disconnect / clear-data actions on the AI settings tab.
     */
    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_settings') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $back = admin_url('admin.php?page=smartlinker_settings&tab=ai');

        if (!empty($_GET['slk_ai_disconnect']) && check_admin_referer('slk_ai_disconnect')) {
            $s = get_option(SLK_OPTION_SETTINGS, []);
            $s['openai_api_key'] = '';
            $s['use_ai'] = 0;
            update_option(SLK_OPTION_SETTINGS, $s);
            wp_safe_redirect($back . '&ai_msg=disconnected');
            exit;
        }

        if (!empty($_GET['slk_ai_clear_cache']) && check_admin_referer('slk_ai_clear_cache')) {
            $n = self::clear_cache();
            wp_safe_redirect($back . '&ai_msg=cache&n=' . (int) $n);
            exit;
        }

        if (!empty($_GET['slk_ai_clear_errors']) && check_admin_referer('slk_ai_clear_errors')) {
            self::clear_errors();
            wp_safe_redirect($back . '&ai_msg=errors');
            exit;
        }
    }

    /**
     * Is an API key configured?
     */
    public static function is_configured()
    {
        return trim((string) Slk_Settings::get('openai_api_key', '')) !== '';
    }

    /**
     * Whether AI scanning can run, and if not, which setting is missing.
     * Shared by the meta box and the block-editor sidebar so the two can
     * never disagree about why the button is locked.
     *
     * @return array ['enabled' => bool, 'reason' => string[]]
     */
    public static function availability()
    {
        $on = (int) Slk_Settings::get('use_ai', 0) === 1;

        if (!self::is_configured()) {
            return ['enabled' => false, 'reason' => [
                __('AI suggestions need an OpenAI API key before they can run.', 'smartlinker'),
                __('Add one under SmartLinker → Settings → AI. The key is stored in your own site’s database, and every scan is billed to your own OpenAI account.', 'smartlinker'),
            ]];
        }
        if (!$on) {
            return ['enabled' => false, 'reason' => [
                __('AI suggestions are switched off for this site.', 'smartlinker'),
                __('Your API key is already saved — turn the feature on under SmartLinker → Settings → AI.', 'smartlinker'),
            ]];
        }
        return ['enabled' => true, 'reason' => []];
    }

    protected static function api_key()
    {
        return trim((string) Slk_Settings::get('openai_api_key', ''));
    }

    public static function model()
    {
        $m = trim((string) Slk_Settings::get('openai_model', 'gpt-4o-mini'));
        return $m !== '' ? $m : 'gpt-4o-mini';
    }

    /* ---------------------------------------------------------------------
     * Cache, error log and processing stats
     * ------------------------------------------------------------------- */

    /**
     * Cache key changes when the post content or the model changes.
     * Display-time filters (min match, top N) are deliberately NOT part of the
     * key — they are applied to cached results, so tuning them never re-bills
     * an API call.
     */
    protected static function content_hash($post)
    {
        return md5($post->post_content . '|' . self::model());
    }

    protected static function get_cached($post)
    {
        if ((int) Slk_Settings::get('ai_cache', 1) !== 1) {
            return null;
        }
        $cache = get_post_meta($post->ID, self::CACHE_META, true);
        if (!is_array($cache) || empty($cache['hash']) || empty($cache['items'])) {
            return null;
        }
        return $cache['hash'] === self::content_hash($post) ? $cache['items'] : null;
    }

    protected static function set_cached($post, $items)
    {
        if ((int) Slk_Settings::get('ai_cache', 1) !== 1) {
            return;
        }
        update_post_meta($post->ID, self::CACHE_META, [
            'hash'  => self::content_hash($post),
            'items' => $items,
            'time'  => current_time('mysql'),
        ]);
    }

    /**
     * Delete every cached AI result. Returns the number of posts cleared.
     */
    public static function clear_cache()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::CACHE_META
        ));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->delete($wpdb->postmeta, ['meta_key' => self::CACHE_META], ['%s']);

        // A direct delete leaves the object cache holding the rows it just
        // removed, and get_post_meta() above would go on serving them.
        Slk_Post::flush_meta_cache();

        return $n;
    }

    /**
     * Log an API failure so it can be shown on the settings screen.
     */
    public static function log_error($message, $data = '')
    {
        $log = get_option(self::ERROR_LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            'time'    => current_time('mysql'),
            'message' => (string) $message,
            'data'    => (string) $data,
        ]);
        update_option(self::ERROR_LOG_OPTION, array_slice($log, 0, self::MAX_ERRORS), false);
    }

    public static function errors()
    {
        $log = get_option(self::ERROR_LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    public static function clear_errors()
    {
        delete_option(self::ERROR_LOG_OPTION);
    }

    /**
     * Processing status counts for the settings screen.
     */
    public static function processing_stats()
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        $total = 0;
        if (!empty($types)) {
            $ph = implode(',', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)",
                $types
            ));
        }
        // phpcs:ignore WordPress.DB.PreparedSQL
        $analysed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::CACHE_META
        ));

        return [
            'total'     => $total,
            'analysed'  => min($analysed, $total),
            'remaining' => max(0, $total - $analysed),
            'errors'    => count(self::errors()),
        ];
    }

    /**
     * Generate AI link suggestions for a post.
     *
     * @return array|WP_Error array of ['phrase','target_id','target_title','url','reason']
     */
    public static function suggest($post_id)
    {
        if (!self::is_configured()) {
            return new WP_Error('slk_ai_no_key', __('No OpenAI API key configured. Add one in SmartLinker → Settings.', 'smartlinker'));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('slk_ai_no_post', __('Post not found.', 'smartlinker'));
        }

        // Respect the "don't process posts older than" limit.
        $max_age = (int) Slk_Settings::get('ai_max_age', 0);
        if ($max_age > 0) {
            $age_days = (time() - get_post_time('U', true, $post)) / DAY_IN_SECONDS;
            if ($age_days > $max_age) {
                return [];
            }
        }

        // Serve a cached result when the content hasn't changed.
        $cached = self::get_cached($post);
        if ($cached !== null) {
            return self::apply_limits($cached);
        }

        $content = Slk_Post::plain_text($post->post_content);
        if (mb_strlen($content) < 30) {
            return [];
        }
        $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);

        // Candidate targets (title + id) and a lookup for validation.
        $targets = Slk_Post::candidate_targets($post_id, self::MAX_CANDIDATES);
        if (empty($targets)) {
            return [];
        }
        $by_id = [];
        $candidate_lines = [];
        foreach ($targets as $t) {
            $by_id[(int) $t->ID] = $t;
            // Send a short excerpt as well as the title. With titles alone the
            // model has to guess what each destination actually covers, which
            // is how vaguely-named pages end up linked from unrelated articles.
            $gist = trim(preg_replace('/\s+/u', ' ', Slk_Post::plain_text($t->post_content)));
            $gist = mb_substr($gist, 0, self::CANDIDATE_GIST_CHARS);
            $candidate_lines[] = $t->ID . ': ' . trim(wp_strip_all_tags($t->post_title))
                . ($gist !== '' ? ' — ' . $gist : '');
        }

        $already_linked = [];
        foreach (Slk_Link::parse($post->post_content) as $link) {
            $already_linked[mb_strtolower($link['anchor'], 'UTF-8')] = true;
        }
        // Destinations this post already points at.
        $linked = Slk_Post::existing_link_targets($post->post_content);

        $limit = (int) Slk_Settings::get('suggestion_limit', 20);
        // Validate anchors against text that is NOT already inside a link, so
        // a phrase that is already linked can't be suggested again.
        $linkable = Slk_Post::linkable_text($post->post_content);
        $content_lc = mb_strtolower($linkable, 'UTF-8');

        $response = self::request($content, $candidate_lines, $limit);
        if (is_wp_error($response)) {
            return $response;
        }

        // Validate the model's picks against the actual content + candidates.
        $out = [];
        $used_targets = [];
        foreach ($response as $item) {
            $phrase = isset($item['phrase']) ? trim((string) $item['phrase']) : '';
            $target_id = isset($item['target_id']) ? (int) $item['target_id'] : 0;
            if ($phrase === '' || !isset($by_id[$target_id])) {
                continue;
            }
            $phrase_lc = mb_strtolower($phrase, 'UTF-8');
            if (isset($already_linked[$phrase_lc])) {
                continue;
            }
            if (isset($linked['ids'][$target_id])) {
                continue; // the post already links to this page
            }
            // Rejections are learned across both engines. Turning something
            // down in Standard and being offered it again by AI would make the
            // feature useless.
            if (Slk_Rejection::blocked($phrase, $target_id)) {
                continue;
            }
            // One link per destination per article. The model happily returns
            // three anchors pointing at the same page; that is cannibalisation,
            // and it wastes the reader's only click.
            if (isset($used_targets[$target_id])) {
                continue;
            }
            // Anchor must appear verbatim (word-boundary) in the content.
            if (!preg_match('/\b' . preg_quote($phrase_lc, '/') . '\b/u', $content_lc)) {
                continue;
            }
            $match = isset($item['match']) ? (int) $item['match'] : 0;
            $match = max(0, min(100, $match));

            // "Prefer page title as anchor": if the target's own title appears
            // verbatim in the content, use that instead of the model's phrase —
            // it produces tighter, more natural anchors.
            if ((int) Slk_Settings::get('ai_prefer_title_anchor', 0) === 1) {
                $title = trim(wp_strip_all_tags($by_id[$target_id]->post_title));
                $title_lc = mb_strtolower($title, 'UTF-8');
                if ($title !== '' && preg_match('/\b' . preg_quote($title_lc, '/') . '\b/u', $content_lc)) {
                    $phrase = $title;
                }
            }

            $used_targets[$target_id] = true;
            $out[] = [
                'phrase'       => $phrase,
                'context'      => Slk_Post::sentence_containing($linkable, $phrase),
                'target_id'    => $target_id,
                'target_title' => trim(wp_strip_all_tags($by_id[$target_id]->post_title)),
                'url'          => get_permalink($target_id),
                'path'         => wp_make_link_relative(get_permalink($target_id)),
                'match'        => $match,
                // Same measured signals as the keyword engine, closing with
                // the model's own rationale for the anchor it chose.
                'reason'       => Slk_Suggestion::explain(
                    $post,
                    $target_id,
                    isset($item['reason']) && trim((string) $item['reason']) !== ''
                        ? rtrim(sanitize_text_field((string) $item['reason']), '.') . '.'
                        : __('Anchor chosen by AI.', 'smartlinker')
                ),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        // Best matches first.
        usort($out, function ($a, $b) {
            return $b['match'] <=> $a['match'];
        });

        self::set_cached($post, $out);
        return self::apply_limits($out);
    }

    /**
     * Apply the minimum-match threshold and the "only top N" cap.
     */
    protected static function apply_limits($items)
    {
        $min = (int) Slk_Settings::get('ai_min_match', 50);
        $out = array_values(array_filter($items, function ($i) use ($min) {
            return (int) ($i['match'] ?? 0) >= $min;
        }));

        if ((int) Slk_Settings::get('ai_only_top', 0) === 1) {
            $out = array_slice($out, 0, max(1, (int) Slk_Settings::get('ai_top_n', 5)));
        }
        return $out;
    }

    /**
     * Call the OpenAI API and return the decoded "suggestions" array.
     *
     * @return array|WP_Error
     */
    protected static function request($content, array $candidate_lines, $limit)
    {
        $system = 'You are an internal-linking assistant for a website. '
            . 'Given an article and a numbered list of candidate pages (id: title — excerpt), choose the best internal links to add. '
            . 'Rules: only use anchor phrases that appear VERBATIM in the article text; never invent or alter phrases; '
            . 'prefer concise, natural anchor text of 2 to 4 words (e.g. a noun phrase), not whole sentences; '
            . 'the anchor must DESCRIBE THE DESTINATION PAGE, so a reader knows where the link goes — '
            . 'never use a phrase that merely happens to appear nearby, and never use generic words '
            . '("equipment", "documents", "your", "more", "here") as anchors; '
            . 'the article and the destination must be about genuinely related subjects — '
            . 'if nothing is a good fit, return fewer links or none at all rather than filling the quota; '
            . 'suggest at most ONE link per destination page; '
            . 'pick the single most topically relevant candidate page for each phrase; '
            . 'each "reason" must be a brief explanation of why the link fits (not the word "short"); '
            . 'give each suggestion a "match" score from 0 to 100 for how topically relevant the target page is to the '
            . 'surrounding text (100 = perfect fit); '
            . 'do not suggest more than ' . (int) $limit . ' links. '
            . 'Respond ONLY as JSON: {"suggestions":[{"phrase":"exact anchor text","target_id":123,"match":92,"reason":"why it fits"}]}.';

        $user = "ARTICLE:\n" . $content . "\n\nCANDIDATE PAGES:\n" . implode("\n", $candidate_lines);

        $body = [
            'model'           => self::model(),
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => (int) Slk_Settings::get('ai_timeout', 45),
            'headers' => [
                'Authorization' => 'Bearer ' . self::api_key(),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            self::log_error($response->get_error_message(), 'transport');
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code !== 200) {
            /* translators: %d: HTTP status code returned by OpenAI */
            $msg = $data['error']['message'] ?? sprintf(__('OpenAI API error (HTTP %d).', 'smartlinker'), $code);
            self::log_error($msg, 'HTTP ' . $code);
            return new WP_Error('slk_ai_http', $msg);
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
            self::log_error(__('Could not parse the AI response.', 'smartlinker'), mb_substr((string) $text, 0, 200));
            return new WP_Error('slk_ai_parse', __('Could not parse the AI response.', 'smartlinker'));
        }
        return $parsed['suggestions'];
    }

    /**
     * Shared plumbing for the one-shot JSON calls below.
     *
     * @return array|WP_Error decoded JSON payload
     */
    protected static function json_call($system, $user, $timeout = 45)
    {
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => max($timeout, (int) Slk_Settings::get('ai_timeout', 45)),
            'headers' => [
                'Authorization' => 'Bearer ' . self::api_key(),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'           => self::model(),
                'temperature'     => 0,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            self::log_error($response->get_error_message(), 'transport');
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? sprintf(__('OpenAI API error (HTTP %d).', 'smartlinker'), $code);
            self::log_error($msg, 'HTTP ' . $code);
            return new WP_Error('slk_ai_http', $msg);
        }
        $parsed = json_decode($data['choices'][0]['message']['content'] ?? '', true);
        if (!is_array($parsed)) {
            return new WP_Error('slk_ai_parse', __('Could not read the AI response.', 'smartlinker'));
        }
        return $parsed;
    }

    /**
     * Which posts should link TO this one — the AI counterpart of
     * Slk_Suggestion::inbound_for_post(), used by the orphan fix panel.
     *
     * One call for the whole set. Every anchor the model returns is checked
     * against the source post's real text before it is shown; the model
     * chooses, the content decides.
     *
     * @return array|WP_Error rows in the shape the inbound cards expect
     */
    public static function inbound_for($target_id)
    {
        if (!self::is_configured()) {
            return new WP_Error('slk_ai_no_key', __('Add an OpenAI API key under Settings → AI first.', 'smartlinker'));
        }
        $target = get_post($target_id);
        if (!$target) {
            return new WP_Error('slk_ai_no_post', __('Post not found.', 'smartlinker'));
        }

        $sources = Slk_Post::candidate_targets($target_id, self::MAX_CANDIDATES);
        if (empty($sources)) {
            return [];
        }

        $by_id = [];
        $lines = [];
        foreach ($sources as $src) {
            $by_id[(int) $src->ID] = $src;
            $gist = trim(preg_replace('/\s+/u', ' ', Slk_Post::plain_text($src->post_content)));
            $lines[] = $src->ID . ': ' . trim(wp_strip_all_tags($src->post_title))
                . ' — ' . mb_substr($gist, 0, self::CANDIDATE_GIST_CHARS);
        }

        $limit = (int) Slk_Settings::get('suggestion_limit', 20);
        $system = 'You decide which existing articles on a site should link TO one particular page. '
            . 'You are given the TARGET page and a numbered list of candidate source articles (id: title — excerpt). '
            . 'Rules: choose only articles whose subject genuinely relates to the target; '
            . 'for each, give an anchor phrase of 2 to 5 words that appears VERBATIM in THAT article and describes the target; '
            . 'never invent or alter a phrase; at most one suggestion per article; '
            . 'give a "match" score 0-100 for how well the target fits that article; '
            . 'returning few or none is correct when nothing genuinely relates. '
            . 'Respond ONLY as JSON: {"links":[{"source_id":123,"phrase":"exact text from that article","match":80,"reason":"why"}]}.';

        $user = "TARGET PAGE: " . $target->post_title . "

"
            . mb_substr(Slk_Post::plain_text($target->post_content), 0, 2500) . "

"
            . "CANDIDATE ARTICLES:
" . implode("
", $lines);

        $parsed = self::json_call($system, $user);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $target_url = get_permalink($target_id);
        $out = [];
        $seen = [];
        foreach (($parsed['links'] ?? []) as $item) {
            $sid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
            $phrase = trim((string) ($item['phrase'] ?? ''));
            if (!$sid || $phrase === '' || !isset($by_id[$sid]) || isset($seen[$sid])) {
                continue;
            }
            $src = $by_id[$sid];

            // Already links there? Then there is nothing to suggest.
            $linked = Slk_Post::existing_link_targets($src->post_content);
            if (isset($linked['ids'][(int) $target_id])) {
                continue;
            }
            // The anchor must really be in that article, outside existing links.
            $plain = Slk_Post::linkable_text($src->post_content);
            if (!preg_match('/\b' . preg_quote(mb_strtolower($phrase), '/') . '\b/u', mb_strtolower($plain))) {
                continue;
            }

            $seen[$sid] = true;
            $match = max(0, min(100, (int) ($item['match'] ?? 0)));
            $out[] = [
                'phrase'       => Slk_Suggestion::original_case($plain, mb_strtolower($phrase)),
                'context'      => Slk_Post::sentence_containing($plain, $phrase),
                'source_id'    => $sid,
                'source_title' => trim(wp_strip_all_tags($src->post_title)),
                'source_edit'  => Slk_Admin::edit_url($sid, 'raw'),
                'target_title' => trim(wp_strip_all_tags($target->post_title)),
                'url'          => $target_url,
                'path'         => wp_make_link_relative($target_url),
                'match'        => $match,
                'score'        => round($match / 10, 2),
                'reason'       => Slk_Suggestion::explain(
                    $target,
                    $sid,
                    trim((string) ($item['reason'] ?? '')) !== ''
                        ? rtrim(sanitize_text_field((string) $item['reason']), '.') . '.'
                        : __('Anchor chosen by AI.', 'smartlinker')
                ),
            ];
        }

        usort($out, function ($a, $b) {
            return $b['match'] <=> $a['match'];
        });
        return $out;
    }

    /**
     * Best replacement page for a broken link, judged from the anchor text and
     * the dead URL. Only ever returns pages that exist on this site.
     *
     * @return array|WP_Error [['url','label','why'], ...]
     */
    public static function replacements_for($link)
    {
        if (!self::is_configured()) {
            return new WP_Error('slk_ai_no_key', __('Add an OpenAI API key under Settings → AI first.', 'smartlinker'));
        }

        $candidates = Slk_Post::candidate_targets((int) $link->post_id, 60);
        if (empty($candidates)) {
            return [];
        }
        $by_id = [];
        $lines = [];
        foreach ($candidates as $c) {
            $by_id[(int) $c->ID] = $c;
            $lines[] = $c->ID . ': ' . trim(wp_strip_all_tags($c->post_title));
        }

        $system = 'A link on a website is broken. Given its anchor text, its dead URL, and a numbered list of '
            . 'pages that exist on the site (id: title), choose which page the link was most likely meant to reach. '
            . 'Rules: only ever answer with ids from the list; suggest at most 3, best first; '
            . 'if nothing on the list is a genuine match, return an empty list rather than a weak guess. '
            . 'Respond ONLY as JSON: {"picks":[{"id":123,"why":"short reason"}]}.';

        $user = 'ANCHOR TEXT: ' . $link->anchor . "
"
            . 'DEAD URL: ' . $link->url . "
"
            . 'IN POST: ' . get_the_title($link->post_id) . "

"
            . "PAGES ON THIS SITE:
" . implode("
", $lines);

        $parsed = self::json_call($system, $user, 30);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $out = [];
        foreach (($parsed['picks'] ?? []) as $p) {
            $id = isset($p['id']) ? (int) $p['id'] : 0;
            if (!$id || !isset($by_id[$id])) {
                continue; // the model must pick from the list, not invent
            }
            $out[] = [
                'url'   => get_permalink($id),
                'label' => trim(wp_strip_all_tags($by_id[$id]->post_title)),
                'why'   => sanitize_text_field((string) ($p['why'] ?? __('Suggested by AI.', 'smartlinker'))),
            ];
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------- */

    /**
     * The standalone AI Suggestions page: pick any post and run the AI
     * without opening the editor.
     */
    public static function render_page()
    {
        $configured = self::is_configured();
        $enabled = (int) Slk_Settings::get('use_ai', 0) === 1;
        $model = self::model();
        $posts = get_posts([
            'post_type'   => Slk_Settings::enabled_post_types(),
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
        include SLK_PLUGIN_DIR . 'templates/ai.php';
    }

    public static function ajax_get_ai_suggestions()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $result = self::suggest($post_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['suggestions' => $result]);
    }
}
