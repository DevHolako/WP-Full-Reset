<?php
/**
 * Plugin Name:       WP Full Reset
 * Plugin URI:        https://github.com/wordpress/wp-full-reset
 * Description:       Advanced WordPress site reset tool. Complete Nuclear Reset, Database Reset, Options Reset, Snapshots, and Cleanup Tools.
 * Version:           1.0.0
 * Author:            Antigravity
 * Author URI:        https://github.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-full-reset
 * Requires at least: 5.6
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('WP_FULL_RESET_VERSION', '1.0.0');
define('WP_FULL_RESET_DIR', plugin_dir_path(__FILE__));
define('WP_FULL_RESET_URL', plugin_dir_url(__FILE__));
define('WP_FULL_RESET_BASENAME', plugin_basename(__FILE__));
define('WP_FULL_RESET_FILE', __FILE__);

// Include required classes
require_once WP_FULL_RESET_DIR . 'includes/class-snapshots.php';
require_once WP_FULL_RESET_DIR . 'includes/class-cleanup-tools.php';
require_once WP_FULL_RESET_DIR . 'includes/class-reset-engine.php';
require_once WP_FULL_RESET_DIR . 'includes/class-admin.php';

/**
 * Initialize the plugin
 */
function wp_full_reset_init() {
    $admin = new WP_Full_Reset_Admin();
    $admin->init();
}
add_action('plugins_loaded', 'wp_full_reset_init');

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, function() {
    // Ensure snapshots directory exists and is secured
    WP_Full_Reset_Snapshots::get_snapshots_dir();
});
