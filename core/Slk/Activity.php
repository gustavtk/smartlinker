<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity log and undo for changes SmartLinker writes directly.
 *
 * Only server-side writes are recorded — the reports that edit a post with no
 * editor open. Insertions made from the editor panel are deliberately NOT
 * logged: those change unsaved editor state, the author reviews them before
 * saving, and WordPress revisions already cover the save. The dangerous case
 * is the one-click apply from a list, and that is what this protects.
 *
 * Undo restores the whole post as it was. Replaying an edit backwards sounds
 * tidier but breaks as soon as the surrounding text has moved on; a full
 * snapshot always restores cleanly. The trade is disk space, which is why the
 * log is pruned.
 */
class Slk_Activity
{
    /** Fallback when the setting is missing. Older entries drop as new ones arrive. */
    const KEEP = 300;

    /**
     * How many entries to keep. 0 switches the log off entirely.
     */
    public static function keep()
    {
        $n = Slk_Settings::get('activity_keep', self::KEEP);
        return max(0, min(5000, (int) $n));
    }

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    /**
     * Record a change we just made.
     *
     * @param string $action  insert | remove | anchor | replace
     * @param string $summary human-readable, shown in the log
     */
    public static function record($post_id, $action, $summary, $content_before, $content_after, $target_url = '', $batch = '')
    {
        if (self::keep() === 0) {
            return;
        }

        global $wpdb;
        $wpdb->insert(Slk_Query::activity_table(), [
            'post_id'        => (int) $post_id,
            'action'         => sanitize_key($action),
            'summary'        => (string) $summary,
            'target_url'     => (string) $target_url,
            'content_before' => (string) $content_before,
            'after_hash'     => md5((string) $content_after),
            'batch'          => (string) $batch,
            'user_id'        => get_current_user_id(),
            'reverted'       => 0,
            'created'        => current_time('mysql'),
        ], [
            // Positional — this list must stay in step with the array above.
            '%d', // post_id
            '%s', // action
            '%s', // summary
            '%s', // target_url
            '%s', // content_before
            '%s', // after_hash
            '%s', // batch
            '%d', // user_id
            '%d', // reverted
            '%s', // created
        ]);

        self::prune();
    }

    /**
     * Trim the log to the configured size, WITHOUT splitting a batch.
     *
     * A bulk operation writes one row per post, and prune() runs after each
     * one. With the default of 300 kept entries, rewriting 500 posts would
     * quietly delete the first 200 rows of that very operation while it was
     * still running — leaving a half-undoable batch, which is precisely the
     * situation the batch was added to prevent.
     *
     * So the newest batch is exempt. It stops being exempt as soon as another
     * batch arrives, at which point it is complete and can be trimmed like
     * anything else. The log can therefore briefly exceed its limit during a
     * large operation; a temporarily larger log is a far better outcome than
     * an undo that only half works.
     */
    protected static function prune()
    {
        global $wpdb;
        $table = Slk_Query::activity_table();

        // phpcs:ignore WordPress.DB.PreparedSQL
        $cutoff = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            self::keep()
        ));
        if (!$cutoff) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $newest_batch = (string) $wpdb->get_var(
            "SELECT batch FROM {$table} WHERE batch <> '' ORDER BY id DESC LIMIT 1"
        );

        if ($newest_batch === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", (int) $cutoff));
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE id <= %d AND batch <> %s",
            (int) $cutoff,
            $newest_batch
        ));
    }

    /** Change types the log records, in the order they are offered as filters. */
    public static function actions()
    {
        return [
            'insert'  => __('Link added', 'smartlinker'),
            'replace' => __('Link repointed', 'smartlinker'),
            'anchor'  => __('Anchor changed', 'smartlinker'),
            'remove'  => __('Link removed', 'smartlinker'),
            'rewrite' => __('URL replaced', 'smartlinker'),
        ];
    }

    /**
     * Read the log, newest first.
     *
     * @param array $args post (int), action (slug), state (undoable|undone), limit
     */
    public static function recent($args = [])
    {
        // Called as recent(150) in a few places, and with an args array here.
        if (!is_array($args)) {
            $args = ['limit' => (int) $args];
        }
        $args = wp_parse_args($args, ['post' => 0, 'action' => '', 'state' => '', 'limit' => 150]);

        global $wpdb;
        $table = Slk_Query::activity_table();

        $where = ['1=1'];
        $params = [];
        if (!empty($args['post'])) {
            $where[] = 'post_id = %d';
            $params[] = (int) $args['post'];
        }
        if (!empty($args['action']) && isset(self::actions()[$args['action']])) {
            $where[] = 'action = %s';
            $params[] = $args['action'];
        }
        if ($args['state'] === 'undone') {
            $where[] = 'reverted = 1';
        } elseif ($args['state'] === 'undoable') {
            // Only the log's own view of it. Whether the post has since been
            // edited is a per-row check the template makes, because it needs
            // the live content anyway.
            $where[] = 'reverted = 0';
        }
        $params[] = max(1, (int) $args['limit']);

        $sql = "SELECT id, post_id, action, summary, target_url, after_hash, user_id, reverted, created
                FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';

        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Posts that appear in the log, for the filter dropdown.
     */
    public static function logged_posts()
    {
        global $wpdb;
        $table = Slk_Query::activity_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$table} ORDER BY id DESC");

        $out = [];
        foreach ($ids as $id) {
            $title = get_the_title($id);
            /* translators: %d: id of a post that no longer exists */
            $out[(int) $id] = $title !== '' ? $title : sprintf(__('Post %d (deleted)', 'smartlinker'), $id);
        }
        return $out;
    }

    /** How many entries there are in total, ignoring filters. */
    public static function total()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Slk_Query::activity_table());
    }

    /**
     * Put a post back the way it was.
     *
     * Refuses when the post no longer matches what we wrote: someone has
     * edited it since, and restoring the snapshot would throw that work away.
     *
     * @return true|WP_Error
     */
    /**
     * A token grouping every row written by one bulk operation.
     *
     * Bulk operations rewrite many posts in one action, and undoing them one
     * post at a time is recovery rather than undo — nobody clicks five hundred
     * times. Rows written under one token can be reversed together.
     */
    public static function new_batch()
    {
        return substr(md5(uniqid('slk', true)), 0, 32);
    }

    /**
     * Undo every post in one bulk operation.
     *
     * Reverts newest-first, so if a post was touched twice within the same
     * batch the earlier snapshot is the one that ends up applied.
     *
     * Partial success is reported rather than hidden. A post edited since the
     * operation ran cannot be restored without discarding that edit, and the
     * honest outcome is "eleven of twelve undone, this one needs you" — not a
     * silent success or a total failure that undoes nothing.
     *
     * @return array{done:int,failed:int,errors:array}
     */
    public static function revert_batch($batch)
    {
        global $wpdb;
        $table = Slk_Query::activity_table();
        $batch = substr(preg_replace('/[^a-f0-9]/', '', (string) $batch), 0, 32);
        if ($batch === '') {
            return ['done' => 0, 'failed' => 0, 'errors' => []];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE batch = %s AND reverted = 0 ORDER BY id DESC",
            $batch
        ));

        $done = 0;
        $errors = [];
        foreach ($ids as $id) {
            $r = self::revert((int) $id);
            if (is_wp_error($r)) {
                $errors[] = $r->get_error_message();
            } else {
                $done++;
            }
        }

        return ['done' => $done, 'failed' => count($errors), 'errors' => array_unique($errors)];
    }

    public static function revert($id)
    {
        global $wpdb;
        $table = Slk_Query::activity_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id));
        if (!$row) {
            return new WP_Error('slk_no_entry', __('That entry is no longer in the log.', 'smartlinker'));
        }
        if ((int) $row->reverted === 1) {
            return new WP_Error('slk_already', __('Already undone.', 'smartlinker'));
        }
        // Existence before permission: edit_post on a missing post maps to
        // do_not_allow, which would blame the user for a deleted post.
        $post = get_post($row->post_id);
        if (!$post) {
            return new WP_Error('slk_no_post', __('That post no longer exists.', 'smartlinker'));
        }
        if (!current_user_can('edit_post', $row->post_id)) {
            return new WP_Error('slk_denied', __('Permission denied.', 'smartlinker'));
        }

        if (md5($post->post_content) !== $row->after_hash) {
            return new WP_Error(
                'slk_changed',
                __('This post has been edited since, so undoing would discard that work. Open the post and remove the link by hand.', 'smartlinker')
            );
        }

        wp_update_post(['ID' => $row->post_id, 'post_content' => $row->content_before]);
        Slk_Link::index_post($row->post_id);
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->update($table, ['reverted' => 1], ['id' => (int) $id], ['%d'], ['%d']);

        return true;
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_activity') {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Undo a whole bulk operation.
        if (!empty($_GET['slk_undo_batch']) && check_admin_referer('slk_undo_batch')) {
            $r = self::revert_batch(sanitize_text_field(wp_unslash($_GET['slk_undo_batch'])));

            $args = ['page' => 'smartlinker_activity'];
            foreach (['slk_post', 'slk_action', 'slk_state'] as $key) {
                if (!empty($_GET[$key])) {
                    $args[$key] = sanitize_key(wp_unslash($_GET[$key]));
                }
            }
            if ($r['failed'] > 0) {
                // Partial success reported honestly: saying "done" when three
                // posts were skipped is how someone finds out weeks later.
                $args['undo_err'] = sprintf(
                    /* translators: 1: posts undone, 2: posts that could not be */
                    __('Undid %1$d post(s). %2$d could not be undone because they have been edited since.', 'smartlinker'),
                    $r['done'],
                    $r['failed']
                );
            } else {
                $args['undone'] = $r['done'];
            }

            wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')));
            exit;
        }

        if (!empty($_GET['slk_undo']) && check_admin_referer('slk_undo')) {
            $result = self::revert((int) $_GET['slk_undo']);

            $args = ['page' => 'smartlinker_activity'];
            // Carry the filters through, so undoing does not throw you back to
            // the top of an unfiltered list.
            foreach (['slk_post', 'slk_action', 'slk_state'] as $key) {
                if (!empty($_GET[$key])) {
                    $args[$key] = sanitize_key(wp_unslash($_GET[$key]));
                }
            }
            if (is_wp_error($result)) {
                $args['undo_err'] = $result->get_error_message();
            } else {
                $args['undone'] = 1;
            }

            wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Bulk operations in the log, newest first, with how much of each is
     * still undoable.
     *
     * @return array of ['batch','summary','posts','undoable','created']
     */
    public static function batches($limit = 20)
    {
        global $wpdb;
        $table = Slk_Query::activity_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT batch,
                    MIN(summary) AS summary,
                    COUNT(*) AS posts,
                    SUM(CASE WHEN reverted = 0 THEN 1 ELSE 0 END) AS undoable,
                    MAX(created) AS created
             FROM {$table}
             WHERE batch <> ''
             GROUP BY batch
             ORDER BY MAX(id) DESC
             LIMIT %d",
            (int) $limit
        ));
    }

    public static function render_page()
    {
        $batches = self::batches();
        $filters = [
            'post'   => isset($_GET['slk_post']) ? (int) $_GET['slk_post'] : 0,
            'action' => isset($_GET['slk_action']) ? sanitize_key($_GET['slk_action']) : '',
            'state'  => isset($_GET['slk_state']) ? sanitize_key($_GET['slk_state']) : '',
        ];
        $rows = self::recent($filters);
        $posts = self::logged_posts();
        $total = self::total();
        $keep = self::keep();
        include SLK_PLUGIN_DIR . 'templates/activity.php';
    }
}
