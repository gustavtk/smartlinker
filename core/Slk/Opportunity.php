<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link opportunities: every place on the site where one post says something
 * that names another post, gathered into a single worklist.
 *
 * The editor panel answers "what should THIS post link to". This answers the
 * same question for the whole site at once, so linking can be done as a batch
 * of decisions rather than one post at a time.
 *
 * Nothing new is computed here — it reuses Slk_Suggestion::for_post(), so a
 * row appears only if it would have appeared in the editor. That matters: two
 * engines producing different answers for the same pair would be indefensible.
 */
class Slk_Opportunity
{
    const TRANSIENT = 'slk_opportunities';

    /** Posts examined per scan pass, so a big site does not time out. */
    const BATCH = 40;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        // Deliberately NOT flushed on save_post. Applying a link from this page
        // saves a post, which would wipe the very list being worked through —
        // one click and the worklist vanishes. It is a dated snapshot with a
        // Re-scan button instead. Rows that go stale are harmless: the engine
        // excludes already-linked targets on the next scan.
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function flush()
    {
        delete_transient(self::TRANSIENT . '_standard');
        delete_transient(self::TRANSIENT . '_ai');
    }

    /**
     * @return array{rows:array,scanned:int,total:int,generated:string}
     */
    public static function all($engine = 'standard')
    {
        $cached = get_transient(self::TRANSIENT . '_' . $engine);
        return is_array($cached) ? $cached : ['rows' => [], 'scanned' => 0, 'total' => 0, 'generated' => ''];
    }

    /**
     * Scan a slice of the site, appending to whatever is already cached.
     *
     * @param int $offset how many posts to skip
     * @return array{rows:array,scanned:int,total:int,done:bool}
     */
    public static function scan($offset = 0, $engine = 'standard')
    {
        $posts = Slk_Post::candidate_targets(0, 5000);
        $total = count($posts);

        $state = $offset > 0 ? self::all($engine) : ['rows' => [], 'scanned' => 0, 'total' => $total, 'generated' => ''];
        $rows = $state['rows'];

        $slice = array_slice($posts, $offset, self::BATCH);
        foreach ($slice as $src) {
            // The AI engine costs one call per post, which is why the scan is
            // batched and the page states the cost before you start.
            if ($engine === 'ai') {
                $found = Slk_AI::suggest($src->ID);
                if (is_wp_error($found)) {
                    continue;
                }
            } else {
                $found = Slk_Suggestion::for_post($src->ID);
            }
            foreach ($found as $s) {
                // Archives and external pages belong to their own features.
                if (empty($s['target_id'])) {
                    continue;
                }
                $rows[] = [
                    'source_id'    => (int) $src->ID,
                    'source_title' => trim(wp_strip_all_tags($src->post_title)),
                    'phrase'       => $s['phrase'],
                    'target_id'    => (int) $s['target_id'],
                    'target_title' => $s['target_title'],
                    'url'          => $s['url'],
                    'path'         => $s['path'],
                    'match'        => isset($s['match']) ? (int) $s['match'] : 0,
                    'reason'       => isset($s['reason']) ? $s['reason'] : '',
                ];
            }
        }

        usort($rows, function ($a, $b) {
            return $b['match'] <=> $a['match'];
        });

        $scanned = min($total, $offset + self::BATCH);
        $out = [
            'rows'      => $rows,
            'scanned'   => $scanned,
            'total'     => $total,
            'generated' => current_time('mysql'),
        ];
        set_transient(self::TRANSIENT . '_' . $engine, $out, DAY_IN_SECONDS);

        $out['done'] = $scanned >= $total;
        return $out;
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_opportunities') {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!empty($_GET['slk_opp_scan']) && check_admin_referer('slk_opp_scan')) {
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
            $engine = isset($_GET['engine']) && $_GET['engine'] === 'ai' ? 'ai' : 'standard';
            $result = self::scan($offset, $engine);

            // One batch per request, redirecting onward, so a large site never
            // hits the PHP time limit in a single call.
            if (empty($result['done'])) {
                wp_safe_redirect(wp_nonce_url(
                    admin_url('admin.php?page=smartlinker_opportunities&slk_opp_scan=1&engine=' . $engine
                        . '&offset=' . $result['scanned']),
                    'slk_opp_scan'
                ));
                exit;
            }
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_opportunities&engine=' . $engine . '&scanned=1'));
            exit;
        }
    }

    public static function render_page()
    {
        $engine = isset($_GET['engine']) && $_GET['engine'] === 'ai' ? 'ai' : 'standard';
        $data = self::all($engine);
        include SLK_PLUGIN_DIR . 'templates/opportunities.php';
    }
}
