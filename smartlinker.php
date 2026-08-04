<?php
/**
 * Plugin Name: SmartLinker
 * Plugin URI:  https://example.com/smartlinker
 * Version:     0.16.2
 * Description: Build smart internal links to and from your content, auto-link keywords, track clicks, and report on your site's internal linking.
 * Author:      SmartLinker
 * Text Domain: smartlinker
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

define('SLK_VERSION', '0.43.0');
define('SLK_PLUGIN_FILE', __FILE__);
define('SLK_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SLK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SLK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SLK_DB_VERSION', '7');

// Option keys.
define('SLK_OPTION_POST_TYPES', 'slk_post_types');
define('SLK_OPTION_SETTINGS', 'slk_settings');
define('SLK_OPTION_DB_VERSION', 'slk_db_version');

/**
 * PSR-0-ish autoloader for the Slk_ namespace.
 * Slk_Foo_Bar maps to core/Slk/Foo/Bar.php
 */
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'Slk') !== 0) {
        return;
    }
    $base = SLK_PLUGIN_DIR . 'core' . DIRECTORY_SEPARATOR;
    $path = str_replace('_', DIRECTORY_SEPARATOR, $class_name) . '.php';
    $file = $base . $path;
    if (is_readable($file)) {
        require_once $file;
    }
});

// Lifecycle hooks.
register_activation_hook(__FILE__, ['Slk_Query', 'install']);
register_deactivation_hook(__FILE__, ['Slk_Base', 'deactivate']);

// Boot the plugin.
add_action('plugins_loaded', function () {
    load_plugin_textdomain('smartlinker', false, dirname(SLK_PLUGIN_BASENAME) . '/languages');
    Slk_Query::maybe_upgrade();
    Slk_Init::register_services();
});
