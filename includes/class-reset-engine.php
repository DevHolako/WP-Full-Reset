<?php
/**
 * Core Reset Engine (Nuclear Reset, Site Reset, Options Reset)
 */

if (!defined('WPINC')) {
    die;
}

class WP_Full_Reset_Engine {

    /**
     * Perform complete Nuclear Reset
     * Drops DB tables, reinstalls WP core, wipes uploads & cache, deletes chosen plugins & themes, retains admin user credentials.
     */
    public static function nuclear_reset($args = array()) {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('You do not have permission to reset this site.', 'wp-full-reset'));
        }

        // Defaults
        $defaults = array(
            'create_snapshot'         => true,
            'delete_uploads'          => true,
            'delete_custom_tables'    => true,
            'plugins_to_delete'       => array(),
            'themes_to_delete'        => array(),
            'delete_inactive_plugins' => false,
            'delete_inactive_themes'  => false,
            'reactivate_theme'        => false,
            'reactivate_plugins'      => false,
        );
        $options = wp_parse_args($args, $defaults);

        // 1. Get current admin credentials and site settings
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->ID) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC'));
            if (!empty($admins)) {
                $current_user = $admins[0];
            } else {
                return new WP_Error('no_admin', __('Unable to locate administrator account.', 'wp-full-reset'));
            }
        }

        $old_user_pass  = $current_user->user_pass;
        $old_user_login = $current_user->user_login;
        $old_user_email = $current_user->user_email;
        $blogname       = get_option('blogname');
        $blog_public    = get_option('blog_public');
        $wplang         = get_option('wplang');
        $siteurl        = get_option('siteurl');
        $home           = get_option('home');
        $active_theme   = wp_get_theme()->get_stylesheet();
        $active_plugins = (array) get_option('active_plugins', array());

        // 2. Create Safety Snapshot if requested
        if ($options['create_snapshot']) {
            WP_Full_Reset_Snapshots::create_snapshot('Automatic Snapshot before Nuclear Reset');
        }

        // 3. Filesystem cleanup
        if ($options['delete_uploads']) {
            WP_Full_Reset_Cleanup_Tools::clean_uploads(true);
        }

        WP_Full_Reset_Cleanup_Tools::purge_cache();

        // Specific or bulk plugin deletion
        if (!empty($options['plugins_to_delete']) && is_array($options['plugins_to_delete'])) {
            WP_Full_Reset_Cleanup_Tools::delete_specific_plugins($options['plugins_to_delete']);
        } elseif ($options['delete_inactive_plugins']) {
            WP_Full_Reset_Cleanup_Tools::delete_inactive_plugins();
        }

        // Specific or bulk theme deletion
        if (!empty($options['themes_to_delete']) && is_array($options['themes_to_delete'])) {
            WP_Full_Reset_Cleanup_Tools::delete_specific_themes($options['themes_to_delete']);
        } elseif ($options['delete_inactive_themes']) {
            WP_Full_Reset_Cleanup_Tools::delete_inactive_themes();
        }

        // 4. Drop DB Tables
        if ($options['delete_custom_tables']) {
            $all_tables = $wpdb->get_col("SHOW TABLES");
            foreach ($all_tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        } else {
            $prefix = str_replace('_', '\_', $wpdb->prefix);
            $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $prefix . '%'));
            foreach ($tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        // 5. Reinstall WordPress Core
        if (!function_exists('wp_install')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $install_res = @wp_install(
            $blogname,
            $old_user_login,
            $old_user_email,
            $blog_public,
            '',
            md5(wp_rand()),
            $wplang
        );

        $user_id = is_array($install_res) && isset($install_res['user_id']) ? $install_res['user_id'] : 1;

        // 6. Restore admin password hash
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->users} SET user_pass = %s, user_activation_key = '' WHERE ID = %d",
            $old_user_pass,
            $user_id
        ));

        // 7. Restore Site URL & Home URL
        update_option('siteurl', $siteurl);
        update_option('home', $home);

        // Remove password nag
        delete_user_meta($user_id, 'default_password_nag');
        delete_user_meta($user_id, $wpdb->prefix . 'default_password_nag');

        // 8. Always keep WP Full Reset active
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        activate_plugin(WP_FULL_RESET_BASENAME);

        // Reactivate theme if requested and still exists
        if ($options['reactivate_theme']) {
            switch_theme($active_theme);
        }

        // Reactivate other kept plugins if requested
        if ($options['reactivate_plugins']) {
            foreach ($active_plugins as $plugin) {
                if ($plugin !== WP_FULL_RESET_BASENAME) {
                    $plugins_deleted = is_array($options['plugins_to_delete']) ? $options['plugins_to_delete'] : array();
                    if (!in_array($plugin, $plugins_deleted)) {
                        activate_plugin($plugin);
                    }
                }
            }
        }

        // 9. Re-authenticate user session
        wp_clear_auth_cookie();
        wp_set_auth_cookie($user_id, true);

        return true;
    }

    /**
     * Perform Site Reset (Database only, preserves files)
     */
    public static function site_reset($args = array()) {
        $args['delete_uploads'] = false;
        $args['plugins_to_delete'] = array();
        $args['themes_to_delete'] = array();
        $args['delete_inactive_plugins'] = false;
        $args['delete_inactive_themes'] = false;
        return self::nuclear_reset($args);
    }

    /**
     * Perform Options Reset (Resets wp_options to WordPress core defaults)
     */
    public static function options_reset($args = array()) {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('You do not have permission to reset options.', 'wp-full-reset'));
        }

        $defaults = array(
            'create_snapshot' => true,
        );
        $options = wp_parse_args($args, $defaults);

        // 1. Create Snapshot if requested
        if ($options['create_snapshot']) {
            WP_Full_Reset_Snapshots::create_snapshot('Automatic Snapshot before Options Reset');
        }

        // 2. Save critical settings to preserve
        $siteurl      = get_option('siteurl');
        $home         = get_option('home');
        $blogname     = get_option('blogname');
        $admin_email  = get_option('admin_email');
        $wplang       = get_option('wplang');
        $active_theme = wp_get_theme()->get_stylesheet();

        // 3. Drop & Recreate options table
        if (!function_exists('populate_options')) {
            require_once ABSPATH . 'wp-admin/includes/schema.php';
        }

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->options}");

        // Recreate schema for options table
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$wpdb->options} (
            option_id bigint(20) unsigned NOT NULL auto_increment,
            option_name varchar(191) NOT NULL default '',
            option_value longtext NOT NULL,
            autoload varchar(20) NOT NULL default 'yes',
            PRIMARY KEY  (option_id),
            UNIQUE KEY option_name (option_name),
            KEY autoload (autoload)
        ) {$wpdb->get_charset_collate()};");

        // Populate default core options
        populate_options();

        // 4. Restore preserved options
        update_option('siteurl', $siteurl);
        update_option('home', $home);
        update_option('blogname', $blogname);
        update_option('admin_email', $admin_email);
        update_option('wplang', $wplang);
        update_option('template', $active_theme);
        update_option('stylesheet', $active_theme);

        // Ensure WP Full Reset remains active
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        activate_plugin(WP_FULL_RESET_BASENAME);

        return true;
    }
}
