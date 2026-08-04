<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anchor text report: what your links actually say, across the whole site.
 *
 * Every other report here asks whether a link exists. This one asks whether it
 * says anything useful. Three things go wrong with anchor text, and each is
 * invisible one post at a time:
 *
 *   Ambiguous — the same words pointing at different pages. A reader who
 *   learned that "burr grinder" means one article is now sent somewhere else
 *   by identical words, and a search engine has no idea which page the phrase
 *   belongs to.
 *
 *   Generic — "click here", "read more", "this page". They carry no meaning to
 *   a reader scanning the page and none to a search engine either. The link
 *   still works; it just does no work.
 *
 *   Repetitive — the identical anchor pointing at one page dozens of times.
 *   Natural writing varies; forty exact repeats is a pattern that reads as
 *   manipulation whether or not it was meant that way.
 *
 * Nothing here is scored or auto-fixed. These are judgement calls about
 * writing, so the report shows the evidence and links to the post.
 */
class Slk_Anchor
{
    /** Anchors used this many times or more on one target are called out. */
    const REPEAT_THRESHOLD = 10;

    /**
     * Anchors that describe nothing. Matched on the whole trimmed anchor, so
     * "read more about grinders" is fine — only a bare "read more" is not.
     */
    public static function generic_phrases()
    {
        return [
            'click here', 'click', 'here', 'this', 'this page', 'this post', 'this article',
            'read more', 'more', 'learn more', 'find out more', 'see more', 'view more',
            'read this', 'read it', 'link', 'this link', 'go', 'go here', 'check it out',
            'check this out', 'see here', 'view', 'details', 'more info', 'more information',
            'full article', 'full post', 'continue reading', 'read on', 'website', 'page',
            'article', 'post', 'download', 'visit', 'visit us', 'our site', 'homepage',
        ];
    }

    public static function is_generic($anchor)
    {
        $norm = self::normalize($anchor);
        if ($norm === '') {
            return false;
        }
        return in_array($norm, self::generic_phrases(), true);
    }

    /**
     * Lowercase, collapse whitespace, drop surrounding punctuation. Anchors
     * differing only in case or a trailing comma are the same anchor to a
     * reader, so they are the same anchor here.
     */
    public static function normalize($anchor)
    {
        $a = wp_strip_all_tags((string) $anchor);
        $a = html_entity_decode($a, ENT_QUOTES, 'UTF-8');
        $a = preg_replace('/\s+/u', ' ', $a);
        $a = trim($a);
        $a = trim($a, " \t\n\r\0\x0B.,;:!?\"'“”‘’()[]");
        return function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower($a);
    }

    /**
     * Every internal anchor on the site, grouped by its normalized text.
     *
     * @return array list of ['anchor','uses','targets'=>[…],'target_count','sources'=>[…],'generic','ambiguous','repetitive']
     */
    public static function rows()
    {
        global $wpdb;
        $table = Slk_Query::links_table();

        // Images and empty anchors are not anchor text; counting them would
        // put a phantom "" row at the top of every report.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $links = $wpdb->get_results(
            "SELECT anchor, url, post_id, target_post_id
             FROM {$table}
             WHERE type = 'internal' AND anchor IS NOT NULL AND TRIM(anchor) <> ''"
        );

        $groups = [];
        foreach ($links as $l) {
            $key = self::normalize($l->anchor);
            if ($key === '') {
                continue;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'anchor'   => trim(wp_strip_all_tags($l->anchor)),
                    'uses'     => 0,
                    'targets'  => [],
                    'sources'  => [],
                ];
            }

            $groups[$key]['uses']++;

            // Group destinations by post where we resolved one, and by URL
            // otherwise — an archive or a term page is still a destination.
            $tkey = $l->target_post_id ? 'p' . (int) $l->target_post_id : 'u' . md5($l->url);
            if (!isset($groups[$key]['targets'][$tkey])) {
                $groups[$key]['targets'][$tkey] = [
                    'post_id' => (int) $l->target_post_id,
                    'url'     => $l->url,
                    'title'   => $l->target_post_id ? get_the_title($l->target_post_id) : $l->url,
                    'count'   => 0,
                ];
            }
            $groups[$key]['targets'][$tkey]['count']++;
            $groups[$key]['sources'][(int) $l->post_id] = true;
        }

        $out = [];
        foreach ($groups as $g) {
            $targets = array_values($g['targets']);
            usort($targets, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            $top = $targets ? $targets[0]['count'] : 0;

            $out[] = [
                'anchor'       => $g['anchor'],
                'uses'         => $g['uses'],
                'targets'      => $targets,
                'target_count' => count($targets),
                'source_count' => count($g['sources']),
                'generic'      => self::is_generic($g['anchor']),
                'ambiguous'    => count($targets) > 1,
                'repetitive'   => $top >= self::REPEAT_THRESHOLD,
            ];
        }

        usort($out, function ($a, $b) {
            // Ambiguity first — it is the one that actively misleads a reader.
            if ($a['ambiguous'] !== $b['ambiguous']) {
                return $a['ambiguous'] ? -1 : 1;
            }
            if ($a['target_count'] !== $b['target_count']) {
                return $b['target_count'] <=> $a['target_count'];
            }
            return $b['uses'] <=> $a['uses'];
        });

        return $out;
    }

    /**
     * Posts whose inbound links all say the same thing.
     *
     * A page with twelve inbound links that every one of them calls "our guide"
     * has no vocabulary around it. Search engines learn what a page is about
     * partly from the words people use to link to it, so this is a real gap —
     * but only worth raising once a page has enough links to vary.
     */
    public static function single_anchor_targets($min_links = 4)
    {
        global $wpdb;
        $table = Slk_Query::links_table();

        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT target_post_id, COUNT(*) AS inbound
             FROM {$table}
             WHERE type = 'internal' AND target_post_id > 0
             GROUP BY target_post_id
             HAVING inbound >= %d",
            (int) $min_links
        ));

        $out = [];
        foreach ($rows as $r) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $anchors = $wpdb->get_col($wpdb->prepare(
                "SELECT anchor FROM {$table} WHERE type = 'internal' AND target_post_id = %d",
                (int) $r->target_post_id
            ));

            $distinct = [];
            foreach ($anchors as $a) {
                $n = self::normalize($a);
                if ($n !== '') {
                    $distinct[$n] = true;
                }
            }
            if (count($distinct) > 1) {
                continue;
            }

            $out[] = [
                'post_id' => (int) $r->target_post_id,
                'title'   => get_the_title($r->target_post_id),
                'url'     => get_permalink($r->target_post_id),
                'inbound' => (int) $r->inbound,
                'anchor'  => $distinct ? key($distinct) : '',
            ];
        }

        usort($out, function ($a, $b) {
            return $b['inbound'] <=> $a['inbound'];
        });

        return $out;
    }

    /**
     * Which posts use a given anchor, so a row can be expanded into evidence.
     */
    public static function usages($anchor)
    {
        global $wpdb;
        $table = Slk_Query::links_table();
        $want = self::normalize($anchor);

        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            "SELECT id, post_id, anchor, url, target_post_id
             FROM {$table} WHERE type = 'internal' AND TRIM(anchor) <> ''"
        );

        $out = [];
        foreach ($rows as $r) {
            if (self::normalize($r->anchor) !== $want) {
                continue;
            }
            $out[] = [
                'link_id'      => (int) $r->id,
                'post_id'      => (int) $r->post_id,
                'post_title'   => get_the_title($r->post_id),
                'edit_url'     => Slk_Admin::edit_url($r->post_id, ''),
                'anchor'       => $r->anchor,
                'url'          => $r->url,
                'target_id'    => (int) $r->target_post_id,
                'target_title' => $r->target_post_id ? get_the_title($r->target_post_id) : $r->url,
            ];
        }
        return $out;
    }

    public static function counts($rows)
    {
        $c = ['total' => 0, 'unique' => count($rows), 'generic' => 0, 'ambiguous' => 0, 'repetitive' => 0];
        foreach ($rows as $r) {
            $c['total'] += $r['uses'];
            if ($r['generic']) {
                $c['generic']++;
            }
            if ($r['ambiguous']) {
                $c['ambiguous']++;
            }
            if ($r['repetitive']) {
                $c['repetitive']++;
            }
        }
        return $c;
    }

    public function register()
    {
        add_action('wp_ajax_slk_anchor_usages', [__CLASS__, 'ajax_usages']);
    }

    public static function ajax_usages()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $anchor = isset($_POST['anchor']) ? sanitize_text_field(wp_unslash($_POST['anchor'])) : '';
        if ($anchor === '') {
            wp_send_json_error(['message' => __('No anchor given.', 'smartlinker')]);
        }
        wp_send_json_success(['rows' => self::usages($anchor)]);
    }

    public static function render_page()
    {
        $filter = isset($_GET['show']) ? sanitize_key($_GET['show']) : 'all';
        $all = self::rows();
        $counts = self::counts($all);

        $rows = $all;
        if ($filter === 'ambiguous') {
            $rows = array_values(array_filter($all, function ($r) {
                return $r['ambiguous'];
            }));
        } elseif ($filter === 'generic') {
            $rows = array_values(array_filter($all, function ($r) {
                return $r['generic'];
            }));
        } elseif ($filter === 'repetitive') {
            $rows = array_values(array_filter($all, function ($r) {
                return $r['repetitive'];
            }));
        }

        $single = $filter === 'single' ? self::single_anchor_targets() : [];
        $suppressed = $filter === 'suppressed' ? Slk_Rejection::suppressed() : [];
        $pending = $filter === 'suppressed' ? Slk_Rejection::pending() : [];
        include SLK_PLUGIN_DIR . 'templates/anchors.php';
    }
}
