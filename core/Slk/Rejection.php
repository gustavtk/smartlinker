<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site-wide memory of the suggestions you have turned down.
 *
 * Rejection used to be recorded per post as a bare list of target IDs. Two
 * things were wrong with that. It never left the post, so turning down the
 * same bad suggestion on twenty posts meant saying no twenty times. And it
 * stored only the destination, never the anchor — so when the real problem was
 * the word "equipment", nothing recorded the word.
 *
 * This keeps both halves and lets repetition speak. Turn down the same
 * anchor→target pair on two different posts and that pair stops being offered
 * anywhere. Turn down the same anchor on three different posts, whatever it
 * pointed at, and the anchor itself is retired.
 *
 * Two rules keep this from quietly eating good suggestions:
 *
 *   Distinct posts are counted, not events. Rejecting the same thing twice on
 *   one post is one opinion, not two.
 *
 *   Nothing is permanent or invisible. Everything suppressed is listed under
 *   Reports → Anchor Text → Suppressed, with the posts that caused it and a
 *   one-click restore. A silent filter you cannot inspect is a bug you cannot
 *   find.
 */
class Slk_Rejection
{
    const OPTION = 'slk_rejections';

    /** Defaults, used when the setting is missing. */
    const PAIR_THRESHOLD = 2;
    const ANCHOR_THRESHOLD = 3;

    /**
     * Distinct posts that must reject an anchor→target pair before it is
     * retired. 0 switches this off.
     */
    public static function pair_threshold()
    {
        return max(0, min(50, (int) Slk_Settings::get('reject_pair_threshold', self::PAIR_THRESHOLD)));
    }

    /**
     * Distinct posts that must reject an anchor, whatever it pointed at,
     * before the anchor is retired. 0 switches this off.
     */
    public static function anchor_threshold()
    {
        return max(0, min(50, (int) Slk_Settings::get('reject_anchor_threshold', self::ANCHOR_THRESHOLD)));
    }

    /** True when site-wide learning is switched off entirely. */
    public static function learning_off()
    {
        return self::pair_threshold() === 0 && self::anchor_threshold() === 0;
    }

    /* ---------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    public static function all()
    {
        $v = get_option(self::OPTION, []);
        if (!is_array($v)) {
            $v = [];
        }
        return wp_parse_args($v, ['anchors' => [], 'pairs' => []]);
    }

    protected static function save(array $data)
    {
        update_option(self::OPTION, $data, false);
    }

    /** Anchors are compared the way the anchor report compares them. */
    public static function key($anchor)
    {
        return Slk_Anchor::normalize($anchor);
    }

    public static function pair_key($anchor, $target_id)
    {
        return self::key($anchor) . '|' . (int) $target_id;
    }

    /* ---------------------------------------------------------------------
     * Recording
     * ------------------------------------------------------------------ */

    /**
     * Note that $post_id turned down linking $anchor to $target_id.
     *
     * @return array{anchor_blocked:bool,pair_blocked:bool} what this made true
     */
    public static function record($post_id, $target_id, $anchor)
    {
        $post_id = (int) $post_id;
        $target_id = (int) $target_id;
        $norm = self::key($anchor);

        $data = self::all();

        // An anchor is only learnable if we were told what it was. Older
        // clients, and rejections of archive targets, may not send one.
        if ($norm !== '') {
            foreach ([['anchors', $norm], ['pairs', self::pair_key($anchor, $target_id)]] as [$bucket, $k]) {
                if (!isset($data[$bucket][$k])) {
                    $data[$bucket][$k] = [
                        'anchor'  => trim(wp_strip_all_tags($anchor)),
                        'target'  => $bucket === 'pairs' ? $target_id : 0,
                        'posts'   => [],
                        'targets' => [],
                        'first'   => current_time('mysql'),
                    ];
                }
                $row = &$data[$bucket][$k];
                // Distinct posts, so re-rejecting on one post is one opinion.
                if (!in_array($post_id, $row['posts'], true)) {
                    $row['posts'][] = $post_id;
                }
                if ($target_id && !in_array($target_id, $row['targets'], true)) {
                    $row['targets'][] = $target_id;
                }
                $row['last'] = current_time('mysql');
                unset($row);
            }
        }

        self::save($data);

        return [
            'anchor_blocked' => $norm !== '' && self::anchor_blocked($anchor),
            'pair_blocked'   => $norm !== '' && self::pair_blocked($anchor, $target_id),
        ];
    }

    /* ---------------------------------------------------------------------
     * Asking
     * ------------------------------------------------------------------ */

    public static function anchor_blocked($anchor)
    {
        $data = self::all();
        $k = self::key($anchor);
        if ($k === '' || !isset($data['anchors'][$k])) {
            return false;
        }
        $threshold = self::anchor_threshold();
        return $threshold > 0 && count($data['anchors'][$k]['posts']) >= $threshold;
    }

    public static function pair_blocked($anchor, $target_id)
    {
        $data = self::all();
        $k = self::pair_key($anchor, $target_id);
        if (!isset($data['pairs'][$k])) {
            return false;
        }
        $threshold = self::pair_threshold();
        return $threshold > 0 && count($data['pairs'][$k]['posts']) >= $threshold;
    }

    /**
     * The one call the suggestion engine makes.
     *
     * @return bool true when this anchor must not be offered for this target
     */
    public static function blocked($anchor, $target_id)
    {
        return self::anchor_blocked($anchor) || self::pair_blocked($anchor, $target_id);
    }

    /* ---------------------------------------------------------------------
     * Undoing
     * ------------------------------------------------------------------ */

    /**
     * Put a suppressed anchor or pair back into circulation.
     *
     * Restoring CLEARS the accumulated evidence rather than granting a
     * permanent exemption. Two reasons. A permanent whitelist would be a
     * one-way door — reject the same thing fifty more times and it would still
     * be offered, with no way to change your mind back. And "start over" is
     * what the button appears to mean.
     *
     * Restoring an anchor also releases every pair using it. Without that the
     * anchor reads as restored on screen while a narrower pair block silently
     * keeps suppressing it — the exact kind of invisible filter this feature
     * is supposed to avoid.
     */
    public static function restore($bucket, $key)
    {
        $data = self::all();
        if (!in_array($bucket, ['anchors', 'pairs'], true) || !isset($data[$bucket][$key])) {
            return false;
        }

        $now = current_time('mysql');
        $anchor = $data[$bucket][$key]['anchor'];

        $data[$bucket][$key]['posts'] = [];
        $data[$bucket][$key]['restored'] = $now;

        if ($bucket === 'anchors') {
            $norm = self::key($anchor);
            foreach ($data['pairs'] as $pk => $prow) {
                if (self::key($prow['anchor']) === $norm) {
                    $data['pairs'][$pk]['posts'] = [];
                    $data['pairs'][$pk]['restored'] = $now;
                }
            }
        }

        self::save($data);
        return true;
    }


    /**
     * Everything currently suppressed, for the management screen.
     */
    public static function suppressed()
    {
        $data = self::all();
        $out = [];

        foreach ($data['anchors'] as $k => $row) {
            if (!self::anchor_blocked($row['anchor'])) {
                continue;
            }
            $out[] = [
                'bucket' => 'anchors',
                'key'    => $k,
                'anchor' => $row['anchor'],
                'scope'  => 'anchor',
                'target' => 0,
                'posts'  => $row['posts'],
                'last'   => isset($row['last']) ? $row['last'] : '',
            ];
        }

        foreach ($data['pairs'] as $k => $row) {
            if (!self::pair_blocked($row['anchor'], $row['target'])) {
                continue;
            }
            // A pair is noise once the anchor itself is retired site-wide.
            if (self::anchor_blocked($row['anchor'])) {
                continue;
            }
            $out[] = [
                'bucket' => 'pairs',
                'key'    => $k,
                'anchor' => $row['anchor'],
                'scope'  => 'pair',
                'target' => (int) $row['target'],
                'posts'  => $row['posts'],
                'last'   => isset($row['last']) ? $row['last'] : '',
            ];
        }

        usort($out, function ($a, $b) {
            return strcmp((string) $b['last'], (string) $a['last']);
        });

        return $out;
    }

    /**
     * Rejections recorded but not yet at their threshold — the evidence that
     * explains why something is about to be suppressed.
     */
    public static function pending()
    {
        $data = self::all();
        $out = [];
        foreach ($data['anchors'] as $k => $row) {
            $n = count($row['posts']);
            if ($n < 1 || self::anchor_threshold() < 1 || $n >= self::anchor_threshold()) {
                continue;
            }
            $out[] = [
                'anchor' => $row['anchor'],
                'count'  => $n,
                'needed' => self::anchor_threshold(),
                'last'   => isset($row['last']) ? $row['last'] : '',
            ];
        }
        usort($out, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Admin action
     * ------------------------------------------------------------------ */

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function handle_actions()
    {
        if (!class_exists('Slk_Reports') || !Slk_Reports::on_tab('anchors')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!empty($_GET['slk_restore']) && !empty($_GET['slk_bucket']) && check_admin_referer('slk_restore')) {
            self::restore(
                sanitize_key(wp_unslash($_GET['slk_bucket'])),
                sanitize_text_field(wp_unslash($_GET['slk_restore']))
            );
            wp_safe_redirect(Slk_Reports::url('anchors', ['show' => 'suppressed', 'restored' => 1]));
            exit;
        }
    }
}
