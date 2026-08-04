<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parsing links out of content, inserting new links into content, and
 * indexing a post's links into the database.
 */
class Slk_Link
{
    /**
     * Insert an anchor for the first occurrence of $phrase in a post's
     * content that is not already inside an <a> tag or HTML tag.
     *
     * @return true|WP_Error
     */
    public static function insert_into_post($post_id, $phrase, $url, $atts = [])
    {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('slk_no_post', __('Post not found.', 'smartlinker'));
        }

        $content = $post->post_content;
        $anchor = self::build_anchor($phrase, $url, $atts);

        $replaced = self::replace_first_outside_tags($content, $phrase, $anchor);
        if ($replaced === null) {
            return new WP_Error('slk_no_match', __('Could not find the phrase in the post content.', 'smartlinker'));
        }

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $replaced,
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        self::index_post($post_id);
        self::log_insertion();
        return true;
    }

    /**
     * Record that SmartLinker inserted a link, so the dashboard can report
     * how many links were created over time. Stored as a capped list of
     * timestamps (cheap, no extra table).
     */
    public static function log_insertion($count = 1)
    {
        $log = get_option('slk_insert_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        $now = time();
        for ($i = 0; $i < max(1, (int) $count); $i++) {
            $log[] = $now;
        }
        // Keep ~6 months of history, capped for safety.
        $cutoff = $now - (180 * DAY_IN_SECONDS);
        $log = array_filter($log, function ($t) use ($cutoff) {
            return $t >= $cutoff;
        });
        if (count($log) > 5000) {
            $log = array_slice($log, -5000);
        }
        update_option('slk_insert_log', array_values($log), false);
    }

    /**
     * Number of links SmartLinker inserted within the last $days.
     */
    public static function insertions_since($days, $offset_days = 0)
    {
        $log = get_option('slk_insert_log', []);
        if (!is_array($log) || empty($log)) {
            return 0;
        }
        $now = time();
        $end = $now - ($offset_days * DAY_IN_SECONDS);
        $start = $end - ($days * DAY_IN_SECONDS);
        $n = 0;
        foreach ($log as $t) {
            if ($t > $start && $t <= $end) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Build a safe anchor tag.
     */
    public static function build_anchor($phrase, $url, $atts = [])
    {
        $rel = [];
        if (!empty($atts['nofollow'])) {
            $rel[] = 'nofollow';
        }
        $target = !empty($atts['new_tab']) ? ' target="_blank"' : '';
        if (!empty($atts['new_tab'])) {
            $rel[] = 'noopener';
        }
        $rel_attr = !empty($rel) ? ' rel="' . esc_attr(implode(' ', array_unique($rel))) . '"' : '';

        return '<a href="' . esc_url($url) . '"' . $target . $rel_attr . ' data-slk="1">'
            . esc_html($phrase) . '</a>';
    }

    /**
     * Replace the first case-insensitive occurrence of $phrase that sits in
     * plain text (not inside a tag and not already inside an anchor).
     * Returns the new content, or null if no safe match was found.
     */
    public static function replace_first_outside_tags($content, $phrase, $replacement)
    {
        // Split content into tags and text so we never touch markup.
        $parts = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inside_anchor = false;
        $pattern = '/\b' . preg_quote($phrase, '/') . '\b/iu';
        $done = false;

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            $is_tag = ($part[0] === '<');

            if ($is_tag) {
                if (preg_match('/^<a[\s>]/i', $part)) {
                    $inside_anchor = true;
                } elseif (preg_match('#^</a\s*>#i', $part)) {
                    $inside_anchor = false;
                }
                continue;
            }

            if ($done || $inside_anchor) {
                continue;
            }

            if (preg_match($pattern, $part)) {
                $parts[$i] = preg_replace($pattern, $replacement, $part, 1);
                $done = true;
            }
        }

        return $done ? implode('', $parts) : null;
    }

    /**
     * Parse all anchors from content.
     *
     * @return array of ['url' => ..., 'anchor' => ...]
     */
    public static function parse($content)
    {
        $links = [];
        if (!preg_match_all('/<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', (string) $content, $m, PREG_SET_ORDER)) {
            return $links;
        }
        foreach ($m as $match) {
            $links[] = [
                'url'    => html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'),
                'anchor' => trim(wp_strip_all_tags($match[3])),
            ];
        }
        return $links;
    }

    /**
     * Classify a URL as internal/external and resolve the target post id.
     *
     * @return array [type, target_post_id]
     */
    /**
     * Hosts compared the way a reader would: case folded, www. ignored.
     *
     * A site reached at example.com and www.example.com is one site. Comparing
     * the raw strings made every link written with the other form look
     * external, which set target_post_id to 0 — and a page with no resolved
     * inbound links is reported as an orphan. That is how a well-linked page
     * ends up on the orphan list.
     */
    protected static function normalise_host($host)
    {
        return preg_replace('/^www\./i', '', strtolower(trim((string) $host)));
    }

    /**
     * Put a URL back on the site's own scheme and host before resolving it.
     *
     * url_to_postid() matches against home_url() and gives up on anything that
     * does not look like it, so an uppercase host or the wrong scheme returns
     * 0 even for a URL that is plainly ours. Only scheme, host and port are
     * replaced — the path is kept exactly, or a WordPress install living in a
     * subdirectory would have its prefix doubled.
     */
    protected static function canonicalise($url)
    {
        $url = trim((string) $url);
        $parts = wp_parse_url($url);
        if (empty($parts) || empty($parts['host'])) {
            return $url;   // already relative; url_to_postid copes
        }

        $home = wp_parse_url(home_url());
        $scheme = !empty($home['scheme']) ? $home['scheme'] : 'http';
        $host = !empty($home['host']) ? $home['host'] : '';
        $port = !empty($home['port']) ? ':' . $home['port'] : '';

        return $scheme . '://' . $host . $port
            . (isset($parts['path']) ? $parts['path'] : '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function classify($url)
    {
        $host = self::normalise_host(wp_parse_url(home_url(), PHP_URL_HOST));
        $url_host = self::normalise_host(wp_parse_url(trim((string) $url), PHP_URL_HOST));

        // Relative or same-host = internal.
        $internal = ($url_host === '' || $url_host === $host);
        $target_id = 0;
        if ($internal) {
            $target_id = (int) url_to_postid(self::canonicalise($url));
        }
        return [$internal ? 'internal' : 'external', $target_id];
    }

    /**
     * Re-index a single post's links into the links table.
     */
    public static function index_post($post_id)
    {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $table = Slk_Query::links_table();
        $wpdb->delete($table, ['post_id' => $post_id], ['%d']);

        $links = self::parse($post->post_content);
        $now = current_time('mysql');
        foreach ($links as $link) {
            list($type, $target_id) = self::classify($link['url']);
            $host = (string) wp_parse_url($link['url'], PHP_URL_HOST);
            $wpdb->insert($table, [
                'post_id'        => $post_id,
                'post_type'      => $post->post_type,
                'url'            => $link['url'],
                'anchor'         => $link['anchor'],
                'type'           => $type,
                'target_post_id' => $target_id,
                'broken'         => 0,
                'host'           => strtolower(preg_replace('/^www\./i', '', $host)),
                'created'        => $now,
            ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        }
    }
}
