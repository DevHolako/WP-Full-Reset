<?php
/**
 * Admin UI & Controller
 */

if (!defined('WPINC')) {
    die;
}

class WP_Full_Reset_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_direct_actions'));

        // AJAX Handlers
        add_action('wp_ajax_wpfr_clean_uploads', array($this, 'ajax_clean_uploads'));
        add_action('wp_ajax_wpfr_purge_transients', array($this, 'ajax_purge_transients'));
        add_action('wp_ajax_wpfr_drop_custom_tables', array($this, 'ajax_drop_custom_tables'));
        add_action('wp_ajax_wpfr_delete_inactive_plugins', array($this, 'ajax_delete_inactive_plugins'));
        add_action('wp_ajax_wpfr_delete_inactive_themes', array($this, 'ajax_delete_inactive_themes'));
        add_action('wp_ajax_wpfr_reset_htaccess', array($this, 'ajax_reset_htaccess'));
        add_action('wp_ajax_wpfr_create_snapshot', array($this, 'ajax_create_snapshot'));
        add_action('wp_ajax_wpfr_restore_snapshot', array($this, 'ajax_restore_snapshot'));
        add_action('wp_ajax_wpfr_delete_snapshot', array($this, 'ajax_delete_snapshot'));
        add_action('wp_ajax_wpfr_run_reset', array($this, 'ajax_run_reset'));
    }

    public function add_menu_page() {
        add_management_page(
            __('WP Full Reset', 'wp-full-reset'),
            __('WP Full Reset', 'wp-full-reset'),
            'manage_options',
            'wp-full-reset',
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_wp-full-reset') {
            return;
        }

        wp_enqueue_style(
            'wp-full-reset-css',
            WP_FULL_RESET_URL . 'assets/css/admin.css',
            array(),
            WP_FULL_RESET_VERSION
        );

        wp_enqueue_script(
            'wp-full-reset-js',
            WP_FULL_RESET_URL . 'assets/js/admin.js',
            array('jquery'),
            WP_FULL_RESET_VERSION,
            true
        );

        wp_localize_script('wp-full-reset-js', 'WPFR_Data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wp_full_reset_nonce'),
            'strings'  => array(
                'confirm_nuclear'  => __('Are you absolutely sure you want to run Nuclear Reset? This will wipe the database, uploads, and selected plugins/themes. Type "reset" below to proceed:', 'wp-full-reset'),
                'confirm_site'     => __('Are you sure you want to reset the database? Type "reset" below to proceed:', 'wp-full-reset'),
                'confirm_options'  => __('Are you sure you want to reset all site options to defaults?', 'wp-full-reset'),
                'confirm_restore'  => __('Are you sure you want to restore this snapshot? Current database data will be overwritten.', 'wp-full-reset'),
                'confirm_delete'   => __('Delete this snapshot file permanently?', 'wp-full-reset'),
                'reset_in_progress'=> __('Resetting WordPress... Please do not close this window.', 'wp-full-reset'),
                'success'          => __('Success!', 'wp-full-reset'),
                'error'            => __('An error occurred.', 'wp-full-reset'),
            ),
        ));
    }

    public function handle_direct_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle snapshot download
        if (isset($_GET['wpfr_action']) && $_GET['wpfr_action'] === 'download_snapshot') {
            check_admin_referer('wpfr_download_snapshot');
            $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
            $filepath = WP_Full_Reset_Snapshots::get_snapshots_dir() . $file;

            if ($file && file_exists($filepath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));
                readfile($filepath);
                exit;
            }
        }
    }

    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'reset';
        ?>
        <div class="wrap wpfr-wrap">
            <h1 class="wpfr-header">
                <span class="dashicons dashicons-shield-alt"></span>
                <?php esc_html_e('WP Full Reset', 'wp-full-reset'); ?>
                <span class="wpfr-badge"><?php esc_html_e('PRO Equivalent - Standalone', 'wp-full-reset'); ?></span>
            </h1>

            <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Reset completed successfully! Your admin credentials and login session were preserved.', 'wp-full-reset'); ?></strong></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper wpfr-nav-tabs">
                <a href="?page=wp-full-reset&tab=reset" class="nav-tab <?php echo $active_tab === 'reset' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e('Reset Tools', 'wp-full-reset'); ?>
                </a>
                <a href="?page=wp-full-reset&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Cleanup Tools', 'wp-full-reset'); ?>
                </a>
                <a href="?page=wp-full-reset&tab=snapshots" class="nav-tab <?php echo $active_tab === 'snapshots' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-backup"></span> <?php esc_html_e('DB Snapshots', 'wp-full-reset'); ?>
                </a>
                <a href="?page=wp-full-reset&tab=system" class="nav-tab <?php echo $active_tab === 'system' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-info"></span> <?php esc_html_e('System Status', 'wp-full-reset'); ?>
                </a>
            </nav>

            <div class="wpfr-tab-content">
                <?php
                switch ($active_tab) {
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                    case 'snapshots':
                        $this->render_snapshots_tab();
                        break;
                    case 'system':
                        $this->render_system_tab();
                        break;
                    case 'reset':
                    default:
                        $this->render_reset_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_reset_tab() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = (array) get_option('active_plugins', array());

        $all_themes = wp_get_themes();
        $current_theme = wp_get_theme();
        $active_theme_slug = $current_theme->get_stylesheet();
        $active_theme_name = $current_theme->get('Name');

        // Filter out WP Full Reset from plugins list
        $deletable_plugins = array();
        foreach ($all_plugins as $plugin_file => $data) {
            if (strpos($plugin_file, 'wp-full-reset') === false) {
                $deletable_plugins[$plugin_file] = $data;
            }
        }
        ?>
        <div class="wpfr-cards-grid">
            <!-- NUCLEAR RESET CARD -->
            <div class="wpfr-card wpfr-card-danger">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-warning"></span>
                    <h2><?php esc_html_e('Nuclear Site Reset (Full Clean Wipe)', 'wp-full-reset'); ?></h2>
                    <span class="wpfr-pill wpfr-pill-danger"><?php esc_html_e('Complete Wipe', 'wp-full-reset'); ?></span>
                </div>
                <div class="wpfr-card-body">
                    <p class="wpfr-desc">
                        <?php esc_html_e('Performs a complete factory reset of your WordPress site: drops database tables, reinstalls clean WordPress defaults, empties uploads directory, and deletes chosen plugins & themes. Your admin account & site URL are automatically preserved.', 'wp-full-reset'); ?>
                    </p>

                    <div class="wpfr-options-box">
                        <label>
                            <input type="checkbox" id="nuclear_create_snapshot" checked>
                            <strong><?php esc_html_e('Create automatic DB snapshot before reset (Recommended)', 'wp-full-reset'); ?></strong>
                        </label>
                        <label>
                            <input type="checkbox" id="nuclear_delete_uploads" checked>
                            <?php esc_html_e('Delete all files in wp-content/uploads/ directory', 'wp-full-reset'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="nuclear_delete_custom_tables" checked>
                            <?php esc_html_e('Drop all custom & orphaned database tables', 'wp-full-reset'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="nuclear_reactivate_kept_plugins" checked>
                            <?php esc_html_e('Automatically reactivate any plugins you choose to keep', 'wp-full-reset'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="nuclear_reactivate_theme">
                            <?php printf(esc_html__('Reactivate current theme (%s) after reset', 'wp-full-reset'), esc_html($active_theme_name)); ?>
                        </label>
                    </div>

                    <!-- SELECT PLUGINS TO DELETE -->
                    <div class="wpfr-selection-panel">
                        <div class="wpfr-selection-header">
                            <div class="wpfr-selection-title">
                                <span class="dashicons dashicons-admin-plugins"></span>
                                <strong><?php esc_html_e('Plugins to Delete', 'wp-full-reset'); ?></strong>
                                <span class="wpfr-count-badge"><?php echo count($deletable_plugins); ?></span>
                            </div>
                            <div class="wpfr-selection-actions">
                                <button type="button" class="button button-small wpfr-btn-select-all" data-target="wpfr-plugin-cb"><?php esc_html_e('Select All (Delete All)', 'wp-full-reset'); ?></button>
                                <button type="button" class="button button-small wpfr-btn-deselect-all" data-target="wpfr-plugin-cb"><?php esc_html_e('Deselect All (Keep All)', 'wp-full-reset'); ?></button>
                            </div>
                        </div>
                        <p class="wpfr-hint"><?php esc_html_e('All plugins are selected for deletion by default. Uncheck any plugin you want to KEEP.', 'wp-full-reset'); ?></p>
                        <div class="wpfr-items-list">
                            <?php if (empty($deletable_plugins)): ?>
                                <div class="wpfr-empty-msg"><?php esc_html_e('No other plugins installed.', 'wp-full-reset'); ?></div>
                            <?php else: ?>
                                <?php foreach ($deletable_plugins as $p_file => $p_data): 
                                    $is_active = in_array($p_file, $active_plugins);
                                ?>
                                    <label class="wpfr-item-row <?php echo $is_active ? 'is-active-item' : ''; ?>">
                                        <input type="checkbox" class="wpfr-plugin-cb" value="<?php echo esc_attr($p_file); ?>" checked>
                                        <span class="wpfr-item-name">
                                            <strong><?php echo esc_html($p_data['Name']); ?></strong>
                                            <span class="wpfr-item-version">v<?php echo esc_html($p_data['Version']); ?></span>
                                            <?php if ($is_active): ?>
                                                <span class="wpfr-tag wpfr-tag-active"><?php esc_html_e('Active', 'wp-full-reset'); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SELECT THEMES TO DELETE -->
                    <div class="wpfr-selection-panel">
                        <div class="wpfr-selection-header">
                            <div class="wpfr-selection-title">
                                <span class="dashicons dashicons-admin-appearance"></span>
                                <strong><?php esc_html_e('Themes to Delete', 'wp-full-reset'); ?></strong>
                                <span class="wpfr-count-badge"><?php echo count($all_themes); ?></span>
                            </div>
                            <div class="wpfr-selection-actions">
                                <button type="button" class="button button-small wpfr-btn-select-all" data-target="wpfr-theme-cb"><?php esc_html_e('Select All (Delete All)', 'wp-full-reset'); ?></button>
                                <button type="button" class="button button-small wpfr-btn-deselect-all" data-target="wpfr-theme-cb"><?php esc_html_e('Deselect All (Keep All)', 'wp-full-reset'); ?></button>
                            </div>
                        </div>
                        <p class="wpfr-hint"><?php esc_html_e('All themes are selected for deletion by default. Uncheck any theme you want to KEEP.', 'wp-full-reset'); ?></p>
                        <div class="wpfr-items-list">
                            <?php foreach ($all_themes as $t_slug => $t_theme): 
                                $is_active = ($t_slug === $active_theme_slug);
                            ?>
                                <label class="wpfr-item-row <?php echo $is_active ? 'is-active-item' : ''; ?>">
                                    <input type="checkbox" class="wpfr-theme-cb" value="<?php echo esc_attr($t_slug); ?>" checked>
                                    <span class="wpfr-item-name">
                                        <strong><?php echo esc_html($t_theme->get('Name')); ?></strong>
                                        <span class="wpfr-item-version">v<?php echo esc_html($t_theme->get('Version')); ?></span>
                                        <?php if ($is_active): ?>
                                            <span class="wpfr-tag wpfr-tag-active"><?php esc_html_e('Current Active', 'wp-full-reset'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wpfr-confirm-box">
                        <label for="nuclear_confirm_text">
                            <?php esc_html_e('Type "reset" to confirm:', 'wp-full-reset'); ?>
                        </label>
                        <input type="text" id="nuclear_confirm_text" placeholder="reset" autocomplete="off">
                        <button type="button" class="button button-primary button-hero wpfr-btn-danger" id="btn-run-nuclear-reset">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Execute Nuclear Reset', 'wp-full-reset'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- STANDARD SITE RESET CARD -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-database-export"></span>
                    <h2><?php esc_html_e('Standard Database Reset', 'wp-full-reset'); ?></h2>
                </div>
                <div class="wpfr-card-body">
                    <p class="wpfr-desc">
                        <?php esc_html_e('Resets the database tables to fresh WordPress defaults. All uploaded media files, themes, and plugins in wp-content remain intact on disk.', 'wp-full-reset'); ?>
                    </p>

                    <div class="wpfr-options-box">
                        <label>
                            <input type="checkbox" id="site_create_snapshot" checked>
                            <strong><?php esc_html_e('Create automatic DB snapshot before reset', 'wp-full-reset'); ?></strong>
                        </label>
                        <label>
                            <input type="checkbox" id="site_reactivate_theme">
                            <?php printf(esc_html__('Reactivate current theme (%s)', 'wp-full-reset'), esc_html($active_theme_name)); ?>
                        </label>
                    </div>

                    <div class="wpfr-confirm-box">
                        <label for="site_confirm_text">
                            <?php esc_html_e('Type "reset" to confirm:', 'wp-full-reset'); ?>
                        </label>
                        <input type="text" id="site_confirm_text" placeholder="reset" autocomplete="off">
                        <button type="button" class="button button-secondary wpfr-btn-warning" id="btn-run-site-reset">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php esc_html_e('Reset Database Only', 'wp-full-reset'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- OPTIONS RESET CARD -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <h2><?php esc_html_e('Options Reset', 'wp-full-reset'); ?></h2>
                </div>
                <div class="wpfr-card-body">
                    <p class="wpfr-desc">
                        <?php esc_html_e('Resets the wp_options table to default WordPress settings. Posts, pages, comments, users, and media remain untouched.', 'wp-full-reset'); ?>
                    </p>

                    <div class="wpfr-options-box">
                        <label>
                            <input type="checkbox" id="options_create_snapshot" checked>
                            <strong><?php esc_html_e('Create automatic DB snapshot before reset', 'wp-full-reset'); ?></strong>
                        </label>
                    </div>

                    <div class="wpfr-confirm-box">
                        <button type="button" class="button button-secondary" id="btn-run-options-reset">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Reset Options Table', 'wp-full-reset'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_tools_tab() {
        ?>
        <div class="wpfr-tools-grid">
            <!-- Clean Uploads -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-media-default"></span>
                    <h3><?php esc_html_e('Clean Uploads Directory', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Deletes all images, documents, and folders inside wp-content/uploads/.', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_clean_uploads">
                        <?php esc_html_e('Purge Uploads Folder', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>

            <!-- Purge Transients -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-performance"></span>
                    <h3><?php esc_html_e('Purge Transients & Cache', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Clears all transient entries from the options table and flushes the object cache.', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_purge_transients">
                        <?php esc_html_e('Purge Transients', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>

            <!-- Drop Custom Tables -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-database"></span>
                    <h3><?php esc_html_e('Drop Custom / Orphan Tables', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Scans and deletes any database tables created by old plugins (leaving standard core tables intact).', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_drop_custom_tables">
                        <?php esc_html_e('Drop Custom Tables', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>

            <!-- Delete Inactive Plugins -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-plugins-checked"></span>
                    <h3><?php esc_html_e('Delete Inactive Plugins', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Removes all deactivated plugins from wp-content/plugins.', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_delete_inactive_plugins">
                        <?php esc_html_e('Delete Inactive Plugins', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>

            <!-- Delete Inactive Themes -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-art"></span>
                    <h3><?php esc_html_e('Delete Inactive Themes', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Deletes unused themes from wp-content/themes (keeps the active theme).', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_delete_inactive_themes">
                        <?php esc_html_e('Delete Inactive Themes', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>

            <!-- Reset .htaccess -->
            <div class="wpfr-card">
                <div class="wpfr-card-header">
                    <span class="dashicons dashicons-admin-page"></span>
                    <h3><?php esc_html_e('Reset .htaccess File', 'wp-full-reset'); ?></h3>
                </div>
                <div class="wpfr-card-body">
                    <p><?php esc_html_e('Deletes the current .htaccess file and regenerates default WordPress rewrite rules.', 'wp-full-reset'); ?></p>
                    <button type="button" class="button button-secondary wpfr-tool-btn" data-action="wpfr_reset_htaccess">
                        <?php esc_html_e('Regenerate .htaccess', 'wp-full-reset'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_snapshots_tab() {
        $snapshots = WP_Full_Reset_Snapshots::get_snapshots();
        ?>
        <div class="wpfr-card">
            <div class="wpfr-card-header wpfr-flex-between">
                <h2><?php esc_html_e('Database Snapshots & Backups', 'wp-full-reset'); ?></h2>
                <button type="button" class="button button-primary" id="btn-create-snapshot">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Create Snapshot Now', 'wp-full-reset'); ?>
                </button>
            </div>
            <div class="wpfr-card-body">
                <p><?php esc_html_e('Snapshots are local database backups stored securely in wp-content/uploads/wp-full-reset-snapshots/. You can restore, download, or delete them anytime.', 'wp-full-reset'); ?></p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Description / Date', 'wp-full-reset'); ?></th>
                            <th><?php esc_html_e('File Name', 'wp-full-reset'); ?></th>
                            <th><?php esc_html_e('Size', 'wp-full-reset'); ?></th>
                            <th><?php esc_html_e('Actions', 'wp-full-reset'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wpfr-snapshots-list">
                        <?php if (empty($snapshots)): ?>
                            <tr>
                                <td colspan="4" class="text-center"><?php esc_html_e('No snapshots found. Click "Create Snapshot Now" to create one.', 'wp-full-reset'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($snapshots as $snap): 
                                $download_url = wp_nonce_url(admin_url('tools.php?page=wp-full-reset&wpfr_action=download_snapshot&file=' . urlencode($snap['filename'])), 'wpfr_download_snapshot');
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($snap['description']); ?></strong><br>
                                        <small class="text-muted"><?php echo esc_html($snap['date']); ?></small>
                                    </td>
                                    <td><code><?php echo esc_html($snap['filename']); ?></code></td>
                                    <td><?php echo esc_html($snap['size']); ?></td>
                                    <td>
                                        <button type="button" class="button button-small button-secondary btn-restore-snapshot" data-file="<?php echo esc_attr($snap['filename']); ?>">
                                            <?php esc_html_e('Restore', 'wp-full-reset'); ?>
                                        </button>
                                        <a href="<?php echo esc_url($download_url); ?>" class="button button-small button-secondary">
                                            <?php esc_html_e('Download', 'wp-full-reset'); ?>
                                        </a>
                                        <button type="button" class="button button-small button-link-delete btn-delete-snapshot" data-file="<?php echo esc_attr($snap['filename']); ?>">
                                            <?php esc_html_e('Delete', 'wp-full-reset'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_system_tab() {
        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES");
        $upload_dir = wp_upload_dir();
        ?>
        <div class="wpfr-card">
            <div class="wpfr-card-header">
                <h2><?php esc_html_e('Environment & System Status', 'wp-full-reset'); ?></h2>
            </div>
            <div class="wpfr-card-body">
                <table class="widefat fixed striped">
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('WordPress Version', 'wp-full-reset'); ?></strong></td>
                            <td><?php bloginfo('version'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('PHP Version', 'wp-full-reset'); ?></strong></td>
                            <td><?php echo phpversion(); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Database Server', 'wp-full-reset'); ?></strong></td>
                            <td><?php echo $wpdb->db_version(); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Table Prefix', 'wp-full-reset'); ?></strong></td>
                            <td><code><?php echo esc_html($wpdb->prefix); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Total Database Tables', 'wp-full-reset'); ?></strong></td>
                            <td><?php echo count($tables); ?> <?php esc_html_e('tables', 'wp-full-reset'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Uploads Directory', 'wp-full-reset'); ?></strong></td>
                            <td><code><?php echo esc_html($upload_dir['basedir']); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('PHP Memory Limit', 'wp-full-reset'); ?></strong></td>
                            <td><?php echo esc_html(ini_get('memory_limit')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Max Execution Time', 'wp-full-reset'); ?></strong></td>
                            <td><?php echo esc_html(ini_get('max_execution_time')); ?>s</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ================= AJAX HANDLERS ================= */

    private function check_ajax_security() {
        check_ajax_referer('wp_full_reset_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-full-reset')));
        }
    }

    public function ajax_clean_uploads() {
        $this->check_ajax_security();
        $res = WP_Full_Reset_Cleanup_Tools::clean_uploads(true);
        wp_send_json_success(array('message' => sprintf(__('Cleaned uploads folder: %d files deleted, %s freed.', 'wp-full-reset'), $res['files_deleted'], $res['size_freed'])));
    }

    public function ajax_purge_transients() {
        $this->check_ajax_security();
        $count = WP_Full_Reset_Cleanup_Tools::purge_transients();
        wp_send_json_success(array('message' => sprintf(__('Purged %d transients and flushed cache.', 'wp-full-reset'), $count)));
    }

    public function ajax_drop_custom_tables() {
        $this->check_ajax_security();
        $dropped = WP_Full_Reset_Cleanup_Tools::drop_custom_tables();
        $count = count($dropped);
        wp_send_json_success(array('message' => sprintf(__('Dropped %d custom table(s): %s', 'wp-full-reset'), $count, implode(', ', $dropped))));
    }

    public function ajax_delete_inactive_plugins() {
        $this->check_ajax_security();
        $deleted = WP_Full_Reset_Cleanup_Tools::delete_inactive_plugins();
        wp_send_json_success(array('message' => sprintf(__('Deleted %d inactive plugin(s): %s', 'wp-full-reset'), count($deleted), implode(', ', $deleted))));
    }

    public function ajax_delete_inactive_themes() {
        $this->check_ajax_security();
        $deleted = WP_Full_Reset_Cleanup_Tools::delete_inactive_themes();
        wp_send_json_success(array('message' => sprintf(__('Deleted %d inactive theme(s): %s', 'wp-full-reset'), count($deleted), implode(', ', $deleted))));
    }

    public function ajax_reset_htaccess() {
        $this->check_ajax_security();
        WP_Full_Reset_Cleanup_Tools::reset_htaccess();
        wp_send_json_success(array('message' => __('Reset .htaccess and regenerated rewrite rules.', 'wp-full-reset')));
    }

    public function ajax_create_snapshot() {
        $this->check_ajax_security();
        $desc = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';
        $res = WP_Full_Reset_Snapshots::create_snapshot($desc);

        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()));
        } else {
            wp_send_json_success(array('message' => sprintf(__('Snapshot created successfully (%s)!', 'wp-full-reset'), $res['size'])));
        }
    }

    public function ajax_restore_snapshot() {
        $this->check_ajax_security();
        $file = isset($_POST['file']) ? sanitize_file_name($_POST['file']) : '';
        $res = WP_Full_Reset_Snapshots::restore_snapshot($file);

        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Snapshot restored successfully! Page will refresh.', 'wp-full-reset')));
        }
    }

    public function ajax_delete_snapshot() {
        $this->check_ajax_security();
        $file = isset($_POST['file']) ? sanitize_file_name($_POST['file']) : '';
        $res = WP_Full_Reset_Snapshots::delete_snapshot($file);

        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Snapshot deleted.', 'wp-full-reset')));
        }
    }

    public function ajax_run_reset() {
        $this->check_ajax_security();
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $args = isset($_POST['args']) ? (array) $_POST['args'] : array();

        // Convert string booleans to actual booleans
        foreach ($args as $k => $v) {
            if ($v === 'true' || $v === '1') $args[$k] = true;
            if ($v === 'false' || $v === '0') $args[$k] = false;
        }

        if ($type === 'nuclear') {
            $res = WP_Full_Reset_Engine::nuclear_reset($args);
        } elseif ($type === 'site') {
            $res = WP_Full_Reset_Engine::site_reset($args);
        } elseif ($type === 'options') {
            $res = WP_Full_Reset_Engine::options_reset($args);
        } else {
            wp_send_json_error(array('message' => __('Invalid reset type.', 'wp-full-reset')));
            return;
        }

        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()));
        } else {
            wp_send_json_success(array(
                'message'      => __('Reset completed successfully! Redirecting...', 'wp-full-reset'),
                'redirect_url' => admin_url('tools.php?page=wp-full-reset&status=success'),
            ));
        }
    }
}
