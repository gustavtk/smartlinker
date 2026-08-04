<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Semantic index: one OpenAI embedding vector per post.
 *
 * This is what lets "which grinder should I buy" match a page called
 * "Choosing a Burr Grinder". Word-overlap scoring cannot do that — the two
 * share no vocabulary — so the embedding is the signal that turns keyword
 * matching into meaning matching.
 *
 * Vectors live in post meta rather than a table: no schema migration, and
 * WordPress deletes them with the post. 512 dimensions packed as float32 is
 * ~2.7 KB per post base64-encoded, so even a large site stays small.
 */
class Slk_Embedding
{
    const ENDPOINT   = 'https://api.openai.com/v1/embeddings';
    const META       = '_slk_embedding';
    const META_HASH  = '_slk_embedding_hash';
    const MODEL      = 'text-embedding-3-small';
    const DIMENSIONS = 512;

    /** Characters of a post sent for embedding (model limit is ~8k tokens). */
    const MAX_CHARS = 8000;

    /** Posts per API request. */
    const BATCH = 50;

    /** Daily top-up event, so the index maintains itself. */
    const EVENT = 'slk_embed_topup';

    /**
     * Most posts one automatic top-up will embed.
     *
     * A ceiling because every embedding is a billed OpenAI call against the
     * site owner's own account. An unbounded background job that quietly spends
     * someone's money is not a feature. At 200 a day a large site catches up
     * within a week of enabling it, and an ordinary week of editing is a
     * handful of posts — well inside one run.
     */
    const TOPUP_MAX = 200;

    /**
     * Cosine below which two posts are treated as unrelated, and the value at
     * which they are treated as clearly the same topic. Embedding cosines sit
     * in a much narrower, higher band than TF-IDF ones, so they need their own
     * scale rather than reusing good_similarity.
     */
    const FLOOR = 0.25;
    const GOOD  = 0.70;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        // A post whose text changed needs re-embedding; clearing the hash is
        // enough, the next run picks it up.
        add_action('save_post', [__CLASS__, 'invalidate']);

        add_action(self::EVENT, [__CLASS__, 'top_up']);
        add_action('admin_init', [__CLASS__, 'ensure_scheduled'], 20);
    }

    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + (2 * HOUR_IN_SECONDS), 'daily', self::EVENT);
        }
    }

    /**
     * Bring the index up to date, a bounded amount at a time.
     *
     * The pieces for this were all here already — save_post marks a post
     * stale, and generate_batch() skips anything unchanged — but nothing ever
     * called it. The index knew exactly what was out of date and then waited
     * for someone to remember to visit a page and press a button. Edit ten
     * posts and the semantic matching silently used ten stale vectors until
     * you noticed.
     *
     * So this runs daily and tops up what changed. "Clear index" becomes what
     * it should always have been: a rare reset, not routine maintenance.
     *
     * It does nothing at all unless embeddings are switched on AND a key is
     * configured — a background job that starts spending money the moment a
     * key is pasted in would be a nasty surprise.
     *
     * @return array{done:int,remaining:int}
     */
    public static function top_up()
    {
        if (!self::is_enabled()) {
            return ['done' => 0, 'remaining' => 0];
        }

        $done = 0;
        $remaining = 0;

        // Several batches per run, but never past the ceiling.
        while ($done < self::TOPUP_MAX) {
            $result = self::generate_batch(min(self::BATCH, self::TOPUP_MAX - $done));
            if (is_wp_error($result)) {
                // A key that has been revoked, or the API being down. Stop and
                // try again tomorrow rather than hammering it.
                break;
            }
            $done += (int) $result['done'];
            $remaining = (int) $result['remaining'];
            if ((int) $result['done'] === 0) {
                break;   // nothing left that needs embedding
            }
        }

        if ($done > 0) {
            update_option('slk_embed_last_topup', current_time('mysql'), false);
        }
        return ['done' => $done, 'remaining' => $remaining];
    }

    public static function is_enabled()
    {
        return Slk_AI::is_configured()
            && (int) Slk_Settings::get('use_embeddings', 1) === 1;
    }

    public static function model()
    {
        $m = trim((string) Slk_Settings::get('embedding_model', self::MODEL));
        return $m !== '' ? $m : self::MODEL;
    }

    public static function invalidate($post_id)
    {
        delete_post_meta((int) $post_id, self::META_HASH);
    }

    /* ---------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------- */

    /** Content fingerprint — re-embed only when the text actually changes. */
    public static function hash($post)
    {
        return md5(self::model() . '|' . $post->post_title . '|' . $post->post_content);
    }

    protected static function pack(array $vector)
    {
        return base64_encode(pack('g*', ...$vector));
    }

    protected static function unpack_vector($packed)
    {
        $raw = base64_decode((string) $packed, true);
        if ($raw === false || $raw === '') {
            return null;
        }
        $vals = unpack('g*', $raw);
        return $vals ? array_values($vals) : null;
    }

    public static function store($post_id, array $vector, $hash)
    {
        update_post_meta((int) $post_id, self::META, self::pack($vector));
        update_post_meta((int) $post_id, self::META_HASH, $hash);
    }

    /**
     * Every stored vector, keyed by post id. One query — callers compare
     * hundreds of pairs, so per-post meta lookups would be far too slow.
     *
     * @return array<int,float[]>
     */
    public static function all()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META
        ));
        $cache = [];
        foreach ($rows as $r) {
            $v = self::unpack_vector($r->meta_value);
            if ($v) {
                $cache[(int) $r->post_id] = $v;
            }
        }
        return $cache;
    }

    /**
     * How many posts are indexed and how many still need it.
     *
     * @return array{total:int,done:int,stale:int}
     */
    public static function status()
    {
        $posts = Slk_Post::candidate_targets(0, 5000);
        $total = count($posts);
        $done = 0;
        foreach ($posts as $p) {
            $stored = get_post_meta((int) $p->ID, self::META_HASH, true);
            if ($stored !== '' && $stored === self::hash($p)) {
                $done++;
            }
        }
        return ['total' => $total, 'done' => $done, 'stale' => max(0, $total - $done)];
    }

    /* ---------------------------------------------------------------------
     * Similarity
     * ------------------------------------------------------------------- */

    public static function cosine(array $a, array $b)
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }
        return $dot / sqrt($na * $nb);
    }

    /* ---------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------- */

    /**
     * Embed the next batch of posts that need it.
     *
     * @return array{done:int,remaining:int}|WP_Error
     */
    public static function generate_batch($limit = self::BATCH)
    {
        if (!Slk_AI::is_configured()) {
            return new WP_Error('slk_no_key', __('Add an OpenAI API key under Settings → AI first.', 'smartlinker'));
        }

        $pending = [];
        foreach (Slk_Post::candidate_targets(0, 5000) as $p) {
            $hash = self::hash($p);
            if (get_post_meta((int) $p->ID, self::META_HASH, true) === $hash) {
                continue;
            }
            $pending[] = ['post' => $p, 'hash' => $hash];
            if (count($pending) >= $limit) {
                break;
            }
        }
        if (empty($pending)) {
            return ['done' => 0, 'remaining' => 0];
        }

        $inputs = [];
        foreach ($pending as $item) {
            $text = $item['post']->post_title . "\n\n"
                . Slk_Post::plain_text($item['post']->post_content);
            $text = trim(mb_substr($text, 0, self::MAX_CHARS));
            // The API rejects empty strings; a placeholder keeps the batch
            // aligned with $pending so results map back to the right post.
            if ($text === '') {
                $text = trim((string) $item['post']->post_title);
            }
            $inputs[] = $text !== '' ? $text : 'untitled';
        }

        $body = [
            'model' => self::model(),
            'input' => $inputs,
        ];
        // Only the v3 models support shortening the vector.
        if (strpos(self::model(), 'text-embedding-3') === 0) {
            $body['dimensions'] = (int) self::DIMENSIONS;
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => max(60, (int) Slk_Settings::get('ai_timeout', 45)),
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string) Slk_Settings::get('openai_api_key', '')),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            Slk_AI::log_error($response->get_error_message(), 'embeddings/transport');
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? sprintf(__('OpenAI API error (HTTP %d).', 'smartlinker'), $code);
            Slk_AI::log_error($msg, 'embeddings/HTTP ' . $code);
            return new WP_Error('slk_emb_http', $msg);
        }
        if (empty($data['data']) || !is_array($data['data'])) {
            return new WP_Error('slk_emb_parse', __('The embeddings response could not be read.', 'smartlinker'));
        }

        $done = 0;
        foreach ($data['data'] as $item) {
            // Trust "index", never array order — the API does not promise it.
            $i = isset($item['index']) ? (int) $item['index'] : -1;
            if (!isset($pending[$i]) || empty($item['embedding']) || !is_array($item['embedding'])) {
                continue;
            }
            self::store(
                (int) $pending[$i]['post']->ID,
                array_map('floatval', $item['embedding']),
                $pending[$i]['hash']
            );
            $done++;
        }

        $status = self::status();
        return ['done' => $done, 'remaining' => $status['stale']];
    }

    /* ---------------------------------------------------------------------
     * Admin action
     * ------------------------------------------------------------------- */

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_ai') {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!empty($_GET['slk_embed']) && check_admin_referer('slk_embed')) {
            $result = self::generate_batch();
            if (is_wp_error($result)) {
                wp_safe_redirect(admin_url('admin.php?page=smartlinker_ai&embed_err=' . rawurlencode($result->get_error_message())));
                exit;
            }
            // More to do? Keep going, one batch per request, so a large site
            // never hits the PHP time limit in a single call.
            if ($result['remaining'] > 0 && $result['done'] > 0) {
                wp_safe_redirect(wp_nonce_url(
                    admin_url('admin.php?page=smartlinker_ai&slk_embed=1'),
                    'slk_embed'
                ));
                exit;
            }
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_ai&embedded=1'));
            exit;
        }

        if (!empty($_GET['slk_embed_clear']) && check_admin_referer('slk_embed_clear')) {
            global $wpdb;
            $wpdb->delete($wpdb->postmeta, ['meta_key' => self::META]);
            $wpdb->delete($wpdb->postmeta, ['meta_key' => self::META_HASH]);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_ai&embed_cleared=1'));
            exit;
        }
    }
}
