<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One batch runner behind every long scan, so they can all report progress.
 *
 * The scans were already batched, but each batch was a page load that
 * redirected to the next — so a long scan looked like the browser flickering
 * with no way to tell whether it had started, stalled, or was nearly done.
 * Here each batch is one AJAX call that returns where it got to, and the
 * browser draws a bar from that.
 *
 * The link re-index was not batched at all: it indexed every published post in
 * a single request, which on a large site is a timeout with no partial result.
 * It runs in slices here like the others.
 *
 * The old redirect URLs still work. They are what a browser with no JavaScript
 * falls back to, and they are linked from the dashboard and the setup
 * checklist — a progress bar is not worth breaking a scan someone can already
 * reach.
 */
class Slk_Scan
{
    /**
     * job => [label, batch size, how to count, how to run one slice]
     *
     * Each runner returns how many items it has processed IN TOTAL so far, so
     * the browser only has to pass back the number it was last given.
     */
    public static function jobs()
    {
        return [
            'links' => [
                'label' => __('Indexing links', 'smartlinker'),
                'batch' => 40,
                'total' => [__CLASS__, 'count_posts'],
                'run'   => [__CLASS__, 'run_links'],
            ],
            'broken' => [
                'label' => __('Checking links', 'smartlinker'),
                // Each item is an HTTP request, so the slice is smaller than
                // the others however fast the database is.
                'batch' => 25,
                'total' => [__CLASS__, 'count_links'],
                'run'   => [__CLASS__, 'run_broken'],
            ],
            'opportunities' => [
                'label' => __('Finding opportunities', 'smartlinker'),
                'batch' => Slk_Opportunity::BATCH,
                'total' => [__CLASS__, 'count_targets'],
                'run'   => [__CLASS__, 'run_opportunities'],
            ],
            'cannibal' => [
                'label' => __('Comparing posts', 'smartlinker'),
                'batch' => Slk_Cannibal::BATCH,
                'total' => [__CLASS__, 'count_vectors'],
                'run'   => [__CLASS__, 'run_cannibal'],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * How many items each job has to get through
     * ------------------------------------------------------------------ */

    public static function count_posts()
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)",
            $types
        ));
    }

    public static function count_links()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Slk_Query::links_table());
    }

    public static function count_targets()
    {
        return count(Slk_Post::candidate_targets(0, 5000));
    }

    public static function count_vectors()
    {
        return Slk_Cannibal::semantic_available() ? count(Slk_Embedding::all()) : 0;
    }

    /* ---------------------------------------------------------------------
     * One slice of work
     * ------------------------------------------------------------------ */

    public static function run_links($offset, $batch)
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return ['scanned' => 0, 'found' => 0];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));
        // Ordered by ID so the slices tile the same set every time; without an
        // ORDER BY a later batch could repeat or skip rows.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($ph)
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            array_merge($types, [(int) $batch, (int) $offset])
        ));
        foreach ($ids as $id) {
            Slk_Link::index_post((int) $id);
        }
        return ['scanned' => $offset + count($ids), 'found' => self::count_links()];
    }

    public static function run_broken($offset, $batch)
    {
        $r = Slk_Error::scan($batch, $offset);
        return [
            'scanned' => $offset + (int) $r['checked'],
            'found'   => count(Slk_Error::broken_rows(500, 'all')),
        ];
    }

    public static function run_opportunities($offset, $batch)
    {
        $r = Slk_Opportunity::scan($offset, 'standard');
        return ['scanned' => (int) $r['scanned'], 'found' => count($r['rows'])];
    }

    public static function run_cannibal($offset, $batch)
    {
        $r = Slk_Cannibal::scan($offset);
        return ['scanned' => (int) $r['scanned'], 'found' => count($r['pairs'])];
    }

    /* ---------------------------------------------------------------------
     * The endpoint
     * ------------------------------------------------------------------ */

    public function register()
    {
        add_action('wp_ajax_slk_scan_batch', [__CLASS__, 'ajax_batch']);
    }

    public static function ajax_batch()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }

        $job = isset($_POST['job']) ? sanitize_key(wp_unslash($_POST['job'])) : '';
        $jobs = self::jobs();
        if (!isset($jobs[$job])) {
            wp_send_json_error(['message' => __('Unknown scan.', 'smartlinker')]);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $spec = $jobs[$job];
        $total = (int) call_user_func($spec['total']);

        if ($total === 0) {
            wp_send_json_success([
                'scanned' => 0, 'total' => 0, 'found' => 0,
                'done' => true, 'label' => $spec['label'],
            ]);
        }

        $r = call_user_func($spec['run'], $offset, $spec['batch']);
        $scanned = min($total, (int) $r['scanned']);

        // Guard against a runner that reports no progress: without this a
        // browser loop would call the same offset forever.
        $done = $scanned >= $total || $scanned <= $offset;

        // A finished scan is the moment the numbers are most accurate, and the
        // one time someone deliberately made them change. Recording here means
        // history reflects the work done rather than only whatever the daily
        // event happened to catch at midnight. Same-day captures overwrite.
        if ($done) {
            Slk_History::capture();
        }

        wp_send_json_success([
            'scanned' => $scanned,
            'total'   => $total,
            'found'   => (int) $r['found'],
            'done'    => $done,
            'label'   => $spec['label'],
        ]);
    }
}
