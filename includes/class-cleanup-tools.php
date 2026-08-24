<?php
/**
 * Cleanup & Optimization Tools
 */

if (!defined('WPINC')) {
    die;
}

class WP_Full_Reset_Cleanup_Tools {

    /**
     * Core WP tables (without prefix)
     */
    public static $core_tables = array(
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'term_relationships',
        'term_taxonomy',
        'termmeta',
        'terms',
        'usermeta',
        'users'
    );

    /**
     * Clean uploads directory
     */
    public static function clean_uploads($preserve_snapshots = true) {
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        if (!file_exists($basedir) || !is_dir($basedir)) {
            return array('files_deleted' => 0, 'size_freed' => '0 B');
        }

        $files_deleted = 0;
        $bytes_freed = 0;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basedir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getRealPath();

            // Skip snapshots directory if preserved
            if ($preserve_snapshots && strpos($path, 'wp-full-reset-snapshots') !== false) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($path);
            } else {
                $bytes_freed += $item->getSize();
                if (@unlink($path)) {
                    $files_deleted++;
                }
            }
        }

        return array(
            'files_deleted' => $files_deleted,
            'size_freed'    => size_format($bytes_freed, 2),
        );
    }

    /**
     * Purge all transients and cache
     */
    public static function purge_transients() {
        global $wpdb;

        $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return $deleted_transients !== false ? $deleted_transients : 0;
    }

    /**
     * Drop custom / orphaned tables
     */
    public static function drop_custom_tables() {
        global $wpdb;

        $all_tables = $wpdb->get_col("SHOW TABLES");
        $core_prefixed = array();

        foreach (self::$core_tables as $tbl) {
            $core_prefixed[] = $wpdb->prefix . $tbl;
        }

        $dropped = array();
        foreach ($all_tables as $table) {
            if (!in_array($table, $core_prefixed)) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
                $dropped[] = $table;
            }
        }

        return $dropped;
    }

    /**
     * Delete specific list of plugins by file path
     */
    public static function delete_specific_plugins($plugins_to_delete = array()) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $deleted = array();

        foreach ($plugins_to_delete as $plugin_file) {
            $plugin_file = sanitize_text_field($plugin_file);

            // Never delete WP Full Reset
            if (strpos($plugin_file, 'wp-full-reset') !== false) {
                continue;
            }

            if (!isset($all_plugins[$plugin_file])) {
                continue;
            }

            $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
            if ($plugin_dir !== WP_PLUGIN_DIR && is_dir($plugin_dir)) {
                self::delete_dir_recursive($plugin_dir);
                $deleted[] = $all_plugins[$plugin_file]['Name'];
            } else {
                $single_file = WP_PLUGIN_DIR . '/' . $plugin_file;
                if (file_exists($single_file)) {
                    @unlink($single_file);
                    $deleted[] = $all_plugins[$plugin_file]['Name'];
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete specific list of themes by slug
     */
    public static function delete_specific_themes($themes_to_delete = array()) {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        $all_themes = wp_get_themes();
        $deleted = array();

        foreach ($themes_to_delete as $slug) {
            $slug = sanitize_file_name($slug);

            if (!isset($all_themes[$slug])) {
                continue;
            }

            $theme = $all_themes[$slug];
            $theme_dir = $theme->get_theme_root() . '/' . $slug;
            if (is_dir($theme_dir)) {
                self::delete_dir_recursive($theme_dir);
                $deleted[] = $theme->get('Name');
            }
        }

        return $deleted;
    }

    /**
     * Delete inactive plugins (keeps current plugin and active plugins)
     */
    public static function delete_inactive_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = (array) get_option('active_plugins', array());
        $to_delete = array();

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (!in_array($plugin_file, $active_plugins) && strpos($plugin_file, 'wp-full-reset') === false) {
                $to_delete[] = $plugin_file;
            }
        }

        return self::delete_specific_plugins($to_delete);
    }

    /**
     * Delete inactive themes
     */
    public static function delete_inactive_themes() {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        $current_theme = wp_get_theme();
        $current_stylesheet = $current_theme->get_stylesheet();
        $current_template = $current_theme->get_template();

        $all_themes = wp_get_themes();
        $to_delete = array();

        foreach ($all_themes as $slug => $theme) {
            if ($slug !== $current_stylesheet && $slug !== $current_template) {
                $to_delete[] = $slug;
            }
        }

        return self::delete_specific_themes($to_delete);
    }

    /**
     * Purge cache directory
     */
    public static function purge_cache() {
        $cache_dir = WP_CONTENT_DIR . '/cache';
        if (is_dir($cache_dir)) {
            self::delete_dir_recursive($cache_dir, false);
            return true;
        }
        return false;
    }

    /**
     * Helper to recursively delete directory
     */
    public static function delete_dir_recursive($dir, $remove_parent = true) {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::delete_dir_recursive($path, true);
            } else {
                @unlink($path);
            }
        }

        if ($remove_parent) {
            return @rmdir($dir);
        }

        return true;
    }

    /**
     * Reset .htaccess file to default WordPress rules
     */
    public static function reset_htaccess() {
        $htaccess_file = ABSPATH . '.htaccess';
        if (file_exists($htaccess_file)) {
            @unlink($htaccess_file);
        }

        if (function_exists('save_mod_rewrite_rules')) {
            save_mod_rewrite_rules();
        }

        return true;
    }
}
