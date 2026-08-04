<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pull focus keywords out of whichever SEO plugin the site already uses, so
 * Target Keywords doesn't have to be typed in by hand.
 *
 * Sources are detected by the presence of their DATA, not by whether the
 * plugin is active — someone migrating away from Yoast still has the meta
 * rows, and that is exactly when this is most useful.
 *
 * Only the primary focus keyword is imported. Yoast Premium, Rank Math and
 * AIOSEO all support additional keyphrases, but Target Keywords drives
 * link-building: importing five phrases per post buries the one that matters.
 */
class Slk_KeywordImport
{
    /** Rows shown in the preview before committing. */
    const PREVIEW_LIMIT = 200;

    public function register()
    {
        // A keyword edited in the SEO plugin should take effect on next scan.
        add_action('save_post', [__CLASS__, 'flush_live']);
    }

    /**
     * Focus keywords read straight out of the SEO plugin, with no import step.
     *
     * Most people maintain focus keywords in their SEO plugin and never think
     * about them again. Requiring an import would mean every new post had no
     * keyword here until someone remembered to re-run it — so the anchor
     * engine reads them live instead.
     *
     * Rank Math is checked first: it is the most common on sites that also
     * want internal linking, and its value is a deliberate, single choice.
     *
     * @return array<int,string> post id => keyword
     */
    public static function live_focus_keywords()
    {
        $cached = get_transient('slk_live_focus_keywords');
        if (is_array($cached)) {
            return $cached;
        }

        $map = [];
        // Later sources must not overwrite earlier ones, so the priority
        // order here is the priority order that applies.
        foreach (['rankmath', 'yoast', 'aioseo', 'seopress'] as $source) {
            foreach (self::read($source) as $row) {
                $id = (int) $row['post_id'];
                if (!isset($map[$id]) && $row['keyword'] !== '') {
                    $map[$id] = $row['keyword'];
                }
            }
        }

        set_transient('slk_live_focus_keywords', $map, 10 * MINUTE_IN_SECONDS);
        return $map;
    }

    public static function flush_live()
    {
        delete_transient('slk_live_focus_keywords');
    }

    /**
     * Every supported source with how much it has to offer.
     *
     * @return array<int,array{id:string,label:string,total:int,new:int}>
     */
    public static function sources()
    {
        $existing = self::existing_pairs();
        $out = [];

        foreach (['yoast', 'rankmath', 'aioseo', 'seopress'] as $id) {
            $rows = self::read($id);
            if (empty($rows)) {
                continue;
            }
            $new = 0;
            foreach ($rows as $r) {
                if (!isset($existing[$r['post_id'] . '|' . mb_strtolower($r['keyword'])])) {
                    $new++;
                }
            }
            $out[] = [
                'id'    => $id,
                'label' => self::label($id),
                'total' => count($rows),
                'new'   => $new,
            ];
        }
        return $out;
    }

    public static function label($id)
    {
        $map = [
            'yoast'    => 'Yoast SEO',
            'rankmath' => 'Rank Math',
            'aioseo'   => 'All in One SEO',
            'seopress' => 'SEOPress',
        ];
        return isset($map[$id]) ? $map[$id] : $id;
    }

    /**
     * Candidate rows for the preview, flagged as new or already present.
     *
     * @return array<int,array{post_id:int,title:string,keyword:string,dupe:bool}>
     */
    public static function candidates($source, $limit = self::PREVIEW_LIMIT)
    {
        $existing = self::existing_pairs();
        $rows = self::read($source);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'post_id' => (int) $r['post_id'],
                'title'   => get_the_title($r['post_id']),
                'keyword' => $r['keyword'],
                'dupe'    => isset($existing[$r['post_id'] . '|' . mb_strtolower($r['keyword'])]),
            ];
        }
        // Show what will actually change first.
        usort($out, function ($a, $b) {
            if ($a['dupe'] === $b['dupe']) {
                return strcasecmp($a['title'], $b['title']);
            }
            return $a['dupe'] ? 1 : -1;
        });
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /**
     * Insert every keyword from $source that isn't already stored.
     *
     * @return array{imported:int,skipped:int}
     */
    public static function import($source)
    {
        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        $existing = self::existing_pairs();
        $imported = 0;
        $skipped = 0;

        foreach (self::read($source) as $r) {
            $key = $r['post_id'] . '|' . mb_strtolower($r['keyword']);
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $wpdb->insert($table, [
                'post_id' => (int) $r['post_id'],
                'keyword' => $r['keyword'],
                'created' => current_time('mysql'),
            ], ['%d', '%s', '%s']);
            // Guard against the same pair appearing twice in one source.
            $existing[$key] = true;
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /* ---------------------------------------------------------------------
     * Readers
     * ------------------------------------------------------------------- */

    /** post_id|lowercased keyword => true, for deduping. */
    protected static function existing_pairs()
    {
        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results("SELECT post_id, keyword FROM {$table}");
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->post_id . '|' . mb_strtolower($r->keyword)] = true;
        }
        return $out;
    }

    /**
     * @return array<int,array{post_id:int,keyword:string}>
     */
    protected static function read($source)
    {
        switch ($source) {
            case 'yoast':
                return self::from_meta('_yoast_wpseo_focuskw');
            case 'rankmath':
                // Comma-separated; the first entry is the primary keyword.
                return self::from_meta('rank_math_focus_keyword', true);
            case 'seopress':
                return self::from_meta('_seopress_analysis_target_kw', true);
            case 'aioseo':
                return self::from_aioseo();
        }
        return [];
    }

    /**
     * Read a plain post-meta key.
     *
     * @param bool $first_of_list treat the value as a comma-separated list and
     *                            keep only the first (primary) entry.
     */
    protected static function from_meta($meta_key, $first_of_list = false)
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));

        $args = array_merge([$meta_key], $types);
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value <> ''
               AND p.post_status = 'publish' AND p.post_type IN ($ph)",
            $args
        ));

        $out = [];
        foreach ($rows as $r) {
            $value = (string) $r->meta_value;
            if ($first_of_list) {
                $parts = explode(',', $value);
                $value = isset($parts[0]) ? $parts[0] : '';
            }
            $keyword = sanitize_text_field(trim($value));
            if ($keyword !== '') {
                $out[] = ['post_id' => (int) $r->post_id, 'keyword' => $keyword];
            }
        }
        return $out;
    }

    /**
     * AIOSEO v4 keeps keyphrases in its own table as JSON, not in post meta:
     * {"focus":{"keyphrase":"..."},"additional":[...]}. v3 used the
     * _aioseo_keywords meta key, so fall back to that.
     */
    protected static function from_aioseo()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';

        // phpcs:ignore WordPress.DB.PreparedSQL
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return self::from_meta('_aioseo_keywords', true);
        }

        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.post_id, a.keyphrases
             FROM {$table} a
             INNER JOIN {$wpdb->posts} p ON p.ID = a.post_id
             WHERE a.keyphrases IS NOT NULL AND a.keyphrases <> ''
               AND p.post_status = 'publish' AND p.post_type IN ($ph)",
            $types
        ));

        $out = [];
        foreach ($rows as $r) {
            $data = json_decode((string) $r->keyphrases, true);
            $keyword = '';
            if (is_array($data) && isset($data['focus']['keyphrase'])) {
                $keyword = (string) $data['focus']['keyphrase'];
            }
            $keyword = sanitize_text_field(trim($keyword));
            if ($keyword !== '') {
                $out[] = ['post_id' => (int) $r->post_id, 'keyword' => $keyword];
            }
        }
        return $out;
    }
}
