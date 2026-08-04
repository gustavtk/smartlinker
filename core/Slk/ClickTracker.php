<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks clicks on internal links. A tiny frontend script pings an AJAX
 * endpoint when a data-slk link is clicked; we log it for reporting.
 */
class Slk_ClickTracker
{
    public function register()
    {
        if (!Slk_Settings::get('track_clicks')) {
            return;
        }
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_script']);
        add_action('wp_ajax_slk_track_click', [__CLASS__, 'ajax_track']);
        add_action('wp_ajax_nopriv_slk_track_click', [__CLASS__, 'ajax_track']);
    }

    public static function frontend_script()
    {
        if (is_admin()) {
            return;
        }
        wp_register_script('slk-clicks', '', [], SLK_VERSION, true);
        wp_enqueue_script('slk-clicks');

        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('slk_click');
        $post_id = (int) get_the_ID();

        $inline = <<<JS
document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-slk]') : null;
    if (!a) return;
    try {
        var body = new URLSearchParams();
        body.append('action', 'slk_track_click');
        body.append('nonce', '{$nonce}');
        body.append('post_id', '{$post_id}');
        body.append('url', a.href);
        body.append('anchor', (a.textContent || '').slice(0, 190));
        navigator.sendBeacon ? navigator.sendBeacon('{$ajax}', body) :
            fetch('{$ajax}', { method: 'POST', body: body, keepalive: true });
    } catch (err) {}
}, true);
JS;
        wp_add_inline_script('slk-clicks', $inline);
    }

    public static function ajax_track()
    {
        check_ajax_referer('slk_click', 'nonce');
        global $wpdb;

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($url === '') {
            wp_send_json_error();
        }

        list($type, $target_id) = Slk_Link::classify($url);
        if ($type !== 'internal') {
            wp_send_json_success(); // only track internal links
        }

        $wpdb->insert(Slk_Query::clicks_table(), [
            'post_id'        => isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0,
            'target_post_id' => $target_id,
            'url'            => $url,
            'anchor'         => isset($_POST['anchor']) ? sanitize_text_field(wp_unslash($_POST['anchor'])) : '',
            'clicked_at'     => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s']);

        wp_send_json_success();
    }

}
