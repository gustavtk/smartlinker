<?php

/**
 * Test bootstrap.
 *
 * These tests deliberately do NOT load WordPress. The usual plugin test
 * harness wants a live MySQL database and a WordPress checkout, which makes
 * the suite slow, environment-dependent, and something nobody runs. What is
 * worth protecting here is the linking logic — how an anchor is chosen, how
 * text is tokenized, how two posts are compared — and none of that needs a
 * database. It needs a handful of WordPress string helpers, which are stubbed
 * below with the same behaviour the real ones have for our inputs.
 *
 * The trade is explicit: anything touching $wpdb, hooks, or HTTP is out of
 * scope here and is covered by exercising the real plugin on a real site.
 */

define('ABSPATH', __DIR__ . '/');
define('SLK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('SLK_VERSION', 'test');
// Option names, as smartlinker.php defines them. Needed because settings now
// feed the rejection thresholds.
define('SLK_OPTION_POST_TYPES', 'slk_post_types');
define('SLK_OPTION_SETTINGS', 'slk_settings');
define('SLK_OPTION_DB_VERSION', 'slk_db_version');

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string);
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null)
    {
        return esc_attr($text);
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format((float) $number, (int) $decimals);
    }
}

if (!function_exists('mysql2date')) {
    function mysql2date($format, $date, $translate = true)
    {
        $ts = strtotime((string) $date);
        return $ts ? date($format, $ts) : '';
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null)
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        $str = wp_strip_all_tags($str);
        return trim(preg_replace('/[\r\n\t ]+/', ' ', $str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

if (!function_exists('is_email')) {
    function is_email($email)
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

/**
 * In-memory options, so the rejection store can be tested without a database.
 * Same contract the real functions have for our use: a value, or the default.
 */
$GLOBALS['slk_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['slk_test_options'])
            ? $GLOBALS['slk_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['slk_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['slk_test_options'][$name]);
        return true;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        return array_merge($defaults, (array) $args);
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

/**
 * Minimal stand-in for a WP_Post, which is all the linking code reads.
 */
class Slk_Test_Post
{
    public $ID;
    public $post_title;
    public $post_content;
    public $post_type = 'post';
    public $post_status = 'publish';

    public function __construct($id, $title, $content = '')
    {
        $this->ID = $id;
        $this->post_title = $title;
        $this->post_content = $content;
    }
}

// Only the classes that are genuinely free of the database are loaded. Pulling
// in the whole plugin would drag in $wpdb and defeat the point.
require_once dirname(__DIR__) . '/core/Slk/Word.php';
require_once dirname(__DIR__) . '/core/Slk/Settings.php';
require_once dirname(__DIR__) . '/core/Slk/Anchor.php';
require_once dirname(__DIR__) . '/core/Slk/Suggestion.php';
require_once dirname(__DIR__) . '/core/Slk/Rejection.php';
require_once dirname(__DIR__) . '/core/Slk/Equity.php';
// Loaded for its threshold constants only; no method here is called.
require_once dirname(__DIR__) . '/core/Slk/Embedding.php';
require_once dirname(__DIR__) . '/core/Slk/Cannibal.php';
require_once dirname(__DIR__) . '/core/Slk/Placement.php';
require_once dirname(__DIR__) . '/core/Slk/Link.php';
