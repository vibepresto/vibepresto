<?php
/**
 * Plugin Name: VibePresto
 * Plugin URI: https://github.com/VibePresto/vibepresto
 * Description: Upload static takeover bundles and assign them to WordPress pages.
 * Version: 0.1.0
 * Author: VibePresto
 * Author URI: https://github.com/VibePresto
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: vibepresto
 * License: MIT
 * License URI: https://opensource.org/license/mit
 */

if (! defined('ABSPATH')) {
    exit;
}

define('VIBEPRESTO_VERSION', '0.1.0');
define('VIBEPRESTO_PLUGIN_FILE', __FILE__);
define('VIBEPRESTO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIBEPRESTO_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-plugin.php';

VibePresto\Plugin::instance();
