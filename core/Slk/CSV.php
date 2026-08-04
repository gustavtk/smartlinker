<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV import/export for every report that has rows worth taking away.
 * Exports stream a download; imports bulk-create auto-link rules.
 *
 * The point of an export is the work you cannot do in the admin screen:
 * sorting two hundred anchors by how many places they point, handing a
 * cannibalisation list to whoever will action it, or keeping a copy of the
 * numbers before a big re-write. So the exports carry MORE than the screen
 * does — the ids, urls and raw values the tables hide because they would make
 * a page unreadable.
 */
class Slk_CSV
{
    /** Safety cap on imported rows. */
    const MAX_IMPORT_ROWS = 5000;

    /**
     * Every dataset that can be exported: type => [capability, method].
     *
     * A registry rather than a switch, so adding a report cannot half-wire an
     * export that renders a button leading to a silent no-op.
     */
    public static function datasets()
    {
        return [
            'autolinks' => ['manage_categories', 'export_autolinks'],
            'report'    => ['edit_posts', 'export_report'],
            'broken'    => ['edit_posts', 'export_broken'],
            'anchors'   => ['edit_posts', 'export_anchors'],
            'equity'    => ['edit_posts', 'export_equity'],
            'cannibal'  => ['edit_posts', 'export_cannibal'],
            'placement' => ['edit_posts', 'export_placement'],
            'domains'   => ['edit_posts', 'export_domains'],
            'trends'    => ['edit_posts', 'export_trends'],
        ];
    }

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_export']);
        add_action('admin_init', [__CLASS__, 'handle_import']);
    }

    /* ---------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------- */

    /**
     * Build a nonce-protected export URL for a given dataset.
     */
    public static function export_url($type)
    {
        return wp_nonce_url(
            admin_url('admin.php?page=smartlinker&slk_export=' . rawurlencode($type)),
            'slk_export_' . $type
        );
    }

    public static function handle_export()
    {
        if (empty($_GET['slk_export'])) {
            return;
        }
        $type = sanitize_key($_GET['slk_export']);
        if (!check_admin_referer('slk_export_' . $type)) {
            return;
        }

        $datasets = self::datasets();
        if (!isset($datasets[$type])) {
            return;
        }
        [$capability, $method] = $datasets[$type];
        if (!current_user_can($capability)) {
            return;
        }
        call_user_func([__CLASS__, $method]);
    }

    protected static function export_autolinks()
    {
        $rows = Slk_Keyword::all(false);
        $header = ['keyword', 'url', 'case_sensitive', 'partial_match', 'new_tab', 'nofollow', 'max_per_post', 'active'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $r->keyword,
                $r->url,
                (int) $r->case_sensitive,
                (int) $r->partial_match,
                (int) $r->new_tab,
                (int) $r->nofollow,
                (int) $r->max_per_post,
                (int) $r->active,
            ];
        }
        self::stream('smartlinker-autolink-rules.csv', $header, $data);
    }

    protected static function export_report()
    {
        $rows = Slk_Report::post_rows(100000, 0);
        $header = ['post_id', 'title', 'post_type', 'outbound_internal', 'inbound_internal', 'clicks', 'url'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                (int) $r->ID,
                $r->post_title,
                $r->post_type,
                (int) $r->outbound,
                (int) $r->inbound,
                (int) $r->clicks,
                get_permalink($r->ID),
            ];
        }
        self::stream('smartlinker-links-report.csv', $header, $data);
    }

    protected static function export_broken()
    {
        $rows = Slk_Error::broken_rows(100000);
        $header = ['found_in_post_id', 'found_in_title', 'anchor', 'url', 'type'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                (int) $r->post_id,
                $r->post_title,
                $r->anchor,
                $r->url,
                $r->type,
            ];
        }
        self::stream('smartlinker-broken-links.csv', $header, $data);
    }

    /* ---------------------------------------------------------------------
     * Exports for the reports built later
     * ------------------------------------------------------------------- */

    /**
     * One row per anchor→target pair, not per anchor.
     *
     * The reason to open this in a spreadsheet is almost always ambiguity —
     * one phrase pointing at several different posts. Collapsing that to a
     * "3 targets" cell throws away the only column you would sort on. The
     * anchor-level figures repeat down the group, which is what makes a pivot
     * table work.
     */
    protected static function export_anchors()
    {
        $header = [
            'anchor', 'total_uses', 'distinct_targets', 'source_posts',
            'generic', 'ambiguous', 'over_used',
            'target_title', 'target_url', 'target_post_id', 'uses_to_this_target',
        ];
        $data = [];
        foreach (Slk_Anchor::rows() as $r) {
            foreach ($r['targets'] as $t) {
                $data[] = [
                    $r['anchor'],
                    (int) $r['uses'],
                    (int) $r['target_count'],
                    (int) $r['source_count'],
                    $r['generic'] ? 1 : 0,
                    $r['ambiguous'] ? 1 : 0,
                    $r['repetitive'] ? 1 : 0,
                    $t['title'],
                    $t['url'],
                    (int) $t['post_id'],
                    (int) $t['count'],
                ];
            }
        }
        self::stream('smartlinker-anchor-text.csv', $header, $data);
    }

    protected static function export_equity()
    {
        $data = [];
        foreach (Slk_Equity::all()['rows'] as $r) {
            $data[] = [
                (int) $r['id'],
                $r['title'],
                $r['url'],
                // Raw PageRank as well as the relative figure. The screen only
                // shows relative because 0.0004 tells nobody anything, but a
                // spreadsheet may want to weight by it.
                round($r['equity'], 8),
                round($r['relative'], 4),
                $r['depth'] === null ? '' : (int) $r['depth'],
                (int) $r['inbound'],
                (int) $r['outbound'],
                $r['seed'] ? 1 : 0,
            ];
        }
        $header = ['post_id', 'title', 'url', 'pagerank', 'relative_equity', 'click_depth', 'inbound', 'outbound', 'is_seed'];
        self::stream('smartlinker-link-equity.csv', $header, $data);
    }

    protected static function export_cannibal()
    {
        $header = [
            'severity', 'similarity', 'shared_keyword',
            'post_a_id', 'post_a_title', 'post_a_url', 'post_a_keyword', 'post_a_equity',
            'post_b_id', 'post_b_title', 'post_b_url', 'post_b_keyword', 'post_b_equity',
            'suggested_keep_id', 'suggested_keep_title',
        ];
        $data = [];
        foreach (Slk_Cannibal::rows() as $r) {
            $keep_is_a = $r['stronger'] === $r['a'];
            $data[] = [
                $r['severity'],
                $r['similarity'] === null ? '' : round($r['similarity'], 4),
                $r['keyword'],
                (int) $r['a'], $r['a_title'], $r['a_url'], $r['a_keyword'], round($r['a_equity'], 4),
                (int) $r['b'], $r['b_title'], $r['b_url'], $r['b_keyword'], round($r['b_equity'], 4),
                (int) $r['stronger'],
                $keep_is_a ? $r['a_title'] : $r['b_title'],
            ];
        }
        self::stream('smartlinker-cannibalisation.csv', $header, $data);
    }

    protected static function export_placement()
    {
        $header = ['post_id', 'title', 'url', 'internal_links', 'avg_position', 'in_first_quarter', 'in_last_quarter', 'shape', 'positions'];
        $data = [];
        foreach (Slk_Placement::all()['rows'] as $r) {
            $data[] = [
                (int) $r['id'],
                $r['title'],
                $r['url'],
                (int) $r['links'],
                $r['avg'] === null ? '' : round($r['avg'], 4),
                (int) $r['first_quarter'],
                (int) $r['last_quarter'],
                $r['shape'],
                // Every individual position, so the distribution can be
                // re-plotted rather than only the average trusted.
                implode(' ', array_map(function ($p) {
                    return round($p, 4);
                }, $r['spots'])),
            ];
        }
        self::stream('smartlinker-link-placement.csv', $header, $data);
    }

    protected static function export_domains()
    {
        $data = [];
        foreach (Slk_Report::domain_rows(100000) as $r) {
            $data[] = [$r->host, (int) $r->links, (int) $r->posts];
        }
        $header = ['host', 'links', 'posts_linking'];
        self::stream('smartlinker-domains.csv', $header, $data);
    }

    protected static function export_trends()
    {
        $header = ['date', 'published_posts', 'internal_links', 'external_links', 'orphaned', 'broken', 'open_opportunities', 'avg_click_depth'];
        $data = [];
        // Everything kept, not the window the screen happens to be showing —
        // the reason to export a trend is to keep it.
        foreach (Slk_History::series(Slk_History::KEEP_DAYS) as $r) {
            $data[] = [
                $r['taken_on'],
                (int) $r['posts'],
                (int) $r['internal'],
                (int) $r['external'],
                (int) $r['orphaned'],
                (int) $r['broken'],
                (int) $r['opportunities'],
                (float) $r['avg_depth'],
            ];
        }
        self::stream('smartlinker-trends.csv', $header, $data);
    }

    /* ---------------------------------------------------------------------
     * Streaming
     * ------------------------------------------------------------------- */

    /**
     * The $escape argument fputcsv() takes.
     *
     * Empty string means "no escape character", which is what RFC 4180 CSV
     * actually is — a quote inside a field is doubled, and nothing else is
     * special. PHP's historical default was a backslash, a non-standard
     * extension that surprises every other CSV reader.
     *
     * It has to be passed EXPLICITLY. PHP 8.4 deprecates calling fputcsv()
     * without it, because the default changes in PHP 9 — and the deprecation
     * notice was being written straight into the download, corrupting every
     * file the plugin exported. See stream() for why that is so easy to miss.
     */
    const CSV_ESCAPE = '';

    /**
     * Stream an array of rows to the browser as a CSV download.
     */
    protected static function stream($filename, $header, $rows)
    {
        /*
         * Throw away anything already buffered, and stop PHP writing errors
         * into the response.
         *
         * A CSV download is one of the few places where a stray notice does
         * not merely look untidy — it lands inside the file, before the header
         * row, and the spreadsheet opens as gibberish. That is exactly what a
         * PHP 8.4 deprecation did here, and it went unnoticed for a while
         * because a browser downloads the file rather than showing it, and the
         * admin pages it was tested alongside all rendered perfectly.
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
        @ini_set('display_errors', '0');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents correctly.
        fwrite($out, "\xEF\xBB\xBF");
        self::put($out, $header);
        foreach ($rows as $row) {
            self::put($out, $row);
        }
        fclose($out);
        exit;
    }

    /** One CSV row, defused and written with an explicit escape character. */
    protected static function put($handle, array $row)
    {
        fputcsv($handle, array_map([__CLASS__, 'defuse'], $row), ',', '"', self::CSV_ESCAPE);
    }

    /**
     * Stop a spreadsheet treating a cell as a formula.
     *
     * Anchor text and post titles are content, and content can start with
     * "=" or "+". Excel and Sheets read a leading =, +, -, @, tab or CR as
     * the start of a formula, so a post titled =HYPERLINK("http://…","Click")
     * becomes a live link in whatever spreadsheet the export is opened in —
     * on a machine that never visited the site. Quoting does not help; the
     * character has to stop being the first one.
     *
     * A leading apostrophe is the conventional fix: spreadsheets read it as
     * "this is text" and do not display it. Bare numbers are left alone so
     * the numeric columns stay numeric.
     */
    public static function defuse($value)
    {
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        $value = (string) $value;
        if ($value === '' || !preg_match('/^[=+\-@\t\r]/', $value)) {
            return $value;
        }
        // A plain negative number is not a formula and should stay a number.
        if (is_numeric($value)) {
            return $value;
        }
        return "'" . $value;
    }

    /* ---------------------------------------------------------------------
     * Import (auto-link rules)
     * ------------------------------------------------------------------- */

    public static function handle_import()
    {
        if (empty($_POST['slk_import_autolinks'])) {
            return;
        }
        if (!current_user_can('manage_categories') || !check_admin_referer('slk_import_autolinks')) {
            return;
        }

        $redirect = admin_url('admin.php?page=smartlinker_autolinks');

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            wp_safe_redirect($redirect . '&import_err=file');
            exit;
        }

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) {
            wp_safe_redirect($redirect . '&import_err=read');
            exit;
        }

        global $wpdb;
        $table = Slk_Query::autolinks_table();
        $imported = 0;
        $skipped = 0;
        $row_num = 0;
        $col = null; // header -> index map

        // Escape passed explicitly: PHP 8.4 deprecates omitting it, and the
        // default changes in PHP 9. '' is RFC 4180 CSV — quotes doubled,
        // nothing else special — which is what other tools produce.
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row_num++ > self::MAX_IMPORT_ROWS) {
                break;
            }
            // Strip a UTF-8 BOM from the first cell if present.
            if (isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue; // blank line
            }

            // First non-empty row is treated as the header if it looks like one.
            if ($col === null) {
                $lower = array_map(function ($c) {
                    return strtolower(trim((string) $c));
                }, $cells);
                if (in_array('keyword', $lower, true) && in_array('url', $lower, true)) {
                    $col = array_flip($lower);
                    continue;
                }
                // No header — assume positional: keyword,url,case,partial,newtab,nofollow,max,active
                $col = [
                    'keyword' => 0, 'url' => 1, 'case_sensitive' => 2, 'partial_match' => 3,
                    'new_tab' => 4, 'nofollow' => 5, 'max_per_post' => 6, 'active' => 7,
                ];
            }

            $get = function ($key, $default = '') use ($cells, $col) {
                return isset($col[$key], $cells[$col[$key]]) ? trim((string) $cells[$col[$key]]) : $default;
            };

            $keyword = sanitize_text_field($get('keyword'));
            $url = esc_url_raw($get('url'));
            if ($keyword === '' || $url === '') {
                $skipped++;
                continue;
            }

            $wpdb->insert($table, [
                'keyword'        => $keyword,
                'url'            => $url,
                'target_post_id' => (int) url_to_postid($url),
                'case_sensitive' => (int) (bool) $get('case_sensitive', 0),
                'partial_match'  => (int) (bool) $get('partial_match', 0),
                'new_tab'        => (int) (bool) $get('new_tab', 0),
                'nofollow'       => (int) (bool) $get('nofollow', 0),
                'max_per_post'   => max(1, (int) $get('max_per_post', 1)),
                'active'         => $get('active', '1') === '' ? 1 : (int) (bool) $get('active', 1),
                'created'        => current_time('mysql'),
            ]);
            $imported++;
        }
        fclose($handle);

        wp_safe_redirect($redirect . '&imported=' . $imported . '&skipped=' . $skipped);
        exit;
    }
}
